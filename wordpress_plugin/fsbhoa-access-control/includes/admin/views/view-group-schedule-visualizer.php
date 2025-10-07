<?php
// File: includes/admin/views/view-group-schedule-visualizer.php - DEFINITIVE VERSION

if (!defined('WPINC')) { die; }

/**
 * Renders a visual timeline of the final permissions for the group being edited.
 * @var int $group_id The ID of the current group.
 */

// 1. Get the final, calculated permissions for THIS group using the correct function.
$permission_data = fsbhoa_get_all_permission_data();
$final_perms_for_group = fsbhoa_get_final_permissions_for_group($group_id, $permission_data);

// 2. Process the results into a display-ready format.
$schedule_by_door = [];
$days_of_week = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

if (!empty($final_perms_for_group)) {
    // First, group all the winning rules by the door they apply to.
    $perms_by_door = [];
    foreach ($final_perms_for_group as $perm) {
        $perms_by_door[$perm->door_id][] = $perm;
    }
    
    // Now, for each door, build its unique daily schedules.
    foreach ($perms_by_door as $door_id => $rules_for_door) {
        $windows_by_day = [];
        foreach ($rules_for_door as $rule) {
            foreach ($days_of_week as $day) {
                if (!empty($rule->{'on_' . $day})) {
                    $windows_by_day[$day][] = substr($rule->start_time, 0, 5) . '-' . substr($rule->end_time, 0, 5);
                }
            }
        }
        
        // For each day, merge its time windows into final segments.
        foreach ($windows_by_day as $day => $windows) {
            $schedule_by_door[$door_id][$day] = fsbhoa_merge_time_windows($windows);
        }
    }
}

// 3. Get a list of all doors to display.
global $wpdb;
$all_doors = $wpdb->get_results("SELECT door_record_id, friendly_name FROM ac_doors ORDER BY friendly_name ASC");
?>
<style>
    /* --- Styles for the Schedule Visualizer --- */
    .schedule-visualizer { margin-top: 1.5em; border-top: 1px solid #ddd; padding-top: 1em; }
    .schedule-gate-row { margin-bottom: 1.5em; }
    .schedule-gate-row h4 { margin: 0 0 0.5em 0; font-size: 14px; }
    .schedule-day-row { display: flex; align-items: center; margin-bottom: 4px; font-size: 12px; }
    .schedule-day-label { flex-basis: 40px; font-weight: bold; color: #555; text-transform: capitalize; }
    .timeline { flex-grow: 1; position: relative; background-color: #f0f0f0; border: 1px solid #ccc; height: 20px; box-sizing: border-box; }
    .timeline-segment { position: absolute; background-color: #2271b1; height: 100%; box-sizing: border-box; border-right: 1px solid #fff; }
    .timeline-segment:hover { background-color: #0a4b78; }
    .timeline-segment .segment-tooltip { visibility: hidden; background: #333; color: #fff; text-align: center; padding: 4px 8px; border-radius: 4px; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -40px; width: 80px; opacity: 0; transition: opacity 0.3s; }
    .timeline-segment:hover .segment-tooltip { visibility: visible; opacity: 1; }
</style>

<div class="schedule-visualizer">
    <h2>Final Schedule Preview</h2>
    <p class="description">This shows the final, calculated schedule for this group after all specificity and inheritance rules have been applied.</p>

    <?php foreach ($all_doors as $door) : ?>
        <div class="schedule-gate-row">
            <h4><?php echo esc_html($door->friendly_name); ?></h4>
            <?php foreach ($days_of_week as $day) : ?>
                <div class="schedule-day-row">
                    <div class="schedule-day-label"><?php echo esc_html($day); ?></div>
                    <div class="timeline">
                        <?php
                        if (isset($schedule_by_door[$door->door_record_id][$day])) {
                            foreach ($schedule_by_door[$door->door_record_id][$day] as $segment_string) {
                                list($start_time, $end_time) = explode('-', $segment_string);
                                
                                list($start_h, $start_m) = explode(':', $start_time);
                                list($end_h, $end_m) = explode(':', $end_time);
                                $start_total_minutes = ($start_h * 60) + $start_m;
                                $end_total_minutes = ($end_h * 60) + $end_m;

                                if ($end_total_minutes <= $start_total_minutes) { $end_total_minutes = 1440; }
                                
                                $duration_minutes = $end_total_minutes - $start_total_minutes;
                                $total_day_minutes = 1440;

                                $left_percent = ($start_total_minutes / $total_day_minutes) * 100;
                                $width_percent = ($duration_minutes / $total_day_minutes) * 100;

                                echo '<div class="timeline-segment" style="left: ' . esc_attr($left_percent) . '%; width: ' . esc_attr($width_percent) . '%;">';
                                echo '<span class="segment-tooltip">' . esc_html($segment_string) . '</span>';
                                echo '</div>';
                            }
                        }
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
