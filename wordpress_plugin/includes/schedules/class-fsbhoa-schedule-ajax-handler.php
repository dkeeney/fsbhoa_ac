<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Schedule_AJAX_Handler {
    public function __construct() {
        add_action('wp_ajax_fsbhoa_ajax_save_and_refresh', [$this, 'handle_ajax_save_and_refresh']);
    }

    public function handle_ajax_save_and_refresh() {
        check_ajax_referer('fsbhoa_save_group', 'fsbhoa_group_nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error('Permission Denied'); }

        global $wpdb;
        $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 1;

        // 1. Save Group Details (Only if Normal Schedule)
        //    If it is a holiday schedule, the group data cannot be modified.
        if ($schedule_id == 1) {
            $group_name = sanitize_text_field($_POST['group_name'] ?? '');
            if (empty($group_name)) {
                wp_send_json_error('Group Name is required.');
                exit; // is implied by wp_send_json_error
            }

            // Safety check: Ensure we aren't unsetting the last default group
            if ($group_id > 0 && !isset($_POST['is_default'])) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM ac_groups WHERE is_default = 1 AND group_id != %d", $group_id
                ));
                if ($count == 0) {
                    wp_send_json_error('System must have at least one Default Group.');
                    exit;
                }
            }
            $group_data = [
                'group_name'        => $group_name,
                'group_description' => sanitize_textarea_field($_POST['group_description']),
                'has_all_access'    => isset($_POST['has_all_access']) ? 1 : 0,
                'is_default'        => isset($_POST['is_default']) ? 1 : 0,
            ];
            if ($group_id > 0) {
                // Existing Group: Update
                $wpdb->update("ac_groups", $group_data, ['group_id' => $group_id]);
            } else {
                // New Group: Insert
                $wpdb->insert("ac_groups", $group_data);
                $group_id = $wpdb->insert_id; // Capture the new ID
            }
        }

        // 2. Save Permissions (Matches your Actions class logic exactly)
        $wpdb->delete("ac_group_permissions", ['group_id' => $group_id, 'schedule_id' => $schedule_id]);
        $permissions = isset($_POST['permissions']) ? (array) $_POST['permissions'] : [];

        foreach ($permissions as $perm) {
            if (empty($perm['door_id']) || empty($perm['start_time']) || empty($perm['end_time'])) continue;
            
            $target_id = sanitize_text_field($perm['door_id']);
            $data = [
                'group_id' => $group_id, 'schedule_id' => $schedule_id,
                'is_enabled' => (isset($perm['is_enabled']) && $perm['is_enabled'] == '1') ? 1 : 0,
                'start_time' => sanitize_text_field($perm['start_time']),
                'end_time' => sanitize_text_field($perm['end_time']),
                'on_mon' => isset($perm['on_mon']) ? 1 : 0, 'on_tue' => isset($perm['on_tue']) ? 1 : 0,
                'on_wed' => isset($perm['on_wed']) ? 1 : 0, 'on_thu' => isset($perm['on_thu']) ? 1 : 0,
                'on_fri' => isset($perm['on_fri']) ? 1 : 0, 'on_sat' => isset($perm['on_sat']) ? 1 : 0,
                'on_sun' => isset($perm['on_sun']) ? 1 : 0,
            ];

            if (strpos($target_id, 'controller-') === 0) {
                $data['controller_id'] = absint(str_replace('controller-', '', $target_id));
            } elseif ($target_id !== 'all') {
                $data['door_id'] = absint(str_replace('gate-', '', $target_id));
            }
            $wpdb->insert("ac_group_permissions", $data);
        }

        fsbhoa_log_pending_change('group', $group_id);

        // 3. Render and return
        // No need to ob_start here since get_visualizer_html handles it
        $response_data = [
          'html'         => $this->get_visualizer_html($group_id, $schedule_id),
          'new_group_id' => $group_id,
        ];

        wp_send_json_success($response_data);
    }



    private function get_visualizer_html($group_id, $schedule_id) {
        ob_start();
        // Ensure these variables are available to the included view
        include FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-group-schedule-visualizer.php';
        return ob_get_clean();
}
}
