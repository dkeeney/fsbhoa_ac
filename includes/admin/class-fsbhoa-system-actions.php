<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_System_Actions {

    public function __construct() {
        // Existing: Service Control & Status (Combined in one handler)
        add_action('wp_ajax_fsbhoa_manage_service', [ $this, 'ajax_manage_service' ]);

        // NEW: System Power Actions
        add_action('wp_ajax_fsbhoa_system_reboot', array($this, 'handle_system_reboot'));
        add_action('wp_ajax_fsbhoa_system_shutdown', array($this, 'handle_system_shutdown'));
        add_action('wp_ajax_fsbhoa_get_service_log', [ $this, 'ajax_get_service_log' ]);
    }

    /**
     *  Handles Start/Stop/Restart AND Status checks
     */
    public function ajax_manage_service() {
        check_ajax_referer('fsbhoa_system_status_nonce', 'nonce');
        
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }

        $service = sanitize_text_field($_POST['service']);
        $command = sanitize_text_field($_POST['command']);

        // Allowed Services
        $allowed_services = array_keys(Fsbhoa_System_Status_Page::get_services());
        $allowed_commands = ['start', 'stop', 'restart', 'status'];

        if ( !in_array($service, $allowed_services) || !in_array($command, $allowed_commands) ) {
            wp_send_json_error(['message' => 'Invalid service or command specified.'], 400);
            return;
        }

        // Execute systemctl
        $exec_command = sprintf('/usr/bin/sudo /usr/bin/systemctl %s %s', escapeshellarg($command), escapeshellarg($service));
        $output = (string) shell_exec($exec_command . " 2>&1");

        // --- STATUS CHECK LOGIC (From Production) ---
        if ($command === 'status') {
            $status = 'unknown'; 

            if (preg_match('/Active:\s+active\s+\(running\)/', $output)) {
                $status = 'running';
            } elseif (preg_match('/Loaded:.*loaded.*disabled;/', $output)) {
                $status = 'disabled';
            } elseif (preg_match('/Active:\s+(inactive|failed)/', $output)) {
                $status = 'stopped';
            }

            wp_send_json_success(['status' => $status, 'raw' => $output]);

        } else {
            // --- CONTROL LOGIC (Start/Stop) ---
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

    /**
     * Retrieves the last 100 lines of the systemd journal for the requested service.
     */
    public function ajax_get_service_log() {
        check_ajax_referer('fsbhoa_system_status_nonce', 'nonce');

        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Permission denied.');
        }

        $service = sanitize_text_field($_POST['service']);
        $allowed_services = array_keys(Fsbhoa_System_Status_Page::get_services());

        if ( !in_array($service, $allowed_services) ) {
            wp_send_json_error('Invalid service specified.');
        }

        // Query the system journal for this specific service
        $exec_command = sprintf('/usr/bin/sudo /bin/journalctl -u %s -n 100 --no-pager', escapeshellarg($service));
        $output = (string) shell_exec($exec_command . " 2>&1");

        if (empty(trim($output))) {
            $output = "No logs found. The service may not have been started recently, or systemd isn't capturing its standard output.";
        }

        wp_send_json_success(['log' => $output]);
    }

    /**
     *  Reboot Logic
     */
    public function handle_system_reboot() {
        check_ajax_referer('fsbhoa_power_action', 'nonce');
        $output = [];
        $return_code = 0;
        
        // Using /sbin/shutdown (Standard Debian/Pi path)
        exec('sudo /sbin/reboot 2>&1 &', $output, $return_code); 
        
        if ($return_code !== 0) {
            // Fallback: Try /usr/sbin/shutdown just in case
             exec('sudo /usr/sbin/reboot 2>&1 &', $output, $return_code);
        }

        if ($return_code !== 0) {
            wp_send_json_error("Command Failed (Code $return_code): " . implode(" ", $output));
        } else {
            wp_send_json_success('Reboot scheduled.');  // will probably not have time to send this.
        }
    }

    /**
     *  Shutdown Logic
     */
    public function handle_system_shutdown() {
        check_ajax_referer('fsbhoa_power_action', 'nonce');
        $output = [];
        $return_code = 0;
        
        exec('sudo /sbin/shutdown -h +1 2>&1 &', $output, $return_code); 
        
        if ($return_code !== 0) {
             exec('sudo /usr/sbin/shutdown -h +1 2>&1 &', $output, $return_code);
        }
        
        if ($return_code !== 0) {
             wp_send_json_error("Command Failed (Code $return_code): " . implode(" ", $output));
        } else {
            wp_send_json_success('Shutdown scheduled in one minute. Goodbye.');
        }
    }


}
