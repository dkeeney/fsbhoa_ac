#!/bin/bash

# --- Read Configuration from JSON File ---
CONFIG_FILE="/var/lib/fsbhoa/kiosk.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: Config file not found at $CONFIG_FILE"
    exit 1
fi

# Use jq to parse the URL and Port from the config file
BASE_URL=$(jq -r '.wordpress_api_base_url' "$CONFIG_FILE")
PORT=$(jq -r '.port' "$CONFIG_FILE")

# Combine them to create the full URL for the browser
KIOSK_URL="${BASE_URL}${PORT}"
# --- End of Configuration ---


# The command to launch the browser, now using the dynamic URL
BROWSER_CMD="firefox --kiosk $KIOSK_URL"

# A unique string to find the Firefox process
BROWSER_PROCESS_STRING="firefox --kiosk"

# Give other system services a moment to settle
sleep 5

# Infinite loop to monitor the service
while true; do

  # Check if the Go service is running by connecting to the URL from the config
  curl -s -k --head "$KIOSK_URL" > /dev/null
  
  if [ $? -eq 0 ]; then
    # --- SERVICE IS RUNNING ---
    
    # If browser is not running, start it.
    if ! pgrep -f "$BROWSER_PROCESS_STRING" > /dev/null; then
      echo "Kiosk service is UP. Starting Firefox..."
      $BROWSER_CMD &
    fi
  else
    # --- SERVICE IS DOWN ---
    
    # If service is down, make sure the browser is closed.
    if pgrep -f "$BROWSER_PROCESS_STRING" > /dev/null; then
      echo "Kiosk service is DOWN. Closing Firefox..."
      pkill -f "$BROWSER_PROCESS_STRING"
    fi
  fi
  
  # Wait 5 seconds before the next check.
  sleep 5
done
