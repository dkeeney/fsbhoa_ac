<?php
// FILE: fsbhoa-uhppote-sync-service.php

if (!defined('WPINC')) { die; }

require_once FSBHOA_AC_PLUGIN_DIR . 'includes/class-fsbhoa-permission-compiler.php';

add_action('fsbhoa_run_background_sync', 'fsbhoa_perform_delta_sync');
add_action('fsbhoa_run_nightly_rebuild', 'fsbhoa_perform_nightly_rebuild_sync');
add_action('fsbhoa_run_daily_time_sync', 'fsbhoa_perform_daily_time_sync');

/**
 * fsbhoa_perform_delta_sync()
 */
function fsbhoa_perform_delta_sync() {
    global $wpdb;
    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    error_log("DELTA SYNC: Process started.");
    if ($is_dry_run) { error_log("DELTA SYNC: --- DRY RUN MODE ENABLED ---"); }
    
    set_time_limit(300);
    set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Starting delta sync...'], MINUTE_IN_SECONDS * 10);

    $active_schedule_id = fsbhoa_get_active_schedule_id();
    $active_schedule_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM ac_schedules WHERE schedule_id = %d", $active_schedule_id)) ?: 'Default';
    error_log("DELTA SYNC: Determined active schedule is: '" . $active_schedule_name . "' (ID: " . $active_schedule_id . ")");

    $change_types = $wpdb->get_col("SELECT DISTINCT change_type FROM ac_pending_changes");

    $cardholders_to_sync = [];
    $cardholders_to_delete = [];
    $high_impact_card_changes = ['group', 'controller', 'generic']; 

    if (!empty(array_intersect($high_impact_card_changes, $change_types))) {
        error_log("DELTA SYNC: High-impact change detected (" . implode(', ', $change_types) . "). Running Full Sync (No Wipe).");
        fsbhoa_perform_nightly_rebuild_sync(false);
        return;
    } else if (in_array('cardholder', $change_types)) {
        $changed_cardholder_ids = $wpdb->get_col("SELECT DISTINCT record_id FROM ac_pending_changes WHERE change_type = 'cardholder'");
        if (!empty($changed_cardholder_ids)) {
            $id_list = implode(',', array_map('absint', $changed_cardholder_ids));
            error_log("DELTA SYNC: Low-impact change detected. Processing cardholder IDs: {$id_list}");
            $cardholders_to_process = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE id IN ($id_list)");
            foreach ($cardholders_to_process as $cardholder) {
                if (in_array($cardholder->card_status, ['active', 'disabled'])) {
                    $cardholders_to_sync[] = $cardholder;
                } else {
                    $cardholders_to_delete[] = $cardholder;
                }
            }
        }
    }

    $task_related_changes = ['tasks', 'generic'];
    $task_sync_needed = !empty(array_intersect($task_related_changes, $change_types));

    if (empty($cardholders_to_sync) && empty($cardholders_to_delete) && !$task_sync_needed) {
        if (!$is_dry_run) { $wpdb->query("DELETE FROM ac_pending_changes"); }
        set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => 'No relevant changes to push.'], MINUTE_IN_SECONDS * 5);
        error_log("DELTA SYNC: No cardholder or task changes found. Exiting.");
        return;
    }

    $permission_data = fsbhoa_get_all_permission_data($active_schedule_id);
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE type = 'UHPPOTE'");
    
    fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $cardholders_to_delete, $task_sync_needed, $is_dry_run, false, false, $active_schedule_id);
}


/**
 * fsbhoa_perform_nightly_rebuild_sync()
 */
function fsbhoa_perform_nightly_rebuild_sync( $wipe_memory = true ) {
    error_log('[' . current_time('Y-m-d H:i:s T') . "] NIGHTLY REBUILD: Process started. Wipe Mode: " . ($wipe_memory ? 'ON' : 'OFF'));
    
    set_time_limit(600);
    global $wpdb;

    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    if ($is_dry_run) { error_log("NIGHTLY REBUILD: --- DRY RUN MODE ENABLED ---"); }

    $active_schedule_id = fsbhoa_get_active_schedule_id();
    error_log("NIGHTLY REBUILD: Determined active schedule ID is: " . $active_schedule_id);

    $permission_data = fsbhoa_get_all_permission_data($active_schedule_id);
    $cardholders_to_sync = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE card_status IN ('active', 'disabled')");
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE type = 'UHPPOTE'");

    fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, [], true, $is_dry_run, true, $wipe_memory, $active_schedule_id);
}

/**
 * fsbhoa_execute_sync_logic
 */
function fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $cardholders_to_delete, $task_sync_needed, $is_dry_run, $is_full_sync, $wipe_memory = false, $active_schedule_id = 1) {
    global $wpdb;
    $retry_attempts = 3; 
    $retry_wait = 250000; 
    $global_sync_failed = false;

    // --- STEP 1: COMPILE PERMISSIONS ---
    if (FSBHOA_DEBUG_MODE) { error_log("SYNC EXECUTE: initializing Permission Compiler (Force Rebuild: " . ($wipe_memory ? 'YES' : 'NO') . ")..."); }

    $compiler = new Fsbhoa_Permission_Compiler();
    $sync_artifacts = $compiler->generate_sync_data( $wipe_memory, $is_dry_run );
    
    if ($sync_artifacts === false) {
        set_transient('fsbhoa_sync_status', ['status' => 'failed', 'message' => 'Critical Error: Memory exhausted.'], MINUTE_IN_SECONDS * 10);
        error_log("CRITICAL SYNC ERROR: Compiler failed (likely memory exhaustion). Aborting sync.");
        return; 
    }

    $global_profiles = $sync_artifacts['profiles'];
    $global_card_perms = $sync_artifacts['cards'];

    // --- STEP 2: LOOP CONTROLLERS ---
    foreach ($controllers as $controller) {
        if (isset($controller->type) && $controller->type !== 'UHPPOTE') continue;

        $device_id = $controller->uhppoted_device_id;
        $friendly_name = $controller->friendly_name;

        // DEBUG: Controller Start
        error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is syncing.");

        set_transient('fsbhoa_sync_status', [
            'status'  => 'in_progress',
            'message' => 'Processing controller: ' . esc_html($friendly_name) . '...'
        ], MINUTE_IN_SECONDS * 10);

        // Check Online Status
        $status_output = shell_exec(sprintf('uhppote-cli --timeout 2s get-status %s 2>&1', $device_id));
        if (strpos($status_output, 'ERROR') !== false || empty(trim($status_output))) {
            if (FSBHOA_DEBUG_MODE) error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is offline. Skipping.");
            continue;
        }

        if (!$is_dry_run) { shell_exec(sprintf('uhppote-cli set-time %s 2>&1', $device_id)); }

        // --- STEP 3: WIPE MEMORY ---
        if ($is_full_sync && $wipe_memory) {
            error_log("SYNC SERVICE: Wiping card & profile memory on $friendly_name...");
            if (!$is_dry_run) {
                shell_exec(sprintf('uhppote-cli clear-time-profiles %s 2>&1', $device_id));
                shell_exec(sprintf('uhppote-cli delete-cards %s 2>&1', $device_id));
                sleep(1);
            } else {
                error_log("DRY RUN: Would execute clear-time-profiles and delete-cards on " . $device_id);
            }
        }

        // --- STEP 4: UPLOAD TIME PROFILES ---
        // Always upload profiles. It is fast (~20 commands) and ensures 
        // that if a single-user change created a "New Snowflake" ID, 
        // the profile exists on the controller before we write the card.
        $profiles_to_write = $global_profiles[$device_id] ?? [];
        if (!empty($profiles_to_write)) {
            foreach ($profiles_to_write as $profile_id => $data) {
                $parts = explode('|', $data['content']);
                $weekdays = $parts[0];
                $spans_string = "'" . $parts[1] . "'";
                $linked_profile_id = $data['link'];

                $command = sprintf("uhppote-cli set-time-profile %s %d %s %s %s %d",
                    $device_id, $profile_id, '2020-01-01:2099-12-31', $weekdays, $spans_string, $linked_profile_id
                );

                if ($is_dry_run) {
                    error_log("DRY RUN (PROFILE): " . $command);
                } else {
                    error_log("SYNC SERVICE (PROFILE): $command");
                    $success = false;
                    for ($i = 0; $i < $retry_attempts; $i++) {
                        $output = shell_exec($command . " 2>&1");
                        if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                            $success = true; break;
                        }
                        usleep($retry_wait);
                    }
                    if (!$success) {
                        error_log("SYNC FAILED (PROFILE $profile_id) for $friendly_name: $output");
                        $global_sync_failed = true; 
                    }
                }
            }
            if (!$is_dry_run) usleep(500000);  // 0.5s pause
        }

        // --- STEP 5: DELETE CARDS ---
        foreach ($cardholders_to_delete as $cardholder) {
            if (!empty($cardholder->rfid_id)) {
                error_log("SYNC SERVICE: Deleting card [{$cardholder->rfid_id}] (id:{$cardholder->id})");
                $cmd = sprintf('uhppote-cli delete-card %s %s', $device_id, $cardholder->rfid_id);
                if ($is_dry_run) error_log("DRY RUN: " . $cmd);
                else shell_exec($cmd . " 2>&1");
            }
        }

        // --- STEP 6: UPLOAD/UPDATE CARDS ---
        $puts_sent = 0;
        foreach ($cardholders_to_sync as $cardholder) {
            $rfid = $cardholder->rfid_id;
            if (empty($rfid)) continue;

            $perm_string = $global_card_perms[$rfid][$device_id] ?? ''; 

            $new_start = $cardholder->card_issue_date ?? '2000-01-01';
            $new_end   = $cardholder->card_expiry_date ?? '2099-12-31';
            $put_card_command = sprintf('uhppote-cli put-card %s %s %s %s %s', 
                $device_id, $rfid, $new_start, $new_end, $perm_string);

            // SMART SKIP
            if (!$wipe_memory) {
                $current_hash = md5($put_card_command);
                $cache_key = 'fsbhoa_card_hash_' . $device_id . '_' . $cardholder->id;
                $cached_hash = get_transient($cache_key);

                if ($cached_hash === $current_hash) {
                    continue; 
                }
                
                if (!$is_dry_run) {
                    set_transient($cache_key, $current_hash, DAY_IN_SECONDS * 7);
                }
            }

            // DEBUG: Log specific card update
            error_log("SYNC SERVICE: Updating card [$rfid] (id:{$cardholder->id}) perms:[$perm_string]");

            if ($is_dry_run) {
                error_log("DRY RUN (PUT CARD): " . $put_card_command);
            } else {
                $success = false;
                for ($i = 0; $i < $retry_attempts; $i++) {
                    $output = shell_exec($put_card_command . " 2>&1");
                    if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                        $success = true; break;
                    }
                    usleep($retry_wait);
                }
                if (!$success) {
                    error_log("SYNC FAILED (CARD $rfid) for $friendly_name: $output");
                    $global_sync_failed = true;
                }
                usleep(20000); 
            }
            $puts_sent++;
        }

        // --- STEP 7: SYNC TASKS ---
        if ($task_sync_needed || $is_full_sync) {
             fsbhoa_execute_task_sync($device_id, $controller->controller_record_id, $active_schedule_id, $is_dry_run, $retry_attempts);
        }

        if (FSBHOA_DEBUG_MODE) {
            error_log(sprintf("SYNC STATS '$friendly_name': Updates Sent: %d", $puts_sent));
        }

    } // End Controller Loop

    if (!$is_dry_run) {
        $wpdb->query("DELETE FROM ac_pending_changes");
    }

    if ($global_sync_failed) {
        set_transient('fsbhoa_sync_status', ['status' => 'failed', 'message' => 'Sync failed. Check error log.'], MINUTE_IN_SECONDS * 10);
        error_log("Sync FAILED. One or more commands returned 'false' or 'ERROR'.");
    } else {
        $final_message = ($is_full_sync) ? "Full sync complete." : "Delta sync complete.";
        if ($is_dry_run) { $final_message = "Dry run complete."; }
        set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => $final_message], MINUTE_IN_SECONDS * 5);
        error_log("Sync Complete.");
        fsbhoa_rebuild_monitor_status_cache();
    }
}


/**
 * Helper to handle Task Syncing (Clear and Add).
 */
function fsbhoa_execute_task_sync($device_id, $controller_id, $active_schedule_id, $is_dry_run, $retry_attempts) {
    global $wpdb;
    $retry_wait = 250000;

    $tasks = $wpdb->get_results($wpdb->prepare(
         "SELECT t.*, s.start_date, s.end_date, s.is_default
          FROM ac_task_list t
          JOIN ac_schedules s ON t.schedule_id = s.schedule_id
          WHERE t.enabled = 1 AND t.schedule_id = %d",
         $active_schedule_id
    ));

    $clear_task_list_command = sprintf('uhppote-cli clear-task-list %s 2>&1', $device_id);
    
    // DEBUG: Wiping Tasks
    error_log("SYNC SERVICE: Refreshing tasks on controller $device_id...");

    if (!$is_dry_run) {
        $output_clear_tasks = shell_exec($clear_task_list_command);
        if (strpos($output_clear_tasks, 'false') !== false || strpos($output_clear_tasks, 'ERROR') !== false) {
             error_log("SYNC WARNING (CLEAR TASKS) for $device_id: $output_clear_tasks");
        }
    } else {
        error_log("DRY RUN: Would execute: " . $clear_task_list_command);
    }

    foreach ($tasks as $task) {
        if ($task->controller_id === null || $task->controller_id == $controller_id) {
            $valid_from = ($task->is_default) ? '2025-01-01' : $task->start_date;
            $valid_to   = ($task->is_default) ? '2099-12-31' : $task->end_date;
            $weekdays = rtrim(($task->on_sun ? 'Sun,' : '') . ($task->on_mon ? 'Mon,' : '') . ($task->on_tue ? 'Tue,' : '') . ($task->on_wed ? 'Wed,' : '') . ($task->on_thu ? 'Thu,' : '') . ($task->on_fri ? 'Fri,' : '') . ($task->on_sat ? 'Sat,' : ''), ',');
            if (empty($weekdays)) $weekdays = '...'; 

            $doors_to_set = ($task->door_number === null) ? [1, 2, 3, 4] : [intval($task->door_number)];
            $task_description = '';
            switch (intval($task->task_type)) {
                case 1: $task_description = "'control door'"; break;
                case 2: $task_description = "'unlock door'"; break;
                case 3: $task_description = "'lock door'"; break;
                default: continue 2; 
            }

            foreach ($doors_to_set as $door) {
                $add_task_command = sprintf('uhppote-cli add-task %s %s %d %s:%s %s %s 0',
                    $device_id, $task_description, $door, $valid_from, $valid_to, $weekdays, substr($task->start_time, 0, 5));
                if ($is_dry_run) {
                    error_log("DRY RUN (TASK): " . $add_task_command);
                } else {
                    $success = false;
                    for ($i = 0; $i < $retry_attempts; $i++) {
                        $output = shell_exec($add_task_command . " 2>&1");
                        if (strpos($output, 'false') === false && strpos($output, 'ERROR') === false) {
                            $success = true; break;
                        }
                        usleep($retry_wait);
                    }
                }
            }
        }
    }
    
    $refresh_task_list_command = sprintf('uhppote-cli refresh-task-list %s 2>&1', $device_id);
    if (!$is_dry_run) {
        $output_refresh_tasks = shell_exec($refresh_task_list_command);
        if (strpos($output_refresh_tasks, 'false') !== false || strpos($output_refresh_tasks, 'ERROR') !== false) {
             error_log("SYNC FAILED (REFRESH TASKS) for $device_id: $output_refresh_tasks");
        }
    }
}

/**
 * fsbhoa_perform_daily_time_sync
 */
function fsbhoa_perform_daily_time_sync() {
    error_log("DAILY TIME SYNC: Process started.");
    global $wpdb;
    $controllers = $wpdb->get_results("SELECT uhppoted_device_id, friendly_name FROM ac_controllers WHERE type = 'UHPPOTE'");

    if (empty($controllers)) {
        error_log("DAILY TIME SYNC: No controllers found.");
        return;
    }

    foreach ($controllers as $controller) {
        $status_command = sprintf('uhppote-cli --timeout 2s get-status %s', $controller->uhppoted_device_id);
        $status_output = shell_exec($status_command . " 2>&1");

        if (strpos($status_output, 'ERROR') === false && !empty(trim($status_output))) {
            error_log("DAILY TIME SYNC: Setting time on " . $controller->friendly_name);
            shell_exec(sprintf('uhppote-cli set-time %s 2>&1', $controller->uhppoted_device_id));
        } else {
            error_log("DAILY TIME SYNC: Controller " . $controller->friendly_name . " is offline. Skipping.");
        }
    }
    error_log("DAILY TIME SYNC: Complete.");
}

