<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the front-end list of DELETED cardholders using a custom HTML table enhanced by DataTables.
 */
function fsbhoa_render_deleted_cardholder_list_view() {
    global $wpdb;
    $table_name = 'ac_deleted_cardholders';
    
    // Fetch all deleted cardholders, ordered by deletion date descending
    $deleted_cardholders = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY deleted_at DESC", ARRAY_A );
    if ( $wpdb->last_error ) {
        echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve deleted cardholders. ' . esc_html( $wpdb->last_error ) . '</p></div>';
        return; // Stop rendering the rest of the view
    }
    
    // Get the current page URL without the 'view' parameter for action links
    $current_page_url = remove_query_arg('view');
?>
    <!--  Custom HTML Control Bar (Simplified for this view) -->
    <div class="fsbhoa-table-controls">
        <!-- Right Side: Container for Search -->
        <div class="fsbhoa-table-right-controls" style="justify-content: flex-end;">
            <div class="fsbhoa-control-group">
                <label for="fsbhoa-deleted-cardholder-search-input">Search:</label>
                <input type="search" id="fsbhoa-deleted-cardholder-search-input" placeholder="Search deleted...">
            </div>
        </div>
    </div>
    <!-- End Custom HTML Control Bar -->

    <table id="fsbhoa-deleted-cardholder-table" class="display" style="width:100%">
        <thead>
            <tr>
                <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Date Deleted', 'fsbhoa-ac' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty($deleted_cardholders) ) : foreach ( $deleted_cardholders as $cardholder ) : ?>
                <?php
                    // Create a nonce for the restore action
                    $restore_nonce = wp_create_nonce( 'fsbhoa_restore_cardholder_' . $cardholder['id'] );

                    // Build the URL for the restore action, pointing to admin-post.php
                    $restore_url = add_query_arg(
                        [
                            'action'        => 'fsbhoa_restore_deleted_cardholder',
                            'cardholder_id' => absint( $cardholder['id'] ),
                            '_wpnonce'      => $restore_nonce
                        ],
                        admin_url( 'admin-post.php' )
                    );

                    // Build the URL for the preview action
                    $preview_url = add_query_arg(
                        [
                            'view'          => 'deleted',
                            'action'        => 'preview_deleted',
                            'cardholder_id' => absint( $cardholder['id'] )
                        ],
                        $current_page_url
                    );
                ?>
                <tr>
                    <td class="fsbhoa-actions-column">
                        <a href="<?php echo esc_url($preview_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Preview Cardholder', 'fsbhoa-ac'); ?>">
                            <span class="dashicons dashicons-visibility"></span>
                        </a>
                        <a href="<?php echo esc_url($restore_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Restore Cardholder', 'fsbhoa-ac'); ?>" onclick="return confirm('Are you sure you want to restore this cardholder?');">
                            <span class="dashicons dashicons-undo"></span>
                        </a>
                    </td>
                    <td><strong><?php echo esc_html( $cardholder['first_name'] . ' ' . $cardholder['last_name'] ); ?></strong></td>
                    <td><?php echo esc_html( $cardholder['email'] ); ?></td>
                    <td><?php echo esc_html( date( 'Y-m-d H:i:s', strtotime( $cardholder['deleted_at'] ) ) ); ?></td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="4"><?php esc_html_e( 'No deleted cardholders found.', 'fsbhoa-ac' ); ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
<?php
}


