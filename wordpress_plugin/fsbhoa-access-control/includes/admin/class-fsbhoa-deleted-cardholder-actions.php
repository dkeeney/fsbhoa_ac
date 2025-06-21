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

        // 4. Prepare data for insertion
        $table_cardholders = 'ac_cardholders';
        $restore_data = $archived_record;
        $restore_data['origin'] = 'override';
        unset( $restore_data['id'] );
        unset( $restore_data['deleted_at'] );

        // 5. Insert the record back into the main `ac_cardholders` table
        $restored = $wpdb->insert( $table_cardholders, $restore_data );
        if ( false === $restored ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Failed to insert the record back into the main table. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        // 6. Delete the record from the `ac_deleted_cardholders` table
        $deleted = $wpdb->delete( $table_deleted, [ 'id' => $cardholder_id ], [ '%d' ] );
        if ( false === $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            wp_die( 'Failed to remove the record from the archive table after restoring. DB Error: ' . esc_html( $wpdb->last_error ), 'Error', ['back_link' => true] );
        }

        // 7. Commit transaction if all steps were successful
        $wpdb->query( 'COMMIT' );

        // 8. Redirect back with a cache-buster
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            $page_slug = 'cardholder';
            $redirect_url = add_query_arg( 'view', 'deleted', get_permalink( get_page_by_path( $page_slug ) ) );
        }
        $redirect_url = add_query_arg( [ 'message' => 'cardholder_restored', 'ts' => time() ], $redirect_url );
        $redirect_url = remove_query_arg( [ 'action', 'cardholder_id', '_wpnonce' ], $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }

}
