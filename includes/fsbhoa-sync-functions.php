<?php
// FILE: includes/fsbhoa-sync-functions.php
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Logs that a change has occurred which requires a sync to the controllers.
 * This adds a single row to the pending changes table.
 */
function fsbhoa_log_pending_change($change_type = 'generic', $record_id = null, $change_data = '') {
    global $wpdb;
    $table_name = 'ac_pending_changes';

    $wpdb->insert($table_name, [
        'change_type' => $change_type,
        'record_id'   => $record_id,
        'change_data' => $change_data, // This stores your JSON rfid_id
        'changed_at'  => current_time('mysql')
    ]);

    if ($wpdb->last_error) {
        error_log('FSBHOA SYNC ERROR: Could not log a pending change. ' . $wpdb->last_error);
    } else {
        // === THE NEW HUB BROADCAST ===
        // This fires an event like 'fsbhoa_pending_change_cardholder'
        // or 'fsbhoa_pending_change_schedule' that other plugins can listen to.
        do_action("fsbhoa_pending_change_{$change_type}", $record_id, $change_data);
    }

}

/**
 * Returns the currently active Schedule ID.
 * * @return int
 */
function fsbhoa_get_active_schedule_id() {
    global $wpdb;
    $active_id = $wpdb->get_var(
          "SELECT schedule_id FROM ac_schedules
           WHERE is_default = 0
           AND NOW() >= start_date
           AND NOW() < DATE_ADD(end_date, INTERVAL 1 DAY)
           ORDER BY start_date DESC
           LIMIT 1"
    );
    return $active_id ? absint($active_id) : 1;
}


/**
 * Standardized Logging function.
 * Writes to debug.log with a specific prefix.
 * * @param string $message
 */
if ( ! function_exists( 'fsbhoa_log' ) ) {
    function fsbhoa_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "FSBHOA SYNC: " . $message );
        }
    }
}

/**
 * Helper to trigger a sync for a specific cardholder.
 * Instantiates the Sync Class and runs the update.
 * * @param int $cardholder_id
 * @return array|bool Result of the sync operation
 */
if ( ! function_exists( 'fsbhoa_sync_cardholder' ) ) {
    function fsbhoa_sync_cardholder( $cardholder_id ) {
        if ( ! class_exists( 'Fsbhoa_Access_Sync' ) ) {
            fsbhoa_log( "CRITICAL: Fsbhoa_Access_Sync class not found!" );
            return false;
        }

        $sync = new Fsbhoa_Access_Sync();
        return $sync->sync_single_user( $cardholder_id );
    }
}

/**
 * Helper to get a Controller's IP from the database.
 * * @param int|string $device_id (Serial Number)
 * @return string|false IP Address or false if not found
 */
if ( ! function_exists( 'fsbhoa_get_controller_ip' ) ) {
    function fsbhoa_get_controller_ip( $device_id ) {
        global $wpdb;
        $ip = $wpdb->get_var( $wpdb->prepare(
            "SELECT ip_address FROM ac_controllers WHERE uhppoted_device_id = %s LIMIT 1",
            $device_id
        ));
        return $ip ? $ip : false;
    }
}


/**
 * REBUILD HARDWARE CACHE
 * Updates the 'fsbhoa_monitor_cache' option.
 * This allows the dashboard/kiosk to load door status instantly without querying 
 * the hardware or complex joins every time the page loads.
 * This cache is probably NOT USED.  This is dead code.
 */
function fsbhoa_rebuild_monitor_status_cache() {
    global $wpdb;

    // Using 'type' as confirmed
    $results = $wpdb->get_results("
        SELECT 
            c.uhppoted_device_id, 
            c.friendly_name as controller_name,
            c.type,
            c.door_count,
            d.door_record_id,
            d.door_number_on_controller, 
            d.friendly_name as door_name,
            d.door_role
        FROM ac_controllers c
        LEFT JOIN ac_doors d ON c.controller_record_id = d.controller_record_id
        ORDER BY c.uhppoted_device_id ASC, d.door_number_on_controller ASC
    ", ARRAY_A);

    if (empty($results)) {
        return [];
    }

    $cache = [];
    foreach ($results as $row) {
        $serial = $row['uhppoted_device_id'];
        
        if (!isset($cache[$serial])) {
            $cache[$serial] = [
                'name'       => $row['controller_name'],
                'type'       => $row['type'],       // 'UHPPOTE' or 'VIRTUAL_KIOSK'
                'door_count' => $row['door_count'], // 1, 2, or 4
                'doors'      => []
            ];
        }

        if ($row['door_number_on_controller']) {
            $cache[$serial]['doors'][$row['door_number_on_controller']] = [
                'door_record_id' => $row['door_record_id'],
                'door_number'    => $row['door_number_on_controller'],
                'name'           => $row['door_name'],
                'role'           => $row['door_role']
            ];
        }
    }

    update_option('fsbhoa_monitor_status_cache', $cache, true);
    return $cache;
}


/**
 * 5. GET ALL PERMISSION DATA
 * Fetches all groups, permissions, and card assignments for the active schedule.
 * Used by legacy sync services or debugging tools.
 *
 * @return array
 */
if ( ! function_exists( 'fsbhoa_get_all_permission_data' ) ) {
    function fsbhoa_get_all_permission_data() {
        global $wpdb;
        $schedule_id = fsbhoa_get_active_schedule_id();

        $data = [];

        // 1. Active Groups
        $data['groups'] = $wpdb->get_results("SELECT * FROM ac_groups WHERE is_enabled = 1", OBJECT_K);

        // 2. Permissions for the Active Schedule
        $data['permissions'] = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM ac_group_permissions 
            WHERE is_enabled = 1 AND schedule_id = %d
        ", $schedule_id));

        // 3. Active/Disabled Cards (We fetch disabled too so we can explicitly block them)
        $data['cards'] = $wpdb->get_results("
            SELECT ch.id, cred.credential_value AS rfid_id, cred.status AS card_status
            FROM ac_cardholders ch
            JOIN ac_credentials cred ON ch.id = cred.cardholder_id
            WHERE cred.credential_type = 'MIFARE_BADGE'
            AND cred.status IN ('active', 'disabled')
            AND ch.cardholder_status NOT IN ('archived', 'purged')
        ");

        // 4. Cardholder-Group Links
        $memberships = $wpdb->get_results("SELECT cardholder_id, group_id FROM ac_cardholder_groups");
        foreach($memberships as $m) {
            $data['memberships'][$m->cardholder_id][] = $m->group_id;
        }

        return $data;
    }
}
