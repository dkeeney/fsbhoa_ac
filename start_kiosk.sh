#!/bin/bash

# --- Set Graphical Session Environment ---
REAL_USER="${USER:-$(whoami)}"
export DISPLAY=:0
export XAUTHORITY="/home/$REAL_USER/.Xauthority"

# Ensure Firefox uses X11 and ignores native touch OSK triggers
export MOZ_USE_XINPUT2=0
export MOZ_ENABLE_WAYLAND=0

# Standard GNOME Kiosk silencing
gsettings set org.gnome.desktop.a11y.applications screen-keyboard-enabled false
gsettings set org.gnome.desktop.notifications show-banners false 2>/dev/null

# Clean up any previous session crashes
pkill -9 firefox
pkill -9 firefox-bin
find ~/.mozilla/firefox/ -name "*lock" -delete 2>/dev/null

# --- Read Configuration from JSON File ---
CONFIG_FILE="/var/lib/fsbhoa/kiosk.json"
if [ ! -f "$CONFIG_FILE" ]; then
    echo "ERROR: Config file not found at $CONFIG_FILE"
    exit 1
fi

WP_URL=$(jq -r '.wordpress_api_base_url' "$CONFIG_FILE" | sed 's|/$||')
PORT=$(jq -r '.port' "$CONFIG_FILE" | sed 's/://g')
if [ -z "$PORT" ] || [ "$PORT" == "null" ]; then PORT="8080"; fi

KIOSK_URL="${WP_URL}:${PORT}/?auto_name=LobbyKiosk"

# --- Launch Logic ---
mkdir -p /tmp/firefox_kiosk_profile
BROWSER_CMD="firefox --kiosk --private-window --profile /tmp/firefox_kiosk_profile $KIOSK_URL"

echo "Launching Kiosk for: $KIOSK_URL"
sleep 5

while true; do
    RESTART_FLAG="/tmp/restart_kiosk_browser"
    
    # Check states
    curl -s -k --head "$KIOSK_URL" > /dev/null
    SERVICE_UP=$?
    pgrep -f "firefox --kiosk" > /dev/null
    BROWSER_RUNNING=$?

    # CASE 1: Restart signal received
    if [ -f "$RESTART_FLAG" ]; then
        echo "Restart signal received."
        pkill -9 -f "firefox"
        rm -f "$RESTART_FLAG"
        find /tmp/firefox_kiosk_profile -name "*lock" -delete 2>/dev/null
        sleep 2
    
    # CASE 2: Service is UP but Browser is DOWN
    elif [ $SERVICE_UP -eq 0 ] && [ $BROWSER_RUNNING -ne 0 ]; then
        echo "Service is UP. Starting Firefox..."
        find /tmp/firefox_kiosk_profile -name "*lock" -delete 2>/dev/null
        $BROWSER_CMD &
        sleep 5 

    # CASE 3: Service is DOWN but Browser is UP
    elif [ $SERVICE_UP -ne 0 ] && [ $BROWSER_RUNNING -eq 0 ]; then
        echo "Service is DOWN. Closing Firefox..."
        pkill -f "firefox --kiosk"
    fi

    sleep 5
done

