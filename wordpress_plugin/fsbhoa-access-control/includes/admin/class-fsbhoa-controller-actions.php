<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Controller_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_add_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_update_controller', [ $this, 'handle_form_submission' ]);
        add_action('admin_post_fsbhoa_delete_controller', [ $this, 'handle_delete_action' ]);
        add_action('admin_post_fsbhoa_discover_controllers', [ $this, 'handle_discover_action' ]);
        add_action('admin_post_fsbhoa_add_discovered_controllers', [ $this, 'handle_add_discovered_action' ]);
        add_action('wp_ajax_fsbhoa_sync_all_controllers', [ $this, 'ajax_handle_sync_all' ]);
        add_action('wp_ajax_fsbhoa_get_sync_status', [ $this, 'ajax_get_sync_status' ]);
    }

public function handle_form_submission() {
		global $wpdb;
		$is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_update_controller' );
		$item_id = $is_update ? absint($_POST['controller_record_id']) : 0;

		$nonce_action = $is_update ? 'fsbhoa_update_controller_' . $item_id : 'fsbhoa_add_controller';
		check_admin_referer($nonce_action, '_wpnonce');

		$errors = [];
		$submitted_ip = sanitize_text_field($_POST['ip_address']);
		$reverting_to_dhcp = ($is_update && (empty($submitted_ip) || $submitted_ip === '0.0.0.0'));

		$controller_data = [
			'friendly_name'        => sanitize_text_field($_POST['friendly_name']),
			'uhppoted_device_id'   => absint($_POST['uhppoted_device_id']),
			'ip_address'           => sanitize_text_field($_POST['ip_address']),
			'door_count'           => isset($_POST['door_count']) ? absint($_POST['door_count']) : 4,
			'is_static_ip'         => $reverting_to_dhcp ? 0 : 1, // It's static unless we are reverting to DHCP
			'notes'                => sanitize_textarea_field($_POST['notes']),
		];

		// --- Main Validation for Controller ---
		if (empty($controller_data['friendly_name'])) { $errors[] = 'Controller Name is required.'; }
		if (empty($controller_data['uhppoted_device_id'])) { $errors[] = 'Controller Device ID is required.'; }

		// --- Start Database Transaction ---
		$wpdb->query('START TRANSACTION');

		// --- Save Controller Data ---
		if (empty($errors)) {
			if ($is_update) {
				$result = $wpdb->update('ac_controllers', $controller_data, ['controller_record_id' => $item_id]);
			} else {
				$result = $wpdb->insert('ac_controllers', $controller_data);
				$item_id = $wpdb->insert_id; // Get the new controller ID for the gates
			}

			if ($result === false) {
				$errors[] = 'Database error saving controller details. ' . $wpdb->last_error;
			}
		}

		// --- Save Associated Gate Data (only in edit mode) ---
		if ($is_update && empty($errors) && isset($_POST['gates']) && is_array($_POST['gates'])) {
			foreach ($_POST['gates'] as $slot_number => $gate_data) {
				$door_record_id = absint($gate_data['door_record_id']);
				$friendly_name = sanitize_text_field($gate_data['friendly_name']);
				$notes = sanitize_textarea_field($gate_data['notes']);

				if (!empty($friendly_name)) {
					// This is an INSERT or UPDATE
					$data_to_save = [
						'controller_record_id' => $item_id,
						'door_number_on_controller' => absint($slot_number),
						'friendly_name' => $friendly_name,
						'notes' => $notes,
					];
					if ($door_record_id > 0) {
						// Update existing gate
						$result = $wpdb->update('ac_doors', $data_to_save, ['door_record_id' => $door_record_id]);
					} else {
						// Insert new gate
						$result = $wpdb->insert('ac_doors', $data_to_save);
					}
				} elseif ($door_record_id > 0) {
					// The name is empty but the ID exists, so DELETE it.
					$result = $wpdb->delete('ac_doors', ['door_record_id' => $door_record_id]);
				}

				// Check for errors on any of the gate operations
				if (isset($result) && $result === false) {
					$errors[] = "Database error on Gate Slot #{$slot_number}. " . $wpdb->last_error;
					break; // Stop processing more gates if one fails
				}
			}
		}

		// --- Interact with hardware if needed to set ip address to DHCP ---
		if ($is_update && empty($errors) && $reverting_to_dhcp) {
			if (FSBHOA_DEBUG_MODE) {
				error_log("CONTROLLER ACTIONS: Reverting device {$controller_data['uhppoted_device_id']} to DHCP.");
			}
			// This function is in 'fsbhoa-uhppote-discovery.php'
			fsbhoa_set_controller_ip($controller_data['uhppoted_device_id'], '0.0.0.0', '0.0.0.0', '0.0.0.0');
		}

		// --- Finalize Transaction ---
		if (empty($errors)) {
			$wpdb->query('COMMIT');
            $this->write_controllers_file();
		} else {
			$wpdb->query('ROLLBACK');
			// If there are errors, save data to a transient and redirect back to the form
			$transient_key = 'fsbhoa_controller_feedback_' . ($is_update ? 'edit_' . $item_id : 'add');
			set_transient($transient_key, ['errors' => $errors, 'data' => $_POST], MINUTE_IN_SECONDS * 5);

			wp_safe_redirect(add_query_arg('message', 'validation_error', wp_get_referer()));
			exit;
		}

		// --- Redirect on Success ---
		$list_page_url = remove_query_arg(['action', 'controller_id', 'message'], wp_get_referer());

		if ($reverting_to_dhcp) {
			$message_code = 'controller_set_to_dhcp';
		} else {
			$message_code = $is_update ? 'controller_updated' : 'controller_added';
		}

		$redirect_url = add_query_arg('message', $message_code, $list_page_url);
		wp_safe_redirect($redirect_url);
		exit;
    }


    public function handle_delete_action() {
        $item_id = absint($_GET['controller_id']);
        check_admin_referer('fsbhoa_delete_controller_nonce_' . $item_id, '_wpnonce');

        global $wpdb;
        $table_name = 'ac_controllers';
        $result = $wpdb->delete($table_name, ['controller_record_id' => $item_id]);
        $this->write_controllers_file();

        // Added database error checking
        if (false === $result) {
            wp_die('Database delete operation failed. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true]);
        }

        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $redirect_url = get_permalink();
        }

        // This is the correct block for the delete action
        $redirect_url = remove_query_arg( ['action', 'controller_id', '_wpnonce'], $redirect_url );
        $redirect_url = add_query_arg('message', 'controller_deleted', $redirect_url);

        wp_safe_redirect($redirect_url);
        exit;

    }

    /**
     * Handles the controller discovery process.
     */
    public function handle_discover_action() {
        check_admin_referer('fsbhoa_discover_controllers_nonce');
    
        $discovered_controllers = fsbhoa_discover_controllers_udp();

        global $wpdb;
        $table_name = 'ac_controllers';
        $db_controllers_raw = $wpdb->get_results("SELECT * FROM {$table_name}", ARRAY_A);

        $db_controllers = [];
        foreach ($db_controllers_raw as $c) {
            $db_controllers[$c['uhppoted_device_id']] = $c;
        }
    
        $results = [ 'updated' => [], 'missing' => [], 'new' => [], ];
    
        foreach ($discovered_controllers as $discovered) {
            $device_id = $discovered['device-id'];
            $ip_address = $discovered['address'];
    
            if (isset($db_controllers[$device_id])) {
                if ($db_controllers[$device_id]['ip_address'] !== $ip_address) {
                    $wpdb->update($table_name, ['ip_address' => $ip_address], ['uhppoted_device_id' => $device_id]);
                    $results['updated'][] = [
                        'friendly_name' => $db_controllers[$device_id]['friendly_name'],
                        'uhppoted_device_id' => $device_id,
                        'old_ip' => $db_controllers[$device_id]['ip_address'],
                        'new_ip' => $ip_address,
                    ];
                }
                unset($db_controllers[$device_id]);
            } else {
                $results['new'][] = $discovered;
            }
        }

        $results['missing'] = array_values($db_controllers);
        foreach ($results['missing'] as $missing_controller) {
            $wpdb->update($table_name, ['ip_address' => ''], ['uhppoted_device_id' => $missing_controller['uhppoted_device_id']]);
        }

        set_transient('fsbhoa_discovery_results', $results, MINUTE_IN_SECONDS * 5);

        $results_page_url = add_query_arg('discovery-results', 'true', wp_get_referer());
        wp_safe_redirect($results_page_url);
        exit;
    }

    /**
     * Handles adding the new controllers selected from the discovery results page.
     */
    public function handle_add_discovered_action() {
        check_admin_referer('fsbhoa_add_discovered_nonce', '_wpnonce');

        if (empty($_POST['new_controllers'])) {
            wp_safe_redirect(wp_get_referer());
            exit;
        }

        global $wpdb;
        $table_name = 'ac_controllers';

        foreach ($_POST['new_controllers'] as $device_id => $details) {
            // Check if the 'add' checkbox was checked and a name was provided
            if (isset($details['add']) && !empty($details['friendly_name'])) {
                $wpdb->insert($table_name, [
                    'uhppoted_device_id'   => absint($device_id),
                    'ip_address'           => sanitize_text_field($details['ip_address']),
                    'door_count'           => 4,
                    'friendly_name'        => sanitize_text_field($details['friendly_name']),
                ]);
            }
        }

        // Get the URL of the page that submitted the form
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            // As a fallback, build the URL to the main hardware page
            $redirect_url = add_query_arg('view', 'controllers', get_permalink( get_page_by_path('hardware') ));
        }

        // Clean up the URL from the discovery-results parameter
        $list_page_url = remove_query_arg( 'discovery-results', $redirect_url );

        $this->write_controllers_file();

        // Add a success message to the final URL
        $final_url = add_query_arg('message', 'controller_added', $list_page_url);

        wp_safe_redirect($final_url);
        exit;
    }

    /**
     * AJAX handler to kick off the background sync process.
     */
    public function ajax_handle_sync_all() {
        check_ajax_referer('fsbhoa_sync_nonce', 'nonce');

        // First, check if a sync is already scheduled or running to prevent duplicates.
        if (wp_next_scheduled('fsbhoa_run_background_sync')) {
            wp_send_json_success(['message' => 'Sync is already in progress.']);
            return;
        }

        // Schedule a single, one-off event to run as soon as possible.
        // This is the key to creating a reliable background process.
        wp_schedule_single_event(time(), 'fsbhoa_run_background_sync');

        // Set the initial "in progress" transient so the UI updates immediately.
        set_transient('fsbhoa_sync_status', ['status' => 'in_progress', 'message' => 'Sync process has been scheduled...'], MINUTE_IN_SECONDS * 5);

        // Tell the browser that the scheduling was successful.
        wp_send_json_success(['message' => 'Sync process scheduled successfully.']);
    }



    /**
     * AJAX handler to check the status of a sync.
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('fsbhoa_sync_nonce', 'nonce');
        error_log("AJAX GET STATUS: Polling function executed.");

        // First, check for the "sticky" final status flag in the options table.
        error_log("AJAX GET STATUS: >>> Checking for 'fsbhoa_sync_final_status' option.");
        $final_status = get_option('fsbhoa_sync_final_status');

        if ($final_status !== false) {
            error_log("AJAX GET STATUS: <<< FOUND final status option: " . json_encode($final_status));
            wp_send_json_success($final_status);

            // This runs after the browser has received the 'complete' message.
            error_log("AJAX GET STATUS: >>> Deleting 'fsbhoa_sync_final_status' option.");
            delete_option('fsbhoa_sync_final_status');
            error_log("AJAX GET STATUS: <<< Deleted final status option. Terminating poll check.");
            return; // We are done.
        }
        error_log("AJAX GET STATUS: <<< Final status option NOT found.");

        // If no final flag was found, check for a normal 'in-progress' transient.
        error_log("AJAX GET STATUS: >>> Checking for 'fsbhoa_sync_status' transient.");
        $in_progress_status = get_transient('fsbhoa_sync_status');

        if ($in_progress_status !== false) {
            error_log("AJAX GET STATUS: <<< FOUND in-progress transient: " . json_encode($in_progress_status));
            wp_send_json_success($in_progress_status);
        } else {
            error_log("AJAX GET STATUS: <<< In-progress transient NOT found. Sending 'idle'.");
            wp_send_json_success(['status' => 'idle', 'message' => '']);
        }
    }


/**
     * Queries the DB for all controller IDs and writes them to a JSON file.
     */
    private function write_controllers_file() {
        global $wpdb;
        $table_name = 'ac_controllers';
        $config_path = '/var/lib/fsbhoa/controllers.json';

        $controllers = $wpdb->get_results( "SELECT uhppoted_device_id FROM {$table_name}", ARRAY_A );

        if ( is_array( $controllers ) ) {
            $json_data = json_encode( $controllers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            // This will fail if /var/lib/fsbhoa isn't writable by www-data
            file_put_contents( $config_path, $json_data );
        }
    }
}

