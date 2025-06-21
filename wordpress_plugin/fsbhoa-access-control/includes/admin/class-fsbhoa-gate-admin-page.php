<?php
// =================================================================================================
// FILE: includes/admin/class-fsbhoa-gate-admin-page.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Gate_Admin_Page {
    
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        if (empty($action) && isset($_GET['message'])) {
            $message_code = sanitize_key($_GET['message']);
            $message_text = '';
            switch ($message_code) {
                case 'gate_added': $message_text = 'Gate added successfully.'; break;
                case 'gate_updated': $message_text = 'Gate updated successfully.'; break;
                case 'gate_deleted': $message_text = 'Gate deleted successfully.'; break;
            }
            if ($message_text) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message_text) . '</p></div>';
            }
        }
        
        if ('add' === $action || 'edit' === $action) {
            $this->render_form_page($action);
        } else {
            $this->render_list_page();
        }
    }

    private function render_list_page() {
        require_once plugin_dir_path(__FILE__) . 'views/view-gate-list.php';
        fsbhoa_render_gate_list_view();
    }

    private function render_form_page($action) {
        $form_data = [
            'door_record_id' => 0,
            'controller_record_id' => 0,
            'door_number_on_controller' => 0,
            'friendly_name' => '',
            'notes' => ''
        ];
        $is_edit_mode = ($action === 'edit');
        
        global $wpdb;
        $available_slots = $this->get_available_controller_slots();

        if ($is_edit_mode && isset($_GET['gate_id'])) {
            $item_id = absint($_GET['gate_id']);
            $result = $wpdb->get_row($wpdb->prepare("SELECT d.*, c.friendly_name as controller_name FROM ac_doors d LEFT JOIN ac_controllers c ON d.controller_record_id = c.controller_record_id WHERE d.door_record_id = %d", $item_id), ARRAY_A);
            if ($result) {
                $form_data = $result;
            }
        }
        
        require_once plugin_dir_path(__FILE__) . 'views/view-gate-form.php';
        fsbhoa_render_gate_form($form_data, $available_slots, $is_edit_mode);
    }

    private function get_available_controller_slots() {
        global $wpdb;
        $slots_per_controller = get_option('fsbhoa_ac_slots_per_controller', 4);
        $controllers = $wpdb->get_results("SELECT controller_record_id, friendly_name FROM ac_controllers", ARRAY_A);
        $used_slots = $wpdb->get_results("SELECT controller_record_id, door_number_on_controller FROM ac_doors", OBJECT_K);

        $available_slots = [];
        foreach ($controllers as $controller) {
            for ($i = 1; $i <= $slots_per_controller; $i++) {
                $slot_key = $controller['controller_record_id'] . '_' . $i;
                if (!isset($used_slots[$slot_key])) {
                    $available_slots[] = [
                        'value' => $controller['controller_record_id'] . '-' . $i,
                        'label' => $controller['friendly_name'] . ' - Slot ' . $i
                    ];
                }
            }
        }
        return $available_slots;
    }
}

