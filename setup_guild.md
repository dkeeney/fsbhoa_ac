# FSBHOA Access Control - Developer Testbed Setup Guide

This guide provides the simple steps to configure the application services on a fresh Ubuntu server that already has a standard LAMP stack (Linux, Apache, MySQL, PHP) and a running WordPress site.

## Prerequisites

1.  A functional Ubuntu server.
2.  Apache2, MySQL Server, and PHP are installed and running.
3.  WordPress is installed and accessible via a web browser.
4.  The `fsbhoa_ac` project has been cloned from GitHub into a user's home directory (e.g., `/home/fsbhoa/fsbhoa_ac`).

## Application Setup

The setup process for the backend Go services is automated.

1.  **Navigate to the project directory:**
    ```bash
    cd /path/to/your/fsbhoa_ac
    ```

2.  **Make the setup script executable:**
    ```bash
    chmod +x setup.sh
    ```

3.  **Run the script with `sudo`:**
    ```bash
    sudo ./setup.sh
    ```
4.  When prompted, enter the **non-root username** that owns the project files (e.g., `fsbhoa`).

The script will automatically handle the setup of all backend services.

---

## Optional: Configuring SSL with Let's Encrypt (DNS Challenge Method)

Follow these steps if you need to enable HTTPS on the test server (e.g., for testing photo capture features). This process is for servers on a private network that cannot be reached from the public internet.

### Step 1: Create a DNS "A" Record

Log in to your DNS provider (e.g., Bluehost) and create a new **A record** that points your test subdomain to your server's **private IP address**.

* **Host Record / Name:** `test` (for `test.fsbhoa.com`)
* **Type:** `A`
* **Points To:** `192.168.1.107` (or your server's private IP)

### Step 2: Request the Certificate

On your test server, run the Certbot command to begin the manual DNS challenge. The `setup.sh` script has already installed the necessary tools (`certbot`, `acl`).

```bash
sudo certbot certonly --manual --preferred-challenges dns -d test.fsbhoa.com
Step 3: Complete the DNS Challenge
Certbot will pause and ask you to create a TXT record in your DNS to prove you own the domain.

Go back to your DNS provider.

Create a new TXT record.

For the Host Record / Name, paste the value Certbot provides (e.g., _acme-challenge.test).

For the Value / Content, paste the long, random string Certbot provides.

Wait 2-3 minutes for the DNS record to become public.

Go back to your terminal and press Enter to complete the validation.

Step 4: Grant Permissions to the Certificate Files
The Go services run as a non-root user and need permission to read the certificate files. We use Access Control Lists (ACLs) to grant this permission securely.

Bash

# Replace 'fsbhoa' with the username that runs the services if it's different.
sudo setfacl -R -m u:fsbhoa:rX /etc/letsencrypt/live/
sudo setfacl -R -m u:fsbhoa:rX /etc/letsencrypt/archive/
Step 5: Configure Apache for SSL
Create a new Apache virtual host file:

Bash

sudo nano /etc/apache2/sites-available/test-fsbhoa.conf
Paste in the following configuration:

Apache

<VirtualHost *:80>
    ServerName test.fsbhoa.com
    Redirect permanent / [https://test.fsbhoa.com/](https://test.fsbhoa.com/)
</VirtualHost>

<VirtualHost *:443>
    ServerName test.fsbhoa.com
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/[test.fsbhoa.com/fullchain.pem](https://test.fsbhoa.com/fullchain.pem)
    SSLCertificateKeyFile /etc/letsencrypt/live/[test.fsbhoa.com/privkey.pem](https://test.fsbhoa.com/privkey.pem)

    <Directory /var/www/html>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
Enable the new site and restart Apache:

Bash

sudo a2ensite test-fsbhoa.conf
sudo a2enmod ssl
sudo systemctl restart apache2
Step 6: Update WordPress
Edit wp-config.php and set the home and site URL:

PHP

define( 'WP_HOME', '[https://test.fsbhoa.com](https://test.fsbhoa.com)' );
define( 'WP_SITEURL', '[https://test.fsbhoa.com](https://test.fsbhoa.com)' );
Run a search-and-replace to update all URLs in the database:

Bash

cd /var/www/html
sudo -u www-data wp search-replace 'http://<old_url>' '[https://test.fsbhoa.com](https://test.fsbhoa.com)' --skip-columns=guid
***

