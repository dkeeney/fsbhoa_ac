<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the printable report for selected cardholders with dynamic sorting.
 */
function fsbhoa_render_cardholder_report_view() {
    // Retrieve the cardholder IDs from the URL parameter.
    if (empty($_GET['selected_ids'])) {
        echo '<div class="notice notice-warning"><p>No cardholders were selected. Please go back to the cardholder list and try again.</p></div>';
        echo '<a href="' . esc_url(get_permalink(get_page_by_path('cardholder'))) . '" class="button button-secondary">Back to Cardholder List</a>';
        return;
    }
    
    $ids_string = sanitize_text_field($_GET['selected_ids']);
    $cardholder_ids = array_map('absint', explode(',', $ids_string));

    // Get sort parameters from URL, with safe defaults.
    $orderby_col_index = isset($_GET['orderby_col']) ? absint($_GET['orderby_col']) : 2;
    $order_dir = isset($_GET['order_dir']) && in_array(strtolower($_GET['order_dir']), ['asc', 'desc']) ? strtoupper($_GET['order_dir']) : 'ASC';

    // Whitelist of allowed sort columns to prevent SQL injection.
    // Maps the JavaScript column index to a safe SQL ORDER BY clause.
    $sort_column_map = [
        2 => "c.last_name {$order_dir}, c.first_name {$order_dir}",
        3 => "p.street_name {$order_dir}, CAST(p.house_number AS UNSIGNED) {$order_dir}",
        4 => "c.cardholder_status {$order_dir}",
        5 => "c.resident_type {$order_dir}",
    ];

    // Default to sorting by name if an invalid or unsortable column index is provided.
    $orderby_sql = $sort_column_map[$orderby_col_index] ?? $sort_column_map[2];

    global $wpdb;
    $cardholders_table = 'ac_cardholders';
    $properties_table = 'ac_property';

    $sql = "
        SELECT c.*, p.street_address 
        FROM {$cardholders_table} c
        LEFT JOIN {$properties_table} p ON c.property_id = p.property_id
        WHERE c.id IN ({$ids_string})
    ";
    
    // Use the dynamic sort order.
    $sql .= " ORDER BY " . $orderby_sql;

    $cardholders = $wpdb->get_results( $sql );

    ?>
    <div class="fsbhoa-print-report-wrap">
        <h1>Cardholder Information Verification</h1>
        <p class="report-instructions">Please review the information below and provide any corrections.</p>
        
        <div class="report-actions">
            <button onclick="window.print();" class="button button-primary">Print This Page</button>
            <a href="<?php echo esc_url(get_permalink(get_page_by_path('cardholder'))); ?>" class="button button-secondary">Back to Cardholder List</a>
        </div>

        <?php foreach ( $cardholders as $cardholder ) : ?>
        <div class="cardholder-report-sheet">
            <h2><?php echo esc_html( $cardholder->first_name . ' ' . $cardholder->last_name ); ?></h2>
            
            <p class="report-sub-title">
                <strong>Name in property management db:</strong> 
                <?php echo esc_html( $cardholder->import_first_name . ' ' . $cardholder->import_last_name ); ?>
            </p>

            <div class="report-section">
                <div class="report-field">
                    <label>Preferred First Name:</label>
                    <span><?php echo esc_html( $cardholder->first_name ); ?></span>
                </div>
                <div class="report-field">
                    <label>Preferred Last Name:</label>
                    <span><?php echo esc_html( $cardholder->last_name ); ?></span>
                </div>
            </div>

            <div class="report-section">
                <div class="report-field">
                    <label>Title:</label>
                    <span><?php echo esc_html( $cardholder->title ); ?></span>
                </div>
                <div class="report-field">
                    <label>Resident Type:</label>
                    <span><?php echo esc_html( $cardholder->resident_type ); ?></span>
                </div>
            </div>
            
            <div class="report-section">
                <div class="report-field full-width">
                    <label>Property Address:</label>
                    <span><?php echo esc_html( $cardholder->street_address ); ?></span>
                </div>
            </div>

            <div class="report-section">
                <div class="report-field">
                    <label>Email:</label>
                    <span><?php echo esc_html( $cardholder->email ); ?></span>
                </div>
                <div class="report-field">
                    <label>Phone:</label>
                    <span><?php echo esc_html( $cardholder->phone . ' (' . $cardholder->phone_type . ')' ); ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

