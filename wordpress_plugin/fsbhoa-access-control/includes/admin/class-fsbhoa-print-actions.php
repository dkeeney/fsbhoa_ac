<?php
/**
 * Handles all AJAX actions for the interactive print workflow.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Print_Actions {

    public function __construct() {
        add_action('wp_ajax_fsbhoa_submit_print_job', array($this, 'ajax_submit_print_job'));
        add_action('wp_ajax_fsbhoa_check_print_status', array($this, 'ajax_check_print_status'));
        add_action('wp_ajax_fsbhoa_save_rfid', array($this, 'ajax_save_rfid_and_activate'));
    }

    /**
     * AJAX handler to submit the print job to the RISK server.
     */
    public function ajax_submit_print_job() {
        check_ajax_referer('fsbhoa_print_card_nonce', 'security');

        if (!isset($_POST['cardholder_id']) || !is_numeric($_POST['cardholder_id'])) {
            wp_send_json_error(['message' => 'Invalid Cardholder ID.'], 400);
        }
        $cardholder_id = absint($_POST['cardholder_id']);

        // Fetch all cardholder data needed for the payload
        global $wpdb;
        $cardholder = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_cardholders WHERE id = %d", $cardholder_id), ARRAY_A);
        if (!$cardholder) {
            wp_send_json_error(['message' => 'Cardholder not found.'], 404);
        }
        
        $property_address = 'N/A';
        if ($cardholder['property_id']) {
            $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM ac_property WHERE property_id = %d", $cardholder['property_id']));
        }

        // Build the payload matching PrintRequestPayload.java
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

        $risk_server_url = 'http://127.0.0.1:8081/print_card';
        $args = [
            'body'        => json_encode($payload),
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'method'      => 'POST',
            'data_format' => 'body',
            'timeout'     => 15,
        ];

        $response = wp_remote_post($risk_server_url, $args);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Failed to connect to the Print Service: ' . $response->get_error_message()], 500);
        } else {
            $body = wp_remote_retrieve_body($response);
            $decoded_body = json_decode($body, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                wp_send_json($decoded_body);
            } else {
                wp_send_json_error(['message' => 'Received an invalid response from the Print Service.', 'raw_response' => $body], 500);
            }
        }
    }

    /**
     * AJAX handler to check the status of a print job from our database.
     */
    public function ajax_check_print_status() {
        check_ajax_referer('fsbhoa_print_card_nonce', 'security');

        if (!isset($_POST['system_job_id'])) {
            wp_send_json_error(['message' => 'No Job ID provided.'], 400);
        }
        $system_job_id = sanitize_text_field($_POST['system_job_id']);

        global $wpdb;
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT status, status_message FROM ac_print_log WHERE system_job_id = %s",
            $system_job_id
        ), ARRAY_A);

        if ($result) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => 'Job ID not found.'], 404);
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

