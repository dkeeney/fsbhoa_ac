<?php
if (!defined('WPINC')) { die; }

/**
 * Acts as the controller for the Schedule-specific Groups management editor.
 */
class Fsbhoa_Schedule_Groups_Page {

    /**
     * Renders the page content. This is the main entry point.
     * It decides whether to show the form for adding/editing a permission rule.
     */
    public function render_page() {
        // Get the schedule ID from the URL, defaulting to 1 (Default Schedule)
        $schedule_id = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 1;
        $group_id = isset($_GET['group_id']) ? absint($_GET['group_id']) : 0;

        ob_start();
        echo '<div class="fsbhoa-frontend-wrap">';

        $page_title = $group_id ? __('Edit Permissions Group Schedule', 'fsbhoa-ac') : __('Add New Permissions Group Schedule', 'fsbhoa-ac');
        echo '<h1>' . esc_html($page_title) . '</h1>';
        
        // The form HTML is in a separate view file.
        // We will create a new, schedule-aware version of this form in the next step.
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-schedule-group-form.php';
        
        return ob_get_clean();
    }
}
