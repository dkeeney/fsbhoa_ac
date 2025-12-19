#!/bin/bash

# --- Set Graphical Session Environment ---
# Detect the real user to set the correct Xauthority path
REAL_USER="${USER:-$(whoami)}"
export DISPLAY=:0
export XAUTHORITY="/home/$REAL_USER/.Xauthority"

# Disable notification popups (Printer/Update warnings)
if command -v gsettings >/dev/null; then
    gsettings set org.gnome.desktop.notifications show-banners false 2>/dev/null
fi

# --- Read Configuration from JSON File ---
CONFIG_FILE="/var/lib/fsbhoa/kiosk.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: Config file not found at $CONFIG_FILE"
    exit 1
fi

# 1. Get Base URL (e.g. "https://testbed.fsbhoa.com")
# We strip a trailing slash just in case one was added by mistake.
WP_URL=$(jq -r '.wordpress_api_base_url' "$CONFIG_FILE" | sed 's|/$||')

# 2. Get Port (e.g. ":8080" -> "8080")
# We strip the colon to ensure we don't end up with double colons later.
PORT=$(jq -r '.port' "$CONFIG_FILE" | sed 's/://g')
if [ -z "$PORT" ] || [ "$PORT" == "null" ]; then PORT="8080"; fi

# 3. Construct URL
KIOSK_URL="${WP_URL}:${PORT}/?auto_name=Lobby+Kiosk"

echo "Launching Kiosk for: $KIOSK_URL"
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

  # Check if the Go service is running by connecting to the LOCAL URL.
  # We use -L to follow redirects if necessary.
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

