<?php
// FILE: fsbhoa-permission-functions.php

if (!defined('WPINC')) { die; }

/**
 * Fetches all necessary group and permission data from the database.
 * This function remains unchanged.
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

    // Map all permissions by their group_id
    $permissions_by_group = [];
    foreach ($all_permissions as $perm) { $permissions_by_group[$perm->group_id][] = $perm; }

    // Map all group memberships by cardholder_id
    $groups_by_cardholder = [];
    foreach ($all_memberships as $member) { $groups_by_cardholder[$member->cardholder_id][] = $member->group_id; }

    return [
        'groups'                 => $all_groups,
        'permissions_by_group'   => $permissions_by_group,
        'groups_by_cardholder' => $groups_by_cardholder,
    ];
}

/**
 * [NEW] Inverts the cardholder-to-group map.
 * This gives us two maps:
 * 1. A unique 'signature' for each cardholder's group set.
 * 2. A map of all cardholders that belong to each unique signature.
 *
 * @param array $groups_by_cardholder Map of [cardholder_id => [group_id_1, group_id_2]]
 * @return array [ 'cardholder_to_sig' => [cardholder_id => 'sig'], 'sig_to_groups' => ['sig' => [group_ids]] ]
 */
function fsbhoa_invert_cardholder_groups($groups_by_cardholder) {
    $cardholder_to_sig = [];
    $sig_to_groups = [];

    foreach ($groups_by_cardholder as $cardholder_id => $group_ids) {
        if (empty($group_ids)) continue;
        
        // Sort the group IDs to create a stable, unique signature
        sort($group_ids);
        $signature = implode(',', $group_ids);

        $cardholder_to_sig[$cardholder_id] = $signature;
        if (!isset($sig_to_groups[$signature])) {
            $sig_to_groups[$signature] = $group_ids;
        }
    }

    return [
        'cardholder_to_sig' => $cardholder_to_sig,
        'sig_to_groups'     => $sig_to_groups,
    ];
}

/**
 * [NEW] Calculates the *raw*, unmerged permission rules for each unique group set.
 * This resolves the rule specificity (door > controller > all)
 *
 * @param array $sig_to_groups Map from fsbhoa_invert_cardholder_groups
 * @param array $permission_data The global permission data
 * @param array $all_door_data A map of [door_id => door_object] from the DB
 * @return array [ 'sig' => [ 'door_id' => [raw_perm_rule_1, ...], 'all_access' => bool ] ]
 */
function fsbhoa_calculate_raw_permissions_for_sets($sig_to_groups, $permission_data, $all_door_data) {
    $raw_perm_sets = [];
    $all_door_ids = array_keys($all_door_data);
    $doors_by_controller = [];
    foreach ($all_door_data as $door) {
        if ($door->controller_record_id) {
            $doors_by_controller[$door->controller_record_id][] = $door->door_record_id;
        }
    }

    foreach ($sig_to_groups as $sig => $group_ids) {
        $has_all_access = false;
        $rules_for_set = []; // [ door_id => [ rule_with_specificity, ... ] ]

        foreach ($group_ids as $group_id) {
            // Check for 'All Access' group
            if (isset($permission_data['groups'][$group_id])) {
                $group = $permission_data['groups'][$group_id];
                if (!empty($group->has_all_access) && $group->has_all_access) {
                    $has_all_access = true;
                    break; // This set has 'all access', no need to calculate rules
                }
            }

            // Get raw permission rules for this group
            $rules_for_this_group = $permission_data['permissions_by_group'][$group_id] ?? [];

            foreach ($rules_for_this_group as $rule) {
                $specificity = 1;
                $doors_to_add = [];

                if ($rule->controller_id === null && $rule->door_id === null) {
                    // Specificity 1: Applies to ALL doors
                    $specificity = 1; $doors_to_add = $all_door_ids;
                } elseif ($rule->controller_id !== null && $rule->door_id === null) {
                    // Specificity 2: Applies to all doors on a controller
                    $specificity = 2; $doors_to_add = $doors_by_controller[$rule->controller_id] ?? [];
                } elseif ($rule->door_id !== null) {
                    // Specificity 3: Applies to a single door
                    $specificity = 3; $doors_to_add[] = $rule->door_id;
                }

                foreach ($doors_to_add as $door_id) {
                    $permission_copy = clone $rule;
                    $permission_copy->specificity = $specificity;
                    $rules_for_set[$door_id][] = $permission_copy;
                }
            }
        }

        if ($has_all_access) {
            $raw_perm_sets[$sig] = ['all_access' => true, 'perms' => []];
            continue;
        }

        // Filter rules by specificity (highest number wins)
        $final_rules_for_set = [];
        foreach ($rules_for_set as $door_id => $rules) {
            $max_specificity = 0;
            foreach ($rules as $r) { $max_specificity = max($max_specificity, $r->specificity); }

            foreach ($rules as $r) {
                if ($r->specificity === $max_specificity) {
                    $final_rules_for_set[$door_id][] = $r;
                }
            }
        }
        $raw_perm_sets[$sig] = ['all_access' => false, 'perms' => $final_rules_for_set];
    }
    return $raw_perm_sets;
}


/**
 * [NEW] The master function to build profile maps for a *single* controller.
 * Takes all raw permission sets and a list of doors for *this* controller,
 * and builds the optimized profile maps.
 *
 * @param array $raw_perm_sets The map from fsbhoa_calculate_raw_permissions_for_sets
 * @param array $door_ids_for_this_controller An array of door_ids [1, 2, 5, ...]
 * @return array Three maps: 'dictionary', 'links', 'entry_points'
 */
function fsbhoa_build_global_profile_maps($raw_perm_sets, $door_ids_for_this_controller) {
    $profile_dictionary = []; // [profile_signature => profile_id]
    $profile_chain_links = []; // [profile_id => next_profile_id]
    $set_entry_points = []; // [group_set_sig => [door_id => entry_profile_id]]
    $profile_id_counter = 2; // Profiles 2-254

    foreach ($raw_perm_sets as $sig => $perm_set) {
        if ($perm_set['all_access']) {
            continue; // 'all_access' is handled separately
        }

        foreach ($perm_set['perms'] as $door_id => $rules) {
            // IMPORTANT: Only process rules for doors on the current controller
            if (!in_array($door_id, $door_ids_for_this_controller)) {
                continue;
            }

            // 1. Group rules by day-of-week combination
            $rules_by_days = [];
            foreach ($rules as $rule) {
                $days = [];
                if ($rule->on_sun) $days[] = 'Sun';
                if ($rule->on_mon) $days[] = 'Mon';
                if ($rule->on_tue) $days[] = 'Tue';
                if ($rule->on_wed) $days[] = 'Wed';
                if ($rule->on_thu) $days[] = 'Thu';
                if ($rule->on_fri) $days[] = 'Fri';
                if ($rule->on_sat) $days[] = 'Sat';
                if (empty($days)) continue;
                
                $day_sig = implode(',', $days);
                $rules_by_days[$day_sig][] = substr($rule->start_time, 0, 5) . '-' . substr($rule->end_time, 0, 5);
            }

            if (empty($rules_by_days)) {
                continue; // No valid rules for this door/set
            }

            // 2. Build the chain for this (set, door)
            $linked_profile_id = 0; // Start at the end of the chain

            // We iterate the day groups to build the chain.
            // The order doesn't strictly matter as long as it's consistent,
            // but we build it backwards (last chunk first).
            foreach ($rules_by_days as $day_sig => $windows) {
                
                // 3. Merge and Chunk the time windows
                $merged_windows = fsbhoa_merge_time_windows($windows);
                $window_chunks = array_chunk($merged_windows, 3); // Max 3 spans per profile

                // 4. Build chain backwards for each chunk
                for ($i = count($window_chunks) - 1; $i >= 0; $i--) {
                    $chunk = $window_chunks[$i];
                    $span_sig = implode(',', $chunk);
                    $profile_signature = $day_sig . '|' . $span_sig;

                    // 5. Check if this profile is already in our global dictionary
                    if (isset($profile_dictionary[$profile_signature])) {
                        $current_profile_id = $profile_dictionary[$profile_signature];
                    } else {
                        // It's a new profile. Assign it an ID.
                        if ($profile_id_counter > 254) {
                            error_log("FATAL SYNC ERROR: Controller ran out of time profiles (max 253). Stopping profile generation.");
                            break 3; // Break out of all loops for this controller
                        }
                        $current_profile_id = $profile_id_counter++;
                        $profile_dictionary[$profile_signature] = $current_profile_id;
                    }

                    // 6. Set this profile's *link* to the *previous* one we processed
                    // (which is the *next* one in the chain)
                    if (!isset($profile_chain_links[$current_profile_id])) {
                         $profile_chain_links[$current_profile_id] = $linked_profile_id;
                    }
                   
                    // 7. This profile becomes the link for the *next* chunk
                    $linked_profile_id = $current_profile_id;
                }
            }

            // 8. The last ID we processed is the *entry point* for this chain
            if ($linked_profile_id > 0) {
                $set_entry_points[$sig][$door_id] = $linked_profile_id;
            }
        }
    }

    return [
        'dictionary'   => $profile_dictionary,
        'links'        => $profile_chain_links,
        'entry_points' => $set_entry_points
    ];
}


/**
 * [NEW] Formats the final permission string for the 'put-card' command.
 *
 * @param array $door_num_to_profile_map Map of [door_number => profile_id]
 * @param bool $has_all_access If true, returns 'all access' string
 * @return string
 */
function fsbhoa_format_permission_string($door_num_to_profile_map, $has_all_access) {
    if ($has_all_access) {
        return "1:Y,2:Y,3:Y,4:Y";
    }

    if (empty($door_num_to_profile_map)) {
        return ""; // No permissions
    }

    ksort($door_num_to_profile_map); // Sort by door number (1, 2, 3, 4)
    $final_perms = [];
    foreach ($door_num_to_profile_map as $door_num => $profile_id) {
        $final_perms[] = $door_num . ':' . $profile_id;
    }
    
    return implode(',', $final_perms);
}


/**
 * Helper function to merge overlapping time windows.
 * This function remains unchanged.
 */
function fsbhoa_merge_time_windows($windows) {
    if (empty($windows)) { return []; }
    
    $timestamps = [];
    foreach ($windows as $window) {
        if(empty($window) || strpos($window, '-') === false) continue;
        list($start, $end) = explode('-', $window);
        $timestamps[] = [strtotime($start), strtotime($end)];
    }
    if (empty($timestamps)) { return []; }

    // Sort by start time
    usort($timestamps, function($a, $b) { return $a[0] <=> $b[0]; });

    $merged = [];
    if (!empty($timestamps)) {
        $merged[] = $timestamps[0];
        for ($i = 1; $i < count($timestamps); $i++) {
            $last_merged = &$merged[count($merged) - 1];
            // Check for overlap or contiguous
            if ($timestamps[$i][0] <= $last_merged[1]) {
                $last_merged[1] = max($last_merged[1], $timestamps[$i][1]);
            } else {
                $merged[] = $timestamps[$i];
            }
        }
    }

    $result = [];
    foreach ($merged as $ts) { $result[] = date('H:i', $ts[0]) . '-' . date('H:i', $ts[1]); }
    return $result;
}

/**
 * Determines the currently active schedule ID.
 * This function remains unchanged.
 */
function fsbhoa_get_active_schedule_id() {
    global $wpdb;
    $active_id = $wpdb->get_var(
          "SELECT schedule_id FROM ac_schedules 
           WHERE is_default = 0 
           AND NOW() >= start_date 
           AND NOW() < DATE_ADD(end_date, INTERVAL 1 DAY) 
           ORDER BY start_date DESC 
           LIMIT 1"
    );
    return $active_id ? absint($active_id) : 1;
}

/**
 * REBUILDS the daily access cache for the Monitor Status Group.
 * This runs at midnight and on settings save.
 * It stores a simple array of valid time windows for "Today" for each door.
 */
function fsbhoa_rebuild_monitor_status_cache() {
    global $wpdb;

    // 1. Get Configuration
    $group_id = get_option('fsbhoa_monitor_status_group_id', 0);
    if (!$group_id) return; // Feature disabled

    // 2. Get Context (Today's Schedule & Day of Week)
    $schedule_id = fsbhoa_get_active_schedule_id();
    $now_ts = current_time('timestamp');
    $current_day_col = 'on_' . strtolower(date('D', $now_ts)); // e.g., 'on_mon'

    // 3. Fetch Data (Heavy lifting happens here)
    $permission_data = fsbhoa_get_all_permission_data($schedule_id);
    $all_door_data = $wpdb->get_results("SELECT door_record_id, controller_record_id, door_number_on_controller FROM ac_doors", OBJECT_K) ?: [];

    // 4. Calculate Specificity Rules
    $group_sig = strval($group_id);
    $sig_to_groups = [$group_sig => [$group_id]];
    $raw_perm_sets = fsbhoa_calculate_raw_permissions_for_sets($sig_to_groups, $permission_data, $all_door_data);
    
    $final_perms = $raw_perm_sets[$group_sig]['perms'] ?? [];
    $has_all_access = $raw_perm_sets[$group_sig]['all_access'] ?? false;

    // 5. Build the Simple Cache: [ door_id => [ 'start'=>'08:00', 'end'=>'20:00' ] ]
    $todays_cache = [];

    foreach ($all_door_data as $door_id => $door) {
        if ($has_all_access) {
            $todays_cache[$door_id] = 'ALWAYS';
            continue;
        }

        $todays_cache[$door_id] = null; // Default to closed

        if (isset($final_perms[$door_id])) {
            foreach ($final_perms[$door_id] as $rule) {
                // If this rule is active TODAY, store its window
                if (!empty($rule->$current_day_col) && isset($rule->start_time) && isset($rule->end_time)) {
                    // We found the specific winning rule for today. Store it.
                    $todays_cache[$door_id] = [
                        'start' => substr($rule->start_time, 0, 5), // '08:00'
                        'end'   => substr($rule->end_time, 0, 5)    // '22:00'
                    ];
                    break; // Stop after finding the winning rule
                }
            }
        }
    }

    // 6. Save to WP Options (Persistent Cache)
    update_option('fsbhoa_monitor_daily_cache', $todays_cache, false); // 'false' = autoload not required on every page load
    update_option('fsbhoa_monitor_cache_date', date('Y-m-d', $now_ts)); // To verify freshness
}
