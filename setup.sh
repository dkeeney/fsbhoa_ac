#!/bin/bash

# ==============================================================================
# FSBHOA Access Control - Application Setup Script
# ==============================================================================
# This script is designed to be run on a server that already has a
# functional LAMP (Linux, Apache, MySQL, PHP) stack.
#
# It handles the application-specific setup:
#   1. Installs Go, jq, and SSL tool dependencies.
#   2. Creates the necessary configuration & temporary directories.
#   3. Sets correct, cross-service user permissions.
#   4. Compiles all Go service binaries.
#   5. Creates, enables, and starts the systemd services.
#   6. Grants the web user permissions to manage the services.
#
# To run:
#   1. Clone the fsbhoa_ac repository.
#   2. Navigate into the repository directory.
#   3. Make the script executable: chmod +x setup.sh
#   4. Run with sudo: sudo ./setup.sh
# ==============================================================================

# --- Color Codes for Output ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# --- Safety Check: Must be run with sudo ---
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}Please run this script with sudo.${NC}"
  exit 1
fi

echo -e "${GREEN}--- Starting FSBHOA Application Setup ---${NC}"

# --- Get the correct non-root username ---
read -p "Please enter the non-root username that owns the fsbhoa_ac project files (e.g., fsbhoa): " username

if ! id -u "$username" >/dev/null 2>&1; then
    echo -e "${RED}Error: User '$username' does not exist. Aborting.${NC}"
    exit 1
fi
USER_HOME=$(eval echo ~$username)
PROJECT_DIR="$USER_HOME/fsbhoa_ac"

if [ ! -d "$PROJECT_DIR" ]; then
    echo -e "${RED}Error: Project directory '$PROJECT_DIR' not found. Please clone the repository first.${NC}"
    exit 1
fi

# --- 1. System Dependencies ---
echo -e "\n${YELLOW}[1/7] Installing Go, jq, and SSL tools (certbot, acl)...${NC}"
apt-get update > /dev/null
apt-get install -y golang-go jq certbot acl || { echo -e "${RED}Failed to install dependencies.${NC}"; exit 1; }

# --- 2. Create Application Configuration Directory ---
echo -e "\n${YELLOW}[2/7] Creating configuration directory /var/lib/fsbhoa...${NC}"
mkdir -p /var/lib/fsbhoa
chown -R www-data:www-data /var/lib/fsbhoa
chmod -R 775 /var/lib/fsbhoa

# --- 3. NEW: Create Service-Writable Directories & Set Permissions ---
echo -e "\n${YELLOW}[3/7] Setting up shared directories and permissions...${NC}"
PRINT_TEMP_DIR="/var/www/html/wp-content/uploads/fsbhoa_print_temp"
echo "Creating print temp directory: $PRINT_TEMP_DIR"
mkdir -p "$PRINT_TEMP_DIR"
chown www-data:www-data "$PRINT_TEMP_DIR"

echo "Adding user '$username' to the 'www-data' group for shared access..."
usermod -a -G www-data "$username"

echo "Setting group-write permissions on print temp directory..."
chmod g+w "$PRINT_TEMP_DIR"
chmod g+s "$PRINT_TEMP_DIR" # Ensures new files inherit the group

# --- 4. Compile Go Service Binaries ---
echo -e "\n${YELLOW}[4/7] Compiling Go services...${NC}"
SERVICES=("event_service" "monitor_service" "kiosk" "zebra_print_service")
for service in "${SERVICES[@]}"; do
    if [ -d "$PROJECT_DIR/$service" ]; then
        echo "Compiling $service..."
        (cd "$PROJECT_DIR/$service" && go build) || { echo -e "${RED}Failed to compile $service.${NC}"; exit 1; }
        chown "$username:$username" "$PROJECT_DIR/$service/$service"
    else
        echo -e "${YELLOW}Warning: Directory for service '$service' not found. Skipping compilation.${NC}"
    fi
done

# --- 5. Create systemd Service Files for Go Apps ---
echo -e "\n${YELLOW}[5/7] Creating systemd service files...${NC}"
# (Service file creation logic is unchanged)
cat << EOF > /etc/systemd/system/event_service.service
[Unit]
Description=FSBHOA Hardware Event Service
After=network.target
[Service]
Type=simple
User=$username
Group=$(id -gn "$username")
WorkingDirectory=$PROJECT_DIR/event_service
ExecStart=$PROJECT_DIR/event_service/event_service
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF
# ... (repeat for monitor, zebra_print, and kiosk services)
cat << EOF > /etc/systemd/system/monitor_service.service
[Unit]
Description=FSBHOA Monitor Service
After=network.target
[Service]
Type=simple
User=$username
Group=$(id -gn "$username")
WorkingDirectory=$PROJECT_DIR/monitor_service
ExecStart=$PROJECT_DIR/monitor_service/monitor_service
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF
cat << EOF > /etc/systemd/system/zebra_print_service.service
[Unit]
Description=FSBHOA Zebra Card Printer Service
After=network.target
[Service]
Type=simple
User=$username
Group=$(id -gn "$username")
WorkingDirectory=$PROJECT_DIR/zebra_print_service
ExecStart=$PROJECT_DIR/zebra_print_service/zebra_print_service
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF
cat << EOF > /etc/systemd/system/kiosk.service
[Unit]
Description=FSBHOA Resident Sign-in Kiosk App
After=network.target
[Service]
Type=simple
User=$username
Group=$(id -gn "$username")
WorkingDirectory=$PROJECT_DIR/kiosk
ExecStart=$PROJECT_DIR/kiosk/kiosk
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

# --- 6. Enable and Start Services ---
echo -e "\n${YELLOW}[6/7] Enabling and starting services...${NC}"
systemctl daemon-reload
for service in "${SERVICES[@]}"; do
    echo "Enabling and starting ${service}..."
    systemctl enable "${service}.service"
    systemctl start "${service}.service"
done

# --- 7. Grant Web User Sudo Permissions ---
echo -e "\n${YELLOW}[7/7] Granting web user permissions to manage services...${NC}"
SUDOERS_FILE="/etc/sudoers.d/30-fsbhoa-services"
cat << EOF > $SUDOERS_FILE
# This file grants the www-data user (which runs PHP) the specific
# permissions needed to start, stop, restart, and check the status
# of the FSBHOA backend services from the WordPress admin dashboard.
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start event_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop event_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart event_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status event_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start monitor_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop monitor_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart monitor_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status monitor_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start zebra_print_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop zebra_print_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart zebra_print_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status zebra_print_service.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl start kiosk.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop kiosk.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart kiosk.service
www-data ALL=(ALL) NOPASSWD: /bin/systemctl status kiosk.service
EOF
chmod 0440 $SUDOERS_FILE

echo "Permissions granted. The System Status page in WordPress should now be fully functional."
echo -e "\n${YELLOW}Final status of all services:${NC}"
for service in "${SERVICES[@]}"; do
    systemctl --no-pager status "${service}.service"
done

echo -e "\n${GREEN}--- Application Setup Complete ---${NC}"
echo "All Go services have been compiled, registered, started, and are now manageable from WordPress."


