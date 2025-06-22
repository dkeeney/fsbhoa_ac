<?php
// =================================================================================================
// FILE: includes/admin/class-fsbhoa-task-admin-page.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Task_Admin_Page {
    
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        if (empty($action) && isset($_GET['message'])) {
            $message_code = sanitize_key($_GET['message']);
            $message_text = '';
            switch ($message_code) {
                case 'task_added': $message_text = 'Task added successfully.'; break;
                case 'task_updated': $message_text = 'Task updated successfully.'; break;
                case 'task_deleted': $message_text = 'Task deleted successfully.'; break;
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
        require_once plugin_dir_path(__FILE__) . 'views/view-task-list.php';
        fsbhoa_render_task_list_view();
    }

    private function render_form_page($action) {
        $form_data = [
            'id' => 0, 'controller_id' => null, 'door_number' => null, 'task_type' => 0,
            'start_time' => '00:00', 'valid_from' => date('Y-m-d'), 'valid_to' => '2099-12-31',
            'on_mon' => 0, 'on_tue' => 0, 'on_wed' => 0, 'on_thu' => 0, 'on_fri' => 0, 'on_sat' => 0, 'on_sun' => 0,
            'notes' => '', 'adapt_to_selected' => ''
        ];
        $is_edit_mode = ($action === 'edit');
        
        global $wpdb;

        if ($is_edit_mode && isset($_GET['task_id'])) {
            $item_id = absint($_GET['task_id']);
            $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_task_list WHERE id = %d", $item_id), ARRAY_A);
            if ($result) {
                $form_data = $result;
                // Determine the currently selected 'adapt_to' value for the dropdown
                if ($result['door_number']) {
                    $form_data['adapt_to_selected'] = 'door-' . $result['door_number'];
                } elseif ($result['controller_id']) {
                    $form_data['adapt_to_selected'] = 'controller-' . $result['controller_id'];
                } else {
                    $form_data['adapt_to_selected'] = 'all-0';
                }
            }
        }
        
        $adapt_to_options = $this->get_adapt_to_options();
        
        require_once plugin_dir_path(__FILE__) . 'views/view-task-form.php';
        fsbhoa_render_task_form($form_data, $adapt_to_options, $is_edit_mode);
    }

    private function get_adapt_to_options() {
        global $wpdb;
        $options = [['value' => 'all-0', 'label' => '(All Controllers & Gates)']];
        
        $controllers = $wpdb->get_results("SELECT controller_record_id, friendly_name FROM ac_controllers ORDER BY friendly_name", ARRAY_A);
        foreach ($controllers as $controller) {
            $options[] = [
                'value' => 'controller-' . $controller['controller_record_id'],
                'label' => 'Controller: ' . $controller['friendly_name']
            ];
        }

        $doors = $wpdb->get_results("SELECT door_record_id, friendly_name FROM ac_doors ORDER BY friendly_name", ARRAY_A);
        foreach ($doors as $door) {
            $options[] = [
                'value' => 'door-' . $door['door_record_id'],
                'label' => 'Gate: ' . $door['friendly_name']
            ];
        }
        
        return $options;
    }
}

