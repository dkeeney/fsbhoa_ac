package main 

import (
    "bytes"
    "bufio"
    "encoding/json"
    "fmt"
    "io"
    "log"
    "net/http"
    "os"
    "sync"
    "time"
    "strings"

"github.com/gorilla/websocket"
)

// --- Data Structs ---
type Config struct {
	WordPressAPIBaseURL string `json:"wordpress_api_base_url"`
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
	resp, err := http.Get(url)
	if err != nil {
		log.Fatalf("FATAL: Could not fetch kiosk config: %v", err)
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
func processCardSwipe(rfid string) {
	log.Printf("PROCESSING SWIPE for card: %s\n", rfid)
	validationURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/kiosk/validate-card/%s", config.WordPressAPIBaseURL, rfid)
	resp, err := http.Get(validationURL)
	if err != nil {
		log.Printf("Error validating card: %v", err)
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
	broadcast(message)
}


// listenForLocalReader is a goroutine that periodically checks for the card
// reader device and reads from it when it's available.
func listenForLocalReader(cardChan chan<- string) {
	devicePath := "/dev/kiosk_reader"
	for {
		// Try to open the device file.
		file, err := os.Open(devicePath)
		if err != nil {
			log.Printf("INFO: Local reader at %s not found. Will check again in 10 seconds.", devicePath)
			time.Sleep(10 * time.Second)
			continue // Go to the next iteration of the loop to try again.
		}

		log.Printf("INFO: Local reader connected at %s. Now listening for card swipes.", devicePath)
		
		// We have a valid device, now read from it until it's disconnected.
		reader := bufio.NewReader(file)
		var cardData strings.Builder
		
		for {
			char, _, err := reader.ReadRune()
			if err != nil {
				// This error (like EOF) means the device was unplugged.
				log.Printf("INFO: Local reader disconnected: %v", err)
				file.Close()
				break // Break the inner loop to go back to trying to open the device.
			}

			if char >= '0' && char <= '9' {
				cardData.WriteRune(char)
				if cardData.Len() == 8 {
					log.Printf("LOCAL SWIPE: Received 8 digits: %s", cardData.String())
					cardChan <- cardData.String()
					cardData.Reset()
				}
			}
		}
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
					cardChan <- rfid
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

	cardSwipeChannel := make(chan string)

	go listenForLocalReader(cardSwipeChannel)

	fs := http.FileServer(http.Dir("./web"))
	http.Handle("/", fs)
	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) {
		handleConnections(w, r, cardSwipeChannel)
	})

	go func() {
		for cardNumber := range cardSwipeChannel {
			processCardSwipe(cardNumber)
		}
	}()

	log.Printf("Starting kiosk server on https://localhost%s\n", config.Port)
	if err := http.ListenAndServeTLS(config.Port, config.SSLCertPath, config.SSLKeyPath, nil); err != nil {
		log.Fatalf("FATAL: ListenAndServeTLS failed: %v", err)
	}
}
