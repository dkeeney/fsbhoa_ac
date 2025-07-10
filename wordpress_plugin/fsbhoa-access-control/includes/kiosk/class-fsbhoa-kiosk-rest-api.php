<?php
/**
 * Handles Kiosk-specific REST API endpoints.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Kiosk_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        register_rest_route( $this->namespace, '/kiosk/config', array( // Renamed for clarity
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_kiosk_config_callback' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( $this->namespace, '/kiosk/log-signin', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'log_signin_callback' ),
            'permission_callback' => '__return_true', // In production, this should be secured with an API key
        ) );

        register_rest_route( $this->namespace, '/kiosk/validate-card/(?P<rfid>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'validate_card_callback' ),
            'permission_callback' => '__return_true', // Should be secured with an API key
        ) );
    }

    /**
     * Returns the kiosk configuration: logo URL and active amenities.
     */
    public function get_kiosk_config_callback( WP_REST_Request $request ) {
        global $wpdb;
        $table_name = 'ac_amenities';

        $amenities = $wpdb->get_results(
            "SELECT name, image_url FROM {$table_name} WHERE is_active = 1 ORDER BY display_order ASC, name ASC"
        );
        if ($wpdb->last_error) { return new WP_Error( 'db_error', 'Database error getting amenities.', ['status' => 500] ); }

        $response_data = [
            'logo_url' => get_option('fsbhoa_kiosk_logo_url', ''), // Get logo from WP options
            'amenities' => $amenities,
        ];

        return new WP_REST_Response( $response_data, 200 );
    }

    public function log_signin_callback( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        $rfid = isset($params['rfid']) ? sanitize_text_field($params['rfid']) : '';
        $amenity_name = isset($params['amenity']) ? sanitize_text_field($params['amenity']) : '';

        if (empty($rfid) || empty($amenity_name)) {
            return new WP_Error( 'bad_request', 'Missing rfid or amenity name.', ['status' => 400] );
        }

        $cardholder_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM ac_cardholders WHERE rfid_id = %s", $rfid));
        if ($wpdb->last_error) {
            return new WP_Error( 'db_error', 'Database error finding cardholder.', ['status' => 500, 'db_error' => $wpdb->last_error] );
        }

        $log_data = [
            'event_timestamp'       => current_time('mysql'),  // local time
            'controller_identifier' => 'kiosk',
            'door_number'           => 0,
            'rfid_id'               => $rfid,
            'cardholder_id'         => $cardholder_id ? (int)$cardholder_id : null,
            'event_type_code'       => 100,
            'event_description'     => 'Amenity Sign-in: ' . $amenity_name,
            'access_granted'        => 1,
        ];

        $result = $wpdb->insert('ac_access_log', $log_data);
        if ($result === false) {
            return new WP_Error( 'db_error', 'Failed to insert sign-in into access log.', ['status' => 500, 'db_error' => $wpdb->last_error] );
        }

        // After successfully logging, notify the event_service to broadcast the event
        $port = get_option('fsbhoa_ac_websocket_port', 8083);
        $event_service_url = sprintf('https://127.0.0.1:%d/inject-event', $port);
        $post_body = [
            'rfid'              => $rfid,
            'event_description' => 'Amenity Sign-in: ' . $amenity_name,
            'granted'           => true,
        ];

        $injection_response = wp_remote_post($event_service_url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => json_encode($post_body),
            'sslverify' => false,
            'timeout'   => 5,
        ]);

        if( is_wp_error( $injection_response)){
            error_log('KIOSK-INJECT-ERROR: Failed to inject event into event_service. Reason:' . $injection_response->get_error_message());
        } else {
            error_log('KIOSK-INJECT-SUCCESS: Successfully sent event injection request to event_service.');
        }


        return new WP_REST_Response( ['status' => 'success', 'message' => 'Sign-in logged.'], 200 );
    }


    public function validate_card_callback( WP_REST_Request $request ) {
        global $wpdb;
        $rfid = sanitize_text_field($request['rfid']);

        $cardholder = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name, photo, card_status, card_expiry_date FROM ac_cardholders WHERE rfid_id = %s",
            $rfid
        ));
        if ($wpdb->last_error) { return new WP_Error('db_error', 'Database error validating card.', ['status' => 500]); } 

        $is_valid = true;
        $message = 'Card is valid.';

        if (!$cardholder) {
            $is_valid = false;
            $message = 'Card not found.';
        } elseif ($cardholder->card_status !== 'active') {
            $is_valid = false;
            $message = 'Card is not active.';
        } elseif (strtotime($cardholder->card_expiry_date) < time()) {
            $is_valid = false;
            $message = 'Card has expired.';
        }

        // Always log the event, whether it's a success or failure
        $this->_log_kiosk_event($rfid, 'Kiosk Validation: ' . $message, $is_valid);

        if ($is_valid) {
            $cardholder_data = [
                'name'  => trim($cardholder->first_name . ' ' . $cardholder->last_name),
                'photo' => !empty($cardholder->photo) ? base64_encode($cardholder->photo) : null,
            ];
            $response = ['isValid' => true, 'message' => $message, 'cardholder' => $cardholder_data];
        } else {
            // For invalid cards, also notify the event_service so it appears on the live monitor
            $port = get_option('fsbhoa_ac_websocket_port', 8083);
            $event_service_url = sprintf('https://127.0.0.1:%d/inject-event', $port);
            wp_remote_post($event_service_url, [
                'body'      => json_encode(['rfid' => $rfid, 'granted' => false, 'event_description' => 'Kiosk Failure: ' . $message, 'amenity' => '']),
                'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
                'sslverify' => false,
                'timeout'   => 5,
            ]);
            $response = ['isValid' => false, 'message' => $message];
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Private helper to log kiosk events.
     */
    private function _log_kiosk_event($rfid, $description, $is_granted) {
        global $wpdb;

        $cardholder_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM ac_cardholders WHERE rfid_id = %s", $rfid));

        $log_data = [
            'event_timestamp'       => current_time('mysql', 1),
            'controller_identifier' => 'kiosk',
            'door_number'           => 0,
            'rfid_id'               => $rfid,
            'cardholder_id'         => $cardholder_id ? (int)$cardholder_id : null,
            'event_type_code'       => $is_granted ? 100 : 101, // 100=Success, 101=Failure
            'event_description'     => $description,
            'access_granted'        => $is_granted ? 1 : 0,
        ];

        $wpdb->insert('ac_access_log', $log_data);
    }
}
