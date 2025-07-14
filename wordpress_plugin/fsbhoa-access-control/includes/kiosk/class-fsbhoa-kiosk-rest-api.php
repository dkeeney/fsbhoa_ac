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
        register_rest_route( $this->namespace, '/kiosk/config', array(
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
            'logo_url' => get_option('fsbhoa_kiosk_logo_url', ''),
            'amenities' => $amenities,
        ];

        return new WP_REST_Response( $response_data, 200 );
    }

    /**
     * This is called when kiosk has collected an amenity for a valid cardholder.
     */
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
            'event_timestamp'       => current_time('mysql'),
            'controller_identifier' => 'kiosk',
            'door_number'           => 0,
            'rfid_id'               => $rfid,
            'cardholder_id'         => $cardholder_id ? (int)$cardholder_id : null,
            'event_type_code'       => 100, // Kiosk Sign-in Success
            'event_description'     => 'Amenity: ' . $amenity_name,
            'access_granted'        => 1,
        ];

        // CORRECTED: Use insert_id to get the new record ID.
        $wpdb->insert('ac_access_log', $log_data);
        $record_id = $wpdb->insert_id;

        if ($record_id === 0) { // Check if insert failed
            return new WP_Error( 'db_error', 'Failed to insert kiosk sign-in into access log.', ['status' => 500, 'db_error' => $wpdb->last_error] );
        }

        // After successfully logging, notify the monitor to display the event
        $this->send_notification_to_monitor($record_id);

        return new WP_REST_Response( ['status' => 'success', 'message' => 'Sign-in logged.'], 200 );
    }


    /**
     * This is called when kiosk has had a card swipe.
     * The card id should be checked against the database and confirm this cardholder is valid.
     */
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

        // CORRECTED LOGIC:
        if ($is_valid) {
            // If the card is valid, send the cardholder data to the kiosk UI
            $cardholder_data = [
                'name'  => trim($cardholder->first_name . ' ' . $cardholder->last_name),
                'photo' => !empty($cardholder->photo) ? base64_encode($cardholder->photo) : null,
            ];
            $response = ['isValid' => true, 'message' => $message, 'cardholder' => $cardholder_data];
        } else {
            // If the card is NOT valid, log the failure and notify the monitor
            $log_id = $this->_log_kiosk_event($rfid, 'Kiosk Validation: ' . $message, false);
            if ($log_id > 0) {
                 $this->send_notification_to_monitor($log_id);
            }
            $response = ['isValid' => false, 'message' => $message];
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * Private helper to send notifications to monitor
     */
    private function send_notification_to_monitor($log_id){
        // Port for the new monitor_service
        $port = 8082; 
        $monitor_url = sprintf('https://127.0.0.1:%d/notify', $port);
        $post_body = [
            'event_id' => $log_id,
        ];
        
        // Note: Using http, so sslverify is not needed.
        $monitor_response = wp_remote_post($monitor_url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => json_encode($post_body),
            'timeout'   => 5,
            'sslverify' => false,
        ]);

        if( is_wp_error( $monitor_response ) ){
            error_log('KIOSK-NOTIFY-ERROR: Failed to notify monitor_service. Reason: ' . $monitor_response->get_error_message());
        } else {
            error_log('KIOSK-NOTIFY-SUCCESS: Successfully sent notification to monitor_service for event_id: ' . $log_id);
        }
    }

    /**
     * Private helper to log kiosk events.
     */
    private function _log_kiosk_event($rfid, $description, $is_granted) {
        global $wpdb;

        $cardholder_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM ac_cardholders WHERE rfid_id = %s", $rfid));

        $log_data = [
            'event_timestamp'       => current_time('mysql'),
            'controller_identifier' => 'kiosk',
            'door_number'           => 0,
            'rfid_id'               => $rfid,
            'cardholder_id'         => $cardholder_id ? (int)$cardholder_id : null,
            'event_type_code'       => $is_granted ? 100 : 101, // 100=Success, 101=Failure
            'event_description'     => $description,
            'access_granted'        => $is_granted ? 1 : 0,
        ];

        // CORRECTED: Use insert_id to get the new record ID.
        $wpdb->insert('ac_access_log', $log_data);
        return $wpdb->insert_id;
    }
}

