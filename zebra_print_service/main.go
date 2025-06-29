package main

/*
#cgo linux LDFLAGS: -L/usr/local/ZebraJaguarDriver -lzmjxml -ltinyxml
#cgo windows LDFLAGS: -lZBRPrinter -lZBRGraphics
// This is where we would include the real C header files from the SDK once we find them.
// For example:
// #include "ZBRPrinter.h"
*/
import "C" // This special import enables cgo

import (
	"encoding/json"
    "flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"strings"
	"sync"
	"time"
)

// --- Data Structures ---

type PrintRequestPayload struct {
	RfidId              string `json:"rfid_id"`
	FirstName           string `json:"first_name"`
	LastName            string `json:"last_name"`
	PropertyAddressText string `json:"property_address_text"`
	PhotoBase64         string `json:"photo_base64"`
	ResidentType        string `json:"resident_type"`
	CardIssueDate       string `json:"card_issue_date"`
	CardExpiryDate      string `json:"card_expiry_date"`
	SubmittedByUser     string `json:"submitted_by_user"`
}

type PrintJob struct {
	PrinterJobID string
	SystemJobID  string
	Status       string
	Message      string
	SubmittedAt  time.Time
	IsTerminal   bool
}

type StatusResponse struct {
	Status  string `json:"status"`
	Message string `json:"status_message"`
}

// --- In-Memory Job Store ---

var (
	jobStore = make(map[string]*PrintJob)
	jobMutex = &sync.Mutex{}
)

// --- Core SDK Logic ---

// This function now contains the placeholder logic for real SDK interaction.
func submitPrintJobToSDK(payload PrintRequestPayload) (string, error) {
	log.Println("Connecting to printer via Zebra SDK...")

	// The code below is a representation of the steps we would take.
	// The actual function names (e.g., ZBRGetHandle) must be confirmed
	// from the SDK's header files when the printer is available.

	/*
		// Step 1: Discover and get a handle to the printer.
		var errorCode C.int
		printerHandle := C.ZBRGetHandle(C.CString("USB"), nil, &errorCode)
		if errorCode != 0 {
			return "", fmt.Errorf("could not get printer handle, error code: %d", errorCode)
		}
		defer C.ZBRCloseHandle(printerHandle)
		log.Println("SDK: Printer connection established.")

		// Step 2: Create graphics for the card.
		var graphicsHandle C.HANDLE
		C.ZBRGraphicsInit(&graphicsHandle, ...) // Initialize graphics context
		defer C.ZBRGraphicsClose(graphicsHandle)

		// Draw text elements
		C.ZBRGraphicsDrawText(graphicsHandle, C.CString(payload.FirstName + " " + payload.LastName), ...)
		C.ZBRGraphicsDrawText(graphicsHandle, C.CString(payload.PropertyAddressText), ...)

		// Decode the Base64 image and draw it
		photoBytes, err := base64.StdEncoding.DecodeString(payload.PhotoBase64)
		if err != nil {
			return "", fmt.Errorf("invalid base64 photo data: %v", err)
		}
		C.ZBRGraphicsDrawImage(graphicsHandle, photoBytes, ...)
		log.Println("SDK: Graphics created for the card.")


		// Step 3: Send the print job.
		var jobId C.int
		C.ZBRPrint(printerHandle, graphicsHandle, &jobId, &errorCode)
		if errorCode != 0 {
			return "", fmt.Errorf("failed to send print job, error code: %d", errorCode)
		}

		printerJobId := fmt.Sprintf("%d", jobId)
	*/

	// For now, until the printer arrives, we will return a simulated job ID.
	printerJobId := fmt.Sprintf("REAL_GO_JOB_%d", time.Now().UnixNano())
	log.Printf("SDK: Print job submitted. Printer Job ID: %s (simulated)", printerJobId)

	return printerJobId, nil
}

// --- HTTP Handlers ---

func printCardHandler(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Only POST method is accepted", http.StatusMethodNotAllowed)
		return
	}

	body, err := io.ReadAll(r.Body)
	if err != nil {
		http.Error(w, "Error reading request body", http.StatusInternalServerError)
		return
	}
	defer r.Body.Close()

	var payload PrintRequestPayload
	if err := json.Unmarshal(body, &payload); err != nil {
		http.Error(w, "Error parsing JSON payload", http.StatusBadRequest)
		return
	}

	log.Printf("Received print request for: %s %s", payload.FirstName, payload.LastName)

	printerJobId, err := submitPrintJobToSDK(payload)
	if err != nil {
		log.Printf("Error submitting job to SDK: %v", err)
		http.Error(w, "Error submitting job to SDK", http.StatusInternalServerError)
		return
	}

	systemJobID := fmt.Sprintf("go_sys_job_%d", time.Now().UnixNano())
	newJob := &PrintJob{
		PrinterJobID: printerJobId,
		SystemJobID:  systemJobID,
		Status:       "PRINTING",
		Message:      "Job is currently printing.",
		SubmittedAt:  time.Now(),
		IsTerminal:   false,
	}

	jobMutex.Lock()
	jobStore[printerJobId] = newJob
	jobMutex.Unlock()

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	responseMap := map[string]string{
		"status":          "queued",
		"message":         "Print job successfully queued by Go service",
		"system_job_id":  systemJobID,
		"printer_job_id": printerJobId,
	}
	json.NewEncoder(w).Encode(responseMap)
}

func printStatusHandler(w http.ResponseWriter, r *http.Request) {
	jobID := strings.TrimPrefix(r.URL.Path, "/print-status/")
	if jobID == "" {
		http.Error(w, "Missing job ID", http.StatusBadRequest)
		return
	}

	log.Printf("Received status check for printer job ID: %s", jobID)

	jobMutex.Lock()
	job, exists := jobStore[jobID]
	jobMutex.Unlock()

	if !exists {
		http.Error(w, "Job ID not found", http.StatusNotFound)
		return
	}

	/*
		// This is where the real SDK call to check status would go.
		var status C.int
		var messageBuffer [256]C.char
		C.ZBRGetJobStatus(job.PrinterJobID, &status, &messageBuffer[0], 256)
		job.Status = C.GoString(status)
		job.Message = C.GoString(&messageBuffer[0])
	*/

	// For now, we simulate the job's progress.
	if !job.IsTerminal && time.Since(job.SubmittedAt).Seconds() > 15 {
		job.Status = "COMPLETED_OK"
		job.Message = "Print completed successfully (simulated)."
		job.IsTerminal = true
		log.Printf("Job %s has reached a terminal state: %s", jobID, job.Status)
		// Here we would also delete the temporary image file.
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	response := StatusResponse{
		Status:  job.Status,
		Message: job.Message,
	}
	json.NewEncoder(w).Encode(response)
}

func main() {
        // Define a command-line flag for the port number
        port := flag.Int("port", 8081, "Port number for the print service")
        flag.Parse()

        http.HandleFunc("/print_card", printCardHandler)
        http.HandleFunc("/print-status/", printStatusHandler)

        http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
                fmt.Fprintf(w, "FSBHOA Go Printer Service is running!")
        })

        addr := fmt.Sprintf(":%d", *port)
        log.Printf("Starting Zebra Print Service on port %s...", addr)
        if err := http.ListenAndServe(addr, nil); err != nil {
                log.Fatal("ListenAndServe: ", err)
        }
}

