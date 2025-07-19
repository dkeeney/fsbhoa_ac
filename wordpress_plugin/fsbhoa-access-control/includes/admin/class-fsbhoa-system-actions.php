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

        // This is the corrected list of allowed service names to match the systemd files.
        $allowed_services = [
            'fsbhoa-events.service',
            'fsbhoa-monitor.service',
            'fsbhoa-printer.service',
            'fsbhoa-kiosk.service',
        ];
        $allowed_commands = ['start', 'stop', 'restart', 'status'];

        if ( !in_array($service, $allowed_services) || !in_array($command, $allowed_commands) ) {
            wp_send_json_error(['message' => 'Invalid service or command specified.'], 400);
            return;
        }

        // Using the absolute path to sudo is a more robust method.
        $exec_command = sprintf('/usr/bin/sudo /bin/systemctl %s %s', escapeshellarg($command), escapeshellarg($service));
        
        $output = (string) shell_exec($exec_command . " 2>&1");

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
                } elseif (preg_match('/Active:\s+inactive\s+\(dead\)/', $output) || preg_match('/Active:\s+activating/', $output) || preg_match('/Active:\s+failed/', $output)) {
                    // Treat services that are in a restart loop or failed as 'stopped' for the UI.
                    $status = 'stopped';
                }
            }
            wp_send_json_success(['status' => $status, 'raw' => $output]);
        } else {
            // For start/stop/restart, check our failure flag
            if ($command_failed) {
                wp_send_json_error(['message' => 'Command failed on server.', 'raw' => $output]);
            } else {
                wp_send_json_success(['status' => 'command_sent', 'message' => "Command '{$command}' sent to '{$service}'."]);
            }
        }
    }
}

