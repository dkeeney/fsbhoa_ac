package main

import (
	"encoding/json"
	"log"
	"time"

	"github.com/uhppoted/uhppote-core/types"
	"github.com/uhppoted/uhppote-core/uhppote"
)

// pollGateStatus runs in a loop, periodically checking the status of each configured door.
func pollGateStatus(u uhppote.IUHPPOTE, hub *Hub) {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()

	// Run an initial poll immediately, then wait for subsequent ticks.
	runPoll(u, hub)

	for range ticker.C {
		runPoll(u, hub)
	}
}

// runPoll contains the actual logic for a single polling run.
func runPoll(u uhppote.IUHPPOTE, hub *Hub) {
	if config.Debug {
		log.Println("DEBUG: Polling gate statuses...")
	}

	serialsLock.RLock()
	currentControllers := controllerInfo
	serialsLock.RUnlock()

	if config.Debug {
		log.Printf("DEBUG: Found %d controllers to poll.", len(currentControllers))
	}

	for sn, info := range currentControllers {
		// Loop through the doors configured for this specific controller.
		for _, door := range info.Doors {
			if config.Debug {
				log.Printf("DEBUG: Checking status for controller %d, door %d (%s)...", sn, door.Number, door.Name)
			}
			status := getDoorStatus(u, sn, door.Number)
			broadcastDoorStatus(hub, door.ID, status)
		}
	}
}

// sendInitialState gets the status of all doors and sends it ONLY to a new client.
func sendInitialState(c *Client) {
	time.Sleep(250 * time.Millisecond)

	serialsLock.RLock()
	currentControllers := controllerInfo
	serialsLock.RUnlock()

	if config.Debug {
		log.Printf("DEBUG sendInitialState: Found %d configured controllers.", len(currentControllers))
	}

	for sn, info := range currentControllers {
		for _, door := range info.Doors {
			status := getDoorStatus(c.hub.u, sn, door.Number)
			payload := GateStatusPayload{
				DoorRecordID: door.ID,
				Status:       status,
			}
			message := WebSocketMessage{
				MessageType: "gateStatus",
				Payload:     payload,
			}
			jsonMessage, _ := json.Marshal(message)
			c.send <- jsonMessage
		}
	}
	log.Printf("INFO: Finished sending initial status to client %v", c.conn.RemoteAddr())
}

// getDoorStatus is a helper function to get a single door's status string.
func getDoorStatus(u uhppote.IUHPPOTE, controllerSN uint32, door uint8) string {
	state, err := u.GetDoorControlState(controllerSN, door)
	if err != nil {
		if config.Debug {
			log.Printf("DEBUG: Controller %d unreachable for status poll: %v", controllerSN, err)
		}
		return "down"
	}
	switch state.ControlState {
	case types.NormallyOpen:
		return "unlocked"
	case types.NormallyClosed:
		return "locked"
	case types.Controlled:
		return "intermediate"
	default:
		return "down"
	}
}

// broadcastDoorStatus is a helper function to send a status message to the hub.
func broadcastDoorStatus(hub *Hub, doorRecordID int, status string) {
	payload := GateStatusPayload{
		DoorRecordID: doorRecordID,
		Status:       status,
	}
	message := WebSocketMessage{
		MessageType: "gateStatus",
		Payload:     payload,
	}
	jsonMessage, _ := json.Marshal(message)
	hub.broadcast <- jsonMessage
}

