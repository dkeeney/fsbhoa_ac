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

// processCardSwipe is the central function that validates a card and broadcasts the result.
func processCardSwipe(rfid string, client *websocket.Conn) {
	log.Printf("PROCESSING SWIPE for card: %s\n", rfid)
	validationURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/validate-card/%s", config.WordPressAPIBaseURL, rfid)
        req, err := http.NewRequest("GET", validationURL, nil)
	if err != nil {
		log.Printf("Error creating validation request: %v", err)
		return
	}
	req.Header.Set("X-API-KEY", config.APIKey)
	log.Printf("Sending API request. X-API-KEY header set to: '%s'", req.Header.Get("X-API-KEY"))

	httpClient := &http.Client{}
	resp, err := httpClient.Do(req)
	if err != nil {
		log.Printf("Error validating card: %v", err)
		return
	}
	if resp.StatusCode != http.StatusOK {
		bodyBytes, _ := io.ReadAll(resp.Body)
		log.Printf("Error: WordPress returned non-200 status for card validation: %s - %s", resp.Status, string(bodyBytes))
		errorMsg := SocketMessage{Event: "cardSwiped", Payload: map[string]interface{}{"isValid": false, "message": "System Error: Could not validate card."}}
		client.WriteJSON(errorMsg)
		return
	}

	defer resp.Body.Close()
	var validationResponse struct {
		IsValid    bool   `json:"isValid"`
		Message    string `json:"message"`
		Cardholder struct {
			Name  string `json:"name"`
			Photo string `json:"photo"`
		} `json:"cardholder"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&validationResponse); err != nil {
		log.Printf("Error decoding validation response: %v", err)
		return
	}
	message := SocketMessage{
		Event: "cardSwiped",
		Payload: map[string]interface{}{
			"rfid":       rfid,
			"isValid":    validationResponse.IsValid,
			"message":    validationResponse.Message,
			"cardholder": validationResponse.Cardholder,
		},
	}
        
        // Since all swipes are from browsers, we send a targeted response to the originating client.
        log.Printf("Sending targeted response to a single browser client.")
        clientsMutex.Lock()
        err = client.WriteJSON(message)
        clientsMutex.Unlock()
        if err != nil {
            log.Printf("Error sending targeted message: %v", err)
        }
}





// handleConnections is the WebSocket handler for browser UI communication.
func handleConnections(w http.ResponseWriter, r *http.Request, cardChan chan<- string) {
        log.Println("==> Received a request to upgrade to WebSocket")

	ws, err := upgrader.Upgrade(w, r, nil)
        if err != nil {
            log.Printf("ERROR: Failed to upgrade WebSocket connection: %v", err)
            return // Exit this handler, but do not crash the server.
        }
        log.Println("==> WebSocket upgrade successful. A client is now connected.")
	defer ws.Close()

	clientsMutex.Lock()
	clients[ws] = true
	clientsMutex.Unlock()
	log.Println("Client Connected")

	ws.WriteJSON(SocketMessage{Event: "kioskConfig", Payload: kioskConfig})

	for {
		var msg SocketMessage
		if err := ws.ReadJSON(&msg); err != nil {
			log.Println("Client disconnected.")
			clientsMutex.Lock()
			delete(clients, ws)
			clientsMutex.Unlock()
			break
		}

		switch msg.Event {
                case "amenitySelected":
			if payload, ok := msg.Payload.(map[string]interface{}); ok {
				if rfid, okR := payload["rfid"].(string); okR {
					if amenity, okA := payload["amenity"].(string); okA {
						guests := 0 // Default to 0 if not provided
						if g, okG := payload["guests"].(float64); okG {
							guests = int(g)
						}
						go logSignInToWordPress(rfid, amenity, guests)
					}
				}
			}
		case "manualSwipe":
                    if payload, ok := msg.Payload.(map[string]interface{}); ok {
                        if rfid, okR := payload["rfid"].(string); okR {
                            // Launch a goroutine to handle the scan, so we don't block the main read loop.
                            go handleWalletScan(rfid, ws)
                        }
                    }
		}
	}
}


// handleWalletScan collects all card swipes in a short time window and processes them.
func handleWalletScan(initialRFID string, client *websocket.Conn) {
    // A channel to collect all RFIDs scanned in a short burst.
    rfidChan := make(chan string, 10) // Buffer of 10 is plenty.
    rfidChan <- initialRFID

    // This timer defines our "listening window". 500ms is a good starting point.
    timer := time.NewTimer(500 * time.Millisecond)
    
    // Concurrently listen for more card swipes from this specific client.
    go func() {
        for {
            var msg SocketMessage
            // This is a temporary, quick read from the connection.
            if err := client.ReadJSON(&msg); err != nil {
                // If the client disconnects or there's an error, just stop listening.
                return
            }
            if msg.Event == "manualSwipe" {
                if payload, ok := msg.Payload.(map[string]interface{}); ok {
                    if rfid, okR := payload["rfid"].(string); okR {
                        rfidChan <- rfid
                    }
                }
            }
        }
    }()

    <-timer.C      // Wait for the 500ms timer to fire.
    close(rfidChan) // Close the channel to signal we're done collecting.

    var scannedRFIDs []string
    uniqueRFIDs := make(map[string]bool)
    for rfid := range rfidChan {
        if !uniqueRFIDs[rfid] {
            scannedRFIDs = append(scannedRFIDs, rfid)
            uniqueRFIDs[rfid] = true
        }
    }
    log.Printf("Wallet scan complete. Found %d unique RFIDs to check.", len(scannedRFIDs))

    var validCardResponse interface{}
    var lastInvalidRFID string

    // Now, iterate through the unique RFIDs we collected.
    for _, rfid := range scannedRFIDs {
        // We can reuse the existing processCardSwipe logic, but we need to extract its core.
        // Let's call WordPress to validate each card.
        validationURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/validate-card/%s", config.WordPressAPIBaseURL, rfid)
        req, _ := http.NewRequest("GET", validationURL, nil)
        req.Header.Set("X-API-KEY", config.APIKey)
        httpClient := &http.Client{Timeout: 5 * time.Second}
        resp, err := httpClient.Do(req)
        if err != nil || resp.StatusCode != http.StatusOK {
            continue // Skip if there's a network error or non-200 response
        }
        
        var vResponse struct {
            IsValid    bool        `json:"isValid"`
            Message    string      `json:"message"`
            Cardholder interface{} `json:"cardholder"`
        }
        
        json.NewDecoder(resp.Body).Decode(&vResponse)
        resp.Body.Close()

        if vResponse.IsValid {
            log.Printf("Found valid card in wallet: %s", rfid)
            validCardResponse = vResponse
            break // We found a valid card, so we can stop checking.
        }
        lastInvalidRFID = rfid // Keep track of the last invalid card we saw.
    }

    // After checking all cards, send a single response to the UI.
    var finalMessage SocketMessage
    if validCardResponse != nil {
        // If we found a valid card, send its success payload.
        finalMessage = SocketMessage{Event: "cardSwiped", Payload: validCardResponse}
    } else {
        // If no valid cards were found, send an error for the last invalid one.
        log.Printf("No valid cards found in wallet scan. Reporting error for last card: %s", lastInvalidRFID)
        finalMessage = SocketMessage{Event: "cardSwiped", Payload: map[string]interface{}{
            "isValid": false, 
            "message": "Card not found.",
        }}
    }
    
    // Use a mutex to safely write the final response to the client.
    clientsMutex.Lock()
    client.WriteJSON(finalMessage)
    clientsMutex.Unlock()
}


// main is the application entry point.

func main() {
	loadConfiguration()
	setupLogging()
	fetchKioskConfig()

	cardSwipeChannel := make(chan string)


	fs := http.FileServer(http.Dir("./web"))
	http.Handle("/", fs)
	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) {
		handleConnections(w, r, cardSwipeChannel)
	})

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
