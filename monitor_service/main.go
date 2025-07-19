package main

import (
    "flag"
	"log"
	"net/http"
	"os"
	"time"
)

func main() {
    // Add a -config flag to specify the config file path
    configFile := flag.String("config", "/var/lib/fsbhoa/monitor_service.json", "Path to the JSON configuration file.")
    flag.Parse()

	// Load config using the path from the flag
	config, err := LoadConfig(*configFile)
	if err != nil {
		log.Fatalf("FATAL: Failed to load config from '%s': %v", *configFile, err)
	}
	log.Println("INFO: Configuration loaded successfully.")
    log.Printf("DEBUG: Loaded Config -- Cert Path: '%s', Key Path: '%s'", config.TlsCertPath, config.TlsKeyPath)

	hub := NewHub(config)
	go hub.Run()
	log.Println("INFO: WebSocket Hub started.")

	// HTTP endpoints
	http.HandleFunc("/notify", NotifyHandler(config, hub))
    http.HandleFunc("/update-gate-status", UpdateGateStatusHandler(hub))
	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) {
		log.Printf("INFO: Received request on /ws from %s", r.RemoteAddr)
		ServeWS(hub, w, r)
	})

	server := &http.Server{
		Addr:         config.ListenAddr,
		ReadTimeout:  10 * time.Second,
		WriteTimeout: 10 * time.Second,
	}

    // In the main() function...
	log.Printf("INFO: Monitor service starting. Listening securely on %s", config.ListenAddr)
	if err := server.ListenAndServeTLS(config.TlsCertPath, config.TlsKeyPath); err != nil {
		log.Fatalf("FATAL: Server error: %v", err)
		os.Exit(1)
	}
}
