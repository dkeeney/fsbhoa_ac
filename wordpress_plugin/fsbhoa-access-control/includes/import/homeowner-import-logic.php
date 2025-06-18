<?php
/*
* Handles the smart synchronization of the uploaded CSV file with error handling.
 */
function fsbhoa_handle_csv_upload_frontend($file) {
    global $wpdb;

    $has_header = isset($_POST['csv_has_header']);

    // Define the columns we need and their possible names in the CSV
    $expected_headers = [
        'propertyaddress' => ['propertyaddress', 'property_address'],
        'firstname' => ['firstname', 'first_name', 'first'],
        'lastname' => ['lastname', 'last_name', 'last'],
        'secondownerfirstname' => ['secondownerfirstname', 'second_owner_first_name'],
        'secondownerlastname' => ['secondownerlastname', 'second_owner_last_name'],
        'phone' => ['phone', 'phonenumber'],
        'email' => ['email', 'emailaddress'],
    ];

    $suffix_to_remove = trim(get_option('fsbhoa_ac_address_suffix', ''));

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    $uploaded_file = wp_handle_upload($file, ['test_form' => false]);

    if (!$uploaded_file || isset($uploaded_file['error'])) {
        echo '<div class="fsbhoa-notice error"><p>Error saving uploaded file: ' . esc_html($uploaded_file['error']) . '</p></div>';
        return;
    }

    // --- PHASE 1: PRE-COMPUTATION ---
    $property_table = 'ac_property';
    $cardholder_table = 'ac_cardholders';

    $all_db_properties = $wpdb->get_results("SELECT property_id, street_address, origin FROM {$property_table}", ARRAY_A);
    $properties_by_address = [];
    foreach ($all_db_properties as $prop) {
        $properties_by_address[strtolower(trim($prop['street_address']))] = $prop;
    }

    $all_db_cardholders = $wpdb->get_results("SELECT c.*, p.street_address FROM {$cardholder_table} c LEFT JOIN {$property_table} p ON c.property_id = p.property_id", ARRAY_A);
    $cardholders_by_email = [];
    $cardholders_by_fingerprint = [];
    $unseen_imported_ids = [];

    foreach ($all_db_cardholders as $ch) {
        if (!empty($ch['email'])) {
            $cardholders_by_email[strtolower(trim($ch['email']))][] = $ch;
        }
        $norm_address = isset($ch['street_address']) ? strtolower(trim($ch['street_address'])) : '';
        $cardholders_by_fingerprint[strtolower(trim($ch['first_name']) . trim($ch['last_name']) . $norm_address)] = $ch;
        if ($ch['origin'] === 'import') {
            $unseen_imported_ids[$ch['id']] = true;
        }
    }

    $stats = ['rows' => 0, 'prop_added' => 0, 'prop_adopted' => 0, 'ch_added' => 0, 'ch_updated' => 0, 'ch_flagged' => 0, 'errors' => []];

    // --- PHASE 2: PROCESS CSV FILE ---
    $handle = fopen($uploaded_file['file'], "r");

    $column_map = [];

    // If the first row contains the header, map the field names with column numbers
    if ($has_header) {
        $header_row = fgetcsv($handle);
        if (empty($header_row)) {
            $stats['errors'][] = 'Could not read the header row from the CSV file.';
        } else {
            // Build the column map
            foreach ($header_row as $index => $header_name) {
                $normalized_header = str_replace([' ', '_'], '', strtolower(trim($header_name)));
                foreach ($expected_headers as $key => $aliases) {
                    if (in_array($normalized_header, $aliases)) {
                        $column_map[$key] = $index;
                        break;
                    }
                }
            }
            // Validate that we found the required columns
            if ( !isset($column_map['propertyaddress']) || !isset($column_map['firstname']) || !isset($column_map['lastname']) ) {
                $stats['errors'][] = 'The CSV file must contain columns for at least "Property Address", "First Name", and "Last Name".';
            }
        }
    } else {
        // If no header, assume a fixed order
        $column_map = [
            'propertyaddress' => 1,
            'firstname' => 2,
            'lastname' => 3,
            'secondownerfirstname' => 4,
            'secondownerlastname' => 5,
            'phone' => 6,
            'email' => 7,
        ];
    }

    $row_number = 1;
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $row_number++;
        if (count($data) < 8) continue;
        $stats['rows']++;

        $csv_address_full = isset($column_map['propertyaddress']) ? trim($data[$column_map['propertyaddress']]) : '';
        $csv_address_trimmed = $csv_address_full; // Default to the full address

        // If a suffix is defined in settings, remove it from the address (case insensitive).
        if ( ! empty($suffix_to_remove) ) {
            // Create a more flexible regex pattern from the suffix setting.
            $pattern = $suffix_to_remove;
            // Escape any special regex characters in the user's setting string.
            $pattern = preg_quote($pattern, '/');
            // Replace all whitespace in the pattern with a flexible whitespace matcher (\s+).
            $pattern = preg_replace('/\s+/', '\s+', $pattern);

            // Now use this flexible pattern for the replacement.
            $csv_address_trimmed = trim(preg_replace('/' . $pattern . '$/i', '', $csv_address_full));
        }

        $norm_csv_address = strtolower($csv_address_trimmed);

        $property_id_for_row = null;
        if (isset($properties_by_address[$norm_csv_address])) {
            $property_id_for_row = $properties_by_address[$norm_csv_address]['property_id'];
            if ($properties_by_address[$norm_csv_address]['origin'] === 'manual') {
                $result = $wpdb->update($property_table, ['origin' => 'import'], ['property_id' => $property_id_for_row]);
                if ($result === false) {
                    $stats['errors'][] = "Row {$row_number}: Failed to adopt property '{$csv_address}'. DB Error: " . $wpdb->last_error;
                    continue; // Skip this entire row
                }
                $stats['prop_adopted']++;
            }
        } else {
            $result = $wpdb->insert($property_table, ['street_address' => $csv_address_trimmed, 'origin' => 'import'], ['%s', '%s']);
            if ($result === false) {
                $stats['errors'][] = "Row {$row_number}: Failed to insert new property '{$csv_address_trimmed}'. DB Error: " . $wpdb->last_error;
                continue; // Skip this entire row
            }
            $property_id_for_row = $wpdb->insert_id;
            $stats['prop_added']++;
        }

        // --- Sanitize the phone number ---
        $raw_phone = isset($column_map['phone']) ? trim($data[$column_map['phone']]) : '';
        $sanitized_phone = preg_replace('/[^0-9]/', '', $raw_phone);
        // Also remove a leading '1' if it exists on an 11-digit number
        if (strlen($sanitized_phone) === 11 && substr($sanitized_phone, 0, 1) === '1') {
            $sanitized_phone = substr($sanitized_phone, 1);
        }

        $owners = [
            [
                'first' => isset($column_map['firstname']) ? trim($data[$column_map['firstname']]) : '',
                'last'  => isset($column_map['lastname']) ? trim($data[$column_map['lastname']]) : '',
                'phone' => isset($column_map['phone']) ? $sanitized_phone : '',
                'email' => isset($column_map['email']) ? trim($data[$column_map['email']]) : ''
            ],
            [
                'first' => isset($column_map['secondownerfirstname']) ? trim($data[$column_map['secondownerfirstname']]) : '',
                'last'  => isset($column_map['secondownerlastname']) ? trim($data[$column_map['secondownerlastname']]) : '',
                'phone' => isset($column_map['phone']) ? $sanitized_phone : '',
                'email' => isset($column_map['email']) ? trim($data[$column_map['email']]) : ''
            ]
        ];

        foreach ($owners as $owner) {
            if (empty($owner['first']) && empty($owner['last'])) continue;

            $matched_cardholder_id = null;
            $norm_email = strtolower($owner['email']);

            if (!empty($norm_email) && isset($cardholders_by_email[$norm_email])) {
                foreach ($cardholders_by_email[$norm_email] as $potential_match) {
                    if (strtolower($potential_match['first_name']) == strtolower($owner['first']) && strtolower($potential_match['last_name']) == strtolower($owner['last'])) {
                        $matched_cardholder_id = $potential_match['id'];
                        break;
                    }
                }
                if (!$matched_cardholder_id) $matched_cardholder_id = $cardholders_by_email[$norm_email][0]['id'];
            }

            if (!$matched_cardholder_id) {
                $fingerprint = strtolower($owner['first'] . $owner['last'] . $norm_csv_address);
                if (isset($cardholders_by_fingerprint[$fingerprint])) {
                    $matched_cardholder_id = $cardholders_by_fingerprint[$fingerprint]['id'];
                }
            }

            $cardholder_data = ['first_name' => $owner['first'],
                'last_name' => $owner['last'],
                'property_id' => $property_id_for_row,
                'email' => $owner['email'],
                'phone' => $owner['phone'],
                'origin' => 'import',
            ];


            if ($matched_cardholder_id) {
                $result = $wpdb->update($cardholder_table, $cardholder_data, ['id' => $matched_cardholder_id]);
                if ($result === false) {
                    $stats['errors'][] = "Row {$row_number}: Failed to update cardholder '{$owner['first']} {$owner['last']}'. DB Error: " . $wpdb->last_error;
                    continue; // Skip to next owner
                }
                $stats['ch_updated']++;
                unset($unseen_imported_ids[$matched_cardholder_id]);
            } else {
                // Add some default values
                $cardholder_data['notes'] = '';
                $cardholder_data['card_status'] = 'inactive';
                $cardholder_data['phone_type'] = 'Mobile';
                $cardholder_data['resident_type'] = 'Resident Owner';
                $cardholder_data['card_expiry_date'] = '2099-12-31';


                $result = $wpdb->insert($cardholder_table, $cardholder_data);
                if ($result === false) {
                    $stats['errors'][] = "Row {$row_number}: Failed to insert new cardholder '{$owner['first']} {$owner['last']}'. DB Error: " . $wpdb->last_error;
                    continue; // Skip to next owner
                }
                $stats['ch_added']++;
            }
        }
    }
    fclose($handle);
    unlink($uploaded_file['file']);

    // --- PHASE 3: FLAG MISSING RECORDS ---
    if (!empty($unseen_imported_ids)) {
        $ids_to_flag = array_keys($unseen_imported_ids);
        $stats['ch_flagged'] = count($ids_to_flag);
        $id_placeholders = implode(',', array_fill(0, $stats['ch_flagged'], '%d'));

        $result = $wpdb->query($wpdb->prepare("UPDATE {$cardholder_table} SET card_status = 'absent_from_import' WHERE id IN ({$id_placeholders})", $ids_to_flag));
        if ($result === false) {
            $stats['errors'][] = "Critical Error: Failed to flag " . $stats['ch_flagged'] . " missing cardholders. DB Error: " . $wpdb->last_error;
        }
    }

    // --- Final Report ---
    echo '<div class="fsbhoa-notice success"><p><strong>Synchronization Complete!</strong></p><ul>';
    echo '<li>' . esc_html($stats['rows']) . ' rows processed from CSV file.</li>';
    echo '<li>' . esc_html($stats['prop_added']) . ' new properties added.</li>';
    echo '<li>' . esc_html($stats['prop_adopted']) . ' manual properties adopted as imported.</li>';
    echo '<li>' . esc_html($stats['ch_added']) . ' new cardholders added.</li>';
    echo '<li>' . esc_html($stats['ch_updated']) . ' existing cardholders updated/verified.</li>';
    echo '<li>' . esc_html($stats['ch_flagged']) . ' cardholders marked as absent from import.</li>';
    echo '</ul></div>';

    if (!empty($stats['errors'])) {
         echo '<div class="fsbhoa-notice warning"><p><strong>Some issues were found during the import:</strong></p><ul>';
         foreach ($stats['errors'] as $error) {
             echo '<li>' . esc_html($error) . '</li>';
         }
         echo '</ul></div>';
    }
}
