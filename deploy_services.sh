#!/bin/bash

# deploy_services.sh
# CONFIGURATION: Compiles Go binaries and deploys to Production locations.
# PREREQUISITE: Run setup_os.sh first.

INSTALL_BIN="/usr/local/bin"
INSTALL_ASSETS="/var/lib/fsbhoa"
PLUGIN_DIR="/var/www/html/wp-content/plugins/fsbhoa-access-control"

echo "--- Starting Service Deployment ---"

# 1. MONITOR SERVICE
echo "[1/5] Building Monitor..."
cd monitor_service
go build -o fsbhoa_monitor main.go config.go hub.go ws_client.go notify_handler.go
sudo systemctl stop fsbhoa_monitor
sudo cp fsbhoa_monitor "$INSTALL_BIN/"
sudo chmod +x "$INSTALL_BIN/fsbhoa_monitor"
cd ..

# 2. KIOSK SERVICE
echo "[2/5] Building Kiosk..."
cd kiosk
go build -o fsbhoa_kiosk main.go
sudo systemctl stop fsbhoa_kiosk
sudo cp fsbhoa_kiosk "$INSTALL_BIN/"
sudo chmod +x "$INSTALL_BIN/fsbhoa_kiosk"

# Deploy Kiosk Web Assets
echo "      Deploying Web Assets..."
sudo mkdir -p "$INSTALL_ASSETS/kiosk/web"
sudo cp -r web/* "$INSTALL_ASSETS/kiosk/web/"
sudo chown -R root:www-data "$INSTALL_ASSETS/kiosk"
sudo chmod -R 2775 "$INSTALL_ASSETS/kiosk"
cd ..

# 3. ZEBRA PRINT SERVICE
echo "[3/5] Building Printer Service..."
cd zebra_print_service
go build -o fsbhoa_printer main.go config.go
sudo systemctl stop fsbhoa_printer
sudo cp fsbhoa_printer "$INSTALL_BIN/"
sudo chmod +x "$INSTALL_BIN/fsbhoa_printer"

# Deploy Templates
sudo mkdir -p "$INSTALL_ASSETS/zebra/templates"
sudo cp -r templates/* "$INSTALL_ASSETS/zebra/templates/"
cd ..

# 4. EVENT SERVICE
echo "[4/5] Building Event Service..."
cd event_service
go build -o fsbhoa_events main.go config.go hub.go status_poller.go event_handler.go types.go
sudo systemctl stop fsbhoa_events
sudo cp fsbhoa_events "$INSTALL_BIN/"
sudo chmod +x "$INSTALL_BIN/fsbhoa_events"
cd ..

# 5. WORDPRESS PLUGIN
echo "[5/5] Deploying WordPress Plugin..."
# We use rsync to cleanly mirror the directory structure
if [ ! -d "wordpress_plugin/fsbhoa-access-control" ]; then
    echo "ERROR: Plugin source directory not found!"
    exit 1
fi

sudo mkdir -p "$PLUGIN_DIR"
sudo rsync -av --delete wordpress_plugin/fsbhoa-access-control/ "$PLUGIN_DIR/"
sudo chown -R www-data:www-data "$PLUGIN_DIR"

echo "--- Restarting All Services ---"
sudo systemctl daemon-reload
sudo systemctl start fsbhoa_monitor
sudo systemctl start fsbhoa_kiosk
sudo systemctl start fsbhoa_printer
sudo systemctl start fsbhoa_events

echo "Deployment Complete."
echo "Check status: sudo systemctl status fsbhoa_monitor"

