#!/bin/bash

# --- Set Graphical Session Environment ---
# Allows the script to launch a GUI app from SSH or a system service.
export DISPLAY=:0
export XAUTHORITY=/home/fsbhoa/.Xauthority

# ADDED: This is the primary fix to prevent printer and other OS notification popups.
# Run this on any computer that will serve as a dedicated kiosk display.
gsettings set org.gnome.desktop.notifications show-banners false

# --- Read Configuration from JSON File ---
CONFIG_FILE="/var/lib/fsbhoa/kiosk.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: Config file not found at $CONFIG_FILE"
    exit 1
fi

# Use jq to parse the hostname and port from the config file.
# This was your correct approach.
HOSTNAME=$(jq -r '.wordpress_api_base_url' "$CONFIG_FILE")
PORT=$(jq -r '.port' "$CONFIG_FILE" | sed 's/://g') # Read port and ensure no extra colons

# Combine them to create the full, correct network URL.
#KIOSK_URL="${HOSTNAME}:${PORT}"
KIOSK_URL="${HOSTNAME}:${PORT}/?auto_id=30&auto_name=Lodge+Lobby"
# --- End of Configuration ---


# The command to launch the browser using the correct network URL.
BROWSER_CMD="firefox --kiosk --private-window $KIOSK_URL"

# A unique string to find the Firefox process.
BROWSER_PROCESS_STRING="firefox --kiosk"

# Give other system services a moment to settle.
sleep 5

# Infinite loop to monitor the service.
while true; do
  RESTART_FLAG="/tmp/restart_kiosk_browser"
  if [ -f "$RESTART_FLAG" ]; then
      echo "Restart signal received. Restarting browser..."
      pkill -f "$BROWSER_PROCESS_STRING"
      rm -f "$RESTART_FLAG"
      sleep 2 # Give the browser a moment to close completely
  fi

  # Check if the Go service is running by connecting to the network URL.
  curl -s -k --head "$KIOSK_URL" > /dev/null

  if [ $? -eq 0 ]; then
    # --- SERVICE IS RUNNING ---
    if ! pgrep -f "$BROWSER_PROCESS_STRING" > /dev/null; then
      echo "Kiosk service is UP. Starting Firefox on $KIOSK_URL..."
      $BROWSER_CMD &
    fi
  else
    # --- SERVICE IS DOWN ---
    if pgrep -f "$BROWSER_PROCESS_STRING" > /dev/null; then
      echo "Kiosk service is DOWN. Closing Firefox..."
      pkill -f "$BROWSER_PROCESS_STRING"
    fi
  fi

  # Wait 5 seconds before the next check.
  sleep 5
done

