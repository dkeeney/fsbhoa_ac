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

        // This route is called by the monitor page to check if the status group (Residents) has access now
        register_rest_route( $this->namespace, '/monitor/group-status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_group_status_callback' ),
            'permission_callback' => '__return_true', // Public read-only status
        ) );
    }

    public function is_numeric_callback( $value, $request, $param ) {
        return is_numeric( $value );
    }

    public function get_all_gates_callback( WP_REST_Request $request ) {
        global $wpdb;
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';
        $role_filter = $request->get_param('role');
        
        $query = "
            SELECT d.door_record_id, d.friendly_name, d.door_number_on_controller, d.map_x, d.map_y, c.uhppoted_device_id
            FROM {$doors_table} d
            JOIN {$controllers_table} c ON d.controller_record_id = c.controller_record_id
        ";

        // Apply Filter if present
        if ( !empty($role_filter) ) {
            // Use prepare to sanitize the role string
            $query = $wpdb->prepare($query . " WHERE d.door_role = %s", $role_filter);
        }

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

        // Process the event and write to the access log via the centralized service.
        $result = Fsbhoa_Access_Service::process_and_write_event( $log_data );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            $message = $result->get_error_message();
        
            if ( $error_code === 'rate_limit' ) {
                // SCENARIO: Rate Limited. Handled successfully by discarding. Return 200 OK.
                error_log("FSBHOA EVENT INFO: Event discarded due to rate limit.");
                return new WP_REST_Response( ['status' => 'success', 'message' => 'Event discarded (rate limited).'], 200 );
            }
        
            // SCENARIO: Actual Database Failure or other fatal error.
            error_log("FSBHOA EVENT CRITICAL: Failed to log event. Error: " . $message);
            $status = $error_code === 'db_error' ? 500 : 400;
            return new WP_REST_Response( ['status' => 'failure', 'message' => $message], $status );
        }

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
            'logId'          => (int)$log_id,
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
                'logId'          => (int)$event['log_id'],
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
            SELECT l.log_id, l.event_timestamp, l.access_granted, l.event_description, l.rfid_id, l.controller_identifier, ch.first_name, ch.last_name, ch.photo, d.friendly_name AS gate_name, d.door_record_id, p.street_address
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
    

    public function get_group_status_callback(WP_REST_Request $request) {
        // 1. Load Cache
        $cache = get_option('fsbhoa_monitor_daily_cache', []);
        $cache_date = get_option('fsbhoa_monitor_cache_date', '');
        
        // Safety: If cache is stale or missing (e.g., missed cron), rebuild it now.
        if (empty($cache) || $cache_date !== current_time('Y-m-d')) {
            require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-permission-functions.php';
            fsbhoa_rebuild_monitor_status_cache();
            $cache = get_option('fsbhoa_monitor_daily_cache', []);
        }

        if (empty($cache)) {
            return rest_ensure_response([]); // No config or no doors
        }

        // 2. Compare against Current Time
        $now = current_time('H:i'); // e.g. '14:30'
        $status_map = [];

        foreach ($cache as $door_id => $rule) {
            if ($rule === 'ALWAYS') {
                $status_map[$door_id] = true;
                continue;
            }

            if ($rule === null) {
                $status_map[$door_id] = false;
                continue;
            }

            // Time comparison
            $start = $rule['start'];
            $end   = $rule['end'];

            if ($end <= $start) {
                // Overnight rule (e.g. 22:00 to 05:00)
                // Open if NOW > Start OR NOW < End
                $status_map[$door_id] = ($now >= $start || $now <= $end);
            } else {
                // Normal rule (e.g. 08:00 to 20:00)
                $status_map[$door_id] = ($now >= $start && $now <= $end);
            }
        }

        return rest_ensure_response($status_map);
    }


} // end of class

