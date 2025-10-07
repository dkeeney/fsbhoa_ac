<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/admin/class-fsbhoa-groups-admin-page.php

if (!defined('WPINC')) {
    die;
}

/**
 * Acts as the controller for the Groups management page rendered by a shortcode.
 *
 * This class is instantiated by the shortcode handler. It is responsible for
 * routing to the correct view (list vs. form) and displaying the page titles.
 */
class FSBHOA_Groups_Admin_Page {

    /**
     * Renders the page content. This is the main entry point called by the shortcode.
     *
     * It acts as a router, deciding whether to show the list of groups
     * or the add/edit form based on the 'action' GET parameter.
     */
    public function render_page() {
        // Determine the current action. Default to 'list'.
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        
        // The ID of the group, if we are editing.
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;

        // Start output buffering to capture the HTML.
        ob_start();

        // Main wrapper for the page content.
        echo '<div class="fsbhoa-admin-page-wrap">';

        // Route to the correct view.
        switch ($action) {
            case 'edit':
            case 'add':
                // Display the 'Add New' or 'Edit' form.
                $page_title = $group_id ? __('Edit Permissions Group', 'fsbhoa-ac') : __('Add New Permissions Group', 'fsbhoa-ac');
                echo '<h1>' . esc_html($page_title) . '</h1>';
                
                // The form HTML is in a separate view file.
                include_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-group-form.php';
                break;

            case 'validate':
                $this->render_validation_page();
                break;

            default:
                // Display the list of groups.
                $add_new_url = add_query_arg(['action' => 'add']);
                $validate_url = add_query_arg(['action' => 'validate']); // URL for our new page
                
                echo '<h1>' . esc_html__('Permissions Groups', 'fsbhoa-ac') . 
                     ' <a href="' . esc_url($add_new_url) . '" class="button button-primary">' . esc_html__('Add New', 'fsbhoa-ac') . '</a>' .
                     ' <a href="' . esc_url($validate_url) . '" class="button button-secondary">' . esc_html__('Validate Permissions', 'fsbhoa-ac') . '</a></h1>';

                // The list table is in a separate view file.
                include_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-group-list.php';
                break;
        }

        echo '</div>'; // close .fsbhoa-admin-page-wrap

        // Return the captured HTML.
        return ob_get_clean();
    }


    /**
     * Renders the Permission Validation tool page.
     */
    public function render_validation_page() {
        echo '<h1>' . esc_html__('Validate Group Permissions', 'fsbhoa-ac') . '</h1>';
        echo '<p>' . esc_html__('This tool checks for permission combinations that could result in more than 3 time segments per day for a single door, which the hardware does not support.', 'fsbhoa-ac') . '</p>';

        $check_type = isset($_POST['check_type']) ? sanitize_key($_POST['check_type']) : '';
        $results = null;

        if (!empty($check_type)) {
            // Verify nonce before running a check
            check_admin_referer('fsbhoa_validate_permissions_nonce');
            $results = $this->run_permission_validation($check_type);
        }
        ?>
        <div class="fsbhoa-validation-controls">
            <form method="POST">
                <input type="hidden" name="check_type" value="exhaustive">
                <?php wp_nonce_field('fsbhoa_validate_permissions_nonce'); ?>
                <button type="submit" class="button button-primary">Run Exhaustive Validation Check</button>
            </form>
        </div>
        <div class="fsbhoa-validation-results" style="margin-top: 2em;">
            <?php if ($results !== null) : ?>
                <h2>Results</h2>
                <?php if (empty($results)) : ?>
                    <div class="notice notice-success is-dismissible"><p><strong>Success!</strong> No invalid time segment combinations were found.</p></div>
                <?php else : ?>
                    <div class="notice notice-warning is-dismissible">
                        <p><strong>Warning!</strong> The following group combinations result in more than 3 time segments for at least one door and may not sync correctly:</p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <?php foreach ($results as $result) : ?>
                                <li><strong>Groups:</strong> <?php echo esc_html(implode(', ', $result['group_names'])); ?><br>
                                    <small><strong>Affected Cardholders:</strong> <?php echo esc_html($result['user_info']); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Runs the main permission validation logic.
     */
    private function run_permission_validation($mode = 'standard') { // Keep $mode for future use
        global $wpdb;
        $permission_data = fsbhoa_get_all_permission_data();
        if (!$permission_data) { return [['group_names' => ['Error'], 'user_info' => 'Could not fetch permission data.']]; }

        $all_groups = $wpdb->get_results("SELECT group_id, group_name FROM ac_groups", OBJECT_K);
        $combinations_to_check = [];

        // Generate all possible combinations of groups for the exhaustive check
        $all_group_ids = array_keys($all_groups);
        $all_combinations = $this->get_all_combinations($all_group_ids);
        foreach ($all_combinations as $combo) {
            if (empty($combo)) continue; // Skip the empty set
            sort($combo);
            $key = implode(',', $combo);
            $combinations_to_check[$key] = ['group_ids' => $combo];
        }
        
        $problems = [];
        foreach ($combinations_to_check as $key => $combo_data) {
            $temp_cardholder_id = -1; // Use a fake ID for calculation
            
            $temp_permission_data = $permission_data;
            $temp_permission_data['groups_by_cardholder'][$temp_cardholder_id] = $combo_data['group_ids'];

            $calculated_perms = fsbhoa_calculate_cardholder_permissions($temp_cardholder_id, $temp_permission_data);
            if (!$calculated_perms || isset($calculated_perms['all_access'])) { continue; }

            $perms_by_door = [];
            foreach ($calculated_perms as $perm) {
                $perms_by_door[$perm->door_id][] = $perm;
            }

            foreach ($perms_by_door as $door_id => $door_perms) {
                $segments = [];
                foreach($door_perms as $p) { $segments[] = substr($p->start_time, 0, 5) . '-' . substr($p->end_time, 0, 5); }
                $merged_segments = fsbhoa_merge_time_windows($segments);
                if (count($merged_segments) > 3) {
                    $group_names = [];
                    foreach($combo_data['group_ids'] as $gid) { $group_names[] = $all_groups[$gid]->group_name ?? 'Unknown'; }
                    
                    $problems[$key] = [
                        'group_names' => $group_names,
                        'user_info' => 'This combination of groups creates a schedule that is too complex.'
                    ];
                    break; 
                }
            }
        }

        return $problems;
    }

    /**
     * Helper function to generate all possible combinations of a set.
     */
    private function get_all_combinations($array) {
        $results = [[]];
        foreach ($array as $element) {
            foreach ($results as $combination) {
                $results[] = array_merge([$element], $combination);
            }
        }
        return array_slice($results, 1); // Remove the initial empty set
    }
}

