<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Schedules_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_save_schedule', [$this, 'handle_save_schedule']);
        add_action('admin_post_fsbhoa_update_schedule', [$this, 'handle_update_schedule']);
        add_action('wp_ajax_fsbhoa_copy_schedule_rules', [$this, 'handle_copy_schedule_rules']);
        add_action('wp_ajax_fsbhoa_delete_schedule', [$this, 'ajax_delete_schedule']);
    }

    public function handle_save_schedule() {
        // 1. Security Checks
        check_admin_referer('fsbhoa_save_schedule_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        // 2. Sanitize and Validate Data
        $name = isset($_POST['schedule_name']) ? sanitize_text_field($_POST['schedule_name']) : '';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        if (empty($name) || empty($start_date) || empty($end_date)) {
            wp_die('All fields are required.', 'Validation Error', ['back_link' => true]);
        }
        
        // 3. Insert into Database
        global $wpdb;
        $wpdb->insert(
            'ac_schedules',
            [ 'name' => $name, 'start_date' => $start_date, 'end_date' => $end_date, 'is_default' => 0 ],
            ['%s', '%s', '%s', '%d']
        );

        if ($wpdb->last_error) {
            wp_die('Database error saving schedule: ' . $wpdb->last_error, 'DB Error', ['back_link' => true]);
        }

        // 4. Redirect to the new tab
        $new_schedule_id = $wpdb->insert_id;
        $schedules_page_url = get_permalink(get_page_by_path('schedules'));
        $redirect_url = add_query_arg('schedule_id', $new_schedule_id, $schedules_page_url);
        
        wp_safe_redirect($redirect_url);
        exit;
    }

    public function handle_update_schedule() {
        // 1. Security Checks
        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        check_admin_referer('fsbhoa_update_schedule_nonce_' . $schedule_id);
        if (!current_user_can('manage_options') || $schedule_id === 0) {
            wp_die('Permission denied or invalid ID.');
        }

        // 2. Sanitize and Validate Data
        $name = isset($_POST['schedule_name']) ? sanitize_text_field($_POST['schedule_name']) : '';
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '';

        if (empty($name) || empty($start_date) || empty($end_date)) {
            wp_die('All fields are required.', 'Validation Error', ['back_link' => true]);
        }
        
        // 3. Update Database
        global $wpdb;
        $wpdb->update(
            'ac_schedules',
            ['name' => $name, 'start_date' => $start_date, 'end_date' => $end_date],
            ['schedule_id' => $schedule_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        if ($wpdb->last_error) {
            wp_die('Database error updating schedule: ' . $wpdb->last_error, 'DB Error', ['back_link' => true]);
        }

        // 4. Redirect back to the same tab
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function handle_copy_schedule_rules() {
        // 1. Security & Data Validation
        check_ajax_referer('fsbhoa_schedules_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }
        $dest_id = isset($_POST['destination_schedule_id']) ? absint($_POST['destination_schedule_id']) : 0;
        $source_id = isset($_POST['source_schedule_id']) ? absint($_POST['source_schedule_id']) : 0;
        if ($source_id === 0 || $dest_id === 0 || $source_id === $dest_id) {
            wp_send_json_error('Invalid source or destination schedule selected.');
        }

        // 2. Perform Copy Operation with Correct Error Handling
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        // Delete old rules for the destination
        $wpdb->delete('ac_group_permissions', ['schedule_id' => $dest_id], ['%d']);
        if ($wpdb->last_error) { $wpdb->query('ROLLBACK'); wp_send_json_error('DB Error deleting old permissions: ' . $wpdb->last_error); return; }

        $wpdb->delete('ac_task_list', ['schedule_id' => $dest_id], ['%d']);
        if ($wpdb->last_error) { $wpdb->query('ROLLBACK'); wp_send_json_error('DB Error deleting old tasks: ' . $wpdb->last_error); return; }

        // Copy Group Permissions
        $group_perms = $wpdb->get_results($wpdb->prepare("SELECT * FROM ac_group_permissions WHERE schedule_id = %d", $source_id), ARRAY_A);
        if ($wpdb->last_error) { $wpdb->query('ROLLBACK'); wp_send_json_error('DB Error fetching source permissions: ' . $wpdb->last_error); return; }

        if ($group_perms) {
            foreach ($group_perms as $perm) {
                unset($perm['permission_id']);
                $perm['schedule_id'] = $dest_id;
                $wpdb->insert('ac_group_permissions', $perm);
                if ($wpdb->last_error) { $wpdb->query('ROLLBACK'); wp_send_json_error('DB Error inserting new permission: ' . $wpdb->last_error); return; }
            }
        }

        // Copy Tasks
        $tasks = $wpdb->get_results($wpdb->prepare("SELECT * FROM ac_task_list WHERE schedule_id = %d", $source_id), ARRAY_A);
        if ($wpdb->last_error) { $wpdb->query('ROLLBACK'); wp_send_json_error('DB Error fetching source tasks: ' . $wpdb->last_error); return; }

        if ($tasks) {
            foreach ($tasks as $task) {
                unset($task['id']);
                $task['schedule_id'] = $dest_id;
                $wpdb->insert('ac_task_list', $task);
                if ($wpdb->last_error) { $wpdb->query('ROLLBACK'); wp_send_json_error('DB Error inserting new task: ' . $wpdb->last_error); return; }
            }
        }

        $wpdb->query('COMMIT');
        wp_send_json_success('Schedules copied successfully. The page will now reload.');
    }


    public function ajax_delete_schedule() {
        check_ajax_referer('fsbhoa_schedules_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 0;
        
        // Critical safety check: Do not allow deleting the Default schedule.
        if ($schedule_id <= 1) {
            wp_send_json_error('The Default schedule cannot be deleted.');
        }

        global $wpdb;
        $result = $wpdb->delete('ac_schedules', ['schedule_id' => $schedule_id], ['%d']);

        if ($result === false) {
            wp_send_json_error('Database error deleting schedule: ' . $wpdb->last_error);
        }

        // Because we set up the database with "ON DELETE CASCADE",
        // all associated permissions and tasks were deleted automatically.
        wp_send_json_success('Schedule deleted successfully. Reloading...');
    }
}
