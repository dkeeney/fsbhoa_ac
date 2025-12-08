<?php

/**
 * Handles REST API endpoints for the Live Monitor component.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Monitor_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        // Endpoint for the monitor_service to fetch a single, enriched event by its log ID.
        register_rest_route( $this->namespace, '/monitor/event', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_event_by_id_callback' ),
            'permission_callback' => '__return_true', // Should be secured with an API key
             'args'               => array(
                'record_id' => array(
                    'required'          => true,
                    'validate_callback' => array( $this, 'is_numeric_callback' )
                ),
            ),
        ) );
        
        // This route is called by the frontend JavaScript to get all gate data for the map
        register_rest_route( $this->namespace, '/monitor/gates', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_all_gates_callback' ),
            'permission_callback' => '__return_true',
        ) );

        // This route is called by the Go event_service to log a raw hardware event to the database
        register_rest_route( $this->namespace, '/monitor/log-event', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'log_event_callback' ),
            'permission_callback' => '__return_true', // Internal service-to-service call
        ) );

        // This route is called by the monitor page to get recent historical events
        register_rest_route( $this->namespace, '/monitor/recent-activity', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_recent_activity_callback' ),
            'permission_callback' => '__return_true',
        ) );

        // This route is called by the monitor page to manually set a door's state
        register_rest_route( $this->namespace, '/monitor/set-door-state', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'set_door_state_callback' ),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ) );
    }

    public function is_numeric_callback( $value, $request, $param ) {
        return is_numeric( $value );
    }

    public function get_all_gates_callback( WP_REST_Request $request ) {
        global $wpdb;
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';

        $query = "
            SELECT d.door_record_id, d.friendly_name, d.door_number_on_controller, d.map_x, d.map_y, c.uhppoted_device_id
            FROM {$doors_table} d
            JOIN {$controllers_table} c ON d.controller_record_id = c.controller_record_id
        ";
        $gates = $wpdb->get_results( $query, ARRAY_A );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error fetching gates.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }
        return new WP_REST_Response( $gates ?? [], 200 );
    }

    /**
     * Callback to receive an event from the Go event_service and log it to the database.
     * It also sends a notification to the new monitor_service.
     */
    public function log_event_callback( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        // Get West Gate hardware details once

        error_log('[RAW EVENT DATA] ' . print_r($params, true));

        if ( !isset($params['SerialNumber']) || !isset($params['Door']) ) {
            return new WP_Error( 'bad_request', 'Missing required event parameters.', array( 'status' => 400 ) );
        }
        $raw_card_number = absint($params['CardNumber'] ?? 0);

	$log_data = [
		'event_timestamp'       => $params['Timestamp'] ?? current_time('mysql'),
		'controller_identifier' => strval($params['SerialNumber']),
		'door_number'           => absint($params['Door']),
                'rfid_id' => ($raw_card_number === 0) ? 
                     NULL : // If the number is 0 (the 'no card' indicator), set to NULL.
                     sprintf('%08d', $raw_card_number), // Otherwise, pad the number with leading zeros to 8 characters.
		'event_type_code'       => absint($params['Reason']),
                'event_description'     => isset($params['EventMessage']) ? sanitize_text_field($params['EventMessage']) : 'Unknown Event',
		'access_granted'        => isset($params['Granted']) ? ($params['Granted'] ? 1 : 0) : null,
                'amenity_name'          => NULL,
	];
        $this->lookup_cardholder( $log_data );

/***********
        $is_resident = false;

        // --- RATE LIMIT CHECK ---
        // Concept:
        //    We are trying to avoid recording multiple swipes that a user might perform
        //    all at about the same time.  It is just redundant. So ignore.
        //    1) If there is no RFID (not a valid cardholder) then just skip the check.
        //    2) If this is a system user, it means we are running a regression test, then
        //    also skip the check.
        //    3) If no rate limit time is specified, then skip the check.
        //    4) If the current swipe is the 
        //         A. the same cardholder 
        //         B. within this rate limit time 
        //         C. at the same gate
        //         D. and the same access granted status
        //       Then ignore this swipe entirely.
       
        if ( !empty($log_data['rfid_id']) && $log_data['rfid_id'] !== '00000000' ) {
            // Fetch the whole cardholder record to get the ID and resident_type
            $cardholder = $wpdb->get_row($wpdb->prepare(
                  "SELECT id, resident_type FROM ac_cardholders WHERE rfid_id = %s", 
	  	  $log_data['rfid_id']));
            
            if ($cardholder) {
                $log_data['cardholder_id'] = $cardholder->id;
            }
        }
        if ($cardholder != NULL) {

                // Only run the rate-limit check if the user is NOT a 'System' user.
                // The regression test needs to see the entry so cannot be rate-limited.
                if ($cardholder->resident_type !== 'System') {
                    
                    $minutes = get_option('fsbhoa_ac_rate_limit_minutes', 10);

                    if ($minutes > 0) {
                        // Use WordPress's time functions to ensure the correct timezone.
                        $time_ago_unix = current_time('timestamp') - ($minutes * MINUTE_IN_SECONDS);
                        $time_ago = date('Y-m-d H:i:s', $time_ago_unix);

                        $sql = "SELECT 1 FROM ac_access_log WHERE cardholder_id = %d AND event_timestamp >= %s AND controller_identifier = %s AND door_number = %d";
                        $params_sql = [
                            $cardholder->id,
                            $time_ago,
                            $log_data['controller_identifier'],
                            $log_data['door_number']
                        ];

                        if ($log_data['access_granted'] === null) {
                            $sql .= " AND access_granted IS NULL";
                        } else {
                            $sql .= " AND access_granted = %d";
                            $params_sql[] = $log_data['access_granted'];
                        }

                        $sql .= " LIMIT 1";

                        $recent_swipe = $wpdb->get_var($wpdb->prepare($sql, $params_sql));
$minutes = get_option('fsbhoa_ac_rate_limit_minutes', 10);

                    if ($minutes > 0) {
                        // Use WordPress's time functions to ensure the correct timezone.
                        $time_ago_unix = current_time('timestamp') - ($minutes * MINUTE_IN_SECONDS);
                        $time_ago = date('Y-m-d H:i:s', $time_ago_unix);

                        if ($recent_swipe) {
                            error_log("[RATE LIMIT DEBUG] FOUND recent swipe. Ignoring this one.");
                            return new WP_REST_Response( ['status' => 'success', 'message' => 'Duplicate event ignored.'], 200 );
                        }
                    }
                }
            }
        }

/****************
        // --- AMENITY TRACKING LOGIC ---
        //   The concept:
        //     There are multiple routes that a cardholder may take to get to an amenity.
        //     We are trying to guess as to which amenity they arrived at.
        //     1) Entered the Lodge during business hours and login at the kiosk.
        //        The kiosk swipe will collect the cardholder's intended amenity.
        //        1a) then proceed to the amenity (no inner gates).
        //            The kiosk record holds the amenity.
        //        1b) then proceed to an inner gate who's role was the same as recorded amenity.
        //            We already know the amenity but to avoid double counting
        //            we move the amenity (and guest count) from the kiosk record 
        //            to the inner doors record.
        //        1c) then proceed to an inner gate who's role was NOT the same as recorded amenity.
        //            We assume this cardholder is visiting more than one amenity so
        //            we leave the recorded amenity on the kiosk record and 
        //            record another record with the gate's role as the amenity.
        //     2) Enter the After Hours gate.  We don't collect the amenity so we guess
        //        the amenity could be "Courts".
        //        2a) then proceeded to the amenity (no inner gates).
        //            The After Hours gate reocrd holds the guessed amenity (Courts).
        //        2b) Then proceeded to an inner gate.
        //            The role of the inner gate is a better guess so we assign the
        //            inner gates role as the amenity and clear the after hours gate's 
        //            amenity to avoid double counting.
        //     3) Enter one of the perimeter gates.
        //        Record the event with role as the amenity.
        //
        //  The net result: Only case 1b and 2b need extra processing.
        //

        // We use MINUTE_IN_SECONDS (defined in wp-includes)
        $ten_minutes_ago = date('Y-m-d H:i:s', current_time('timestamp') - (10 * MINUTE_IN_SECONDS));
        $default_amenity_name = get_option('fsbhoa_ac_default_court_amenity_name', 'Courts');
        $current_role = $this->get_gate_classification($log_data['controller_identifier'], $log_data['door_number']);
        $is_inner_amenity_gate = is_numeric($current_role) && absint($current_role) > 0;

        if ($current_role === "AFTER_HOURS_ACCESS") {
            // This is the after hours gate.  
            // case 2a:  We would not get an inner gate access in this case so we use
            //           the guessed amenity assigned to the after hours gate.
            $log_data['guest_count'] = 0;
            $log_data['amenity_name'] = $default_amenity_name;
            $log_data['event_description'] = 'Amenity: ' . $default_amenity_name;
            

        } else if ($log_data['access_granted'] == 1 && $is_inner_amenity_gate) {
    
            // --- SCENARIO 1:  Entered the Kiosk during business hours followed by inner gate.
            // case 1a:  Note that Kiosk entry record is recorded in the kiosk logic
            //           so we would not see case 1a here.

            // See if this is case 1b.  Is there a kiosk entry?
            $kiosk_check_query = $wpdb->prepare("
                    SELECT log_id, amenity_name, guest_count
                    FROM ac_access_log
                    WHERE cardholder_id = %d 
                      AND event_timestamp >= %s
                      AND controller_identifier = 'kiosk'
                      AND amenity_name = %s 
                      AND access_granted = 1
                    ORDER BY event_timestamp DESC 
                    LIMIT 1
                ", $log_data['cardholder_id'], 
                   $ten_minutes_ago, 
                   $log_data['amenity_name']); 
            $recent_kiosk_entry = $wpdb->get_row($kiosk_check_query);
    
            if ($recent_kiosk_entry) {
                // case 1b: Kiosk gate followed by inner gate.
                //          We have a Kiosk Entry by this cardholder within 
                //          last 10 min with this amenity. 
                //          In this case, move the guest count and amenity to inner gate record 
                //          to avoid double counting, clear kiosk amenity field. 
                $log_data['guest_count'] = $recent_kiosk_entry->guest_count;
                $log_data['amenity_name'] = $current_role;
                $log_data['event_description'] = 'Amenity: '. $log_data['amenity_name'];

                // Clear Kiosk Amenity and counts (but preserve Kiosk log entry)
                $wpdb->update(
                   'ac_access_log',
                   ['amenity_name' => NULL, 'guest_count' => 0, 'event_description' => 'Kiosk sign-in followed by ' . $inner_gate_name],
                   ['log_id' => $recent_kiosk_entry->log_id],
                   ['%s', '%d', '%s'],
                   ['%d']
                );

                // case 1c:  This is where the inner gate's role is not the same as was recorded 
                //           at the kiosk.  In this case, keep the kiosk count and record a 
                //           new event for the inner gate with its role as it's amenity.

        
            } else {
                // --- SCENARIO 2:    After Hours Entry followed by inner gate.
                //
                // See if this is case 2b.
                $afterhours_check_query = $wpdb->prepare("
                        SELECT log_id
                        FROM ac_access_log
                        WHERE cardholder_id = %d 
                          AND event_timestamp >= %s
                          AND access_granted = 1
                        ORDER BY event_timestamp DESC 
                        LIMIT 1
                    ", $log_data['cardholder_id'], $ten_minutes_ago);
                $afterhours_entry = $wpdb->get_row($afterhours_check_query);
                if ($afterhours_entry) {
                    // case 2b:
                    //    If this cardholder entered the afterhours gate less than 10 minutes ago
                    //    and now enters an inner gate.
                    //    So, clear the afterhours gate's  amenity field and change its 
                    //    descripton to prevent double counting.
            
                    // Assign Amenity Name to Current Log (0 guests assumed for non-kiosk swipe)
                    $confirmed_amenity_name = $wpdb->get_var($wpdb->prepare(
                          "SELECT name FROM ac_amenities WHERE id = %d", absint($current_role)));
                    if ($confirmed_amenity_name) {
                        $log_data['amenity_name'] = $confirmed_amenity_name;
                        $log_data['guest_count'] = 0;
                        $log_data['event_description'] = $wpdb->prepare(
                             'Amenity: %s', $confirmed_amenity_name);
                
                        // clear West Gate Amenity (Preserve gate log entry)
                        $wpdb->update(
                                'ac_access_log',
                                ['amenity_name' => NULL, 
                                 'event_description' => 'After hours access to ' . $inner_gate_name],
                                ['log_id' => $afterhours_entry->log_id],
                                ['%s', '%s'],
                                ['%d']
                        );
                    } else {
            
                        // SCENARIO 3: a perimeter gate. Use the role as amenity
                        //             OR this could be an inner gate but cardholder
                        //             did not record an entry at kiosk.
                        $log_data['guest_count'] = 0;
                        $log_data['amenity_name'] = $current_role;
                        $log_data['event_description'] = 
                    }
                }
            }
        }

        // --- END AMENITY TRACKING LOGIC ---
********************************/

        // Save the access log record for this gate.
        // Use insert_id to get the new record ID.
        $wpdb->insert('ac_access_log', $log_data);
        $log_id = $wpdb->insert_id;

        if ($log_id === 0) {
            error_log('FSBHOA DB Error logging access event: ' . $wpdb->last_error);
            return new WP_Error( 'db_error', 'Failed to insert event into access log.', array( 'status' => 500 ) );
        }

        // Send notification to the new monitor service
        $this->send_notification_to_monitor($log_id);

        return new WP_REST_Response( ['status' => 'success', 'message' => 'Event logged.'], 200 );
    }

    /**
     * Callback for the monitor_service to fetch a single enriched event.
     */
    public function get_event_by_id_callback( WP_REST_Request $request ) {
        global $wpdb;
        $log_id = absint($request->get_param('record_id'));

        $log_table = 'ac_access_log';
        $cardholders_table = 'ac_cardholders';
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';
        $property_table = 'ac_property';

        $query = $wpdb->prepare(
            "SELECT l.event_timestamp, l.access_granted, l.event_description, l.rfid_id, l.controller_identifier, ch.id as cardholder_id, ch.first_name, ch.last_name, ch.photo, d.friendly_name AS gate_name, d.door_record_id, p.street_address
             FROM {$log_table} AS l
             LEFT JOIN {$cardholders_table} AS ch ON l.cardholder_id = ch.id
             LEFT JOIN {$controllers_table} AS c ON l.controller_identifier = c.uhppoted_device_id
             LEFT JOIN {$doors_table} AS d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller
             LEFT JOIN {$property_table} AS p ON ch.property_id = p.property_id
             WHERE l.log_id = %d",
             $log_id
        );
        $event = $wpdb->get_row($query, ARRAY_A);

        if (!$event) {
            return new WP_Error('not_found', 'Event not found.', ['status' => 404]);
        }
        
        $cardholder_name = trim($event['first_name'] . ' ' . $event['last_name']);
        if (empty($cardholder_name) && !empty($event['rfid_id']) && $event['rfid_id'] != 0) {
            $cardholder_name = 'Unknown Card (' . $event['rfid_id'] . ')';
        } elseif (empty($cardholder_name)) {
            $cardholder_name = 'System Event';
        }

        $formatted_event = [
            'eventType'      => $event['access_granted'] ? 'accessGranted' : 'accessDenied',
            'cardholderName' => $cardholder_name,
            'photoURL'       => !empty($event['photo']) ? 'data:image/jpeg;base64,' . base64_encode($event['photo']) : '',
            'gateName'       => $event['gate_name'] ?: ($event['controller_identifier'] === 'kiosk' ? get_option('fsbhoa_kiosk_name', 'Kiosk') : 'Unknown Gate'),
            'timestamp'      => date('g:i:s A', strtotime($event['event_timestamp'])),
            'eventMessage'   => $event['event_description'],
            'cardNumber'     => (int)ltrim($event['rfid_id'], '0'),
            'doorRecordId'   => (int)$event['door_record_id'],
            'streetAddress'  => $event['street_address'] ?? 'N/A',
        ];

        return new WP_REST_Response($formatted_event, 200);
    }
    
    /**
     *  Callback to get recent events to populate the monitor on load.
     */
    public function get_recent_activity_callback( WP_REST_Request $request ) {
        global $wpdb;
        $query = $this->get_recent_activity_query();
        $results = $wpdb->get_results($query, ARRAY_A);

        if ($wpdb->last_error) {
            return new WP_Error('db_error', 'Database error fetching recent activity.', ['status' => 500, 'db_error' => $wpdb->last_error]);
        }

        $formatted_events = [];
        foreach ($results as $event) {
            $cardholder_name = trim($event['first_name'] . ' ' . $event['last_name']);
            if (empty($cardholder_name) && !empty($event['rfid_id']) && $event['rfid_id'] != 0) {
                $cardholder_name = 'Unknown Card (' . $event['rfid_id'] . ')';
            } elseif (empty($cardholder_name)) {
                $cardholder_name = 'System Event';
            }

            $formatted_events[] = [
                'eventType'      => $event['access_granted'] ? 'accessGranted' : 'accessDenied',
                'cardholderName' => $cardholder_name,
                'photoURL'       => !empty($event['photo']) ? 'data:image/jpeg;base64,' . base64_encode($event['photo']) : '',
                'gateName'       => $event['gate_name'] ?: ($event['controller_identifier'] === 'kiosk' ? get_option('fsbhoa_kiosk_name', 'Kiosk') : 'Unknown Gate'),
                'timestamp'      => date('g:i:s A', strtotime($event['event_timestamp'])),
                'eventMessage'   => $event['event_description'],
                'cardNumber'     => (int)ltrim($event['rfid_id'], '0'),
                'doorRecordId'   => (int)$event['door_record_id'],
                'streetAddress'  => $event['street_address'] ?? 'N/A',
            ];
        }

        return new WP_REST_Response($formatted_events, 200);
    }
    
    // Helper function for building the recent activity query
    private function get_recent_activity_query() {
        global $wpdb;
        $log_table = 'ac_access_log';
        $cardholders_table = 'ac_cardholders';
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';
        $property_table = 'ac_property';

        // It uses the database's internal clock, to get records in the last 24 hrs.
        $query = "
            SELECT l.event_timestamp, l.access_granted, l.event_description, l.rfid_id, l.controller_identifier, ch.first_name, ch.last_name, ch.photo, d.friendly_name AS gate_name, d.door_record_id, p.street_address
            FROM {$log_table} AS l
            LEFT JOIN {$cardholders_table} AS ch ON l.cardholder_id = ch.id
            LEFT JOIN {$controllers_table} AS c ON l.controller_identifier = c.uhppoted_device_id
            LEFT JOIN {$doors_table} AS d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller
            LEFT JOIN {$property_table} AS p ON ch.property_id = p.property_id
            WHERE l.event_timestamp >= NOW() - INTERVAL 24 HOUR
            ORDER BY l.event_timestamp DESC
            LIMIT 50";

        return $query;
    }

    /**
     *  Callback to manually set the control state of a single door.
     */
    public function set_door_state_callback( WP_REST_Request $request ) {
        global $wpdb;

        $params = $request->get_json_params();
        $door_id = isset($params['door_id']) ? absint($params['door_id']) : 0;
        $state_code = isset($params['state']) ? absint($params['state']) : 0;

        if (empty($door_id) || !in_array($state_code, [1, 2, 3])) {
            return new WP_Error('bad_request', 'Invalid door ID or state provided.', ['status' => 400]);
        }

        $state_map = [ 1 => 'controlled', 2 => 'normally open', 3 => 'normally closed' ];
        $state_string = $state_map[$state_code];

        $door_info = $wpdb->get_row($wpdb->prepare("SELECT c.uhppoted_device_id, d.door_number_on_controller FROM ac_doors d JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id WHERE d.door_record_id = %d", $door_id));

        if (!$door_info) {
            return new WP_Error('not_found', 'Could not find door details in database.', ['status' => 404]);
        }

        $command = sprintf('uhppote-cli set-door-control %s %s %s', escapeshellarg($door_info->uhppoted_device_id), escapeshellarg($door_info->door_number_on_controller), escapeshellarg($state_string));
        
        // This still uses shell_exec as we decided not to change it yet.
        $output = shell_exec($command . " 2>&1");

        if (strpos($output, 'ERROR') === false) {
            //  Nudge the original event_service on port 8083.
            wp_remote_post('https://127.0.0.1:8083/trigger-poll', [
                'timeout'   => 2,
                'sslverify' => false
            ]);
            return new WP_REST_Response(['status' => 'success', 'message' => 'Command sent.'], 200);
        } else {
            error_log("set-door-control failed: " . $output);
            return new WP_Error('command_failed', 'The command failed to execute.', ['status' => 500, 'output' => $output]);
        }
    }
    
    /**
     * Private helper to send notifications to monitor_service
     */
    private function send_notification_to_monitor($log_id){
        $port = 8082; // The new monitor_service port
        $monitor_url = sprintf('https://127.0.0.1:%d/notify', $port);
        $post_body = [ 'event_id' => $log_id ];
        
        $monitor_response = wp_remote_post($monitor_url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => json_encode($post_body),
            'timeout'   => 5,
            'sslverify' => false,
        ]);

        if( is_wp_error( $monitor_response ) ){
            error_log('MONITOR-NOTIFY-ERROR: Failed to notify monitor_service. Reason: ' . $monitor_response->get_error_message());
        } else {
            error_log('MONITOR-NOTIFY-SUCCESS: Successfully sent notification to monitor_service for event_id: ' . $log_id);
        }
    }

    /**
     * Private helper to retrieve the role/classification of a gate from the database.
     * Returns: amenity ID (int) or the system role string ('AFTER_HOURS_ACCESS', 'NO_AMENITY', etc.).
     */
    private function get_gate_classification($controller_id, $door_number) {
        global $wpdb;

        // Fetch the amenity_role string from the ac_doors table
        $role_string = $wpdb->get_var($wpdb->prepare("
            SELECT d.amenity_role
            FROM ac_doors d
            JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
            WHERE c.uhppoted_device_id = %s AND d.door_number_on_controller = %d
        ", $controller_id, $door_number));

        if (empty($role_string)) {
            return null;
        }
        
        // If it starts with 'AMENITY_', strip the prefix and return the ID (int)
        if (strpos($role_string, 'AMENITY_') === 0) {
            return absint(str_replace('AMENITY_', '', $role_string));
        }

        // Otherwise, return the system role string (e.g., 'AFTER_HOURS_ACCESS')
        return $role_string;
    }


    /**
     * Private helper to check if a cardholder is a member of a specific group.
     * Assumes $cardholder_id is already validated.
     */
    private function is_cardholder_in_group($cardholder_id, $group_name) {
        global $wpdb;
        $query = $wpdb->prepare("
            SELECT COUNT(chg.cardholder_id)
            FROM ac_cardholder_groups chg
            JOIN ac_groups g ON chg.group_id = g.group_id
            WHERE chg.cardholder_id = %d AND g.group_name = %s
        ", $cardholder_id, $group_name);

        return $wpdb->get_var($query) > 0;
    }


    /**
     * Looks up the cardholder and door information based on the raw event data.
     * Populates cardholder_id, resident_type, and door info in the log array.
     * @param array &$log_data The standardized log data array (passed by reference).
     */
    private function lookup_cardholder( &$log_data ) {
        global $wpdb;

        $rfid = $log_data['rfid_id'];
    
        // 1. Check for no RFID read (Controller returned 0, parsed as NULL in the API handler)
        if ( empty( $rfid ) ) {
            $log_data['cardholder_id'] = 0;
//            $log_data['resident_type'] = 'None';
            $log_data['access_granted'] = $log_data['access_granted'] ?? 0; // Default to denied
            $log_data['event_description'] = $log_data['event_description'] ?? 'No Card Read';
            return; // Cannot proceed without an RFID
        }

        // 2. Lookup Cardholder using the rfid_id
        // NOTE: This assumes rfid_id is the primary lookup key, or you use the new ac_rfid_tags lookup
        $cardholder = $wpdb->get_row( $wpdb->prepare( "
            SELECT id, resident_type
            FROM ac_cardholders
            WHERE rfid_id = %s", $rfid ) 
        );
    
        if ( $cardholder ) {
            $log_data['cardholder_id'] = absint( $cardholder->id );
  //          $log_data['resident_type'] = $cardholder->resident_type;
        } else {
            // Card is not found in the system
            $log_data['cardholder_id'] = 0;
 //           $log_data['resident_type'] = 'None';
            $log_data['access_granted'] = 0; // Ensure access is denied if card is unknown
            $log_data['event_description'] = 'Card not found';
        }
    }
}

