<?php
/**
 * Handles all admin-post actions for the Deleted Cardholder management page.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Deleted_Cardholder_Actions {

    /**
     * Constructor. Hooks the methods into WordPress admin-post actions.
     */
    public function __construct() {
        add_action( 'admin_post_fsbhoa_restore_deleted_cardholder', [ $this, 'handle_restore_action' ] );
        add_action( 'admin_post_fsbhoa_permanent_delete', [ $this, 'handle_permanent_delete_action' ] );
        add_action( 'admin_post_fsbhoa_confirm_merge', [ $this, 'handle_confirm_merge_action' ] );
    }

    /**
     * Handles the entire logic for restoring a deleted cardholder.
     * Uses a database transaction with SELECT...FOR UPDATE to prevent race conditions.
     */
    public function handle_restore_action() {
        global $wpdb;

        // 1. Validate the incoming request
        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) {
            wp_die( 'Invalid cardholder ID specified.', 'Error', ['back_link' => true] );
        }
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( $_GET['_wpnonce'] ) : '';

        // Verify nonce and user permissions
        if ( ! wp_verify_nonce( $nonce, 'fsbhoa_restore_cardholder_' . $cardholder_id ) || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Security check failed.', 'Error', ['response' => 403, 'back_link' => true] );
        }

        // 2. Begin database transaction immediately.
        $wpdb->query( 'START TRANSACTION' );

        // 3. Fetch the archived record and lock the row for this transaction.
        $table_deleted = 'ac_deleted_cardholders';
        $table_cardholders = 'ac_cardholders';
        $archived_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_deleted} WHERE id = %d FOR UPDATE", $cardholder_id ), ARRAY_A );

        // DB Error Check for the SELECT
        if ( $wpdb->last_error ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Database error while fetching and locking the archived record. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        // Check if the record was found. If not, another process may have just restored it.
        if ( ! $archived_record ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Could not find the archived record to restore. It may have already been restored by another process.', 'Error', ['back_link' => true] );
        }

        // --- START: DUPLICATE CHECK ---
        // 3a. Check for potential duplicates in the live table based on formal name and property.
        $first_name_to_check = $archived_record['import_first_name'];
        $last_name_to_check = $archived_record['import_last_name'];
        $property_id_to_check = $archived_record['property_id'];

        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table_cardholders} WHERE import_first_name = %s AND import_last_name = %s AND property_id = %d",
            $first_name_to_check,
            $last_name_to_check,
            $property_id_to_check
        ) );

        if ($existing_id) {
            $wpdb->query('ROLLBACK');
            $error_message = sprintf(
                'Cannot restore this cardholder. A record for "%s %s" at property ID %d already exists in the live table. Please use the Merge feature to combine the records.',
                esc_html($first_name_to_check),
                esc_html($last_name_to_check),
                absint($property_id_to_check)
            );
            wp_die( $error_message, 'Error', ['back_link' => true] );
        }
        // --- END: DUPLICATE CHECK ---

        // 4. Prepare data for insertion
        $table_cardholders = 'ac_cardholders';
        $restore_data = $archived_record;
        $restore_data['origin'] = 'override';

        // Separate the group data and remove fields that don't exist in the live table.
        $group_ids_to_restore = !empty($restore_data['groups_csv']) ? explode(',', $restore_data['groups_csv']) : [];
        unset( $restore_data['id'] );
        unset( $restore_data['deleted_at'] );
        unset( $restore_data['groups_csv'] );
        unset( $restore_data['id'] );
        unset( $restore_data['deleted_at'] );

        // 5. Insert the record back into the main `ac_cardholders` table
        $restored = $wpdb->insert( $table_cardholders, $restore_data );
        if ( false === $restored ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Failed to insert the record back into the main table. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }
        // 5a. Restore the group memberships for the newly inserted cardholder.
        if ( ! empty($group_ids_to_restore) ) {
            foreach ($group_ids_to_restore as $group_id) {
                $group_id = absint($group_id);
                if ($group_id > 0) {
                    $wpdb->insert($table_memberships, [
                        'cardholder_id' => $new_cardholder_id,
                        'group_id' => $group_id
                    ], ['%d', '%d']);

                    if ( $wpdb->last_error ) {
                        $wpdb->query('ROLLBACK');
                        wp_die('Failed to restore group memberships. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true]);
                    }
                }
            }
        }

        // 6. Delete the record from the `ac_deleted_cardholders` table
        $deleted = $wpdb->delete( $table_deleted, [ 'id' => $cardholder_id ], [ '%d' ] );
        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Failed to remove the record from the archive table after restoring. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        // 7. Commit transaction if all steps were successful
        $wpdb->query( 'COMMIT' );
        fsbhoa_log_pending_change();

        // 8. Redirect back with a cache-buster
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $page_slug = 'cardholder';
            $redirect_url = add_query_arg( 'view', 'deleted', get_permalink( get_page_by_path( $page_slug ) ) );
        }
        $redirect_url = add_query_arg( [ 'message' => 'cardholder_restored', 'ts' => time() ], $redirect_url );
        // Clean the URL of action parameters and add the success message.
        $redirect_url = remove_query_arg( ['action', 'cardholder_id', 'source_id', '_wpnonce', '_wp_http_referer'], $redirect_url );
        $redirect_url = add_query_arg( 'message', 'merge_success', $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handles the permanent deletion of a cardholder record from the archive table.
     */
    public function handle_permanent_delete_action() {
        global $wpdb;

        // 1. Validate the incoming request
        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) {
            wp_die( 'Invalid cardholder ID specified.', 'Error', ['back_link' => true] );
        }
        check_admin_referer('fsbhoa_permanent_delete_' . $cardholder_id, '_wpnonce');
        if ( ! current_user_can('manage_options') ) {
            wp_die('You do not have permission to permanently delete cardholders.');
        }

        // 2. Perform the delete operation
        $table_deleted = 'ac_deleted_cardholders';
        $result = $wpdb->delete( $table_deleted, [ 'id' => $cardholder_id ], [ '%d' ] );

        if ( false === $result ) {
            wp_die( 'Database error during permanent deletion. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true] );
        }

        $redirect_url = remove_query_arg( [ 'action', 'cardholder_id', '_wpnonce' ], wp_get_referer() );
        $redirect_url = add_query_arg( 'message', 'perm_delete_success', $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }


/**
     * Handles the final merge action.
     * Copies key data from an archived record to a live record, then deletes the archive.
     */
    public function handle_confirm_merge_action() {
        // --- START: DEBUGGING BLOCK ---
        error_log("MERGE DEBUG: handle_confirm_merge_action() function started.");
        error_log("MERGE DEBUG: Raw POST data: " . print_r($_POST, true));
        // --- END: DEBUGGING BLOCK ---
        
        global $wpdb;

        // 1. Validate the incoming request
        check_admin_referer('fsbhoa_confirm_merge_nonce');
        if ( ! current_user_can('manage_options') ) {
            wp_die('You do not have permission to merge cardholders.');
        }

        $source_id = isset($_POST['source_cardholder_id']) ? absint($_POST['source_cardholder_id']) : 0;
        $destination_id = isset($_POST['destination_cardholder_id']) ? absint($_POST['destination_cardholder_id']) : 0;

        if ( ! $source_id || ! $destination_id ) {
            wp_die( 'Invalid source or destination cardholder ID specified.', 'Error', ['back_link' => true] );
        }

        error_log("MERGE DEBUG: Nonce passed. Source ID: {$source_id}, Destination ID: {$destination_id}");

        // 2. Begin database transaction.
        $wpdb->query( 'START TRANSACTION' );
        
        $table_deleted = 'ac_deleted_cardholders';
        $table_cardholders = 'ac_cardholders';
        $table_memberships = 'ac_cardholder_groups';

        // 3. Fetch the archived record.
        $source_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_deleted} WHERE id = %d", $source_id ), ARRAY_A );

        if ( $wpdb->last_error || ! $source_record ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Could not find the archived record to merge.', 'Error', ['back_link' => true] );
        }

        // 4. Prepare the specific data to be merged/copied.
        $data_to_merge = [
            'rfid_id' => $source_record['rfid_id'],
            'first_name' => $source_record['first_name'],
            'last_name' => $source_record['last_name'],
            'title' => $source_record['title'],
            'email_used' => $source_record['email_used'],
            'photo' => $source_record['photo'],
            'notes' => $source_record['notes'],
            'card_status' => $source_record['card_status'],
            'card_issue_date' => $source_record['card_issue_date'],
            'card_expiry_date' => $source_record['card_expiry_date'],
            'resident_type' => $source_record['resident_type'],
        ];

        // 5. Update the live record with the data from the archive.
        $updated = $wpdb->update( $table_cardholders, $data_to_merge, ['id' => $destination_id] );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Database error while merging data into the live record. DB Error: ' . esc_html($wpdb->last_error), 'Error', ['back_link' => true] );
        }

        // 6. Restore the group memberships.
        $group_ids_to_restore = !empty($source_record['groups_csv']) ? explode(',', $source_record['groups_csv']) : [];
        if ( ! empty($group_ids_to_restore) ) {
            // First, clear any existing memberships for the destination user.
            $wpdb->delete($table_memberships, ['cardholder_id' => $destination_id]);

            // Now, insert the restored group memberships.
            foreach ($group_ids_to_restore as $group_id) {
                $group_id = absint($group_id);
                if ($group_id > 0) {
                    $wpdb->insert($table_memberships, [
                        'cardholder_id' => $destination_id,
                        'group_id' => $group_id
                    ], ['%d', '%d']);
                }
            }
        }

        // 7. Delete the now-merged record from the archive.
        $deleted = $wpdb->delete( $table_deleted, [ 'id' => $source_id ], [ '%d' ] );
        
        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Failed to remove the record from the archive table after merging. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        // 8. Commit transaction and log that a sync is needed.
        $wpdb->query( 'COMMIT' );
        fsbhoa_log_pending_change();

        // 9. Redirect back with a success message.
        // --- UPDATED: Use the correct page slug for the redirect ---
        $redirect_url = get_permalink(get_page_by_path('deleted-cardholders'));
        $redirect_url = add_query_arg( 'message', 'merge_success', $redirect_url );
        
        wp_safe_redirect( $redirect_url );
        exit;
    }
}
