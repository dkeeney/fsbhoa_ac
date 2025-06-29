package main

import (
	"context"
    "crypto/tls"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"math/rand"
	"net/http"
	"net/netip"
	"os"
	"os/signal"
	"sync"
	"time"

	"github.com/uhppoted/uhppote-core/uhppote"
	"github.com/gorilla/websocket"
	"github.com/uhppoted/uhppote-core/types"
)

var debug *bool

// --- Data Structs ---
type EnrichedEvent struct {
	EventType      string `json:"eventType"`
	CardholderName string `json:"cardholderName"`
	PhotoURL       string `json:"photoURL"`
	GateName       string `json:"gateName"`
	Timestamp      string `json:"timestamp"`
	EventMessage   string `json:"eventMessage"`
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
	hub   *Hub
	wpURL string
}

// --- Listener Methods ---
func (m *EventMonitor) OnEvent(status *types.Status) {
	if status.Event.CardNumber == 0 {
		return
	}
	event := status.Event
	if *debug {
		log.Printf("DEBUG: OnEvent received: %+v", event)
	}
	rawEvent := RawHardwareEvent{
		SerialNumber: uint32(status.SerialNumber),
		CardNumber:   event.CardNumber,
		Door:         event.Door,
		Granted:      event.Granted,
		Reason:       event.Reason,
	}
	enriched, _ := enrichEvent(rawEvent, m.wpURL)
	jsonEvent, _ := json.Marshal(enriched)
	m.hub.broadcast <- jsonEvent
}
func (m *EventMonitor) OnConnected() {
	if *debug {
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

// --- Event Enrichment & Hardware Listener ---
func enrichEvent(rawEvent RawHardwareEvent, wpURL string) (EnrichedEvent, error) {
	apiURL := fmt.Sprintf(
		"%s/wp-json/fsbhoa/v1/monitor/enrich-event?card_number=%d&controller_sn=%d&door_number=%d",
		wpURL, rawEvent.CardNumber, rawEvent.SerialNumber, rawEvent.Door,
	)

    if *debug {
        log.Printf("DEBUG: Enriching event for card %d...", rawEvent.CardNumber)
    }

   	// --- Create a custom HTTP client that skips SSL certificate verification ---
	// This is safe because we are only calling our own internal server.
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
	}
	if rawEvent.Granted {
		enriched.EventType = "accessGranted"
		enriched.EventMessage = "Access Granted"
	} else {
		enriched.EventType = "accessDenied"
		enriched.EventMessage = "Access Denied"
	}

    if *debug {
        log.Printf("DEBUG: Event enriched successfully: %+v", enriched)
    }
	return enriched, nil
}
func listenForHardwareEvents(u uhppote.IUHPPOTE, hub *Hub, wpURL string) error {
	listener := EventMonitor{hub: hub, wpURL: wpURL}
	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)
	log.Println("INFO: Hardware Event Listener started successfully.")
	err := u.Listen(&listener, interrupt)
	log.Println("INFO: Hardware Event Listener stopped.")
	return err
}
func testEventHandler(hub *Hub, wpURL string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		rawEvent := RawHardwareEvent{SerialNumber: 425043852, CardNumber: uint32(10000000 + rand.Intn(999999)), Door: uint8(rand.Intn(4) + 1), Granted: rand.Intn(10) > 2}
		enriched, err := enrichEvent(rawEvent, wpURL)
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
	// --- Configuration Flags ---
	bindAddr := types.MustParseBindAddr("0.0.0.0:0")
	broadcastAddr := types.MustParseBroadcastAddr("192.168.42.255:60000")
	listenAddr := types.MustParseListenAddr("0.0.0.0:60001")

	port := flag.Int("port", 8083, "Port for the WebSocket service.")
	wpProtocol := flag.String("wp-protocol", "https", "Protocol for WordPress API (http or https).")
	wpHost := flag.String("wp-host", "nas.local", "Hostname of the WordPress server.")
	wpPort := flag.Int("wp-port", 443, "Port of the WordPress server (e.g., 80 or 443).")
	callbackHost := flag.String("callback-host", "192.168.42.99", "The specific IP address to be sent to the controller for event callbacks.")
	logFile := flag.String("logFile", "", "Path to the log file.")
	tlsCert := flag.String("tls-cert", "", "Path to the TLS certificate file.")
	tlsKey := flag.String("tls-key", "", "Path to the TLS key file.")
	enableTestStub := flag.Bool("enable-test-stub", false, "Enable the /test_event endpoint.")
	debug = flag.Bool("debug", false, "Enable verbose debug logging.")
	flag.Parse()

	// --- Assemble WordPress URL from flags ---
	wpURL := fmt.Sprintf("%s://%s:%d", *wpProtocol, *wpHost, *wpPort)
	callbackAddrString := fmt.Sprintf("%s:60001", *callbackHost)


	// --- Logging Setup ---
	if *logFile != "" {
		f, err := os.OpenFile(*logFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0666)
		if err != nil {
			log.Fatalf("FATAL: Failed to open log file %s: %v", *logFile, err)
		}
		defer f.Close()
		log.SetOutput(f)
	}
	log.Println("----------------------------------------------------")
	log.Printf("INFO: FSBHOA Event Service starting...")
	log.Printf("INFO: WebSocket listening on port: %d", *port)
    log.Printf("INFO: Setting controller callback address to: %s", callbackAddrString)
	log.Printf("INFO: WordPress API target: %s", wpURL)

	// --- UHPPOTE Initialization ---
	u := uhppote.NewUHPPOTE(bindAddr, broadcastAddr, listenAddr, 5*time.Second, nil, *debug)

    controllerListenAddrPort, _ := netip.ParseAddrPort(callbackAddrString)
	if _, err := u.SetListener(425043852, controllerListenAddrPort, 2); err != nil {
		log.Printf("WARN: Could not set listener: %v", err)
	}

    log.Println("INFO: Verifying listener address on controller...")
	retrievedListener, interval, err := u.GetListener(425043852)
	if err != nil {
		log.Printf("ERROR: Could not get listener address from controller: %v", err)
	} else {
		log.Printf("INFO: Controller reports listener is: Addr(%s), Interval(%v)", retrievedListener, interval)
		if retrievedListener.String() == controllerListenAddrPort.String() {
			log.Println("INFO: OK - Listener address appears to be set correctly.")
		} else {
			log.Printf("WARN: MISMATCH - Listener on controller is %s, but we expected %s.", retrievedListener, controllerListenAddrPort)
		}
	}

	// --- Graceful Shutdown & Goroutine Management ---
	hub := newHub()
	errors := make(chan error, 2)
	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)

	go hub.run()

	go func() {
		errors <- listenForHardwareEvents(u, hub, wpURL)
	}()

	server := &http.Server{Addr: fmt.Sprintf("0.0.0.0:%d", *port)}
	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) { serveWs(hub, w, r) })
	if *enableTestStub {
		http.HandleFunc("/test_event", testEventHandler(hub, wpURL))
	}

	go func() {
		var err error
		if *tlsCert != "" && *tlsKey != "" {
			err = server.ListenAndServeTLS(*tlsCert, *tlsKey)
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

