<?php
// FILE: fsbhoa-permission-functions.php - DEFINITIVE LINKED PROFILE VERSION

if (!defined('WPINC')) { die; }

/**
 * Fetches all necessary group and permission data from the database.
 */
function fsbhoa_get_all_permission_data($schedule_id = 1) {
    global $wpdb;
    $all_groups = $wpdb->get_results("SELECT * FROM ac_groups WHERE is_enabled = 1", OBJECT_K);
    if ($wpdb->last_error) { return false; }

    $permissions_query = $wpdb->prepare(
        "SELECT group_id, controller_id, door_id, start_time, end_time, on_mon, on_tue, on_wed, on_thu, on_fri, on_sat, on_sun FROM ac_group_permissions WHERE is_enabled = 1 AND schedule_id = %d",
        $schedule_id
    );
    $all_permissions = $wpdb->get_results($permissions_query);
    if ($wpdb->last_error) { return false; }

    $memberships_query = "SELECT DISTINCT cardholder_id, group_id FROM ac_cardholder_groups";
    $all_memberships = $wpdb->get_results($memberships_query);
    if ($wpdb->last_error) { return false; }

    $permissions_by_group = [];
    foreach ($all_permissions as $perm) { $permissions_by_group[$perm->group_id][] = $perm; }

    $groups_by_cardholder = [];
    foreach ($all_memberships as $member) { $groups_by_cardholder[$member->cardholder_id][] = $member->group_id; }

    return [
        'groups'                 => $all_groups,
        'permissions_by_group'   => $permissions_by_group,
        'groups_by_cardholder'   => $groups_by_cardholder,
    ];
}

/**
 * The main permission calculation engine for a single cardholder.
 */
function fsbhoa_calculate_cardholder_permissions($cardholder_id, $permission_data) {
    global $wpdb;
    $base_group_ids = $permission_data['groups_by_cardholder'][$cardholder_id] ?? [];
    if (empty($base_group_ids)) { return null; }

    foreach ($base_group_ids as $group_id) {
        if (isset($permission_data['groups'][$group_id])) {
            $group = $permission_data['groups'][$group_id];
            if (!empty($group->has_all_access) && $group->has_all_access) {
                return ['all_access' => true];
            }
        }
    }

    $rules_to_union = [];
    foreach ($base_group_ids as $group_id) {
        $final_perms_for_group = fsbhoa_get_final_permissions_for_group($group_id, $permission_data);
        if (!empty($final_perms_for_group)) {
             $rules_to_union = array_merge($rules_to_union, $final_perms_for_group);
        }
    }
    if (empty($rules_to_union)) { return null; }

    $perms_by_door = [];
    foreach ($rules_to_union as $perm) { $perms_by_door[$perm->door_id][] = $perm; }

    $final_permissions = [];
    foreach ($perms_by_door as $door_id => $perms_for_door) {
        $merged_perm = new stdClass();
        $merged_perm->door_id = $door_id;
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        foreach ($days as $day) { $merged_perm->{'on_' . $day} = false; }
        
        $windows_by_day = [];
        foreach ($perms_for_door as $p) {
            foreach ($days as $day) {
                if ($p->{'on_' . $day}) {
                    $merged_perm->{'on_' . $day} = true;
                    $windows_by_day[$day][] = substr($p->start_time, 0, 5) . '-' . substr($p->end_time, 0, 5);
                }
            }
        }
        
        $final_day_schedules = [];
        foreach($windows_by_day as $day => $windows) {
            $merged = fsbhoa_merge_time_windows($windows);
            $final_day_schedules[$day] = $merged;
        }
        $merged_perm->schedules = $final_day_schedules;
        $final_permissions[] = $merged_perm;
    }
    return $final_permissions;
}

/**
 * The "Unit of Work" function. Calculates the final permissions for a SINGLE group.
 */
function fsbhoa_get_final_permissions_for_group($group_id, $permission_data) {
    global $wpdb;
    $rules_for_hierarchy = $permission_data['permissions_by_group'][$group_id] ?? [];
    if (empty($rules_for_hierarchy)) { return []; }
    
    $expanded_perms_by_door = [];
    foreach ($rules_for_hierarchy as $rule) {
        $doors_to_add = [];
        $specificity = 1;
        if ($rule->controller_id === null && $rule->door_id === null) {
            $specificity = 1; $doors_to_add = $wpdb->get_col("SELECT door_record_id FROM ac_doors");
        } elseif ($rule->controller_id !== null && $rule->door_id === null) {
            $specificity = 2; $doors_to_add = $wpdb->get_col($wpdb->prepare("SELECT door_record_id FROM ac_doors WHERE controller_record_id = %d", $rule->controller_id));
        } elseif ($rule->door_id !== null) {
            $specificity = 3; $doors_to_add[] = $rule->door_id;
        }
        foreach ($doors_to_add as $door_id) {
            $permission_copy = clone $rule;
            $permission_copy->door_id = $door_id;
            $permission_copy->specificity = $specificity;
            $expanded_perms_by_door[$door_id][] = $permission_copy;
        }
    }
    
    $final_permissions = [];
    foreach ($expanded_perms_by_door as $door_id => $perms_for_door) {
        $max_specificity = 0;
        foreach ($perms_for_door as $p) { $max_specificity = max($max_specificity, $p->specificity); }
        foreach ($perms_for_door as $p) {
            if ($p->specificity === $max_specificity) { $final_permissions[] = $p; }
        }
    }
    return $final_permissions;
}

/**
 * Builds a map of all unique schedule chains needed for the sync.
 */
function fsbhoa_build_profile_chains($all_cardholders_permissions) {
    $chains_by_door = [];
    foreach ($all_cardholders_permissions as $cardholder_perms) {
        if ($cardholder_perms && !isset($cardholder_perms['all_access'])) {
            foreach ($cardholder_perms as $perm) {
                if (!empty($perm->schedules)) {
                    $schedules_by_signature = [];
                    foreach((array)$perm->schedules as $day => $segments) {
                        $signature = implode(',', $segments);
                        $schedules_by_signature[$signature][] = $day;
                    }

                    if (!isset($chains_by_door[$perm->door_id])) {
                        $chains_by_door[$perm->door_id] = [];
                    }

                    foreach($schedules_by_signature as $sig => $days) {
                         $chains_by_door[$perm->door_id][$sig] = array_unique(array_merge($chains_by_door[$perm->door_id][$sig] ?? [], $days));
                    }
                }
            }
        }
    }
    return $chains_by_door;
}

/**
 * Uploads all necessary time profiles to a controller, creating linked chains.
 */
function fsbhoa_sync_time_profiles($device_id, $chains_by_door, $is_dry_run = false) {
    $profile_map = []; // Final map: [door_id => entry_profile_id]
    $schedule_to_profile_id = []; // Cache: [full_signature => profile_id]
    $profile_id_counter = 2;

    if (!$is_dry_run) { shell_exec(sprintf('uhppote-cli clear-time-profiles %s 2>&1', $device_id)); } 
    else { error_log("DRY RUN: Would execute: uhppote-cli clear-time-profiles " . $device_id); }

    // First pass: Pre-assign a unique profile ID to every single unique daily schedule across all doors.
    foreach ($chains_by_door as $door_id => $schedules) {
        foreach ($schedules as $signature => $days) {
            sort($days);
            $full_signature = implode(':', $days) . '|' . $signature;
            if (!isset($schedule_to_profile_id[$full_signature])) {
                if ($profile_id_counter > 254) { return []; }
                $schedule_to_profile_id[$full_signature] = $profile_id_counter++;
            }
        }
    }

    // Second pass: Upload the profiles for each door, creating linked chains.
    $uploaded_profiles = []; 
    foreach ($chains_by_door as $door_id => $schedules) {
        $schedule_keys = array_keys($schedules);
        $entry_profile_id = 0;
        $linked_profile_id = 0; // The end of any chain links to 0 (none).

        // Build the chain in REVERSE order of the schedules array.
        for ($i = count($schedule_keys) - 1; $i >= 0; $i--) {
            $signature = $schedule_keys[$i];
            $days = $schedules[$signature];
            sort($days);
            $full_signature = implode(':', $days) . '|' . $signature;
            $current_profile_id = $schedule_to_profile_id[$full_signature] ?? 0;
            if (!$current_profile_id) continue;
            
            $entry_profile_id = $current_profile_id; // The last one we process is the entry point.

            if (!in_array($current_profile_id, $uploaded_profiles)) {
                $weekdays = implode(',', array_map('ucfirst', $days));
                $command = sprintf("uhppote-cli set-time-profile %s %d %s %s '%s' %d", $device_id, $current_profile_id, '2020-01-01:2099-12-31', $weekdays, $signature, $linked_profile_id);

                if ($is_dry_run) { error_log("DRY RUN: Would execute: " . $command); } 
                else { shell_exec($command . " 2>&1"); }
                $uploaded_profiles[] = $current_profile_id;
            }
            // The profile we just created becomes the one the *next* one in the chain will link to.
            $linked_profile_id = $current_profile_id;
        }
        $profile_map[$door_id] = $entry_profile_id;
    }
    return $profile_map;
}

/**
 * Builds the final permission string for a single cardholder.
 */
function fsbhoa_build_card_permissions_string($cardholder, $cardholder_permissions, $profile_map) {
    if ($cardholder->card_status === 'disabled') { return ''; }
    if (!$cardholder_permissions) { return ''; }
    if (isset($cardholder_permissions['all_access'])) { return "1:Y,2:Y,3:Y,4:Y"; }

    global $wpdb;
    $door_perms = []; 
    foreach ($cardholder_permissions as $perm) {
        $door_obj = $wpdb->get_row($wpdb->prepare("SELECT door_number_on_controller FROM ac_doors WHERE door_record_id = %d", $perm->door_id));
        if ($door_obj && isset($profile_map[$perm->door_id])) {
            $door_perms[$door_obj->door_number_on_controller] = $profile_map[$perm->door_id];
        }
    }
    ksort($door_perms);
    $final_perms = [];
    foreach ($door_perms as $door_num => $profile_id) { $final_perms[] = $door_num . ':' . $profile_id; }
    return implode(',', $final_perms);
}

/**
 * Helper function to merge overlapping time windows.
 */
function fsbhoa_merge_time_windows($windows) {
    if (empty($windows) || count($windows) < 2) { return $windows ?? []; }
    $timestamps = [];
    foreach ($windows as $window) {
        if(empty($window)) continue;
        list($start, $end) = explode('-', $window);
        $timestamps[] = [strtotime($start), strtotime($end)];
    }
    if (empty($timestamps)) { return []; }
    usort($timestamps, function($a, $b) { return $a[0] <=> $b[0]; });
    $merged = [$timestamps[0]];
    for ($i = 1; $i < count($timestamps); $i++) {
        $last_merged = &$merged[count($merged) - 1];
        if ($timestamps[$i][0] <= $last_merged[1]) {
            $last_merged[1] = max($last_merged[1], $timestamps[$i][1]);
        } else {
            $merged[] = $timestamps[$i];
        }
    }
    $result = [];
    foreach ($merged as $ts) { $result[] = date('H:i', $ts[0]) . '-' . date('H:i', $ts[1]); }
    return $result;
}



/**
 * Determines the currently active schedule ID.
 * Looks for a holiday schedule for the current date, otherwise returns 1 for Default.
 * @return int The active schedule ID.
 */
function fsbhoa_get_active_schedule_id() {
    global $wpdb;
    $active_id = $wpdb->get_var(
        "SELECT schedule_id FROM ac_schedules WHERE is_default = 0 AND NOW() BETWEEN start_date AND DATE_ADD(end_date, INTERVAL 1 DAY) ORDER BY start_date DESC LIMIT 1"
    );
    return $active_id ? absint($active_id) : 1;
}
