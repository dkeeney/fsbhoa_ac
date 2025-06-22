<?php
/**
 * Handles all AJAX actions for the interactive print workflow.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Print_Actions {

    public function __construct() {
// --- DEBUG LINE 1 ---
error_log('DEBUG: Fsbhoa_Print_Actions constructor was called.');

        add_action('wp_ajax_fsbhoa_submit_print_job', array($this, 'ajax_submit_print_job'));
        add_action('wp_ajax_fsbhoa_check_print_status', array($this, 'ajax_check_print_status'));
        add_action('wp_ajax_fsbhoa_save_rfid', array($this, 'ajax_save_rfid_and_activate'));
    }

    /**
     * AJAX handler to submit the print job to the RISK server.
     */
    /**
     * AJAX handler to submit the print job to the Go service and log the attempt.
     */
    public function ajax_submit_print_job() {
        check_ajax_referer('fsbhoa_print_card_nonce', 'security');

        if (!isset($_POST['cardholder_id']) || !is_numeric($_POST['cardholder_id'])) {
            wp_send_json_error(['message' => 'Invalid Cardholder ID.'], 400);
            return;
        }
        $cardholder_id = absint($_POST['cardholder_id']);

        global $wpdb;
        $cardholder = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_cardholders WHERE id = %d", $cardholder_id), ARRAY_A);
        if (!$cardholder) {
            wp_send_json_error(['message' => 'Cardholder not found.'], 404);
            return;
        }

        $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM ac_property WHERE property_id = %d", $cardholder['property_id'])) ?: 'N/A';

        $payload = [
            'rfid_id'               => $cardholder['rfid_id'],
            'first_name'            => $cardholder['first_name'],
            'last_name'             => $cardholder['last_name'],
            'property_address_text' => $property_address,
            'photo_base64'          => base64_encode($cardholder['photo']),
            'resident_type'         => $cardholder['resident_type'],
            'card_issue_date'       => $cardholder['card_issue_date'],
            'card_expiry_date'      => $cardholder['card_expiry_date'],
            'submitted_by_user'     => wp_get_current_user()->user_login,
        ];
        $payload_json = json_encode($payload);

        // Call the Go service
        $risk_server_url = 'http://127.0.0.1:8081/print_card';
        $response = wp_remote_post($risk_server_url, [
            'body'      => $payload_json,
            'headers'   => ['Content-Type' => 'application/json'],
            'timeout'   => 15,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Failed to connect to the Print Service: ' . $response->get_error_message()], 500);
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded_body['status'])) {
            wp_send_json_error(['message' => 'Received an invalid response from the Print Service.', 'raw_response' => $body], 500);
            return;
        }

        // --- THIS IS THE FIX ---
        // If the Go service accepted the job, log it to our database before returning.
        if ($decoded_body['status'] === 'queued') {
            $system_job_id = $decoded_body['system_job_id'] ?? 'unknown_sys_id_' . time();
            $printer_job_id = $decoded_body['printer_job_id'] ?? null;

            $log_result = $wpdb->insert('ac_print_log', [
                'system_job_id'      => $system_job_id,
                'printer_job_id'     => $printer_job_id,
                'cardholder_id'      => $cardholder_id,
                'rfid_id'            => $payload['rfid_id'],
                'print_request_data' => $payload_json,
                'status'             => 'queued',
                'submitted_by_user'  => $payload['submitted_by_user']
            ]);

            if (false === $log_result) {
                // If logging fails, we must inform the user.
                wp_send_json_error(['message' => 'Job was sent to printer, but failed to log to the database. Please check manually. DB Error: ' . $wpdb->last_error], 500);
                return;
            }

            // Forward the successful response from the Go service to the browser.
            // The JS needs the system_job_id to start polling.
            wp_send_json($decoded_body);

        } else {
            // If the Go service returned an error, forward that error.
            wp_send_json_error(['message' => 'Print service rejected the job: ' . ($decoded_body['message'] ?? 'Unknown reason')]);
        }
    }


    /**
     * AJAX handler to check the status of a print job from our database.
     */
    public function ajax_check_print_status() {
// --- DEBUG LINE 2 ---
    error_log('DEBUG: ajax_check_print_status function was reached.');
        check_ajax_referer('fsbhoa_print_card_nonce', 'security');

        if (!isset($_POST['system_job_id'])) {
            wp_send_json_error(['message' => 'No System Job ID provided.'], 400);
        }
        $system_job_id = sanitize_text_field($_POST['system_job_id']);

        // 1. Get the printer_job_id from our log table
        global $wpdb;
        $printer_job_id = $wpdb->get_var($wpdb->prepare(
            "SELECT printer_job_id FROM ac_print_log WHERE system_job_id = %s",
            $system_job_id
        ));

        if ($wpdb->last_error) {
            wp_send_json_error(['message' => 'Database error looking up job ID: ' . esc_html($wpdb->last_error)], 500);
        }

        if (empty($printer_job_id)) {
            wp_send_json_error(['message' => 'Could not find a printer job ID for the given system job ID.'], 404);
        }

        // 2. Poll the Go service for the status
        $risk_server_url = 'http://127.0.0.1:8081/print-status/' . $printer_job_id;
        $response = wp_remote_get($risk_server_url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Failed to connect to the Print Service for status check: ' . $response->get_error_message()], 500);
        }

        // 3. Forward the Go service's response to the browser
        $body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            // The data payload from Go already contains 'status' and 'message' (renamed to status_message)
            wp_send_json_success($decoded_body);
        } else {
            wp_send_json_error(['message' => 'Received an invalid status response from the Print Service.'], 500);
        }
    }


    /**
     * AJAX handler to save the new RFID and activate the card.
     */
    public function ajax_save_rfid_and_activate() {
        check_ajax_referer('fsbhoa_print_card_nonce', 'security');

        if (!isset($_POST['cardholder_id']) || !is_numeric($_POST['cardholder_id']) || !isset($_POST['rfid_id'])) {
            wp_send_json_error(['message' => 'Invalid data provided.'], 400);
        }

        $cardholder_id = absint($_POST['cardholder_id']);
        $rfid_id = sanitize_text_field($_POST['rfid_id']);
        
        global $wpdb;
        $result = $wpdb->update(
            'ac_cardholders',
            [
                'rfid_id'         => $rfid_id,
                'card_status'     => 'active',
                'card_issue_date' => current_time('mysql'),
            ],
            ['id' => $cardholder_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error(['message' => 'Database error during update.'], 500);
        } else {
            wp_send_json_success(['message' => 'Card activated successfully!']);
        }
    }
}

