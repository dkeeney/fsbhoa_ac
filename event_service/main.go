package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net/http"
	"net/netip"
	"os"
	"os/signal"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"github.com/uhppoted/uhppote-core/uhppote"
	"github.com/uhppoted/uhppote-core/types"
)

// --- Global Variables ---
var config Config
var currentControllerSerials []uint32
var serialsLock sync.Mutex

// --- Data Structs ---

// Config holds all configuration loaded from the JSON file
type Config struct {
	BindAddress      string `json:"bindAddress"`
	BroadcastAddress string `json:"broadcastAddress"`
	ListenPort       int    `json:"listenPort"`
	CallbackHost     string `json:"callbackHost"`
	WebSocketPort    int    `json:"webSocketPort"`
	WpURL            string `json:"wpURL"`
	TlsCert          string `json:"tlsCert"`
	TlsKey           string `json:"tlsKey"`
	LogFile          string `json:"logFile"`
	Debug            bool   `json:"debug"`
	EnableTestStub   bool   `json:"enableTestStub"`
}

// Controller struct for parsing the list from WordPress/JSON
type Controller struct {
	DeviceID uint32 `json:"uhppoted_device_id,string"`
}

type EnrichedEvent struct {
	EventType      string `json:"eventType"`
	CardholderName string `json:"cardholderName"`
	PhotoURL       string `json:"photoURL"`
	GateName       string `json:"gateName"`
	Timestamp      string `json:"timestamp"`
	EventMessage   string `json:"eventMessage"`
	CardNumber     uint32 `json:"cardNumber"`
}

type RawHardwareEvent struct {
	SerialNumber uint32
	CardNumber   uint32
	Door         uint8
	Granted      bool
	Reason       uint8
}

type WordPressEnrichmentData struct {
	CardholderName string `json:"cardholderName"`
	PhotoURL       string `json:"photoURL"`
	GateName       string `json:"gateName"`
}

type EventMonitor struct {
	hub *Hub
}

// --- Listener Methods ---
func (m *EventMonitor) OnEvent(status *types.Status) {
	if status.Event.CardNumber == 0 {
		return
	}
	event := status.Event
	if config.Debug {
		log.Printf("DEBUG: OnEvent received: %+v", event)
	}
	rawEvent := RawHardwareEvent{
		SerialNumber: uint32(status.SerialNumber),
		CardNumber:   event.CardNumber,
		Door:         event.Door,
		Granted:      event.Granted,
		Reason:       event.Reason,
	}
	enriched, _ := enrichEvent(rawEvent)
	jsonEvent, _ := json.Marshal(enriched)
	m.hub.broadcast <- jsonEvent
}

func (m *EventMonitor) OnConnected() {
	if config.Debug {
		log.Printf("DEBUG: OnConnected callback received.")
	}
}

func (m *EventMonitor) OnError(err error) bool {
	log.Printf("ERROR: uhppote-core library error: %v", err)
	return true
}

// --- WebSocket Hub & Client Logic ---
type Hub struct {
	clients    map[*Client]bool
	broadcast  chan []byte
	register   chan *Client
	unregister chan *Client
	mu         sync.Mutex
}
type Client struct {
	hub  *Hub
	conn *websocket.Conn
	send chan []byte
}

func newHub() *Hub {
	return &Hub{
		broadcast:  make(chan []byte),
		register:   make(chan *Client),
		unregister: make(chan *Client),
		clients:    make(map[*Client]bool),
	}
}

func (h *Hub) run() {
	for {
		select {
		case client := <-h.register:
			h.mu.Lock()
			h.clients[client] = true
			h.mu.Unlock()
		case client := <-h.unregister:
			h.mu.Lock()
			if _, ok := h.clients[client]; ok {
				delete(h.clients, client)
				close(client.send)
			}
			h.mu.Unlock()
		case message := <-h.broadcast:
			h.mu.Lock()
			for client := range h.clients {
				select {
				case client.send <- message:
				default:
					close(client.send)
					delete(h.clients, client)
				}
			}
			h.mu.Unlock()
		}
	}
}

var upgrader = websocket.Upgrader{
	ReadBufferSize:  1024,
	WriteBufferSize: 1024,
	CheckOrigin:     func(r *http.Request) bool { return true },
}

func serveWs(hub *Hub, w http.ResponseWriter, r *http.Request) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	client := &Client{hub: hub, conn: conn, send: make(chan []byte, 256)}
	client.hub.register <- client
	go client.writePump()
}
func (c *Client) writePump() {
	defer func() {
		c.hub.unregister <- c
		c.conn.Close()
	}()
	for {
		message, ok := <-c.send
		if !ok {
			c.conn.WriteMessage(websocket.CloseMessage, []byte{})
			return
		}
		if err := c.conn.WriteMessage(websocket.TextMessage, message); err != nil {
			return
		}
	}
}

// --- Event Enrichment, Watcher, and Listener Functions ---
func enrichEvent(rawEvent RawHardwareEvent) (EnrichedEvent, error) {
	if config.Debug {
		log.Printf("DEBUG: Enriching event for card %d...", rawEvent.CardNumber)
	}
	apiURL := fmt.Sprintf(
		"%s/wp-json/fsbhoa/v1/monitor/enrich-event?card_number=%d&controller_sn=%d&door_number=%d",
		config.WpURL, rawEvent.CardNumber, rawEvent.SerialNumber, rawEvent.Door,
	)
	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := http.Client{
		Timeout:   5 * time.Second,
		Transport: tr,
	}
	resp, err := client.Get(apiURL)
	if err != nil {
		log.Printf("ERROR: Failed to call WordPress API at %s: %v", apiURL, err)
		return EnrichedEvent{EventMessage: "Cannot reach API"}, err
	}
	defer resp.Body.Close()

	var wpData WordPressEnrichmentData
	json.NewDecoder(resp.Body).Decode(&wpData)
	enriched := EnrichedEvent{
		CardholderName: wpData.CardholderName,
		PhotoURL:       wpData.PhotoURL,
		GateName:       wpData.GateName,
		Timestamp:      time.Now().Format("3:04:05 PM"),
		CardNumber:     rawEvent.CardNumber,
	}
	if rawEvent.Granted {
		enriched.EventType = "accessGranted"
		enriched.EventMessage = "Access Granted"
	} else {
		enriched.EventType = "accessDenied"
		enriched.EventMessage = "Access Denied"
	}

	if config.Debug {
		log.Printf("DEBUG: Event enriched successfully: %+v", enriched)
	}
	return enriched, nil
}

func watchConfigFile(u uhppote.IUHPPOTE) {
	configPath := "/var/lib/fsbhoa/controllers.json"
	var lastModTime time.Time

	ticker := time.NewTicker(1 * time.Minute)
	defer ticker.Stop()

	for range ticker.C {
		stat, err := os.Stat(configPath)
		if err != nil {
			if !os.IsNotExist(err) && config.Debug {
				log.Printf("DEBUG WATCHER: Error checking controller file: %v", err)
			}
			continue
		}

		if stat.ModTime().After(lastModTime) {
			log.Println("INFO WATCHER: Detected change in controllers.json. Reloading list...")
			lastModTime = stat.ModTime()

			jsonFile, err := os.Open(configPath)
			if err != nil {
				log.Printf("ERROR WATCHER: Could not open changed controller file: %v", err)
				continue
			}

			var newControllers []Controller
			byteValue, _ := io.ReadAll(jsonFile)
			jsonFile.Close()
			if err := json.Unmarshal(byteValue, &newControllers); err != nil {
				log.Printf("ERROR WATCHER: Could not parse changed controller file: %v", err)
				continue
			}

			newSerials := []uint32{}
			for _, c := range newControllers {
				newSerials = append(newSerials, c.DeviceID)
			}

			serialsLock.Lock()

			callbackAddrString := fmt.Sprintf("%s:%d", config.CallbackHost, config.ListenPort)
			callbackAddr, _ := netip.ParseAddrPort(callbackAddrString)
			nullAddr, _ := netip.ParseAddrPort("0.0.0.0:0")

			for _, newID := range newSerials {
				found := false
				for _, oldID := range currentControllerSerials {
					if newID == oldID {
						found = true
						break
					}
				}
				if !found {
					log.Printf("INFO WATCHER: New controller added (%d). Setting listener.", newID)
					u.SetListener(newID, callbackAddr, 0)
				}
			}

			for _, oldID := range currentControllerSerials {
				found := false
				for _, newID := range newSerials {
					if oldID == newID {
						found = true
						break
					}
				}
				if !found {
					log.Printf("INFO WATCHER: Controller removed (%d). Clearing listener.", oldID)
					u.SetListener(oldID, nullAddr, 0)
				}
			}

			currentControllerSerials = newSerials
			serialsLock.Unlock()
			log.Println("INFO WATCHER: Controller list reloaded successfully.")
		}
	}
}

func listenForHardwareEvents(u uhppote.IUHPPOTE, hub *Hub) error {
	listener := EventMonitor{hub: hub}
	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)
	log.Println("INFO: Hardware Event Listener started successfully.")
	err := u.Listen(&listener, interrupt)
	log.Println("INFO: Hardware Event Listener stopped.")
	return err
}
func testEventHandler(hub *Hub) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if config.Debug {
			log.Println("DEBUG: Received request on /test_event endpoint.")
		}
		rawEvent := RawHardwareEvent{SerialNumber: 425043852, CardNumber: uint32(10000000 + rand.Intn(999999)), Door: uint8(rand.Intn(4) + 1), Granted: rand.Intn(10) > 2}
		enriched, err := enrichEvent(rawEvent)
		if err != nil {
			http.Error(w, "Failed to enrich test event", 500)
			return
		}
		jsonEvent, _ := json.Marshal(enriched)
		hub.broadcast <- jsonEvent
		fmt.Fprintln(w, "Test event generated.")
	}
}

// --- Main Application ---
func main() {
	configFile := flag.String("config", "/var/lib/fsbhoa/event_service.conf", "Path to the JSON configuration file.")
	flag.Parse()

	jsonFile, err := os.Open(*configFile)
	if err != nil {
		log.Fatalf("FATAL: Could not open config file '%s': %v", *configFile, err)
	}
	defer jsonFile.Close()

	byteValue, _ := io.ReadAll(jsonFile)
	if err := json.Unmarshal(byteValue, &config); err != nil {
		log.Fatalf("FATAL: Could not parse config file '%s': %v", *configFile, err)
	}

	if config.LogFile != "" {
		f, err := os.OpenFile(config.LogFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
		if err != nil {
			log.Fatalf("FATAL: Failed to open log file %s: %v", config.LogFile, err)
		}
		defer f.Close()
		log.SetOutput(f)
	}

	log.Println("----------------------------------------------------")
	log.Printf("INFO: FSBHOA Event Service starting...")
	log.Printf("INFO: WordPress API target: %s", config.WpURL)

	log.Println("INFO: Fetching initial controller list...")
	controllersConfigPath := "/var/lib/fsbhoa/controllers.json"
	controllersFile, err := os.Open(controllersConfigPath)
	if err != nil {
		log.Printf("WARN: Could not open controllers file '%s': %v. Starting with empty list.", controllersConfigPath, err)
	} else {
		var controllers []Controller
		byteValue, _ := io.ReadAll(controllersFile)
		controllersFile.Close()
		if err := json.Unmarshal(byteValue, &controllers); err != nil {
			log.Printf("WARN: Could not parse controllers file '%s': %v. Starting with empty list.", controllersConfigPath, err)
		} else {
			serialsLock.Lock()
			for _, c := range controllers {
				currentControllerSerials = append(currentControllerSerials, c.DeviceID)
			}
			serialsLock.Unlock()
		}
	}

	listenAddressString := fmt.Sprintf("%s:%d", config.CallbackHost, config.ListenPort)
	log.Printf("INFO: Service listening for events on %s", listenAddressString)

	bindAddr := types.MustParseBindAddr(config.BindAddress)
	broadcastAddr := types.MustParseBroadcastAddr(config.BroadcastAddress)
	listenAddr := types.MustParseListenAddr(listenAddressString)
	u := uhppote.NewUHPPOTE(bindAddr, broadcastAddr, listenAddr, 5*time.Second, nil, config.Debug)

	callbackAddrString := fmt.Sprintf("%s:%d", config.CallbackHost, config.ListenPort)
	controllerListenerAddr, err := netip.ParseAddrPort(callbackAddrString)
	if err != nil {
		log.Fatalf("FATAL: Invalid callback address '%s': %v", callbackAddrString, err)
	}
    log.Printf("INFO: Callback address: %s", callbackAddrString )

	log.Printf("INFO: Configuring %d controllers...", len(currentControllerSerials))
	for _, deviceID := range currentControllerSerials {
		log.Printf("INFO: Sending SetListener to device ID %d", deviceID)
		if _, err := u.SetListener(deviceID, controllerListenerAddr, 0); err != nil {
			log.Printf("WARN: Could not set listener for %d: %v", deviceID, err)
		}
	}

	hub := newHub()
	errors := make(chan error, 2)
	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)

	go hub.run()
	go watchConfigFile(u)
	go func() {
		errors <- listenForHardwareEvents(u, hub)
	}()

	server := &http.Server{Addr: fmt.Sprintf("0.0.0.0:%d", config.WebSocketPort)}
	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) { serveWs(hub, w, r) })
	if config.EnableTestStub {
		http.HandleFunc("/test_event", testEventHandler(hub))
	}

	go func() {
		var err error
		if config.TlsCert != "" && config.TlsKey != "" {
			err = server.ListenAndServeTLS(config.TlsCert, config.TlsKey)
		} else {
			err = server.ListenAndServe()
		}
		if err != nil && err != http.ErrServerClosed {
			errors <- err
		}
	}()

	log.Println("INFO: Application started. Press Ctrl+C to exit.")
	select {
	case err := <-errors:
		log.Printf("FATAL: A service failed unexpectedly: %v", err)
	case <-interrupt:
		log.Println("INFO: Shutdown signal received...")
	}

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := server.Shutdown(ctx); err != nil {
		log.Printf("ERROR: HTTP server shutdown error: %v", err)
	}
	log.Println("INFO: Shutdown complete.")
}

