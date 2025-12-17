<?php
// FILE: fsbhoa-uhppote-sync-service.php

if (!defined('WPINC')) { die; }
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-permission-functions.php';

add_action('fsbhoa_run_background_sync', 'fsbhoa_perform_delta_sync');
add_action('fsbhoa_run_nightly_rebuild', 'fsbhoa_perform_nightly_rebuild_sync');

// fsbhoa_perform_delta_sync()
function fsbhoa_perform_delta_sync() {
    global $wpdb;
    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    error_log("DELTA SYNC: Process started.");
    if ($is_dry_run) { error_log("DELTA SYNC: --- DRY RUN MODE ENABLED ---"); }
    set_time_limit(300);
    set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Starting delta sync...'], MINUTE_IN_SECONDS * 10);

    // Get the active schedule
    $active_schedule_id = fsbhoa_get_active_schedule_id();
    $active_schedule_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM ac_schedules WHERE schedule_id = %d", $active_schedule_id)) ?: 'Default';
    error_log("DELTA SYNC: Determined active schedule is: '" . $active_schedule_name . "' (ID: " . $active_schedule_id . ")");

    $change_types = $wpdb->get_col("SELECT DISTINCT change_type FROM ac_pending_changes");

    $cardholders_to_sync = [];
    $cardholders_to_delete = [];

    //  Determine which cards to sync
    $high_impact_card_changes = ['group', 'controller', 'generic']; // 'generic' can mean schedules, etc.

    if (!empty(array_intersect($high_impact_card_changes, $change_types))) {
        error_log("DELTA SYNC: High-impact change detected (" . implode(', ', $change_types) . "). Syncing all active cardholders.");
        fsbhoa_perform_nightly_rebuild_sync();
        return;
    } else if (in_array('cardholder', $change_types)) {
        $changed_cardholder_ids = $wpdb->get_col("SELECT DISTINCT record_id FROM ac_pending_changes WHERE change_type = 'cardholder'");
        if (!empty($changed_cardholder_ids)) {
            $id_list = implode(',', array_map('absint', $changed_cardholder_ids));
            error_log("DELTA SYNC: Low-impact change detected. Processing cardholder IDs: {$id_list}");
            $cardholders_to_process = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE id IN ($id_list)");
            foreach ($cardholders_to_process as $cardholder) {
                if ($cardholder->card_status === 'active') {
                    $cardholders_to_sync[] = $cardholder;
                } else {
                    $cardholders_to_delete[] = $cardholder;
                }
            }
        }
    }

    // --- Determine if tasks need to be synced (separately) ---
    $task_related_changes = ['tasks', 'generic'];
    $task_sync_needed = !empty(array_intersect($task_related_changes, $change_types));

    // --- Check if we need to do anything at all ---
    if (empty($cardholders_to_sync) && empty($cardholders_to_delete) && !$task_sync_needed) {
        if (!$is_dry_run) { $wpdb->query("DELETE FROM ac_pending_changes"); }
        set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => 'No relevant changes to push.'], MINUTE_IN_SECONDS * 5);
        error_log("DELTA SYNC: No cardholder or task changes found. Exiting.");
        return;
    }

    // --- Execute the sync with the correctly identified changes ---
    $permission_data = fsbhoa_get_all_permission_data($active_schedule_id);
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE type = 'UHPPOTE'");
    fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $cardholders_to_delete, $task_sync_needed, $is_dry_run, false, $active_schedule_id);
}


// fsbhoa_perform_nightly_rebuild_sync()
function fsbhoa_perform_nightly_rebuild_sync() {
    error_log('[' . current_time('Y-m-d H:i:s T') . "] NIGHTLY REBUILD: Process started.");
    set_time_limit(600);
    global $wpdb;

    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    if ($is_dry_run) { error_log("NIGHTLY REBUILD: --- DRY RUN MODE ENABLED ---"); }

    // --- Find the active schedule for today ---
    $active_schedule_id = fsbhoa_get_active_schedule_id();
    error_log("NIGHTLY REBUILD: Determined active schedule ID is: " . $active_schedule_id);

    // Fetch permissions for the active schedule
    $permission_data = fsbhoa_get_all_permission_data($active_schedule_id);

    $cardholders_to_sync = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE card_status = 'active'");
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE type = 'UHPPOTE'");

    //  Pass the active schedule ID to the main sync logic
    fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, [], true, $is_dry_run, true, $active_schedule_id);

}

/**
 *  Executes the main sync logic using the new profile dictionary model.
 */
function fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $cardholders_to_delete, $task_sync_needed, $is_dry_run, $is_rebuild, $active_schedule_id = 1) {
    global $wpdb;
    $retry_attempts = 3; // Number of times to retry a failed command
    $retry_wait = 250000; // 250ms wait between retries (in microseconds)
    $global_sync_failed = false;

    if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) {
        $ids_to_sync = [];
        foreach ($cardholders_to_sync as $cardholder) { $ids_to_sync[] = $cardholder->id; }
        error_log('SYNC EXECUTE: About to process ' . count($ids_to_sync) . ' Cardholder IDs: ' . implode(', ', $ids_to_sync));
    }
    
    // ---
    //  1. Pre-calculate all global permission data
    // ---
    $all_door_data = $wpdb->get_results("SELECT door_record_id, controller_record_id, door_number_on_controller FROM ac_doors", OBJECT_K);
    if (!$all_door_data) $all_door_data = [];

    // Invert map to find [cardholder_id => 'group,sig'] and ['group,sig' => [group_ids]]
    $inverted_group_sets = fsbhoa_invert_cardholder_groups($permission_data['groups_by_cardholder']);
    
    // Calculate the raw, unmerged, specificity-filtered rules for each unique group set
    $raw_perm_sets = fsbhoa_calculate_raw_permissions_for_sets($inverted_group_sets['sig_to_groups'], $permission_data, $all_door_data);

    // Create a simple map of [rfid_id => cardholder_object] for the cards we are syncing
    $db_cards_to_sync = [];
    foreach ($cardholders_to_sync as $cardholder) {
        if (!empty($cardholder->rfid_id)) {
            $db_cards_to_sync[$cardholder->rfid_id] = $cardholder;
        }
    }

    // ---
    //  2. Loop through each controller and sync it
    // ---
//    $max_controller_attempts = 2;
//    $controller_retry_delay = 3; // 3 seconds

    foreach ($controllers as $controller) {
        // --- SAFETY CHECK ---
        if (isset($controller->type) && $controller->type !== 'UHPPOTE') {
            continue;
        }

        $device_id = $controller->uhppoted_device_id;
        $controller_id = $controller->controller_record_id;
        $friendly_name = $controller->friendly_name;
//        for ($attempt = 1; $attempt <= $max_controller_attempts; $attempt++) {
        
//           $controller_sync_succeeded = true; // Flag for this attempt   
           set_transient('fsbhoa_sync_status', [
               'status'  => 'in_progress',
               'message' => 'Processing controller: ' . esc_html($controller->friendly_name) . '...'
           ], MINUTE_IN_SECONDS * 10);


           $puts_sent = 0;
           $deletes_sent = 0;

           // Check if controller is online
           $status_command = sprintf('uhppote-cli --timeout 2s get-status %s', $device_id);
           $status_output = shell_exec($status_command . " 2>&1");
           if (strpos($status_output, 'ERROR') !== false || empty(trim($status_output))) {
               if (FSBHOA_DEBUG_MODE) {
                   error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is offline. Skipping.");
               }
               continue;
           }
           error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is syncing.");

           // Set controller time
           if (!$is_dry_run) { shell_exec(sprintf('uhppote-cli set-time %s 2>&1', $device_id)); }

           // ---
           //  3. Build and Upload Time Profiles for *this* controller
           // ---

           // Get a list of all door_record_ids managed by this controller
           $door_ids_for_this_controller = [];
           foreach($all_door_data as $door_id => $door) {
               if ($door->controller_record_id == $controller_id) {
                   $door_ids_for_this_controller[] = $door_id;
               }
           }
        
           // Build the unique profile maps for *only* the doors on this controller
           $profile_maps = fsbhoa_build_global_profile_maps($raw_perm_sets, $door_ids_for_this_controller);
           
           $profile_dictionary = $profile_maps['dictionary']; // [sig => id]
           $profile_chain_links = $profile_maps['links'];    // [id => next_id]
           $set_entry_points = $profile_maps['entry_points']; // [group_sig => [door_id => entry_id]]

           if ($is_rebuild) {
               // Clear all profiles and cards on a full rebuild
               if (!$is_dry_run) {
                   $output_clear_profiles = shell_exec(sprintf('uhppote-cli clear-time-profiles %s 2>&1', $device_id));
                   if (strpos($output_clear_profiles, 'false') !== false || strpos($output_clear_profiles, 'ERROR') !== false) {
                        error_log("SYNC WARNING (CLEAR PROFILES) for $friendly_name: $output_clear_profiles");
                        // We don't set global fail here, as it might just be empty
                   }
                   
                   $output_delete_cards = shell_exec(sprintf('uhppote-cli delete-cards %s 2>&1', $device_id));
                    if (strpos($output_delete_cards, 'false') !== false || strpos($output_delete_cards, 'ERROR') !== false) {
                        error_log("SYNC WARNING (DELETE CARDS) for $friendly_name: $output_delete_cards");
                        // We don't set global fail here
                   }
                   sleep(1);
               } else {
                   error_log("DRY RUN (REBUILD): Would execute: uhppote-cli clear-time-profiles " . $device_id);
                   error_log("DRY RUN (REBUILD): Would execute: uhppote-cli delete-cards " . $device_id);
               }
           }
           
           // Upload all unique profiles needed for this controller
           // This only runs on a rebuild. Delta syncs assume profiles are correct.
           if ($is_rebuild) {
               foreach ($profile_dictionary as $profile_signature => $profile_id) {
                   list($day_sig, $span_sig) = explode('|', $profile_signature);
                   $linked_profile_id = $profile_chain_links[$profile_id] ?? 0;
                   
                   // Format for command: 'Mon,Tue,Wed' and '08:00-12:00,13:00-17:00'
                   $weekdays = $day_sig; 
                   $spans_string = "'" . $span_sig . "'";
   
                   $command = sprintf("uhppote-cli set-time-profile %s %d %s %s %s %d",
                       $device_id,
                       $profile_id,
                       '2020-01-01:2099-12-31', // Valid forever
                       $weekdays,
                       $spans_string,
                       $linked_profile_id
                   );
   
                   if ($is_dry_run) {
                       error_log("DRY RUN (PROFILE): Would execute: " . $command);
                   } else {
                       if (FSBHOA_DEBUG_MODE) { error_log("SYNC PROFILE: Executing: " . $command); }
                       $output = '';
                       for ($i = 0; $i < $retry_attempts; $i++) {
                           if (FSBHOA_DEBUG_MODE && $i > 0) { error_log("SYNC PROFILE (Attempt " . ($i+1) . "): Executing: " . $command); }
                           $output = shell_exec($command . " 2>&1");
                           if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                               $success = true;
                               break; // Succeeded
                           }
                           usleep($retry_wait); // Wait 250ms before retrying
                       }

                       if (!$success) {
                           $controller_sync_succeeded = false;
                           error_log("SYNC FAILED (PROFILE) for $friendly_name after $retry_attempts attempts: $output");
                       }
                       sleep(1);

/*******************************************
                       // --- VERIFY PROFILE CONTENTS AFTER SUCCESSFUL UPLOAD  ---
                       if (!$is_dry_run && $success) {
                           if (!fsbhoa_verify_time_profile_content($device_id, $profile_id, $weekdays, $spans_string)) {
                               $global_sync_failed = true;
                               $profiles_uploaded_successfully = false;
                               error_log("SYNC CRITICAL: Profile $profile_id failed content verification after upload. Aborting.");
                               break 2; // Break out of profile loop and controller loop
                           }
                       }
**********************************************/
                   }
               }
           }
           // Add a small delay to allow controllers to process profile updates before we start sending card data.
           if ($is_rebuild && !$is_dry_run) {
               sleep(2); // Wait 2 seconds
           }
   
           // ---
           // 4. Process Card Deletions
           // ---
           foreach ($cardholders_to_delete as $cardholder) {
               if (!empty($cardholder->rfid_id)) {
                   $delete_card_command = sprintf('uhppote-cli delete-card %s %s', $device_id, $cardholder->rfid_id);
                   if ($is_dry_run) { error_log("DRY RUN (DELTA): Would execute: " . $delete_card_command); }
                   else { shell_exec($delete_card_command . " 2>&1"); }
                   $deletes_sent++;
               }
           }

           // ---
           //  5. Process Card Additions/Updates
           // ---
           foreach ($db_cards_to_sync as $card_number => $cardholder) {
               // Find this cardholder's unique group signature
               $group_set_sig = $inverted_group_sets['cardholder_to_sig'][$cardholder->id] ?? null;
               $has_all_access = $raw_perm_sets[$group_set_sig]['all_access'] ?? false;
               
               // Get the map of [door_id => entry_profile_id] for this cardholder's group set
               $door_id_to_entry_point = $set_entry_points[$group_set_sig] ?? [];
   
               // Convert map from [door_id => profile_id] to [door_num => profile_id]
               $door_num_to_profile_map = [];
               if (!$has_all_access && !empty($door_id_to_entry_point)) {
                   foreach ($door_id_to_entry_point as $door_id => $profile_id) {
                       // We already filtered for this controller's doors,
                       // so $all_door_data[$door_id] should be safe.
                       if (isset($all_door_data[$door_id])) {
                           $door_num = $all_door_data[$door_id]->door_number_on_controller;
                           $door_num_to_profile_map[$door_num] = $profile_id;
                       }
                   }
               }
               
               // Get the final, formatted permission string
               $new_permissions_string = fsbhoa_format_permission_string($door_num_to_profile_map, $has_all_access);
   
               $new_start_date = $cardholder->card_issue_date ?? '2000-01-01';
               $new_end_date = $cardholder->card_expiry_date ?? '2099-12-31';
   
               $put_card_command = sprintf('uhppote-cli put-card %s %s %s %s %s', $device_id, $card_number, $new_start_date, $new_end_date, $new_permissions_string);
               
               if ($is_dry_run) {
                   error_log("DRY RUN (PUT CARD): Would execute: " . $put_card_command);
               } else {
                   $success = false;
                   $output = '';
                   for ($i = 0; $i < $retry_attempts; $i++) {
                       $output = shell_exec($put_card_command . " 2>&1");
                       if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                           $success = true;
                           break; // Succeeded
                       }
                       // Failed, wait 250ms before retrying for socket exhaustion or profile race condition
                       usleep($retry_wait); 
                   }
   
                   if (!$success) {
                       $global_sync_failed = true;
                       // Log the final error after all attempts
                       error_log("SYNC FAILED (PUT CARD) for $friendly_name, Card $card_number after $retry_attempts attempts:" . PHP_EOL . $put_card_command . PHP_EOL . $output);
                   }
                   //  Add a 20ms delay to prevent socket exhaustion
                   usleep(20000);
               }
               $puts_sent++;
           }

           // ---
           // 6. Sync Tasks 
           // ---
           if ($task_sync_needed || $is_rebuild) {
               $tasks = $wpdb->get_results($wpdb->prepare(
                    "SELECT t.*, s.start_date, s.end_date, s.is_default 
                     FROM ac_task_list t
                     JOIN ac_schedules s ON t.schedule_id = s.schedule_id
                     WHERE t.enabled = 1 AND t.schedule_id = %d",
                    $active_schedule_id
               ));
               $clear_task_list_command = sprintf('uhppote-cli clear-task-list %s 2>&1', $device_id);
               if (!$is_dry_run) {
                   error_log($clear_task_list_command);
                   $output_clear_tasks = shell_exec($clear_task_list_command);
                    if (strpos($output_clear_tasks, 'false') !== false || strpos($output_clear_tasks, 'ERROR') !== false) {
                        error_log("SYNC WARNING (CLEAR TASKS) for $friendly_name: $output_clear_tasks");
                    }
               } else { 
                   error_log("DRY RUN: Would execute: uhppote-cli clear-task-list " . $device_id); 
               }
               
               foreach ($tasks as $task) {
                   // Only sync tasks for this controller OR global tasks (controller_id is NULL)
                   if ($task->controller_id === null || $task->controller_id == $controller_id) {
                       $valid_from = ($task->is_default) ? '2025-01-01' : $task->start_date;
                       $valid_to   = ($task->is_default) ? '2099-12-31' : $task->end_date;
                       $weekdays = rtrim(($task->on_sun ? 'Sun,' : '') . ($task->on_mon ? 'Mon,' : '') . ($task->on_tue ? 'Tue,' : '') . ($task->on_wed ? 'Wed,' : '') . ($task->on_thu ? 'Thu,' : '') . ($task->on_fri ? 'Fri,' : '') . ($task->on_sat ? 'Sat,' : ''), ',');
                       if (empty($weekdays)) $weekdays = '...'; // uhppote-cli syntax for 'none'

                       $doors_to_set = ($task->door_number === null) ? [1, 2, 3, 4] : [intval($task->door_number)];
                       $task_description = '';
                       switch (intval($task->task_type)) {
                           case 1: $task_description = "'control door'"; break;
                           case 2: $task_description = "'unlock door'"; break;
                           case 3: $task_description = "'lock door'"; break;
                           default: continue 2; // Skip this task
                       }
                       
                       foreach ($doors_to_set as $door) {
                           $add_task_command = sprintf('uhppote-cli add-task %s %s %d %s:%s %s %s 0', 
                               $device_id, 
                               $task_description, 
                               $door, 
                               $valid_from, 
                               $valid_to, 
                               $weekdays, 
                               substr($task->start_time, 0, 5));
                           if ($is_dry_run) { 
                               error_log("DRY RUN (TASK): Would execute: " . $add_task_command);
                           } else {
                               $success = false;
                               $output = '';
                               error_log($add_task_command);
                               for ($i = 0; $i < $retry_attempts; $i++) {
                                   $output = shell_exec($add_task_command . " 2>&1");
                                   if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                                       $success = true;
                                       break;
                                   }
                                   usleep($retry_wait);
                               }
                               if (!$success) {
                                   $global_sync_failed = true;
                                   error_log("SYNC FAILED (ADD TASK) for $friendly_name after $retry_attempts attempts:" . PHP_EOL . $add_task_command . PHP_EOL . $output);
                               }
                           }
                       }
                   }
               }
               $refresh_task_list_command = sprintf('uhppote-cli refresh-task-list %s 2>&1', $device_id);
               if (!$is_dry_run) {
                   error_log($refresh_task_list_command);
                   $output_refresh_tasks = shell_exec($refresh_task_list_command);
                    if (strpos($output_refresh_tasks, 'false') !== false || strpos($output_refresh_tasks, 'ERROR') !== false) {
                        $global_sync_failed = true; // This one is critical
                        error_log("SYNC FAILED (REFRESH TASKS) for $friendly_name: $output_refresh_tasks");
                    }
               } else { 
                   error_log("DRY RUN: Would execute: uhppote-cli refresh-task-list " . $device_id); 
               }
           }

           if (FSBHOA_DEBUG_MODE) {
               error_log(sprintf( "SYNC STATS for '%s': Processed %d cards to sync. Sent %d delete commands. Sent %d put commands.", $friendly_name, count($db_cards_to_sync), $deletes_sent, $puts_sent ));
           }
    } // End controller loop

    // ---
    // 7. Finalize 
    // ---
    if (!$is_dry_run) {
        $wpdb->query("DELETE FROM ac_pending_changes");
    } else {
        error_log("DRY RUN: Would delete from ac_pending_changes table.");
    }
    
    if ($global_sync_failed) {
        set_transient('fsbhoa_sync_status', ['status' => 'failed', 'message' => 'Sync failed. Check error log.'], MINUTE_IN_SECONDS * 10);
        error_log("Sync FAILED. One or more commands returned 'false' or 'ERROR'.");
    } else {
        $final_message = ($is_rebuild) ? "Nightly rebuild complete." : "Delta sync complete.";
        if ($is_dry_run) { $final_message = "Dry run complete."; }
        set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => $final_message], MINUTE_IN_SECONDS * 5);
        error_log("Sync Complete.");

        // REBUILD MONITOR CACHE
        fsbhoa_rebuild_monitor_status_cache();
        error_log("NIGHTLY REBUILD: Monitor status cache updated.");
    }
}


/**
 * Action hook for the daily 3AM time sync
 */
add_action('fsbhoa_run_daily_time_sync', 'fsbhoa_perform_daily_time_sync');

/**
 * New function that *only* sets the time on all controllers.
 * This is a lightweight job to run after DST changes.
 */
function fsbhoa_perform_daily_time_sync() {
    error_log("DAILY TIME SYNC: Process started.");
    global $wpdb;
    $controllers = $wpdb->get_results("SELECT uhppoted_device_id, friendly_name FROM ac_controllers WHERE type = 'UHPPOTE");

    if (empty($controllers)) {
        error_log("DAILY TIME SYNC: No controllers found.");
        return;
    }

    foreach ($controllers as $controller) {
        // Check if controller is online
        $status_command = sprintf('uhppote-cli --timeout 2s get-status %s', $controller->uhppoted_device_id);
        $status_output = shell_exec($status_command . " 2>&1");

        if (strpos($status_output, 'ERROR') === false && !empty(trim($status_output))) {
            // Controller is online, set the time
            error_log("DAILY TIME SYNC: Setting time on " . $controller->friendly_name);
            shell_exec(sprintf('uhppote-cli set-time %s 2>&1', $controller->uhppoted_device_id));
        } else {
            error_log("DAILY TIME SYNC: Controller " . $controller->friendly_name . " is offline. Skipping.");
        }
    }
    error_log("DAILY TIME SYNC: Complete.");
}



/**
 * Verifies if a specific time profile exists on the controller AND if its content matches the expected data.
 * This is performed by reading the profile back from the controller.
 * Example: 
 *  uhppote-cli set-time-profile 425045111 3 2020-01-01:2099-12-31 Mon,Tue,Wed,Thu,Fri,Sat '05:00-17:00' 0
 *  uhppote-cli get-time-profile 425045111 3
 *  425045111  3 2020-01-01:2099-12-31 Mon,Tue,Wed,Thurs,Fri,Sat 05:00-17:00 0
 *                                         NOTE:-----^
 *
 * @param string $device_id Controller serial number.
 * @param int $profile_id The ID to check (2-254).
 * @param string $expected_weekdays The expected weekday string (e.g., 'Wed,Thu,Fri').
 * @param string $expected_spans The expected time spans string (e.g., '05:00-21:59').
 * @return bool True if profile contents match, false otherwise.
 */
function fsbhoa_verify_time_profile_content($device_id, $profile_id, $expected_weekdays, $expected_spans) {
    $command = sprintf('uhppote-cli get-time-profile %s %d 2>&1', escapeshellarg($device_id), absint($profile_id));
    $output = shell_exec($command);
    
    // 1. Basic Existence Check (Failure)
    if (strpos($output, 'ERROR') !== false || strpos($output, 'fail') !== false || empty(trim($output))) {
        error_log("SYNC VERIFY FAILED: Profile $profile_id read failed on $device_id. Output: " . trim($output));
        return false;
    }

    // 2. Parse and Normalize Output 
    
    // a. Extract Weekdays (Simplest method: search for the expected substring)
    $normalized_weekdays = str_replace("'", "", $expected_weekdays);
    // Create a normalized version of the *received* string by replacing Thurs with Thu
    $normalized_output_weekdays = str_replace('Thurs', 'Thu', $output);

    // b. Extract Time Spans
    $normalized_spans = str_replace("'", "", $expected_spans); // Remove PHP quotes for comparison

    // For robust verification, we check if the required strings exist in the returned output.
    $found_weekdays = strpos($normalized_output_weekdays, $normalized_weekdays) !== false;
    $found_spans = strpos($output, $normalized_spans) !== false;

    if ($found_weekdays && $found_spans) {
        return true;
    } else {
        error_log("SYNC VERIFY CONTENT MISMATCH: Profile $profile_id on $device_id. Expected Weekdays: $normalized_weekdays, Expected Spans: $normalized_spans. Actual output: " . trim($output));
        return false;
    }
}
