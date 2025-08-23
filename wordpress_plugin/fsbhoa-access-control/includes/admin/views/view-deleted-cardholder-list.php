<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the front-end list of DELETED cardholders using a custom HTML table enhanced by DataTables.
 */
function fsbhoa_render_deleted_cardholder_list_view() {
    global $wpdb;
    $table_name = 'ac_deleted_cardholders';

    $deleted_cardholders = $wpdb->get_results( 
        "SELECT d.*, p.street_address 
         FROM {$table_name} d
         LEFT JOIN ac_property p ON d.property_id = p.property_id
         ORDER BY d.deleted_at DESC", 
        ARRAY_A 
    );
    if ( $wpdb->last_error ) {
        echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve deleted cardholders. ' . esc_html( $wpdb->last_error ) . '</p></div>';
        return;
    }

    $current_page_url = remove_query_arg('view');
    ?>
    <div class="fsbhoa-table-controls">
        <div class="fsbhoa-control-group">
            <label for="fsbhoa-deleted-custom-length-menu">Show</label>
            <select name="fsbhoa-deleted-custom-length-menu" id="fsbhoa-deleted-custom-length-menu">
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200">200</option>
                <option value="500">500</option>
                <option value="-1">All</option>
            </select>
            <span>entries</span>
        </div>

        <div class="fsbhoa-control-group">
            <label for="fsbhoa-deleted-cardholder-search-input">Search:</label>
            <input type="search" id="fsbhoa-deleted-cardholder-search-input" placeholder="Search deleted...">
        </div>
    </div>
    <table id="fsbhoa-deleted-cardholder-table" class="display" style="width:100%">
        <thead>
            <tr>
                <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Date Deleted', 'fsbhoa-ac' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty($deleted_cardholders) ) : foreach ( $deleted_cardholders as $cardholder ) : ?>
                <tr>
                    <td class="fsbhoa-actions-column">
                        <?php
                        $restore_nonce = wp_create_nonce( 'fsbhoa_restore_cardholder_' . $cardholder['id'] );
                        $restore_url = add_query_arg(
                            [
                                'action'        => 'fsbhoa_restore_deleted_cardholder',
                                'cardholder_id' => absint( $cardholder['id'] ),
                                '_wpnonce'      => $restore_nonce
                            ],
                            admin_url( 'admin-post.php' )
                        );
                        $preview_url = add_query_arg(
                            [
                                'view'          => 'deleted',
                                'action'        => 'preview_deleted',
                                'cardholder_id' => absint( $cardholder['id'] )
                            ],
                            $current_page_url
                        );
                        $merge_url = add_query_arg(['view' => 'deleted', 'action' => 'merge_cardholder', 'source_id' => absint($cardholder['id'])], $current_page_url);
                        $perm_delete_nonce = wp_create_nonce('fsbhoa_permanent_delete_' . $cardholder['id']);
                        $perm_delete_url = add_query_arg(['action' => 'fsbhoa_permanent_delete', 'cardholder_id' => absint($cardholder['id']), '_wpnonce' => $perm_delete_nonce], admin_url('admin-post.php'));
                        ?>
                        <a href="<?php echo esc_url($preview_url); ?>" class="fsbhoa-action-icon" title="Preview Archived Record">
                            <span class="dashicons dashicons-visibility"></span>
                        </a>
                        <a href="<?php echo esc_url($restore_url); ?>" class="fsbhoa-action-icon" title="Restore Cardholder" onclick="return confirm('Are you sure you want to restore this cardholder?');">
                            <span class="dashicons dashicons-undo"></span>
                        </a>
                        <a href="<?php echo esc_url($merge_url); ?>" class="fsbhoa-action-icon" title="Merge this record into a live record">
                            <span class="dashicons dashicons-controls-repeat"></span>
                        </a>
                        <a href="<?php echo esc_url($perm_delete_url); ?>" class="fsbhoa-action-icon" title="Permanently Delete" onclick="return confirm('WARNING: This action is permanent and cannot be undone. Are you sure you want to permanently delete this record?');">
                            <span class="dashicons dashicons-trash" style="color: #d63638;"></span>
                        </a>
                    </td>
                    <td><strong><?php echo esc_html( $cardholder['first_name'] . ' ' . $cardholder['last_name'] ); ?></strong></td>
                    <td><?php echo esc_html( $cardholder['street_address'] ?? 'N/A' ); ?></td>
                    <td><?php echo esc_html( $cardholder['email'] ); ?></td>
                    <td><?php echo esc_html( date( 'Y-m-d H:i:s', strtotime( $cardholder['deleted_at'] ) ) ); ?></td>
                </tr>
            <?php endforeach; 
              endif;
           ?> 
        </tbody>
    </table>
<?php
}
