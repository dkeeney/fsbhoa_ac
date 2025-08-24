#!/bin/bash

# --- Set Graphical Session Environment ---
# This allows the script to launch a GUI application even when
# started from a non-graphical context like SSH or a system service.
export DISPLAY=:0
export XAUTHORITY=/home/fsbhoa/.Xauthority

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
BROWSER_CMD="firefox --kiosk --private-window $KIOSK_URL"

# A unique string to find the Firefox process
BROWSER_PROCESS_STRING="firefox --kiosk"

# Give other system services a moment to settle
sleep 5

# Infinite loop to monitor the service
while true; do
  RESTART_FLAG="/tmp/restart_kiosk_browser"
  if [ -f "$RESTART_FLAG" ]; then
      echo "Restart signal received. Restarting browser..."
      pkill -f "$BROWSER_PROCESS_STRING"
      rm -f "$RESTART_FLAG"
      sleep 2 # Give the browser a moment to close completely
  fi

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
