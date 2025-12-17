<?php
// FILE: includes/fsbhoa-sync-functions.php
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Logs that a change has occurred which requires a sync to the controllers.
 * This adds a single row to the pending changes table.
 */
function fsbhoa_log_pending_change($change_type = 'generic', $record_id = null) {
    global $wpdb;
    $table_name = 'ac_pending_changes';

    // Don't log if a sync is already pending. We only care that *something* changed.
    // This is a simple way to keep the table small. A more advanced system might update timestamps.
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    if ($count > 0 && $change_type === 'generic') {
        return; // Don't flood the log with generic changes if a specific one is already there.
    }

    $wpdb->insert($table_name, [
        'change_type' => $change_type,
        'record_id'   => $record_id
    ]);

    if ($wpdb->last_error) {
        error_log('FSBHOA SYNC ERROR: Could not log a pending change. ' . $wpdb->last_error);
    }
}


