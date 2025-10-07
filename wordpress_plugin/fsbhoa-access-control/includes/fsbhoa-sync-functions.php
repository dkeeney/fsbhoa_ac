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
    $wpdb->query("INSERT INTO {$table_name} (id) VALUES (NULL)");

    if ($wpdb->last_error) {
        error_log('FSBHOA SYNC ERROR: Could not log a pending change. ' . $wpdb->last_error);
    }
}


/**
 * AJAX handler to get the current status of the sync process.
 * Returns a JSON object with the count of pending changes and the last status message.
 */
function fsbhoa_ajax_get_sync_status() {
    // Nonce check for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fsbhoa_sync_nonce')) {
        wp_send_json_error('Invalid security nonce.', 403);
        return;
    }
    
    global $wpdb;
    $count = $wpdb->get_var("SELECT COUNT(*) FROM ac_pending_changes");
    $transient = get_transient('fsbhoa_sync_status');

    $response_data = [
        'count'   => absint($count),
        'status'  => $transient ? $transient['status'] : 'idle', // e.g., 'in_progress' or 'idle'
        'message' => $transient ? $transient['message'] : '',
    ];
    
    wp_send_json_success($response_data);
}
add_action('wp_ajax_fsbhoa_get_sync_status', 'fsbhoa_ajax_get_sync_status');
