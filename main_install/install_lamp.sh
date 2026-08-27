#!/bin/bash

# install_lamp.sh
# PURPOSE: Install Apache, MySQL, PHP, phpMyAdmin, and WordPress on a fresh Raspberry Pi OS.
# PREREQUISITE: Internet connection.

if [ "$EUID" -ne 0 ]; then
  echo "Please run this script with sudo."
  exit 1
fi

echo "--- [1/5] Updating System and Installing Packages ---"
apt-get update
apt-get upgrade -y

# Install Core Stack
# Use noninteractive mode to prevent "Save Rules?" popups on Ubuntu
# Note: We install 'expect' to automate the mysql_secure_installation if needed, 
# but for simplicity in this script, we will set root pass manually.
DEBIAN_FRONTEND=noninteractive apt-get install -y \
    apache2 mariadb-server php php-mysql libapache2-mod-php \
    php-xml php-mbstring php-curl php-zip php-gd php-json php-imagick \
    imagemagick unzip git golang-go iptables-persistent firefox \
    jq

# Install phpMyAdmin (Automated)
echo "phpmyadmin phpmyadmin/dbconfig-install boolean true" | debconf-set-selections
echo "phpmyadmin phpmyadmin/app-password-confirm password root" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/admin-pass password root" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/app-pass password root" | debconf-set-selections
echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2" | debconf-set-selections
apt-get install -y phpmyadmin

echo "--- [2/5] Database Setup ---"
# Create WordPress DB and User
# CHANGE 'fsbhoa_pass' TO A SECURE PASSWORD FOR PRODUCTION
mysql -u root -e "CREATE DATABASE IF NOT EXIST fsbhoa_db DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;"
mysql -u root -e "CREATE USER IF NOT EXIST 'wp_user'@'localhost' IDENTIFIED BY 'fsbhoa_pass';"
mysql -u root -e "GRANT ALL ON wordpress.* TO 'wp_user'@'localhost';"
mysql -u root -e "FLUSH PRIVILEGES;"


echo "--- [3/5] Installing WordPress ---"
# CHECK: Only install if config is missing
if [ -f "/var/www/html/wp-config.php" ]; then
    echo "WordPress appears to be configured already. Skipping core install."
else
    echo "Downloading and Configuring WordPress..."
    cd /tmp
    wget https://wordpress.org/latest.tar.gz
    tar -xzvf latest.tar.gz
    rm /var/www/html/index.html
    cp -r /tmp/wordpress/* /var/www/html/
    chown -R www-data:www-data /var/www/html/
    chmod -R 755 /var/www/html/

    # Config setup
    cp /var/www/html/wp-config-sample.php /var/www/html/wp-config.php
    sed -i "s/database_name_here/wordpress/" /var/www/html/wp-config.php
    sed -i "s/username_here/wp_user/" /var/www/html/wp-config.php
    sed -i "s/password_here/fsbhoa_pass/" /var/www/html/wp-config.php
    sed -i "/That's all, stop editing/i define('FS_METHOD', 'direct');" /var/www/html/wp-config.php
fi

echo "--- [4/5] Installing Astra Theme ---"
THEME_DIR="/var/www/html/wp-content/themes/astra"
if [ -d "$THEME_DIR" ]; then
    echo "Astra theme directory exists. Skipping download."
else
    echo "Downloading Astra Theme..."
    mkdir -p /var/www/html/wp-content/themes
    cd /var/www/html/wp-content/themes/
    wget https://downloads.wordpress.org/theme/astra.latest-stable.zip
    unzip astra.latest-stable.zip
    rm astra.latest-stable.zip
    chown -R www-data:www-data "$THEME_DIR"
fi

echo "--- [5/5] Final Apache Tweak ---"
# Enable Rewrite module for Permalinks
a2enmod rewrite


# Create .htaccess only if missing
if [ ! -f "/var/www/html/.htaccess" ]; then
    cat << EOF > /var/www/html/.htaccess
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
EOF
    chown www-data:www-data /var/www/html/.htaccess
fi

if [ -L "/etc/apache2/sites-enabled/fsbhoa-access.conf" ]; then
    echo "Production site (fsbhoa-access) detected. Skipping default config modification."
else
    echo "Configuring default Apache site for initial setup..."
    cat << EOF > /etc/apache2/sites-available/000-default.conf
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html/>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF
fi

systemctl restart apache2

echo "--- LAMP Setup Complete ---"
echo "Database Name: fsbhoa_db"
echo "DB User: wp_user"
echo "DB Pass: fsbhoa_pass"
echo "Access phpMyAdmin at: http://$(hostname -I | awk '{print $1}')/phpmyadmin"

