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

        // Whitelist of allowed services and commands for security
        $allowed_services = ['fsbhoa-event-service.service', 'zebra_print_service.service'];
        $allowed_commands = ['start', 'stop', 'restart', 'status'];

        if ( !in_array($service, $allowed_services) || !in_array($command, $allowed_commands) ) {
            wp_send_json_error(['message' => 'Invalid service or command specified.'], 400);
        }

        $exec_command = sprintf('sudo /bin/systemctl %s %s', escapeshellarg($command), escapeshellarg($service));
        $output = shell_exec($exec_command . " 2>&1");

        // For the 'status' command, parse the output to get a simple state
        if ($command === 'status') {
            if (strpos($output, 'Active: active (running)') !== false) {
                $status = 'running';
            } elseif (strpos($output, 'Active: inactive (dead)') !== false) {
                $status = 'stopped';
            } else {
                $status = 'unknown';
            }
            wp_send_json_success(['status' => $status, 'raw' => $output]);
        } else {
            // For other commands, just report success
            wp_send_json_success(['status' => 'command_sent', 'message' => "Command '{$command}' sent to '{$service}'."]);
        }
    }
}
