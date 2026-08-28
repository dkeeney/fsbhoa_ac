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
            case 'run_import_test':
                $this->run_import_test();
                break;
            case 'verify_import_test':
                $this->verify_import_test();
                break;
            default:
                wp_send_json_error('Invalid test step.');
        }
    }

    private function run_hardware_test() {
        // Broadcast the test intent to hardware plugins
        $result = apply_filters('fsbhoa_simulate_hardware_event', false);

        if ( is_wp_error($result) ) {
            wp_send_json_error('Test failed: ' . $result->get_error_message());
        } elseif ($result !== false) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error('Test failed: No hardware plugin configured to run simulated events.');
        }
    }

    private function verify_database_event($rfid, $success_message) {
        global $wpdb;
        $table = 'ac_access_log';
        
        // Add a delay to ensure the event has time to be processed
        sleep(2);

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE rfid_id = %s AND event_timestamp > NOW() - INTERVAL 15 SECOND",
            $rfid
        );
        
        // --- DEBUGGING LOGS START ---
        error_log("--- Verifying Database Event ---");
        error_log("Running Query: " . $query);
        $event_count = $wpdb->get_var($query);
        error_log("Query Result (COUNT): " . $event_count);
        // --- DEBUGGING LOGS END ---

        if ($event_count > 0) {
            wp_send_json_success($success_message);
        } else {
            wp_send_json_error("Verification failed: Event for RFID {$rfid} not found in database.");
        }
    }

    private function run_kiosk_test() {
        // 1. Get the configured API key from WordPress options.
        $api_key = get_option('fsbhoa_ac_kiosk_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('Test failed: Kiosk API Key is not configured in the settings.');
            return;
        }

        // 2. Prepare the request, including the correct 'X-API-KEY' header.
        $url = get_site_url() . '/wp-json/fsbhoa/v1/kiosk/log-signin';
        $body = ['rfid' => '22222222', 'amenity' => 'Test Amenity', 'guests' => 0];
        $args = [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY'    => $api_key, // <-- This is the corrected header
            ],
            'body'      => json_encode($body),
            'timeout'   => 5,
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to trigger kiosk sign-in: ' . $response->get_error_message());
        } elseif (wp_remote_retrieve_response_code($response) !== 200) {
            $error_body = wp_remote_retrieve_body($response);
            wp_send_json_error('Failed to trigger kiosk sign-in. Server responded with: ' . $error_body);
        } else {
            wp_send_json_success('Test kiosk sign-in triggered.');
        }
    }


    private function run_import_test() {
      try {
        $api_key = get_option('fsbhoa_ac_api_key', '');
        if (empty($api_key)) {
            wp_send_json_error('Test failed: Import API Key is not configured.');
            return;
        }

        // 1. Create a dummy CSV file in a temporary location
        $csv_header = "Property Address,First Name,Last Name\n";
        $csv_row = "\"9999 Test Way Bakersfield, CA 93306\",Test,Import\n";
        $csv_data = $csv_header . $csv_row;
        $tmp_file_path = get_temp_dir() . 'test_import_' . time() . '.csv';
        
        if (file_put_contents($tmp_file_path, $csv_data) === false) {
            wp_send_json_error('Test failed: Could not write temporary CSV file.');
            return;
        }

        // 2. Prepare the JSON payload with the file path and dry_run flag
        $url = get_site_url() . '/wp-json/fsbhoa/v1/import/run';
        $body = [
            'file_path' => $tmp_file_path,
            'dry_run'   => true // IMPORTANT: We are running this test in dry run mode
        ];
        $args = [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-KEY'    => $api_key,
            ],
            'body'    => json_encode($body),
            'timeout' => 15,
        ];

        // 3. Make the API call
        $response = wp_remote_post($url, $args);

        // 4. Clean up the temporary file
        unlink($tmp_file_path);

        // 5. Store the response for the verification step
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        update_option('fsbhoa_last_test_response', ['code' => $response_code, 'body' => $response_body]);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to contact Import service: ' . $response->get_error_message());
        } elseif ($response_code !== 200) {
            wp_send_json_error('Import service returned an error: ' . $response_body);
        } else {
            wp_send_json_success('Test import (Dry Run) submitted successfully.');
        }
      } catch (Throwable $e) {
            // This will catch any fatal errors and log them
            error_log('FATAL ERROR in run_import_test: ' . $e->getMessage());
            wp_send_json_error('A fatal error occurred: ' . $e->getMessage());
      }
    }

    private function verify_import_test() {
        $last_response = get_option('fsbhoa_last_test_response');
        if (empty($last_response)) {
            wp_send_json_error('Verification failed: No test response found.');
            return;
        }
        
        // Clean up the stored option
        delete_option('fsbhoa_last_test_response');

        $response_body = json_decode($last_response['body'], true);

        if ($last_response['code'] !== 200) {
            wp_send_json_error('Verification failed: The test run returned a non-200 status code.');
            return;
        }

        // For a dry run, we just check that the response contains the expected summary structure.
        if (isset($response_body['messages']) && is_array($response_body['messages'])) {
             wp_send_json_success('Import dry run completed and returned a valid report.');
        } else {
            wp_send_json_error('Verification failed: The dry run response was not in the expected format.');
        }
    }


    private function ensure_regression_controller() {
        global $wpdb;

        $controllers_table = 'ac_controllers';
        $doors_table = 'ac_doors';

        // 1. Check if the Regression Test controller already exists specifically by type
        $controller_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT controller_record_id FROM {$controllers_table} WHERE type = %s LIMIT 1",
            'REGRESSION_TEST'
        ) );

        // If not, create it with the REGRESSION_TEST type
        if ( ! $controller_id ) {
            $inserted = $wpdb->insert( $controllers_table, [
                'uhppoted_device_id' => 88888888,
                'friendly_name'      => 'Regression Test Controller',
                'type'               => 'REGRESSION_TEST',
            ] );

            if ( false === $inserted ) {
                error_log("FSBHOA DEBUG: Failed to insert regression controller: " . $wpdb->last_error);
                return;
            }
            $controller_id = $wpdb->insert_id;
            error_log("FSBHOA DEBUG: Created regression controller with ID: " . $controller_id);
        }

        // 2. Ensure a default door/gate exists for this specific controller record
        $door_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT door_record_id FROM {$doors_table} WHERE controller_record_id = %d LIMIT 1",
            $controller_id
        ) );

        if ( ! $door_id ) {
            $inserted_door = $wpdb->insert( $doors_table, [
                'controller_record_id'      => $controller_id,
                'door_number_on_controller' => 1,
                'friendly_name'             => 'Regression Test Gate',
                'door_role'                 => 'KIOSK',
            ] );

            if ( false === $inserted_door ) {
                error_log("FSBHOA DEBUG: Failed to insert regression door: " . $wpdb->last_error);
            } else {
                error_log("FSBHOA DEBUG: Created regression door for controller ID: " . $controller_id);
            }
        }
    }
}
