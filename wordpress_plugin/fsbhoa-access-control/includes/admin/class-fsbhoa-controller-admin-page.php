<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Controller_Admin_Page {
    
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        // Display messages
        if (empty($action) && isset($_GET['message'])) {
            $message_code = sanitize_key($_GET['message']);
            $message_text = '';
            switch ($message_code) {
                case 'controller_added': $message_text = 'Controller added successfully.'; break;
                case 'controller_updated': $message_text = 'Controller updated successfully.'; break;
                case 'controller_deleted': $message_text = 'Controller deleted successfully.'; break;
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
        require_once plugin_dir_path(__FILE__) . 'views/view-controller-list.php';
        fsbhoa_render_controller_list_view();
    }

    private function render_form_page($action) {
        $form_data = [
            'controller_record_id' => 0,
            'friendly_name' => '',
            'uhppoted_device_id' => '',
            'location_description' => '',
            'notes' => ''
        ];
        $errors = [];
        $is_edit_mode = ($action === 'edit');
        $item_id = $is_edit_mode ? absint($_GET['controller_id']) : 0;

        // --- NEW: Check for feedback from a failed submission via transient ---
        $transient_key = 'fsbhoa_controller_feedback_' . ($is_edit_mode ? 'edit_' . $item_id : 'add');
        $feedback = get_transient($transient_key);

        if ($feedback !== false) {
            // Recovering from a validation error
            $form_data = array_merge($form_data, $feedback['data']);
            $errors = $feedback['errors'];
            delete_transient($transient_key);
        } elseif ($is_edit_mode) {
            // Initial load of an edit form, get data from DB
            global $wpdb;
            $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_controllers WHERE controller_record_id = %d", $item_id), ARRAY_A);
            if ($result) {
                $form_data = $result;
            }
        }
        
        require_once plugin_dir_path(__FILE__) . 'views/view-controller-form.php';
        fsbhoa_render_controller_form($form_data, $is_edit_mode, $errors);
    }
}

