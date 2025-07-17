# FSBHOA Access Control System Installation Guide (access.fsbhoa.com)
*Version 2.9*

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

### Step 3: Configure Apache and SSL
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

### Step 4: Install WordPress and Import Data
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
    > **Note:** For a brand new installation, you should use the initial schema file from the git repository. For a migration, you should use a backup `.sql` file from the old server.

    * **A) For a fresh installation:**
        ```bash
        # This command will ask for the mysql root password
        sudo mysql -u root -p fsbhoa_db < ~/fsbhoa_ac/database/schema.sql
        ```
    * **B) For migrating from an existing system (your current scenario):**
        ```bash
        # First, copy your backup.sql file to the server.
        # Then, run this command, which will ask for the mysql root password:
        sudo mysql -u root -p fsbhoa_db < /path/to/your/backup.sql
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

**The next steps will be added here once you confirm the web server and WordPress setup is complete.**


