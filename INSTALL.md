b# FSBHOA Access Control System Installation Guide (access.fsbhoa.com)
*Version 3.5*

This document provides step-by-step instructions for deploying the FSBHOA Access Control system on the new dedicated server (`access.fsbhoa.com`).

---

## Part 1: Operating System Installation

This section covers the installation of the Ubuntu Desktop LTS operating system on your new mini-PC.

> **Important Note on Location:** It is highly recommended to perform this OS installation while the machine is connected to its final network at the Lodge (`192.168.42.0/24`). This ensures that all network services and configurations default to the correct settings from the very beginning, which simplifies later steps like setting a static IP and testing the firewall.

### Step 1: Create a Bootable USB Drive
1.  On a separate computer, download the latest **Ubuntu Desktop LTS** ISO file.
    * Official Ubuntu Desktop Download Page: https://ubuntu.com/download/desktop
2.  Use a tool like [Balena Etcher](https://www.balena.io/etcher/) to flash the ISO file onto a USB drive.

### Step 2: Install Ubuntu Desktop
1.  Boot the mini-PC from the USB drive.
2.  Choose **"Install Ubuntu"** (the interactive option).
3.  Follow the on-screen prompts, choosing **"Erase disk and install Ubuntu"**.
4.  On the "Who are you?" screen, use the following details:
    * **Your name:** `IT Committee`
    * **Your computer's name:** `access`
    * **Pick a username:** `fsbhoa`
    * **Choose a strong password.**
    * Select **"Log in automatically"**.
5.  Complete the installation and reboot.

---
## Part 2: System & Services Setup

### Step 1: Prepare for Installation
1.  Open a Terminal window (`Ctrl+Alt+T`).
2.  Install `git`: `sudo apt install git -y`
3.  Clone the repository: `git clone https://github.com/dkeeney/fsbhoa_ac.git ~/fsbhoa_ac`
4.  Navigate into the directory: `cd ~/fsbhoa_ac`
5.  Make the script executable: `chmod +x install.sh`

### Step 2: Run the Automated Installation Script
1.  Run the script: `sudo ./install.sh`
2.  At the firewall prompt, select **`<Yes>`** for both IPv4 and IPv6.

### Step 3: Enable Remote Access (SSH)
1.  Install the SSH server: `sudo apt install openssh-server -y`
2.  Find and note your IP address for remote login: `ip addr show`

### Step 4: Set Static IP Address
1.  **Find your network interface name:** Run `ip addr` and find the name of your main wired connection (e.g., `enp3s0`).
2.  **Edit the Netplan config file:** `sudo nano /etc/netplan/01-network-manager-all.yaml`
3.  **Paste the following configuration**, replacing `enp3s0` with your actual interface name.
    ```yaml
    network:
      version: 2
      ethernets:
        enp3s0:
          dhcp4: no
          addresses:
            - 192.168.42.98/24
          routes:
            - to: default
              via: 192.168.42.1
          nameservers:
            addresses:
              - 192.168.42.1
              - 8.8.8.8
    ```
4.  **Apply the changes:** `sudo netplan apply`. Your SSH session will drop. Reconnect to the new static IP `192.168.42.98`.

### Step 5: Configure DNS
1.  **Log in to Bluehost** and navigate to the DNS settings for `fsbhoa.com`.
2.  **Create an "A" record** that points the hostname `access.fsbhoa.com` to the server's private, static IP address.
    * **Host Record** (or **Name**): `access`
    * **Type:** `A`
    * **Points To:** `192.168.42.98`
3.  This ensures that the hostname resolves correctly for users both on the local LAN and connected via VPN.

---
## Part 3: Web Server & WordPress Setup

### Step 1: Obtain SSL Certificate (Let's Encrypt)
1.  **Install Certbot:** `sudo apt install certbot -y`
2.  **Start the Certificate Request:** `sudo certbot certonly --manual --preferred-challenges dns -d access.fsbhoa.com`
3.  **Follow the Prompts** and add the provided TXT record to your DNS settings on Bluehost.
4.  **Verify DNS Propagation** using an online tool, then press Enter in the terminal to complete the process.

### Step 2: Set Up the Database
1.  Secure the MySQL installation: `sudo mysql_secure_installation`
2.  Log in to MySQL: `sudo mysql -u root -p`
3.  Create the database and user. **Replace `'your_strong_password'` with a new, secure password.**
    ```sql
    CREATE DATABASE fsbhoa_db;
    CREATE USER 'wp_user'@'localhost' IDENTIFIED BY 'your_strong_password';
    GRANT ALL PRIVILEGES ON fsbhoa_db.* TO 'wp_user'@'localhost';
    FLUSH PRIVILEGES;
    EXIT;
    ```
    *Note the database name, username, and password for the next steps.*

### Step 3: Install phpMyAdmin (Optional)
1.  **Install the necessary packages.**
    ```bash
    sudo apt install phpmyadmin php-mbstring php-zip php-gd php-json php-curl -y
    ```
2.  **Follow the on-screen prompts:**
    * When asked to choose a web server, select **`apache2`**.
    * When asked to configure a database, select **`<Yes>`**.
    * If you encounter a password policy error, select **<Abort>**, then follow the manual database setup steps in the project's troubleshooting guide.
3.  **Enable the configuration:**
    ```bash
    sudo a2enconf phpmyadmin
    sudo systemctl restart apache2
    ```

### Step 4: Configure Apache and SSL
1.  **Create Apache Virtual Host:** `sudo nano /etc/apache2/sites-available/fsbhoa-access.conf`
2.  **Paste the following configuration** into the file.
    ```apache
    <VirtualHost *:80>
        ServerName access.fsbhoa.com
        Redirect permanent / [https://access.fsbhoa.com/](https://access.fsbhoa.com/)
    </VirtualHost>

    <VirtualHost *:443>
        ServerName access.fsbhoa.com
        DocumentRoot /var/www/html

        SSLEngine on
        SSLCertificateFile    /etc/letsencrypt/live/[access.fsbhoa.com/fullchain.pem](https://access.fsbhoa.com/fullchain.pem)
        SSLCertificateKeyFile /etc/letsencrypt/live/[access.fsbhoa.com/privkey.pem](https://access.fsbhoa.com/privkey.pem)

        <Directory /var/www/html>
            Options Indexes FollowSymLinks
            AllowOverride All
            Require all granted
        </Directory>

        ErrorLog ${APACHE_LOG_DIR}/error.log
        CustomLog ${APACHE_LOG_DIR}/access.log combined
    </VirtualHost>
    ```
3.  **Set Certificate Permissions:** Allow the Apache server to read the certificate directories and private key.
    ```bash
    sudo chmod 755 /etc/letsencrypt/live /etc/letsencrypt/archive
    sudo chown root:ssl-cert /etc/letsencrypt/archive/[access.fsbhoa.com/privkey1.pem](https://access.fsbhoa.com/privkey1.pem)
    sudo chmod 640 /etc/letsencrypt/archive/[access.fsbhoa.com/privkey1.pem](https://access.fsbhoa.com/privkey1.pem)
    ```
4.  **Enable the new site** and disable the default one.
    ```bash
    sudo a2ensite fsbhoa-access.conf
    sudo a2dissite 000-default.conf
    sudo a2enmod ssl
    sudo a2enmod headers
    sudo systemctl daemon-reload
    sudo systemctl restart apache2
    ```

### Step 5: Install WordPress and Import Data
1.  **Set Web Directory Permissions:** Give the web server ownership of the WordPress files.
    ```bash
    sudo chown -R www-data:www-data /var/www/html/
    ```
2.  **Copy Plugin:** Copy your custom WordPress plugin from your repository to the WordPress plugins directory.
    ```bash
    sudo cp -r ~/fsbhoa_ac/wordpress-plugin /var/www/html/wp-content/plugins/fsbhoa-access-control
    ```
3.  **Run Web Installer:** Open a web browser and navigate to `https://access.fsbhoa.com`.
    * Follow the on-screen WordPress installation guide.
    * When prompted for database details, enter the information from Step 2 (`fsbhoa_db`, `wp_user`, and the password you created).
    * Complete the installation by providing a site title, admin username, and admin password.
4.  **Log in** to the WordPress admin dashboard.
5.  **Import Database:**
    * **A) Log in to MySQL:** `sudo mysql -u root -p`
    * **B) Drop and Recreate the Database:** Run these commands inside the MySQL prompt.
        ```sql
        DROP DATABASE fsbhoa_db;
        CREATE DATABASE fsbhoa_db;
        GRANT ALL PRIVILEGES ON fsbhoa_db.* TO 'wp_user'@'localhost';
        FLUSH PRIVILEGES;
        EXIT;
        ```
    * **C) Import the SQL file:** From your regular terminal prompt, import your database backup.
        ```bash
        sudo mysql -u root -p fsbhoa_db < /path/to/your/fsbhoa_db.sql
        ```
6.  **Update Site URL:** After importing, log back into MySQL to tell WordPress its new address.
    ```bash
    sudo mysql -u root -p
    ```
    Then run these SQL commands inside the MySQL prompt:
    ```sql
    USE fsbhoa_db;
    UPDATE wp_options SET option_value = '[https://access.fsbhoa.com](https://access.fsbhoa.com)' WHERE option_name = 'siteurl';
    UPDATE wp_options SET option_value = '[https://access.fsbhoa.com](https://access.fsbhoa.com)' WHERE option_name = 'home';
    EXIT;
    ```
7.  **Log in again:** After the import and URL update, your old WordPress admin user and password will be restored. You will need to log in again at `https://access.fsbhoa.com/wp-admin` with your previous credentials from the old server.
8.  Go to **Plugins -> Installed Plugins** and ensure the "FSBHOA Access Control" plugin is **Activated**.

### Step 6: Finalize Migration
1.  **Update Internal URLs:**
    * From the WordPress dashboard, go to **Plugins -> Add New**.
    * Search for, install, and activate the **"Better Search Replace"** plugin.
    * Go to **Tools -> Better Search Replace**.
    * **Search for:** `https://nas.fsbhoa.com` and **Replace with:** `https://access.fsbhoa.com`. Run this search.
    * **Run a second time:** **Search for:** `http://NAS.local` and **Replace with:** `https://access.fsbhoa.com`.
    * For each run, select all tables, do a "dry run" first, then uncheck the box to perform the permanent change.
    * You can deactivate and delete the plugin when finished.
2.  **Reset Permalinks:**
    * From the WordPress dashboard, go to **Settings -> Permalinks**.
    * Do not change any settings. Simply click the **"Save Changes"** button at the bottom. This flushes the old rewrite rules and ensures all your page links work correctly.

### Step 7: Copy Media Files
1.  **From the new server (`access.fsbhoa.com`)**, run the following command. This will securely copy the entire `uploads` directory from the old server to the new one.
    > Note: This assumes the username on the old NAS is `pi`. If it's different, change `pi@nas.fsbhoa.com` accordingly. You will be prompted for the `pi` user's password on the old server.
    ```bash
    scp -r pi@nas.fsbhoa.com:/var/www/html/wp-content/uploads /var/www/html/wp-content/
    ```
2.  **Set Correct Permissions:** After the copy is complete, set the ownership of the new files to the web server user.
    ```bash
    sudo chown -R www-data:www-data /var/www/html/wp-content/uploads
    ```
3.  Refresh your browser. The images should now appear correctly.

---
## Part 4: Build and Enable Backend Services

The `install.sh` script created the service files, but you need to build the Go applications from your source code.

### Step 1: Build Go Applications
1.  For each Go service, navigate to its directory and build the executable.
    ```bash
    # Build and install the CLI tool so it's available system-wide
    cd ~/fsbhoa_ac/uhppote-cli
    go build
    sudo cp uhppote-cli /usr/local/bin/

    # Build the event service
    cd ~/fsbhoa_ac/event_service
    go build

    # Repeat for all other Go services (discovery_service, websocket_service, printer_service, etc.)
    ```

### Step 2: Enable and Start Services
1.  Tell `systemd` to reload the service files (in case of changes).
    ```bash
    sudo systemctl daemon-reload
    ```
2.  Enable the services to start automatically on boot, and start them now.
    ```bash
    sudo systemctl enable --now fsbhoa-events.service
    sudo systemctl enable --now fsbhoa-discovery.service
    sudo systemctl enable --now fsbhoa-websocket.service
    sudo systemctl enable --now fsbhoa-printer.service
    ```
3.  You can check the status of any service to ensure it's running.
    ```bash
    sudo systemctl status fsbhoa-events.service
    ```
4.  To view live logs for a service:
    ```bash
    sudo journalctl -u fsbhoa-events.service -f
    ```

---
## Part 5: Kiosk Setup

This section covers the final steps to enable the kiosk functionality on this machine.

### Step 1: Find USB Card Reader ID
1.  Plug the USB card reader into the `access` machine.
2.  Run the following command to list all input devices by their stable ID.
    ```bash
    ls -l /dev/input/by-id/
    ```
3.  Identify the line that corresponds to your card reader. It will likely end in `-event-kbd`. Note down this full name (e.g., `usb-MagTek_USB_Keyboard-event-kbd`).

### Step 2: Configure the Kiosk Service
1.  Open the kiosk service file for editing.
    ```bash
    sudo nano /etc/systemd/system/fsbhoa-kiosk.service
    ```
2.  Find the line that begins with `StandardInput=`.
3.  Replace the placeholder `usb-YOUR_CARD_READER_ID-event-kbd` with the actual device ID you found in the previous step.
4.  Save and close the file (`Ctrl+X`, `Y`, `Enter`).

### Step 3: Set Permissions and Enable Service
1.  Add your `fsbhoa` user to the `input` group so it has permission to read from the device.
    ```bash
    sudo usermod -a -G input fsbhoa
    ```
2.  **You must reboot the machine** for this group change to take full effect.
    ```bash
    sudo reboot
    ```
3.  After rebooting, enable and start the kiosk service.
    ```bash
    sudo systemctl enable --now fsbhoa-kiosk.service
    ```
4.  Check its status to ensure it is running correctly.
    ```bash
    sudo systemctl status fsbhoa-kiosk.service
    ```

### Step 4: Configure Browser for Kiosk Mode
The final step is to configure the web browser to automatically launch in fullscreen (kiosk) mode and point to the Go application's local URL (`http://localhost:8080`). This process varies depending on the browser and desktop environment but typically involves creating a custom `.desktop` file in `~/.config/autostart/`.

**The system installation is now complete.**


