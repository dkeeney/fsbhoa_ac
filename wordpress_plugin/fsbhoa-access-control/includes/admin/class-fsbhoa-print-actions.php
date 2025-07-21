<?php
/**
 * Handles all AJAX actions for the interactive print workflow.
 * V2 - Refactored for database-driven status tracking.
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
     * Step 1: Creates a log entry, then submits the print job to the Go service.
     */
    public function ajax_submit_print_job() {
        check_ajax_referer('fsbhoa_print_card_nonce', 'security');

        if (!isset($_POST['cardholder_id']) || !is_numeric($_POST['cardholder_id'])) {
            wp_send_json_error(['message' => 'Invalid Cardholder ID.'], 400);
        }
        $cardholder_id = absint($_POST['cardholder_id']);

        global $wpdb;
        $cardholder = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_cardholders WHERE id = %d", $cardholder_id), ARRAY_A);

        if ($wpdb->last_error) {
            wp_send_json_error(['message' => 'Database error when fetching cardholder: ' . $wpdb->last_error], 500);
        }
        if (!$cardholder) {
            wp_send_json_error(['message' => 'Cardholder not found.'], 404);
        }

        // 1. Create the initial log entry in our database
        $log_data = [
            'cardholder_id'     => $cardholder_id,
            'status'            => 'submitted',
            'submitted_by_user' => wp_get_current_user()->user_login,
        ];
        $log_format = ['%d', '%s', '%s'];
        $wpdb->insert('ac_print_log', $log_data, $log_format);

        if ($wpdb->last_error) {
            wp_send_json_error(['message' => 'FATAL: Could not create initial database log entry. ' . $wpdb->last_error], 500);
        }
        $log_id = $wpdb->insert_id;

        // 2. Prepare the payload for the Go service
        // Get Card Back Logo from settings
        $card_back_url = get_option('fsbhoa_ac_card_back_url', '');
        $card_back_base64 = '';
        if (!empty($card_back_url)) {
            $image_id = attachment_url_to_postid($card_back_url);
            if ($image_id) {
                $image_path = get_attached_file($image_id);
                if ($image_path && file_exists($image_path)) {
                    $card_back_base64 = base64_encode(file_get_contents($image_path));
                }
            }
        }
        
        // Get Template JSON from settings
        $template_path = get_option('fsbhoa_ac_print_template_path', '');
        $template_json = '';
        if (!empty($template_path) && file_exists($template_path)) {
            $template_json = file_get_contents($template_path);
        }

        // Prepare expiration date line (line 2)
        $line_2 = '';
        if ($cardholder['card_expiry_date'] && $cardholder['card_expiry_date'] !== '2099-12-31') {
            $line_2 = 'Expires: ' . date('m/d/Y', strtotime($cardholder['card_expiry_date']));
        }

        $payload = [
            'log_id'           => $log_id,
            'cardholder_id'    => $cardholder_id,
            'template_xml'     => $template_json, // Renamed from template_json
            'fields' => [
                'firstName'     => $cardholder['first_name'],
                'lastName'      => $cardholder['last_name'],
                'residentPhoto' => base64_encode($cardholder['photo']),
                'cardBackLogo'  => $card_back_base64,
                // 'expirationDate' => $line_2, // We can add this later
            ]
        ];
        $payload_json = json_encode($payload);

        // Update the log with the data we are about to send, for auditing
        $update_result = $wpdb->update('ac_print_log', ['print_request_data' => $payload_json], ['log_id' => $log_id]);
        if ($update_result === false) {
            wp_send_json_error(['message' => 'FATAL: Could not update database log with print data. ' . $wpdb->last_error], 500);
        }

        // 3. Call the Go service
        $print_service_port = get_option('fsbhoa_ac_print_port', 8081);
        $print_service_url = sprintf('http://127.0.0.1:%d/print_card', $print_service_port);
        $response = wp_remote_post($print_service_url, [
            'body'    => $payload_json,
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);

        // 4. Handle the response
        if (is_wp_error($response)) {
            $error_message = 'Failed to connect to Print Service: ' . $response->get_error_message();
            // Update our log to reflect the connection failure
            $wpdb->update('ac_print_log', ['status' => 'failed_error', 'status_message' => $error_message], ['log_id' => $log_id]);
            // We don't check for an error on this final log update, as we are already in an error state.
            wp_send_json_error(['message' => $error_message], 500);
        }

        // If the connection was successful, the Go service has taken over.
        // Return the log_id so the JavaScript can start polling.
        wp_send_json_success(['log_id' => $log_id]);
    }


    /**
     * Step 2: Checks the status of a print job from our own database.
     */
    public function ajax_check_print_status() {
        check_ajax_referer('fsbhoa_print_card_nonce', 'security');

        if (!isset($_POST['log_id']) || !is_numeric($_POST['log_id'])) {
            wp_send_json_error(['message' => 'No Log ID provided.'], 400);
        }
        $log_id = absint($_POST['log_id']);

        global $wpdb;
        $status_row = $wpdb->get_row($wpdb->prepare(
            "SELECT status, status_message FROM ac_print_log WHERE log_id = %d",
            $log_id
        ), ARRAY_A);

        if ($wpdb->last_error) {
            wp_send_json_error(['message' => 'Database error looking up job status: ' . esc_html($wpdb->last_error)], 500);
        }
        if (!$status_row) {
            wp_send_json_error(['message' => 'Could not find a print job for the given log ID.'], 404);
        }

        wp_send_json_success($status_row);
    }

    /**
     * Step 3: Saves the new RFID and activates the card.
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
            wp_send_json_error(['message' => 'Database error during card activation: ' . $wpdb->last_error], 500);
        } else {
            wp_send_json_success(['message' => 'Card activated successfully!']);
        }
    }
}

