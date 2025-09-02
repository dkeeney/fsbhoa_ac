<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the front-end list of ARCHIVED cardholders using a custom HTML table.
 */
function fsbhoa_render_archived_cardholder_list_view() {
    global $wpdb;

    // UPDATED: Query the main cardholders table for archived records.
    $archived_cardholders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.*, p.street_address
             FROM ac_cardholders c
             LEFT JOIN ac_property p ON c.property_id = p.property_id
             WHERE c.card_status = %s
             ORDER BY c.deleted_at DESC",
            'archived'
        ),
        ARRAY_A
    );

    if ( $wpdb->last_error ) {
        echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve archived cardholders. ' . esc_html( $wpdb->last_error ) . '</p></div>';
        return;
    }

    $current_page_url = remove_query_arg('view');
    ?>
    <div class="fsbhoa-table-controls">
        <div class="fsbhoa-control-group">
            <label for="fsbhoa-archived-custom-length-menu">Show</label>
            <select name="fsbhoa-archived-custom-length-menu" id="fsbhoa-archived-custom-length-menu">
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200">200</option>
                <option value="500">500</option>
                <option value="-1">All</option>
            </select>
            <span>entries</span>
        </div>

        <div class="fsbhoa-control-group">
            <label for="fsbhoa-archived-cardholder-search-input">Search:</label>
            <input type="search" id="fsbhoa-archived-cardholder-search-input" placeholder="Search archived...">
        </div>
    </div>
    <table id="fsbhoa-archived-cardholder-table" class="display" style="width:100%">
        <thead>
            <tr>
                <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></th>
                <th><?php esc_html_e( 'Date Archived', 'fsbhoa-ac' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( ! empty($archived_cardholders) ) : foreach ( $archived_cardholders as $cardholder ) : ?>
                <tr>
                    <td class="fsbhoa-actions-column">
                        <?php
                        // UPDATED: All links and nonces changed from 'deleted' to 'archived' or 'purged'.
                        $restore_nonce = wp_create_nonce( 'fsbhoa_restore_archived_cardholder_' . $cardholder['id'] );
                        $restore_url = add_query_arg(
                            [
                                'action'        => 'fsbhoa_restore_archived_cardholder',
                                'cardholder_id' => absint( $cardholder['id'] ),
                                '_wpnonce'      => $restore_nonce
                            ],
                            admin_url( 'admin-post.php' )
                        );
                        $preview_url = add_query_arg(
                            [
                                'action'        => 'preview_archived',
                                'cardholder_id' => absint( $cardholder['id'] )
                            ],
                            $current_page_url
                        );
                        $merge_url = add_query_arg(['action' => 'merge_cardholder', 'source_id' => absint($cardholder['id'])], $current_page_url);
                        $purge_nonce = wp_create_nonce('fsbhoa_purge_cardholder_' . $cardholder['id']);
                        $purge_url = add_query_arg(['action' => 'fsbhoa_purge_archived_cardholder', 'cardholder_id' => absint($cardholder['id']), '_wpnonce' => $purge_nonce], admin_url('admin-post.php'));
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
                        <a href="<?php echo esc_url($purge_url); ?>" class="fsbhoa-action-icon" title="Purge Record (Hide from view)" onclick="return confirm('WARNING: This will hide the record from this list. It will be retained for historical reports but cannot be restored. Are you sure?');">
                            <span class="dashicons dashicons-minus" style="color: #d63638;"></span>
                        </a>
                    </td>
                    <td><strong><?php echo esc_html( $cardholder['first_name'] . ' ' . $cardholder['last_name'] ); ?></strong></td>
                    <td><?php echo esc_html( $cardholder['street_address'] ?? 'N/A' ); ?></td>
                    <td><?php echo esc_html( $cardholder['email'] ); ?></td>
                    <td><?php echo !empty($cardholder['deleted_at']) ? esc_html( date( 'Y-m-d H:i:s', strtotime( $cardholder['deleted_at'] ) ) ) : 'N/A'; ?></td>
                </tr>
            <?php endforeach;
              endif;
            ?>
        </tbody>
    </table>
<?php
}


