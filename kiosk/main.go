package main 

import (
    "bytes"
    "encoding/json"
    "fmt"
    "io"
    "log"
    "net/http"
    "os"
    "time"
    "sync"

"github.com/gorilla/websocket"
)

// --- Data Structs ---
type Config struct {
	WordPressAPIBaseURL string `json:"wordpress_api_base_url"`
        APIKey              string `json:"api_key"`
	SSLCertPath         string `json:"ssl_cert_path"`
	SSLKeyPath          string `json:"ssl_key_path"`
	Port                string `json:"port"`
	LogFile             string `json:"log_file"`
        MaxGuests           int    `json:"max_guests"`
}

type Amenity struct {
	Name     string `json:"name"`
	ImageURL string `json:"image_url"`
}

type KioskConfig struct {
	LogoURL   string    `json:"logo_url"`
        SplashURL string    `json:"splash_url"`
	Amenities []Amenity `json:"amenities"`
        MaxGuests int       `json:"max_guests"`
}

type SocketMessage struct {
	Event   string      `json:"event"`
	Payload interface{} `json:"payload"`
}

type SignInPayload struct {
	RFID    string `json:"rfid"`
	Amenity string `json:"amenity"`
        Guests  int    `json:"guests"`
}

type ValidationResponse struct {
	IsValid    bool        `json:"isValid"`
	Message    string      `json:"message"`
	Cardholder interface{} `json:"cardholder"`
}

// --- Global variables ---
var config Config
var kioskConfig KioskConfig
var upgrader = websocket.Upgrader{CheckOrigin: func(r *http.Request) bool { return true }}
var clients = make(map[*websocket.Conn]bool)
var clientsMutex = sync.Mutex{}


// --- Core Functions ---

// loadConfiguration reads settings from the central config file.
func loadConfiguration() {
	file, err := os.Open("/var/lib/fsbhoa/kiosk.json")
	if err != nil {
		log.Fatalf("FATAL: Could not open config file: %v", err)
	}
	defer file.Close()
	decoder := json.NewDecoder(file)
	err = decoder.Decode(&config)
	if err != nil {
		log.Fatalf("FATAL: Could not parse config file: %v", err)
	}
        log.Printf("Kiosk service starting. Config loaded. API Key Found: '%s'", config.APIKey)
}

// setupLogging configures the log output to a file if specified.
func setupLogging() {
	if config.LogFile != "" {
		file, err := os.OpenFile(config.LogFile, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0664)
		if err != nil {
			log.Printf("WARNING: Could not open log file %s: %v. Logging to console.", config.LogFile, err)
			return
		}
		log.SetOutput(file)
		log.Println("--- Kiosk Service Starting ---")
	}
}

// fetchKioskConfig gets the kiosk UI config from the WordPress server.
func fetchKioskConfig() {
	url := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/config", config.WordPressAPIBaseURL)
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		log.Fatalf("FATAL: Could not create request for kiosk config: %v", err)
	}
	req.Header.Set("X-API-KEY", config.APIKey)
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil { // Check for non-nil error from the Do call
		log.Fatalf("FATAL: Could not fetch kiosk config: %v", err)
	}
	if resp.StatusCode != http.StatusOK {
		bodyBytes, _ := io.ReadAll(resp.Body)
		log.Fatalf("FATAL: WordPress returned non-200 status for kiosk config: %s - %s", resp.Status, string(bodyBytes))
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		log.Fatalf("FATAL: Could not read response body: %v", err)
	}
	err = json.Unmarshal(body, &kioskConfig)
	if err != nil {
		log.Fatalf("FATAL: Could not parse kiosk config JSON: %v", err)
	}
        kioskConfig.MaxGuests = config.MaxGuests
	log.Printf("Successfully fetched config. Logo: '%s', Amenities: %d, MaxGuest: %d", kioskConfig.LogoURL, len(kioskConfig.Amenities), kioskConfig.MaxGuests)
}

// logSignInToWordPress sends the final amenity selection event to WordPress.
func logSignInToWordPress(rfid, amenity string, guests int) {
        log.Println("GO DEBUG 3: logSignInToWordPress function has started.")
	log.Printf("LOGGING TO WORDPRESS: Card %s, Amenity %s, guest %d\n", rfid, amenity, guests)
	payload := SignInPayload{RFID: rfid, Amenity: amenity, Guests: guests}
	jsonData, err := json.Marshal(payload)
	if err != nil {
		log.Printf("Error marshalling JSON for sign-in: %v", err)
		return
	}
	url := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/log-signin", config.WordPressAPIBaseURL)
	req, err := http.NewRequest("POST", url, bytes.NewBuffer(jsonData))
	if err != nil {
		log.Printf("Error creating request: %v", err)
		return
	}
        req.Header.Set("X-API-KEY", config.APIKey)
	req.Header.Set("Content-Type", "application/json")
	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("Error sending sign-in to WordPress: %v", err)
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		log.Printf("WordPress API returned non-200 status: %s", resp.Status)
	} else {
		log.Println("Successfully logged sign-in to WordPress.")
	}
}

// broadcast sends a message to all connected browser clients.
func broadcast(message SocketMessage) {
	clientsMutex.Lock()
	defer clientsMutex.Unlock()
	log.Printf("Broadcasting message to %d client(s).", len(clients))
	for client := range clients {
		if err := client.WriteJSON(message); err != nil {
			log.Printf("error broadcasting message: %v", err)
			client.Close()
			delete(clients, client)
		}
	}
}




// This helper function cleanly validates a single RFID against WordPress.
// It's called by the wallet scan logic. It returns the response, the validated RFID, and any error.
func validateRFID(rfid string) (ValidationResponse, string, error) {
	var vResponse ValidationResponse
	validationURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/validate-card/%s", config.WordPressAPIBaseURL, rfid)
	req, err := http.NewRequest("GET", validationURL, nil)
	if err != nil {
		log.Printf("Error creating validation request for %s: %v", rfid, err)
		return vResponse, rfid, err
	}

	req.Header.Set("X-API-KEY", config.APIKey)
	httpClient := &http.Client{Timeout: 5 * time.Second}
	resp, err := httpClient.Do(req)
	if err != nil {
		log.Printf("Network error validating %s: %v", rfid, err)
		return vResponse, rfid, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		bodyBytes, _ := io.ReadAll(resp.Body)
		log.Printf("Non-200 status for %s: %s - %s", rfid, resp.Status, string(bodyBytes))
		return vResponse, rfid, fmt.Errorf("bad status: %s", resp.Status)
	}

	if err := json.NewDecoder(resp.Body).Decode(&vResponse); err != nil {
		log.Printf("Error decoding validation response for %s: %v", rfid, err)
		return vResponse, rfid, err
	}

	return vResponse, rfid, nil
}


// handleConnections is the WebSocket handler for browser UI communication.
func handleConnections(w http.ResponseWriter, r *http.Request) {
	log.Println("==> Received a request to upgrade to WebSocket")
	ws, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Printf("ERROR: Failed to upgrade WebSocket connection: %v", err)
		return
	}
	log.Println("==> WebSocket upgrade successful. A client is now connected.")
	defer ws.Close()

	clientsMutex.Lock()
	clients[ws] = true
	clientsMutex.Unlock()
	log.Println("Client Connected")

	// Send initial kiosk config to the newly connected client.
	ws.WriteJSON(SocketMessage{Event: "kioskConfig", Payload: kioskConfig})

	// NEW: Variables to manage the wallet scan debouncing for this specific connection.
	var walletScanTimer *time.Timer
	var scannedRFIDs []string
	var rfidMutex sync.Mutex // Protects access to the scannedRFIDs slice

	// NEW: This is the function that will be called when the wallet scan timer fires.
	processWalletScan := func() {
		rfidMutex.Lock()
		// De-duplicate the collected RFIDs
		uniqueRFIDs := make(map[string]bool)
		var finalRFIDs []string
		for _, rfid := range scannedRFIDs {
			if !uniqueRFIDs[rfid] {
				uniqueRFIDs[rfid] = true
				finalRFIDs = append(finalRFIDs, rfid)
			}
		}
		scannedRFIDs = []string{} // Clear the slice for the next scan
		rfidMutex.Unlock()

		log.Printf("Wallet scan complete. Found %d unique RFIDs to check.", len(finalRFIDs))

		var validCardResponse ValidationResponse
		var validRFID string
		foundValid := false

		// Check each unique RFID until we find a valid one.
		for _, rfid := range finalRFIDs {
			vResponse, checkedRFID, err := validateRFID(rfid)
			if err == nil && vResponse.IsValid {
				log.Printf("Found valid card in wallet: %s", checkedRFID)
				validCardResponse = vResponse
				validRFID = checkedRFID
				foundValid = true
				break // Stop checking once a valid card is found
			}
		}

		var finalMessage SocketMessage
		if foundValid {
			// Construct the success payload
			finalPayload := map[string]interface{}{
				"isValid":    validCardResponse.IsValid,
				"message":    validCardResponse.Message,
				"cardholder": validCardResponse.Cardholder,
				"rfid":       validRFID, // Include the specific RFID that was validated
			}
			finalMessage = SocketMessage{Event: "cardSwiped", Payload: finalPayload}
		} else {
			// Construct the failure payload
			log.Printf("No valid cards found in wallet scan.")
			finalMessage = SocketMessage{Event: "cardSwiped", Payload: map[string]interface{}{
				"isValid": false,
				"message": "Card not found or invalid.",
			}}
		}

		// Safely write the single, final response back to the client.
		clientsMutex.Lock()
		if err := ws.WriteJSON(finalMessage); err != nil {
			log.Printf("Error sending wallet scan result: %v", err)
		}
		clientsMutex.Unlock()
	}

	// This is now the ONLY read loop for this connection.
	for {
		var msg SocketMessage
		if err := ws.ReadJSON(&msg); err != nil {
			log.Println("Client disconnected.")
			clientsMutex.Lock()
			delete(clients, ws)
			clientsMutex.Unlock()
			// Stop any pending timer when client disconnects
			if walletScanTimer != nil {
				walletScanTimer.Stop()
			}
			break // Exit the read loop
		}

		log.Printf("GO DEBUG 1: Received WebSocket message with event type: %s", msg.Event)

		switch msg.Event {
		case "amenitySelected":
			log.Println("GO DEBUG 2: Entered 'amenitySelected' case.")
			if payload, ok := msg.Payload.(map[string]interface{}); ok {
				rfid, okR := payload["rfid"].(string)
				amenity, okA := payload["amenity"].(string)
				guestsFloat, okG := payload["guests"].(float64) // JSON numbers are float64
				if okR && okA && okG {
					guests := int(guestsFloat)
					go logSignInToWordPress(rfid, amenity, guests)
				}
			}

		case "manualSwipe":
			if payload, ok := msg.Payload.(map[string]interface{}); ok {
				if rfid, okR := payload["rfid"].(string); okR {
					rfidMutex.Lock()
					scannedRFIDs = append(scannedRFIDs, rfid)
					// If a timer is already running, stop it. We'll start a new one.
					if walletScanTimer != nil {
						walletScanTimer.Stop()
					}
					// Start a new 250ms timer. If another swipe comes in, this will be
					// stopped and reset. If not, processWalletScan will run.
					walletScanTimer = time.AfterFunc(400*time.Millisecond, processWalletScan)
					rfidMutex.Unlock()
				}
			}
		}
	}
}




// main is the application entry point.

func main() {
	loadConfiguration()
	setupLogging()
	fetchKioskConfig()


	fs := http.FileServer(http.Dir("./web"))
	http.Handle("/", fs)
        http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) { handleConnections(w, r) })

        if config.SSLCertPath != "" && config.SSLKeyPath != "" {
            log.Printf("INFO: Starting kiosk HTTPS server on port %s", config.Port)
            err := http.ListenAndServeTLS(config.Port, config.SSLCertPath, config.SSLKeyPath, nil)
            if err != nil { log.Fatalf("FATAL: Secure server error: %v", err) }
        } else {
            log.Printf("INFO: Starting kiosk HTTP server on port %s", config.Port)
            err := http.ListenAndServe(config.Port, nil)
            if err != nil { log.Fatalf("FATAL: Insecure server error: %v", err) }
        }
}
