<?php
/**
 * Handles the CSV import functionality for cardholders (Version 2).
 *
 * This module provides the user interface for uploading a CSV file and processes
 * the file to sync property, owner, and tenant data with the database according
 * to the specifications for Iteration 6. This class is designed to be called
 * by a shortcode.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 *
 *
 *
 * Gemini: please do not remove this comment block.
 * To clear the database of all records, do the following;
SET FOREIGN_KEY_CHECKS=0;

DELETE FROM `ac_property`;
DELETE FROM `ac_cardholders`;
DELETE FROM `ac_access_log`;

ALTER TABLE `ac_property` AUTO_INCREMENT = 1;
ALTER TABLE `ac_cardholders` AUTO_INCREMENT = 1;
ALTER TABLE `ac_access_log` AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS=1;
*******************************/

if (!defined('WPINC')) {
    die;
}

class Fsbhoa_Import_V2
{
    private $wpdb;
    private $table_cardholders;
    private $table_properties;
    private $table_deleted_cardholders;
    private $feedback = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_cardholders = 'ac_cardholders';
        $this->table_properties = 'ac_property';
        $this->table_deleted_cardholders = 'ac_deleted';
    }

    /**
     * Main render method for the shortcode. It handles form submission and displays the UI.
     */
    public function render_shortcode_page()
    {
        // Check for form submission before rendering anything
        if (isset($_POST['fsbhoa_import_v2_nonce']) && wp_verify_nonce($_POST['fsbhoa_import_v2_nonce'], 'fsbhoa_import_v2_action')) {
            $this->handle_import_submission();
        }

        // Now, start output buffering to capture the HTML
        ob_start();
        $this->render_import_form();
        return ob_get_clean();
    }
    
    /**
     * Renders the HTML for the import form.
     */
    private function render_import_form()
    {
        if (!current_user_can('manage_options')) {
            echo "<p>You do not have sufficient permissions to perform this action.</p>";
            return;
        }
        ?>
        <div class="fsbhoa-import-wrapper  fsbhoa-frontend-wrap">
            <div class="import-section">
                <h2>Cardholder & Property Sync</h2>

                <?php if (!empty($this->feedback)) : ?>
                    <div class="import-results notice notice-<?php echo esc_attr($this->feedback['type']); ?>">
                        <p><strong><?php esc_html_e('Import Results:', 'fsbhoa-ac'); ?></strong></p>
                        <ul>
                            <?php foreach ($this->feedback['messages'] as $message) : ?>
                                <li><?php echo esc_html($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <p>This tool imports or synchronizes all data for addresses, owners, and tenants 
                   from a single CSV file extracted from the property management database. 
                   Any resident that is defined in this file will be added to our access control 
                   database (See Cardholders). Any resident that is not in this CSV file but is 
                   in our access control database will be archived (see Archived Cardholders), 
                   unless that record is marked with an override. 
                    <a href="#" id="open-csv-info-dialog" style="text-decoration: underline;">More info on CSV file content.</a>
                </p>

                <div id="csv-info-dialog" title="CSV File Content" style="display:none;">
                    <p>The columns in the CSV file can be in any order, but the file must contain 
                     a header row with the following titles:</p>
                    <ul class="fsbhoa-info-list">
                        <li><strong>Property Address</strong> -- full address. The city, state, and zip matching the text in WordPress options will be removed.</li>
                        <li><strong>First Name</strong> -- The formal First Name as on deed</li>
                        <li><strong>Last Name</strong> -- The formal Last Name as on deed</li>
                        <li><strong>Second Owner First Name</strong> -- formal first name as on deed</li>
                        <li><strong>Second Owner Last Name</strong> -- formal last name as on deed.</li>
                        <li><strong>Phone</strong> -- comma separated list of corresponding phone numbers</li>
                        <li><strong>Email</strong> -- comma separated list of corresponding email addresses</li>
                        <li><strong>Tenant Names(s)</strong> -- a comma separated list of tenant's Formal names</li>
                        <li><strong>Tenant Email(s)</strong> -- a comma separated list of corresponding emails.</li>
                        <li><strong>Tenant Phone(s)</strong> -- a comma separated list of corresponding phone numbers</li>
                    </ul>
                    <p>A separate cardholder record will be generated for each resident and address 
                       combination; for owner, 2nd owner, and each tenant.</p>
                    <p>Note that if a resident moves from one address to another within the community, 
                       a new cardholder record will be created for the new resident and address 
                       combination and the old cardholder record will be moved to the archive. To 
                       recover the photo and rfid for this resident, go to the Archive Cardholder 
                       screen, click the merge icon, locate the new address, and merge.</p>
                </div>

                <form method="post" action="" enctype="multipart/form-data" class="fsbhoa-form">
                    <?php wp_nonce_field('fsbhoa_import_v2_action', 'fsbhoa_import_v2_nonce'); ?>
                    <p>
                        <label for="csv_import_file"><strong>Select CSV File to Import:</strong></label><br>
                        <input type="file" id="csv_import_file" name="csv_file" accept=".csv, text/csv" required>
                    </p>
                    <p>
                        <input type="submit" name="submit_import" class="button-primary" value="Import CSV">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handles the file upload submission.
     */
    private function handle_import_submission()
    {
        if (!current_user_can('manage_options') || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $this->feedback = ['type' => 'error', 'messages' => [__('File upload error or insufficient permissions. Please try again.', 'fsbhoa-ac')]];
            return;
        }
        
        $file_path = sanitize_text_field($_FILES['csv_file']['tmp_name']);
        $this->feedback = $this->process_csv_file($file_path, false);
    }
    
    
    /**
     * Core processing logic that reads and syncs data from the CSV file.
     * @param string $file_path The temporary server path to the uploaded CSV file.
     * @return array An array containing feedback messages and status.
     */
    function process_csv_file($file_path,  $is_dry_run = false)
    {
        $stats = [
            'rows_processed' => 0,
            'properties_created' => 0,
            'cardholders_created' => 0,
            'cardholders_updated' => 0,
            'cardholders_deleted' => 0,
            'landlords_identified' => 0,
            'errors' => [],
        ];

        $address_suffix_to_remove = get_option('fsbhoa_ac_address_suffix', '');
        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return ['type' => 'error', 'messages' => [__('Could not open the uploaded file.', 'fsbhoa-ac')]];
        }

        $header_raw = fgetcsv($handle);
        if ($header_raw === false) {
             return ['type' => 'error', 'messages' => [__('Could not read the header row from the CSV file.', 'fsbhoa-ac')]];
        }

        // Detect and remove UTF-8 BOM from the first header element if it exists
        if (isset($header_raw[0]) && strpos($header_raw[0], "\xEF\xBB\xBF") === 0) {
            $header_raw[0] = substr($header_raw[0], 3);
        }

        $header = array_map('trim', array_map('strtolower', $header_raw));

        while (($row_data = fgetcsv($handle)) !== false) {
            $stats['rows_processed']++;
            // Pad the row data with empty strings if it has fewer columns than the header
            $row_data = array_pad($row_data, count($header), '');
            $row = array_combine($header, $row_data);

            try {
                $new_cardholders_from_row = $this->parse_cardholders_from_row($row);

                $property_address_raw = $this->get_value_from_row($row, ['property address', 'property_address']);
                $property_id = $this->get_or_create_property($property_address_raw, $stats, $is_dry_run);
                if (!$property_id) {
                    throw new Exception("Skipping row due to missing or invalid property address.");
                }

                $existing_db_cardholders = $this->get_cardholders_by_property($property_id);
                $this->sync_property_occupants($new_cardholders_from_row, $existing_db_cardholders, $property_id, $stats, $is_dry_run);
                $this->apply_changes_to_db($new_cardholders_from_row, $property_id, $stats, $is_dry_run);

            } catch (Exception $e) {
                $stats['errors'][] = "Row " . ($stats['rows_processed'] + 1) . ": " . $e->getMessage();
            }
        }
        fclose($handle);
        
        // Only log a pending change if this is NOT a dry run
        if (!$is_dry_run) {
            fsbhoa_log_pending_change(); 
        }
        $feedback_messages = [
            sprintf(__("Import complete. Processed %d rows.", 'fsbhoa-ac'), $stats['rows_processed']),
            sprintf(__("Properties Created: %d", 'fsbhoa-ac'), $stats['properties_created']),
            sprintf(__("Cardholders Created: %d", 'fsbhoa-ac'), $stats['cardholders_created']),
            sprintf(__("Cardholders Updated: %d", 'fsbhoa-ac'), $stats['cardholders_updated']),
            sprintf(__("Cardholders Removed: %d", 'fsbhoa-ac'), $stats['cardholders_deleted']),
            sprintf(__("Owner sets updated to 'Landlord': %d", 'fsbhoa-ac'), $stats['landlords_identified']),
        ];

        // Add a message to the results if this was a dry run
        if ($is_dry_run) {
            array_unshift($feedback_messages, "--- DRY RUN MODE: No changes were made to the database. ---");
        }


        if (!empty($stats['errors'])) {
            $feedback_messages[] = __("--- The following errors occurred: ---", 'fsbhoa-ac');
            $feedback_messages = array_merge($feedback_messages, $stats['errors']);
        }
        
        return [ 'type' => empty($stats['errors']) ? 'success' : 'warning', 'messages' => $feedback_messages ];
    }
    

    private function sync_property_occupants(&$new_cardholders_from_row, $existing_db_cardholders, $property_id, &$stats, $is_dry_run)
    {
        // Compare based on the formal import name, not the preferred display name
        $new_full_names = array_map(function ($ch) { 
            return strtolower(trim($ch['import_first_name'])) . ' ' . strtolower(trim($ch['import_last_name'])); 
        }, $new_cardholders_from_row);

        foreach ($existing_db_cardholders as $db_cardholder) {
            // Compare based on the formal import name stored in the database
            $existing_full_name = strtolower(trim($db_cardholder->import_first_name)) . ' ' . strtolower(trim($db_cardholder->import_last_name));
            
            // If an existing person from an import is NOT in the new import file, delete them.
            if (!in_array($existing_full_name, $new_full_names)) {
                if ($db_cardholder->origin === 'import') {
                    // Only archive if it's not a dry run
                    if (!$is_dry_run) {
                        $result = fsbhoa_archive_and_delete_cardholder($db_cardholder->id);

                        if (is_wp_error($result)) {
                            $stats['errors'][] = "Row " . ($stats['rows_processed'] + 1) . ": Could not archive '{$db_cardholder->first_name} {$db_cardholder->last_name}'. Reason: " . $result->get_error_message();
                        } else {
                            $stats['cardholders_deleted']++;
                        }
                    } else {
                        // In dry run mode, just increment the stat
                        $stats['cardholders_deleted']++;
                    }
                }
            }
            
            // If a manually created user exists, prevent the import from creating a duplicate.
            if ($db_cardholder->origin !== 'import' && in_array(strtolower(trim($db_cardholder->first_name)) . ' ' . strtolower(trim($db_cardholder->last_name)), $new_full_names)) {
                $new_cardholders_from_row = array_filter($new_cardholders_from_row, function($new_ch) use ($db_cardholder) {
                    $new_ch_full_name = strtolower(trim($new_ch['first_name'])) . ' ' . strtolower(trim($new_ch['last_name']));
                    return $new_ch_full_name !== strtolower(trim($db_cardholder->first_name)) . ' ' . strtolower(trim($db_cardholder->last_name));
                });
            }
        }

        /****************************************************************************
        ***************** comment this code out.  Might need it later however.  ****
         *************So, do not remove this block.    ******************************
        $has_tenants = false;
        foreach ($new_cardholders_from_row as $cardholder) { 
            if ($cardholder['resident_type'] === 'Tenant') { 
                $has_tenants = true; 
                break; 
            } 
        }
        if (!$has_tenants) {
            foreach ($existing_db_cardholders as $db_cardholder) {
                $existing_full_name = strtolower(trim($db_cardholder->first_name)) . ' ' . strtolower(trim($db_cardholder->last_name));
                if ($db_cardholder->resident_type === 'Tenant' && in_array($existing_full_name, $new_full_names)) { 
                    $has_tenants = true; 
                    break; 
                }
            }
        }
        if ($has_tenants) {
            foreach ($new_cardholders_from_row as &$cardholder) {
                if ($cardholder['resident_type'] !== 'Tenant') {
                    $cardholder['resident_type'] = 'Landlord';
                }
            }
            unset($cardholder);
        }
        ***************** end of commented out block.   *******************/
    }

private function parse_cardholders_from_row($row)
    {
        $parsed_cardholders = [];

        $owner1_first = $this->get_value_from_row($row, ['first name', 'firstname', 'first_name']);
        $owner1_last = $this->get_value_from_row($row, ['last name', 'lastname', 'last_name']);

        $owner2_first = $this->get_value_from_row($row, ['second owner first name', 'secondownerfirstname', 'second_owner_first_name']);
        $owner2_last = $this->get_value_from_row($row, ['second owner last name', 'secondownerlastname', 'second_owner_last_name']);
        
        $phones_str = $this->get_value_from_row($row, ['phone', 'phonenumber']);
        $phones_str_cleaned = str_replace(':', ',', $phones_str);
        $owner_phones = !empty($phones_str_cleaned) ? array_map('trim', explode(',', $phones_str_cleaned)) : [];
        
        $emails_str = $this->get_value_from_row($row, ['email', 'emailaddress']);
        $owner_emails = !empty($emails_str) ? array_map('trim', explode(',', $emails_str)) : [];

        $tenant_names_str = $this->get_value_from_row($row, ['tenant name(s)', 'tenantname(s)', 'tenant_name(s)']);
        $tenant_emails_str = $this->get_value_from_row($row, ['tenant email(s)', 'tenantemail(s)', 'tenant_email(s)']);
        $tenant_phones_str = $this->get_value_from_row($row, ['tenant phone(s)', 'tenantphone(s)', 'tenant_phone(s)']);

        // Owner 1
        if (!empty($owner1_first) && !empty($owner1_last)) {
            $email1 = $owner_emails[0] ?? '';
            $parsed_cardholders[] = [
                'first_name'        => trim($owner1_first),
                'last_name'         => trim($owner1_last),
                'import_first_name' => trim($owner1_first),
                'import_last_name'  => trim($owner1_last),
                'title'             => '', // Manually entered
                'email'             => $email1,
                'email_used'        => !empty($email1) ? 1 : 0, // Set default based on email presence
                'phone'             => $this->normalize_phone($owner_phones[0] ?? ''),
                'resident_type'     => 'Resident Owner',
                'origin'            => 'import',
            ];
        }

        // Owner 2
        if (!empty($owner2_first) && !empty($owner2_last)) {
            $email2 = $owner_emails[1] ?? '';
            $parsed_cardholders[] = [
                'first_name'        => trim($owner2_first),
                'last_name'         => trim($owner2_last),
                'import_first_name' => trim($owner2_first),
                'import_last_name'  => trim($owner2_last),
                'title'             => '', // Manually entered
                'email'             => $email2,
                'email_used'        => !empty($email2) ? 1 : 0, // Set default based on email presence
                'phone'             => $this->normalize_phone($owner_phones[1] ?? ''),
                'resident_type'     => 'Resident Owner',
                'origin'            => 'import',
            ];
        }

        // Tenants
        if (!empty($tenant_names_str)) {
            $tenant_names = array_map('trim', explode(',', $tenant_names_str));
            $tenant_emails = !empty($tenant_emails_str) ? array_map('trim', explode(',', $tenant_emails_str)) : [];
            $tenant_phones_str_cleaned = str_replace(':', ',', $tenant_phones_str);
            $tenant_phones = !empty($tenant_phones_str_cleaned) ? array_map('trim', explode(',', $tenant_phones_str_cleaned)) : [];

            foreach ($tenant_names as $index => $name) {
                $name_parts = array_filter(explode(' ', trim($name)));
                if (count($name_parts) < 2) continue;

                $last_name = array_pop($name_parts);
                $first_name = implode(' ', $name_parts);
                $tenant_email = $tenant_emails[$index] ?? '';

                $parsed_cardholders[] = [
                    'first_name'        => $first_name,
                    'last_name'         => $last_name,
                    'import_first_name' => $first_name,
                    'import_last_name'  => $last_name,
                    'title'             => '', // Manually entered
                    'email'             => $tenant_email,
                    'email_used'        => !empty($tenant_email) ? 1 : 0, // Set default based on email presence
                    'phone'             => $this->normalize_phone($tenant_phones[$index] ?? ''),
                    'resident_type'     => 'Tenant',
                    'origin'            => 'import',
                ];
            }
        }
        return $parsed_cardholders;
    }

    private function get_or_create_property($raw_address, &$stats, $is_dry_run)
    {
        if (empty(trim($raw_address))) {
            return null;
        }

        // 1. Clean the raw address from the CSV
        $address_suffix_to_remove = get_option('fsbhoa_ac_address_suffix', '');
        $clean_address = preg_replace('/\s+/u', ' ', trim($raw_address)); // Normalize whitespace
        if (!empty($address_suffix_to_remove)) {
            $clean_address = preg_replace('/' . preg_quote(trim($address_suffix_to_remove), '/') . '$/i', '', $clean_address);
            $clean_address = trim($clean_address);
        }

        if (empty($clean_address)) {
            return null; // Address was just the suffix, so it's empty
        }

        // 2. Parse the cleaned address into house number and street name
        if (!preg_match('/^([0-9]+[A-Z]?)\s+(.*)/', $clean_address, $matches)) {
            throw new Exception("Could not parse address '{$clean_address}'. It must start with a house number.");
        }
        $house_number = trim($matches[1]);
        $street_name = trim($matches[2]);

        // 3. Check if property exists based on the new split fields
        $query = $this->wpdb->prepare(
            "SELECT property_id FROM {$this->table_properties} WHERE house_number = %s AND street_name = %s",
            $house_number,
            $street_name
        );
        $property_id = $this->wpdb->get_var($query);

        if ($this->wpdb->last_error) {
            throw new Exception("Database error while checking for property '{$clean_address}': " . $this->wpdb->last_error);
        }

        if ($property_id) {
            return (int) $property_id;
        } else {
            if (!$is_dry_run) {
                // 4. Create new property, populating all three address columns
                $result = $this->wpdb->insert(
                    $this->table_properties,
                    [
                        'house_number'   => $house_number,
                        'street_name'    => $street_name,
                        'street_address' => $clean_address, // Populate legacy field
                        'origin'         => 'import'
                    ],
                    ['%s', '%s', '%s', '%s']
                );

                if ($result === false) {
                    throw new Exception("Database error: Could not create property for address '{$clean_address}'. DB Error: " . $this->wpdb->last_error);
                }
            }
            $stats['properties_created']++;
            return $this->wpdb->insert_id;
        }
    }

    private function get_cardholders_by_property($property_id) { 
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table_cardholders} WHERE property_id = %d", $property_id)); 
    }
    
    
    private function apply_changes_to_db($new_list, $property_id, &$stats, $is_dry_run)
    {
        foreach ($new_list as $cardholder_data) {
            $cardholder_data['property_id'] = $property_id;
            
            // Find existing records using the IMPORT names as the key
            $query = $this->wpdb->prepare(
                "SELECT id, phone, email, resident_type, import_first_name, import_last_name FROM {$this->table_cardholders} WHERE import_first_name = %s AND import_last_name = %s AND property_id = %d",
                $cardholder_data['import_first_name'],
                $cardholder_data['import_last_name'],
                $property_id
            );
            $existing_record = $this->wpdb->get_row($query);
            
            if ($this->wpdb->last_error) {
                throw new Exception("DB error checking for cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}': " . $this->wpdb->last_error);
            }

            if ($existing_record) {
                // UPDATE existing record
                $data_to_update = [];

                // Update contact info if changed
                if ($existing_record->phone !== $cardholder_data['phone']) {
                    $data_to_update['phone'] = $cardholder_data['phone'];
                }
                if ($existing_record->email !== $cardholder_data['email']) {
                    $data_to_update['email'] = $cardholder_data['email'];
                }
                
                /*******************************************************
                 **********   COMMENT OUT THIS BLOCK, DO NOT REMOVE ****
                 *******************************************************
                // Update resident type if changed
                if ($existing_record->resident_type !== $cardholder_data['resident_type']) {
                    $data_to_update['resident_type'] = $cardholder_data['resident_type'];
                    if ($cardholder_data['resident_type'] === 'Landlord') {
                        $stats['landlords_identified']++;
                    }
                }
                *************** End of commented out block ************/

                // Also update the import_name fields in case of a formal name change
                if ($existing_record->import_first_name !== $cardholder_data['import_first_name']) {
                    $data_to_update['import_first_name'] = $cardholder_data['import_first_name'];
                }
                if ($existing_record->import_last_name !== $cardholder_data['import_last_name']) {
                    $data_to_update['import_last_name'] = $cardholder_data['import_last_name'];
                }

                if (!empty($data_to_update)) {
                    // Only update if it's not a dry run
                    if (!$is_dry_run) {
                        $result = $this->wpdb->update($this->table_cardholders, $data_to_update, ['id' => $existing_record->id]);
                        if ($result === false) {
                            throw new Exception("DB error updating cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}'. DB Error: " . $this->wpdb->last_error);
                        }
                    }
                    $stats['cardholders_updated']++;
                }
            } else {
                // INSERT new record
                // The $cardholder_data array from parse_cardholders_from_row now contains all needed fields
                // Only insert if it's not a dry run
                if (!$is_dry_run) {
                    $result = $this->wpdb->insert($this->table_cardholders, $cardholder_data);
                    if ($result === false) {
                        throw new Exception("DB error inserting cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}'. DB Error: " . $this->wpdb->last_error);
                    }
                }
                $stats['cardholders_created']++;
            }
        }
    }

    /**
     * Flexibly gets a value from a CSV row by checking for multiple possible header keys.
     * @param array $row            The associative array for the CSV row.
     * @param array $possible_keys  An array of possible lowercase keys to check for.
     * @param string $default       The value to return if no key is found.
     * @return string
     */
    private function get_value_from_row($row, $possible_keys, $default = '') {
        foreach ($possible_keys as $key) {
            if (isset($row[$key])) {
                return $row[$key];
            }
        }
        return $default;
    }


    private function normalize_phone($phone) { 
        $digits = preg_replace('/[^0-9]/', '', $phone); 
        if (strlen($digits) == 11 && substr($digits, 0, 1) == '1') 
            return substr($digits, 1); 
        return (strlen($digits) == 10) ? $digits : $phone; 
    }
}

