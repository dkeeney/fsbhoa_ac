<?php
// FILE: includes/fsbhoa-sync-functions.php
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Logs that a change has occurred which requires a sync to the controllers.
 * This adds a single row to the pending changes table.
 */
function fsbhoa_log_pending_change() {
    global $wpdb;
    $table_name = 'ac_pending_changes';

    // Insert a dummy row. We only care about the COUNT, so the data doesn't matter.
    // A column named 'id' with AUTO_INCREMENT is all that's needed.
    $wpdb->insert($table_name, array('id' => null));

    if ($wpdb->last_error) {
        error_log('FSBHOA SYNC ERROR: Could not log a pending change. ' . $wpdb->last_error);
    }
}
