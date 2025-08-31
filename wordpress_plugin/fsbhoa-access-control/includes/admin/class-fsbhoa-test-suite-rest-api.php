<?php
/**
 * Handles REST API endpoints used only by the test suite.
 */

if (!defined('WPINC')) { die; }

class Fsbhoa_Test_Suite_REST_API {

    private $namespace = 'fsbhoa/v1';

    public function register_routes() {
        register_rest_route($this->namespace, '/test/find-event', [
            'methods'             => 'POST',
            'callback'            => [$this, 'find_event_callback'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Finds a specific event in the access log. Used by the test suite for polling.
     */
    public function find_event_callback(WP_REST_Request $request) {
        global $wpdb;
        $params = $request->get_json_params();
        $rfid = isset($params['rfid']) ? sanitize_text_field($params['rfid']) : '';

        if (empty($rfid)) {
            return new WP_Error('bad_request', 'RFID parameter is required.', ['status' => 400]);
        }

        $table = 'ac_access_log';
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rfid_id = %s AND event_timestamp > NOW() - INTERVAL 30 SECOND",
            $rfid
        );
        $event_count = $wpdb->get_var($query);

        if ($event_count > 0) {
            return new WP_REST_Response(['status' => 'found', 'count' => $event_count], 200);
        } else {
            return new WP_REST_Response(['status' => 'not_found'], 404);
        }
    }
}

