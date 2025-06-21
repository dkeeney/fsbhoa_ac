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


/**
 * Renders a clean, consistent HTML preview of a cardholder's record.
 * Can be used for both live and deleted cardholders.
 *
 * @param array $cardholder_data The associative array of the cardholder's data.
 */
function fsbhoa_render_cardholder_preview_html( $cardholder_data ) {
    if ( ! is_array( $cardholder_data ) || empty( $cardholder_data ) ) {
        return;
    }

    // --- Prepare data for display ---
    $full_name = esc_html( $cardholder_data['first_name'] . ' ' . $cardholder_data['last_name'] );
    $photo_src = ! empty( $cardholder_data['photo'] ) ? 'data:image/jpeg;base64,' . base64_encode( $cardholder_data['photo'] ) : '';
    $resident_type = esc_html( $cardholder_data['resident_type'] );

    // Get property address - handle both live (JOINed) and deleted (needs lookup) records
    $property_address = 'N/A';
    if ( ! empty( $cardholder_data['street_address'] ) ) {
        // If street_address is already in the array (from a JOIN)
        $property_address = esc_html( $cardholder_data['street_address'] );
    } elseif ( ! empty( $cardholder_data['property_id'] ) ) {
        // If we only have property_id (from deleted table), look it up
        global $wpdb;
        $property_address = $wpdb->get_var( $wpdb->prepare( "SELECT street_address FROM ac_property WHERE property_id = %d", $cardholder_data['property_id'] ) );
        $property_address = esc_html( $property_address );
    }

    // Format expiry date
    $expiry_date_str = 'None';
    if ( ! empty( $cardholder_data['card_expiry_date'] ) && strpos( $cardholder_data['card_expiry_date'], '2099' ) === false ) {
        $expiry_date_str = date( 'm/d/Y', strtotime( $cardholder_data['card_expiry_date'] ) );
    }
    ?>
    <div class="id-card-container">
        <div class="id-card-header">FSBHOA Photo ID</div>
        <div class="id-card-body">
            <div class="id-card-photo">
                <?php if ( $photo_src ) : ?>
                    <img src="<?php echo $photo_src; ?>" alt="Cardholder Photo">
                <?php else: ?>
                    <div class="no-photo-placeholder"><span>No Photo</span></div>
                <?php endif; ?>
            </div>
            <div class="id-card-info">
                <p><strong>Name</strong><?php echo $full_name; ?></p>
                <p><strong>Resident Type</strong><?php echo $resident_type; ?></p>
                <p><strong>Property</strong><?php echo $property_address; ?></p>
                <p><strong>Expires</strong><?php echo $expiry_date_str; ?></p>
            </div>
        </div>
    </div>
    <?php
}
