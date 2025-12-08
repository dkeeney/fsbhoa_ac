// --- In includes/fsbhoa-access-service.php ---

class Fsbhoa_Access_Service {
    
    /**
     * Main entry point for processing all access events (Kiosk, Gate, Future Readers).
     * Handles rate limit, amenity logic, and database write.
     * * @param array $log_data Standardized event data array.
     * @return bool|WP_Error Returns true on success, WP_Error on failure/rate limit.
     */
    public static function process_and_write_event( $log_data ) {
        
        // 1. Rate Limit Check
        if (self::is_rate_limited($log_data)) {
            return new WP_Error('rate_limit', 'Event is a duplicate or rate-limited.');
        }

        // 2. Amenity Processing (Assigns amenity_name, updates guest_count, clears preceding logs)
        $log_data = self::process_amenity_logic($log_data);

        // 3. Database Write
        if ( ! self::write_to_access_log($log_data) ) {
             return new WP_Error('db_error', 'Failed to insert event into access log.');
        }

        return true;
    }

    

    /**
     * ---RATE LIMITING---
     * Checks if the event is a duplicate based on recent activity (rate limit check).
     * Concept:
     *   We are trying to avoid recording multiple swipes that a user might perform
     *   all at about the same time.  It is just redundant. So ignore.
     *   1) If there is no RFID (not a valid cardholder) then just skip the check.
     *   2) If this is a system user, it means we are running a regression test, then
     *   also skip the check.
     *   3) If no rate limit time is specified, then skip the check.
     *   4) If the current swipe is the
     *        A. the same cardholder
     *        B. within this rate limit time
     *        C. at the same gate
     *        D. and the same access granted status
     *      Then ignore this swipe entirely.
     *
     * @param array $log_data Standardized event data array.
     * @return bool Returns true if the event should be rate-limited (discarded), false otherwise.
     */
    private static function is_rate_limited( $log_data ) {
        global $wpdb;

        // there should be a cardholder_id for a valid cardholder.
        if ( !isset($log_data['cardholder_id']) || $log_data['cardholder_id'] === 0 ) {
            return false;
        }
    
        // Time window for duplicate check (e.g., last 30 seconds for the same event)
        // If no rate limit time is configured, skip rate limiting.
        $minutes = get_option('fsbhoa_ac_rate_limit_minutes', 10);
        if ($minutes > 0) {
            // Use WordPress's time functions to ensure the correct timezone.
            $time_ago_unix = current_time('timestamp') - ($minutes * MINUTE_IN_SECONDS);
            $time_ago = date('Y-m-d H:i:s', $time_ago_unix);

            // Query for a similar event for the same cardholder, door, and granted status
            $duplicate_check_query = $wpdb->prepare("
             SELECT log.log_id
                FROM ac_access_log log
                INNER JOIN ac_cardholders card ON log.cardholder_id = card.id
                WHERE log.cardholder_id = %d
                  AND log.controller_identifier = %s
                  AND log.door_number = %d
                  AND log.access_granted = %d
                  AND log.event_timestamp >= %s
                  AND card.resident_type != 'System' /* EXCLUDE SYSTEM USERS */
                ORDER BY log.event_timestamp DESC
                LIMIT 1
            ", 
                $log_data['cardholder_id'],
                $log_data['controller_identifier'],
                $log_data['door_number'],
                $log_data['access_granted'],
                $time_ago
            );
    
            $duplicate_log_id = $wpdb->get_var( $duplicate_check_query );

            if ($duplicate_log_id) {
                error_log( sprintf("[RATE LIMIT] Discarding duplicate event for Cardholder ID %d at %s.", 
                    $log_data['cardholder_id'], $log_data['event_timestamp']
                ) );
                return true; // Rate limited!
            }
        }

        return false; // Not rate limited.
    }


    /**
     * ---AMENITY TRACKING---
     * Processes all access events to apply amenity rules and clean up provisional logs.
     *  The concept:
     *    There are multiple routes that a cardholder may take to get to an amenity.
     *    We are trying to guess as to which amenity they arrived at.
     *    1) Cardholder entered the Lodge during business hours and login at the kiosk.
     *       The kiosk swipe will collect the cardholder's intended amenity.
     *       1a) Cardholder then proceeded to the amenity (no inner gates).
     *           The kiosk record holds the amenity. Done.
     *       1b) Cardholder then proceeded to an inner gate who's default amenity was the 
     *           same as recorded amenity. We already know the amenity but to avoid double counting
     *           we move the amenity (and guest count) from the kiosk record
     *           to the inner doors record. Done.
     *       1c) Cardholder then proceeded to an inner gate who's default amenity was NOT 
     *           the same as recorded amenity. We assume this cardholder is visiting more 
     *           than one amenity so we leave the recorded amenity on the kiosk record and
     *           record another record with the gate's default amenity as the access amenity.
     *    2) Cardholder entered the After Hours gate.  We don't collect the amenity so we guess
     *       the amenity could be "Courts".
     *       2a) Cardholder then proceeded to the amenity (no inner gates).
     *           The After Hours gate record holds the guessed amenity (Courts). Done
     *       2b) Cardholder then proceeded to an inner gate.
     *           The role of the inner gate is a better guess so we assign the
     *           inner gates role as the amenity and clear the after hours gate's
     *           amenity to avoid double counting. Done.
     *    3) Cardholder entered one of the perimeter gates.
     *       Record the event with the gate's default amenity as the access amenity. Done.
     *
     * NOTE: This relies on the door_role and amenity_id fields being correctly set in ac_doors.
     *
     * @param array $log_data Standardized event data array.
     * @return array The updated log data array ready for insertion.
     */
    private static function process_amenity_logic( $log_data ) {
        // 1. Skip if access was denied or no cardholder
        if ( $log_data['access_granted'] !== 1 || $log_data['cardholder_id'] === 0 ) {
            return $log_data;
        }

        // 2. Get Door/Gate Info (Assuming the API handlers retrieve this and 
        //    populate $log_data['door_info'])
        $door_info = $log_data['door_info'] ?? self::get_door_info(
            $log_data['controller_identifier'], 
            $log_data['door_number']
        );

        if ( !$door_info ) {
            return $log_data; // No mapping found for this gate
        }

        // 3. Define Time Window and Entry Gate Info (Used for look-back queries)
        $minutes = get_option('fsbhoa_ac_amenity_clear_minutes', 10);
        $time_ago = date('Y-m-d H:i:s', current_time('timestamp') - ($minutes * MINUTE_IN_SECONDS));
    
        // --- SCENARIO A: INNER GATE SWIPE (Highest Priority: Clearing/Transfer) ---
        if ( $door_info->door_role === 'INNER_GATE' ) {
        
            $recent_entry = self::find_recent_entry_gate( $log_data, $time_ago ); 
            $inner_amenity_name = self::get_amenity_name_by_id( $door_info->amenity_id );
        
            if ( $recent_entry && !empty($recent_entry->amenity_name) ) {
            
                // Check for Amenity Match (1B: Match)
                if ( $inner_amenity_name === $recent_entry->amenity_name ) {
                    // ACTION 1B/2B: Transfer amenity, clear preceding log
                    $log_data['event_description'] = 'Amenity: ' . esc_html($inner_amenity_name) . ' (Confirmed)';
                    $log_data['amenity_name'] = $recent_entry->amenity_name;
                    $log_data['guest_count'] = $recent_entry->guest_count;
                    self::clear_preceding_log($recent_entry->log_id, $door_info->friendly_name); 
                
                } else {
                    // SCENARIO 1C: Mismatch (Concurrent Usage)
                    // ACTION 1C: Record Inner Gate usage as a new event; preceding log remains
                    $log_data['event_description'] = 'Amenity Access: ' . esc_html($inner_amenity_name) . ' (Concurrent)';
                    $log_data['amenity_name'] = $inner_amenity_name;
                    $log_data['guest_count'] = 0; // Assume 0 guests for the second swipe
                }

            } else {
                // SCENARIO 3: Inner Gate (No Preceding Registration)
                $log_data['event_description'] = 'Amenity Access: ' . esc_html($inner_amenity_name) . ' (Unregistered Entry)';
                $log_data['amenity_name'] = $inner_amenity_name;
                $log_data['guest_count'] = 0;
            }
        }
    
        // --- SCENARIO B: ENTRY GATE SWIPE (Sets Provisional Amenity) ---
        else if ( $door_info->door_role === 'ENTRY_GATE' ) {
        
            // This includes West Gate and Kiosk (which supplies amenity_name directly).
            // If Kiosk, $log_data['amenity_name'] is already set and carries guest count.
        
            if ( $log_data['controller_identifier'] !== 'kiosk' ) {
                // SCENARIO 2A: Physical Entry Gate (West Gate/Front Door) - Set Provisional
                $provisional_name = self::get_amenity_name_by_id( $door_info->amenity_id );
                
                $log_data['event_description'] = 'Entry Access: Provisional ' . esc_html($provisional_name);
                $log_data['amenity_name'] = $provisional_name;
                $log_data['guest_count'] = 0;
            } 
            // If Kiosk, $log_data already contains the amenity_name and guest_count.
        }
    
        // --- SCENARIO C: PERIMETER GATE (Default/Log Only) ---
        // If the role is 'PERIMETER' or unknown, amenity_name remains NULL (as initialized).

        return $log_data;
    }
    

    /**
     * Writes the finalized log data array to the ac_access_log table.
     * @param array $log_data Standardized event data array.
     * @return bool True on success, false on database failure.
     */
    private static function write_to_access_log( $log_data ) {
        global $wpdb;

        // Remove any temporary/non-db fields (like 'resident_type' or 'door_info')
        $db_fields = array_keys( $wpdb->get_columns_in_table( 'ac_access_log' ) );
        $insert_data = array_intersect_key( $log_data, array_flip( $db_fields ) );
    
        // Explicitly define formats for insert (using placeholders for safety)
        $formats = array_fill(0, count($insert_data), '%s'); // Use %s generically, %d for integer fields if known
    
        $result = $wpdb->insert( $wpdb->prefix . 'ac_access_log', $insert_data, $formats );
        
        if ( $result === false ) {
            error_log( sprintf(
                "CRITICAL DB ERROR: Failed to log access event. Error: %s. Data: %s",
                $wpdb->last_error, 
                json_encode($log_data)
            ));
            return false;
        }
    
        return true;
    }



    /**
     * Finds a recent log entry that should be cleared by the current inner gate swipe.
     * Searches for Kiosk or ENTRY_GATE swipes within the time limit.
     *
     * @param array $current_log_data Standardized event data array for the current swipe.
     * @param string $time_ago The minimum timestamp to search from.
     * @return object|null The preceding log entry object, or null if not found.
     */
    private static function find_recent_entry_gate( $current_log_data, $time_ago ) {
        global $wpdb;

        // We assume Kiosk events and ENTRY_GATE events are the ones that need clearing.
        // The key marker is that the preceding event must have an amenity_name set.
        $query = $wpdb->prepare("
            SELECT log_id, amenity_name, guest_count
            FROM ac_access_log
            WHERE cardholder_id = %d
              AND event_timestamp >= %s
              AND amenity_name IS NOT NULL
              AND access_granted = 1
            ORDER BY event_timestamp DESC 
            LIMIT 1
        ", 
            $current_log_data['cardholder_id'],
            $time_ago
        );

        return $wpdb->get_row($query);
    }


    /**
     * Clears the amenity data from a preceding log entry after it has been transferred
     * to an INNER_GATE swipe (preventing double counting).
     *
     * @param int $log_id The log_id of the record to be cleared (the preceding Kiosk/Entry Gate swipe).
     * @param string $inner_gate_name The name of the inner gate that performed the clearing.
     * @return bool True on successful update.
     */
    private static function clear_preceding_log( $log_id, $inner_gate_name ) {
        global $wpdb;
    
        $cleared_description = $wpdb->prepare(
            'Access to %s', 
            esc_html($inner_gate_name)
        );

        return $wpdb->update(
            'ac_access_log',
            [
                'amenity_name' => NULL,
                'guest_count' => 0,
                'event_description' => $cleared_description,
            ],
            ['log_id' => $log_id],
            ['%s', '%d', '%s'],
            ['%d']
        );
    }


    /**
     * Retrieves essential door configuration (role and amenity ID) based on hardware identifiers.
     * This function uses the new door_role and amenity_id fields.
     *
     * @param string $controller_identifier The hardware serial ID (or 'VIRTUAL_KIOSK').
     * @param int $door_number The door number on the controller (or virtual Kiosk ID).
     * @return object|null Door information object with friendly_name, door_role, and amenity_id.
     */
    private static function get_door_info( $controller_identifier, $door_number ) {
        global $wpdb;
    
        // NOTE: This query joins controllers and doors to find the configuration mapping.
        $door_info_query = $wpdb->prepare("
            SELECT 
                d.friendly_name, 
                d.door_role, 
                d.amenity_id
            FROM ac_doors d
            INNER JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
            WHERE c.uhppoted_device_id = %s 
              AND d.door_number_on_controller = %d
        ", 
            $controller_identifier, 
            $door_number
        );

        $result = $wpdb->get_row($door_info_query);
    
        if ($result === null && !empty($wpdb->last_error)) {
            error_log(sprintf("DB ERROR: Failed to fetch door info. Error: %s. Query: %s",
                $wpdb->last_error, 
                $wpdb->last_query
            ));
            // Still return null, allowing the calling function to proceed, but log the error
            return null; 
        }
    
        return $result;
    }

    /**
     * Retrieves the list of amenity names covered by a single door.
     * Uses the comma-separated string stored in the modified amenity_id column.
     *
     * @param int $door_record_id The ID of the door record.
     * @return array Array of amenity names (e.g., ['Pool', 'Spa']). Returns empty array if none are configured.
     */
    private static function get_door_amenity_names( $door_record_id ) {
        global $wpdb;

        // 1. Get the list of IDs from the single amenity_id column
        $id_list_string = $wpdb->get_var( $wpdb->prepare( 
            "SELECT amenity_id FROM ac_doors WHERE door_record_id = %d", 
            $door_record_id
        ) );

        if ( empty( $id_list_string ) ) {
            return [];
        }

        // 2. Parse the comma-separated string into a clean array of IDs
        $ids = array_map('absint', explode(',', $id_list_string));
        $ids = array_filter($ids); // Removes 0s and empty strings

        if ( empty($ids) ) {
            return [];
        }
    
        // 3. Prepare and execute the query for names using call_user_func_array for dynamic arguments
        $id_placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Arguments array: [query_string, id1, id2, id3, ...]
        $prepare_args = array_merge(
            [ "SELECT name FROM ac_amenities WHERE id IN ({$id_placeholders})" ],
            $ids
        );

        $prepared_query = call_user_func_array([$wpdb, 'prepare'], $prepare_args);
        $names = $wpdb->get_col( $prepared_query );

        // 4. Sanitize and return the array of names
        return array_map('sanitize_text_field', $names);
    }

}
