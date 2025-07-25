**FSBHOA Access Control: System Vision & Architecture**

**1\. Executive Summary**

The primary vision for the FSBHOA Access Control project is to create a single, unified, and modern software platform to manage all aspects of physical access to community amenities. This system is designed to replace disparate, proprietary systems and their associated subscription fees with a powerful, flexible, and cost-effective solution owned and operated entirely by the HOA.

The core architectural principle is **decoupling**, achieved by separating the user-facing administrative interface from the low-level hardware interaction services. This creates a robust, scalable, and maintainable ecosystem built with the best technology for each specific task.

**2\. Core Architecture**

The system is built on a modern, service-oriented architecture. This design ensures that each part of the system has a clear responsibility and communicates with other parts through clean, standard interfaces.

\[Browser\] &lt;--&gt; \[WordPress/PHP UI\] &lt;--&gt; \[REST API\] &lt;-&gt; \[Go Hardware Services\] &lt;-&gt; \[Physical Hardware\]

**The Administrative Hub: WordPress & PHP**

The central nervous system of the project is a custom WordPress plugin. WordPress was chosen for its unparalleled strength in rapid UI development, user and role management, and database abstraction.

- **Role:** Provides the web-based Graphical User Interface (GUI) for all administrative tasks (managing people, hardware, and rules). Serves as the central "source of truth" database for all cardholder, property, and configuration data.
- **Technology:** PHP, WordPress Plugin API, MySQL.

**The Hardware Services Layer: Go**

All direct interaction with physical hardware (access controllers, printers, USB card readers, kiosks) is handled by a set of small, lightweight, and highly reliable backend services.

- **Role:** To act as a "wrapper" or "bridge," translating simple, high-level REST API calls from the WordPress backend into the specific, low-level network protocols required to communicate with the hardware.
- **Technology:** Go programming language. Go was chosen for its exceptional performance, low memory footprint, superior networking capabilities, and its ability to be compiled into a single, dependency-free executable for simple and robust deployment on both Linux and Windows servers.

**The Communication Bridge: REST API**

The WordPress plugin and the Go hardware services communicate exclusively through a simple, well-defined REST API.

- **Role:** To decouple the presentation layer from the hardware layer. WordPress does not need to know how to speak UDP or manage a print queue; it only needs to make a standard HTTP call (e.g., POST /discover_controllers). This ensures that either side of the system can be updated or replaced without breaking the other.

**3\. Components**

The following foundational modules are the core architecture.

**Configuration screens (wordpress)**

- **Import**: The initial database load and subsequent updates are obtained from the HOA’s property management database. This is provided via an uploaded .csv file.
- **Cardholder Management:** A full CRUD (Create, Read, Update, Delete) interface for managing all resident and tenant records, including detailed profile information and photo management via file upload and webcam capture.
- **Deleted Cardholders & Archiving:** A robust, transaction-protected system for safely archiving deleted records instead of permanently destroying them. This includes a user interface to view and restore archived cardholders, ensuring data integrity and a complete audit trail.
- **Hardware Management (Controllers & Gates):** A set of CRUD screens for managing the physical access control hardware. This includes:
  - **Controller Management:** For defining the master controller units by name, serial number, and location.
  - **Gate Management:** For defining the individual gates (doors) and associating them with a specific controller slot via an intuitive "available slots" dropdown, which prevents configuration errors.

**Event handler and Controller Discovery Service**

- **Goal:** To dramatically simplify the setup and installation of new controller hardware.
- **Architecture:** A new set of endpoints in the Go hardware service.
- **Functionality:**
    1. **Discovery:** A GET /discover endpoint that sends a UDP broadcast across the local network to find any unconfigured UHPPOTE controllers. It will return a list of discovered devices by their unique serial number.
    2. **Configuration:** A POST /set-ip endpoint that sends a direct command to a discovered controller to assign it a static IP address, subnet, and gateway, bringing it formally into the managed system.
    3. **WordPress UI:** A new "Discovery" tab within the Hardware Management screen featuring a "Scan for New Controllers" button and a simple UI to configure them.
    4. **Event handling:** On card swipe and gate state changes, the hardware controller will issue events that are captured by the event_service. This service then passes these on the the wordpress code which puts it in the database. It also polls for gate state to support the state display on the real-time display.

**Real-Time Activity Monitor**

- **Goal:** To provide front-office staff with a live, visual dashboard of access events as they occur.
- **Architecture:** An expansion of the Go hardware service to include an event listener and a WebSocket server.
- **Functionality:**
    1. The Go service will listen for real-time event packets sent by the controllers for every card swipe.
    2. Upon receiving an event, it will query the WordPress REST API to fetch the cardholder's photo and details.
    3. It will then push this complete event object to all connected web browsers via a WebSocket.
    4. A new "Live Monitor" page in WordPress will use JavaScript to display these events in a scrolling list as they arrive, providing immediate visual feedback.

**Resident Sign-in Kiosk**

- **Goal:** To provide an automated, reliable way for residents to sign in when visiting the Lodge and select the amenity they intend to use, capturing valuable usage data.
- **Architecture:** A dedicated Go application running on a mini-PC (e.g., Raspberry Pi) connected to a USB card reader and a touch screen.
- **Functionality:**
    1. The Go application will directly manage the USB card reader and serve a simple, locked-down touch interface to the local browser.
    2. When a resident swipes their card, the Go app captures the RFID. When they touch an amenity button on the screen, the app captures their selection.
    3. The Go application then sends a single, clean REST API call to the main WordPress server to log the sign-in event.
    4. This architecture ensures high reliability and provides the opportunity for offline capability, where sign-ins can be stored locally on the Pi if the network connection is down and synced later.

**Zebra Card Printer service**

This is a printer driver that decoples the wordpress code from the printer hardware.

**5\. Maintainability & Longevity**

A primary goal of this project is to create a system that can be maintained and extended for many years, even if the original developers are no longer available. This is achieved through several key strategies:

- **Architectural Decoupling:** The separation between the WordPress UI and the Go hardware services is the most critical feature for maintainability. A future developer can work on a UI bug in PHP without needing to understand network protocols, or they can replace a physical controller and update the Go service without ever touching the WordPress theme. This dramatically reduces the cognitive load required to manage the system.
- **Standard Technologies:** The project exclusively uses mainstream, well-documented, and open-source technologies (PHP, Go, REST, MySQL, JavaScript). This ensures a large pool of future developers will be familiar with the underlying technology, making it easier to find support.
- **The Role of this Document:** This vision document itself is a key component of the system's longevity. It serves as a high-level guide to the system's architecture, design philosophy, and intended future direction, allowing a new developer to quickly understand the "why" behind the code.
- **AI-Assisted Maintenance:** With the source code and this vision document as a knowledge base, a future administrator or non-programmer could leverage an AI assistant to diagnose issues, understand code sections, or even generate the code for new, simple features that align with the established architecture. This significantly lowers the barrier to entry for future system maintenance.

**6\. Conclusion**

This architectural vision outlines a complete, end-to-end access control platform. By adhering to the principles of decoupling, using standard technologies, and maintaining clear documentation, the system is designed to be reliable, secure, cost-effective, and easy to maintain and expand for the foreseeable future.
