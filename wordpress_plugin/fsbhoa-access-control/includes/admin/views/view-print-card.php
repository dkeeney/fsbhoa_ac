<?php
/**
 * Renders the printable ID card preview and the interactive print/scan workflow UI.
 * This file is included by the [fsbhoa_print_card] shortcode handler.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Main function to generate the print preview and workflow UI.
 */
function fsbhoa_render_printable_card_view() {
    if ( ! isset( $_GET['cardholder_id'] ) || ! is_numeric( $_GET['cardholder_id'] ) ) {
        echo '<p>' . esc_html__( 'No cardholder ID provided.', 'fsbhoa-ac' ) . '</p>';
        return;
    }
    $cardholder_id = absint( $_GET['cardholder_id'] );

    global $wpdb;
    $cardholder = $wpdb->get_row( $wpdb->prepare("SELECT c.*, p.street_address FROM ac_cardholders c LEFT JOIN ac_property p ON c.property_id = p.property_id WHERE c.id = %d", $cardholder_id), ARRAY_A);

    if ( $wpdb->last_error ) {
        error_log('FSBHOA DB Error on print page query: ' . $wpdb->last_error);
        echo '<p>' . esc_html__('A database error occurred. Please contact an administrator.', 'fsbhoa-ac') . '</p>';
        return;
    }
    if ( ! $cardholder ) {
        echo '<p>' . esc_html__( 'Cardholder not found.', 'fsbhoa-ac' ) . '</p>';
        return;
    }

    // --- Prepare display variables ---
    $full_name = trim($cardholder['first_name'] . ' ' . $cardholder['last_name']);

    $photo_src = !empty($cardholder['photo']) ? 'data:image/jpeg;base64,' . base64_encode($cardholder['photo']) : '';
    $expiration_text = '';
    if ($cardholder['card_expiry_date'] && $cardholder['card_expiry_date'] !== '2099-12-31') {
        $expiration_text = date('m/d/Y', strtotime($cardholder['card_expiry_date']));
    }
    ?>

      <div class="fsbhoa-print-page-wrapper" data-debug-mode="<?php echo (get_option('fsbhoa_ac_print_debug_mode', 'off') === 'on') ? 'true' : 'false'; ?>">
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
                            <?php
                                // Parse the name specifically for the card display
                                $first_name_parts = explode(' ', $cardholder['first_name']);
                                $first_name_only = $first_name_parts[0];
                                $last_name = $cardholder['last_name'];
                            ?>
                            <p class="card-name"><?php echo esc_html($first_name_only); ?></p>
                            <p class="card-name"><?php echo esc_html($last_name); ?></p>
                            <?php if ($expiration_text): ?>
                                <p class="card-expires">Expires: <?php echo esc_html($expiration_text); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fsbhoa-cardholder-details-container">
                <h3>Cardholder Details</h3>
                <div class="details-box">
                    <p><strong>Name:</strong> <?php echo esc_html($full_name); ?></p>
                    <p><strong>Address:</strong> <?php echo esc_html($cardholder['street_address'] ?? 'N/A'); ?></p>
                    <p><strong>Phone:</strong> <?php echo esc_html($cardholder['phone'] ?? 'N/A'); ?></p>
                    <p><strong>Email:</strong> <?php echo esc_html($cardholder['email'] ?? 'N/A'); ?></p>
                    <p><strong>Resident Type:</strong> <?php echo esc_html($cardholder['resident_type'] ?? 'N/A'); ?></p>
                </div>
            </div>

        </div>

        <div class="workflow-container">
            <div id="fsbhoa-initial-section" class="workflow-section">
                <?php
                    // Check if Print Debug Mode (Dry Run) is active
                    if (get_option('fsbhoa_ac_print_debug_mode', 'off') === 'on') {
                        $settings_url = admin_url('admin.php?page=fsbhoa_print_service_settings');
                        echo '<div class="notice notice-warning inline" style="margin-bottom: 1em; padding: 10px;">';
                        echo '<p><strong>Debug Mode (Dry Run) is ON.</strong> The job will NOT be sent to the printer.</p>';
                        echo '</div>';
                    }
                ?>
                <p>Verify the card preview and details above are correct.</p>
                <button id="fsbhoa-start-print-btn" class="button button-primary button-hero"
                        data-cardholder-id="<?php echo esc_attr($cardholder_id); ?>">Start Print Process</button>
            </div>
            <div id="fsbhoa-status-section" class="workflow-section">
                <p class="status-message">Please wait...</p>
            </div>
            <div id="fsbhoa-rfid-section" class="workflow-section">
                <h3>Print Complete!</h3>
                <p>Please swipe the newly printed card now.</p>
                <input type="text" id="fsbhoa-rfid-input" maxlength="8" autofocus />
            </div>
            <div id="fsbhoa-dryrun-section" class="workflow-section">
                <h3>Dry Run Complete!</h3>
                <p>The image has been generated successfully.</p>
                <a href="#" id="fsbhoa-view-image-btn" class="button button-primary" target="_blank" rel="noopener noreferrer">View Generated Image</a>
            </div>
            <div class="fsbhoa-workflow-footer">
                <a href="<?php echo esc_url(get_permalink(get_page_by_path('cardholder'))); ?>" class="button button-secondary">Cancel and Go Back to List</a>
            </div>
        </div>
    </div>
    <?php
}

