package main

import (
    "bytes"
    "encoding/base64"
    "encoding/json"
    "flag"
    "fmt"
    "image"
    "image/draw"
    "image/png"
    "log"
    "net/http"
    "os"
    "os/exec"
    "time"

    "golang.org/x/image/font"
    "golang.org/x/image/font/basicfont"
    "golang.org/x/image/math/fixed"
)

// --- Global Configuration ---
var config Config

// --- Data Structures ---
type PrintRequestPayload struct {
    LogID       int               `json:"log_id"`
    TemplateXML string            `json:"template_xml"` // Note: this is now unused but kept for API compatibility
    Fields      map[string]string `json:"fields"`
}

// --- Core Logic ---

// updatePrintStatusInWordPress sends a status update to our secure endpoint.
func updatePrintStatusInWordPress(logID int, status string, message string) {
    payload := map[string]interface{}{
        "log_id":         logID,
        "status":         status,
        "status_message": message,
    }
    jsonData, err := json.Marshal(payload)
    if err != nil {
        log.Printf("[LogID: %d] Error marshalling status update JSON: %v", logID, err)
        return
    }
    req, err := http.NewRequest("POST", config.ApiURL, bytes.NewBuffer(jsonData))
    if err != nil {
        log.Printf("[LogID: %d] Error creating API request: %v", logID, err)
        return
    }
    req.Header.Set("Content-Type", "application/json")
    req.Header.Set("X-Internal-API-Key", config.ApiToken)
    client := &http.Client{Timeout: 10 * time.Second}
    resp, err := client.Do(req)
    if err != nil {
        log.Printf("[LogID: %d] Error sending status update to WordPress: %v", logID, err)
        return
    }
    defer resp.Body.Close()
    if resp.StatusCode != http.StatusOK {
        log.Printf("[LogID: %d] WordPress API returned a non-200 status for update: %s", logID, resp.Status)
    } else {
        log.Printf("[LogID: %d] Successfully updated status to '%s' via API.", logID, status)
    }
}

// createImage combines the photo and text into a single image file.
func createImage(payload PrintRequestPayload) (string, error) {
    // Decode the base64 photo
    photoData, err := base64.StdEncoding.DecodeString(payload.Fields["residentPhoto"])
    if err != nil {
        return "", fmt.Errorf("could not decode photo: %v", err)
    }
    photoImg, _, err := image.Decode(bytes.NewReader(photoData))
    if err != nil {
        return "", fmt.Errorf("could not decode photo data into image: %v", err)
    }

    // Create a new blank card canvas (dimensions are for a standard CR80 card at 300 DPI)
    cardWidth, cardHeight := 1016, 640
    cardCanvas := image.NewRGBA(image.Rect(0, 0, cardWidth, cardHeight))

    // Draw the photo onto the canvas
    // These coordinates would come from a template file in a full implementation
    photoRect := image.Rect(40, 40, 40+375, 40+450)
    draw.Draw(cardCanvas, photoRect, photoImg, image.Point{}, draw.Src)

    // Draw the text onto the canvas
    fullName := fmt.Sprintf("%s %s", payload.Fields["firstName"], payload.Fields["lastName"])
    addLabel(cardCanvas, 500, 200, fullName)

    // Create a temporary file to save the final image
    tmpFile, err := os.CreateTemp("", fmt.Sprintf("printjob-%d-*.png", payload.LogID))
    if err != nil {
        return "", fmt.Errorf("could not create temp file: %v", err)
    }
    defer tmpFile.Close()

    // Save the canvas as a PNG file
    if err := png.Encode(tmpFile, cardCanvas); err != nil {
        return "", fmt.Errorf("could not encode image to PNG: %v", err)
    }

    return tmpFile.Name(), nil
}

// addLabel is a helper to draw text on an image.
func addLabel(img *image.RGBA, x, y int, label string) {
    point := fixed.Point26_6{X: fixed.I(x), Y: fixed.I(y)}
    d := &font.Drawer{
        Dst:  img,
        Src:  image.Black,
        Face: basicfont.Face7x13, // Using a basic built-in font
        Dot:  point,
    }
    d.DrawString(label)
}


// doPrintJob now generates an image and calls the rastertojg CLI tool.
func doPrintJob(payload PrintRequestPayload) {
    logID := payload.LogID
    log.Printf("[LogID: %d] Starting CLI-based print job.", logID)

    // 1. Generate the card front image
    imagePath, err := createImage(payload)
    if err != nil {
        errMsg := fmt.Sprintf("Failed to generate print image: %v", err)
        log.Printf("[LogID: %d] %s", logID, errMsg)
        updatePrintStatusInWordPress(logID, "failed_error", errMsg)
        return
    }
    defer os.Remove(imagePath) // Clean up the temp file when done
    log.Printf("[LogID: %d] Successfully created temporary print image at %s", logID, imagePath)

    // 2. Execute the rastertojg command
    cmd := exec.Command("/usr/local/ZebraJaguarDriver/rastertojg",
        fmt.Sprintf("%d", logID), // job-id
        "fsbhoa",                 // user
        "Card Print",             // title
        "1",                      // copies
        "",                       // options (can be used for printer name, etc.)
        imagePath,                // file
    )

    output, err := cmd.CombinedOutput()
    if err != nil {
        errMsg := fmt.Sprintf("rastertojg command failed: %v. Output: %s", err, string(output))
        log.Printf("[LogID: %d] %s", logID, errMsg)
        updatePrintStatusInWordPress(logID, "failed_error", errMsg)
        return
    }

    finalMessage := fmt.Sprintf("Print job successfully submitted via rastertojg. Output: %s", string(output))
    log.Printf("[LogID: %d] %s", logID, finalMessage)
    updatePrintStatusInWordPress(logID, "completed_ok", finalMessage)
}

// --- HTTP Handlers ---
func printCardHandler(w http.ResponseWriter, r *http.Request) {
    if r.Method != http.MethodPost {
        http.Error(w, "Only POST method is accepted", http.StatusMethodNotAllowed)
        return
    }
    var payload PrintRequestPayload
    if err := json.NewDecoder(r.Body).Decode(&payload); err != nil {
        http.Error(w, "Error parsing JSON payload", http.StatusBadRequest)
        return
    }
    log.Printf("Received print request for LogID: %d", payload.LogID)
    updatePrintStatusInWordPress(payload.LogID, "printing", "Job received by Go service.")
    go doPrintJob(payload)
    w.WriteHeader(http.StatusAccepted)
    fmt.Fprintf(w, "Job for LogID %d accepted and is being processed.", payload.LogID)
}

func main() {
    configFile := flag.String("config", "/var/lib/fsbhoa/zebra_print_service.json", "Path to the JSON configuration file.")
    flag.Parse()
    var err error
    config, err = LoadConfig(*configFile)
    if err != nil {
        log.Fatalf("FATAL: Could not load config file '%s': %v", *configFile, err)
    }
    log.Printf("Configuration loaded successfully from %s", *configFile)
    http.HandleFunc("/print_card", printCardHandler)
    http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
        fmt.Fprintf(w, "FSBHOA Go Printer Service is running!")
    })
    addr := fmt.Sprintf(":%d", config.Port)
    log.Printf("Starting Zebra Print Service on http://127.0.0.1%s...", addr)
    if err := http.ListenAndServe(addr, nil); err != nil {
        log.Fatal("ListenAndServe: ", err)
    }
}

