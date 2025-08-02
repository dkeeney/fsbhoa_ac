<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/admin/class-fsbhoa-groups-actions.php

if (!defined('WPINC')) {
    die;
}

/**
 * Handles the saving, updating, and deleting of groups.
 * This class hooks into admin_post actions for processing form submissions.
 */
class FSBHOA_Groups_Actions {

    /**
     * Constructor. Hooks into the appropriate admin_post actions.
     */
    public function __construct() {
        add_action('admin_post_fsbhoa_save_group', [$this, 'handle_save_group']);
        add_action('admin_post_fsbhoa_delete_group', [$this, 'handle_delete_group']);
        add_action('admin_post_fsbhoa_toggle_group_status', [$this, 'handle_toggle_group_status']);
    }

    /**
     * Handles the request to enable or disable a group.
     */
    public function handle_toggle_group_status() {
        // 1. Get the group ID from the URL.
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;

        // 2. Security Checks
        if ($group_id === 0) {
            wp_die('Invalid group ID.');
        }
        check_admin_referer('fsbhoa_toggle_status_action_' . $group_id, 'fsbhoa_toggle_status_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to change group status.');
        }

        // 3. Database Operation
        global $wpdb;
        // Get the current status
        $current_status = $wpdb->get_var($wpdb->prepare("SELECT is_enabled FROM ac_groups WHERE group_id = %d", $group_id));

        if ($current_status === null) {
            add_settings_error('fsbhoa-groups-notices', 'db_error', 'Could not find group to update.', 'error');
        } else {
            // Toggle the status (if it's 1, make it 0; if it's 0, make it 1).
            $new_status = $current_status == 1 ? 0 : 1;
            $result = $wpdb->update('ac_groups', ['is_enabled' => $new_status], ['group_id' => $group_id], ['%d'], ['%d']);
            
            if ($result === false) {
                // Error
                add_settings_error('fsbhoa-groups-notices', 'db_error', 'Database error updating group status: ' . $wpdb->last_error, 'error');
            } else {
                // Success
                $message = $new_status == 1 ? 'Group enabled successfully.' : 'Group disabled successfully.';
                add_settings_error('fsbhoa-groups-notices', 'group_status_changed', __($message, 'fsbhoa-ac'), 'updated');
            }
        }
        
        // 4. Set notice and redirect
        set_transient('settings_errors', get_settings_errors(), 30);
        
        // Redirect back to the main list page.
        $redirect_url = remove_query_arg(['action', 'group_id', 'fsbhoa_toggle_status_nonce'], wp_get_referer());
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Handles the request to delete a group.
     */
    public function handle_delete_group() {
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;

        if ($group_id === 0) {
            wp_die('Invalid group ID.');
        }
        check_admin_referer('fsbhoa_delete_group_action_' . $group_id, 'fsbhoa_delete_group_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to delete groups.');
        }

        global $wpdb;
        $result = $wpdb->delete('ac_groups', ['group_id' => $group_id], ['%d']);

        if ($result === false) {
            add_settings_error('fsbhoa-groups-notices', 'db_error', 'Database error deleting group: ' . $wpdb->last_error, 'error');
        } else {
            add_settings_error('fsbhoa-groups-notices', 'group_deleted', __('Group deleted successfully.', 'fsbhoa-ac'), 'updated');
        }
        set_transient('settings_errors', get_settings_errors(), 30);
        
        $redirect_url = remove_query_arg(['action', 'group_id', 'fsbhoa_delete_group_nonce'], wp_get_referer());
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Handles the form submission for saving or updating a group.
     */
    public function handle_save_group() {
        // 1. Security Checks
        check_admin_referer('fsbhoa_save_group', 'fsbhoa_group_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to save groups.');
        }

        // 2. Data Sanitization
        global $wpdb;
        $group_id = isset($_POST['group_id']) ? absint($_POST['group_id']) : 0;

        $group_data = [
            'group_name'        => sanitize_text_field($_POST['group_name']),
            'group_description' => sanitize_textarea_field($_POST['group_description']),
            'has_all_access'    => isset($_POST['has_all_access']) ? 1 : 0,
            'is_default'        => isset($_POST['is_default']) ? 1 : 0,
            'valid_from'        => empty($_POST['valid_from']) ? '2020-01-01' : sanitize_text_field($_POST['valid_from']),
            'valid_to'          => empty($_POST['valid_to']) ? '2099-12-31' : sanitize_text_field($_POST['valid_to']),
            'parent_group_id'   => isset($_POST['parent_group_id']) && absint($_POST['parent_group_id']) > 0 ? absint($_POST['parent_group_id']) : null,
        ];
        
        // Only set the 'is_enabled' status when creating a new group.
        // For existing groups, this is handled by the toggle action.
        if ($group_id === 0) {
            $group_data['is_enabled'] = 1; // Default new groups to enabled.
        }
        
        // 3. Database Operation
        if ($group_id > 0) {
            $result = $wpdb->update("ac_groups", $group_data, ['group_id' => $group_id]);
        } else {
            $result = $wpdb->insert("ac_groups", $group_data);
            if ($result) {
                $group_id = $wpdb->insert_id;
            }
        }

        if ($result === false) {
             add_settings_error('fsbhoa-groups-notices', 'db_error', 'Database error saving group: ' . $wpdb->last_error, 'error');
             wp_safe_redirect(add_query_arg(['action' => 'edit', 'group_id' => $group_id]));
             exit;
        }
        
        // 4. Process Permissions Sub-table
        $permissions_data = isset($_POST['permissions']) ? (array) $_POST['permissions'] : [];
        $this->save_group_permissions($group_id, $permissions_data);

        // 5. Redirect with success message
        add_settings_error('fsbhoa-groups-notices', 'group_saved', __('Group saved successfully.', 'fsbhoa-ac'), 'updated');
        set_transient('settings_errors', get_settings_errors(), 30);
        
        $redirect_url = wp_get_referer();
        if (!$redirect_url) {
            $redirect_url = wp_unslash($_POST['_wp_http_referer']);
        }
        $redirect_url = remove_query_arg(['action', 'group_id'], $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }
    
    /**
     * Saves the permission rules for a given group.
     * This expands "All Gates" and "Controller" selections into individual gate rules.
     */
    private function save_group_permissions($group_id, $permissions) {
        global $wpdb;
        
        $wpdb->delete("ac_group_permissions", ['group_id' => $group_id]);
        if ($wpdb->last_error) {
            add_settings_error('fsbhoa-groups-notices', 'db_error', 'Database error clearing old permissions: ' . $wpdb->last_error, 'error');
            return;
        }

        if (empty($permissions)) {
            return;
        }

        // Get all doors once to avoid querying in a loop.
        $all_doors = $wpdb->get_results("SELECT door_record_id, controller_record_id FROM ac_doors");
        if ($wpdb->last_error) {
            add_settings_error('fsbhoa-groups-notices', 'db_error', 'Database error fetching doors list: ' . $wpdb->last_error, 'error');
            return;
        }

        foreach ($permissions as $perm) {
            if (empty($perm['door_id']) || empty($perm['start_time']) || empty($perm['end_time'])) {
                continue;
            }

            $target_id = sanitize_text_field($perm['door_id']);
            $doors_to_save = [];

            if ($target_id === 'all') {
                // If "All Gates" is selected, add every door.
                foreach ($all_doors as $door) {
                    $doors_to_save[] = $door->door_record_id;
                }
            } elseif (strpos($target_id, 'controller-') === 0) {
                // If a controller is selected, find all doors for that controller.
                $controller_id = absint(str_replace('controller-', '', $target_id));
                foreach ($all_doors as $door) {
                    if ($door->controller_record_id == $controller_id) {
                        $doors_to_save[] = $door->door_record_id;
                    }
                }
            } else {
                // It's an individual gate.
                $doors_to_save[] = absint(str_replace('gate-', '', $target_id));
            }

            // Now, loop through the resolved list of doors and insert a rule for each one.
            foreach ($doors_to_save as $door_id_to_save) {
                $data_to_insert = [
                    'group_id'   => $group_id,
                    'door_id'    => $door_id_to_save,
                    'is_enabled' => isset($perm['is_enabled']) ? 1 : 0,
                    'start_time' => sanitize_text_field($perm['start_time']),
                    'end_time'   => sanitize_text_field($perm['end_time']),
                    'on_mon'     => isset($perm['on_mon']) ? 1 : 0,
                    'on_tue'     => isset($perm['on_tue']) ? 1 : 0,
                    'on_wed'     => isset($perm['on_wed']) ? 1 : 0,
                    'on_thu'     => isset($perm['on_thu']) ? 1 : 0,
                    'on_fri'     => isset($perm['on_fri']) ? 1 : 0,
                    'on_sat'     => isset($perm['on_sat']) ? 1 : 0,
                    'on_sun'     => isset($perm['on_sun']) ? 1 : 0,
                ];
                $wpdb->insert("ac_group_permissions", $data_to_insert);

                if ($wpdb->last_error) {
                    add_settings_error('fsbhoa-groups-notices', 'db_error', 'Database error saving a permission rule: ' . $wpdb->last_error, 'error');
                    // Stop processing on the first error to avoid flooding with messages.
                    return;
                }
            }
        }
    }
}

