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

            default:
                // Display the list of groups.
                $add_new_url = add_query_arg(['action' => 'add']);
                echo '<h1>' . esc_html__('Permissions Groups', 'fsbhoa-ac') . ' <a href="' . esc_url($add_new_url) . '" class="button button-primary">' . esc_html__('Add New', 'fsbhoa-ac') . '</a></h1>';
                
                // The list table is in a separate view file.
                include_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-group-list.php';
                break;
        }

        echo '</div>'; // close .fsbhoa-admin-page-wrap

        // Return the captured HTML.
        return ob_get_clean();
    }
}

