<?php
/**
 * Handles REST API endpoints for the Live Monitor component.
 */

// If this file is called directly, abort.
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
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Registers the REST API routes for the monitor.
     */
    public function register_routes() {
        register_rest_route( $this->namespace, '/monitor/enrich-event', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'enrich_event_callback' ),
            'permission_callback' => '__return_true', // For internal services.
            'args'                => array(
                'card_number'   => array('required' => true, 'validate_callback' => 'is_numeric'),
                'controller_sn' => array('required' => true, 'validate_callback' => 'is_numeric'),
                'door_number'   => array('required' => true, 'validate_callback' => 'is_numeric'),
            ),
        ) );
    }

    /**
     * The callback function for the REST API endpoint.
     *
     * @param WP_REST_Request $request The incoming API request.
     * @return WP_REST_Response|WP_Error The data to send back to the Go service or an error object.
     */
    public function enrich_event_callback( WP_REST_Request $request ) {
        global $wpdb;

        $card_number   = $request->get_param('card_number');
        $controller_sn = $request->get_param('controller_sn');
        $door_number   = $request->get_param('door_number');

        $response_data = array(
            'cardholderName' => 'Unknown Card',
            'photoURL'       => '',
            'gateName'       => 'Unknown Door',
        );

        // Find the Cardholder
        $rfid_id = sprintf('%08d', $card_number);
        $cardholder_table = $wpdb->prefix . 'ac_cardholders';
        $cardholder_query = $wpdb->prepare( "SELECT id, first_name, last_name FROM {$cardholder_table} WHERE rfid_id = %s", $rfid_id );
        $cardholder = $wpdb->get_row( $cardholder_query, ARRAY_A );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error fetching cardholder.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        if ( $cardholder ) {
            $response_data['cardholderName'] = trim( $cardholder['first_name'] . ' ' . $cardholder['last_name'] );
            $response_data['photoURL'] = add_query_arg( array(
                'action' => 'fsbhoa_get_cardholder_photo',
                'id'     => $cardholder['id'],
            ), admin_url( 'admin-ajax.php' ) );
        }

        // Find the Door/Gate Name
        $controllers_table = $wpdb->prefix . 'ac_controllers';
        $doors_table       = $wpdb->prefix . 'ac_doors';
        $gate_name_query = $wpdb->prepare(
            "SELECT d.friendly_name FROM {$doors_table} d
             JOIN {$controllers_table} c ON d.controller_record_id = c.controller_record_id
             WHERE c.uhppoted_device_id = %d AND d.door_number_on_controller = %d",
            $controller_sn,
            $door_number
        );
        $gate_name = $wpdb->get_var( $gate_name_query );

        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error fetching gate name.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        if ( $gate_name ) {
            $response_data['gateName'] = $gate_name;
        }

        return new WP_REST_Response( $response_data, 200 );
    }
}
