<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Discovers controllers by executing the uhppote-cli command directly.
 * We have proven this works when run as the www-data user.
 *
 * @return array An array of discovered controllers.
 */
function fsbhoa_discover_controllers_udp() {

    // The simple, direct command. We use the full path to be safe.
    $command = '/usr/local/bin/uhppote-cli get-devices 2>&1';

    // Execute the command as the current user (which will be www-data).
    $output = shell_exec($command);

    if (empty($output)) {
        // Return an empty array if the command failed or found nothing.
        return [];
    }

    $controllers = [];
    // Split the output into individual lines
    $lines = explode("\n", trim($output));

    foreach ($lines as $line) {
        // Ignore potential warning lines or empty lines
        if (empty(trim($line)) || str_contains(trim($line), 'WARN:')) {
            continue;
        }

        // Split each line by one or more spaces
        $parts = preg_split('/\s+/', trim($line));

        // We only need the first two columns: Device ID and IP Address
        if (count($parts) >= 2) {
            $controllers[] = [
                'device-id' => intval($parts[0]),
                'address'   => $parts[1]
            ];
        }
    }

    return $controllers;
}


