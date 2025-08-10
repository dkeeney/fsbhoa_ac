# FSBHOA Access Control: Full System Test Plan

## 1. Initial System Setup & Verification

**Goal:** Ensure the environment is correctly set up from a known-empty state.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **1.1 Clean Database** | **(Manual)** Using a database tool, run the SQL script from `includes/import/class-fsbhoa-import-v2.php` to clear all `ac_` prefixed tables. | All `ac_` tables are completely empty. |
| **1.2 Verify Hardware** | **(Physical)** Ensure at least one UHPPOTE controller is powered on and connected to the same network as the server. Ensure at least one gate on that controller has a physical card reader connected. | The hardware is ready for discovery and testing. |
| **1.3 Verify Services** | Navigate to **System Status**. | All backend services (`fsbhoa-events`, `fsbhoa-monitor`, etc.) are shown as `active (running)`. |

---
## ## 2. Hardware Configuration & Scheduled Tasks

**Goal:** Test the system's ability to find, configure, and schedule actions on the physical hardware.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **2.1 Discover Controller**| Go to **Hardware Management**. Click **Discover Controllers**. | The page reloads showing Discovery Results. The connected controller appears in the "Newly Discovered Controllers" table. |
| **2.2 Add Controller & Gates** | Give the new controller a name, check "Add?", and submit. Then, **Edit** the new controller and give names to the gates you intend to use (e.g., "Test Gate"). Click **Update**. | The controller and its named gates are saved correctly. |
| **2.3 Configure Monitor Map**| Go to **FSBHOA AC -> Monitor Settings**. Upload a background map image. Drag the numbered gate dots to their correct locations on the map. Click **Save All Monitor Settings**. | The settings save successfully. Upon page reload, the map and gate markers appear in their saved positions. |
| **2.4 Configure Tasks** | Go to the **Task List** page. Add, Edit, Disable, and Delete a test task. | All CRUD and status toggle operations for tasks work correctly. |
| **2.5 Sync & Verify Tasks** | Click the **Push Changes** banner. In the server terminal, run `uhppote-cli get-tasks <DEVICE_ID>`. | The sync completes. The task list in the command output matches the enabled tasks in the UI. |

---
## ## 3. Manual Data Setup

**Goal:** Configure the foundational data needed before the main import.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **3.1 Add Property** | Go to **Cardholder Management** -> **Manage Properties**. Manually add the "Lodge" property. | The "Lodge" property is created successfully. |
| **3.2 Create Groups** | Go to **Permission Groups**. Create: <ul><li>A "Default Group" (marked as default).</li><li>A "Staff" group (marked with "Unrestricted access").</li><li>A "Weekday Morning" group and a "Weekday Afternoon" group for merge testing.</li></ul> | All groups are created and configured correctly. |
| **3.3 Add Staff**| Go to **Cardholder Management**. Manually add a "General Manager" cardholder (Type: Staff). Assign them to the "Staff" permission group. | The cardholder is created. The "Manual Override" checkbox should be checked by default. |

---
## ## 4. CSV Import & Synchronization Workflow

**Goal:** Test the core data import and synchronization logic with evolving datasets.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **4.1 Prepare CSV Files**| **(Manual)** Create three CSV files with the required headers: <br>• **Set A:** Contains 10 unique property/resident records. <br>• **Set B:** Contains the 10 records from Set A plus 10 new unique records. <br>• **Set C:** Is a copy of Set B, but with 5 records from the middle removed. | Three CSV files (`Set A.csv`, `Set B.csv`, `Set C.csv`) are created and ready for testing. |
| **4.2 Import Set A** | Go to **Data Import**. Upload `Set A.csv`. | The import summary shows the correct number of properties and cardholders created. |
| **4.3 Import Set B** | Go to **Data Import**. Upload `Set B.csv`. | The summary shows ~10 new cardholders were created. No records are archived. |
| **4.4 Import Set C**| Go to **Data Import**. Upload `Set C.csv`. | The summary shows that the 5 records you removed from the CSV have been archived. |
| **4.5 Restore from Archive** | Go to **Deleted Cardholders**. Find and **Restore** the 5 archived records. | The records reappear in the main Cardholder Management list. |

---
## ## 5. Cardholder UI & Printing Workflow

**Goal:** Test the detailed user interface for managing an individual cardholder.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **5.1 End-to-End Card Creation**| 1. **Edit** an imported cardholder. <br>2. Test the **address autocomplete** search. <br>3. **Upload** a photo from a file, then **Remove** it. <br>4. Use the **Webcam** to capture and **Crop** a new photo. <br>5. Click **Print ID**. <br>6. On the print page, click **Start Print Process**. <br>7. After the card physically prints, **swipe the new card** using a USB reader at the prompt. | All steps complete successfully. The cardholder is updated with the new 8-digit RFID and set to "Active". |

---
## ## 6. Archive & Merge Workflow (Advanced)

**Goal:** Test the data-recovery feature for residents who move or have name changes.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **6.1 Trigger Archive**| **(Manual)** Using a database tool, edit a live cardholder record (that has a photo) and change their `import_first_name`. Then, re-import `Set B.csv`. | The import archives the record you altered and creates a new, photo-less record for them. |
| **6.2 Merge Records** | Go to **Deleted Cardholders**. For the archived record (with the photo), click the **Merge** icon. Search for and select the new, photo-less live record for that same person. Confirm the merge. | The page reloads, and the archived record is gone. |
| **6.3 Verify Merge** | Go to **Cardholder Management** and edit the live record you just merged into. | The photo, preferred name, title, etc., from the archived record should now be successfully copied onto the live record. |

---
## ## 7. Kiosk & Amenity Configuration

**Goal:** Set up and test the Kiosk and its dependencies.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **7.1 Upload Icons**| **(Manual)** Using the WordPress **Media Library**, upload several icon images (e.g., for "Pool", "Gym", "Cafe"). Copy their URLs. | Icons are available in the Media Library. |
| **7.2 Manage Amenities**| Go to **Amenity Management**. Add/Edit/Re-order/Delete amenities, pasting in the icon URLs. | All CRUD and re-ordering functions work as expected. |
| **7.3 Restart Kiosk** | Go to **System Status** and **Restart** the `fsbhoa-kiosk.service`. | The service restarts successfully. |

---
## ## 8. Live System Testing (Kiosk & Monitor)

**Goal:** Verify end-user functionality and the real-time feedback loop.

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **8.1 Kiosk Workflow** | At the **Kiosk**, swipe a valid, active card. Select a guest count and an amenity. | The Kiosk shows the cardholder info and buttons after the swipe. The sign-in is successful and the event appears on the **Live Monitor**. |
| **8.2 Kiosk Card Types** | At the **Kiosk**, swipe an **invalid**, **disabled**, and **expired** card. | For each swipe, the Kiosk displays an appropriate error message and a corresponding error event appears on the Live Monitor. |
| **8.3 Hardware Card Types** | At a **physical reader**, swipe an **invalid**, **disabled**, and **expired** card. | For each swipe, an appropriate `Access Denied` event with the correct reason ("Card Not Found", "Card Disabled", etc.) appears on the Live Monitor. |
| **8.4 Simulated Event** | Use the **Diagnostics** page to trigger a custom test event. This is primarily for debugging. | The simulated event appears on the **Live Monitor** in real-time. |

---
## ## 9. Advanced Access Logic Scenarios

**Goal:** Rigorously test the core permission logic. (Setup: Create a test cardholder "John Doe" and a "Test Gate").

| Test Scenario | Permission Setup | Gate State Setup (on Monitor) | Expected Result |
| :--- | :--- | :--- | :--- |
| **9.1 Granted** | Add John Doe to a group with permission for "Test Gate", M-F, 9am-5pm. | Set "Test Gate" to **Controlled** (Yellow). | **Access Granted**. |
| **9.2 Denied (Time)** | Use the same setup, but test outside the 9am-5pm window. | Set "Test Gate" to **Controlled** (Yellow). | **Access Denied**. |
| **9.3 Permission Merging** | Create two groups with overlapping times for the "Test Gate" (9am-12pm and 11am-2pm). Add John Doe to **both** groups. Test at 1pm. | Set "Test Gate" to **Controlled** (Yellow). | **Access Granted** (proves permissions merged). |
| **9.4 Unlocked Override** | Ensure John Doe is only in the "Default Group" (with no "Test Gate" permission). | Set "Test Gate" to **Unlocked** (Green). | The door is open. Swiping the card is ignored and **no new event is generated**. |
| **9.5 Locked Override** | Assign the "General Manager" card to the "Staff" group (Unrestricted access). | Set "Test Gate" to **Locked** (Red). | **Access Denied**. The "Locked" state overrides all permissions. |

---
## ## 10. Diagnostics, Reporting & System Admin

| Test Case | Action | Expected Result |
| :--- | :--- | :--- |
| **10.1 Full Test Suite** | Go to **Diagnostics**. Click **Run Full Test Suite**. | All test steps run and log a "success" status. |
| **10.2 Access Log Report**| Go to the **Access Log**. Filter by date and gate. Click **Export**. | The table filters correctly. The downloaded CSV matches the filtered data. |
| **10.3 Analytics** | Go to **Usage Analytics**. | The charts for "Usage by Gate" and "Peak Usage Hours" load with data. |

