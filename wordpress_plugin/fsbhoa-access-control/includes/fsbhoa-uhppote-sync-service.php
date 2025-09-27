<?php
/**
 * Service functions for handling hardware synchronization with uhppoted-rest.
 * This file contains the core logic for pushing data to controllers.
 */
if (!defined('WPINC')) {
    die;
}
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-permission-functions.php';

add_action('fsbhoa_run_background_sync', 'fsbhoa_perform_full_sync');




/**
 * Performs the main sync logic. Queries the DB and loops through hardware.
 * Contains full add, update, delete for cards, and now syncs and validates tasks.
 */
function fsbhoa_perform_full_sync() {
    if (FSBHOA_DEBUG_MODE) {
        error_log("SYNC SERVICE: Main sync process started.");
    }
    set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Gathering data...'], MINUTE_IN_SECONDS * 5);

    global $wpdb;

    // --- Get all necessary data from the database ---
    $permission_data = fsbhoa_get_all_permission_data();
    if ($permission_data === false) {
        if (defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: DB Error fetching permission data."); }
        // Set a failed status
        update_option('fsbhoa_sync_final_status', ['status' => 'failed', 'message' => 'Error fetching permission data from the database.']);
        return;
    }

    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE ip_address IS NOT NULL AND ip_address != ''");
    $cardholders = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE card_status IN ('active', 'disabled') AND resident_type != 'Landlord'");
    $tasks = $wpdb->get_results("SELECT * FROM ac_task_list WHERE enabled = 1");

    if ($wpdb->last_error) {
        if (FSBHOA_DEBUG_MODE) {error_log("SYNC SERVICE: DB Error on fetching data: ".$wpdb->last_error);}
        return;
    }

    $db_cards = [];
    foreach ($cardholders as $cardholder) {
        $card_number = $cardholder->rfid_id;
        if (!empty($card_number)) {
            $db_cards[$card_number] = $cardholder;
        }
    }
    $db_card_numbers = array_keys($db_cards);

    if (empty($controllers)) {
        update_option('fsbhoa_sync_final_status', ['status' => 'complete', 'message' => 'No controllers configured.']);
        return;
    }

    if (FSBHOA_DEBUG_MODE) {
        error_log("SYNC SERVICE: finished gathering data");
    }

    $total_controllers = count($controllers);
    $processed_controllers = 0;
    foreach ($controllers as $controller) {
        $processed_controllers++;
        $device_id = $controller->uhppoted_device_id;
        $friendly_name = $controller->friendly_name;
        $controller_id = $controller->controller_record_id;

        // HEALTH CHECK BLOCK
        if (FSBHOA_DEBUG_MODE) {
            error_log("SYNC SERVICE: Checking status of controller '$friendly_name' ($device_id)...");
        }
        // Use a short 2-second timeout for a quick check
        $status_command = sprintf('uhppote-cli --timeout 2s get-status %s', $device_id);
        $status_output = shell_exec($status_command . " 2>&1");

        // If the output is empty or contains an error, the controller is offline.
        if (strpos($status_output, 'ERROR') !== false || empty(trim($status_output))) {
            if (FSBHOA_DEBUG_MODE) {
                error_log("SYNC SERVICE: Controller '$friendly_name' ($device_id) is offline or not responding. Skipping.");
            }
            continue; // Skip to the next controller in the loop
        }
        error_log("SYNC SERVICE - Controller: $device_id, $friendly_name");


        // Build and Sync Permission Time Profiles for this controller.
        $unique_schedules = fsbhoa_build_unique_schedules_for_controller($db_cards, $permission_data);
        $profile_map = fsbhoa_sync_time_profiles($device_id, $unique_schedules);


        // --- Card Deletion Logic ---
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => "Checking for cards to delete on '$friendly_name'..."], MINUTE_IN_SECONDS * 5);
        $controller_cards = [];
        $get_cards_command = sprintf('uhppote-cli get-cards %s', $device_id);
        if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: ".$get_cards_command); }
        $output = shell_exec($get_cards_command . " 2>&1");
        if (FSBHOA_DEBUG_MODE && !empty(trim($output))) { error_log("output: " . trim($output)); }
 
        if (!empty($output)) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (!empty($line)) {
                    $parts = preg_split('/\s+/', $line);
                    $card_number = $parts[0];
                    $controller_cards[$card_number] = $line; // Store with card number as the key
                }
            }
	}
        $cards_to_delete = array_diff_key($controller_cards, $db_cards);

error_log('SYNC DEBUG - Cards to Delete: ' . print_r(array_keys($cards_to_delete), true));
 
        if (!empty($cards_to_delete)) {
            if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: Found " . count($cards_to_delete) . " card(s) to delete on '$friendly_name'."); }
            foreach ($cards_to_delete as $card_to_del) {
                $delete_card_command = sprintf('uhppote-cli delete-card %s %d',  $device_id, $card_to_del);
                if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: ".$delete_card_command); }
                $output = shell_exec($delete_card_command . " 2>&1");
                if (FSBHOA_DEBUG_MODE && !empty(trim($output))) { error_log("output: " . trim($output)); }
 
            }
        } else {
            if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: No cards need to be deleted from '$friendly_name'."); }
        }

        // --- Card Add/Update Logic ---
        $card_count = 0;
        foreach ($db_cards as $card_number => $cardholder) {
            $card_count++;
            set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => "Checking card $card_count/" . count($db_cards) . " on '$friendly_name'..."], MINUTE_IN_SECONDS * 5);
            $permissions_string = fsbhoa_build_card_permissions_string($cardholder, $permission_data, $profile_map);
error_log("SYNC FINAL CHECK - Card Number Type: " . gettype($card_number) . ", Value: '" . $card_number . "'");
            $put_card_command = sprintf(
                'uhppote-cli put-card %s %s %s %s %s',
                $device_id,
                $card_number,
                $cardholder->card_issue_date ?? '2000-01-01',
                $cardholder->card_expiry_date,
                $permissions_string
            );

            if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: " . $put_card_command); }
            $output = shell_exec($put_card_command . " 2>&1");
            if (FSBHOA_DEBUG_MODE && !empty(trim($output))) { error_log("output: " . trim($output)); }
        }

        // --- Task Synchronization Logic ---
        if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: Using uhppote-cli to sync tasks for '$friendly_name'..."); }
        $clear_command = sprintf('uhppote-cli clear-task-list %s', $device_id);
        if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: ".$clear_command); }
        $output = shell_exec($clear_command . " 2>&1");
        if (FSBHOA_DEBUG_MODE && !empty(trim($output))) { error_log("output: " . trim($output)); }

        $tasks_pushed_count = 0;
        foreach ($tasks as $task) {
            if ($task->controller_id === null || $task->controller_id == $controller_id) {
                $weekdays = rtrim(($task->on_sun ? 'Sun,' : '') . ($task->on_mon ? 'Mon,' : '') . ($task->on_tue ? 'Tue,' : '') . ($task->on_wed ? 'Wed,' : '') . ($task->on_thu ? 'Thu,' : '') . ($task->on_fri ? 'Fri,' : '') . ($task->on_sat ? 'Sat,' : ''), ',');

                $doors_to_set = [];
                // Check if door_number is NULL, which means all doors for that controller
                if ($task->door_number === null) {
                    $doors_to_set = [1, 2, 3, 4];
                } else {
                    $doors_to_set[] = intval($task->door_number);
                }

                $task_description = '';
                switch (intval($task->task_type)) {
                    case 1: $task_description = "'control door'"; break;
                    case 2: $task_description = "'unlock door'"; break;
                    case 3: $task_description = "'lock door'"; break;
                    // Add other task types here as you use them
                    default:
                        if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: Unknown or unsupported task type ID: " . $task->task_type); }
                        continue; // Skip this invalid task
                }
                foreach ($doors_to_set as $door) {
                    $add_task_command = sprintf(
                        'uhppote-cli add-task %s %s %d %s:%s %s %s 0',
                        $device_id,
                        $task_description,
                        $door,
                        $task->valid_from ,
                        $task->valid_to,
                        $weekdays,
                        substr($task->start_time, 0, 5)
                    );
                    if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: Executing: " . $add_task_command); }
                    $output = shell_exec($add_task_command . " 2>&1");
                    if (FSBHOA_DEBUG_MODE && !empty(trim($output))) { error_log("output: " . trim($output)); }
                }
                $tasks_pushed_count++;
            }
        }
        //  Trigger the controller to immediately apply the new task list.
        $refresh_command = sprintf('uhppote-cli refresh-task-list %s', $device_id);
        if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: Executing: " . $refresh_command); }
        $output = shell_exec($refresh_command . " 2>&1");
        if (FSBHOA_DEBUG_MODE && !empty(trim($output))) { error_log("output: " . trim($output)); }
        if (FSBHOA_DEBUG_MODE) { error_log("SYNC SERVICE: Finished pushing {$tasks_pushed_count} tasks."); }
    }

    // *** CLEAR THE QUEUE ***
    global $wpdb;
    $wpdb->query("DELETE FROM ac_pending_changes");

    $final_message = "Sync and verification complete for all " . $total_controllers . " controllers.";
    update_option('fsbhoa_sync_final_status', ['status' => 'complete', 'message' => $final_message]);
    delete_transient('fsbhoa_sync_status');
    if (FSBHOA_DEBUG_MODE) error_log("SYNC SERVICE: --- Sync Process Finished ---");
}


/**
 * Verifies card details on a controller.
 */
function fsbhoa_verify_card_on_controller($device_id, $card_data, $log_mismatch = true) {
    $rest_port = get_option('fsbhoa_ac_rest_port', 8082);
    $card_number = $card_data['card-number'];
    $request_url = sprintf("http://127.0.0.1:{$rest_port}/uhppote/device/%s/card/%s", rawurlencode($device_id), rawurlencode($card_number));
    $response = wp_remote_get($request_url, ['timeout' => 10]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { 
        error_log("SYNC VERIFY(Card): Failed to get card {$card_number}."); 
        // A 404 is an expected result if the card is new, so we just return false without logging an error.
        if (wp_remote_retrieve_response_code($response) === 404) {
            return false;
        }
        // For any other error, log it if requested.
        if ($FSBHOA_DEBUG_MODE && log_mismatch) error_log("SYNC VERIFY(Card): Failed to get card {$card_number}.");
        return false;
    }
 
    
    $body = wp_remote_retrieve_body($response);
    $response_data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) { 
        if ($FSBHOA_DEBUG_MODE && log_mismatch) 
            error_log("SYNC VERIFY(Card): Failed to decode JSON for card {$card_number}."); 
        return false; 
    }

    
    $retrieved_card = $response_data['card'] ?? null;
    if (!$retrieved_card 
     || !isset($retrieved_card['start-date'], $retrieved_card['end-date'], $retrieved_card['doors']) 
     || !is_array($retrieved_card['doors'])) { 
        if (FSBHOA_DEBUG_MODE && $log_mismatch) 
            error_log("SYNC VERIFY(Card): Invalid API response for card {$card_number}. Full Response: " . $body); 
        return false; 
    }

    $dates_match = ($retrieved_card['start-date'] === $card_data['start-date'] 
                    && $retrieved_card['end-date'] === $card_data['end-date']);
    $retrieved_permissions = array_map('intval', array_keys(array_filter($retrieved_card['doors'])));
    sort($retrieved_permissions);
    sort($card_data['permissions']);
    $permissions_match = ($retrieved_permissions == $card_data['permissions']);

    if ((!$dates_match || !$permissions_match) && FSBHOA_DEBUG_MODE && $log_mismatch) { 
        error_log("SYNC VERIFY(Card): Mismatch for card {$card_number}. Dates Match: " . ($dates_match?'Yes':'No') . ", Perms Match: " . ($permissions_match?'Yes':'No')); 
        return false; 
    }

    return true;
}

/**
 * Verifies the list of tasks on a controller by calling the uhppoted-rest API.
 */
function fsbhoa_verify_tasks_on_controller($device_id, $expected_tasks) {
    $request_url = sprintf("http://127.0.0.1:{$rest_port}/uhppote/device/%s/tasks", rawurlencode($device_id));
    $response = wp_remote_get($request_url, ['timeout' => 10]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { 
        if (FSBHOA_DEBUG_MODE) {
            error_log("SYNC VERIFY(Task): Failed to get tasks for device {$device_id}."); 
        }
        return false; 
    }
    
    $body = wp_remote_retrieve_body($response);
    $response_data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) { 
        if (FSBHOA_DEBUG_MODE) {
            error_log("SYNC VERIFY(Task): Failed to decode JSON for tasks on device {$device_id}."); 
        }
        return false; 
    }
    
    $retrieved_tasks = $response_data['tasks'] ?? null;
    if (!is_array($retrieved_tasks)) { 
        if (FSBHOA_DEBUG_MODE) {
            error_log("SYNC VERIFY(Task): Invalid API response for tasks on device {$device_id}. Full Response: " . $body); 
        }
        return false; 
    }

    // Normalize both arrays for a reliable comparison, ignoring order.
    $normalize = function($task) {
        // The API returns 'weekday' not 'weekdays', and might have different case. Standardize it.
        if (isset($task['weekday'])) { $task['weekdays'] = $task['weekday']; unset($task['weekday']); }
        ksort($task); // Sort by key
        return json_encode($task); // Convert to a string for comparison
    };

    $expected_normalized = array_map($normalize, $expected_tasks);
    $retrieved_normalized = array_map($normalize, $retrieved_tasks);
    sort($expected_normalized);
    sort($retrieved_normalized);

    if ($expected_normalized != $retrieved_normalized) {
        if (FSBHOA_DEBUG_MODE) {
            error_log("SYNC VERIFY(Task): Task list mismatch for device {$device_id}.");
            error_log("Expected: " . print_r($expected_normalized, true));
            error_log("Retrieved: " . print_r($retrieved_normalized, true));
        }
        return false;
    }

    return true;
}

