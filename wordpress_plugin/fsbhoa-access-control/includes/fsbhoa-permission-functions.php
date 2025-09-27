<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/fsbhoa-permission-functions.php

/**
 * Contains all helper functions related to calculating and syncing
 * group-based permissions for the UHPPOTE controllers.
 */
if (!defined('WPINC')) {
    die;
}

/**
 * Fetches all necessary group and permission data from the database.
 */
function fsbhoa_get_all_permission_data() {
    global $wpdb;
    $today = date('Y-m-d');

    $groups_query = $wpdb->prepare("SELECT * FROM ac_groups WHERE is_enabled = 1 AND valid_from <= %s AND valid_to >= %s", $today, $today);
    $all_groups = $wpdb->get_results($groups_query, OBJECT_K);
    if ($wpdb->last_error) { return false; }

    $permissions_query = "SELECT * FROM ac_group_permissions WHERE is_enabled = 1";
    $all_permissions = $wpdb->get_results($permissions_query);
    if ($wpdb->last_error) { return false; }

    $memberships_query = "SELECT DISTINCT * FROM ac_cardholder_groups";
    $all_memberships = $wpdb->get_results($memberships_query);
    if ($wpdb->last_error) { return false; }

    $permissions_by_group = [];
    foreach ($all_permissions as $perm) {
        $permissions_by_group[$perm->group_id][] = $perm;
    }

    $groups_by_cardholder = [];
    foreach ($all_memberships as $member) {
        $groups_by_cardholder[$member->cardholder_id][] = $member->group_id;
    }

    return [
        'groups' => $all_groups,
        'permissions_by_group' => $permissions_by_group,
        'groups_by_cardholder' => $groups_by_cardholder,
    ];
}

/**
 * Calculates the final, merged permissions for a single cardholder from all their groups.
 */
function fsbhoa_calculate_cardholder_permissions($cardholder_id, $permission_data) {
    $cardholder_group_ids = $permission_data['groups_by_cardholder'][$cardholder_id] ?? [];

    foreach ($permission_data['groups'] as $group) {
        if ($group->is_default) {
            $cardholder_group_ids[] = $group->group_id;
        }
    }
    $cardholder_group_ids = array_unique($cardholder_group_ids);

    if (empty($cardholder_group_ids)) { return null; }

    $all_cardholder_groups = [];
    foreach ($cardholder_group_ids as $group_id) {
        $current_group_id = $group_id;
        while ($current_group_id) {
            if (isset($permission_data['groups'][$current_group_id])) {
                $all_cardholder_groups[$current_group_id] = $permission_data['groups'][$current_group_id];
                $current_group_id = $permission_data['groups'][$current_group_id]->parent_group_id;
            } else {
                $current_group_id = null;
            }
        }
    }

    if (empty($all_cardholder_groups)) { return null; }

    // --- START OF NEW MERGE LOGIC ---
    $merged_permissions = [];
    $has_all_access = false;


    foreach ($all_cardholder_groups as $group) {
        // *** START: CORRECTED DEBUGGING CODE ***
        if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) {
            $group_name = $group->group_name ?? 'Unknown Group';
            $group_all_access_flag = isset($group->all_access) && $group->all_access ? 'TRUE' : 'FALSE';
            error_log("DEBUG PERMS (Cardholder: $cardholder_id): Checking Group ID " . $group->group_id . " ($group_name). All-access flag is: $group_all_access_flag");
        }
        // *** END: CORRECTED DEBUGGING CODE ***

        // If any group grants all access, that takes ultimate precedence.
        if (!empty($group->all_access) && $group->all_access) {
            $has_all_access = true;
            break; // No need to check other groups
        }
        
        // Collect all permission rules from this group.
        if (isset($permission_data['permissions_by_group'][$group->group_id])) {
            $merged_permissions = array_merge($merged_permissions, $permission_data['permissions_by_group'][$group->group_id]);
        }
    }

    if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) {
        $group_names = wp_list_pluck($all_cardholder_groups, 'group_name');
        error_log("SYNC DEBUG (Cardholder {$cardholder_id}): Merging permissions from groups [" . implode(', ', $group_names) . "].");
    }

    if ($has_all_access) {
        return ['all_access' => true];
    }

    return $merged_permissions;
    // --- END OF NEW MERGE LOGIC ---
}

/**
 * Helper function to merge overlapping time windows.
 * e.g., ['09:00-17:00', '16:00-20:00'] becomes ['09:00-20:00']
 *
 * @param array $windows An array of "HH:mm-HH:mm" strings.
 * @return array A new array of merged "HH:mm-HH:mm" strings.
 */
function fsbhoa_merge_time_windows($windows) {
    if (count($windows) < 2) {
        return $windows;
    }

    // Convert "HH:mm-HH:mm" strings to arrays of timestamps for easier comparison
    $timestamps = [];
    foreach ($windows as $window) {
        list($start, $end) = explode('-', $window);
        $timestamps[] = [strtotime($start), strtotime($end)];
    }

    // Sort by start time
    usort($timestamps, function($a, $b) {
        return $a[0] <=> $b[0];
    });

    $merged = [$timestamps[0]];
    $last_merged_index = 0;

    for ($i = 1; $i < count($timestamps); $i++) {
        $last_merged = &$merged[$last_merged_index];
        $current = $timestamps[$i];

        // If the current window overlaps with or is adjacent to the last merged one
        if ($current[0] <= $last_merged[1]) {
            // Extend the last merged window if the current one ends later
            $last_merged[1] = max($last_merged[1], $current[1]);
        } else {
            // No overlap, add as a new window
            $merged[] = $current;
            $last_merged_index++;
        }
    }

    // Convert back to "HH:mm-HH:mm" strings
    $result = [];
    foreach ($merged as $ts) {
        $result[] = date('H:i', $ts[0]) . '-' . date('H:i', $ts[1]);
    }

    return $result;
}

/**
 * Creates a unique string signature for a schedule after merging time segments.
 */
function fsbhoa_generate_schedule_signature($permissions_for_door) {
    $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $segments_by_day = [];

    foreach ($permissions_for_door as $perm) {
        foreach ($days as $day) {
            if ($perm->{'on_' . $day}) {
                $start_time = substr($perm->start_time, 0, 5);
                $end_time = substr($perm->end_time, 0, 5);
                $segments_by_day[$day][] = $start_time . '-' . $end_time;
            }
        }
    }

    $signature_parts = [];
    foreach ($days as $day) {
        if (isset($segments_by_day[$day])) {
            // Merge overlapping time windows for this day before creating the signature.
            $merged_segments = fsbhoa_merge_time_windows($segments_by_day[$day]);
            $signature_parts[] = $day . ':' . implode(',', $merged_segments);
        }
    }
    return implode(';', $signature_parts);
}

/**
 * Uploads all necessary time profiles to a controller.
 */
function fsbhoa_sync_time_profiles($device_id, $unique_schedules) {
    $profile_map = [];
    $profile_id = 2;

    shell_exec(sprintf('uhppote-cli clear-time-profiles %s', $device_id));

    foreach ($unique_schedules as $signature => $schedule_data) {
        if ($profile_id > 255) {
            error_log("SYNC SERVICE: Exceeded maximum of 255 time profiles for device {$device_id}.");
            break;
        }

        $profile_map[$signature] = $profile_id;
        
        $active_dates = $schedule_data['valid_from'] . ':' . $schedule_data['valid_to'];
        $weekdays_arr = [];
        if ($schedule_data['mon']) $weekdays_arr[] = 'Mon';
        if ($schedule_data['tue']) $weekdays_arr[] = 'Tue';
        if ($schedule_data['wed']) $weekdays_arr[] = 'Wed';
        if ($schedule_data['thu']) $weekdays_arr[] = 'Thu';
        if ($schedule_data['fri']) $weekdays_arr[] = 'Fri';
        if ($schedule_data['sat']) $weekdays_arr[] = 'Sat';
        if ($schedule_data['sun']) $weekdays_arr[] = 'Sun';
        $weekdays = implode(',', $weekdays_arr);

        $segment1 = !empty($schedule_data['segment_1_start']) ? $schedule_data['segment_1_start'] . '-' . $schedule_data['segment_1_end'] : '';
        $segment2 = !empty($schedule_data['segment_2_start']) ? $schedule_data['segment_2_start'] . '-' . $schedule_data['segment_2_end'] : '';
        $segment3 = !empty($schedule_data['segment_3_start']) ? $schedule_data['segment_3_start'] . '-' . $schedule_data['segment_3_end'] : '';
        $segments = $segment1 . ',' . $segment2 . ',' . $segment3;

        $command = sprintf(
            'uhppote-cli set-time-profile %s %d %s %s %s',
            $device_id,
            $profile_id,
            $active_dates,
            $weekdays,
            $segments
        );
        
        if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) {
            error_log("SYNC DEBUG (Time Profile): Assigning Profile ID {$profile_id} to schedule '{$signature}'. Command: {$command}");
        }

        shell_exec($command . " 2>&1");
        $profile_id++;
    }
    return $profile_map;
}

/**
 * Builds a list of unique schedules required by all cardholders for a controller.
 */
function fsbhoa_build_unique_schedules_for_controller($db_cards, $permission_data) {
    $unique_schedules = [];

    foreach ($db_cards as $cardholder) {
        $permissions = fsbhoa_calculate_cardholder_permissions($cardholder->id, $permission_data);
        if ($permissions && !isset($permissions['all_access'])) {
            $perms_by_door = [];
            foreach ($permissions as $perm) {
                $perms_by_door[$perm->door_id][] = $perm;
            }
            foreach ($perms_by_door as $door_id => $door_perms) {
                $signature = fsbhoa_generate_schedule_signature($door_perms);
                if (!isset($unique_schedules[$signature])) {
                    $schedule_data = [
                        'valid_from' => '2020-01-01', 'valid_to'   => '2099-12-31',
                        'mon' => false, 'tue' => false, 'wed' => false, 'thu' => false, 'fri' => false, 'sat' => false, 'sun' => false,
                    ];
                    $segment_count = 1;
                    $added_segments = [];

                    foreach ($door_perms as $perm) {
                        if ($segment_count <= 3) {
                            $start_time = substr($perm->start_time, 0, 5);
                            $end_time = substr($perm->end_time, 0, 5);
                            $segment_key = $start_time . '-' . $end_time;

                            if (!in_array($segment_key, $added_segments)) {
                                $schedule_data['segment_' . $segment_count . '_start'] = $start_time;
                                $schedule_data['segment_' . $segment_count . '_end'] = $end_time;
                                $added_segments[] = $segment_key;
                                $segment_count++;
                            }
                        }
                        if ($perm->on_mon) $schedule_data['mon'] = true;
                        if ($perm->on_tue) $schedule_data['tue'] = true;
                        if ($perm->on_wed) $schedule_data['wed'] = true;
                        if ($perm->on_thu) $schedule_data['thu'] = true;
                        if ($perm->on_fri) $schedule_data['fri'] = true;
                        if ($perm->on_sat) $schedule_data['sat'] = true;
                        if ($perm->on_sun) $schedule_data['sun'] = true;
                    }
                    $unique_schedules[$signature] = $schedule_data;
                }
            }
        }
    }
    
    if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) {
        error_log("SYNC DEBUG (Schedules): Found " . count($unique_schedules) . " unique schedules to create.");
    }
    
    return $unique_schedules;
}

/**
 * Builds the final permission string for a single cardholder.
 */
function fsbhoa_build_card_permissions_string($cardholder, $permission_data, $profile_map) {
    global $wpdb;

    if ($cardholder->card_status === 'disabled') {
        return '';
    }

    $permissions = fsbhoa_calculate_cardholder_permissions($cardholder->id, $permission_data);
    
    if (!$permissions) {
        return '';
    } elseif (isset($permissions['all_access'])) {
        $permissions_string = "1:Y,2:Y,3:Y,4:Y";
    } else {
        $door_perms = [];
        $perms_by_door = [];
        foreach ($permissions as $perm) {
            $perms_by_door[$perm->door_id][] = $perm;
        }

        foreach ($perms_by_door as $door_id => $door_perms_list) {
            $door_obj = $wpdb->get_row($wpdb->prepare("SELECT door_number_on_controller FROM ac_doors WHERE door_record_id = %d", $door_id));
            if ($door_obj) {
                $signature = fsbhoa_generate_schedule_signature($door_perms_list);
                if (isset($profile_map[$signature])) {
                    $door_perms[$door_obj->door_number_on_controller] = $profile_map[$signature];
                }
            }
        }
        
        ksort($door_perms);
        $final_perms = [];
        foreach ($door_perms as $door_num => $profile_id) {
            $final_perms[] = $door_num . ':' . $profile_id;
        }
        $permissions_string = implode(',', $final_perms);
    }

    if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) {
        error_log("SYNC DEBUG (Card {$cardholder->rfid_id}): Final permission string is '{$permissions_string}'.");
    }

    return $permissions_string;
}

