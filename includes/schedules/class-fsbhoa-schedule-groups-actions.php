<?php
if (!defined('WPINC')) { die; }

class Fsbhoa_Schedule_Groups_Actions {

    public function __construct() {
        // Hooks for the new, schedule-aware group editor
        add_action('admin_post_fsbhoa_schedule_delete_group', [$this, 'handle_delete_group']);
        add_action('admin_post_fsbhoa_schedule_toggle_group', [$this, 'handle_toggle_group_status']);
    }

    public function handle_delete_group() {
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
        if ($group_id === 0) { wp_die('Invalid group ID.'); }
        check_admin_referer('fsbhoa_schedule_delete_group_' . $group_id);
        if (!current_user_can('manage_options')) { wp_die('You do not have permission to delete groups.'); }

        global $wpdb;
        $result = $wpdb->delete('ac_groups', ['group_id' => $group_id], ['%d']);
        if ($result !== false) {
            fsbhoa_log_pending_change('group', $group_id);
        }
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function handle_toggle_group_status() {
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;
        if ($group_id === 0) { wp_die('Invalid group ID.'); }
        check_admin_referer('fsbhoa_schedule_toggle_group_' . $group_id);
        if (!current_user_can('manage_options')) { wp_die('You do not have permission to change group status.'); }

        global $wpdb;
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_enabled FROM ac_groups WHERE group_id = %d", $group_id));
        if ($current_status !== null) {
            $new_status = $current_status == 1 ? 0 : 1;
            $result = $wpdb->update('ac_groups', ['is_enabled' => $new_status], ['group_id' => $group_id], ['%d'], ['%d']);
            if ($result !== false) {
                fsbhoa_log_pending_change('group', $group_id);
            }
        }
        wp_safe_redirect(wp_get_referer());
        exit;
    }

}

