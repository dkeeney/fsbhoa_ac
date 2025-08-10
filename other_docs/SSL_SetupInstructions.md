**Question**: I have a NAS machine which is a mini PC running Ubuntu. It runs samba for the NAS. This also runs a custom built web app that is a plugin for wordpress. So I also have Apache2, wordpress, and MySQL running on this machine. It is behind our firewall (Netgate 2100) and I do not want public access to this machine; the NAS or the app.

This app has some features that require https. I have it setup as NAS.local with a self-signed certificate but for multiple users it would be much better if I ran it with a real certificate from LetsEncript. Our website has the domain of "fsbhoa.com".

So, what do I need to do?

**Reply:**

As you want to use a Let's Encrypt certificate for a local server (NAS.local) that isn't accessible from the internet, you'll need to use the **DNS-01 challenge** method. This method verifies that you control the domain name (fsbhoa.com) by having you place a specific DNS record there, rather than by having your server respond to a request from the internet.

Here is a step-by-step guide on what you need to do:

**1\. Point a Subdomain to Your NAS's Local IP**

First, you'll need to create a subdomain of your public domain (fsbhoa.com) and point it to the local IP address of your NAS. For example, you could use nas.fsbhoa.com.

1. **Log in to your domain registrar's DNS management panel.** This is where you manage the DNS records for fsbhoa.com.
2. **Create an A record.**
    - **Name/Host:** nas (or another subdomain of your choice)
    - **Value/Points to:** The local IP address of your NAS machine (e.g., 192.168.1.100).

Now, when devices on your local network look up nas.fsbhoa.com, your local DNS (likely your Netgate firewall or another internal DNS server) should resolve it to the NAS's internal IP.

**2\. Install Certbot**

Certbot is the official Let's Encrypt client that you'll use to obtain and manage your certificates.

1. **Open a terminal on your Ubuntu NAS.**
2. **Install Certbot** using snapd, which is the recommended method for Ubuntu:

Bash

sudo snap install --classic certbot

sudo ln -s /snap/bin/certbot /usr/bin/certbot

**3\. Obtain the Certificate Using the DNS-01 Challenge**

Since your server is not public, you will use the DNS-01 challenge with Certbot in manual mode. This process will require you to manually create a TXT record in your domain's DNS settings.

1. **Run Certbot in manual mode:**

Bash

sudo certbot certonly --manual --preferred-challenges dns -d nas.fsbhoa.com

1. **Follow the prompts:**
    - Certbot will ask you to agree to the terms of service and provide an email address for renewal notices.
    - It will then display a **TXT record** that you need to add to your domain's DNS settings. It will look something like this:
    - Please deploy a DNS TXT record under the name
    - \_acme-challenge.nas.fsbhoa.com with the following value:
    - xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
2. **Add the TXT record to your DNS:**
    - Go back to your domain registrar's DNS management panel.
    - Create a new **TXT record**.
    - **Name/Host:** \_acme-challenge.nas
    - **Value/Content:** The long string of characters provided by Certbot.
3. **Verify the DNS record has propagated.** You can use an online tool like dnschecker.org to check that the TXT record is visible. This can take a few minutes.
4. **Complete the Certbot process.** Once you have confirmed the TXT record is in place, press **Enter** in the Certbot terminal. Certbot will then verify the record and issue your certificate.

The certificate files will be saved in /etc/letsencrypt/live/nas.fsbhoa.com/.

**4\. Configure Apache2 to Use the New Certificate**

Now you need to tell Apache to use your new Let's Encrypt certificate.

1. **Enable the SSL module in Apache:**

Bash

sudo a2enmod ssl

1. **Edit your Apache configuration file for your WordPress site.** This is typically located at /etc/apache2/sites-available/your-site.conf. You will want to edit the virtual host block for port 443.

Apache

&lt;VirtualHost \*:443&gt;

ServerName nas.fsbhoa.com

DocumentRoot /var/www/html/wordpress

SSLEngine on

SSLCertificateFile /etc/letsencrypt/live/nas.fsbhoa.com/fullchain.pem

SSLCertificateKeyFile /etc/letsencrypt/live/nas.fsbhoa.com/privkey.pem

\# Other SSL settings can go here

&lt;/VirtualHost&gt;

1. **Restart Apache** to apply the changes:

Bash

sudo systemctl restart apache2

**5\. Update WordPress and Access Your Site**

1. **Update your WordPress Address and Site Address.** In your WordPress dashboard, go to **Settings > General** and change both the **WordPress Address (URL)** and **Site Address (URL)** to <https://nas.fsbhoa.com>.
2. **Access your site.** You should now be able to access your web app securely at <https://nas.fsbhoa.com> from within your local network without any certificate warnings.

**Renewing the Certificate**

Let's Encrypt certificates are valid for 90 days. Since you are using the manual DNS method, you will need to repeat step 3 every 90 days to renew the certificate. Certbot will send you email reminders before the certificate expires.

To make this easier, you can script the process if your DNS provider has an API that Certbot can use through a plugin.
