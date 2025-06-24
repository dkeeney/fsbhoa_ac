<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Controller_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_add_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_update_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_delete_controller', [ $this, 'handle_delete_action' ]);
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

        // --- NEW: Only proceed if there are no validation errors yet ---
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

        // --- NEW: Check for errors and redirect accordingly ---
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
        // --- THIS IS THE FIX ---
        // Remove the action parameters to ensure we go back to the list view.
        $redirect_url = remove_query_arg( ['action', 'controller_id', '_wpnonce'], $redirect_url );
        $redirect_url = add_query_arg('message', 'controller_deleted', $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;
    }
}

