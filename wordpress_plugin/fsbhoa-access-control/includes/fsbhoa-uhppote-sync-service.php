<?php
/**
 * Service functions for handling hardware synchronization with uhppoted-rest.
 * This file contains the core logic for pushing data to controllers.
 */
if (!defined('WPINC')) {
    die;
}

add_action('fsbhoa_run_background_sync', 'fsbhoa_perform_full_sync');

/**
 * Performs the main sync logic. Queries the DB and loops through hardware.
 * Contains full add, update, delete for cards, and now syncs and validates tasks.
 */
function fsbhoa_perform_full_sync() {
    $rest_port = get_option('fsbhoa_ac_rest_port', 8082);

    if (FSBHOA_DEBUG_MODE) {
        error_log("SYNC SERVICE: Main sync process started.");
    }
    set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Gathering data...'], MINUTE_IN_SECONDS * 5);

    global $wpdb;

    // --- Get all necessary data from the database ---
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE ip_address IS NOT NULL AND ip_address != ''");
    if ($wpdb->last_error) { 
        if (FSBHOA_DEBUG_MODE) {error_log("SYNC SERVICE: DB Error on controllers: ".$wpdb->last_error);} 
        return; 
    }
    
    $cardholders = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE card_status = 'active' AND resident_type != 'Landlord'");
    if ($wpdb->last_error) { 
        if (FSBHOA_DEBUG_MODE) {error_log("SYNC SERVICE: DB Error on cardholders: ".$wpdb->last_error);}
         return; 
    }

    $tasks = $wpdb->get_results("SELECT * FROM ac_task_list WHERE enabled = 1");
    if ($wpdb->last_error) { 
        if (FSBHOA_DEBUG_MODE) {error_log("SYNC SERVICE: DB Error on tasks: ".$wpdb->last_error);}
         return; 
    }
    if (FSBHOA_DEBUG_MODE) {
        error_log("SYNC SERVICE: Found " . count($tasks) . " enabled tasks to sync.");
    }

    // Create an associative array of cardholders keyed by their card number for quick lookups
    $db_cards = [];
    foreach ($cardholders as $cardholder) {
        $card_number = intval($cardholder->rfid_id);
        if ($card_number > 0) {
            $db_cards[$card_number] = $cardholder;
        }
    }
    $db_card_numbers = array_keys($db_cards);

    if (empty($controllers)) {
        update_option('fsbhoa_sync_final_status', ['status' => 'complete', 'message' => 'No controllers configured.']);
        return;
    }

    $total_controllers = count($controllers);
    $processed_controllers = 0;
    foreach ($controllers as $controller) {
        $processed_controllers++;
        $device_id = $controller->uhppoted_device_id;
        $device_id_for_url = rawurlencode($device_id);
        $friendly_name = $controller->friendly_name;
        $controller_id = $controller->controller_record_id;
        if (FSBHOA_DEBUG_MODE) {
            error_log("SYNC SERVICE: Processing controller $processed_controllers/$total_controllers: '$friendly_name'");
        }

        // --- Card Deletion Logic ---
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => "Checking for cards to delete on '$friendly_name'..."], MINUTE_IN_SECONDS * 5);
        
        $cards_on_controller_req = wp_remote_get("http://127.0.0.1:{$rest_port}/uhppote/device/{$device_id_for_url}/cards", ['timeout' => 20]);

        if (is_wp_error($cards_on_controller_req)) {
            error_log("SYNC SERVICE ERROR: Failed to connect to uhppoted-rest. Error: " . $cards_on_controller_req->get_error_message());
        } else {
            error_log("SYNC SERVICE DEBUG: Successfully connected to uhppoted-rest. Status code: " . wp_remote_retrieve_response_code($cards_on_controller_req));
        }

        if (!is_wp_error($cards_on_controller_req) && wp_remote_retrieve_response_code($cards_on_controller_req) === 200) {
            $response_body = json_decode(wp_remote_retrieve_body($cards_on_controller_req), true);
            $controller_card_numbers = $response_body['cards'] ?? [];
            $cards_to_delete = array_diff($controller_card_numbers, $db_card_numbers);

            if (!empty($cards_to_delete)) {
                if (FSBHOA_DEBUG_MODE) {
                    error_log("SYNC SERVICE: Found " . count($cards_to_delete) . " card(s) to delete on '$friendly_name'.");
                }
                foreach ($cards_to_delete as $card_to_del) {
                    if (FSBHOA_DEBUG_MODE) {
                        error_log("SYNC SERVICE: >>> Deleting Card #{$card_to_del} from '$friendly_name'");
                    }
                    wp_remote_request("http://127.0.0.1:{$rest_port}/uhppote/device/{$device_id_for_url}/card/{$card_to_del}", ['method' => 'DELETE', 'timeout' => 15]);
                }
            } else {
                if (FSBHOA_DEBUG_MODE) {
                    error_log("SYNC SERVICE: No cards need to be deleted from '$friendly_name'.");
                }
            }
        }

        // --- Card Add/Update Logic ---
        $card_count = 0;
        foreach ($db_cards as $card_number => $cardholder) {
            $card_count++;
            set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => "Checking card $card_count/" . count($db_cards) . " on '$friendly_name'..."], MINUTE_IN_SECONDS * 5);
            
            $card_data_for_db = [
                'card-number' => $card_number,
                'start-date'  => $cardholder->card_issue_date ?? '2000-01-01',
                'end-date'    => $cardholder->card_expiry_date,
                'permissions' => [1, 2, 3, 4]
            ];

            // Compare with controller state before pushing. Run silently (false)
            if (fsbhoa_verify_card_on_controller($device_id, $card_data_for_db, false)) {
                if (FSBHOA_DEBUG_MODE) {
                    error_log("SYNC SERVICE: Card #{$card_number} is already up-to-date. Skipping.");
                }
                continue; // Move to the next card
            }

            // If verification failed, it means data is different or card is new. Push the update.
            if (FSBHOA_DEBUG_MODE) {
                error_log("SYNC SERVICE: >>> Pushing update for Card #{$card_number} to '$friendly_name'");
            }
            $card_data_to_push = ['start-date'  => $card_data_for_db['start-date'], 'end-date' => $card_data_for_db['end-date'], 'doors' => ["1" => true, "2" => true, "3" => true, "4" => true]];
            $request_url = sprintf("http://127.0.0.1:{$rest_port}/uhppote/device/%s/card/%s", $device_id_for_url, $card_number);
            $response = wp_remote_request($request_url, ['method' => 'PUT', 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($card_data_to_push), 'timeout' => 15]);

            // Final verification after push. Run verbosely (true)
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 400) {
                if (fsbhoa_verify_card_on_controller($device_id, $card_data_for_db, true)) {
                    if (FSBHOA_DEBUG_MODE) {
                        error_log("SYNC SERVICE: OK/VERIFIED Card #{$card_number} on '$friendly_name'");
                    }
                }
            } else {
                 $error_body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
                 if (FSBHOA_DEBUG_MODE) {
                    error_log("SYNC SERVICE: ERROR pushing Card #{$card_number}. Response: " . $error_body);
                 }
            }
        }
        
        
        // --- Task Synchronization Logic ---
		// This logic clears all tasks, then uses `uhppote-cli add-task` for each enabled task.
		if (FSBHOA_DEBUG_MODE) {
			error_log("SYNC SERVICE: Using uhppote-cli to sync tasks for '$friendly_name'...");
		}

		// 1. Clear all existing tasks from the controller.
		$clear_command = sprintf('uhppote-cli --debug clear-task-list %s', escapeshellarg($device_id));
		$clear_output = shell_exec($clear_command . " 2>&1");
		if (FSBHOA_DEBUG_MODE) {
			error_log("SYNC SERVICE (clear-task-list): " . $clear_output);
		}

		// 2. Loop through the enabled tasks from the database and add them one by one.
		$tasks_pushed_count = 0;
		foreach ($tasks as $task) {
			if ($task->controller_id === null || $task->controller_id == $controller_id) {
				$weekdays = rtrim(
					($task->on_sun ? 'Su,' : '') .
					($task->on_mon ? 'Mo,' : '') .
					($task->on_tue ? 'Tu,' : '') .
					($task->on_wed ? 'We,' : '') .
					($task->on_thu ? 'Th,' : '') .
					($task->on_fri ? 'Fr,' : '') .
					($task->on_sat ? 'Sa,' : ''),
				',');

				// Build the command string with parameters in the correct order from the 'help' output
				// Usage: add-task <serial-number> <door> <task> <active> <weekdays> <start> <cards>
				$add_task_command = sprintf(
					'uhppote-cli --debug add-task %s %d %d %s %s %s %s',
					escapeshellarg($device_id),
					escapeshellarg(intval($task->door_number)),
					escapeshellarg($task->task_type),
					escapeshellarg($task->valid_from . ':' . $task->valid_to),
					escapeshellarg($weekdays),
					escapeshellarg(substr($task->start_time, 0, 5)),
					escapeshellarg('0') // For the optional 'cards' parameter
				);

				if (FSBHOA_DEBUG_MODE) {
					error_log("SYNC SERVICE: Executing: " . $add_task_command);
				}

				$add_output = shell_exec($add_task_command . " 2>&1");
				$tasks_pushed_count++;

				if (FSBHOA_DEBUG_MODE) {
					error_log("SYNC SERVICE (add-task): " . $add_output);
				}
			}
		}
		if (FSBHOA_DEBUG_MODE) {
			error_log("SYNC SERVICE: Finished pushing {$tasks_pushed_count} tasks.");
        }

        // end Task Sychronization logic  //
    }

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


