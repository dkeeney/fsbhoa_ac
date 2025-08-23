# FSBHOA Access Control: User Manual 📖

## 1. Introduction

Welcome to the FSBHOA Access Control system! This system is a complete, self-contained platform designed to manage physical access to our community's amenities and pedestrian gates.

It works by synchronizing with resident data from the HOA's primary property management system to ensure that only current, authorized residents are granted access. Its purpose is to replace older, proprietary systems with a modern, flexible, and cost-effective solution that is owned and operated entirely by the HOA.

This guide will walk you through all the common tasks you'll perform to manage resident access, monitor activity, and keep the system running smoothly.

### What Does This System Do?

At its core, this system is the central hub for translating resident data into physical access permissions. It does not control vehicle traffic.

Its main features include:

* **Cardholder & Permission Management**: A comprehensive tool for adding, editing, and managing all residents, tenants, and staff. This includes capturing photos for ID cards and assigning them to permission groups that control their access rights.
* **Live Activity Monitor**: A real-time dashboard showing who is accessing amenities and the current status of all gates.
* **Resident Sign-in Kiosk**: A touch-screen application for residents to log their use of community amenities.
* **ID Card Printing**: A complete workflow for designing and printing professional photo ID cards.
* **Hardware & Gate Control**: Advanced tools for managing the physical door controllers and scheduling automatic tasks like daily unlocks.
* **Reporting & Analytics**: Tools to view access logs and analyze amenity usage. The generated reports and graphs provide valuable data to the HOA Board of Directors for making policy and funding decisions.

### Accessing the System

The entire FSBHOA Access Control system is a web-based application. This means you can access and manage it from any authorized computer on the HOA network using a standard web browser (like Chrome or Firefox) by navigating to your system's specific URL.

The system is designed to be used by multiple staff members at the same time.

There are two hardware requirements for certain tasks:

* For tasks that involve reading physical ID cards (like assigning a new RFID), the computer you are using must have a **USB card reader** attached.
* When printing an ID card, the command is sent from your browser to the server, which then sends the job to the card printer over the network. This means the staff member performing the print action must be **physically near the card printer** to retrieve the new card and scan it with their local USB reader to complete the activation.

---

## 2. Menu & Page Overview

This section provides a brief tour of the main pages in the system, organized by the menu structure.

### Home

This is your main dashboard for monitoring and reviewing system activity.

* **Real-time Monitor**: This is the live dashboard showing all gate and amenity access events as they happen. You can view the status of all gates on the community map and manually control them from this screen.
* **Access Logs Report**: Provides a detailed, searchable history of every event recorded by the system. You can filter by date range or specific gates and export the data to a CSV file for analysis.
* **Analytics Report**: Displays graphs and charts summarizing amenity and gate usage, helping to identify peak hours and popular locations.

### Manage Cardholders

This is the central area for all tasks related to managing the people in the system.

* **Cardholders**: The primary screen for adding, editing, searching for, and printing ID cards for all residents, tenants, and staff.
* **Properties**: A list of all property addresses in the community. You can add or edit properties from here.
* **Permission Groups**: Allows you to create and manage sets of access rules (e.g., "Pool Access," "Staff Access") that determine who can open which doors and when.
* **Archive**: When a cardholder is deleted, their record is moved here. From this screen, you can restore them, merge their data into a new record, or permanently delete them.
* **Import**: An administrative tool for performing a bulk import and synchronization of resident and property data from a CSV file.

### Hardware Management

This section is for configuring the physical components of the access control system.

* **Controllers**: Used to manage the physical door controller hardware. This screen is where you can discover new controllers on the network and assign names to them and their connected gates.
* **Task List**: Allows you to create scheduled, automatic door actions, such as having a door unlock at 9 AM and lock at 5 PM every weekday.
* **Amenity Configuration**: Manage the buttons that appear on the resident sign-in Kiosk. You can add, remove, and re-order the amenities from this page.
* **Database**: Provides direct access to the system's underlying database for advanced troubleshooting or manual data management by an administrator.

---

## 3. How-To Guides: Common Tasks

### How to Onboard a Resident and Issue an ID Card

> **Note on User Types**: This guide covers the standard workflow for residents (Owners, Tenants, Guests) whose records are created by the CSV import. For Staff and Contractors, you will first need to create their records manually by clicking the "Add New Cardholder" button, and then follow the steps below.

> **Important Prerequisite**: Except for HOA staff and approved contractors, a person must first be registered in the official HOA property management system. If a new resident is not found in the access control database after a recent import, please direct them to the front desk to complete the necessary paperwork (like the Age Verification Form) so they can be added to the property management system for the next import.

**Step 1: Find the Cardholder** Navigate to **Manage Cardholders > Cardholders**. Use the Search box to find the resident by their last name or property address. Once you've found them, click the **Edit icon (✎)** to open their record.

**Step 2: Review and Edit Cardholder Details** Go through the fields on the form to ensure they are correct before printing.

* **Name (First & Last)**: The import process sets this to the resident's formal name from the deed. You should adjust this to be the preferred name for the ID card. This often means removing middle names, titles like "Trustee," or using a nickname (e.g., changing "William" to "Bill").
* **Title**: This optional field appears under the name on the ID card. It is typically left blank for residents but is used for staff to indicate their job, such as "General Manager".
* **Email & Phone**: This contact information is synced from the property management database.
    * *Note*: Changes you make here will be overwritten by the next CSV import unless the **Manual Override** box is checked. To permanently update contact information, it must be changed in the main property management system.
* **Email "Used" Checkbox**: Ask the cardholder if they check their email daily. If so, check this box. This helps the board understand what percentage of the community can be reached effectively via email.
* **Phone Type**: Ask the cardholder if their phone is a smartphone capable of receiving texts. Select **Mobile** if it is. This helps the board gauge texting capabilities in the community.
* **Permissions**: By default, all residents are part of the "Default" permissions group. For staff members, you should also check the box for the "Staff" group to grant them unrestricted access.
* **Property Address**: For imported residents, this is pre-filled. For staff or contractors, use the autocomplete to search for and select the "Lodge" property.
* **Resident Type**: This is set by the import but can be changed to reflect the actual situation.
    * **Resident Owner**: A person living at the property whose name is on the deed.
    * **Tenant**: A person renting the property.
    * **Guest**: A person permanently living at the property who is not an owner or tenant (e.g., a long-term family member).
    * **Landlord**: A non-resident owner. Per current policy, Landlords are not issued access cards.
    * **Staff**: An employee of the property management firm who works on-site.
    * **Contractor**: A temporary worker. This type enables an **Expiration Date** for their card.
    * **Other**: Special cases as approved by the board (e.g., caregivers).
* **Manual Override**: This checkbox protects a record from being automatically archived by a CSV import. It is checked by default when you create a cardholder manually.

**Step 3: Add and Prepare the Photo**
* **Add a Photo**: Use either the **Upload File** button to select a photo from your computer or the **Start Webcam** button to take a live picture.
* **Crop the Photo**: Once an image is in the preview box, click **Crop Photo**. Use the tool to create a clean headshot, then click **Apply Crop**.

*Tips for a Good Photo*
* **Lighting**: Have the person sit facing the computer monitor so the screen's light illuminates their face.
* **Framing**: The person's head should fill about 3/4 of the webcam's height.
* **Background**: Use a plain, light-colored wall or a white poster board behind the person.
* **Cropping**: Leave a small, even margin around the top and sides of the head.

**Step 4: Print and Activate the Card**
* **Save and Go to Print**: Once all information is correct, click the **Print ID** button. This saves all changes and takes you directly to the print workflow screen.
* **Print the Card**: On the "Print Photo ID" screen, verify the preview and click **Start Print Process**.
* **Assign the RFID**: After the card is printed, the screen will prompt you to swipe the new card. Use the **USB Card Reader** to scan it. The 8-digit RFID is automatically saved, the Card Status is set to "Active," and the Issue Date is recorded. You will be redirected back to the cardholder list.

**Step 5: Push Changes to the Controllers** After you are redirected back to the cardholder list, a red banner will appear at the top of the screen reminding you to "Push Changes". The new card is saved in the central database, but it will not work at the physical gate readers until you push the data to the controllers. Click the **Push Changes Now** button to start the background sync. Once the sync is complete, the new card will be fully active at all gates.

### How to Replace a Lost or Damaged Card

This guide covers the process for issuing a new physical card to an existing resident. Each new card has its unique 8-digit RFID pre-encoded inside it. This workflow will deactivate the old card by replacing its RFID in the system with the new one.

1.  **Find the Cardholder and Go to the Print Screen**
    * Navigate to **Manage Cardholders > Cardholders**.
    * Use the Search box to find the resident.
    * Click the **Edit icon (✎)** to open their record.
    * Verify that their photo and information are correct.
    * In the Photo Management section, click the **Print ID** button.
2.  **Print the New Card**
    * On the "Print Photo ID" screen, verify the card preview.
    * Click the **Start Print Process** button and follow the on-screen status updates until the card has been physically printed.
3.  **Assign the New RFID and Deactivate the Old Card**
    * After the card is printed, the screen will prompt you to "Please swipe the newly printed card now."
    * Use the **USB Card Reader** to scan the newly printed card.
    * When the 8-digit RFID is read, the system automatically overwrites the old RFID number with the new one. The new card is now active, and the old, lost card is automatically deactivated.
4.  **Push Changes to the Controllers**
    * A red banner will appear at the top of the screen reminding you to "Push Changes". You must click the **Push Changes Now** button to sync the new card information to the physical controllers.

**What to Do If the RFID Scan is Missed** If you accidentally close the print screen before scanning the card, you can correct it manually:

1.  Go back to the Cardholder Management list and **Edit** the resident's record.
2.  Scroll down to the **RFID & Card Details** section.
3.  Click inside the **RFID Card ID** field and clear out any existing number.
4.  With the cursor still blinking in the field, swipe the correct card using the USB reader.
5.  Click the **Update Cardholder** button at the bottom of the page to save the new RFID.

### How to Use Bulk Actions (Export and Print Reports)

Bulk actions allow you to perform an operation on multiple cardholders at once from the main cardholder list.

**Step 1: Selecting Cardholders** There are three ways to select records:

* **Individually**: In the first column of the table, click on the row for each resident you want to select. A checkmark will appear.
* **As a Range**: To select a block of residents, click the first one, then hold down the **Shift** key on your keyboard and click the last one.
* **All Cardholders**: To select every cardholder in the database, click the checkbox in the table's header row.

> **Note**: You can use the Search box or click on column headers to sort and filter the list first. However, the 'Select All' checkbox in the header is disabled when a search is active. To select all results of a search, you must select them individually or by using Shift+click.

**Step 2: Performing an Action** Once you have selected your desired cardholders, click one of the buttons in the control bar above the table.

* **Print Selected**:
    This action opens a new, printer-friendly browser tab showing a detailed report for each selected person. This feature is ideal for creating a physical document with updated contact information that can be passed to the person who maintains the property management database when changes are needed. You can use your browser's standard print function (**Ctrl+P** or **Cmd+P**) to print the report.
* **Export Selected (.csv)**:
    This action creates and downloads a CSV spreadsheet file containing the textual data (name, address, etc.) for the selected cardholders. Photos are not included in the export.  However, there will be a link.  If you are still logged into WordPress, the link will fetch the photo.
    **Important**: This exported file is for viewing and analysis only. It is in a different format and cannot be used with the Data Import tool.

### How to Configure Hardware (Discovering Controllers & Naming Gates)

This guide explains how to add new physical door controllers to the system and define the gates they manage.

1.  **Discovering New Controllers**
    * Physically connect the new controller hardware to the network and power it on.
    * Navigate to **Hardware Management > Controllers**.
    * Click the **Discover Controllers** button.
    * The system will scan the network and display a **Discovery Results** page, listing any new hardware it finds.
2.  **Adding a Discovered Controller**
    * On the Discovery Results page, find the controller you wish to add.
    * Give the controller a descriptive **Friendly Name** (e.g., "Lodge Main Controller").
    * Ensure the **Add?** checkbox is checked and click **Add Selected Controllers**.
3.  **Defining Gates for a Controller**
    * From the **Hardware Management > Controllers** list, click the **Edit icon (✎)** next to the controller you just added.
    * In the **Associated Gates/Doors** section, enter a descriptive **Gate Name** for each physical door that is wired to a slot on the controller (e.g., "Front Entrance," "Pool Restroom Door").
    * Click the **Update Controller & Gates** button.
4.  **Configure Tasks and Permissions for New Gates**
    * After configuring the new hardware, a red banner will be visible at the top of the screen, indicating that a sync is required. Before you sync, you must set up the rules for your new gates. Access cards will not work on the new gates until you grant permissions.
    * **To grant access**: Go to the **Manage Cardholders > Permission Groups** page and edit the relevant groups to include rules for your new gates. (See: "How to Set Up a Permission Group").
    * **To schedule unlocks**: Go to the **Hardware Management > Task List** page to create any automatic unlock/lock schedules for the new gates. (See: "How to Use the Task List").
5.  **Push All Changes to Controllers**
    * Once you have finished configuring the permissions and tasks, click the **Push Changes Now** button on the red banner.
    * This performs a full synchronization, sending all your new hardware configurations, permission rules, and existing cardholder data to the controllers. When the sync is complete, your new gates will be fully operational.

### How to Use the Task List (Scheduling Gate Actions)

1.  **What is the Task List?** The Task List is an administrative tool used to schedule automatic actions for your gates. The most common use for this is to create a "Hold Open" schedule, where a door automatically unlocks every morning and re-locks every evening on specific days of the week.
2.  **Navigating to the Task List** In the main WordPress menu, navigate to **Hardware Management > Task List**. The main page shows a list of all currently scheduled tasks.
3.  **Creating or Editing a Scheduled Task** To create a new scheduled task, click the **Add New Task** button. To edit an existing one, click the **Edit icon (✎)** in its row.  
    This will open the Task Form. Fill out the following fields:
    * **Task**: Select the action you want the gate to perform.
        * **Unlock (Normally Open)**: This is the "Hold Open" state. The door will remain unlocked for everyone.
        * **Locked (Normally Closed)**: The door will be locked and will not open for anyone, even those with valid permissions. This overrides all other access rules.
        * **Unlock by Card (Controlled)**: This is the normal state where the door is locked but will open for a valid card swipe. You can use this to return a gate to its normal state after a "Hold Open" period.
    * **Adapt To**: Choose which doors this task will affect. You can select "(All Controllers & Gates)", a single controller and all its doors, or a specific, individual gate.
    * **Activation Time**: Set the time of day the task will run.
    * **Activation/Deactivation Date**: Set the date range during which this task is active. For a year-round schedule, you can leave the default dates.
    * **Days of the Week**: Check the boxes for the days you want this task to repeat.
4.  **Saving and Syncing** When you are finished, click the **Add Task** or **Update Task** button.  
    **Important**: After saving any changes to the Task List, a red banner will appear. You must click the **Push Changes Now** button to send the new schedules to the physical door controllers. The tasks will not take effect until this sync is complete.

### How to Use the Real-Time Monitor

1.  **What is the Real-Time Monitor?** The Real-Time Monitor is your live dashboard for viewing all access activity throughout the community. It is designed to be left open on a front-desk computer screen to provide immediate, at-a-glance information. It has two main parts: the Community Status Map and the Activity Log.
2.  **Navigating to the Monitor** In the main WordPress menu, navigate to **Home > Real-time Monitor**.
3.  **The Community Status Map** The map at the top of the screen shows a layout of the community buildings with the locations of all access gates.
    * **Gate Status Lights**: Each colored dot represents a gate. The color tells you its current status:
        * **Green**: Unlocked (Held Open)
        * **Red**: Locked (Will Not Open)
        * **Yellow**: Controlled (Normal - Opens for Valid Cards)
        * **Black**: Down (the system is not receiving a signal from this gate).
    * **Manual Gate Control**: You can manually override a gate's state by clicking on its dot. A menu will appear allowing you to lock, unlock, or return the gate to its normal controlled state. This is useful for letting in a contractor or securing an area during an emergency.
        * **Important**: A manual override is not timed. A gate that is manually set to 'Unlocked' will remain unlocked indefinitely until it is changed by another manual command or the next scheduled task from the Task List.
        * **Best Practice**: It is highly recommended to create a scheduled task (e.g., for midnight) to set all important gates to their normal "Controlled" state. This ensures that a gate accidentally left unlocked is automatically secured each night.
    * **Show/Hide Map**: You can use the "Show/Hide Map" button at the top of the page to collapse or expand the map view.
4.  **The Activity Log** Below the map is a live, scrolling list of all access events. New events appear at the top automatically, without needing to refresh the page.
    * **Expanded Events**: The most recent events are shown in an expanded view with the resident's photo, name, and address, and the result of the swipe ("Access Granted" or "Access Denied").
    * **Collapsed Events**: Older events collapse into a single line of text to save space.
    * **Connection Status**: A small indicator in the corner of the map should be green and read "Connected." If it is red or yellow, there may be a network issue preventing live updates.
> **Note**: The number of events shown with photos can be configured by an administrator on the `FSBHOA AC -> Monitor Settings` page.

### How to Generate Reports and View Usage Graphs

The system provides powerful tools to review historical access data and understand how community amenities are being used.

1.  **The Access Log Report** This report provides a detailed, searchable table of every access event that has occurred. It is the primary tool for investigating incidents or reviewing a specific resident's activity.
    * Navigate to **Home > Access Logs Report**.
    * Use the filters at the top of the page to find the data you need:
        * **Date Range**: Use the "Start Date" and "End Date" fields to select a specific time period.
        * **Gate**: Use the dropdown menu to see events for only one specific gate or amenity.
        * **Live Search**: Type a name, address, or RFID number into the search box to instantly filter the results.
        * **Show Photo**: Check this box to include the cardholder's photo in the table results.
    * The table will update automatically as you apply filters.
    * **Exporting to CSV**: To download a copy of your filtered results as a spreadsheet, click the **Export** button. The downloaded CSV file contains all the textual data for the events but does not include photos.
2.  **The Usage Analytics Report** This page provides a high-level, visual summary of amenity usage, which is valuable for the HOA Board when making policy and funding decisions. The report defaults to showing data for the current month and year.
    * Navigate to **Home > Analytics Report**.
    * Use the **Month** and **Year** dropdown menus at the top to select the time period you wish to view. The charts will update automatically.
    * **Usage by Gate Chart**: This bar chart shows a ranked list of the most frequently used gates and amenities for the selected month.
    * **Peak Usage Hours Chart**: This line chart shows the busiest times of day for all amenity access, helping you identify patterns in community activity.

---

## 4. System Administration

The pages described in this section are for system administrators and require a WordPress account with **Administrator** privileges, not just a standard login. These settings control the core functionality of the entire system.

All administrative functions can be found in the main WordPress dashboard menu under the **FSBHOA AC** top-level menu item.

This menu contains the following pages:

* **General Settings**: For configuring system-wide options like photo dimensions and communication settings used by the backend services.
* **Event Service, Print Service, Monitor Settings, Kiosk**: Each of these pages contains specific settings for the corresponding backend Go service. Changes made here are written to configuration files used by those services.
* **System Status**: Your dashboard for monitoring and restarting the backend services.
* **Diagnostics**: A tool for running automated tests to ensure all parts of the system are communicating correctly.

### How to Configure General Settings

This page contains settings that are shared across multiple parts of the system.

**Navigating to General Settings** In the main WordPress dashboard menu, navigate to **FSBHOA AC > General Settings**.

**Photo Editor Settings**
* **Photo Width (px) / Photo Height (px)**: These settings control the final dimensions (in pixels) of a resident's photo after it has been processed by the **Crop Photo** tool. This ensures that all photos printed on ID cards are a consistent, high-quality size.

**Display Options**
* **Address Suffix to Remove**: Enter the part of a property address that you want to hide in display lists (like the main Cardholder list). For example, if all addresses end in "Bakersfield, CA 93306", you can enter that text here to keep the address columns clean and readable.

**Service Communication Settings**
> **Note**: These are advanced settings that tell the backend services how to securely communicate with the main WordPress application. They should only be changed by a system administrator. For detailed information on how to determine the correct values for these fields, please refer to the `INSTALL.md` document.

* **WordPress API Host & Port**: The hostname and port of the WordPress site.
* **TLS Certificate Path & Key Path**: The full server file paths to the SSL certificate files required for secure communication.

**Saving and Applying Changes** This is a two-step process:

1.  After making any changes on this page, scroll to the bottom and click the **Save General Settings** button.
2.  **Important**: If you have changed any of the Service Communication Settings (Host, Port, or Certificate Paths), you must then navigate to the **System Status** page and **Restart ALL** of the backend services. The services will not load the new communication settings until they have been restarted.

### How to Configure Event Service Settings

> **Note**: This is an advanced page for configuring the `fsbhoa-events` backend service. These settings control how the service communicates with the physical door controller hardware on the network. They should only be changed by an administrator familiar with the system's network setup.

**Navigating to the Event Service Page** In the main WordPress dashboard menu, navigate to **FSBHOA AC > Event Service**.

**Settings Explained**
* **Bind Address & Broadcast Address**: These are network settings that the service uses to find and communicate with controllers on the local network.
* **Event Listener Port**: The network port the service listens on for incoming events (like card swipes) sent from the controllers.
* **Event Callback Host IP**: The IP address of the main server. This address is given to the controllers so they know where to send their events.
* **WebSocket Service Port**: The network port this service uses to listen for internal commands from other services (like the "trigger poll" command from the Monitor Service).
* **Event Service Log Path**: The full path on the server to the file where this service should write its logs. If you leave this blank, logs will be sent to the standard system journal.
* **Debug Mode**: Check this to enable much more detailed logging, which is useful for troubleshooting hardware communication issues.
* **Enable Test Stub**: A feature for developers to test the system. This should normally be left checked.

**Saving and Applying Changes** This is a two-step process:

1.  After making any changes, click the **Save Event Settings** button. This saves the settings to the database and updates the JSON configuration file for the service.
2.  **Important**: The running service will not see these changes until it is restarted. Navigate to the **System Status** page and click **Restart** for the "Event Service".

### How to Configure Print Service Settings

This page configures the `fsbhoa-zebra-printer` backend service, which is responsible for generating and printing the physical ID cards.

**Navigating to the Print Service Page** In the main WordPress dashboard menu, navigate to **FSBHOA AC > Print Service**.

**Settings Explained**
* **Zebra Print Service Port**: The network port that this service uses to listen for print job requests from the main application.
* **CUPS Printer Name**: The exact name of the Zebra ZC300 printer as it is configured in the server's CUPS printing system (e.g., Zebra-ZC300).
* **Debug Mode (Dry Run)**: If this box is checked, the service will generate the card image but will not send it to the physical printer. This is very useful for testing the card layout without wasting physical cards.
* **Card Back Logo**: Use the **Upload/Select Image** button to choose an image from the Media Library to be printed on the back of the ID cards. If this field is left blank, the printer will only print on the front of the card.
* **Print Template JSON Path**: An advanced setting pointing to the template file on the server that defines the layout of the ID card. This should only be changed by an administrator or developer.

**Saving and Applying Changes** This is a two-step process:

1.  After making any changes, click the **Save Print Settings** button.
2.  **Important**: Navigate to the **System Status** page and click **Restart** for the "Print Service" for the new settings to take effect.

### How to Configure Monitor Settings

This page configures the visual appearance and core settings of the Live Activity Monitor.

**Navigating to Monitor Settings** In the main WordPress dashboard menu, navigate to **FSBHOA AC > Monitor Settings**.

**Gate Position Editor** This tool allows you to visually place icons for your gates onto a map of the community.
* **Uploading a Map**: Click the **Upload/Change Map** button to select a background image (e.g., a site plan or aerial photo) from the WordPress Media Library.
* **Positioning Gates**: Once the map is loaded, you will see numbered dots representing each of your configured gates. Drag and drop each dot to its correct physical location on the map. The legend on the right shows which number corresponds to which gate.

**Monitor Service Settings**
* **Monitor Service Port (WSS)**: The network port that the `fsbhoa-monitor` backend service uses to listen for connections from browsers viewing the Live Monitor page.
* **Photo Event Limit**: The maximum number of recent events that will be displayed with a photo in the "Today's Activity Log."

**Saving and Applying Changes**
1.  After arranging your gates and changing any settings, click the **Save All Monitor Settings** button at the bottom of the page.
2.  **Important**: After saving, you must navigate to the **System Status** page and click **Restart** for the "Monitor Service" for the new settings to take effect.

### How to Use the System Status Page

1.  **What is the System Status Page?** This page is your main dashboard for monitoring the health of the backend services. These are the small applications that run in the background on the server to handle all communication with the physical hardware (door controllers, printers, etc.). From here, you can see if they are running and restart them if needed.
2.  **Navigating to the System Status Page** In the main WordPress dashboard menu, navigate to **FSBHOA AC > System Status**.
3.  **Understanding the Status Indicators** Next to each service is a status indicator that tells you its current state:
    * **Running (Green)**: The service is healthy and operating normally.
    * **Stopped (Red)**: The service is not running. This might be due to a server reboot or an error. You should try restarting it.
    * **Checking... (Gray)**: The page is in the process of getting the current status from the server. If an indicator remains in this state for more than 10 seconds, it means the page cannot get a response from the service. You should contact your system administrator to check the service logs for errors.
4.  **How to Manage Services** If a service is stopped or seems unresponsive, you can use the action buttons in its row to manage it.
    * **Start**: Use this to start a service that is currently stopped.
    * **Stop**: Use this to stop a running service.
    * **Restart**: This is the most common button you will use. It performs a "stop" and then an immediate "start." This is required after you save any changes on the settings pages for a service (e.g., Kiosk Settings, Monitor Settings) for those changes to take effect.
> **Note**: After clicking a button, please wait 5-10 seconds for the action to complete and for the status indicator to update with the new state.

### How to Use the Diagnostics Page

This is an advanced page for system administrators. It provides tools to test and troubleshoot the communication between the various parts of the access control system.

**Navigating to the Diagnostics Page** In the main WordPress dashboard menu, navigate to **FSBHOA AC > Diagnostics**.

1.  **Running the Full Test Suite**
    * This is an automated test that checks the main data pathways in the system to ensure they are working correctly.
    * Click the **Run Full Test Suite** button.
    * Observe the **Test Results** box below. The script will run through several steps, logging the result of each one (e.g., triggering a hardware event, verifying it was saved to the database).
    * A successful run will show a "success" status for all steps and end with a "Test Suite Complete" message. If any step fails, it will be marked in red, which can help diagnose system problems.
2.  **Sending a Custom Test Event**
    * This tool allows you to simulate a specific card swipe at a specific controller. It is very useful for debugging the Real-Time Monitor without needing to be at a physical card reader.
    * In the **Card Number** field, enter the 8-digit RFID of a card you want to test.
    * In the **Controller Serial Number** field, enter the serial number of the controller you want the event to appear to come from.
    * Click the **Run Custom Test** button.
    * A pop-up will confirm the test event was sent. You can then go to the **Home > Real-Time Monitor** page to see the simulated swipe appear in the activity log.
> **Note**: The simulated event will be randomly marked as 'Access Granted' or 'Access Denied'. This is useful for testing how both types of events appear on the Live Monitor.

---

## 5. System Maintenance & Data Protection (Revised)

This section provides a high-level guide for system administrators on how to perform routine maintenance and ensure the system's data is backed up safely.

### 5. System Maintenance & Data Protection

This section provides system administrators with the procedures for performing routine system maintenance, specifically creating reliable backups and restoring the system using the provided scripts.

---

#### Backup Strategy Overview

A complete and reliable backup consists of two key components:

* **The WordPress Database**: This contains all cardholder data, photos, permissions, settings, and access logs.
* **The Media Library**: This contains all uploaded images, primarily resident photos. This is the `/wp-content/uploads/` directory.

The recommended strategy is to use the `backup.sh` script to perform an automated backup every night. **Crucially, these backup files must be regularly copied to a secure, off-site location** (such as a separate server or a cloud storage provider). A backup stored on the same server is not sufficient protection against hardware failure or a server-wide compromise.

---

#### The Automated Backup Script (`backup.sh`)

The `backup.sh` script, available in the project repository, automates the process of backing up the database and media library. It creates timestamped, compressed files for easy management and includes a cleanup function to remove backups older than 30 days.

##### Setup and Automation Instructions

1.  **Locate and Configure the Script**: Find the `backup.sh` script on the server. Before its first use, you must open the file and edit the variables in the **CONFIGURATION** section to match your server's environment (database credentials, file paths).  This information can be obtained from the file /var/www/html/wp-config.php.
2.  **Run Manually (Optional)**: To run a backup manually at any time, simply execute the script from the terminal:
    ```bash
    ./backup.sh
    ```
3.  **Automate with Cron**: To run the backup automatically, set up a cron job.
    * Open the crontab editor with `crontab -e`.
    * Add the following line to schedule the script to run daily at 2:00 AM:
        ```
        0 2 * * * /path/to/your/backup.sh > /dev/null 2>&1
        ```
        * (Ensure you use the correct full path to your `backup.sh` file.)*

---

#### The Manual Restore Script (`restore.sh`)

This script is used to recover the system from a previous backup. It is a powerful tool that should only be used when necessary and with extreme care.

> **WARNING: EXTREME CAUTION IS ADVISED.** This script will permanently overwrite your live database and media library with the data from the backup files. There is no undo. It is highly recommended to perform a backup of the current state before running a restore, if possible.

##### How to Perform a Restore

1.  **Locate and Configure the Script**: Find the `restore.sh` script on the server. Ensure the variables in its **CONFIGURATION** section are correct for your environment.
2.  **Identify Backup Files**: Locate the specific database (`.sql.gz`) and uploads (`.tar.gz`) files you wish to restore.
3.  **Run the Script**: Execute the script from your terminal, providing the full paths to the database and uploads backup files as arguments.

    **Example Command:**
    ```bash
    ./restore.sh /path/to/backup_storage/wp_database_2025-08-15.sql.gz /path/to/backup_storage/wp_uploads_2025-08-15.tar.gz
    ```
4.  **Confirm**: The script will ask for a final confirmation before proceeding. Type `y` and press Enter to begin the destructive restore process.


