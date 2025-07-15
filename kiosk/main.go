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
}

type Amenity struct {
	Name     string `json:"name"`
	ImageURL string `json:"image_url"`
}

type KioskConfig struct {
	LogoURL   string    `json:"logo_url"`
	Amenities []Amenity `json:"amenities"`
}

type SocketMessage struct {
	Event   string      `json:"event"`
	Payload interface{} `json:"payload"`
}

type SignInPayload struct {
	RFID    string `json:"rfid"`
	Amenity string `json:"amenity"`
}

// --- Global variables ---
var config Config
var kioskConfig KioskConfig
var upgrader = websocket.Upgrader{CheckOrigin: func(r *http.Request) bool { return true }}
var clients = make(map[*websocket.Conn]bool)
var clientsMutex = sync.Mutex{}


// --- Core Functions ---

// loadConfiguration reads settings from config.json
func loadConfiguration() {
	file, err := os.Open("config.json")
	if err != nil {
		log.Fatalf("FATAL: Could not open config.json: %v", err)
	}
	defer file.Close()
	decoder := json.NewDecoder(file)
	err = decoder.Decode(&config)
	if err != nil {
		log.Fatalf("FATAL: Could not parse config.json: %v", err)
	}
	log.Println("Configuration loaded.")
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
	log.Printf("Successfully fetched config. Logo: '%s', Amenities: %d", kioskConfig.LogoURL, len(kioskConfig.Amenities))
}

// logSignInToWordPress sends the final amenity selection event to WordPress.
func logSignInToWordPress(rfid, amenity string) {
	log.Printf("LOGGING TO WORDPRESS: Card %s, Amenity %s\n", rfid, amenity)
	payload := SignInPayload{RFID: rfid, Amenity: amenity}
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


// listenForStdInSwipes is a goroutine that reads from standard input to capture
// card swipes from a keyboard-like device. It will automatically try to
// restart the listener if the input stream fails (e.g., USB disconnect).
func listenForStdInSwipes(cardChan chan<- string) {
	// This outer loop ensures that if the reader fails (e.g., device disconnect),
	// it will wait and then try to start listening again.
	for {
		log.Println("STDIN: Starting listener. Waiting for card swipes from standard input...")

		var cardData strings.Builder
		var readTimer *time.Timer

		// Create a buffered reader to read from stdin one character at a time.
		reader := bufio.NewReader(os.Stdin)

		// Create channels to handle async character reads for this attempt.
		charChan := make(chan rune)
		errChan := make(chan error)
		doneChan := make(chan struct{}) // Used to signal the reader goroutine to stop.

		// This goroutine reads from stdin continuously and sends results back.
		go func() {
			defer close(charChan)
			for {
				char, _, err := reader.ReadRune()
				if err != nil {
					// Send the error and exit this reader goroutine.
					select {
					case errChan <- err:
					case <-doneChan:
					}
					return
				}
				select {
				case charChan <- char:
				case <-doneChan: // Exit if the main loop signals us to.
					return
				}
			}
		}()

	readLoop:
		for {
			// This setup allows the timer case in the select statement to be
			// enabled only when the timer is actually running.
			var timerChan <-chan time.Time
			if readTimer != nil {
				timerChan = readTimer.C
			}

			select {
			case char, ok := <-charChan:
				if !ok { // Channel was closed, meaning the reader goroutine exited.
					log.Println("STDIN: Reader channel closed.")
					break readLoop
				}

				// We only care about digits. Ignore all other characters like newlines.
				if char >= '0' && char <= '9' {
					// If this is the first digit of a new swipe, start the timeout timer.
					if cardData.Len() == 0 {
						log.Println("STDIN: First digit received, starting 1-second timeout.")
						readTimer = time.NewTimer(time.Second)
					}

					cardData.WriteRune(char)

					// If we have collected 8 digits, the swipe is complete.
					if cardData.Len() == 8 {
						log.Printf("STDIN: Received 8 digits: %s", cardData.String())
						if readTimer != nil {
							readTimer.Stop() // We got the data in time, so stop the timer.
							readTimer = nil
						}
						cardChan <- cardData.String()
						cardData.Reset() // Reset for the next swipe.
					}
				}

			case <-timerChan:
				log.Printf("STDIN: Read timeout. Discarding partial data: '%s'", cardData.String())
				cardData.Reset()
				readTimer = nil // The timer fired, so it's considered inactive.

			case err := <-errChan:
				// An EOF (End Of File) error is expected when the device disconnects.
				if err == io.EOF {
					log.Println("STDIN: Input stream closed (device likely disconnected).")
				} else {
					log.Printf("STDIN ERROR: Read failed: %v", err)
				}

				if readTimer != nil {
					readTimer.Stop()
				}
				break readLoop // Exit the inner read loop to trigger a restart.
			}
		}

		// Clean up the reader goroutine before the next cycle.
		close(doneChan)
		log.Println("STDIN: Listener has stopped. Restarting in 5 seconds...")
		time.Sleep(5 * time.Second)
	}
}





// handleConnections is the WebSocket handler for browser UI communication.
func handleConnections(w http.ResponseWriter, r *http.Request, cardChan chan<- string) {
	ws, err := upgrader.Upgrade(w, r, nil)
	if err != nil { log.Fatal(err) }
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
						go logSignInToWordPress(rfid, amenity)
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
	fetchKioskConfig()

	cardSwipeChannel := make(chan string)

	go listenForStdInSwipes(cardSwipeChannel)

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

	port := ":8080"
	log.Printf("Starting kiosk server on http://localhost%s\n", port)
	if err := http.ListenAndServe(port, nil); err != nil {
		log.Fatal(err)
	}
}

