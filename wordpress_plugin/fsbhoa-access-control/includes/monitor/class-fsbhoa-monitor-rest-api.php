<?php
/**
 * Handles REST API endpoints for the Live Monitor component.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Monitor_REST_API {

    /**
     * The namespace for the REST API.
     * @var string
     */
    private $namespace = 'fsbhoa/v1';

    /**
     * Constructor. Hooks into WordPress.
     */
    public function __construct() {
       // Registration is now handled by the main plugin file.
    }

    /**
     * Registers the REST API routes for the monitor.
     */
    public function register_routes() {
        // This route is called by the Go service to enrich a specific event
        register_rest_route( $this->namespace, '/monitor/enrich-event', array(
            'methods'               => 'GET',
            'callback'              => array( $this, 'enrich_event_callback' ),
            'permission_callback'   => '__return_true',
            'args'                  => array(
                // Use our new class method for validation
                'card_number'   => array('required' => true, 'validate_callback' => array( $this, 'is_numeric_callback' )),
                'controller_sn' => array('required' => true, 'validate_callback' => array( $this, 'is_numeric_callback' )),
                'door_number'   => array('required' => true, 'validate_callback' => array( $this, 'is_numeric_callback' )),
            ),
        ) );

        // This route is called by the frontend JavaScript to get all gate data for the map
        register_rest_route( $this->namespace, '/monitor/gates', array(
            'methods'               => 'GET',
            'callback'              => array( $this, 'get_all_gates_callback' ),
            'permission_callback'   => '__return_true',
        ) );

        // This route is called by the Go service to log an event to the database
        register_rest_route( $this->namespace, '/monitor/log-event', array(
            'methods'               => 'POST',
            'callback'              => array( $this, 'log_event_callback' ),
            'permission_callback'   => '__return_true', // Internal service-to-service call
        ) );

        // This route is called by the monitor page to get recent historical events
        register_rest_route( $this->namespace, '/monitor/recent-activity', array(
            'methods'               => 'GET',
            'callback'              => array( $this, 'get_recent_activity_callback' ),
            'permission_callback'   => '__return_true',
        ) );

        // This route is called by the monitor page to manually set a door's state
        register_rest_route( $this->namespace, '/monitor/set-door-state', array(
            'methods'               => 'POST',
            'callback'              => array( $this, 'set_door_state_callback' ),
            'permission_callback'   => function () {
                return current_user_can('manage_options');
            },
        ) );
    }

    /**
     * A valid_callback function for the REST API that correctly handles arguments.
     *
     * @param mixed           $value   The value of the parameter.
     * @param WP_REST_Request $request The request object.
     * @param string          $param   The name of the parameter.
     * @return bool
     */
    public function is_numeric_callback( $value, $request, $param ) {
        return is_numeric( $value );
    }

    /**
     * AJAX callback to get all configured gates for the map display.
     *
     * @param WP_REST_Request $request The incoming API request.
     * @return WP_REST_Response|WP_Error A list of gates or an error object.
     */
    public function get_all_gates_callback( WP_REST_Request $request ) {
        global $wpdb;

        // Construct table names safely
        $doors_table =  'ac_doors';
        $controllers_table =  'ac_controllers';

        // Build the query string directly. No user input is used, so prepare() is not needed.
        // This is the corrected line that fixes the 500 error.
        $query = "
            SELECT
                d.door_record_id,
                d.friendly_name,
                d.door_number_on_controller,
                d.map_x,
                d.map_y,
                c.uhppoted_device_id
            FROM {$doors_table} d
            JOIN {$controllers_table} c ON d.controller_record_id = c.controller_record_id
        ";

        $gates = $wpdb->get_results( $query, ARRAY_A );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error fetching gates.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        if ( is_null( $gates ) ) {
            $gates = [];
        }

        return new WP_REST_Response( $gates, 200 );
    }


    /**
     * The callback function for the enrich-event REST API endpoint.
     *
     * @param WP_REST_Request $request The incoming API request.
     * @return WP_REST_Response|WP_Error The data to send back to the Go service or an error object.
     */
    public function enrich_event_callback( WP_REST_Request $request ) {
        error_log('--- enrich_event_callback triggered ---');
        global $wpdb;

        $card_number   = $request->get_param('card_number');
        $controller_sn = $request->get_param('controller_sn');
        $door_number   = $request->get_param('door_number');
        error_log(sprintf('Received params: card=%s, sn=%s, door=%s', $card_number, $controller_sn, $door_number));

        $response_data = array(
            'cardholderName' => 'Unknown Card',
            'photoURL'       => '',
            'gateName'       => 'Unknown Door',
            'doorRecordId'   => 0,
            'streetAddress'  => '',
        );

        // Find the Cardholder AND their photo
        $rfid_id = sprintf('%08d', $card_number);
        // Ensure we get an associative ARRAY
        $cardholder_query = $wpdb->prepare(
            "SELECT ch.id, ch.first_name, ch.last_name, ch.photo, p.street_address
             FROM ac_cardholders ch
             LEFT JOIN ac_property p ON ch.property_id = p.property_id
             WHERE ch.rfid_id = %s",
            $rfid_id
        );
        $cardholder = $wpdb->get_row( $cardholder_query, ARRAY_A );

        error_log('Cardholder query result: ' . print_r($cardholder, true));

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error fetching cardholder.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        // Use array syntax to access the data
        if ( $cardholder ) {
            $response_data['cardholderName'] = trim( $cardholder['first_name'] . ' ' . $cardholder['last_name'] );
            $response_data['streetAddress'] = $cardholder['street_address'] ?? 'N/A';

            // Check the 'photo' key in the array
            if ( !empty($cardholder['photo']) ) {
                $b64 = base64_encode($cardholder['photo']);
                $response_data['photoURL'] = 'data:image/jpeg;base64,' . $b64;
                error_log('DEBUG: Encoded photo. Base64 string length: ' . strlen($b64));
            }
        }

        // Find the Door/Gate Name
        if ($controller_sn == 0) {
            // This is a Kiosk event
            $response_data['gateName'] = get_option('fsbhoa_kiosk_name', 'Front Desk Kiosk');
            $gate_data = null; // No physical gate data
        } else {
            // This is a physical hardware event
            $gate_data_query = $wpdb->prepare(
                "SELECT d.friendly_name, d.door_record_id
                 FROM ac_doors d
                 JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
                 WHERE c.uhppoted_device_id = %d AND d.door_number_on_controller = %d",
                $controller_sn,
                $door_number
            );
            $gate_data = $wpdb->get_row( $gate_data_query ); // get_row returns an object by default which is fine here

            error_log('Gate data query result: ' . print_r($gate_data, true));
            error_log('--- enrich_event_callback finished ---');

            if ( $wpdb->last_error ) {
                return new WP_Error( 'db_error', 'Database error fetching gate name.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
            }
        }

        if ( $gate_data ) {
            $response_data['gateName'] = $gate_data->friendly_name;
            $response_data['doorRecordId'] = (int) $gate_data->door_record_id;
        }

        return new WP_REST_Response( $response_data, 200 );
    }


    /**
     * Callback to receive an event from the Go service and log it to the database.
     */
    public function log_event_callback( WP_REST_Request $request ) {
        error_log('--- log_event_callback triggered ---');
        global $wpdb;

        $params = $request->get_json_params();

        // Basic validation
        if ( !isset($params['SerialNumber']) || !isset($params['Door']) ) {
            return new WP_Error( 'bad_request', 'Missing required event parameters.', array( 'status' => 400 ) );
        }

        // Sanitize the incoming data
        $log_data = [
            'event_timestamp'       => current_time('mysql'), // Use local time
            'controller_identifier' => strval($params['SerialNumber']),
            'door_number'           => absint($params['Door']),
            'rfid_id'               => isset($params['CardNumber']) ? sprintf('%08d', absint($params['CardNumber'])) : null,
            'event_type_code'       => absint($params['Reason']),
            'event_description'     => sanitize_text_field($params['EventMessage']),
            'access_granted'        => isset($params['Granted']) ? ($params['Granted'] ? 1 : 0) : null,
        ];

        // Only try to find a cardholder if a card number was part of the event
        if ( !empty($log_data['rfid_id']) && $log_data['rfid_id'] !== '00000000' ) {
            $cardholder_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM ac_cardholders WHERE rfid_id = %s",
                $log_data['rfid_id']
            ));

            if ($cardholder_id) {
                $log_data['cardholder_id'] = $cardholder_id;
            }
        }

        $result = $wpdb->insert('ac_access_log', $log_data);

        if ($result === false) {
            error_log('FSBHOA DB Error logging access event: ' . $wpdb->last_error);
            return new WP_Error( 'db_error', 'Failed to insert event into access log.', array( 'status' => 500 ) );
        }

        return new WP_REST_Response( ['status' => 'success', 'message' => 'Event logged.'], 200 );
    }

    /**
     * Callback to get recent events from the access log to populate the monitor on load.
     */
    public function get_recent_activity_callback( WP_REST_Request $request ) {
        global $wpdb;

        $log_table = 'ac_access_log';
        $cardholders_table = 'ac_cardholders';
        $doors_table = 'ac_doors';
        $controllers_table = 'ac_controllers';
        $property_table = 'ac_property';

        //  query to get the cardholder's info
        $query = $wpdb->prepare(
            "SELECT
                l.event_timestamp,
                l.access_granted,
                l.event_description,
                l.rfid_id,
                ch.id as cardholder_id,
                ch.first_name,
                ch.last_name,
                ch.photo,
                d.friendly_name AS gate_name,
                d.door_record_id,
                p.street_address
            FROM {$log_table} AS l
            LEFT JOIN {$cardholders_table} AS ch ON l.cardholder_id = ch.id
            LEFT JOIN {$controllers_table} AS c ON l.controller_identifier = c.uhppoted_device_id
            LEFT JOIN {$doors_table} AS d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller
            LEFT JOIN {$property_table} AS p ON ch.property_id = p.property_id
            WHERE l.event_timestamp >= %s
            ORDER BY l.event_timestamp DESC
            LIMIT 50",
            date('Y-m-d H:i:s', strtotime('-24 hours'))
        );

        $results = $wpdb->get_results($query, ARRAY_A);

        if ($wpdb->last_error) {
            return new WP_Error('db_error', 'Database error fetching recent activity.', ['status' => 500, 'db_error' => $wpdb->last_error]);
        }

        $formatted_events = [];
        foreach ($results as $event) {
            $cardholder_name = trim($event['first_name'] . ' ' . $event['last_name']);
            if (empty($cardholder_name)) {
                $cardholder_name = 'Unknown Card (' . $event['rfid_id'] . ')';
            }

            // If photo data exists, base64 encode it and create a data URI
            $photo_url = '';
            if ( !empty($event['photo']) ) {
                $photo_url = 'data:image/jpeg;base64,' . base64_encode($event['photo']);
            }

            $formatted_events[] = [
                'eventType'      => $event['access_granted'] ? 'accessGranted' : 'accessDenied',
                'cardholderName' => $cardholder_name,
                'photoURL'       => $photo_url, // Use the new base64 data URI
                'gateName'       => $event['gate_name'] ?: 'Unknown Gate',
                'timestamp'      => date('g:i:s A', strtotime($event['event_timestamp'])),
                'eventMessage'   => $event['event_description'],
                'cardNumber'     => (int)ltrim($event['rfid_id'], '0'),
                'doorRecordId'   => (int)$event['door_record_id'],
                'streetAddress'  => $event['street_address'] ?? 'N/A',
            ];
        }

        return new WP_REST_Response($formatted_events, 200);
    }

    /**
     * Callback to manually set the control state of a single door.
     */
    public function set_door_state_callback( WP_REST_Request $request ) {
        global $wpdb;

        $params = $request->get_json_params();
        $door_id = isset($params['door_id']) ? absint($params['door_id']) : 0;
        $state_code = isset($params['state']) ? absint($params['state']) : 0;

        error_log("SET DOOR STATE: Received door_id: {$door_id}, state_code: {$state_code}");

        if (empty($door_id) || !in_array($state_code, [1, 2, 3])) {
            return new WP_Error('bad_request', 'Invalid door ID or state provided.', ['status' => 400]);
        }

        // Translate our state code to the string the CLI tool expects
        $state_map = [
            1 => 'controlled',
            2 => 'normally open',
            3 => 'normally closed',
        ];
        $state_string = $state_map[$state_code];

        // Get the controller SN and physical door number from the database
        $door_info = $wpdb->get_row($wpdb->prepare(
            "SELECT c.uhppoted_device_id, d.door_number_on_controller
             FROM ac_doors d
             JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id
             WHERE d.door_record_id = %d",
            $door_id
        ));

        if (!$door_info) {
            return new WP_Error('not_found', 'Could not find door details in database.', ['status' => 404]);
        }
        error_log("SET DOOR STATE: DB query result for door_id {$door_id}: " . print_r($door_info, true));

        // Build and execute the command
        $command = sprintf(
            'uhppote-cli set-door-control %s %s %s',
            escapeshellarg($door_info->uhppoted_device_id),
            escapeshellarg($door_info->door_number_on_controller),
            escapeshellarg($state_string)
        );

        // Execute the command and capture any output/errors
        $output = shell_exec($command . " 2>&1");
        
        // The event_service poller will automatically pick up the change and update the UI.
        // We just need to report success or failure back to the click handler.
        if (strpos($output, 'ERROR') === false) {
           
            // Nudge the event service to poll immediately for a fast UI update
            $nudge_response = wp_remote_post('https://127.0.0.1:8083/trigger-poll', [
                'timeout'   => 2,
                'sslverify' => false // Required to accept the self-signed cert from the Go service
            ]);

            return new WP_REST_Response(['status' => 'success', 'message' => 'Command sent to controller.'], 200);
        } else {
            error_log("set-door-control failed: " . $output);
            return new WP_Error('command_failed', 'The command failed to execute.', ['status' => 500, 'output' => $output]);
        }
    }

}
