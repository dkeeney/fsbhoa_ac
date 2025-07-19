# FSBHOA Access Control System Configuration Guide
*Version 4.3 - Final*

This document provides step-by-step instructions for configuring the FSBHOA Access Control application after the server platform has been installed according to `INSTALL.md`.

---
## Part 1: Initial WordPress Configuration

This section covers the first steps after the WordPress application has been installed and the database has been imported.

### Step 1: Log In
1.  In a web browser, navigate to `https://access.fsbhoa.com/wp-admin`.
2.  Log in using your administrator credentials (e.g., username `admin` and your password). This will take you to the WordPress Dashboard.

### Step 2: Configure the Theme
The visual appearance of the site is controlled by a theme. These instructions assume the use of the "Astra" theme.

1.  From the Dashboard, navigate to **Appearance -> Themes**.
2.  If "Astra" is not already installed, click **"Add New Theme"**, search for "Astra", and then install and activate it.
3.  Navigate to **Appearance -> Customize**. This will open the theme customizer.
4.  Go to the **Header Builder** section.
5.  Click on **"Site Title & Logo"**.
6.  Upload your desired logo image and adjust the "Logo Width" slider until it looks correct in the header.
7.  Click the **"Publish"** button at the top of the customizer to save your changes.

---
## Part 2: WordPress Application Setup

### Step 1: Set Up Shared Permissions for Development
Because we are using a symbolic link for the plugin for active development, we need to allow the web server (`www-data` user) to read files from your user's (`fsbhoa`) home directory.

1.  **Add the `www-data` user to your `fsbhoa` group.**
    ```bash
    sudo usermod -a -G fsbhoa www-data
    ```
2.  **Set permissions on your home directory.** This allows group members (like `www-data`) to enter your home directory but not list its contents.
    ```bash
    sudo chmod 750 /home/fsbhoa
    ```
3.  **Set ownership and permissions on your project directory.**
    ```bash
    sudo chown -R fsbhoa:fsbhoa ~/fsbhoa_ac
    sudo chmod -R 775 ~/fsbhoa_ac
    ```
4.  **Restart Apache** for the new group membership to take effect.
    ```bash
    sudo systemctl restart apache2
    ```

### Step 2: Install WordPress and Import Data
1.  **Link Plugin:** Create a symbolic link from your git repository to the WordPress plugins directory.
    ```bash
    sudo ln -s /home/fsbhoa/fsbhoa_ac/wordpress_plugin/fsbhoa-access-control /var/www/html/wp-content/plugins/fsbhoa-access-control
    ```
2.  **Run Web Installer:** Open a web browser and navigate to `https://access.fsbhoa.com`.
    * Follow the on-screen WordPress installation guide.
    * When prompted for database details, use the credentials created during the server installation.
    * Complete the installation by providing a site title, admin username, and admin password.
3.  **Log in** to the WordPress admin dashboard.
4.  **Import Database:**
    * **A) Log in to MySQL:** `sudo mysql -u root -p`
    * **B) Drop and Recreate the Database:** Run these commands inside the MySQL prompt to clear the fresh install.
        ```sql
        DROP DATABASE fsbhoa_db;
        CREATE DATABASE fsbhoa_db;
        GRANT ALL PRIVILEGES ON fsbhoa_db.* TO 'wp_user'@'localhost';
        FLUSH PRIVILEGES;
        EXIT;
        ```
    * **C) Import the SQL file:** From your regular terminal prompt, import your database backup or schema.
        * **For a fresh installation:**
            ```bash
            sudo mysql -u root -p fsbhoa_db < ~/fsbhoa_ac/database/schema.sql
            ```
        * **For migrating from an existing system:**
            ```bash
            sudo mysql -u root -p fsbhoa_db < /path/to/your/fsbhoa_db.sql
            ```
5.  **Update Site URL:** After importing, log back into MySQL to tell WordPress its new address.
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
6.  **Log in again:** After the import and URL update, your old WordPress admin user and password will be restored. You will need to log in again at `https://access.fsbhoa.com/wp-admin` with your previous credentials from the old server.
7.  Go to **Plugins -> Installed Plugins** and **Activate** the "FSBHOA Access Control" plugin.

### Step 3: Finalize Migration
1.  **Update Internal URLs:**
    * From the WordPress dashboard, go to **Plugins -> Add New**.
    * Search for, install, and activate the **"Better Search Replace"** plugin.
    * Go to **Tools -> Better Search Replace**.
    * **Search for:** `https://nas.fsbhoa.com` and **Replace with:** `https://access.fsbhoa.com`. Run this search.
    * **Run a second time:** **Search for:** `http://NAS.local` and **Replace with:** `https://access.fsbhoa.com`.
    * For each run, select all tables, do a "dry run" first, then uncheck the box to perform the permanent change.
    * You can deactivate and delete the plugin when finished.
2.  **Enable URL Rewriting:** For the REST API and clean URLs (permalinks) to work, Apache's rewrite module must be enabled.
    ```bash
    sudo a2enmod rewrite
    sudo systemctl restart apache2
    ```
3.  **Reset Permalinks:**
    * From the WordPress dashboard, go to **Settings -> Permalinks**.
    * Do not change any settings. Simply click the **"Save Changes"** button at the bottom. This flushes the old rewrite rules and ensures all your page links work correctly.

### Step 4: Handle Media Files
* **For migrating from an existing system:**
    1.  **From the new server (`access.fsbhoa.com`)**, run the following command to securely copy the entire `uploads` directory from the old server.
        ```bash
        scp -r pi@nas.fsbhoa.com:/var/www/html/wp-content/uploads /var/www/html/wp-content/
        ```
    2.  **Set Correct Permissions:** After the copy is complete, set the ownership of the new files to the web server user.
        ```bash
        sudo chown -R www-data:www-data /var/www/html/wp-content/uploads
        ```
* **For a fresh installation from the repository:**
    * The repository contains a few sample images in the `/images` directory. The administrator should upload their own logo and map images via the WordPress Media Library.

---
## Part 3: Configure Plugin Settings

The Go services read their configuration from `.json` files that are generated by the WordPress plugin. You must configure and save these settings before starting the services.

### Step 1: General Settings
1.  From the WordPress Dashboard, navigate to **FSBHOA AC -> General Settings**.
2.  **Photo Editor Settings:**
    * **Photo Width (px):** Set the desired width for cardholder photos (e.g., `640`). This determines the aspect ratio for cropping and printing.
    * **Photo Height (px):** Set the desired height for cardholder photos (e.g., `800`).
3.  **Display Options:**
    * **Address Suffix to Remove:** Enter any common text you want removed from property addresses in display lists (e.g., `Bakersfield, CA 93306`).
4.  Click **"Save General Settings"**.

### Step 2: Event Service Settings
1.  From the WordPress Dashboard, navigate to **FSBHOA AC -> Event Service**.
2.  **Bind Address:** This is the local IP address the service will use. The default `0.0.0.0` is typically correct.
3.  **Broadcast Address:** This is the address used to discover controllers on the network. This should be the broadcast address for your LAN (e.g., `192.168.42.255`) followed by the controller listening port (`:60000`).
4.  **Event Listener Port:** This is the port the service will listen on for events from the controllers. The default is `60002`.
    > **Note:** The firewall must be configured to allow UDP traffic on ports 60000, 60001, and 60002 for the event service to function correctly. The `install.sh` script handles port 60000.
5.  **Event Callback Host IP:** The IP address of this server, which is sent to the controllers so they know where to send event messages. This should be set to the server's static IP (e.g., `192.168.42.98`).
6.  **WebSocket Service Port:** The port the `event_service` will use to send live events to the `monitor_service`. The default is `8083`.
7.  **WordPress API Protocol:** `https`
8.  **WordPress API Host:** The hostname of this server (e.g., `access.fsbhoa.com`).
9.  **WordPress API Port:** The port for the WordPress API. This should be `443` for HTTPS.
10. **TLS Certificate Path:** The full filesystem path to the SSL certificate file (e.g., `/etc/letsencrypt/live/access.fsbhoa.com/fullchain.pem`).
11. **TLS Key Path:** The full filesystem path to the SSL private key file (e.g., `/etc/letsencrypt/live/access.fsbhoa.com/privkey.pem`).
12. **Event Service Log Path:** The full path to a log file. Leave empty to log to the console (viewable with `journalctl`).
13. **Debug Mode:** Check to enable verbose logging for the service.
14. **Enable Test Stub:** Check to enable a URL that can be used to simulate swipe events for testing.
15. Click **"Save Settings"**. This will write the `event_service.json` file to `/var/lib/fsbhoa/`.

### Step 3: Print Service Settings
1.  From the WordPress Dashboard, navigate to **FSBHOA AC -> Print Service**.
2.  **Zebra Print Service Port:** Set the port that the `zebra_print_service` will listen on. The default is `8081`.
3.  Click **"Save Print Settings"**.

### Step 4: Monitor Settings
1.  From the WordPress Dashboard, navigate to **FSBHOA AC -> Monitor Settings**.
2.  **Gate Position Editor:**
    * Click the **"Upload/Change Map"** button to select an image from the Media Library to serve as the background for the real-time monitor.
    * Once controllers and gates are configured, markers for each gate will appear. Drag these markers to their correct positions on the map.
3.  **Monitor Service Settings:**
    * **Monitor Service Port (WSS):** Set the port that the `monitor_service` will listen on for secure WebSocket connections from browsers. The default is `8082`.
4.  Click **"Save All Monitor Settings"**. This will save the gate positions and write the `monitor_service.json` file to `/var/lib/fsbhoa/`.

### Step 5: Kiosk Settings
1.  From the WordPress Dashboard, navigate to **FSBHOA AC -> Kiosk**.
2.  **Display Settings:**
    * **Kiosk Logo URL:** Enter the full URL to an image in the Media Library that will be displayed on the kiosk's idle screen.
    * **Kiosk Display Name:** Enter the name that will be shown on the Real-Time Monitor when a swipe event occurs at the kiosk (e.g., "Front Desk Kiosk").
3.  Click **"Save Kiosk Settings"**.

### Step 6: System Status
1.  From the WordPress Dashboard, navigate to **FSBHOA AC -> System Status**.
2.  This page displays the current status (`Running`, `Stopped`, `Unknown`) of each Go backend service.
3.  It provides buttons to **Start**, **Stop**, and **Restart** each service directly from the web interface.
    > **Note:** For these buttons to function, a special `sudoers` configuration must be in place, as detailed in Part 4.

### Step 7: System Diagnostics
1.  From the WordPress Dashboard, navigate to **FSBHOA AC -> System Diagnostics**.
2.  This page provides a button to run a suite of regression tests that verify all event messaging pathways are functioning correctly.

---
## Part 4: System Management Configuration

### Step 1: Grant Service Control Permissions
For the "System Status" page to work, the web server user (`www-data`) needs permission to run `systemctl` commands for your specific services. This is done by creating a custom `sudoers` file.

1.  **Open a new, protected file for editing** using `visudo`. This prevents syntax errors.
    ```bash
    sudo visudo -f /etc/sudoers.d/fsbhoa-services
    ```
2.  **Paste the following lines** into the editor. This allows the `www-data` user to run `start`, `stop`, `restart`, and `status` commands on any service beginning with `fsbhoa-` without needing a password.
    ```
    # Allow www-data to manage fsbhoa services
    www-data ALL=(ALL) NOPASSWD: /bin/systemctl start fsbhoa-*.service
    www-data ALL=(ALL) NOPASSWD: /bin/systemctl stop fsbhoa-*.service
    www-data ALL=(ALL) NOPASSWD: /bin/systemctl restart fsbhoa-*.service
    www-data ALL=(ALL) NOPASSWD: /bin/systemctl status fsbhoa-*.service
    ```
3.  **Save and exit the editor** (`Ctrl+X`, then `Y`, then `Enter`).
4.  **Restart Apache** for the new permissions to take effect.
    ```bash
    sudo systemctl restart apache2
    ```

---
## Part 5: Build and Enable Backend Services

The `install.sh` script created the service files, but you need to build the Go applications from your source code and install the necessary command-line tools.

### Step 1: Install/Update Go (Latest Version)
The version of Go provided by the default Ubuntu repositories can be outdated. To ensure compatibility with the latest libraries, it's best to install the latest version directly from the official source.

1.  **Remove the old version of Go** to prevent conflicts.
    ```bash
    sudo apt remove golang-go
    sudo rm -rf /usr/local/go
    ```
2.  **Download the latest Go archive.** You can find the latest version link at https://go.dev/dl/.
    ```bash
    wget [https://go.dev/dl/go1.24.5.linux-amd64.tar.gz](https://go.dev/dl/go1.24.5.linux-amd64.tar.gz) -O /tmp/go.tar.gz
    ```
3.  **Extract the new version** to the standard location.
    ```bash
    sudo tar -C /usr/local -xzf /tmp/go.tar.gz
    ```
4.  **Add Go to your PATH.** Open your profile file for editing.
    ```bash
    nano ~/.profile
    ```
    Add the following line to the end of the file. This ensures the new version of Go is found before any system versions.
    ```bash
    export PATH=/usr/local/go/bin:$PATH
    ```
    Save and close the file (`Ctrl+X`, `Y`, `Enter`).

5.  **Apply the changes** to your current session.
    ```bash
    source ~/.profile
    ```
6.  **Verify the new version** is installed correctly.
    ```bash
    go version
    ```
    The output should now show the new version you just installed.

### Step 2: Build & Install Go Applications
1.  **Install `uhppote-cli`:** Download, extract, and install the pre-compiled command-line tool from the official repository.
    ```bash
    # Download the tar.gz archive
    wget [https://github.com/uhppoted/uhppote-cli/releases/download/v0.8.11/uhppote-cli_v0.8.11-linux-x64.tar.gz](https://github.com/uhppoted/uhppote-cli/releases/download/v0.8.11/uhppote-cli_v0.8.11-linux-x64.tar.gz) -O /tmp/uhppote-cli.tar.gz

    # Create a temporary directory in your home folder for extraction
    mkdir -p ~/tmp-install

    # Extract the archive into your temporary directory
    tar -xzvf /tmp/uhppote-cli.tar.gz -C ~/tmp-install/

    # Copy the binary to a location in the system's PATH
    sudo cp ~/tmp-install/uhppote-cli /usr/local/bin/

    # Clean up the temporary files and directory
    rm -rf ~/tmp-install /tmp/uhppote-cli.tar.gz
    ```

2.  **Build Your Custom Services:** For each Go service in your repository, navigate to its directory and build the executable.
    ```bash
    # Build the event service
    cd ~/fsbhoa_ac/event_service
    go build

    # Build the monitor service
    cd ~/fsbhoa_ac/monitor_service
    go build

    # Build the kiosk service
    cd ~/fsbhoa_ac/kiosk
    go build

    # Note: zebra_print_service is incomplete and will not be built.
    ```
### Step 3: Enable and Start Services
1.  **Configure Services in WordPress:**
    * Log in to the WordPress admin dashboard.
    * Navigate to **FSBHOA AC -> Event Service**.
    * Update all fields to reflect the new server's configuration (e.g., change `nas.fsbhoa.com` to `access.fsbhoa.com`, update IP addresses and certificate paths).
    * Click **"Save Changes"**.
    * Repeat this process for all other service configuration pages.
2.  **Enable and Start Services:**
    * Tell `systemd` to reload the service files.
        ```bash
        sudo systemctl daemon-reload
        ```
    * Enable the services to start automatically on boot, and start them now.
        ```bash
        sudo systemctl enable --now fsbhoa-events.service
        sudo systemctl enable --now fsbhoa-monitor.service
        # Note: We are not starting fsbhoa-zebra_printer.service as it is incomplete.
        ```
    * You can check the status of any service to ensure it's running. The status should now be `active (running)`.
        ```bash
        sudo systemctl status fsbhoa-monitor.service
        ```
    * To view live logs for a service:
        ```bash
        sudo journalctl -u fsbhoa-monitor.service -f
        ```

---
## Part 6: Kiosk Setup

This section covers the final steps to enable the kiosk functionality on this machine.

### Step 1: Create a Permanent Device Rule (`udev`)
This is the most robust way to handle the USB card reader. This one-time setup creates a permanent, easy-to-use device name that won't change.

1.  **Plug in the USB card reader.**
2.  **Find the device's stable path.** Run the following command and identify the new device that appears. It will likely end in `-event-kbd`.
    ```bash
    ls -l /dev/input/by-id/
    ```
    For example: `usb-413d_2107-event-kbd`.

3.  **Find the device's unique identifiers.** Now use the stable path you just found to get the vendor and product IDs.
    > **Note:** Often, the Vendor ID and Product ID are visible in the filename itself. For `usb-413d_2107-event-kbd`, the Vendor ID is `413d` and the Product ID is `2107`. If so, you can skip to step 5.

    Run the following command, replacing the example path with your actual device path:
    ```bash
    udevadm info -a -n /dev/input/by-id/usb-413d_2107-event-kbd | grep -E "ATTRS{name}|ATTRS{idVendor}|ATTRS{idProduct}"
    ```
    * Look at the output. You will see a block of text containing the device's attributes. Find the `ATTRS{idVendor}` (e.g., "0801") and `ATTRS{idProduct}` (e.g., "0002") values from that block and note them down.

4.  **Create a new `udev` rule file.**
    ```bash
    sudo nano /etc/udev/rules.d/99-kiosk-reader.rules
    ```
5.  **Paste the following rule** into the file, replacing the `idVendor` and `idProduct` values with the ones you found.
    ```
    SUBSYSTEM=="input", ATTRS{idVendor}=="413d", ATTRS{idProduct}=="2107", SYMLINK+="kiosk_reader"
    ```
6.  Save and close the file (`Ctrl+X`, `Y`, `Enter`).

7.  **Reload the `udev` rules** and trigger them to apply the new rule.
    ```bash
    sudo udevadm control --reload-rules && sudo udevadm trigger
    ```
8.  Verify that the new device link has been created.
    ```bash
    ls -l /dev/kiosk_reader
    ```
    You should see it pointing to an `input/eventX` device.

### Step 2: Configure the Kiosk Service
1.  Open the kiosk service file for editing.
    ```bash
    sudo nano /etc/systemd/system/fsbhoa-kiosk.service
    ```
2.  Find the line that begins with `StandardInput=`.
3.  **Update it to use the new, permanent device name.**
    ```
    StandardInput=file:/dev/kiosk_reader
    ```
    > **For Browser-Only Mode:** If you need to run the kiosk service without a physical reader attached, you can change this line to `StandardInput=null`.

4.  Save and close the file.

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

**The system configuration is now complete.**


