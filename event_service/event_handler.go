package main

import (
    "bytes"
	"crypto/tls"
	"encoding/json"
	"fmt"
    "io"
	"log"
	"net/http"
	"time"

	"github.com/uhppoted/uhppote-core/types"
)

// EventMonitor implements the uhppote-core.EventListener interface.
type EventMonitor struct {
	hub *Hub
}

// OnEvent is the callback function that gets executed when a controller sends an event.
func (m *EventMonitor) OnEvent(status *types.Status) {
	event := status.Event
	if config.Debug {
		log.Printf("DEBUG: OnEvent received: %+v", event)
	}

	// Create a descriptive message based on the event type and reason code.
	var eventMessage string
	switch event.Reason {
	case 1:
		eventMessage = "Card swipe event" // This will be overwritten by enrichEvent for card swipes
	case 2:
		eventMessage = "Door opened"
	case 3:
		eventMessage = "Door closed"
	case 4:
		eventMessage = "Button pressed"
	case 5:
		eventMessage = "Door propped open"
	case 6:
		eventMessage = "Door forced open"
    case 100:
        eventMessage = "Sign-in"
    case 101:
        eventMessage = "Sign-in Failure"
	default:
		eventMessage = fmt.Sprintf("Unknown event reason: %d", event.Reason)
	}

	// Create the event struct to be logged
	rawEvent := RawHardwareEvent{
		SerialNumber: uint32(status.SerialNumber),
        Timestamp:    time.Time(event.Timestamp),
		CardNumber:   event.CardNumber,
		Door:         event.Door,
		Granted:      event.Granted,
		Reason:       event.Reason,
	}

	// Log ALL event types to the database in the background
	go logEventToWordPress(rawEvent, eventMessage)

	// ONLY process card swipes for real-time display and enrichment
	if event.CardNumber > 0 {
		enrichedPayload, err := enrichEvent(rawEvent)
		if err != nil {
			log.Printf("ERROR: Could not enrich event: %v", err)
			return
		}
		message := WebSocketMessage{
			MessageType: "accessEvent",
			Payload:     enrichedPayload,
		}
		jsonMessage, err := json.Marshal(message)
		if err != nil {
			log.Printf("ERROR: Could not marshal access event message: %v", err)
			return
		}
		m.hub.broadcast <- jsonMessage
	}
}


// OnConnected is a callback for when the listener establishes a connection.
func (m *EventMonitor) OnConnected() {
	if config.Debug {
		log.Printf("DEBUG: OnConnected callback received from uhppote-core listener.")
	}
}

// OnError is a callback for errors within the uhppote-core library.
func (m *EventMonitor) OnError(err error) bool {
	log.Printf("ERROR: uhppote-core library error: %v", err)
	return true
}

// enrichEvent combines local config data with a WordPress API call for cardholder info.
func enrichEvent(rawEvent RawHardwareEvent) (AccessEventPayload, error) {
	if config.Debug {
		log.Printf("DEBUG: Enriching event for card %d at controller %d...", rawEvent.CardNumber, rawEvent.SerialNumber)
	}

	// 1. Get Gate Name and ID from our local config first.
	gateName := "Unknown Door"
	doorRecordID := 0

	serialsLock.RLock()
	if controller, ok := controllerInfo[rawEvent.SerialNumber]; ok {
	    for _, door := range controller.Doors {
		    if door.Number == rawEvent.Door {
			    gateName = door.Name
			    doorRecordID = door.ID
			    break
		    }
	    }
	   }
	serialsLock.RUnlock()

	// 2. Call WordPress API to get Cardholder Name and Photo URL.
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

	// 3. Build the final payload
	enriched := AccessEventPayload{
		CardholderName: "Unknown Card",
		PhotoURL:       "",
		GateName:       gateName,
		Timestamp:      toLocalTime(rawEvent.Timestamp),
		CardNumber:     rawEvent.CardNumber,
		DoorRecordID:   doorRecordID,
	}

	// If the API call was successful, use the data from WordPress
	if err == nil {
		defer resp.Body.Close()
		var wpData WordPressEnrichmentData
		if err := json.NewDecoder(resp.Body).Decode(&wpData); err == nil {
			enriched.CardholderName = wpData.CardholderName
			enriched.PhotoURL = wpData.PhotoURL
            enriched.StreetAddress = wpData.StreetAddress
           	// ** If WordPress provided a gate name (for the kiosk), use it.
			if wpData.GateName != "" && wpData.GateName != "Unknown Door" {
				enriched.GateName = wpData.GateName
			}
		} else {
			log.Printf("ERROR: Could not decode enrichment response from WordPress: %v", err)
		}
	} else {
		log.Printf("ERROR: Failed to call WordPress API at %s: %v", apiURL, err)
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

// logEventToWordPress sends the raw event details to a WordPress endpoint to be logged.
func logEventToWordPress(event RawHardwareEvent, eventMessage string) {
	apiURL := fmt.Sprintf("%s/wp-json/fsbhoa/v1/monitor/log-event", config.WpURL)

    fmt.Println(">>>> logEventToWordPress was called!")

	if config.Debug {
		log.Printf("DEBUG LOGGING: Preparing POST request to %s", apiURL)
	}

	postBody, err := json.Marshal(map[string]interface{}{
		"SerialNumber": event.SerialNumber,
		"CardNumber":   event.CardNumber,
		"Door":         event.Door,
		"Granted":      event.Granted,
		"Reason":       event.Reason,
		"EventMessage": eventMessage,
	})
	if err != nil {
		log.Printf("ERROR LOGGING: Failed to create JSON for event log: %v", err)
		return
	}

	req, err := http.NewRequest("POST", apiURL, bytes.NewBuffer(postBody))
	if err != nil {
		log.Printf("ERROR LOGGING: Failed to create POST request: %v", err)
		return
	}
	req.Header.Set("Content-Type", "application/json")

	tr := &http.Transport{
		TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
	}
	client := &http.Client{Timeout: 10 * time.Second, Transport: tr}
	if config.Debug {
		log.Printf("DEBUG LOGGING: sending POST request")
	}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("ERROR LOGGING: Failed to send event to WordPress log: %v", err)
		return
	}
	defer resp.Body.Close()

	responseBody, bodyErr := io.ReadAll(resp.Body)
	if bodyErr != nil {
		log.Printf("ERROR LOGGING: Could not read response body: %v", bodyErr)
		return
	}
	log.Printf("DEBUG LOGGING: Response from Log Endpoint -- Status: %s, Body: %s", resp.Status, string(responseBody))
}

// toLocalTime converts a UTC time to a formatted string in the server's local time zone.
func toLocalTime(utcTime time.Time) string {
	return utcTime.Local().Format("3:04:05 PM")
}
