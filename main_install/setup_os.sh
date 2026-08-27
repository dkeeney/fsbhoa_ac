#!/bin/bash

# setup_os.sh
# CONFIGURATION: Sets up Users, Groups, Directories, Firewall, Services, Sudoers, and SSL tools.
# PREREQUISITE: Run install_lamp.sh first.

if [ "$EUID" -ne 0 ]; then
  echo "Please run this script with sudo."
  exit 1
fi


SERVICE_USER=$(logname)
echo "--- Configuring FSBHOA OS Environment for user: $SERVICE_USER ---"

# --- 1. SSL Tools (Certbot) ---
echo "[1/7] Installing Certbot..."
apt-get install -y certbot python3-certbot-apache
# Enable SSL modules but don't force config yet
a2enmod ssl
a2enmod headers
systemctl restart apache2

# --- 2. Directories & Permissions ---
echo "[2/7] Configuring Directories and Permissions..."
mkdir -p /var/lib/fsbhoa
mkdir -p /etc/fsbhoa
mkdir -p /var/log/fsbhoa

# Add Service User to www-data group for shared access
usermod -a -G www-data "$SERVICE_USER"

# Set Ownership & Sticky Bit (2775)
chown -R root:www-data /var/lib/fsbhoa
chown -R root:www-data /etc/fsbhoa
chown -R "$SERVICE_USER":"$SERVICE_USER" /var/log/fsbhoa

# 2775 = rwxrwsr-x (New files inherit group www-data)
chmod -R 2775 /var/lib/fsbhoa
chmod -R 2775 /etc/fsbhoa

# --- 3. Firewall ---
echo "[3/7] Configuring Firewall..."
# Disable UFW if present (common on Ubuntu) to let pure iptables take over
if command -v ufw >/dev/null 2>&1; then
    ufw disable
fi
# Helper: Only add rule if it doesn't exist
ensure_port() {
    local PROTO=$1
    local PORT=$2
    if ! iptables -C INPUT -p "$PROTO" --dport "$PORT" -j ACCEPT 2>/dev/null; then
        echo "Opening $PROTO port $PORT..."
        iptables -A INPUT -p "$PROTO" --dport "$PORT" -j ACCEPT
    else
        echo "Port $PORT ($PROTO) already open."
    fi
}

# Ensure basic access
if ! iptables -C INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT 2>/dev/null; then
    iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
fi

if ! iptables -C INPUT -i lo -j ACCEPT 2>/dev/null; then
    iptables -A INPUT -i lo -j ACCEPT
fi

# Application Ports
ensure_port tcp 22   # SSH
ensure_port tcp 80   # HTTP
ensure_port tcp 443  # HTTPS
ensure_port tcp 8080 # Kiosk
ensure_port tcp 8081 # Printer
ensure_port tcp 8082 # Monitor
ensure_port tcp 8083 # Events

# UDP Hardware Ports (Range 60000:60002)
if ! iptables -C INPUT -p udp --dport 60000:60002 -j ACCEPT 2>/dev/null; then
    echo "Opening UDP ports 60000-60002..."
    iptables -A INPUT -p udp --dport 60000:60002 -j ACCEPT
fi

iptables -P INPUT DROP
netfilter-persistent save


# --- 4. Systemd Service Files ---
echo "[4/7] Creating Service Definitions..."

# Event Service
cat << EOF > /etc/systemd/system/fsbhoa_events.service
[Unit]
Description=FSBHOA Hardware Event Service
After=network.target mysql.service

[Service]
Type=simple
User=$SERVICE_USER
Group=www-data
WorkingDirectory=/etc/fsbhoa
ExecStart=/usr/local/bin/fsbhoa_events
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# Monitor Service
cat << EOF > /etc/systemd/system/fsbhoa_monitor.service
[Unit]
Description=FSBHOA Monitor Service
After=network.target

[Service]
Type=simple
User=$SERVICE_USER
Group=www-data
WorkingDirectory=/etc/fsbhoa
ExecStart=/usr/local/bin/fsbhoa_monitor
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# Printer Service
cat << EOF > /etc/systemd/system/fsbhoa_printer.service
[Unit]
Description=FSBHOA Zebra Card Printer Service
After=network.target

[Service]
Type=simple
User=$SERVICE_USER
Group=www-data
WorkingDirectory=/var/lib/fsbhoa/zebra
ExecStart=/usr/local/bin/fsbhoa_printer
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# Kiosk Service
cat << EOF > /etc/systemd/system/fsbhoa_kiosk.service
[Unit]
Description=FSBHOA Resident Sign-in Kiosk App
After=network.target

[Service]
Type=simple
User=$SERVICE_USER
Group=www-data
WorkingDirectory=/var/lib/fsbhoa/kiosk
ExecStart=/usr/local/bin/fsbhoa_kiosk
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload

# --- 5. Sudoers for PHP Control ---
echo "[5/7] Configuring Sudoers..."
SUDO_FILE="/etc/sudoers.d/fsbhoa_php_admin"
cat << EOF > "$SUDO_FILE"
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start fsbhoa_monitor
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop fsbhoa_monitor
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart fsbhoa_monitor
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl status fsbhoa_monitor

www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start fsbhoa_events
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop fsbhoa_events
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart fsbhoa_events
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl status fsbhoa_events

www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start fsbhoa_printer
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop fsbhoa_printer
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart fsbhoa_printer
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl status fsbhoa_printer

www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start fsbhoa_kiosk
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop fsbhoa_kiosk
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart fsbhoa_kiosk
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl status fsbhoa_kiosk

www-data ALL=(ALL) NOPASSWD: /usr/sbin/shutdown
www-data ALL=(ALL) NOPASSWD: /usr/sbin/reboot
EOF

chmod 0440 "$SUDO_FILE"

# --- 6. Apache Virtual Host Configuration ---
echo "[6/7] Configuring Apache Virtual Host for SSL..."

cat << EOF > /etc/apache2/sites-available/fsbhoa-access.conf
<VirtualHost *:80>
    ServerName access.fsbhoa.com
    Redirect permanent / https://access.fsbhoa.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName access.fsbhoa.com
    DocumentRoot /var/www/html

    SSLEngine on
    # These paths match what Certbot will create in the manual step
    SSLCertificateFile    /etc/letsencrypt/live/access.fsbhoa.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/access.fsbhoa.com/privkey.pem

    <Directory /var/www/html>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

# Enable the site, but don't restart yet (Cert doesn't exist)
a2ensite fsbhoa-access.conf

# --- 7. Final Instructions ---
echo ""
echo "=================================================================="
echo "[7/7]   FSBHOA SYSTEM SETUP COMPLETE"
echo "=================================================================="
echo ""
echo "STEP 1: DEPLOY APPLICATIONS"
echo "   Run the deployment script to compile and install the software:"
echo "   ./deploy_services.sh"
echo ""
echo "STEP 2: CONFIGURE SSL (REQUIRED FOR WEBCAM)"
echo "   Because this server is on an internal network, you must use"
echo "   DNS validation to get a valid certificate."
echo ""
echo "   Run this command exactly:"
echo "   sudo certbot certonly --manual --preferred-challenges dns -d access.fsbhoa.com"
echo ""
echo "   INSTRUCTIONS FOR CERTBOT:"
echo "   1. It will ask for your email (enter it)."
echo "   2. It will display a text string (e.g., '_acme-challenge...')."
echo "   3. Go to your Domain Registrar (GoDaddy/Namecheap) DNS settings."
echo "   4. Create a TXT record named '_acme-challenge' with that value."
echo "   5. Wait 60 seconds, then press Enter in this terminal."
echo ""
echo "STEP 3: ACTIVATE SSL (AFTER STEP 2 IS DONE)"
echo "   Once Certbot has successfully created the certificates,"
echo "   run this command to enable the secure site:"
echo "   sudo systemctl reload apache2"
echo "=================================================================="
echo ""

