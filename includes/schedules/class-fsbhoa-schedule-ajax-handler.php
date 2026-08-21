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

        // 2. Broadcast that the group was saved so hardware plugins can save their rules
        do_action('fsbhoa_core_group_saved', $group_id, $schedule_id, wp_unslash($_POST));

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
        do_action('fsbhoa_render_schedule_visualizer', $group_id, $schedule_id);
        return ob_get_clean();
}
}
