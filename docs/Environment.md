**Environment Statement**  
**Project Summary: FSBHOA Access Control**

This summary is based on the "Photo ID Project" sessions, culminating in a fully functional system to control access to the amenities at our HOA (Home Owners Association).

**Hardware & Network Environment**

- **Server:** A single mini-PC, acting as the primary server for all components.
  - **Hostname:** access.fsbhoa.com  
        There is an SSL certificate for this host.
  - **IP Address:** 192.168.42.98
  - **Location:** On-site at the "Lodge" (our HOA’s club house).
  - **LAN Subnet:** 192.168.42.0/24
  - **Broadcast Address:** 192.168.42.255
- **Operating System:**,
  - Ubuntu
  - using iptables for the firewall.
- **Access Control Hardware:**
  - **Type:** UHPPOTE controllers. There are 4 of these, each controlling 4 doors (we call gates). There are a total of 11 gates.
  - **Configuration:** For testing, one controller is configured with a **static IP** (192.168.42.179) and serial number 425043852. The system is designed to support multiple controllers dynamically.

**Software Architecture**

The project is an open-source access control system with a decoupled architecture.

- **Source Code:** <https://github.com/dkeeney/fsbhoa_ac>
- **Frontend & Source of Truth:** A **WordPress plugin** written in PHP. It provides all administrative UIs (CRUD screens for cardholders, controllers, tasks, etc.) and is the central management point.
- **Backend Services:** Multiple services written in **Go**.  
    <br/>The event_service handles real-time event listening and processing as the interface with the uhppote hardware . The wordpress plugin (PHP code) uses the uhppote-cli API as a separate backend configure the UHPPOTE controllers with card and task list information.  
    <br/>The monitor_service handles the interactions with the real-time display. This display is a webpage that shows current gate status and swipes of cards at the gates.  
    <br/>The kiosk service handles the card swipes used for residents using the Lodge amenities. This collects the resident’s indication as to which amenity they will be using.  
    <br/>The zebra_print_service, provices an interface to the Zebra ZC300 printer used to print the photo ID cards which contain an RFID chip.
- **Configuration Model:** The WordPress plugin generates JSON configuration files (e.g., /var/lib/fsbhoa/event_service.conf, /var/lib/fsbhoa/monitor_service.json, /var/lib/fsbhoa/controllers.json, /var/lib/fsbhoa/zebra_print_service.json) for the Go services. The Go services read these files on startup and can "hot-reload" the controller list without restarting.  
    <br/>There is a set of configuration “options” settable in the wordpress dashboard. The elements in this set of screens are written to the configuration files for the configuring the backgound services whenever a setting is changed.
- **Communication:**
  - A **REST API** built into the WordPress plugin is used by the Go services to enrich data (e.g., getting cardholder details).
  - A **WebSocket** server within the Go event_service pushes live events to the browser-based monitor screen.

**Configuration:**

All of the servers are configured using .json files. The wordpress PHP code contains a admin mode menu that allows entring of configuration for the wordpress CRUD screens and for configuring as well as starting/stopping of the services. Any time any configuration changes, there is a .json file written for each service that the services can read at startup.

**User Preferences & Workflow**

- **Code Changes:** I strongly prefer **small, targeted edits** to files rather than full replacements, as this allows me to track changes and avoid losing your own modifications (like comments and debugging lines). A full file replacement should only be used for major, clearly explained refactoring.  

- **Completeness:** Any code provided must be complete. No placeholders or simplifications. When focusing on one part of the code, ALL parts of all other lines of code presented must be complete, including debug statements and comments. Never provide a complete function or complete file for replacement that is not completely filled in with all details even if a detail is not related to the current focus.  
    <br/>If code presented for replacement is not complete it is unusable because humans don’t have the ability to fill in the missing parts.
- **Architecture:** I prioritize robust, clean, and platform-independent architecture. I prefer solutions that avoid redundancy (e.g., hardcoded values) and are secure (e.g., questioning file permissions). Decouple code the best you can so that changes to fix a bug in one area of the code will not break something in another part of the code.
- **Debugging:** I am an active and skilled participant in debugging, providing logs and forming hypotheses.
- **Development Environment:** I work remotely via SSH (PuTTY) over a VPN and use vi for editing files directly on the server.
- **Database I/O:** All database I/O must include checks for database errors.
- **Wait!** I will try to always say "Wait!" if I have not implemented your previous suggestion. This means I want to talk about it before we continue. I will also try to indicate what portions of your suggestions I have implemented. The objective is to try to keep your copy of the source code in sync with the real code.  

- **Uploads:** At any time you can ask me to upload any other code that you may like to see or to resync the module we are working on.  

- **Baselines:** When we reach a clean point I will push the code to github and declare a new baseline. I will then upload the modified files so you can replace it’s corresponding file in your long term storage.  
    <br/>When we reach a baseline, you can forget all of the steps or mis-steps taken to arrive at the baseline. Only the baseline code matters from then on.
