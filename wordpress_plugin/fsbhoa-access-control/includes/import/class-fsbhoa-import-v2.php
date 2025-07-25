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

                <p>This tool imports or synchronizes all property, owner, and tenant data from a single CSV file. The columns can be in any order, but the file must contain a header row with the following titles:</p>
                <ul style="list-style: disc; padding-left: 20px; display: flex; flex-wrap: wrap; gap: 10px 40px;">
                    <li><code>Property Address</code></li>
                    <li><code>First Name</code></li>
                    <li><code>Last Name</code></li>
                    <li><code>Second Owner First Name</code></li>
                    <li><code>Second Owner Last Name</code></li>
                    <li><code>Phone</code></li>
                    <li><code>Email</code></li>
                    <li><code>Tenant Names(s)</code></li>
                    <li><code>Tenant Email(s)</code></li>
                    <li><code>Tenant Phone(s)</code></li>
                </ul>

                <form method="post" action="" enctype="multipart/form-data" class="fsbhoa-form">
                    <?php wp_nonce_field('fsbhoa_import_v2_action', 'fsbhoa_import_v2_nonce'); ?>
                    <p>
                        <label for="csv_import_file"><strong>Select CSV File to Import:</strong></label><br>
                        <input type="file" id="csv_import_file" name="csv_file" accept=".csv, text/csv" required>
                    </p>
                    <p>
                        <input type="submit" name="submit_import" class="button-primary" value="Run Sync">
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
        $this->feedback = $this->process_csv_file($file_path);
    }
    
    
    /**
     * Core processing logic that reads and syncs data from the CSV file.
     * @param string $file_path The temporary server path to the uploaded CSV file.
     * @return array An array containing feedback messages and status.
     */
    private function process_csv_file($file_path)
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
                $property_id = $this->get_or_create_property($property_address_raw, $stats);
                if (!$property_id) {
                    throw new Exception("Skipping row due to missing or invalid property address.");
                }

                $existing_db_cardholders = $this->get_cardholders_by_property($property_id);
                $this->sync_property_occupants($new_cardholders_from_row, $existing_db_cardholders, $property_id, $stats);
                $this->apply_changes_to_db($new_cardholders_from_row, $property_id, $stats);

            } catch (Exception $e) {
                $stats['errors'][] = "Row " . ($stats['rows_processed'] + 1) . ": " . $e->getMessage();
            }
        }
        fclose($handle);

        $feedback_messages = [
            sprintf(__("Import complete. Processed %d rows.", 'fsbhoa-ac'), $stats['rows_processed']),
            sprintf(__("Properties Created: %d", 'fsbhoa-ac'), $stats['properties_created']),
            sprintf(__("Cardholders Created: %d", 'fsbhoa-ac'), $stats['cardholders_created']),
            sprintf(__("Cardholders Updated: %d", 'fsbhoa-ac'), $stats['cardholders_updated']),
            sprintf(__("Cardholders Removed: %d", 'fsbhoa-ac'), $stats['cardholders_deleted']),
            sprintf(__("Owner sets updated to 'Landlord': %d", 'fsbhoa-ac'), $stats['landlords_identified']),
        ];

        if (!empty($stats['errors'])) {
            $feedback_messages[] = __("--- The following errors occurred: ---", 'fsbhoa-ac');
            $feedback_messages = array_merge($feedback_messages, $stats['errors']);
        }
        
        return [ 'type' => empty($stats['errors']) ? 'success' : 'warning', 'messages' => $feedback_messages ];
    }
    

private function parse_cardholders_from_row($row)
    {
        $parsed_cardholders = [];

        $owner1_first = $this->get_value_from_row($row, ['first name', 'firstname', 'first_name']);
        $owner1_last  = $this->get_value_from_row($row, ['last name', 'lastname', 'last_name']);

        $owner2_first = $this->get_value_from_row($row, ['second owner first name', 'secondownerfirstname', 'second_owner_first_name']);
        $owner2_last  = $this->get_value_from_row($row, ['second owner last name', 'secondownerlastname', 'second_owner_last_name']);

        $phones_str   = $this->get_value_from_row($row, ['phone', 'phonenumber']);
        $phones_str_cleaned = str_replace(':', ',', $phones_str); // Also clean owner phones for colons
        $owner_phones = !empty($phones_str_cleaned) ? array_map('trim', explode(',', $phones_str_cleaned)) : [];

        $emails_str   = $this->get_value_from_row($row, ['email', 'emailaddress']);
        $owner_emails = !empty($emails_str) ? array_map('trim', explode(',', $emails_str)) : [];


        $tenant_names_str  = $this->get_value_from_row($row, ['tenant name(s)', 'tenantname(s)', 'tenant_name(s)']);
        $tenant_emails_str = $this->get_value_from_row($row, ['tenant email(s)', 'tenantemail(s)', 'tenant_email(s)']);
        $tenant_phones_str = $this->get_value_from_row($row, ['tenant phone(s)', 'tenantphone(s)', 'tenant_phone(s)']);

        // Owner 1
        if (!empty($owner1_first) && !empty($owner1_last)) {
            $phones = !empty($phones_str) ? array_map('trim', explode(',', $phones_str)) : [];
            $emails = !empty($emails_str) ? array_map('trim', explode(',', $emails_str)) : [];
            $parsed_cardholders[] = [
                'first_name'    => trim($owner1_first),
                'last_name'     => trim($owner1_last),
                'email'         => $emails[0] ?? '',
                'phone'         => $this->normalize_phone($phones[0] ?? ''),
                'resident_type' => 'Resident Owner',
                'origin'        => 'import',
            ];
        }

        // Owner 2
        if (!empty($owner2_first) && !empty($owner2_last)) {
            $phones = !empty($phones_str) ? array_map('trim', explode(',', $phones_str)) : [];
            $emails = !empty($emails_str) ? array_map('trim', explode(',', $emails_str)) : [];
            $parsed_cardholders[] = [
                'first_name'    => trim($owner2_first),
                'last_name'     => trim($owner2_last),
                'email'         => $emails[1] ?? '',
                'phone'         => $this->normalize_phone($phones[1] ?? ''),
                'resident_type' => 'Resident Owner',
                'origin'        => 'import',
            ];
        }

        // Tenants
        if (!empty($tenant_names_str)) {
            $tenant_names  = array_map('trim', explode(',', $tenant_names_str));
            $tenant_emails = !empty($tenant_emails_str) ? array_map('trim', explode(',', $tenant_emails_str)) : [];

            // --- START: Phone parsing fix ---
            // Replace colons with commas to handle more separator variations before exploding.
            $tenant_phones_str_cleaned = str_replace(':', ',', $tenant_phones_str);
            $tenant_phones = !empty($tenant_phones_str_cleaned) ? array_map('trim', explode(',', $tenant_phones_str_cleaned)) : [];
            // --- END: Phone parsing fix ---

            foreach ($tenant_names as $index => $name) {
                $name_parts = array_filter(explode(' ', trim($name)));
                if (count($name_parts) < 2) continue;

                $last_name  = array_pop($name_parts);
                $first_name = implode(' ', $name_parts);

                $parsed_cardholders[] = [
                    'first_name'    => $first_name,
                    'last_name'     => $last_name,
                    'email'         => $tenant_emails[$index] ?? '',
                    'phone'         => $this->normalize_phone($tenant_phones[$index] ?? ''),
                    'resident_type' => 'Tenant',
                    'origin'        => 'import',
                ];
            }
        }
        return $parsed_cardholders;
    }

    private function get_or_create_property($raw_address, &$stats)
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
            $stats['properties_created']++;
            return $this->wpdb->insert_id;
        }
    }

    private function get_cardholders_by_property($property_id) { return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table_cardholders} WHERE property_id = %d", $property_id)); }
    
    private function sync_property_occupants(&$new_cardholders_from_row, $existing_db_cardholders, $property_id, &$stats)
    {
        $new_full_names = array_map(function ($ch) { return strtolower(trim($ch['first_name'])) . ' ' . strtolower(trim($ch['last_name'])); }, $new_cardholders_from_row);
        foreach ($existing_db_cardholders as $db_cardholder) {
            $existing_full_name = strtolower(trim($db_cardholder->first_name)) . ' ' . strtolower(trim($db_cardholder->last_name));
            if (!in_array($existing_full_name, $new_full_names)) {
                if ($db_cardholder->origin === 'import') {
                    // Call the robust, global archive function.
                    $result = fsbhoa_archive_and_delete_cardholder($db_cardholder->id);

                    if (is_wp_error($result)) {
                        // If archiving fails, log the specific error and do not increment the deleted count.
                        $stats['errors'][] = "Row " . ($stats['rows_processed'] + 1) . ": Could not archive '{$db_cardholder->first_name} {$db_cardholder->last_name}'. Reason: " . $result->get_error_message();
                    } else {
                        // Only increment the count if the archive was successful.
                        $stats['cardholders_deleted']++;
                    }
                }
            }
            if ($db_cardholder->origin !== 'import' && in_array($existing_full_name, $new_full_names)) {
                 $new_cardholders_from_row = array_filter($new_cardholders_from_row, function($new_ch) use ($existing_full_name) {
                    $new_ch_full_name = strtolower(trim($new_ch['first_name'])) . ' ' . strtolower(trim($new_ch['last_name']));
                    return $new_ch_full_name !== $existing_full_name;
                });
            }
        }

        $has_tenants = false;
        foreach ($new_cardholders_from_row as $cardholder) { if ($cardholder['resident_type'] === 'Tenant') { $has_tenants = true; break; } }
        if (!$has_tenants) {
            foreach ($existing_db_cardholders as $db_cardholder) {
                $existing_full_name = strtolower(trim($db_cardholder->first_name)) . ' ' . strtolower(trim($db_cardholder->last_name));
                if ($db_cardholder->resident_type === 'Tenant' && in_array($existing_full_name, $new_full_names)) { $has_tenants = true; break; }
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
    }

    private function apply_changes_to_db($new_list, $property_id, &$stats)
    {
        foreach ($new_list as $cardholder_data) {
            $cardholder_data['property_id'] = $property_id;
            $query = $this->wpdb->prepare("SELECT id, phone, email, resident_type FROM {$this->table_cardholders} WHERE first_name = %s AND last_name = %s AND property_id = %d", $cardholder_data['first_name'], $cardholder_data['last_name'], $property_id);
            $existing_record = $this->wpdb->get_row($query);
            if ($this->wpdb->last_error) {
                throw new Exception("DB error checking for cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}': " . $this->wpdb->last_error);
            }

            if ($existing_record) {
                // UPDATE existing record
                $data_to_update = [];
                $update_reasons = []; // For debugging
                if ($existing_record->phone !== $cardholder_data['phone']) {
                    $data_to_update['phone'] = $cardholder_data['phone'];
                    $update_reasons[] = "Phone changed from '{$existing_record->phone}' to '{$cardholder_data['phone']}'";
                }
                if ($existing_record->email !== $cardholder_data['email']) {
                    $data_to_update['email'] = $cardholder_data['email'];
                    $update_reasons[] = "Email changed from '{$existing_record->email}' to '{$cardholder_data['email']}'";
                }
                if ($existing_record->resident_type !== $cardholder_data['resident_type']) {
                    $data_to_update['resident_type'] = $cardholder_data['resident_type'];
                    $update_reasons[] = "Resident Type changed from '{$existing_record->resident_type}' to '{$cardholder_data['resident_type']}'";
                    // Only increment the count if the type is specifically changing TO 'Landlord'.
                    if ( $cardholder_data['resident_type'] === 'Landlord' ) {
                        $stats['landlords_identified']++;
                    }
                }
                if(!empty($data_to_update)) {

                    $result = $this->wpdb->update($this->table_cardholders, $data_to_update, ['id' => $existing_record->id]);
                    if ($result === false) {
                        throw new Exception("DB error updating cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}'. DB Error: " . $this->wpdb->last_error);
                    }

                    $stats['cardholders_updated']++;
                }
            } else {
                // INSERT new record
                $result = $this->wpdb->insert($this->table_cardholders, $cardholder_data);
                // --- Added DB Error Check ---
                if ($result === false) {
                    throw new Exception("DB error inserting cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}'. DB Error: " . $this->wpdb->last_error);
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

