<?php
/**
 * Handles the display, preview, and restore actions for the Deleted Cardholders page.
 *
 * @package     Fsbhoa_Ac
 * @subpackage  Fsbhoa_Ac/admin
 * @author      FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Deleted_Cardholder_Admin_Page {

    /**
     * Main render method. Acts as a controller to show the correct view.
     */
    public function render_page() {
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $message_code = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : '';

        ?>
        <div class="fsbhoa-frontend-wrap">
            <h1><?php esc_html_e( 'Deleted Cardholders', 'fsbhoa-ac' ); ?></h1>

            <?php // The "Back" button now only shows on sub-pages
            if ( $action === 'preview_deleted' || $action === 'merge_cardholder' ) : ?>
                <a href="<?php echo esc_url( remove_query_arg(['action', 'cardholder_id', 'source_id']) ); ?>" class="button">&larr; Back to Deleted List</a>
            <?php endif; ?>

            <hr style="margin-top: 1em; margin-bottom: 1em;">
            
            <?php
            // Display feedback messages from redirects
            if ( $message_code === 'cardholder_restored' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cardholder restored successfully.', 'fsbhoa-ac' ) . '</p></div>';
            } elseif ( $message_code === 'merge_success' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cardholder merged successfully.', 'fsbhoa-ac' ) . '</p></div>';
            } elseif ( $message_code === 'perm_delete_success' ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cardholder permanently deleted.', 'fsbhoa-ac' ) . '</p></div>';
            }
            ?>

            <?php
            switch ( $action ) {
                case 'preview_deleted':
                    $this->render_preview_page();
                    break;
                case 'merge_cardholder':
                    require_once plugin_dir_path( __FILE__ ) . 'views/view-merge-deleted-cardholder.php';
                    fsbhoa_render_merge_cardholder_view();
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
     * Renders the main list view by loading the view file.
     */
    private function render_list_page() {
        require_once plugin_dir_path( __FILE__ ) . 'views/view-deleted-cardholder-list.php';
        fsbhoa_render_deleted_cardholder_list_view();
    }

    /**
     * Renders the read-only preview of a single deleted cardholder.
     */
    private function render_preview_page() {
        global $wpdb;
        $cardholder_id = isset( $_GET['cardholder_id'] ) ? absint( $_GET['cardholder_id'] ) : 0;
        if ( ! $cardholder_id ) { return; } // Simplified exit

        $cardholder = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM ac_deleted_cardholders WHERE id = %d", $cardholder_id ), ARRAY_A );
        if ( ! $cardholder ) { return; } // Simplified exit

        $property_address = 'N/A';
        if (!empty($cardholder['property_id'])) {
            $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM ac_property WHERE property_id = %d", $cardholder['property_id']));
        }
        
        echo '<h2>Preview: ' . esc_html( trim( ($cardholder['first_name'] ?? '') . ' ' . ($cardholder['last_name'] ?? '') ) ) . '</h2>';
        
        $this->render_card_preview_layout( $cardholder, $property_address );
    }


    
    /**
     * Renders the standard two-column card preview and details layout.
     *
     * @param array $cardholder The cardholder data array.
     * @param string $property_address The formatted property address.
     */
    private function render_card_preview_layout( $cardholder, $property_address ) {
        // --- Prepare display variables ---
        $first_name = trim($cardholder['first_name'] ?? '');
        $last_name = trim($cardholder['last_name'] ?? '');
        $full_name = $first_name . ' ' . $last_name;
        $title = trim($cardholder['title'] ?? '');
        $photo_src = !empty($cardholder['photo']) ? 'data:image/jpeg;base64,' . base64_encode($cardholder['photo']) : '';

        $subtitle_text = '';
        if (!empty($title)) {
            $subtitle_text = $title;
        } elseif (isset($cardholder['card_expiry_date']) && $cardholder['card_expiry_date'] !== '2099-12-31') {
            $expiration_text = date('m/d/Y', strtotime($cardholder['card_expiry_date']));
            $subtitle_text = 'Expires: ' . $expiration_text;
        }
        ?>
        <div class="fsbhoa-print-page-wrapper">
            <div class="fsbhoa-print-columns">
    
                <div class="fsbhoa-card-preview-container">
                    <h3>Card Preview</h3>
                    <div class="id-card-container">
                        <div class="id-card-body">
                            <div class="id-card-photo">
                                <?php if ($photo_src): ?>
                                    <img src="<?php echo esc_attr($photo_src); ?>" alt="Cardholder Photo">
                                <?php endif; ?>
                            </div>
                            <div class="id-card-info">
                                <p class="card-name"><?php echo esc_html($first_name); ?></p>
                                <p class="card-name"><?php echo esc_html($last_name); ?></p>
                                <?php if (!empty($subtitle_text)): ?>
                                    <p class="card-subtitle"><?php echo esc_html($subtitle_text); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fsbhoa-cardholder-details-container">
                    <h3>Cardholder Details</h3>
                    <div class="details-box">
                        <p><strong>Name:</strong> <?php echo esc_html($full_name); ?></p>

                        <?php if (!empty($cardholder['import_first_name']) || !empty($cardholder['import_last_name'])): ?>
                            <p><strong>Formal Name:</strong> <?php echo esc_html(trim($cardholder['import_first_name'] . ' ' . $cardholder['import_last_name'])); ?></p>
                        <?php endif; ?>
                         <?php if (!empty($title)): ?>
                            <p><strong>Title:</strong> <?php echo esc_html($title); ?></p>
                        <?php endif; ?>
                        <p><strong>Address:</strong> <?php echo esc_html($property_address ?? 'N/A'); ?></p>
                        <p><strong>RFID:</strong> <?php echo esc_html($cardholder['rfid_id'] ?: 'N/A'); ?></p>
                        <p><strong>Phone:</strong> <?php echo esc_html( !empty($cardholder['phone']) ? $cardholder['phone'] . ' (' . $cardholder['phone_type'] . ')' : 'N/A' ); ?></p>
                        <p><strong>Email:</strong> <?php echo esc_html($cardholder['email'] ?? 'N/A'); ?></p>
                        <p><strong>Resident Type:</strong> <?php echo esc_html($cardholder['resident_type'] ?? 'N/A'); ?></p>
                    </div>
                </div>
    
            </div>
        </div>
        <?php
    }

}
