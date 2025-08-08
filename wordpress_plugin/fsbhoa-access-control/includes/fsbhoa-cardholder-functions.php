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
 * Moves a cardholder record to the ac_deleted_cardholders table and then
 * deletes it from the main ac_cardholders table atomically using a transaction
 * and row-level locking to prevent race conditions.
 *
 * @param int $cardholder_id The ID of the cardholder to delete.
 * @return true|WP_Error True on success, WP_Error object on failure.
 */
function fsbhoa_archive_and_delete_cardholder( $cardholder_id ) {
    global $wpdb;
    $table_cardholders = 'ac_cardholders';
    $table_deleted_cardholders = 'ac_deleted_cardholders';

    // 1. Begin the transaction immediately.
    $wpdb->query( 'START TRANSACTION' );

    // 2. Fetch the full cardholder record and lock the row for this transaction.
    $cardholder_record = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table_cardholders} WHERE id = %d FOR UPDATE", $cardholder_id ),
        ARRAY_A
    );

    // DB Error Check for the SELECT
    if ( $wpdb->last_error ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'db_error_select', 'Database error while fetching cardholder to delete. DB Error: ' . esc_html( $wpdb->last_error ) );
    }

    // Check if the record exists *after* attempting to lock it.
    if ( ! $cardholder_record ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'not_found', 'Cardholder record not found to delete. It may have already been deleted.' );
    }

    // 3. Insert the (now locked and guaranteed current) record into the archive table.
    $inserted = $wpdb->insert( $table_deleted_cardholders, $cardholder_record );

    if ( false === $inserted ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'archive_failed', 'Failed to copy record to archive. DB Error: ' . esc_html( $wpdb->last_error ) );
    }

    // 4. Now, delete the record from the main table.
    $deleted = $wpdb->delete( $table_cardholders, array( 'id' => $cardholder_id ), array( '%d' ) );

    if ( false === $deleted ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'delete_failed', 'Failed to delete original record. DB Error: ' . esc_html( $wpdb->last_error ) );
    }

    // 5. All good, commit the changes to the database.
    $wpdb->query( 'COMMIT' );
    
    return true;
}


