<?php
// =================================================================================================
// FILE: includes/admin/class-fsbhoa-gate-actions.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Gate_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_add_gate', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_update_gate', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_delete_gate', [ $this, 'handle_delete_action' ]);
    }

    public function handle_form_submission() {
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_update_gate' );
        $item_id = $is_update ? absint($_POST['door_record_id']) : 0;
        
        $nonce_action = $is_update ? 'fsbhoa_update_gate_' . $item_id : 'fsbhoa_add_gate';
        check_admin_referer($nonce_action, '_wpnonce');

        $slot_parts = explode('-', sanitize_text_field($_POST['controller_slot']));
        
        $data = [
            'friendly_name'             => sanitize_text_field($_POST['gate_name']),
            'notes'                     => sanitize_textarea_field($_POST['notes']),
            'controller_record_id'      => isset($slot_parts[0]) ? absint($slot_parts[0]) : 0,
            'door_number_on_controller' => isset($slot_parts[1]) ? absint($slot_parts[1]) : 0,
        ];

        if (empty($data['friendly_name']) || empty($data['controller_record_id']) || empty($data['door_number_on_controller'])) {
            wp_die('Gate Name and a valid Controller Slot are required.', 'Error', ['back_link' => true]);
        }

        global $wpdb;
        $table_name = 'ac_doors';

        if ($is_update) {
            $result = $wpdb->update($table_name, $data, ['door_record_id' => $item_id]);
        } else {
            $result = $wpdb->insert($table_name, $data);
        }

        if (false === $result) {
            wp_die('Database operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }
        
        $redirect_url = remove_query_arg(['action', 'gate_id'], wp_get_referer());
        $redirect_url = add_query_arg('message', $is_update ? 'gate_updated' : 'gate_added', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_delete_action() {
        $item_id = absint($_GET['gate_id']);
        check_admin_referer('fsbhoa_delete_gate_nonce_' . $item_id, '_wpnonce');

        global $wpdb;
        $table_name = 'ac_doors';
        $result = $wpdb->delete($table_name, ['door_record_id' => $item_id]);

        if (false === $result) {
            wp_die('Database delete operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }
        
        $redirect_url = remove_query_arg(['action', 'gate_id', '_wpnonce'], wp_get_referer());
        $redirect_url = add_query_arg('message', 'gate_deleted', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }
}

