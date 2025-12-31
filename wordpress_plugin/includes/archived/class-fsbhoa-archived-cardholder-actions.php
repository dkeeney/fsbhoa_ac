<?php
/**
 * Handles all admin-post actions for the Archived Cardholder management page.
 * REFACTORED FOR SOFT-DELETE ARCHITECTURE.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Archived_Cardholder_Actions {

    public function __construct() {
        add_action( 'admin_post_fsbhoa_restore_archived_cardholder', [ $this, 'handle_restore_action' ] );
        add_action( 'admin_post_fsbhoa_purge_archived_cardholder', [ $this, 'handle_purge_action' ] );
        add_action( 'admin_post_fsbhoa_confirm_merge', [ $this, 'handle_confirm_merge_action' ] );
        add_action( 'admin_post_fsbhoa_update_archived_notes', [ $this, 'handle_update_archived_notes_action' ] );
        add_action('wp_ajax_fsbhoa_update_archived_notes', [$this, 'ajax_update_archived_notes']);
    }

    /**
     * Handles restoring an archived cardholder back to 'inactive' status.
     * NOW WRAPPED IN A TRANSACTION FOR DATA INTEGRITY.
     */
    public function handle_restore_action() {
        global $wpdb;

        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) {
            wp_die( 'Invalid cardholder ID specified.', 'Error', ['back_link' => true] );
        }
        check_admin_referer( 'fsbhoa_restore_archived_cardholder_' . $cardholder_id );

        // --- START TRANSACTION ---
        $wpdb->query( 'START TRANSACTION' );

        $table_cardholders = 'ac_cardholders';

        $source_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_cardholders} WHERE id = %d FOR UPDATE", $cardholder_id ) );

        // DB ERROR CHECK
        if ( $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Database error while fetching archived data. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        $groups_csv = $source_record->groups_csv;

        $rfid_is_present = !empty($source_record->rfid_id);
        $new_status = $rfid_is_present ? 'active' : 'inactive';

        // --- CANARY #2: Log the decision-making process ---
        //error_log('RESTORE DEBUG: Value of rfid_id: ' . var_export($source_record->rfid_id, true));
        //error_log('RESTORE DEBUG: Result of !empty() check: ' . ($rfid_is_present ? 'TRUE' : 'FALSE'));
        //error_log('RESTORE DEBUG: Final chosen status: ' . $new_status);

        $result = $wpdb->update(
            $table_cardholders,
            [
                'card_status' => $new_status,
                'deleted_at'  => null,
                'groups_csv'  => null
            ],
            [ 'id' => $cardholder_id ],
            [ '%s', null, null ],
            [ '%d' ]
        );

        // DB ERROR CHECK
        if ( false === $result ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Database error while updating cardholder status during restore. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        if ( ! empty( $groups_csv ) ) {
            $table_memberships = 'ac_cardholder_groups';
            $wpdb->delete($table_memberships, ['cardholder_id' => $cardholder_id]);

            // DB ERROR CHECK
            if ( $wpdb->last_error ) {
                $wpdb->query( 'ROLLBACK' );
                wp_die( 'Database error while clearing old group memberships. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
            }

            $group_ids_to_restore = explode( ',', $groups_csv );
            foreach ( $group_ids_to_restore as $group_id ) {
                $group_id = absint( $group_id );
                if ( $group_id > 0 ) {
                    $inserted = $wpdb->insert( $table_memberships, [ 'cardholder_id' => $cardholder_id, 'group_id' => $group_id ], [ '%d', '%d' ] );
                    // DB ERROR CHECK
                    if ( false === $inserted ) {
                        $wpdb->query( 'ROLLBACK' );
                        wp_die( 'Database error while restoring group memberships. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
                    }
                }
            }
        }
        
        // --- COMMIT TRANSACTION ---
        $wpdb->query( 'COMMIT' );
        fsbhoa_log_pending_change('cardholder', $cardholder_id);

        $redirect_url = remove_query_arg( [ 'action', 'cardholder_id', '_wpnonce' ], wp_get_referer() );
        $redirect_url = add_query_arg( 'message', 'cardholder_restored', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handles "purging" a cardholder. This sets their status to 'purged',
     * hiding them from the UI but keeping them for historical reports.
     */
    public function handle_purge_action() {
        global $wpdb;
        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) {
            wp_die( 'Invalid cardholder ID specified.', 'Error', ['back_link' => true] );
        }
        check_admin_referer('fsbhoa_purge_cardholder_' . $cardholder_id);

        $result = $wpdb->update(
            'ac_cardholders',
            [ 'card_status' => 'purged' ],
            [ 'id' => $cardholder_id ],
            [ '%s' ],
            [ '%d' ]
        );

        // DB ERROR CHECK
        if ( false === $result ) {
            wp_die( 'Database error while purging the cardholder. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        $redirect_url = remove_query_arg( [ 'action', 'cardholder_id', '_wpnonce' ], wp_get_referer() );
        $redirect_url = add_query_arg( 'message', 'cardholder_purged', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handles the final merge action.
     * REFACTORED to use prepared statements for all database writes, ensuring
     * that binary photo data is handled safely and correctly.
     */
    public function handle_confirm_merge_action() {
        global $wpdb;
        check_admin_referer('fsbhoa_confirm_merge_nonce');
        if ( ! current_user_can('manage_options') ) {
            wp_die('You do not have permission to merge cardholders.');
        }

        $source_id = isset($_POST['source_cardholder_id']) ? absint($_POST['source_cardholder_id']) : 0;
        $destination_id = isset($_POST['destination_cardholder_id']) ? absint($_POST['destination_cardholder_id']) : 0;
        error_log("[MERGE ACTION START] Initiating merge from Source ID: {$source_id} to Destination ID: {$destination_id}");

        if ( ! $source_id || ! $destination_id || $source_id === $destination_id) {
            error_log("[MERGE ACTION ERROR] Invalid source or destination ID. Aborting.");
            wp_die( 'Invalid source or destination cardholder ID specified.', 'Error', ['back_link' => true] );
        }

        $wpdb->query( 'START TRANSACTION' );
        error_log("[MERGE ACTION DB] Transaction started.");

        $table_cardholders = 'ac_cardholders';
        $table_access_log = 'ac_access_log';
        $table_properties = 'ac_property';

        $source_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_cardholders} WHERE id = %d AND card_status = 'archived' FOR UPDATE", $source_id ), ARRAY_A );
        if ( ! $source_record ) {
            error_log("[MERGE ACTION ERROR] Could not find or lock source record ID: {$source_id}. Rolling back.");
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Could not find or lock the archived source record to merge.', 'Error', ['back_link' => true] );
        }

        // ---  Fetch Destination to compare ---
        $dest_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_cardholders} WHERE id = %d FOR UPDATE", $destination_id ), ARRAY_A );
        if ( ! $dest_record ) {
            error_log("[MERGE ACTION ERROR] Could not find or lock destination record ID: {$destination_id}. Rolling back.");
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Could not find or lock the destination record to merge into.', 'Error', ['back_link' => true] );
        }

        // --- LOGIC: Prioritize Destination for RFID and Photo ---
        // If Destination has an RFID, keep it. Otherwise, take the one from Source.
        $final_rfid = !empty($dest_record['rfid_id']) ? $dest_record['rfid_id'] : $source_record['rfid_id'];
    
        // If Destination has a Photo, keep it. Otherwise, take the one from Source.
        $final_photo = !empty($dest_record['photo']) ? $dest_record['photo'] : $source_record['photo'];

        // --- LOGIC: Auto-Activate ---
        // If the resulting record has an RFID, it should be 'active'.
        $new_status = !empty($final_rfid) ? 'active' : 'inactive';

        error_log("[MERGE LOGIC] Resulting RFID: $final_rfid | Resulting Status: $new_status");


        // Get the property ID from the source record before we do anything else.
        $manual_property_id = !empty($source_record['property_id']) ? absint($source_record['property_id']) : 0;
        error_log("[MERGE ACTION INFO] Source record property ID is: {$manual_property_id}");


        // --- STEP 1: Update all simple text/numeric data using a prepared statement. ---
        $new_status = 
        $text_sql = $wpdb->prepare(
            "UPDATE {$table_cardholders} SET
                rfid_id = %s, first_name = %s, last_name = %s, title = %s,
                email = %s, email_used = %d, phone = %s, phone_type = %s,
                card_status = %s, notes = %s, card_issue_date = %s,
                card_expiry_date = %s, resident_type = %s
            WHERE id = %d",
            $final_rfid, 
            $source_record['first_name'], 
            $source_record['last_name'], 
            $source_record['title'],
            $source_record['email'], 
            $source_record['email_used'], 
            $source_record['phone'], 
            $source_record['phone_type'],
            $new_status,
            $source_record['notes'], 
            $source_record['card_issue_date'],
            $source_record['card_expiry_date'], 
            $source_record['resident_type'],
            $destination_id
        );
        $updated_text = $wpdb->query($text_sql);
        if ( false === $updated_text ) {
            error_log("[MERGE ACTION ERROR] DB error merging text data: " . $wpdb->last_error . ". Rolling back.");
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Database error while merging text data. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true] );
        }
        error_log("[MERGE ACTION DB] Step 1: Merged text data. Rows affected: " . $updated_text);

        // --- STEP 2: Update the binary photo data in a separate, dedicated prepared statement. ---
        if (!empty($source_record['photo'])) {
            $photo_sql = $wpdb->prepare(
                "UPDATE {$table_cardholders} SET photo = %s WHERE id = %d",
                $final_photo,
                $destination_id
            );
            $updated_photo = $wpdb->query($photo_sql);
            if ( false === $updated_photo ) {
                error_log("[MERGE ACTION ERROR] DB error merging photo data: " . $wpdb->last_error . ". Rolling back.");
                $wpdb->query( 'ROLLBACK' );
                wp_die( 'Database error while merging the photo data. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true] );
            }
            error_log("[MERGE ACTION DB] Step 2: Merged photo data. Rows affected: " . $updated_photo);
        }

        // --- STEP 3: Re-link historical access logs. ---
        $relinked = $wpdb->update( $table_access_log, ['cardholder_id' => $destination_id], ['cardholder_id' => $source_id], ['%d'], ['%d'] );
        if ( false === $relinked ) {
            error_log("[MERGE ACTION ERROR] DB error re-linking access logs: " . $wpdb->last_error . ". Rolling back.");
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Database error while re-linking access logs. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true] );
        }
        error_log("[MERGE ACTION DB] Step 3: Relinked access logs. Rows affected: " . $relinked);

        // --- STEP 4: Purge the now-merged source record. ---
        $purged = $wpdb->update( $table_cardholders, ['card_status' => 'purged'], ['id' => $source_id], ['%s'], ['%d'] );
        if ( false === $purged ) {
            error_log("[MERGE ACTION ERROR] DB error purging source record: " . $wpdb->last_error . ". Rolling back.");
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Database error while purging the source record. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true] );
        }
        error_log("[MERGE ACTION DB] Step 4: Purged source record. Rows affected: " . $purged);

        // --- STEP 5: Clean up the orphaned property. ---
        if ( $manual_property_id > 0 ) {
            // Check the origin of the property
            $property_origin = $wpdb->get_var( $wpdb->prepare( "SELECT origin FROM {$table_properties} WHERE property_id = %d", $manual_property_id ) );

            // Only proceed if the property was manually created
            if ( $property_origin === 'manual' ) {
                // Count how many cardholders are still linked to this property
                $remaining_cardholders = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_cardholders} WHERE property_id = %d", $manual_property_id ) );
                error_log("[MERGE ACTION INFO] Checking manual property ID {$manual_property_id}. Found {$remaining_cardholders} remaining cardholders.");

                // If the property is now empty, delete it
                if ( $remaining_cardholders == 0 ) {
                    $deleted_property = $wpdb->delete( $table_properties, ['property_id' => $manual_property_id], ['%d'] );
                    
                    if ( false === $deleted_property ) {
                        error_log("[MERGE ACTION ERROR] DB error deleting orphaned property: " . $wpdb->last_error . ". Rolling back.");
                        $wpdb->query( 'ROLLBACK' );
                        wp_die( 'Database error while deleting orphaned manual property. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true] );
                    }
                    error_log("[MERGE ACTION DB] Step 5: Deleted orphaned manual property ID {$manual_property_id}. Rows affected: " . $deleted_property);
                }
            }
        }

        $wpdb->query( 'COMMIT' );

        // NOTE: if both source and destination had non-null but different rfid's then we 
        //       use the one from the destination.  Otherwise we would need to add code
        //       to remove the overwitten active rfid on the destination.
        if ($new_status == 'active') {
            fsbhoa_log_pending_change('cardholder', $destination_id);
        }

        error_log("[MERGE ACTION END] Commit successful. Redirecting.");

        $redirect_url = get_permalink(get_page_by_path('archived-cardholders'));
        if (!$redirect_url) {
             wp_die('Configuration Error: The page slug "archived-cardholders" was not found.', 'Error', ['back_link' => true]);
        }
        $redirect_url = add_query_arg( 'message', 'merge_success', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handles updating the notes for a single archived cardholder.
     */
    public function handle_update_archived_notes_action() {
        global $wpdb;

        // Security and permission checks
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'fsbhoa_update_archived_notes_nonce')) {
            wp_die('Security check failed.', 'Error', ['back_link' => true]);
        }
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.', 'Error', ['back_link' => true]);
        }

        $cardholder_id = isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0;
        if (!$cardholder_id) {
            wp_die('Invalid cardholder ID.', 'Error', ['back_link' => true]);
        }

        // Sanitize and update the notes
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $result = $wpdb->update(
            'ac_cardholders',
            ['notes' => $notes],
            ['id' => $cardholder_id],
            ['%s'],
            ['%d']
        );

        if ($result === false) {
            wp_die('Database error while updating notes.', 'Error', ['back_link' => true]);
        }
        
        // Redirect back to the preview page with a success message
        $redirect_url = wp_get_referer();
        $redirect_url = add_query_arg('message', 'notes_updated', $redirect_url);
        wp_safe_redirect($redirect_url);
        exit;
    }


    public function ajax_update_archived_notes() {
        check_ajax_referer('fsbhoa_archived_notes_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $cardholder_id = isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0;
        if (!$cardholder_id) {
            wp_send_json_error('Invalid cardholder ID.');
        }

        global $wpdb;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $result = $wpdb->update(
            'ac_cardholders',
            ['notes' => $notes],
            ['id' => $cardholder_id],
            ['%s'], ['%d']
        );

        if ($result === false) {
            wp_send_json_error('Database error updating notes: ' . $wpdb->last_error);
        }

        wp_send_json_success('Notes updated successfully.');
    }

}
