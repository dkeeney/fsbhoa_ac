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
        
        error_log("--- SYSTEM ACTION DEBUG ---");
        error_log("1. Received request for service: '{$service}', command: '{$command}'");

        $allowed_services = [
            'fsbhoa-event-service.service', 
            'zebra_print_service.service', 
            'monitor_service.service', 
            'kiosk_service.service',
        ];
        $allowed_commands = ['start', 'stop', 'restart', 'status'];

        if ( !in_array($service, $allowed_services) || !in_array($command, $allowed_commands) ) {
            error_log("2. FAILED security check. Service or command not in whitelist.");
            wp_send_json_error(['message' => 'Invalid service or command specified.'], 400);
            return;
        }
        
        error_log("2. Passed security check.");

        // Using the absolute path to sudo is a more robust method.
        $exec_command = sprintf('/usr/bin/sudo /bin/systemctl %s %s', escapeshellarg($command), escapeshellarg($service));
        error_log("3. Executing command: {$exec_command}");

        $output = (string) shell_exec($exec_command . " 2>&1");
        error_log("4. Raw output from command: " . print_r($output, true));
        error_log("RAW HEX OUTPUT for {$service}: " . bin2hex($output));
        
        // Check for common error strings in the output of ANY command.
        $command_failed = (
            stripos($output, 'Failed') !== false ||
            stripos($output, 'Error') !== false ||
            stripos($output, 'password') !== false
        );

        if ($command === 'status') {
            $status = 'unknown'; // Default state
            if (!$command_failed) {
                if (preg_match('/Active:\s+active\s+\(running\)/', $output)) {
                    $status = 'running';
                } elseif (preg_match('/Active:\s+inactive\s+\(dead\)/', $output)) {
                    $status = 'stopped';
                }
            }
            error_log("5. Parsed status as: '{$status}'");
            wp_send_json_success(['status' => $status, 'raw' => $output]);
        } else {
            // For start/stop/restart, check our failure flag
            if ($command_failed) {
                error_log("5. Command failed. Raw output: " . $output);
                wp_send_json_error(['message' => 'Command failed on server.', 'raw' => $output]);
            } else {
                error_log("5. Command '{$command}' sent successfully.");
                wp_send_json_success(['status' => 'command_sent', 'message' => "Command '{$command}' sent to '{$service}'."]);
            }
        }
    }
}
