**Technical Summary: Cardholder Form Fields & Validation Logic**

This document outlines the fields, validation rules, and operations performed by the Cardholder Add/Edit form within the fsbhoa-access-control plugin.

**1\. Profile Section (view-cardholder-profile-section.php)**

This section captures the cardholder's basic personal information.

| **Field** | **HTML Element** | **Data Type** | **Validation Logic (fsbhoa_validate_profile_data)** |
| --- | --- | --- | --- |
| **First Name** | input\[type="text"\] | VARCHAR(100) | \- **Required:** Cannot be empty. - **Sanitization:** sanitize_text_field() |
| **Last Name** | input\[type="text"\] | VARCHAR(100) | \- **Required:** Cannot be empty. - **Sanitization:** sanitize_text_field() |
| **Email** | input\[type="email"\] | VARCHAR(255) | \- **Optional:** Can be empty. - **Format:** If provided, must be a valid email format (e.g., <name@domain.com>). This is checked with a strict regex '/^\[^@\\s\]+@\[^@\\s\\.\]+\\.\[^@\\s\\.\]{2,}$/'. - **Sanitization:** sanitize_email() |
| **Phone Number** | input\[type="tel"\] | VARCHAR(30) | \- **Optional:** Can be empty. - **Format:** If provided, all non-numeric characters are stripped. The result must be exactly 10 digits. - **Database:** Only the 10 digits are stored. |
| **Phone Type** | select | VARCHAR(10) | \- **Required if Phone Number is present.** - **Values:** Must be one of "Mobile", "Home", "Work", or "Other". - **Sanitization:** sanitize_text_field() |
| **Notes** | textarea | TEXT | \- **Optional:** Can be empty. - **Sanitization:** sanitize_textarea_field() |

**2\. Address & Resident Type Section (view-cardholder-address-section.php)**

This section assigns the cardholder to a property and defines their role.

| **Field** | **HTML Element** | **Data Type** | **Validation Logic (fsbhoa_validate_address_data)** |
| --- | --- | --- | --- |
| **Property Address** | input\[type="text"\] (Autocomplete) | N/A (Display) | \- User begins typing an address. - JavaScript makes an AJAX call to fsbhoa_ajax_search_properties_callback which searches the ac_property table. - On selection, the property_id is stored in a hidden input. |
| **Property ID** | input\[type="hidden"\] | INT | \- **Optional:** Can be empty. - **Validation:** If the display address is filled, a valid numeric property_id must be submitted. Simply typing an address without selecting from the dropdown is an error. |
| **Resident Type** | select | VARCHAR(50) | \- **Required:** Cannot be empty. - **Values:** Must be one of "Resident Owner", "Tenant", "Staff", "Contractor", or "Other". - **Sanitization:** sanitize_text_field() |

**3\. RFID & Card Details Section (view-cardholder-rfid-section.php)**

This section is **only visible on the "Edit Cardholder" screen.** It manages the physical access card.

| **Field** | **HTML Element** | **Data Type** | **Validation & Operations (fsbhoa_validate_rfid_data)** |
| --- | --- | --- | --- |
| **RFID Card ID** | input\[type="text"\] | VARCHAR(8) | \- **Format:** If provided, must be exactly 8 alphanumeric characters. - **Uniqueness:** Must not already exist in the ac_cardholders table (checked against other users). - **Operation:** Entering an 8-digit ID via the UI instantly triggers JavaScript to set the status to "Active" and the issue date to today. |
| **Card Status** | span / checkbox | VARCHAR(20) | \- **On Add:** Defaults to inactive. - **On Edit (RFID Assigned):** Status is set to active. The "Disable" checkbox appears. - **Checkbox Logic:** Checking the box sets the status to disabled. Unchecking it sets the status back to active and updates the issue date. |
| **Issued On** | span | DATE | \- **Read-only.** - **Operation:** Automatically set to the current date when an RFID ID is first assigned or when a disabled card is re-activated. |
| **Expires (Contractor)** | input\[type="date"\] | DATE | \- **Visible only if resident_type is "Contractor".** - **Required if the Contractor's card is active.** - **Validation:** Must be a date in the future. - **Default:** For non-contractors, this is automatically set to a far-future date (e.g., 2099-12-31). |

**4\. Photo Section (view-cardholder-photo-section.php)**

**This section manages the cardholder's picture for the ID card.**

| **Field** | **HTML Element** | **Data Type** | **Validation Logic (fsbhoa_validate_photo_data)** |
| --- | --- | --- | --- |
| **Photo** | **input\[type="file"\], Webcam controls** | **LONGBLOB** | **\- Optional. This preview window contains the image as read from the database. It can be loaded from a file or directly from a WebCam.  <br>\- Source (File): User can select a local JPG or PNG file to be uploaded. - Source (Webcam): Or the user can capture a image using a WebCam. User can use a series of buttons to manage the webcam:       - Start WebCam: Activates the user's camera and displays the live video feed.       - Capture Image: Takes the current video frame, draws it to a canvas, and sets it as the new photo preview.       - Stop WebCam: Deactivates the camera feed. - Processing: After a new image is selected (from file or webcam), a Crop Photo button appears, Clicking the Crop Photo will open a Croppie.js modal interface. The final cropped image (as base64 data) is placed back into the preview window.** |
| **Remove Photo** | **checkbox** | **N/A** | **\- Appears only if a photo exists. - Operation: Checking this box and saving will set the photo field in the database to NULL. The JavaScript also provides instant UI feedback by hiding the preview and disabling other photo controls.** |
