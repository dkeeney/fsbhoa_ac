<?php
// =================================================================================================
// FILE: includes/admin/class-fsbhoa-task-actions.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Task_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_add_task', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_update_task', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_delete_task', [ $this, 'handle_delete_action' ]);
        add_action('admin_post_fsbhoa_toggle_task_status', [ $this, 'handle_toggle_enabled_action' ]);
    }


    public function handle_form_submission() {
        error_log('DEBUG Task POST data: ' . print_r($_POST, true));

        global $wpdb;
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_update_task' );
        $item_id = $is_update ? absint($_POST['task_id']) : 0;

        check_admin_referer($is_update ? 'fsbhoa_update_task_' . $item_id : 'fsbhoa_add_task', '_wpnonce');

        // This section now correctly determines the controller_id and door_number
        $adapt_to = sanitize_text_field($_POST['adapt_to']);
        list($type, $id_from_form) = explode('-', $adapt_to);

        $controller_id = null;
        $door_number   = null; // This will store the physical door number (1-4)

        if ($type === 'controller') {
            $controller_id = absint($id_from_form);
        } elseif ($type === 'door') {
            $door_id = absint($id_from_form); // This is the door's primary key
            // We have the door's primary key, now we need to look up its details.
            $door_info = $wpdb->get_row($wpdb->prepare("SELECT controller_record_id, door_number_on_controller FROM ac_doors WHERE door_record_id = %d", $door_id));
            if ($door_info) {
                $controller_id = $door_info->controller_record_id;
                $door_number   = $door_info->door_number_on_controller;
            }
        }
        // If $type is 'all', both controller_id and door_number correctly remain null.

        $data = [
            'controller_id' => $controller_id,
            'door_number'   => $door_number, // This is now correctly defined or null
            'task_type'     => absint($_POST['task_type']),
            'start_time'    => sanitize_text_field($_POST['start_time']),
            'valid_from'    => sanitize_text_field($_POST['valid_from']),
            'valid_to'      => sanitize_text_field($_POST['valid_to']),
            'on_mon'        => isset($_POST['on_mon']) ? 1 : 0,
            'on_tue'        => isset($_POST['on_tue']) ? 1 : 0,
            'on_wed'        => isset($_POST['on_wed']) ? 1 : 0,
            'on_thu'        => isset($_POST['on_thu']) ? 1 : 0,
            'on_fri'        => isset($_POST['on_fri']) ? 1 : 0,
            'on_sat'        => isset($_POST['on_sat']) ? 1 : 0,
            'on_sun'        => isset($_POST['on_sun']) ? 1 : 0,
            'notes'         => sanitize_textarea_field($_POST['notes']),
            'enabled'       => 1,
        ];
        
        error_log('DEBUG: Data being saved to DB: ' . print_r($data, true));

        $table_name = 'ac_task_list';
        if ($is_update) {
            $result = $wpdb->update($table_name, $data, ['id' => $item_id]);
        } else {
            $result = $wpdb->insert($table_name, $data);
        }

        if (false === $result) {
            wp_die('Database operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }

        wp_schedule_single_event(time(), 'fsbhoa_run_background_sync');

        $redirect_url = remove_query_arg(['action', 'task_id'], wp_get_referer());
        $redirect_url = add_query_arg('sync_started', '1', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }


    public function handle_delete_action() {
        global $wpdb;
        $item_id = absint($_GET['task_id']);
        check_admin_referer('fsbhoa_delete_task_nonce_' . $item_id, '_wpnonce');
        
        $result = $wpdb->delete('ac_task_list', ['id' => $item_id]);

        if (false === $result) {
            wp_die('Database delete operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }
        // Trigger the background sync to push changes to the hardware.
        wp_schedule_single_event(time(), 'fsbhoa_run_background_sync');
        
        
        $redirect_url = remove_query_arg(['action', 'task_id', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('message', 'task_deleted', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

/**
     * Handles toggling the 'enabled' status of a task.
     */
    public function handle_toggle_enabled_action() {
        global $wpdb;
        $item_id = absint($_GET['task_id']);
        check_admin_referer('fsbhoa_toggle_task_status_' . $item_id, '_wpnonce');

        // Get the current status
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT enabled FROM ac_task_list WHERE id = %d", $item_id));

        // Flip the status (if it's 1, make it 0; otherwise, make it 1)
        $new_status = ($current_status == 1) ? 0 : 1;

        // Update the database
        $result = $wpdb->update('ac_task_list', ['enabled' => $new_status], ['id' => $item_id]);

        if (false === $result) {
            wp_die('Database update operation failed. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true]);
        }

        // Trigger a background sync to push the change
        wp_schedule_single_event(time(), 'fsbhoa_run_background_sync');

        // Redirect back to the task list with a sync flag
        $redirect_url = remove_query_arg(['action', 'task_id', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('sync_started', '1', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

}

