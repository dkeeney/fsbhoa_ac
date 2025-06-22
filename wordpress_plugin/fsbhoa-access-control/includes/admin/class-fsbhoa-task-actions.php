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
    }

    public function handle_form_submission() {
        global $wpdb;
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_update_task' );
        $item_id = $is_update ? absint($_POST['task_id']) : 0;
        
        check_admin_referer($is_update ? 'fsbhoa_update_task_' . $item_id : 'fsbhoa_add_task', '_wpnonce');

        // Parse the 'adapt_to' field
        list($type, $id) = explode('-', sanitize_text_field($_POST['adapt_to']));
        $controller_id = null;
        $door_id = null;
        
        if ($type === 'controller') {
            $controller_id = absint($id);
        } elseif ($type === 'door') {
            $door_id = absint($id);
        }

        $data = [
            'controller_id' => $controller_id,
            'door_number'   => $door_id, // Note: storing door_id here, as your table joins on it.
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
            'enabled'       => 1, // Always enabled by default
        ];

        $table_name = 'ac_task_list';
        if ($is_update) {
            $result = $wpdb->update($table_name, $data, ['id' => $item_id]);
        } else {
            $result = $wpdb->insert($table_name, $data);
        }

        if (false === $result) {
            wp_die('Database operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }
        
        $redirect_url = remove_query_arg(['action', 'task_id'], wp_get_referer());
        $redirect_url = add_query_arg('message', $is_update ? 'task_updated' : 'task_added', $redirect_url);
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
        
        $redirect_url = remove_query_arg(['action', 'task_id', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('message', 'task_deleted', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }
}

