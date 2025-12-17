<?php
if (!defined('WPINC')) { die; }

class Fsbhoa_Schedule_Groups_Actions {

    public function __construct() {
        // Hooks for the new, schedule-aware group editor
        add_action('admin_post_fsbhoa_save_schedule_group', [$this, 'handle_save_group']);
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

    public function handle_save_group() {
        check_admin_referer('fsbhoa_save_group', 'fsbhoa_group_nonce');
        if (!current_user_can('manage_options')) { wp_die('Permission Denied.'); }

        global $wpdb;
        $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;
        $schedule_id = isset($_POST['schedule_id']) ? absint($_POST['schedule_id']) : 1;
        $is_default_schedule = ($schedule_id == 1);
        if ($is_default_schedule) {

            $other_default_groups_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM ac_groups WHERE is_default = 1 AND group_id != %d", $group_id
            ));
            $other_default_groups_count += isset($_POST['is_default']);
            if ($other_default_groups_count == 0) {
                wp_die('Error: The system must have at least one "Default Group". Please check the "Default Group" box before saving.', 'Error', ['back_link' => true]);
            }

            $group_data = [
                'group_name'        => sanitize_text_field($_POST['group_name']),
                'group_description' => sanitize_textarea_field($_POST['group_description']),
                'has_all_access'    => isset($_POST['has_all_access']) ? 1 : 0,
                'is_default'        => isset($_POST['is_default']) ? 1 : 0,
            ];
    
            if ($group_id > 0) {
                $result = $wpdb->update("ac_groups", $group_data, ['group_id' => $group_id]);
            } else {
                $group_data['is_enabled'] = 1;
                $result = $wpdb->insert("ac_groups", $group_data);
                if ($result) { $group_id = $wpdb->insert_id; }
            }
            if ($result === false) { wp_die('DB Error saving group details: ' . $wpdb->last_error); }
            } else {
            // If not the default schedule, just ensure the group_id is valid
            if ($group_id === 0) {
                wp_die('Error: Cannot create a new group from a holiday schedule. Please add the group from the "Default" schedule first.', 'Error', ['back_link' => true]);
            }
        }

        $permissions_data = isset($_POST['permissions']) ? (array) $_POST['permissions'] : [];
        $this->save_group_permissions($group_id, $permissions_data, $schedule_id);

        $active_schedule_id = fsbhoa_get_active_schedule_id();
        if ($schedule_id === $active_schedule_id) {
            fsbhoa_log_pending_change('group', $group_id);
        }

        $schedules_page_url = get_permalink(get_page_by_path('schedules'));
        $redirect_url = add_query_arg('schedule_id', $schedule_id, $schedules_page_url);
        wp_safe_redirect($redirect_url);
        exit;
    }

    private function save_group_permissions($group_id, $permissions, $schedule_id) {
        global $wpdb;
        $wpdb->delete("ac_group_permissions", ['group_id' => $group_id, 'schedule_id' => $schedule_id]);
        if ($wpdb->last_error) { wp_die('DB Error clearing old permissions: ' . $wpdb->last_error); }

        if (empty($permissions)) { return; }

        foreach ($permissions as $perm) {
            if (empty($perm['door_id']) || empty($perm['start_time']) || empty($perm['end_time'])) { continue; }
            $target_id = sanitize_text_field($perm['door_id']);
            $data_to_insert = [
                'group_id' => $group_id, 'schedule_id' => $schedule_id,
                'controller_id' => null, 'door_id' => null,
                'is_enabled' => isset($perm['is_enabled']) ? 1 : 0,
                'start_time' => sanitize_text_field($perm['start_time']),
                'end_time' => sanitize_text_field($perm['end_time']),
                'on_mon' => isset($perm['on_mon']) ? 1 : 0, 'on_tue' => isset($perm['on_tue']) ? 1 : 0,
                'on_wed' => isset($perm['on_wed']) ? 1 : 0, 'on_thu' => isset($perm['on_thu']) ? 1 : 0,
                'on_fri' => isset($perm['on_fri']) ? 1 : 0, 'on_sat' => isset($perm['on_sat']) ? 1 : 0,
                'on_sun' => isset($perm['on_sun']) ? 1 : 0,
            ];

            if ($target_id === 'all') {} 
            elseif (strpos($target_id, 'controller-') === 0) {
                $data_to_insert['controller_id'] = absint(str_replace('controller-', '', $target_id));
            } else {
                $data_to_insert['door_id'] = absint(str_replace('gate-', '', $target_id));
            }
            $wpdb->insert("ac_group_permissions", $data_to_insert);
            if ($wpdb->last_error) { wp_die('DB Error inserting permission rule: ' . $wpdb->last_error); }
        }
    }
}

