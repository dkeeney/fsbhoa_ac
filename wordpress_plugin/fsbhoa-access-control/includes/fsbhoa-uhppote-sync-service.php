<?php
// FILE: fsbhoa-uhppote-sync-service.php - FINAL VERSION

if (!defined('WPINC')) { die; }
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-permission-functions.php';

add_action('fsbhoa_run_background_sync', 'fsbhoa_perform_delta_sync');
add_action('fsbhoa_run_nightly_rebuild', 'fsbhoa_perform_nightly_rebuild_sync');

function fsbhoa_perform_delta_sync() {
    global $wpdb;
    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    error_log("DELTA SYNC: Process started.");
    if ($is_dry_run) { error_log("DELTA SYNC: --- DRY RUN MODE ENABLED ---"); }
    set_time_limit(300);
    set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Starting delta sync...'], MINUTE_IN_SECONDS * 10);

    $change_types = $wpdb->get_col("SELECT DISTINCT change_type FROM ac_pending_changes");

    $cardholders_to_sync = [];
    $cardholders_to_delete = [];

    //  Determine which cards to sync 
    $high_impact_card_changes = ['group', 'controller', 'generic'];

    if (!empty(array_intersect($high_impact_card_changes, $change_types))) {
        error_log("DELTA SYNC: High-impact change detected (" . implode(', ', $change_types) . "). Syncing all active cardholders.");
        $cardholders_to_sync = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE card_status = 'active'");
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
    $permission_data = fsbhoa_get_all_permission_data();
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers");
    fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $cardholders_to_delete, $task_sync_needed, $is_dry_run, false);
}



// --- Nightly Build ---
function fsbhoa_perform_nightly_rebuild_sync() {
    error_log("NIGHTLY REBUILD: Process started.");
    set_time_limit(600);
    global $wpdb;

    $is_dry_run = (get_option('fsbhoa_ac_sync_dry_run') === 'on');
    if ($is_dry_run) { error_log("NIGHTLY REBUILD: --- DRY RUN MODE ENABLED ---"); }

    // --- Find the active schedule for today ---
    $active_schedule_id = $wpdb->get_var(
        "SELECT schedule_id FROM ac_schedules WHERE is_default = 0 AND NOW() BETWEEN start_date AND DATE_ADD(end_date, INTERVAL 1 DAY) ORDER BY start_date DESC LIMIT 1"
    );
    if (!$active_schedule_id) {
        $active_schedule_id = 1; // Default to 1 if no holiday is found
    }
    error_log("NIGHTLY REBUILD: Determined active schedule ID is: " . $active_schedule_id);

    // Fetch permissions for the active schedule
    $permission_data = fsbhoa_get_all_permission_data($active_schedule_id);
    
    $cardholders_to_sync = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE card_status = 'active'");
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers");

    //  Pass the active schedule ID to the main sync logic
    fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, [], true, $is_dry_run, true, $active_schedule_id);
}

function fsbhoa_execute_sync_logic($controllers, $permission_data, $cardholders_to_sync, $cardholders_to_delete, $task_sync_needed, $is_dry_run, $is_rebuild, $active_schedule_id = 1) {
    global $wpdb;

    if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) {
        $ids_to_sync = [];
        foreach ($cardholders_to_sync as $cardholder) {
            $ids_to_sync[] = $cardholder->id;
        }
        error_log('SYNC EXECUTE: About to process ' . count($ids_to_sync) . ' Cardholder IDs: ' . implode(', ', $ids_to_sync));
    }


    
    $all_cardholders_permissions = [];
    foreach ($cardholders_to_sync as $cardholder) {
        $all_cardholders_permissions[$cardholder->id] = fsbhoa_calculate_cardholder_permissions($cardholder->id, $permission_data);
    }
    $chains_by_door = fsbhoa_build_profile_chains($all_cardholders_permissions);
    
    $db_cards_to_sync = [];
    foreach ($cardholders_to_sync as $cardholder) {
        if (!empty($cardholder->rfid_id)) { $db_cards_to_sync[$cardholder->rfid_id] = $cardholder; }
    }

    foreach ($controllers as $controller) {
        // Update the transient to show which controller is being processed.
        set_transient('fsbhoa_sync_status', [
            'status'  => 'in_progress',
            'message' => 'Processing controller: ' . esc_html($controller->friendly_name) . '...'
        ], MINUTE_IN_SECONDS * 10);

        $device_id = $controller->uhppoted_device_id;
        $controller_id = $controller->controller_record_id;
        $friendly_name = $controller->friendly_name;
        $puts_sent = 0;
        $deletes_sent = 0;

        $status_command = sprintf('uhppote-cli --timeout 2s get-status %s', $device_id);
        $status_output = shell_exec($status_command . " 2>&1");
        if (strpos($status_output, 'ERROR') !== false || empty(trim($status_output))) {
            if (FSBHOA_DEBUG_MODE) { 
               error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is offline. Skipping."); 
            }
            continue;
        }
        error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is syncing."); 


        if (!$is_dry_run) { shell_exec(sprintf('uhppote-cli set-time %s 2>&1', $device_id)); }

        $profile_map = fsbhoa_sync_time_profiles($device_id, $chains_by_door, $is_dry_run);

        if ($is_rebuild) {
            if (!$is_dry_run) { shell_exec(sprintf('uhppote-cli delete-cards %s 2>&1', $device_id)); }
            else { error_log("DRY RUN (REBUILD): Would execute: uhppote-cli delete-cards " . $device_id); }
        }

        foreach ($cardholders_to_delete as $cardholder) {
            if (!empty($cardholder->rfid_id)) {
                $delete_card_command = sprintf('uhppote-cli delete-card %s %s', $device_id, $cardholder->rfid_id);
                if ($is_dry_run) { error_log("DRY RUN (DELTA): Would execute: " . $delete_card_command); } 
                else { shell_exec($delete_card_command . " 2>&1"); }
                $deletes_sent++;
            }
        }
        
        foreach ($db_cards_to_sync as $card_number => $cardholder) {
            $cardholder_permissions = $all_cardholders_permissions[$cardholder->id] ?? null;
            $perms_for_this_controller = [];
            if ($cardholder_permissions && !isset($cardholder_permissions['all_access'])) {
                $door_ids_on_this_controller = $wpdb->get_col($wpdb->prepare("SELECT door_record_id FROM ac_doors WHERE controller_record_id = %d", $controller_id));
                if (!empty($door_ids_on_this_controller)) {
                    foreach ($cardholder_permissions as $perm) {
                        if (in_array($perm->door_id, $door_ids_on_this_controller)) { $perms_for_this_controller[] = $perm; }
                    }
                }
            } elseif (isset($cardholder_permissions['all_access'])) {
                $perms_for_this_controller = $cardholder_permissions;
            }
            $new_permissions_string = fsbhoa_build_card_permissions_string($cardholder, $perms_for_this_controller, $profile_map);
            $new_start_date = $cardholder->card_issue_date ?? '2000-01-01';
            $new_end_date = $cardholder->card_expiry_date ?? '2099-12-31';

            $put_card_command = sprintf('uhppote-cli put-card %s %s %s %s %s', $device_id, $card_number, $new_start_date, $new_end_date, $new_permissions_string);
            if ($is_dry_run) { error_log("DRY RUN: Would execute: " . $put_card_command); } 
            else { shell_exec($put_card_command . " 2>&1"); }
            $puts_sent++;
        }

        if ($task_sync_needed || $is_rebuild) {
            $tasks = $wpdb->get_results($wpdb->prepare("SELECT * FROM ac_task_list WHERE enabled = 1 AND schedule_id = %d", $active_schedule_id));
            if (!$is_dry_run) { shell_exec(sprintf('uhppote-cli clear-task-list %s 2>&1', $device_id)); } 
            else { error_log("DRY RUN: Would execute: uhppote-cli clear-task-list " . $device_id); }
            foreach ($tasks as $task) {
                if ($task->controller_id === null || $task->controller_id == $controller_id) {
                    $weekdays = rtrim(($task->on_sun ? 'Sun,' : '') . ($task->on_mon ? 'Mon,' : '') . ($task->on_tue ? 'Tue,' : '') . ($task->on_wed ? 'Wed,' : '') . ($task->on_thu ? 'Thu,' : '') . ($task->on_fri ? 'Fri,' : '') . ($task->on_sat ? 'Sat,' : ''), ',');
                    $doors_to_set = ($task->door_number === null) ? [1, 2, 3, 4] : [intval($task->door_number)];
                    $task_description = '';
                    switch (intval($task->task_type)) {
                        case 1: $task_description = "'control door'"; break;
                        case 2: $task_description = "'unlock door'"; break;
                        case 3: $task_description = "'lock door'"; break;
                        default: continue 2;
                    }
                    foreach ($doors_to_set as $door) {
                        $add_task_command = sprintf('uhppote-cli add-task %s %s %d %s:%s %s %s 0', $device_id, $task_description, $door, $task->valid_from, $task->valid_to, $weekdays, substr($task->start_time, 0, 5));
                        if ($is_dry_run) { error_log("DRY RUN: Would execute: " . $add_task_command); } 
                        else { shell_exec($add_task_command . " 2>&1"); }
                    }
                }
            }
            if (!$is_dry_run) { shell_exec(sprintf('uhppote-cli refresh-task-list %s 2>&1', $device_id)); } 
            else { error_log("DRY RUN: Would execute: uhppote-cli refresh-task-list " . $device_id); }
        }
        if (FSBHOA_DEBUG_MODE) {
            error_log(sprintf( "SYNC STATS for '%s': Processed %d cards to sync. Sent %d delete commands. Sent %d put commands.", $friendly_name, count($cardholders_to_sync), $deletes_sent, $puts_sent ));
        }
    }

    $wpdb->query("DELETE FROM ac_pending_changes");
    $final_message = ($is_rebuild) ? "Nightly rebuild complete." : "Delta sync complete.";
    if ($is_dry_run) { $final_message = "Dry run complete."; }
    set_transient('fsbhoa_sync_status', ['status' => 'complete', 'message' => $final_message], MINUTE_IN_SECONDS * 5);
    error_log("Sync Complete.");
}

