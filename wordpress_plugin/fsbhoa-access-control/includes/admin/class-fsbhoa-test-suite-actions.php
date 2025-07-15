<?php
// includes/admin/class-fsbhoa-test-suite-actions.php

if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Test_Suite_Actions {

    public function __construct() {
        add_action('wp_ajax_fsbhoa_run_regression_test', array($this, 'run_test_step'));
    }

    public function run_test_step() {
        check_ajax_referer('fsbhoa_test_suite_nonce', 'nonce');

        $step = sanitize_text_field($_POST['test_step']);

        switch ($step) {
            case 'run_hardware_test':
                $this->run_hardware_test();
                break;
            case 'verify_hardware_test':
                $this->verify_database_event('11111111', 'Hardware event logged to DB');
                break;
            case 'run_kiosk_test':
                $this->run_kiosk_test();
                break;
            case 'verify_kiosk_test':
                $this->verify_database_event('22222222', 'Kiosk sign-in logged to DB');
                break;
            default:
                wp_send_json_error('Invalid test step.');
        }
    }

    private function run_hardware_test() {
        global $wpdb;
        $controllers_table = 'ac_controllers';

        // Fetch the serial number of the first controller from the database
        $serial_number = $wpdb->get_var("SELECT uhppoted_device_id FROM $controllers_table ORDER BY controller_record_id ASC LIMIT 1");

        if (empty($serial_number)) {
            wp_send_json_error('Test failed: No controllers found in the database.');
            return;
        }

        // This simulates a hardware event by calling the event_service
        $url = 'https://127.0.0.1:8083/test_event';
        $body = [
            'card_number'   => 11111111,
            'serial_number' => (int) $serial_number // Pass the dynamic serial number
        ];
        $args = [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'      => json_encode($body),
            'sslverify' => false,
            'timeout'   => 5
        ];

        $response = wp_remote_post($url, $args);
    
        if (is_wp_error($response)) {
            wp_send_json_error('Failed to contact event_service: ' . $response->get_error_message());
        }
        wp_send_json_success("Test hardware event triggered for controller SN {$serial_number}.");
    }


    private function run_kiosk_test() {
        // This simulates the kiosk app logging a sign-in
        $url = get_site_url() . '/wp-json/fsbhoa/v1/kiosk/log-signin';
        $body = ['rfid' => '22222222', 'amenity' => 'Test Amenity'];
        $response = wp_remote_post($url, ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error('Failed to trigger kiosk sign-in.');
        }
        wp_send_json_success('Test kiosk sign-in triggered.');
    }

    private function verify_database_event($rfid, $success_message) {
        global $wpdb;
        $table = 'ac_access_log';
        // Look for an event in the last 15 seconds
        $event = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE rfid_id = %s AND event_timestamp > NOW() - INTERVAL 15 SECOND",
            $rfid
        ));

        if ($event > 0) {
            wp_send_json_success($success_message);
        } else {
            wp_send_json_error("Verification failed: Event for RFID {$rfid} not found in database.");
        }
    }
}
