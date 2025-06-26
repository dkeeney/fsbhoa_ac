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
 * Contains full add, update, delete, and verification functionality.
 */
function fsbhoa_perform_full_sync() {
    error_log("SYNC SERVICE: Main sync process started.");
    set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Gathering data...'], MINUTE_IN_SECONDS * 5);

    global $wpdb;

    // Get all necessary data from the database with error checking.
    $controllers = $wpdb->get_results("SELECT * FROM ac_controllers WHERE ip_address IS NOT NULL AND ip_address != ''");
    if ($wpdb->last_error) { error_log("SYNC SERVICE: DB Error on controllers: ".$wpdb->last_error); return; }
    
    $cardholders = $wpdb->get_results("SELECT * FROM ac_cardholders WHERE card_status = 'active' AND resident_type != 'Landlord'");
    if ($wpdb->last_error) { error_log("SYNC SERVICE: DB Error on cardholders: ".$wpdb->last_error); return; }

    // Create a simple array of card numbers that should exist.
    $db_card_numbers = array_map('intval', wp_list_pluck($cardholders, 'rfid_id'));

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
        error_log("SYNC SERVICE: Processing controller $processed_controllers/$total_controllers: '$friendly_name'");

        // --- DELETION LOGIC ---
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => "Checking for cards to delete on '$friendly_name'..."], MINUTE_IN_SECONDS * 5);
        
        $cards_on_controller_req = wp_remote_get("http://127.0.0.1:8082/uhppote/device/{$device_id_for_url}/cards", ['timeout' => 20]);
        if (!is_wp_error($cards_on_controller_req) && wp_remote_retrieve_response_code($cards_on_controller_req) === 200) {
            $response_body = json_decode(wp_remote_retrieve_body($cards_on_controller_req), true);
            $controller_card_numbers = $response_body['cards'] ?? [];

            $cards_to_delete = array_diff($controller_card_numbers, $db_card_numbers);

            if (!empty($cards_to_delete)) {
                error_log("SYNC SERVICE: Found " . count($cards_to_delete) . " card(s) to delete on '$friendly_name'.");
                foreach ($cards_to_delete as $card_to_del) {
                    error_log("SYNC SERVICE: >>> Deleting Card #{$card_to_del} from '$friendly_name'");
                    wp_remote_request("http://127.0.0.1:8082/uhppote/device/{$device_id_for_url}/card/{$card_to_del}", ['method' => 'DELETE', 'timeout' => 15]);
                }
            } else {
                error_log("SYNC SERVICE: No cards need to be deleted from '$friendly_name'.");
            }
        }
        
        // --- ADD/UPDATE LOGIC ---
        $total_cards = count($cardholders);
        $card_count = 0;
        foreach ($cardholders as $cardholder) {
            $card_count++;
            $card_number = !empty($cardholder->rfid_id) ? intval($cardholder->rfid_id) : 0;
            if ($card_number <= 0) continue;

            $status_message = "Syncing card $card_count/$total_cards to '$friendly_name'...";
            set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => $status_message], MINUTE_IN_SECONDS * 5);
            
            $card_data_to_push = [
                'start-date'  => $cardholder->card_issue_date ?? '2000-01-01',
                'end-date'    => $cardholder->card_expiry_date,
                'doors'       => ["1" => true, "2" => true, "3" => true, "4" => true]
            ];

            $request_url = sprintf("http://127.0.0.1:8082/uhppote/device/%s/card/%s", $device_id_for_url, $card_number);
            
            error_log("SYNC SERVICE: >>> Pushing Card #$card_number to '$friendly_name'");
            $response = wp_remote_request($request_url, ['method' => 'PUT', 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($card_data_to_push), 'timeout' => 15]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 400) {
                set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => "Verifying card $card_count/$total_cards..."], MINUTE_IN_SECONDS * 5);
                $card_data_for_verify = ['card-number' => $card_number, 'start-date' => $card_data_to_push['start-date'], 'end-date' => $card_data_to_push['end-date'], 'permissions' => [1,2,3,4]];
                if (fsbhoa_verify_card_on_controller($device_id, $card_data_for_verify)) {
                    error_log("SYNC SERVICE: OK/VERIFIED Card #$card_number on '$friendly_name'");
                } else {
                    error_log("SYNC SERVICE: VERIFICATION FAILED for Card #$card_number on '$friendly_name'");
                }
            } else { 
                $error_body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
                error_log("SYNC SERVICE: ERROR pushing Card #{$card_number}. Response: " . $error_body);
            }
        }
    }

    $final_message = "Sync and verification complete for all " . $total_controllers . " controllers.";
    update_option('fsbhoa_sync_final_status', ['status' => 'complete', 'message' => $final_message]);
    delete_transient('fsbhoa_sync_status');
    error_log("SYNC SERVICE: --- Sync Process Finished ---");
}

/**
 * Verifies a single card's details on a controller by calling the uhppoted-rest API.
 */
function fsbhoa_verify_card_on_controller($device_id, $card_data) {
    $card_number = $card_data['card-number'];
    $request_url = sprintf("http://127.0.0.1:8082/uhppote/device/%s/card/%s", rawurlencode($device_id), rawurlencode($card_number));
    $response = wp_remote_get($request_url, ['timeout' => 10]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        error_log("SYNC VERIFY: Failed to get card {$card_number} for verification.");
        return false;
    }
    
    $body = wp_remote_retrieve_body($response);
    $response_data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("SYNC VERIFY: Failed to decode JSON for card {$card_number}.");
        return false;
    }
    
    $retrieved_card = $response_data['card'] ?? null;

    if (!$retrieved_card || !isset($retrieved_card['start-date'], $retrieved_card['end-date'], $retrieved_card['doors']) || !is_array($retrieved_card['doors'])) {
        error_log("SYNC VERIFY: Invalid API response for card {$card_number}. Full Response: " . $body);
        return false;
    }

    $dates_match = ($retrieved_card['start-date'] === $card_data['start-date'] && $retrieved_card['end-date'] === $card_data['end-date']);
    
    // --- FIX: Convert the string keys from the response to integers before comparing ---
    $retrieved_permissions = array_map('intval', array_keys(array_filter($retrieved_card['doors'])));
    sort($retrieved_permissions); // Sort both arrays to ensure order doesn't affect comparison
    sort($card_data['permissions']);

    $permissions_match = ($retrieved_permissions == $card_data['permissions']);

    if (!$dates_match || !$permissions_match) {
        error_log("SYNC VERIFY: Mismatch for card {$card_number}. Dates Match: " . ($dates_match?'Yes':'No') . ", Perms Match: " . ($permissions_match?'Yes':'No'));
        return false;
    }

    return true;
}


