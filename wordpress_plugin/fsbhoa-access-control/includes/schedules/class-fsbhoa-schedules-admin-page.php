<?php
// Final complete version of includes/schedules/class-fsbhoa-schedules-admin-page.php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Schedules_Admin_Page {

    private $schedules;
    private $active_schedule_id;

    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        ?>
        <div class="fsbhoa-frontend-wrap fsbhoa-schedules-page">
        <?php
        switch ($action) {
            case 'add_schedule':
                echo '<h1>Add New Schedule</h1>';
                require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-schedule-form.php';
                fsbhoa_render_schedule_form();
                break;

            case 'add_group_schedule':
            case 'edit_group_schedule':
                require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/class-fsbhoa-schedule-groups-page.php';
                $group_schedule_page = new Fsbhoa_Schedule_Groups_Page();
                echo $group_schedule_page->render_page();
                break;
            
            case 'add_task_schedule':
            case 'edit_task_schedule':
                require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/class-fsbhoa-schedule-tasks-page.php';
                $task_schedule_page = new Fsbhoa_Schedule_Tasks_Page();
                $task_schedule_page->render_page();
                break;
            
            default:
                $this->render_tabs_and_content();
                break;
        }
        ?>
        </div>
        <?php
    }

    private function render_tabs_and_content() {
        global $wpdb;
        $this->schedules = $wpdb->get_results("SELECT * FROM ac_schedules ORDER BY is_default DESC, start_date ASC, name ASC");
        if ($wpdb->last_error) { echo '<div class="notice notice-error"><p>Database Error: ' . esc_html($wpdb->last_error) . '</p></div>'; return; }

        $this->active_schedule_id = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 1;
        $schedules_page_url = get_permalink(get_page_by_path('schedules'));
        ?>
        <h1>Schedules</h1>
        <h2 class="nav-tab-wrapper">
            <?php foreach ($this->schedules as $schedule) : ?>
                <a href="<?php echo esc_url(add_query_arg('schedule_id', $schedule->schedule_id, $schedules_page_url)); ?>"
                   class="nav-tab <?php echo ($this->active_schedule_id == $schedule->schedule_id) ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($schedule->name); ?>
                    <?php if (!$schedule->is_default) : ?><span class="fsbhoa-tab-delete-x">ⓧ</span><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(add_query_arg('action', 'add_schedule', $schedules_page_url)); ?>" class="nav-tab" id="fsbhoa-add-schedule-tab">+ Add Schedule</a>
        </h2>
        <div class="fsbhoa-schedule-content-wrap" style="margin-top: 1em;">
            <?php $this->render_tab_content(); ?>
        </div>
        <?php
    }
    
    private function render_tab_content() {
        $schedules_page_url = get_permalink(get_page_by_path('schedules'));
        
        if ($this->active_schedule_id == 1) {
            // --- Renders the Default Tab Content ---
            $add_group_url = add_query_arg(['action' => 'add_group_schedule', 'schedule_id' => 1], $schedules_page_url);
            $add_task_url = add_query_arg(['action' => 'add_task_schedule', 'schedule_id' => 1], $schedules_page_url);

            ?>
            <div class="fsbhoa-section-header">
                <h2>Permission Group Schedules</h2>
                <a href="<?php echo esc_url($add_group_url); ?>" class="button button-primary">Add New Group</a>
            </div>
            <?php
            require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-schedule-groups-list.php';
            fsbhoa_render_schedule_groups_list(1);
            ?>
            <div class="fsbhoa-section-header" style="margin-top: 40px;">
                <h2>Automated Task Schedules</h2>
                <a href="<?php echo esc_url($add_task_url); ?>" class="button button-primary">Add New Task</a>
            </div>
            <?php
            require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-schedule-tasks-list.php';
            fsbhoa_render_schedule_tasks_list(1);

        } else {
            // --- Renders the Holiday Tab Content ---
            global $wpdb;
            $schedule_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_schedules WHERE schedule_id = %d", $this->active_schedule_id));
            
            if ($schedule_data) {
                require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-schedule-header-controls.php';
                fsbhoa_render_schedule_header_controls($schedule_data, $this->schedules, null); // Pass null for group_data for now

                // We'll add an "Add Rule" button here in a later step
                echo '<h2 style="margin-top: 40px;">Permission Group Schedules for ' . esc_html($schedule_data->name) . '</h2>';
                require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-schedule-groups-list.php';
                fsbhoa_render_schedule_groups_list($this->active_schedule_id);

                // We'll add an "Add Task" button here in a later step
                echo '<h2 style="margin-top: 40px;">Automated Task Schedules for ' . esc_html($schedule_data->name) . '</h2>';
                require_once FSBHOA_AC_PLUGIN_DIR . 'includes/schedules/views/view-schedule-tasks-list.php';
                fsbhoa_render_schedule_tasks_list($this->active_schedule_id);
            } else {
                echo '<div class="notice notice-error"><p>Error: Could not find schedule data.</p></div>';
            }
        }
    }
}
