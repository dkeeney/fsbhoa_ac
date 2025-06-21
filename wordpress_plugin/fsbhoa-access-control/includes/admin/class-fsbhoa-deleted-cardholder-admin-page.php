<?php
/**
 * Handles the display, preview, and restore actions for the Deleted Cardholders page.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Deleted_Cardholder_Admin_Page {


    /**
     * Main render method. Acts as a controller to show the correct view.
     */
    public function render_page() {

        // Determine which view to show based on the 'action' GET parameter.
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $message_code = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';

        ?>
        <div class="fsbhoa-frontend-wrap">
            <h1><?php esc_html_e( 'Deleted Cardholders', 'fsbhoa-ac' ); ?></h1>
            <a href="<?php echo esc_url( remove_query_arg('view') ); ?>" class="button">&larr; Back to All Cardholders</a>
            <hr style="margin-top: 1em; margin-bottom: 1em;">
            <?php
            // Display feedback messages from redirects
            if ( $message_code === 'cardholder_restored' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cardholder restored successfully.', 'fsbhoa-ac' ) . '</p></div>';
            }
            ?>
            <?php

            switch ( $action ) {
                case 'preview_deleted':
                    $this->render_preview_page();
                    break;
                default:
                    $this->render_list_page();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Renders the main list view by loading the new view file.
     */
    private function render_list_page() {
        // Include the new view file that uses a custom table with DataTables.
        require_once plugin_dir_path( __FILE__ ) . 'views/view-deleted-cardholder-list.php';

        // Call the render function from that file.
        fsbhoa_render_deleted_cardholder_list_view();
    }

    /**
     * Renders the read-only preview of a single deleted cardholder.
     */
    private function render_preview_page() {
        global $wpdb;
        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;

        if ( ! $cardholder_id ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Invalid cardholder ID.', 'fsbhoa-ac' ) . '</p></div>';
            return;
        }

        $table_name = 'ac_deleted_cardholders';
        $cardholder = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $cardholder_id ), ARRAY_A );

        if ( $wpdb->last_error ) {
            echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve the deleted cardholder record. ' . esc_html( $wpdb->last_error ) . '</p></div>';
            return;
        }

        if ( ! $cardholder ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not find the specified deleted cardholder.', 'fsbhoa-ac' ) . '</p></div>';
            return;
        }
        
        // --- Call our new, reusable preview function ---
        echo '<h2>Preview: ' . esc_html( $cardholder['first_name'] . ' ' . $cardholder['last_name'] ) . '</h2>';
        fsbhoa_render_cardholder_preview_html( $cardholder );
    }
}


