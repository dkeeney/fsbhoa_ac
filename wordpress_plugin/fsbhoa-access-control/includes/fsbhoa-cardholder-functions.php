<?php
/**
 * General utility functions for Cardholder operations.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/includes
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Archives a cardholder by setting their status to 'archived', saving their
 * current group memberships to a CSV field, and then removing their active
 * memberships to instantly revoke permissions.
 *
 * @param int $cardholder_id The ID of the cardholder to archive.
 * @return true|WP_Error True on success, WP_Error object on failure.
 */
function fsbhoa_archive_and_delete_cardholder( $cardholder_id ) {
    global $wpdb;
    $table_cardholders = 'ac_cardholders';
    $table_memberships = 'ac_cardholder_groups';

    // 1. Fetch the cardholder's current group memberships to preserve them.
    $group_ids = $wpdb->get_col( $wpdb->prepare( "SELECT group_id FROM {$table_memberships} WHERE cardholder_id = %d", $cardholder_id ) );
    if ( $wpdb->last_error ) {
        return new WP_Error( 'db_error_fetch_groups', 'Database error while fetching groups for archival. DB Error: ' . esc_html( $wpdb->last_error ) );
    }
    $groups_csv = implode( ',', $group_ids );

    // 2. Atomically update the cardholder record to mark it as archived.
    $updated = $wpdb->update(
        $table_cardholders,
        [
            'card_status' => 'archived',
            'deleted_at'  => current_time( 'mysql', 1 ), // Use WordPress's timezone-aware timestamp
            'groups_csv'  => $groups_csv
        ],
        [ 'id' => $cardholder_id ],
        [ '%s', '%s', '%s' ], // Data formats
        [ '%d' ]  // Where format
    );

    if ( false === $updated ) {
        return new WP_Error( 'db_error_update', 'Database error while archiving the cardholder. DB Error: ' . esc_html( $wpdb->last_error ) );
    }

    // 3. For security, remove all active group memberships to revoke permissions immediately.
    $deleted = $wpdb->delete( $table_memberships, [ 'cardholder_id' => $cardholder_id ], [ '%d' ] );
    if ( false === $deleted ) {
        // This is a critical failure, but the user is already archived. Log it.
        error_log( 'FSBHOA SECURITY WARNING: Failed to delete group memberships for archived cardholder ID ' . $cardholder_id . '. DB Error: ' . $wpdb->last_error );
    }

    return true;
}


/**
 * Generates a consistent event description from an event code.
 */
function fsbhoa_get_event_description($event_type, $door, $granted) {
    switch ($event_type) {
        case 0: return 'None';
        case 1: return 'Card swipe';
        case 2: return 'Door open';
        case 3: return 'Door close';
        case 4: return 'Button press';
        // Add other event types as needed
        default: return 'Unknown Event';
    }
}
