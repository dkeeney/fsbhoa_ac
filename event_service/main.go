package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net/http"
	"os"
	"os/signal"
	"sync"
	"time"

	"github.com/uhppoted/uhppote-core/types"
	"github.com/uhppoted/uhppote-core/uhppote"
)

// --- Global Variables ---
var config Config
var currentControllerSerials []uint32
var controllerInfo map[uint32]ControllerConfig // Replaces gateMappings
var serialsLock sync.RWMutex

func main() {
	// 1. Load Configuration
	configFile := flag.String("config", "/var/lib/fsbhoa/event_service.json", "Path to the JSON configuration file.")
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

	// 2. Setup Logging
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

	// 3. Initialize UHPPOTE interface
	listenAddressString := fmt.Sprintf("%s:%d", config.CallbackHost, config.ListenPort)
	bindAddr := types.MustParseBindAddr(config.BindAddress)
	broadcastAddr := types.MustParseBroadcastAddr(config.BroadcastAddress)
	listenAddr := types.MustParseListenAddr(listenAddressString)
	u := uhppote.NewUHPPOTE(bindAddr, broadcastAddr, listenAddr, 5*time.Second, nil, config.Debug)

	// 4. Start All Background Services (Goroutines)
	hub := newHub(u)
	listener := EventMonitor{hub: hub}
	errors := make(chan error, 4)
	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)

	go hub.run()
	go watchConfigFile(u) // This will do the initial load and set listeners
	go pollGateStatus(u)
	go func() {
		log.Println("INFO: Hardware Event Listener starting...")
		if err := u.Listen(&listener, interrupt); err != nil {
			errors <- err
		}
		log.Println("INFO: Hardware Event Listener stopped.")
	}()

	// 5. Start the WebSocket Server
	server := &http.Server{Addr: fmt.Sprintf("0.0.0.0:%d", config.WebSocketPort)}
	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) { serveWs(hub, w, r) })

    // Manual state was set for a gate, get the new state for real-time display.
    http.HandleFunc("/trigger-poll", triggerPollHandler(u, hub))


	if config.EnableTestStub {
		http.HandleFunc("/test_event", testEventHandler(hub, &listener))
	}
	go func() {
		var err error
		log.Printf("INFO: WebSocket server starting on port %d...", config.WebSocketPort)
		if config.TlsCert != "" && config.TlsKey != "" {
			err = server.ListenAndServeTLS(config.TlsCert, config.TlsKey)
		} else {
			err = server.ListenAndServe()
		}
		if err != nil && err != http.ErrServerClosed {
			errors <- err
		}
	}()

	// 6. Wait for Shutdown Signal or Fatal Error
	log.Println("INFO: Application started successfully. Press Ctrl+C to exit.")
	select {
	case err := <-errors:
		log.Printf("FATAL: A service failed unexpectedly: %v", err)
	case <-interrupt:
		log.Println("INFO: Shutdown signal received...")
	}

	// 7. Graceful Shutdown
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := server.Shutdown(ctx); err != nil {
		log.Printf("ERROR: HTTP server shutdown error: %v", err)
	}
	log.Println("INFO: Shutdown complete.")
}

func triggerPollHandler(u uhppote.IUHPPOTE, hub *Hub) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if config.Debug {
			log.Println("DEBUG: Received request on /trigger-poll endpoint.")
		}

		// Run a single poll in the background
		go runPoll(u)

		// Immediately respond with success
		w.WriteHeader(http.StatusOK)
		fmt.Fprintln(w, "Poll triggered.")
	}
}

// testEventHandler remains here as it's a specific HTTP handler set up in main.
func testEventHandler(hub *Hub, listener *EventMonitor) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if config.Debug {
			log.Println("DEBUG: Received request on /test_event endpoint.")
		}
		// TODO: Replace with a REAL card number and door number from your database
		const testCardNumber uint32 = 15364678
		const testDoorNumber uint8 = 1
        granted := rand.Intn(10) > 2

		// Create a mock status object, just like the real listener receives
        status := types.Status{
			SerialNumber: types.SerialNumber(425043852),
			Event: types.StatusEvent{ // This is the corrected type name
				Timestamp:  types.DateTime(time.Now().UTC()), // Add the timestamp in utc like hardware.
				CardNumber: testCardNumber,
				Door:       testDoorNumber,
				Granted:    granted,
				Reason:     1, // 1= swipe, 2=door open.  A sample reason code
			},
		}

		// Manually call the OnEvent function to trigger the full pipeline
		listener.OnEvent(&status)

		fmt.Fprintln(w, "Test event generated and logged.")
	}
}

