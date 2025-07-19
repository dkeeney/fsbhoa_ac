<?php
/**
 * Handles REST API endpoints for the Reports component.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Reports_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        register_rest_route( $this->namespace, '/reports/access-log', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'get_access_log_callback' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );

        register_rest_route( $this->namespace, '/reports/usage-analytics', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_usage_analytics_callback' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );
    }

    public function get_access_log_callback( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_params();

        $draw    = isset( $params['draw'] ) ? absint( $params['draw'] ) : 1;
        $start   = isset( $params['start'] ) ? absint( $params['start'] ) : 0;
        $length  = isset( $params['length'] ) ? absint( $params['length'] ) : 100;
        $search  = isset( $params['search']['value'] ) ? sanitize_text_field( $params['search']['value'] ) : '';
        $order_col_index = isset( $params['order'][0]['column'] ) ? absint( $params['order'][0]['column'] ) : 0;
        $order_dir = isset( $params['order'][0]['dir'] ) && strtolower($params['order'][0]['dir']) === 'asc' ? 'ASC' : 'DESC';
        $start_date = isset($params['start_date']) ? sanitize_text_field($params['start_date']) : '';
        $end_date   = isset($params['end_date']) ? sanitize_text_field($params['end_date']) : '';
        $gate_id    = isset($params['gate_id']) ? absint($params['gate_id']) : 0;
        $show_photo = isset($params['show_photo']) && $params['show_photo'] === 'true';

        $base_query = " FROM ac_access_log l LEFT JOIN ac_cardholders ch ON l.cardholder_id = ch.id LEFT JOIN ac_property p ON ch.property_id = p.property_id LEFT JOIN ac_controllers c ON l.controller_identifier = c.uhppoted_device_id LEFT JOIN ac_doors d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller ";
        $where_clauses = [];
        if ( ! empty($start_date) ) { $where_clauses[] = $wpdb->prepare( "l.event_timestamp >= %s", date( 'Y-m-d 00:00:00', strtotime( $start_date ) ) ); }
        if ( ! empty($end_date) ) {
            $end_date_obj = new DateTime($end_date);
            $end_date_obj->modify('+1 day');
            $next_day_start = $end_date_obj->format('Y-m-d 00:00:00');
            $where_clauses[] = $wpdb->prepare( "l.event_timestamp < %s", $next_day_start );
        }
        if ( ! empty($gate_id) ) { $where_clauses[] = $wpdb->prepare( "d.door_record_id = %d", $gate_id ); }
        if ( ! empty($search) ) { $search_term = '%' . $wpdb->esc_like( $search ) . '%'; $where_clauses[] = $wpdb->prepare( "(CONCAT(ch.first_name, ' ', ch.last_name) LIKE %s OR p.street_address LIKE %s OR d.friendly_name LIKE %s OR l.event_description LIKE %s OR l.rfid_id LIKE %s)", $search_term, $search_term, $search_term, $search_term, $search_term ); }
        $where_sql = ! empty( $where_clauses ) ? " WHERE " . implode( ' AND ', $where_clauses ) : '';

        $records_total = $wpdb->get_var( "SELECT COUNT(l.log_id) {$base_query}" );
        if ( $wpdb->last_error ) { return new WP_Error( 'db_error', 'Database error counting total records.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) ); }

        $records_filtered = $wpdb->get_var( "SELECT COUNT(l.log_id) {$base_query} {$where_sql}" );
        if ( $wpdb->last_error ) { return new WP_Error( 'db_error', 'Database error counting filtered records.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) ); }
        
        $columns = [ 'l.event_timestamp', 'l.event_timestamp', "CONCAT(ch.first_name, ' ', ch.last_name)", 'ch.resident_type', 'p.street_address', 'd.friendly_name', 'l.access_granted', 'l.event_description' ];
        $order_by_col = $columns[$order_col_index] ?? $columns[0];

        $data_query = " SELECT ch.photo, l.event_timestamp, CONCAT(ch.first_name, ' ', ch.last_name) as cardholder, ch.resident_type, p.street_address as property, d.friendly_name as gate_name, l.access_granted, l.event_description {$base_query} {$where_sql} ORDER BY {$order_by_col} {$order_dir} LIMIT %d OFFSET %d ";
        $results = $wpdb->get_results( $wpdb->prepare( $data_query, $length, $start ), ARRAY_A );
        if ( $wpdb->last_error ) { return new WP_Error( 'db_error', 'Database error fetching report data.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) ); }
        
        $data = [];
        foreach ( $results as $row ) {
            $row['event_timestamp'] = date('Y-m-d H:i:s', strtotime($row['event_timestamp']));
            $row['photo'] = $show_photo && !empty($row['photo']) ? base64_encode($row['photo']) : null;
            $granted = $row['access_granted'];
            $row['access_granted'] = is_null($granted) ? '—' : ($granted ? '<span class="access-granted">Granted</span>' : '<span class="access-denied">Denied</span>');
            $resident_type = $row['resident_type'];
            if ( $resident_type === 'Resident Owner' ) { $row['resident_type'] = 'O'; } elseif ( !empty($resident_type) ) { $row['resident_type'] = strtoupper(substr($resident_type, 0, 1)); } else { $row['resident_type'] = ''; }
            $row['cardholder'] = $row['cardholder'] ? esc_html($row['cardholder']) : '<em>Event/No Card</em>';
            $row['gate_name'] = $row['gate_name'] ? esc_html($row['gate_name']) : '<em>Unknown Gate</em>';
            $row['property'] = $row['property'] ? esc_html($row['property']) : '';
            $data[] = $row;
        }

        $response = [ 'draw' => $draw, 'recordsTotal' => $records_total, 'recordsFiltered' => $records_filtered, 'data' => $data ];
        return new WP_REST_Response( $response, 200 );
    }

    public function get_usage_analytics_callback( WP_REST_Request $request ) {
        global $wpdb;

        $year = $request->get_param('year') ? absint($request->get_param('year')) : date('Y');
        $month = $request->get_param('month') ? absint($request->get_param('month')) : date('m');

        $response_data = [
            'gateUsage' => [],
            'hourlyUsage' => array_fill(0, 24, 0)
        ];

        $gate_usage_query = $wpdb->prepare(
            "SELECT COALESCE(d.friendly_name, 'Unknown Gate') as friendly_name, COUNT(l.log_id) as count
            FROM ac_access_log l
            LEFT JOIN ac_controllers c ON l.controller_identifier = c.uhppoted_device_id
            LEFT JOIN ac_doors d ON c.controller_record_id = d.controller_record_id AND l.door_number = d.door_number_on_controller
            WHERE l.access_granted = 1 AND l.event_description LIKE 'Card swipe%%' AND YEAR(l.event_timestamp) = %d AND MONTH(l.event_timestamp) = %d
            GROUP BY COALESCE(d.friendly_name, 'Unknown Gate')
            ORDER BY COUNT(l.log_id) DESC",
            $year,
            $month
        );
        $gate_results = $wpdb->get_results($gate_usage_query);
// --- TEMPORARY DEBUGGING to error_log ---
        error_log('--- FSBHOA Analytics Debug ---');
        error_log('Gate Usage SQL: ' . $gate_usage_query);
        error_log('Gate Usage Result: ' . print_r($gate_results, true));
        error_log('WPDB Error: ' . $wpdb->last_error);
// --- END DEBUGGING ---
        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error getting gate usage.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }
        $response_data['gateUsage'] = $gate_results;
        
        $hourly_usage_query = $wpdb->prepare(
            "SELECT HOUR(l.event_timestamp) as hour, COUNT(l.log_id) as count
            FROM ac_access_log l
            WHERE l.access_granted = 1 AND l.event_description LIKE 'Card swipe%%' AND YEAR(l.event_timestamp) = %d AND MONTH(l.event_timestamp) = %d
            GROUP BY HOUR(l.event_timestamp)",
            $year,
            $month
        );
        $hourly_results = $wpdb->get_results($hourly_usage_query);
        if ( $wpdb->last_error ) {
            return new WP_Error( 'db_error', 'Database error getting hourly usage.', array( 'status' => 500, 'db_error' => $wpdb->last_error ) );
        }

        foreach ($hourly_results as $result) {
            $response_data['hourlyUsage'][$result->hour] = (int)$result->count;
        }

        return new WP_REST_Response($response_data, 200);
    }
}

