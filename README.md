# FSBHOA Access Control System

An open-source, self-hosted access control system designed for Homeowners Associations (HOAs). This project replaces proprietary hardware systems and their recurring subscription fees with a powerful, flexible, and cost-effective platform built on a modern, decoupled architecture.

The entire system is managed through a user-friendly WordPress dashboard, while lightweight backend services written in Go handle all direct communication with the physical hardware.


---
## ## Key Features

* **Centralized WordPress Administration:** A user-friendly WordPress dashboard for all management tasks.
* **Comprehensive Cardholder Management:** Full CRUD (Create, Read, Update, Delete) for residents, tenants, and staff, including detailed profiles and photo capture via webcam or file upload.
* **Live Activity Monitor:** A real-time, WebSocket-powered dashboard to view access events and gate statuses as they happen.
* **Resident Sign-in Kiosk:** A dedicated touch-screen application for residents to log their use of community amenities.
* **Hardware Management & Discovery:** Tools to manage UHPPOTE controllers and discover new hardware on the network.
* **Powerful Permission Engine:** Create granular access rules using a system of hierarchical Permission Groups.
* **Scheduled Tasks:** Automate door actions like locking or unlocking gates at specific times on specific days.
* **Robust Data Management:**
    * **CSV Import:** A powerful tool to perform bulk synchronization of resident and property data.
    * **Archiving:** Deleted records are safely archived instead of being permanently erased, with tools to restore, merge, or purge them.
* **Reporting & Analytics:** An access log report with filtering and CSV export, plus a dashboard for usage analytics.
* **ID Card Printing:** A dedicated service to generate and print professional photo ID cards on a Zebra ZC300 card printer.

---
## ## Architecture Overview

The core architectural principle of this system is **decoupling**. The user-facing administrative interface is completely separate from the low-level hardware services. This creates a robust, scalable, and maintainable ecosystem.

`[Browser] <=> [WordPress/PHP UI] <=> [REST API] <=> [Go Services] <=> [Hardware]`

* **WordPress Plugin (PHP):** Provides the web-based Graphical User Interface (GUI) for all administrative tasks. It serves as the central "source of truth" database for all cardholder, property, and configuration data.

* **Backend Services (Go):** All direct interaction with physical hardware (access controllers, printers, USB card readers) is handled by a set of small, lightweight, and highly reliable backend services written in Go. They translate simple REST API and WebSocket commands into the low-level protocols required by the hardware.

* **Communication:**
    * **REST API:** The primary bridge for communication, allowing the Go services to enrich data (e.g., get cardholder details from WordPress) and for WordPress to send commands.
    * **WebSockets:** Used by the Live Monitor and Kiosk for real-time, two-way communication.

---
## ## Technology Stack

* **Backend Services:** Go
* **Frontend & Database:** PHP (custom WordPress Plugin), MySQL
* **Client-Side:** JavaScript (jQuery, DataTables.js, Chart.js)
* **Communication:** REST, WebSockets
* **Hardware Interface:** `uhppote-cli` for controller management

---
## ## Installation

For detailed setup instructions, please see the **`INSTALL.md`** file and the automated **`install.sh`** script in this repository.

## ## Configuration

All system configuration is managed through the WordPress admin dashboard under **FSBHOA AC**. Settings are saved to the WordPress database and are used to automatically generate `.json` configuration files for the backend Go services. These files are typically written to `/var/lib/fsbhoa/`.

---
## ## Author

* **David Keeney** - *Initial Design & Development* - <dkeeney@gmail.com>
with assistance from Google's Gemini model 2.5Pro who actually wrote all of the code.

## ## License

This project is licensed under the GPL v2 or later. See the main `fsbhoa-access-control.php` file for details.
