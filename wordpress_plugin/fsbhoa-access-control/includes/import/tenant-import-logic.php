


<?php
// includes/import/tenant-import-logic.php

if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Handles the Tenant CSV import.
 * Deletes existing owners at an address and inserts/updates new tenants.
 */
function fsbhoa_handle_tenant_csv_upload($file) {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $uploaded_file = wp_handle_upload($file, ['test_form' => false]);

    if (!$uploaded_file || isset($uploaded_file['error'])) {
        echo '<div class="fsbhoa-notice error"><p>Error saving file: ' . esc_html($uploaded_file['error']) . '</p></div>';
        return;
    }

    $property_table = 'ac_property';
    $cardholder_table = 'ac_cardholders';
    $stats = ['rows' => 0, 'owners_deleted' => 0, 'tenants_added' => 0, 'tenants_updated' => 0, 'errors' => []];

    // Block 1: Pre-computation - Get all existing cardholders for matching
    $all_db_cardholders = $wpdb->get_results("SELECT c.*, p.street_address FROM {$cardholder_table} c LEFT JOIN {$property_table} p ON c.property_id = p.property_id", ARRAY_A);
    $cardholders_by_email = [];
    $cardholders_by_fingerprint = [];
    foreach ($all_db_cardholders as $ch) {
        if (!empty($ch['email'])) { $cardholders_by_email[strtolower(trim($ch['email']))][] = $ch; }
        $norm_address = isset($ch['street_address']) ? strtolower(trim($ch['street_address'])) : '';
        $cardholders_by_fingerprint[strtolower(trim($ch['first_name']) . trim($ch['last_name']) . $norm_address)] = $ch;
    }

    $has_header = isset($_POST['csv_has_header']);
    $expected_headers = [ 'address' => ['address'], 'tenantnames' => ['tenantname(s)','tenantnames'], 'phone' => ['phone','phonenumber'], 'email' => ['email','emailaddress'] ];
    $column_map = [];
    $handle = fopen($uploaded_file['file'], "r");

    // Block 2: Header Mapping
    if ($has_header) {
        $header_row = fgetcsv($handle);
        if(!empty($header_row)) {
            foreach ($header_row as $index => $name) {
                $norm = str_replace([' ','(',')'], '', strtolower(trim($name)));
                foreach ($expected_headers as $key => $aliases) {
                    if (in_array($norm, $aliases)) { $column_map[$key] = $index; break; }
                }
            }
            if (!isset($column_map['address']) || !isset($column_map['tenantnames'])) {
                $stats['errors'][] = 'Tenant CSV must contain "Address" and "Tenant Name(s)" columns.';
            }
        } else { $stats['errors'][] = 'Could not read header row from Tenant CSV.'; }
    } else { $column_map = ['address' => 1, 'tenantnames' => 5, 'phone' => 3, 'email' => 4]; }

    // Block 3: Main Processing Loop
    if (empty($stats['errors'])) {
        $row_number = $has_header ? 1 : 0;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $row_number++;
            if (count($data) < 6) continue;
            $stats['rows']++;

            $property_address = '';
            $raw_address_field = isset($data[$column_map['address']]) ? trim($data[$column_map['address']]) : '';
            if(!empty($raw_address_field)) {
                $address_parts = preg_split('/\s+M\s*:\s*/i', $raw_address_field);
                $property_address = trim($address_parts[0]);
                if (stripos($property_address, 'P:') === 0) { $property_address = trim(substr($property_address, 2)); }
            }
            if (empty($property_address)) { $stats['errors'][] = "Row {$row_number}: Property address could not be parsed."; continue; }

            $property_id = $wpdb->get_var($wpdb->prepare("SELECT property_id FROM {$property_table} WHERE street_address = %s", $property_address));
            if (!$property_id) { $stats['errors'][] = "Row {$row_number}: Property '{$property_address}' not found."; continue; }

            // Find owners at this property from the homeowner import
            $owners_to_remove = $wpdb->get_results($wpdb->prepare("SELECT id, first_name, last_name, email FROM {$cardholder_table} WHERE property_id = %d AND origin = 'import'", $property_id), ARRAY_A);
            if (!empty($owners_to_remove)) {
                $owner_ids_to_delete = wp_list_pluck($owners_to_remove, 'id');
                $id_placeholders = implode(',', array_fill(0, count($owner_ids_to_delete), '%d'));

                // Delete them from the database, with error checking
                $deleted_count = $wpdb->query($wpdb->prepare("DELETE FROM {$cardholder_table} WHERE id IN ({$id_placeholders})", $owner_ids_to_delete));
                if($deleted_count === false) {
                     $stats['errors'][] = "Row {$row_number}: DB error trying to remove owners from '{$property_address}': " . $wpdb->last_error;
                } else {
                    $stats['owners_deleted'] += $deleted_count;
                }
            }

            // Process Tenants from the CSV row
            $raw_tenant_names = isset($data[$column_map['tenantnames']]) ? trim($data[$column_map['tenantnames']]) : '';
            $tenant_names_array = array_map('trim', explode(',', $raw_tenant_names));
            $raw_phone = isset($column_map['phone']) ? trim($data[$column_map['phone']]) : '';
            $sanitized_phone = preg_replace('/[^0-9]/', '', $raw_phone);
            if (strlen($sanitized_phone) === 11 && substr($sanitized_phone, 0, 1) === '1') { $sanitized_phone = substr($sanitized_phone, 1); }
            $email = isset($column_map['email']) ? trim($data[$column_map['email']]) : '';

            foreach ($tenant_names_array as $full_name) {
                if(empty($full_name)) continue;

                $name_parts = explode(' ', $full_name);
                $last_name = array_pop($name_parts);
                $first_name = implode(' ', $name_parts);

                $matched_cardholder_id = null;
                $norm_email = strtolower($email);

                // Smart-match logic...
                if (!empty($norm_email) && isset($cardholders_by_email[$norm_email])) {
                    foreach ($cardholders_by_email[$norm_email] as $potential_match) {
                        if (strtolower($potential_match['first_name']) == strtolower($first_name) && strtolower($potential_match['last_name']) == strtolower($last_name)) {
                            $matched_cardholder_id = $potential_match['id']; break;
                        }
                    }
                }
                if (!$matched_cardholder_id) {
                    $fingerprint = strtolower($first_name . $last_name . strtolower($property_address));
                    if (isset($cardholders_by_fingerprint[$fingerprint])) { $matched_cardholder_id = $cardholders_by_fingerprint[$fingerprint]['id']; }
                }

                $tenant_data = [ 'first_name' => $first_name, 'last_name' => $last_name, 'property_id' => $property_id, 'email' => $email, 'phone' => $sanitized_phone, 'resident_type' => 'Tenant', 'origin' => 'import_tenant' ];

                if ($matched_cardholder_id) {
                    // UPDATE with error checking
                    $result = $wpdb->update($cardholder_table, $tenant_data, ['id' => $matched_cardholder_id]);
                    if ($result === false) { $stats['errors'][] = "Row {$row_number}: Failed to update tenant '{$full_name}'. DB Error: " . $wpdb->last_error; continue; }
                    $stats['tenants_updated']++;
                } else {
                    // INSERT with error checking
                    $tenant_data['card_status'] = 'inactive'; $tenant_data['notes'] = '';
                    $result = $wpdb->insert($cardholder_table, $tenant_data);
                    if ($result === false) { $stats['errors'][] = "Row {$row_number}: Failed to insert new tenant '{$full_name}'. DB Error: " . $wpdb->last_error; continue; }
                    $stats['tenants_added']++;
                }
            }
        }
    }
    fclose($handle);

    // Final Report
    echo '<div class="fsbhoa-notice success"><p><strong>Tenant Import Complete!</strong></p><ul>';
    echo '<li>' . esc_html($stats['rows']) . ' rows processed.</li>';
    echo '<li>' . esc_html($stats['owners_deleted']) . ' resident owners removed from properties.</li>';
    echo '<li>' . esc_html($stats['tenants_added']) . ' new tenants added.</li>';
    echo '<li>' . esc_html($stats['tenants_updated']) . ' existing tenants updated.</li>';
    echo '</ul></div>';

    if (!empty($stats['errors'])) {
        echo '<div class="fsbhoa-notice warning"><p><strong>Some issues were found:</strong></p><ul>';
        foreach ($stats['errors'] as $error) { echo '<li>' . esc_html($error) . '</li>'; }
        echo '</ul></div>';
    }
}
