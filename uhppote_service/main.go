package main

import (
	"fmt"
	"log"
	"time"

	// This is the new, corrected import path for the library.
	"github.com/uhppoted/uhppote-core/uhppote"
)

func main() {
	log.Println("Attempting discovery...")

	// Create a new UHPPOTE object, explicitly binding to your NAS IP and broadcast address.
	u := uhppote.UHPPOTE{
		BindAddress:      "192.168.42.99",
		BroadcastAddress: "192.168.42.255",
		Timeout:          5 * time.Second,
		Debug:            true, // Enable debug output
	}

	// This is the core function that sends the UDP broadcast.
	devices, err := u.GetDevices()
	if err != nil {
		log.Fatalf("ERROR during discovery: %v", err)
	}

	if len(devices) == 0 {
		log.Println(">> SUCCESS: The broadcast was sent, but no devices were found.")
	} else {
		log.Println(">> SUCCESS! Found devices:")
		for _, device := range devices {
			fmt.Printf("   - Serial Number: %v, IP Address: %v\n", device.SerialNumber, device.IpAddress)
		}
	}
}

