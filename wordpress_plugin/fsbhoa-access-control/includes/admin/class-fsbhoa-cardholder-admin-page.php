<?php
/**
 * Handles the DISPLAY of pages for Cardholder management.
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Cardholder_Admin_Page {

    public function __construct() {
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
    }

    public function ajax_search_properties_callback() {
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');
        global $wpdb;
        $table_name = 'ac_property';
        $search_term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $results = array();
        if (strlen($search_term) >= 1) {
            $wildcard_search_term = '%' . $wpdb->esc_like($search_term) . '%';
            $properties = $wpdb->get_results( $wpdb->prepare( "SELECT property_id, street_address FROM {$table_name} WHERE street_address LIKE %s ORDER BY street_address ASC LIMIT 20", $wildcard_search_term ), ARRAY_A );
            if ($properties) {
                foreach ($properties as $property) {
                    $results[] = array( 'id' => $property['property_id'], 'label' => $property['street_address'], 'value' => $property['street_address'] );
                }
            }
        }
        wp_send_json_success($results);
    }

    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ('add' === $action || 'edit_cardholder' === $action ) {
            $this->render_add_new_cardholder_form($action);
        } else {
            if ( ! is_admin() ) {
                $this->render_frontend_cardholder_list();
            } else {
                $this->render_cardholders_list_page();
            }
        }
    }

    public function render_frontend_cardholder_list() {
        $cardholders = class_exists('Fsbhoa_Cardholder_List_Table') ? Fsbhoa_Cardholder_List_Table::get_cardholders(999, 1) : array();
        $current_page_url = get_permalink();
        ?>
        <div id="fsbhoa-cardholder-management-wrap" class="fsbhoa-frontend-wrap">
            <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>
            <a href="<?php echo esc_url( add_query_arg('action', 'add', $current_page_url) ); ?>" class="button button-primary" style="margin-bottom: 20px; display: inline-block;"><?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?></a>
            <table id="fsbhoa-cardholder-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Property', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Card Status', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
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
                                <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this cardholder?', 'fsbhoa-ac'); ?>');" style="color:#a00;">Delete</a>
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

    public function render_cardholders_list_page() {
        $cardholder_list_table = new Fsbhoa_Cardholder_List_Table();
        $cardholder_list_table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>
            <a href="?page=fsbhoa_ac_cardholders&action=add" class="page-title-action"><?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?></a>
            <form method="post">
                <input type="hidden" name="page" value="<?php echo esc_attr( isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '' ); ?>" />
                <?php $cardholder_list_table->display(); ?>
            </form>
        </div>
        <?php
    }

    public function render_add_new_cardholder_form($current_page_action = 'add') {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';
        $property_table_name = 'ac_property';
        $form_data = array(
            'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '',
            'phone_type' => '', 'resident_type' => '', 'property_id' => '',
            'property_address_display' => '', 'photo' => null, 'rfid_id' => '',
            'notes' => '', 'card_status' => 'inactive', 'card_issue_date' => '',
            'card_expiry_date' => '',
        );
        $display_specific_errors = array();
        $item_id_for_edit = null;
        $is_edit_mode = ($current_page_action === 'edit_cardholder' && isset($_GET['cardholder_id']));
        $user_id = get_current_user_id();

        if ($is_edit_mode) {
            $item_id_for_edit = absint($_GET['cardholder_id']);
            if (isset($_GET['message']) && in_array($_GET['message'], array('cardholder_validation_error_edit', 'cardholder_update_dberror', 'cardholder_no_changes'))) {
                $form_transient_key = 'fsbhoa_edit_ch_data_' . $item_id_for_edit . '_' . $user_id;
                $errors_transient_key = 'fsbhoa_edit_ch_errors_' . $item_id_for_edit . '_' . $user_id;
                $transient_form_data = get_transient($form_transient_key);
                if ($transient_form_data !== false) { $form_data = array_merge($form_data, $transient_form_data); delete_transient($form_transient_key); }
                $transient_errors = get_transient($errors_transient_key);
                if ($transient_errors !== false) { $display_specific_errors = $transient_errors; delete_transient($errors_transient_key); }
            }
            if (empty($form_data['first_name']) && $item_id_for_edit > 0) {
                $cardholder_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cardholder_table_name} WHERE id = %d", $item_id_for_edit), ARRAY_A);
                if ($cardholder_to_edit) {
                    $form_data = array_merge($form_data, $cardholder_to_edit);
                    if (!empty($form_data['property_id'])) {
                        $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM {$property_table_name} WHERE property_id = %d", $form_data['property_id']));
                        if ($property_address) { $form_data['property_address_display'] = $property_address; }
                    }
                }
            }
        }

        if (!empty($display_specific_errors)) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Please correct the errors highlighted below:', 'fsbhoa-ac') . '</p></div>';
        }

        $page_title = $is_edit_mode ? __( 'Edit Cardholder', 'fsbhoa-ac' ) : __( 'Add New Cardholder', 'fsbhoa-ac' );
        $submit_button_text = $is_edit_mode ? __( 'Update Cardholder', 'fsbhoa-ac' ) : __( 'Add Cardholder', 'fsbhoa-ac' );
        $submit_button_name = $is_edit_mode ? 'submit_update_cardholder' : 'submit_add_cardholder';
        $nonce_action = $is_edit_mode ? ('fsbhoa_update_cardholder_action_' . $item_id_for_edit) : 'fsbhoa_add_cardholder_action';
        $nonce_name = $is_edit_mode ? 'fsbhoa_update_cardholder_nonce' : 'fsbhoa_add_cardholder_nonce';
        $form_post_hook_action = $is_edit_mode ? 'fsbhoa_do_update_cardholder' : 'fsbhoa_do_add_cardholder';

        $page_slug = get_post_field( 'post_name', get_post() );
        $cancel_url = is_admin() ? admin_url('admin.php?page=fsbhoa_ac_cardholders') : get_permalink(get_page_by_path($page_slug));
        ?>
        <div id="fsbhoa-cardholder-management-wrap" class="wrap fsbhoa-frontend-wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo esc_attr($form_post_hook_action); ?>" />
                <?php if ($is_edit_mode && $item_id_for_edit) : ?>
                    <input type="hidden" name="cardholder_id" value="<?php echo esc_attr($item_id_for_edit); ?>" />
                <?php endif; ?>
                <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>

                <table class="form-table" id="fsbhoa-cardholder-form-table">
                    <tbody>
                        <tr><th scope="row"><label for="first_name"><?php esc_html_e( 'First Name', 'fsbhoa-ac' ); ?></label></th><td><input type="text" name="first_name" id="first_name" class="regular-text" value="<?php echo esc_attr($form_data['first_name']); ?>" required></td></tr>
                        <tr><th scope="row"><label for="last_name"><?php esc_html_e( 'Last Name', 'fsbhoa-ac' ); ?></label></th><td><input type="text" name="last_name" id="last_name" class="regular-text" value="<?php echo esc_attr($form_data['last_name']); ?>" required></td></tr>
                        <tr><th scope="row"><label for="email"><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></label></th><td><input type="email" name="email" id="email" class="regular-text" value="<?php echo esc_attr($form_data['email']); ?>"><p class="description"><?php esc_html_e( 'Optional.', 'fsbhoa-ac' ); ?></p></td></tr>
                        <tr><th scope="row"><label for="phone"><?php esc_html_e( 'Phone Number', 'fsbhoa-ac' ); ?></label></th><td><input type="tel" name="phone" id="phone" class="regular-text" style="width: 15em; margin-right: 1em;" value="<?php echo esc_attr($form_data['phone']); ?>"><select name="phone_type" id="phone_type" style="vertical-align: baseline;"><?php $current_phone_type = isset($form_data['phone_type']) ? $form_data['phone_type'] : ''; ?><option value="" <?php selected($current_phone_type, ''); ?>>-- Select Type --</option><option value="Mobile" <?php selected($current_phone_type, 'Mobile'); ?>>Mobile</option><option value="Home" <?php selected($current_phone_type, 'Home'); ?>>Home</option><option value="Work" <?php selected($current_phone_type, 'Work'); ?>>Work</option><option value="Other" <?php selected($current_phone_type, 'Other'); ?>>Other</option></select></td></tr>
                        <tr><th scope="row"><label for="resident_type"><?php esc_html_e( 'Resident Type', 'fsbhoa-ac' ); ?></label></th><td><select name="resident_type" id="resident_type"><?php $current_resident_type = isset($form_data['resident_type']) ? $form_data['resident_type'] : ''; ?><option value="" <?php selected($current_resident_type, ''); ?>>-- Select Type --</option><option value="Resident Owner" <?php selected($current_resident_type, 'Resident Owner'); ?>>Resident Owner</option><option value="Non-resident Owner" <?php selected($current_resident_type, 'Non-resident Owner'); ?>>Non-resident Owner</option><option value="Tenant" <?php selected($current_resident_type, 'Tenant'); ?>>Tenant</option><option value="Staff" <?php selected($current_resident_type, 'Staff'); ?>>Staff</option><option value="Contractor" <?php selected($current_resident_type, 'Contractor'); ?>>Contractor</option><option value="Caregiver" <?php selected($current_resident_type, 'Caregiver'); ?>>Caregiver</option><option value="Other" <?php selected($current_resident_type, 'Other'); ?>>Other</option></select></td></tr>
                        <tr><th scope="row"><label for="fsbhoa_property_search_input"><?php esc_html_e( 'Property Address', 'fsbhoa-ac' ); ?></label></th><td><input type="text" id="fsbhoa_property_search_input" name="property_address_display" class="regular-text" placeholder="<?php esc_attr_e( 'Start typing address...', 'fsbhoa-ac' ); ?>" value="<?php echo esc_attr($form_data['property_address_display']); ?>"><input type="hidden" name="property_id" id="fsbhoa_property_id_hidden" value="<?php echo esc_attr($form_data['property_id']); ?>"><p class="description"><?php esc_html_e( 'Type 1+ characters to search.', 'fsbhoa-ac' ); ?> <span id="fsbhoa_property_clear_selection" style="display: <?php echo empty($form_data['property_id']) ? 'none' : 'inline'; ?>; margin-left:10px; color: #0073aa; cursor:pointer;"><?php esc_html_e('[Clear Selection]', 'fsbhoa-ac'); ?></span></p></td></tr>
                        <tr><th scope="row"><label for="notes"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></label></th><td><textarea name="notes" id="notes" rows="3" class="large-text"><?php echo esc_textarea(isset($form_data['notes']) ? $form_data['notes'] : ''); ?></textarea></td></tr>
                        <tr>
                            <th scope="row"><label><?php esc_html_e( 'Cardholder Photo', 'fsbhoa-ac' ); ?></label></th>
                            <td class="photo-options-cell">
                                <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                                    <div id="fsbhoa_main_photo_preview_area" style="flex-basis: 200px; text-align: center;">
                                        <strong style="display:block; margin-bottom: 5px;"><?php esc_html_e('Photo Preview', 'fsbhoa-ac'); ?></strong>
                                        <img id="fsbhoa_photo_preview_main_img" src="<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'data:image/jpeg;base64,' . base64_encode($form_data['photo']) : '#'; ?>" alt="<?php esc_attr_e('Photo Preview', 'fsbhoa-ac'); ?>" style="max-width: 150px; max-height: 150px; border: 1px solid #ddd; padding: 2px; margin-top: 5px; display:<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'block' : 'none'; ?>;">
                                        <p id="fsbhoa_no_photo_message" style="display:<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'none' : 'block'; ?>;"><em><?php esc_html_e('No photo.', 'fsbhoa-ac'); ?></em></p>
                                        <button type="button" id="fsbhoa-crop-photo-btn" class="button" style="display:none; margin-top: 10px;"><?php esc_html_e('Crop Photo', 'fsbhoa-ac'); ?></button>
                                    </div>
                                    <div style="flex-grow: 1;">
                                        <div id="fsbhoa_file_upload_section" style="margin-bottom:15px;"><strong><?php esc_html_e('Option 1: Upload Photo File', 'fsbhoa-ac'); ?></strong><br><input type="file" name="cardholder_photo_file_input" id="cardholder_photo_file_input" accept="image/jpeg,image/png,image/gif"></div>
                                        <div id="fsbhoa_webcam_section" style="margin-bottom:15px;">
                                            <strong><?php esc_html_e('Option 2: Use Webcam', 'fsbhoa-ac'); ?></strong><br>
                                            <button type="button" id="fsbhoa_start_webcam_button" class="button"><?php esc_html_e('Start Webcam', 'fsbhoa-ac'); ?></button>
                                            <div id="fsbhoa_webcam_active_controls" style="display:none; margin-top:5px;">
                                                <button type="button" id="fsbhoa_capture_photo_button" class="button"><?php esc_html_e('Capture Photo', 'fsbhoa-ac'); ?></button> 
                                                <button type="button" id="fsbhoa_stop_webcam_button" class="button"><?php esc_html_e('Stop Webcam', 'fsbhoa-ac'); ?></button>
                                            </div>
                                            <div id="fsbhoa_webcam_container" style="margin-top:10px; display:none;">
                                                <video id="fsbhoa_webcam_video" autoplay muted playsinline style="border:1px solid #ccc; max-width:100%;"></video>
                                                <canvas id="fsbhoa_webcam_canvas" style="display:none;"></canvas>
                                                </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="cropped_photo_data" id="fsbhoa_cropped_photo_data" value="">
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button type="submit" name="<?php echo esc_attr( $submit_button_name ); ?>" id="submit" class="button button-primary"><?php echo esc_html( $submit_button_text ); ?></button>
                    <a href="<?php echo esc_url($cancel_url); ?>" class="button button-secondary" style="margin-left: 10px; vertical-align: top;"><?php esc_html_e( 'Cancel', 'fsbhoa-ac' ); ?></a>
                </p>
            </form>
            <div id="fsbhoa-cropper-dialog" title="<?php esc_attr_e('Crop Photo', 'fsbhoa-ac'); ?>" style="display:none;">
                <div id="fsbhoa-cropper-image-container"></div>
            </div>
        </div>
        <?php
    }
}
