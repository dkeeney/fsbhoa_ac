package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"sync"

	"github.com/gorilla/websocket"
)

// --- Structs for our data ---
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
var clients = make(map[*websocket.Conn]string)
var clientsMutex = sync.Mutex{}

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

// fetchKioskConfig gets the kiosk config from the WordPress server.
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

// logSignInToWordPress sends the final event data to the WordPress server.
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

// broadcast sends a message to all connected clients.
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

// handleSimulatedSwipe validates a card number and broadcasts the result.
func handleSimulatedSwipe(rfid string) {
	log.Printf("SIMULATED SWIPE: %s\n", rfid)

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


// handleConnections is the HTTP handler for our /ws endpoint.
func handleConnections(w http.ResponseWriter, r *http.Request) {
	ws, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Fatal(err)
	}
	defer ws.Close()

	clientsMutex.Lock()
	clients[ws] = ""
	clientsMutex.Unlock()
	log.Println("Client Connected")

	configMsg := SocketMessage{Event: "kioskConfig", Payload: kioskConfig}
	if err := ws.WriteJSON(configMsg); err != nil {
		log.Printf("error sending config: %v", err)
	}

    for {
		var msg SocketMessage
		err := ws.ReadJSON(&msg)
		if err != nil {
			log.Println("Client disconnected:", err)
			clientsMutex.Lock()
			delete(clients, ws)
			clientsMutex.Unlock()
			break
		}

		switch msg.Event {
		case "amenitySelected":
			if payload, ok := msg.Payload.(map[string]interface{}); ok {
				rfid := payload["rfid"].(string)
				amenity := payload["amenity"].(string)
				if rfid != "" && amenity != "" {
					log.Printf("UI SELECTION: Amenity '%s' was selected for card %s.", amenity, rfid)
					go logSignInToWordPress(rfid, amenity)
				}
			}
		case "manualSwipe":
			if payload, ok := msg.Payload.(map[string]interface{}); ok {
				rfid := payload["rfid"].(string)
				if rfid != "" {
					go handleSimulatedSwipe(rfid)
				}
			}
		}
	}
}


// main is the entry point for the application.
func main() {
	loadConfiguration()
	fetchKioskConfig()

    log.Println("Kiosk server starting in browser-input mode.")

	fs := http.FileServer(http.Dir("./web"))
	http.Handle("/", fs)
	http.HandleFunc("/ws", handleConnections)

	port := ":8080"
	log.Printf("Starting kiosk server on http://localhost%s\n", port)
	err := http.ListenAndServe(port, nil)
	if err != nil {
		log.Fatal(err)
	}
}

