<?php
// File: includes/admin/views/view-group-schedule-visualizer.php
// REVISED to use new permission functions

if (!defined('WPINC')) { die; }

// Ensure the necessary functions are loaded
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-permission-functions.php';

/**
 * Renders a visual timeline of the final permissions for the group being edited.
 * @var int $group_id The ID of the current group.
 * @var int $schedule_id The ID of the current schedule.
 */

// Add guard clauses to prevent crashes on pages where variables aren't set
if (!isset($schedule_id)) {
    $schedule_id = fsbhoa_get_active_schedule_id(); // Use active schedule
}
if (!isset($group_id) || $group_id === 0) {
    // If no valid group ID, show an empty schedule visualization
    $group_id = 0;
    $final_perms_for_group = []; // Initialize as empty
} else {
    // 1. Get ALL permission data (includes groups, raw permissions, memberships)
    $permission_data = fsbhoa_get_all_permission_data($schedule_id);

    // 2. We need door data to resolve specificity correctly
    global $wpdb;
    $all_door_data = $wpdb->get_results("SELECT door_record_id, controller_record_id, door_number_on_controller FROM ac_doors", OBJECT_K) ?: [];

    // 3. Create the structure needed for the raw permission calculator
    //    For a single group, the signature is just the group ID, and the map contains only that group.
    $group_sig = strval($group_id);
    $sig_to_groups = [$group_sig => [$group_id]];

    // 4. Calculate the raw, specific rules for THIS group set on THIS schedule
    $raw_perm_sets = fsbhoa_calculate_raw_permissions_for_sets($sig_to_groups, $permission_data, $all_door_data);

    // 5. Extract the final rules for the specific group ID
    //    This gives us [door_id => [rule1, rule2]] similar to the old structure
    $final_perms_for_group = $raw_perm_sets[$group_sig]['perms'] ?? [];
}


// --- Process the results into a display-ready format (This logic remains largely the same) ---
$schedule_by_door = [];
$days_of_week = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

// Check the structure returned by the new function
if (!empty($final_perms_for_group)) {
    // The new structure is already grouped by door_id
    foreach ($final_perms_for_group as $door_id => $rules_for_door) {
        $windows_by_day = [];
        foreach ($rules_for_door as $rule) {
            foreach ($days_of_week as $day) {
                if (!empty($rule->{'on_' . $day})) {
                    // Make sure start_time and end_time exist
                    if (isset($rule->start_time) && isset($rule->end_time)) {
                       $windows_by_day[$day][] = substr($rule->start_time, 0, 5) . '-' . substr($rule->end_time, 0, 5);
                    }
                }
            }
        }

        foreach ($windows_by_day as $day => $windows) {
            // Ensure fsbhoa_merge_time_windows exists before calling
            if (function_exists('fsbhoa_merge_time_windows')) {
                 $schedule_by_door[$door_id][$day] = fsbhoa_merge_time_windows($windows);
            } else {
                 // Fallback or error logging if function missing
                 error_log("Visualizer Error: fsbhoa_merge_time_windows function not found!");
                 $schedule_by_door[$door_id][$day] = $windows; // Show unmerged windows as fallback
            }
        }
    }
}

// Get a list of all doors to display.
// This DB call was already here and is correct.
if (!isset($wpdb)) { global $wpdb; } // Ensure $wpdb is available
$all_doors_display = $wpdb->get_results("SELECT door_record_id, friendly_name FROM ac_doors ORDER BY friendly_name ASC");

?>
<style>
    /* --- Styles for the Schedule Visualizer (keep existing styles) --- */
    .schedule-visualizer { margin-top: 1.5em; border-top: 1px solid #ddd; padding-top: 1em; }
    .schedule-gate-row { margin-bottom: 1.5em; }
    .schedule-gate-row h4 { margin: 0 0 0.5em 0; font-size: 14px; }
    .schedule-day-row { display: flex; align-items: center; margin-bottom: 4px; font-size: 12px; }
    .schedule-day-label { flex-basis: 40px; font-weight: bold; color: #555; text-transform: capitalize; flex-shrink: 0;} /* Added flex-shrink */
    .timeline { flex-grow: 1; position: relative; background-color: #f0f0f0; border: 1px solid #ccc; height: 20px; box-sizing: border-box; min-width: 200px;} /* Added min-width */
    .timeline-segment { position: absolute; background-color: #2271b1; height: 100%; box-sizing: border-box; border-right: 1px solid #fff; }
    .timeline-segment:hover { background-color: #0a4b78; }
    .timeline-segment .segment-tooltip { visibility: hidden; background: #333; color: #fff; text-align: center; padding: 4px 8px; border-radius: 4px; position: absolute; z-index: 1; bottom: 125%; left: 50%; margin-left: -40px; width: 80px; opacity: 0; transition: opacity 0.3s; }
    .timeline-segment:hover .segment-tooltip { visibility: visible; opacity: 1; }
    .timeline-ruler-wrapper { display: flex; margin-bottom: 8px; }
    .ruler-spacer { flex-basis: 40px; flex-shrink: 0; }
    .timeline-ruler { flex-grow: 1; position: relative; height: 30px; border-bottom: 1px solid #999; min-width: 200px; } /* Added min-width */
    .ruler-tick { position: absolute; bottom: 0; width: 1px; background-color: #ccc; }
    .ruler-tick.hour { height: 5px; }
    .ruler-tick.quarter-day { height: 10px; background-color: #999; }
    .ruler-label { position: absolute; bottom: 12px; font-size: 10px; color: #555; }
</style>

<div class="schedule-visualizer">
    <h2>Final Schedule Preview</h2>
    <p class="description">This shows the final, calculated schedule for this group after applying specificity rules for the selected schedule (<?php echo esc_html($schedule_id == 1 ? 'Default' : 'Holiday'); ?>).</p>

    <div class="timeline-ruler-wrapper">
        <div class="ruler-spacer"></div>
        <div class="timeline-ruler">
            <?php for ($i = 0; $i <= 24; $i++) :
                $tick_class = 'hour';
                if ($i % 6 === 0) $tick_class = 'quarter-day';
            ?>
                <div class="ruler-tick <?php echo $tick_class; ?>" style="left: <?php echo ($i / 24) * 100; ?>%;"></div>
            <?php endfor; ?>
            <div class="ruler-label" style="left: 0%;">12am</div>
            <div class="ruler-label" style="left: 25%; transform: translateX(-50%);">6am</div>
            <div class="ruler-label" style="left: 50%; transform: translateX(-50%);">12pm</div>
            <div class="ruler-label" style="left: 75%; transform: translateX(-50%);">6pm</div>
            <div class="ruler-label" style="right: 0; transform: translateX(50%);">12am</div>
        </div>
    </div>

    <?php if (empty($all_doors_display)) : ?>
        <p>No gates have been configured yet.</p>
    <?php else : foreach ($all_doors_display as $door) : ?>
        <div class="schedule-gate-row">
            <h4><?php echo esc_html($door->friendly_name); ?></h4>
            <?php foreach ($days_of_week as $day) : ?>
                <div class="schedule-day-row">
                    <div class="schedule-day-label"><?php echo esc_html($day); ?></div>
                    <div class="timeline">
                        <?php
                        // Use the correctly processed $schedule_by_door data
                        if (isset($schedule_by_door[$door->door_record_id][$day])) {
                            foreach ($schedule_by_door[$door->door_record_id][$day] as $segment_string) {
                                // Ensure segment string is valid before exploding
                                if (strpos($segment_string, '-') !== false && strpos($segment_string, ':') !== false) {
                                    list($start_time, $end_time) = explode('-', $segment_string);
                                    list($start_h, $start_m) = explode(':', $start_time);
                                    list($end_h, $end_m) = explode(':', $end_time);

                                    // Basic validation
                                    if (is_numeric($start_h) && is_numeric($start_m) && is_numeric($end_h) && is_numeric($end_m)) {
                                        $start_total_minutes = (intval($start_h) * 60) + intval($start_m);
                                        $end_total_minutes = (intval($end_h) * 60) + intval($end_m);

                                        // Handle overnight wrap-around (e.g., 22:00-02:00 should still render correctly)
                                        // The original code treated end <= start as spanning to midnight (1440).
                                        // Let's keep that logic for consistency unless it causes issues.
                                        if ($end_total_minutes <= $start_total_minutes) { $end_total_minutes = 1440; } // End of day

                                        $duration_minutes = $end_total_minutes - $start_total_minutes;
                                        if ($duration_minutes < 0) $duration_minutes = 0; // Sanity check

                                        $total_day_minutes = 1440;
                                        $left_percent = ($start_total_minutes / $total_day_minutes) * 100;
                                        $width_percent = ($duration_minutes / $total_day_minutes) * 100;

                                        // Ensure width isn't negative or excessively large
                                        $width_percent = max(0, min(100 - $left_percent, $width_percent));

                                        echo '<div class="timeline-segment" style="left: ' . esc_attr($left_percent) . '%; width: ' . esc_attr($width_percent) . '%;">';
                                        echo '<span class="segment-tooltip">' . esc_html($segment_string) . '</span>';
                                        echo '</div>';
                                    }
                                }
                            }
                        }
                        ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; endif; ?>
</div>
