<?php
/**
 * Creates the admin page for managing Kiosk Amenities.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Amenity_Admin_Page {

    public function render_page() {
        if (isset($_GET['action']) && isset($_GET['amenity_id'])) {
            $action = sanitize_key($_GET['action']);
            
            if ($action === 'toggle_status') {
                // This is handled by the actions class, but we can add a handler here if we wanted
                // For now, the actions class handles it via admin-post.php
            }
        }

        if (isset($_GET['message'])) {
            $this->display_admin_notice($_GET['message']);
        }

        $edit_id = (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['amenity_id'])) ? absint($_GET['amenity_id']) : 0;
        $this->render_form_page($edit_id);
        $this->render_list_page();
    }

    private function render_list_page() {
        global $wpdb;
        $table_name = 'ac_amenities';
        $amenities = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY display_order ASC, name ASC");
        if ($wpdb->last_error) {
            echo '<div class="notice notice-error"><p>Database Error: Could not retrieve amenities. ' . esc_html( $wpdb->last_error ) . '</p></div>';
        }
        ?>
        <div class="wrap fsbhoa-frontend-wrap fsbhoa-amenity-page" style="margin-top: 20px;">
            <h1 class="wp-heading-inline">Current Amenities</h1>
            <hr class="wp-header-end">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">Image</th>
                        <th>Name</th>
                        <th style="width: 50px;">Status</th>
                        <th style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty($amenities) ) : foreach ( $amenities as $amenity ) : ?>
                        <tr>
                            <td>
                                <?php if (!empty($amenity->image_url)) : ?>
                                    <img src="<?php echo esc_url($amenity->image_url); ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 3px;">
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($amenity->name); ?></td>
                            <td><?php echo $amenity->is_active ? 'Active' : 'Inactive'; ?></td>
                            <td class="fsbhoa-actions-column">
                                <?php
                                $page_url = get_permalink();
                                // Reorder links
                                $up_nonce = wp_create_nonce('fsbhoa_move_up_nonce_' . $amenity->id);
                                $down_nonce = wp_create_nonce('fsbhoa_move_down_nonce_' . $amenity->id);
                                $up_link = esc_url(admin_url('admin-post.php?action=fsbhoa_move_amenity_up&amenity_id=' . $amenity->id . '&_wpnonce=' . $up_nonce));
                                $down_link = esc_url(admin_url('admin-post.php?action=fsbhoa_move_amenity_down&amenity_id=' . $amenity->id . '&_wpnonce=' . $down_nonce));

                                // Status toggle link
                                $toggle_nonce = wp_create_nonce('fsbhoa_toggle_amenity_nonce_' . $amenity->id);
                                $toggle_link = esc_url(admin_url('admin-post.php?action=fsbhoa_toggle_amenity_status&amenity_id=' . $amenity->id . '&_wpnonce=' . $toggle_nonce));
                                $toggle_icon = $amenity->is_active ? 'dashicons-visibility' : 'dashicons-hidden';
                                $toggle_title = $amenity->is_active ? 'Deactivate' : 'Activate';

                                // Edit and Delete links
                                $delete_nonce = wp_create_nonce('fsbhoa_delete_amenity_nonce_' . $amenity->id);
                                $edit_link = esc_url(add_query_arg(['action' => 'edit', 'amenity_id' => $amenity->id], $page_url));
                                $delete_link = esc_url(add_query_arg(['action' => 'delete', 'amenity_id' => $amenity->id, '_wpnonce' => $delete_nonce], $page_url));
                                ?>
                                <a href="<?php echo $up_link; ?>" title="Move Up"><span class="dashicons dashicons-arrow-up-alt"></span></a>
                                <a href="<?php echo $down_link; ?>" title="Move Down"><span class="dashicons dashicons-arrow-down-alt"></span></a>
                                <a href="<?php echo $toggle_link; ?>" title="<?php echo $toggle_title; ?>"><span class="dashicons <?php echo $toggle_icon; ?>"></span></a>
                                <a href="<?php echo $edit_link; ?>" title="Edit"><span class="dashicons dashicons-edit"></span></a>
                                <a href="<?php echo $delete_link; ?>" title="Delete" onclick="return confirm('Are you sure you want to delete this amenity?')" class="fsbhoa-delete-icon"><span class="dashicons dashicons-trash"></span></a>
                            </td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No amenities found. Use the form above to add one.', 'fsbhoa-ac' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }


    private function render_form_page($edit_id = 0) {
        $amenity = null;
        if ($edit_id > 0) {
            global $wpdb;
            $table_name = 'ac_amenities';
            $amenity = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $edit_id));
            if ($wpdb->last_error) {
                echo '<div class="notice notice-error"><p>Database Error: Could not retrieve amenity for editing. ' . esc_html( $wpdb->last_error ) . '</p></div>';
                return;
            }
        }

        $page_title = $amenity ? 'Edit Amenity' : 'Add New Amenity';
        $name = $amenity ? $amenity->name : '';
        $image_url = $amenity ? $amenity->image_url : '';
        $display_order = $amenity ? $amenity->display_order : '0';
        $is_active = $amenity ? $amenity->is_active : '1';
        $button_text = $amenity ? 'Update Amenity' : 'Add Amenity';
        $form_action = $amenity ? 'fsbhoa_edit_amenity' : 'fsbhoa_add_amenity';
        ?>
        <div class="wrap fsbhoa-frontend-wrap  fsbhoa-amenity-page">
            <h1 class="wp-heading-inline"><?php echo esc_html($page_title); ?></h1>
            <hr class="wp-header-end">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>">
                <input type="hidden" name="amenity_id" value="<?php echo esc_attr($edit_id); ?>">
                <?php wp_nonce_field('fsbhoa_amenity_nonce'); ?>
                 <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url(remove_query_arg(['action', 'amenity_id', 'message'])); ?>">

<div class="fsbhoa-add-form-fields">
                    <div class="form-field-group">
                        <label for="name">Amenity Name</label>
                        <input name="name" type="text" id="name" value="<?php echo esc_attr($name); ?>" required>
                    </div>
                    <div class="form-field-group">
                         <label for="image_url">Image URL</label>
                         <input name="image_url" type="url" id="image_url" value="<?php echo esc_attr($image_url); ?>" placeholder="Optional URL">
                    </div>
                </div>
                <button type="submit" name="submit" id="submit" class="button button-primary"><?php echo esc_html($button_text); ?></button>
                 <?php if ($amenity) : ?>
                    <a href="<?php echo esc_url(remove_query_arg(['action', 'amenity_id'])); ?>" class="button button-secondary" style="margin-left: 10px;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }
    
    private function display_admin_notice($message_code) {
        $message = '';
        $class = 'notice-success';
        switch ($message_code) {
            case 'added': $message = 'Amenity added successfully.'; break;
            case 'updated': $message = 'Amenity updated successfully.'; break;
            case 'deleted': $message = 'Amenity deleted successfully.'; break;
            case 'error': 
                $message = 'An error occurred. Please try again.'; 
                $class = 'notice-error';
                break;
        }
        if ($message) {
            echo '<div class="notice ' . $class . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    private function handle_delete_action() {
        $amenity_id = isset($_GET['amenity_id']) ? absint($_GET['amenity_id']) : 0;
        $nonce = $_GET['_wpnonce'] ?? '';

        if (!$amenity_id || !wp_verify_nonce($nonce, 'fsbhoa_delete_amenity_nonce_' . $amenity_id)) {
            wp_die('Security check failed.');
        }

        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }
        
        global $wpdb;
        $table_name = 'ac_amenities';
        $result = $wpdb->delete($table_name, ['id' => $amenity_id], ['%d']);

        $redirect_url = wp_get_referer();
        $redirect_url = $redirect_url ? remove_query_arg(['action', 'amenity_id', '_wpnonce'], $redirect_url) : '';
        
        if ($result === false) {
            $redirect_url = add_query_arg('message', 'error', $redirect_url);
        } else {
            $redirect_url = add_query_arg('message', 'deleted', $redirect_url);
        }

        wp_safe_redirect($redirect_url);
        exit;
    }
}

