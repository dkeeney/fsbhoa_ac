<?php
/**
 * Renders the printable ID card view and contains the interactive print/scan workflow.
 * This file is included by the [fsbhoa_print_card] shortcode handler.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Main function to generate the printable card and workflow UI.
 */
function fsbhoa_render_printable_card_view() {
    if ( ! isset( $_GET['cardholder_id'] ) || ! is_numeric( $_GET['cardholder_id'] ) ) {
        return '<p>' . esc_html__( 'No cardholder ID provided.', 'fsbhoa-ac' ) . '</p>';
    }
    $cardholder_id = absint( $_GET['cardholder_id'] );

    global $wpdb;
    $cardholder = $wpdb->get_row( $wpdb->prepare("SELECT c.*, p.street_address FROM ac_cardholders c LEFT JOIN ac_property p ON c.property_id = p.property_id WHERE c.id = %d", $cardholder_id), ARRAY_A);

    if ( $wpdb->last_error ) {
        error_log('FSBHOA DB Error on print page query: ' . $wpdb->last_error);
        return '<p>' . esc_html__('A database error occurred. Please contact an administrator.', 'fsbhoa-ac') . '</p>';
    }
    if ( ! $cardholder ) {
        return '<p>' . esc_html__( 'Cardholder not found.', 'fsbhoa-ac' ) . '</p>';
    }

    //  Show the preview of the cardholder
    fsbhoa_render_cardholder_preview_html( $cardholder );
    
    ?>
    <div class="workflow-container">
        <div id="fsbhoa-initial-section" class="workflow-section">
            <p>Verify the card preview above is correct.</p>
            <button id="fsbhoa-start-print-btn" class="button button-primary button-hero" 
                data-cardholder-id="<?php echo esc_attr($cardholder_id); ?>">Start Print Process</button>
        </div>
        <div id="fsbhoa-status-section" class="workflow-section">
            <p class="status-message">Please wait...</p>
        </div>
        <div id="fsbhoa-rfid-section" class="workflow-section">
            <h3>Print Complete!</h3>
            <p>Please scan the newly printed card now.</p>
            <input type="text" id="fsbhoa-rfid-input" maxlength="8" autofocus />
        </div>
       <div class="fsbhoa-workflow-footer" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
             <a href="<?php echo esc_url(get_permalink(get_page_by_path('cardholder'))); ?>" class="button button-secondary">Go Back to List</a>
        </div>
    </div>

    <?php
}

