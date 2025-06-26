<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Controller_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_add_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_update_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_delete_controller', [ $this, 'handle_delete_action' ]);
        add_action('admin_post_fsbhoa_discover_controllers', [ $this, 'handle_discover_action' ]);
        add_action('admin_post_fsbhoa_add_discovered_controllers', [ $this, 'handle_add_discovered_action' ]);
        add_action('wp_ajax_fsbhoa_sync_all_controllers', [ $this, 'ajax_handle_sync_all' ]);
        add_action('wp_ajax_fsbhoa_get_sync_status', [ $this, 'ajax_get_sync_status' ]);
    }

   public function handle_form_submission() {
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_update_controller' );
        $item_id = $is_update ? absint($_POST['controller_record_id']) : 0;

        $nonce_action = $is_update ? 'fsbhoa_update_controller_' . $item_id : 'fsbhoa_add_controller';
        check_admin_referer($nonce_action, '_wpnonce');

        $errors = [];
        $data = [
            'friendly_name'        => sanitize_text_field($_POST['friendly_name']),
            'uhppoted_device_id'   => absint($_POST['uhppoted_device_id']),
            'ip_address'           => sanitize_text_field($_POST['ip_address']),
            'location_description' => sanitize_textarea_field($_POST['location_description']),
            'notes'                => sanitize_textarea_field($_POST['notes']),
        ];

        if (empty($data['friendly_name'])) {
            $errors[] = 'Friendly Name is required.';
        }
        if (empty($data['uhppoted_device_id'])) {
            $errors[] = 'Device ID is required.';
        }

        // ---  Only proceed if there are no validation errors yet ---
        if (empty($errors)) {
            global $wpdb;
            $table_name = 'ac_controllers';

            if ($is_update) {
                $result = $wpdb->update($table_name, $data, ['controller_record_id' => $item_id]);
            } else {
                $result = $wpdb->insert($table_name, $data);
            }

            if (false === $result) {
                // Check for a duplicate key error
                if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                    $errors[] = 'That Device ID (Serial Number) is already in use by another controller.';
                } else {
                    $errors[] = 'A database error occurred. Please try again. Error: ' . esc_html($wpdb->last_error);
                }
            }
        }

        // --- Check for errors and redirect accordingly ---
        $form_page_url = wp_get_referer();
        if ( ! $form_page_url ) { $form_page_url = get_permalink(); }

        if (!empty($errors)) {
            // If there are errors, save data to a transient and redirect back to the form
            $transient_key = 'fsbhoa_controller_feedback_' . ($is_update ? 'edit_' . $item_id : 'add');
            set_transient($transient_key, ['errors' => $errors, 'data' => $_POST], MINUTE_IN_SECONDS * 5);

            $redirect_url = add_query_arg('message', 'validation_error', $form_page_url);
            wp_safe_redirect($redirect_url);
            exit;
        }

        // If successful, redirect to the list page
        $list_page_url = remove_query_arg( ['action', 'controller_id'], $form_page_url );
        $redirect_url = add_query_arg('message', $is_update ? 'controller_updated' : 'controller_added', $list_page_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_delete_action() {
        $item_id = absint($_GET['controller_id']);
        check_admin_referer('fsbhoa_delete_controller_nonce_' . $item_id, '_wpnonce');

        global $wpdb;
        $table_name = 'ac_controllers';
        $result = $wpdb->delete($table_name, ['controller_record_id' => $item_id]);

        // Added database error checking
        if (false === $result) {
            wp_die('Database delete operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }

        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $redirect_url = get_permalink();
        }

        // This is the correct block for the delete action
        $redirect_url = remove_query_arg( ['action', 'controller_id', '_wpnonce'], $redirect_url );
        $redirect_url = add_query_arg('message', 'controller_deleted', $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;

    }

    /**
     * Handles the controller discovery process.
     */
    public function handle_discover_action() {
        check_admin_referer('fsbhoa_discover_controllers_nonce');
    
        $discovered_controllers = fsbhoa_discover_controllers_udp();

        global $wpdb;
        $table_name = 'ac_controllers';
        $db_controllers_raw = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);

        $db_controllers = [];
        foreach ($db_controllers_raw as $c) {
            $db_controllers[$c['uhppoted_device_id']] = $c;
        }
    
        $results = [ 'updated' => [], 'missing' => [], 'new' => [], ];
    
        foreach ($discovered_controllers as $discovered) {
            $device_id = $discovered['device-id'];
            $ip_address = $discovered['address'];
    
            if (isset($db_controllers[$device_id])) {
                if ($db_controllers[$device_id]['ip_address'] !== $ip_address) {
                    $wpdb->update($table_name, ['ip_address' => $ip_address], ['uhppoted_device_id' => $device_id]);
                    $results['updated'][] = [
                        'friendly_name' => $db_controllers[$device_id]['friendly_name'],
                        'uhppoted_device_id' => $device_id,
                        'old_ip' => $db_controllers[$device_id]['ip_address'],
                        'new_ip' => $ip_address,
                    ];
                }
                unset($db_controllers[$device_id]);
            } else {
                $results['new'][] = $discovered;
            }
        }

        $results['missing'] = array_values($db_controllers);
        foreach ($results['missing'] as $missing_controller) {
            $wpdb->update($table_name, ['ip_address' => ''], ['uhppoted_device_id' => $missing_controller['uhppoted_device_id']]);
        }

        set_transient('fsbhoa_discovery_results', $results, MINUTE_IN_SECONDS * 5);

        $results_page_url = add_query_arg('discovery-results', 'true', wp_get_referer());
        wp_safe_redirect($results_page_url);
        exit;
    }

    /**
     * Handles adding the new controllers selected from the discovery results page.
     */
    public function handle_add_discovered_action() {
        check_admin_referer('fsbhoa_add_discovered_nonce', '_wpnonce');

        if (empty($_POST['new_controllers'])) {
            wp_safe_redirect(wp_get_referer());
            exit;
        }

        global $wpdb;
        $table_name = 'ac_controllers';

        foreach ($_POST['new_controllers'] as $device_id => $details) {
            // Check if the 'add' checkbox was checked and a name was provided
            if (isset($details['add']) && !empty($details['friendly_name'])) {
                $wpdb->insert($table_name, [
                    'uhppoted_device_id'   => absint($device_id),
                    'ip_address'           => sanitize_text_field($details['ip_address']),
                    'friendly_name'        => sanitize_text_field($details['friendly_name']),
                ]);
            }
        }

        // Get the URL of the page that submitted the form
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            // As a fallback, build the URL to the main hardware page
            $redirect_url = add_query_arg('view', 'controllers', get_permalink( get_page_by_path('hardware') ));
        }

        // Clean up the URL from the discovery-results parameter
        $list_page_url = remove_query_arg( 'discovery-results', $redirect_url );

        // Add a success message to the final URL
        $final_url = add_query_arg('message', 'controller_added', $list_page_url);

        wp_safe_redirect($final_url);
        exit;
    }

    /**
     * AJAX handler to kick off the background sync process.
     */
    public function ajax_handle_sync_all() {
        check_ajax_referer('fsbhoa_sync_nonce', 'nonce');

        // First, check if a sync is already scheduled or running to prevent duplicates.
        if (wp_next_scheduled('fsbhoa_run_background_sync')) {
            wp_send_json_success(['message' => 'Sync is already in progress.']);
            return;
        }

        // Schedule a single, one-off event to run as soon as possible.
        // This is the key to creating a reliable background process.
        wp_schedule_single_event(time(), 'fsbhoa_run_background_sync');

        // Set the initial "in progress" transient so the UI updates immediately.
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Sync process has been scheduled...'], MINUTE_IN_SECONDS * 5);

        // Tell the browser that the scheduling was successful.
        wp_send_json_success(['message' => 'Sync process scheduled successfully.']);
    }



    /**
     * AJAX handler to check the status of a sync.
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('fsbhoa_sync_nonce', 'nonce');
        error_log("AJAX GET STATUS: Polling function executed.");

        // First, check for the "sticky" final status flag in the options table.
        error_log("AJAX GET STATUS: >>> Checking for 'fsbhoa_sync_final_status' option.");
        $final_status = get_option('fsbhoa_sync_final_status');

        if ($final_status !== false) {
            error_log("AJAX GET STATUS: <<< FOUND final status option: " . json_encode($final_status));
            wp_send_json_success($final_status);

            // This runs after the browser has received the 'complete' message.
            error_log("AJAX GET STATUS: >>> Deleting 'fsbhoa_sync_final_status' option.");
            delete_option('fsbhoa_sync_final_status');
            error_log("AJAX GET STATUS: <<< Deleted final status option. Terminating poll check.");
            return; // We are done.
        }
        error_log("AJAX GET STATUS: <<< Final status option NOT found.");

        // If no final flag was found, check for a normal 'in-progress' transient.
        error_log("AJAX GET STATUS: >>> Checking for 'fsbhoa_sync_status' transient.");
        $in_progress_status = get_transient('fsbhoa_sync_status');

        if ($in_progress_status !== false) {
            error_log("AJAX GET STATUS: <<< FOUND in-progress transient: " . json_encode($in_progress_status));
            wp_send_json_success($in_progress_status);
        } else {
            error_log("AJAX GET STATUS: <<< In-progress transient NOT found. Sending 'idle'.");
            wp_send_json_success(['status' => 'idle', 'message' => '']);
        }
    }
}

