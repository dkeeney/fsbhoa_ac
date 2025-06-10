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

        <a href="<?php echo esc_url( add_query_arg('action', 'add', $current_page_url) ); ?>" class="button button-primary" style="margin-bottom: 20px; display: inline-block;">
            <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
        </a>

        <table id="fsbhoa-cardholder-table" class="display" style="width:100%">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Card Status', 'fsbhoa-ac' ); ?></th>
                    <th class="no-sort"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty($cardholders) ) : foreach ( $cardholders as $cardholder ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $cardholder['first_name'] . ' ' . $cardholder['last_name'] ); ?></strong></td>
                        <td><?php echo isset($cardholder['street_address']) ? esc_html($cardholder['street_address']) : '<em>N/A</em>'; ?></td>
                        <td><?php echo esc_html( ucwords($cardholder['card_status']) ); ?></td>
                        <td>
                            <?php
                            $edit_url = add_query_arg(array('action' => 'edit_cardholder', 'cardholder_id' => absint($cardholder['id'])), $current_page_url);
                            $delete_nonce = wp_create_nonce('fsbhoa_delete_cardholder_nonce_' . $cardholder['id']);
                            $delete_url = add_query_arg(array('action'=> 'fsbhoa_delete_cardholder', 'cardholder_id' => absint($cardholder['id']), '_wpnonce'=> $delete_nonce), admin_url('admin-post.php'));
                            ?>
                            <a href="<?php echo esc_url($edit_url); ?>">Edit</a> | 
                            <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Are you sure you want to delete this cardholder?');" style="color:#a00;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'No cardholders found.', 'fsbhoa-ac' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
