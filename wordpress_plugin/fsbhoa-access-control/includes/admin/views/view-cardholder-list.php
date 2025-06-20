<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the front-end list of cardholders using a custom HTML table enhanced by DataTables.
 */
function fsbhoa_render_cardholder_list_view() {
    // We can still use the static method from our old List Table class to fetch the data
    $cardholders = class_exists('Fsbhoa_Cardholder_List_Table') ? Fsbhoa_Cardholder_List_Table::get_cardholders(999, 1, 'last_name', 'asc') : array();
    $current_page_url = get_permalink(); 
    ?>
    <div id="fsbhoa-cardholder-management-wrap" class="fsbhoa-frontend-wrap">
        <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>
        
        <?php if (isset($_GET['message'])) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color: #4CAF50; padding: 1em; margin-bottom: 1em;">
                <p>
                    <?php 
                    if ($_GET['message'] === 'cardholder_updated') {
                        esc_html_e('Cardholder updated successfully.', 'fsbhoa-ac');
                    } elseif ($_GET['message'] === 'cardholder_added') {
                        esc_html_e('Cardholder added successfully.', 'fsbhoa-ac');
                    } elseif ($_GET['message'] === 'cardholder_deleted') {
                        esc_html_e('Cardholder deleted.', 'fsbhoa-ac');
                    }
                    ?>
                </p>
            </div>
        <?php endif; ?>

       <!--  Custom HTML Control Bar -->
        <div class="fsbhoa-table-controls">
            <!-- Left Side: Add New Button -->
            <a href="<?php echo esc_url( add_query_arg('action', 'add', $current_page_url) ); ?>" class="button button-primary">
                <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
            </a>

            <!-- Right Side: Container for Entries and Search -->
            <div class="fsbhoa-table-right-controls">
                <div class="fsbhoa-control-group">
                    <label for="fsbhoa-custom-length-menu">Show</label>
                    <select name="fsbhoa-custom-length-menu" id="fsbhoa-custom-length-menu">
                        <option value="100">100</option>
                        <option value="250">250</option>
                        <option value="500">500</option>
                        <option value="-1">All</option>
                    </select>
                    <span>entries</span>
                </div>
                <div class="fsbhoa-control-group">
                     <label for="fsbhoa-cardholder-search-input">Search:</label>
                    <input type="search" id="fsbhoa-cardholder-search-input" placeholder="Search...">
                </div>
            </div>
        </div>
        <!-- End Custom HTML Control Bar -->

        <table id="fsbhoa-cardholder-table" class="display" style="width:100%">
            <thead>
                <tr>
                    <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                    <th class="fsbhoa-status-column"><?php esc_html_e( 'Card Status', 'fsbhoa-ac' ); ?></th>
                    <th class="no-sort fsbhoa-type-column" title="<?php esc_attr_e( 'Cardholder Type', 'fsbhoa-ac' ); ?>">
                        <?php esc_html_e( 'Type', 'fsbhoa-ac' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty($cardholders) ) : foreach ( $cardholders as $cardholder ) : ?>
                    <tr>
                        <td class="fsbhoa-actions-column">
                            <?php
                            $edit_url = add_query_arg(array('action' => 'edit_cardholder', 'cardholder_id' => absint($cardholder['id'])), $current_page_url);
                            $delete_nonce = wp_create_nonce('fsbhoa_delete_cardholder_nonce_' . $cardholder['id']);
                            $delete_url = add_query_arg(array('action'=> 'fsbhoa_delete_cardholder', 'cardholder_id' => absint($cardholder['id']), '_wpnonce'=> $delete_nonce), admin_url('admin-post.php'));
                            $print_page_url = get_permalink(get_page_by_path('print-photo-id'));
                            $print_url = add_query_arg(array('action' => 'print_card', 'cardholder_id' => absint($cardholder['id'])), $print_page_url);

                            ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Edit Cardholder', 'fsbhoa-ac'); ?>">
                                <span class="dashicons dashicons-edit"></span>
                            </a>
                            <a href="<?php echo esc_url($print_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Print ID Card', 'fsbhoa-ac'); ?>">
                               <span class="dashicons dashicons-printer"></span>
                            </a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Delete Cardholder', 'fsbhoa-ac'); ?>" onclick="return confirm('Are you sure you want to delete this cardholder?');">
                                <span class="dashicons dashicons-trash"></span>
                            </a>
                        </td>

                        <td><strong><?php echo esc_html( $cardholder['first_name'] . ' ' . $cardholder['last_name'] ); ?></strong></td>
                        <td><?php echo isset($cardholder['street_address']) ? esc_html($cardholder['street_address']) : '<em>N/A</em>'; ?></td>
                        <td class="fsbhoa-status-column"><?php echo esc_html( ucwords($cardholder['card_status']) ); ?></td>
                        <td class="fsbhoa-type-column">
                            <?php echo esc_html( $cardholder['resident_type'] ?? '' ); ?>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No cardholders found.', 'fsbhoa-ac' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
