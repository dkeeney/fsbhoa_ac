<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_System_Actions {

    public function __construct() {
        add_action('wp_ajax_fsbhoa_manage_service', [ $this, 'ajax_manage_service' ]);
    }

public function ajax_manage_service() {
        check_ajax_referer('fsbhoa_system_status_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $service = sanitize_text_field($_POST['service']);
        $command = sanitize_text_field($_POST['command']);

        // This is the corrected list of allowed service names.
        $allowed_services = [
            'fsbhoa-events.service',
            'fsbhoa-monitor.service',
            'fsbhoa-zebra-printer.service',
            'fsbhoa-kiosk.service',
        ];
        $allowed_commands = ['start', 'stop', 'restart', 'status'];

        if ( !in_array($service, $allowed_services) || !in_array($command, $allowed_commands) ) {
            wp_send_json_error(['message' => 'Invalid service or command specified.'], 400);
            return;
        }

        $exec_command = sprintf('/usr/bin/sudo /bin/systemctl %s %s', escapeshellarg($command), escapeshellarg($service));
        error_log(">>> Executing Command: " . $exec_command);
        $output = (string) shell_exec($exec_command . " 2>&1");
        
        if ($command === 'status') {
            $status = 'unknown'; // Default state

            error_log("--- RAW STATUS FOR ($service) ---\n" . $output);
            if (preg_match('/Active:\s+active\s+\(running\)/', $output)) {
                $status = 'running';
            } elseif (preg_match('/Loaded:.*loaded.*disabled;/', $output)) {
                // Specifically check for the 'disabled' state first.
                $status = 'disabled';
            } elseif (preg_match('/Active:\s+(inactive|failed)/', $output)) {
                // Broader check for any 'inactive' or 'failed' state.
                $status = 'stopped';
            }
            
            wp_send_json_success(['status' => $status, 'raw' => $output]);

        } else {
            // For start/stop/restart commands
            $command_failed = (
                stripos($output, 'Failed') !== false ||
                stripos($output, 'Error') !== false
            );

            if ($command_failed) {
                wp_send_json_error(['message' => 'Command failed on server.', 'raw' => $output]);
            } else {
                wp_send_json_success(['status' => 'command_sent', 'message' => "Command '{$command}' sent to '{$service}'."]);
            }
        }
    }
}

