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


    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        if ('add' === $action || 'edit_cardholder' === $action ) {
            $this->render_add_new_cardholder_form($action);
        } else {
            require_once plugin_dir_path( __FILE__ ) . 'views/view-cardholder-list.php';
            fsbhoa_render_cardholder_list_view();
        }
    }


    public function render_add_new_cardholder_form($current_page_action = 'add') {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';
        $property_table_name = 'ac_property';
        $form_data = array(
            'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'phone_type' => '', 
            'resident_type' => '', 'property_id' => '', 'property_address_display' => '', 'photo' => null, 
            'rfid_id' => '', 'notes' => '', 'card_status' => 'inactive', 'card_issue_date' => '', 
            'card_expiry_date' => '', 'photo_base64' => '',
        );
        $errors = array();
        $is_edit_mode = ($current_page_action === 'edit_cardholder' && isset($_GET['cardholder_id']));
        $item_id_for_edit = $is_edit_mode ? absint($_GET['cardholder_id']) : 0;
        $user_id = get_current_user_id();
        
        // Check for feedback from a failed submission
        $transient_key = 'fsbhoa_form_feedback_' . ($is_edit_mode ? 'edit_' . $item_id_for_edit . '_' : 'add_') . $user_id;
        $feedback = get_transient($transient_key);


        if ($feedback !== false) {
            // RECOVERING FROM ERROR
            $form_data = array_merge($form_data, $feedback['data']);
            $errors = $feedback['errors'];
            delete_transient($transient_key);
        
        } elseif ($is_edit_mode) {
            // INITIAL LOAD: Get data from the database
            $cardholder_to_edit = $wpdb->get_row($wpdb->prepare( "SELECT * FROM {$cardholder_table_name} WHERE id = %d", $item_id_for_edit), ARRAY_A);
            if ($wpdb->last_error) {
                wp_die(
                    'Database error: Could not retrieve cardholder data. Please go back and try again. Error: ' . esc_html($wpdb->last_error),
                    'Database Error',
                    array('back_link' => true)
                );
            }
            if ($cardholder_to_edit) {
                $form_data = array_merge($form_data, $cardholder_to_edit);
                if (!empty($cardholder_to_edit['photo'])) {
                    $form_data['photo_base64'] = base64_encode($cardholder_to_edit['photo']);
                }
 
                if (!empty($form_data['property_id'])) {
                    $form_data['property_address_display'] = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM {$property_table_name} WHERE property_id = %d", $form_data['property_id']));
                }
            }
            else {   
                // ---  Handle case where the cardholder ID is not found ---
                wp_die(
                    'Error: Cardholder not found. The record may have been deleted by another user.',
                    'Record Not Found',
                    array('back_link' => true)
                );
            }
        }
        
        $page_title = $is_edit_mode ? 'Edit Cardholder' : 'Add New Cardholder';
        $submit_button_text = $is_edit_mode ? 'Update Cardholder' : 'Add Cardholder';
        $form_post_hook_action = $is_edit_mode ? 'fsbhoa_do_update_cardholder' : 'fsbhoa_do_add_cardholder';
        $nonce_action = $is_edit_mode ? ('fsbhoa_update_cardholder_action_' . $item_id_for_edit) : 'fsbhoa_add_cardholder_action';
        $cancel_url = get_permalink(get_page_by_path('cardholder'));
        ?>
        <div id="fsbhoa-cardholder-management-wrap" class="fsbhoa-frontend-wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>

            <?php if (!empty($errors)) : ?>
                <div class="notice notice-error is-dismissible" style="border-left-color: #d63638; padding: 1em; margin-bottom: 1em;">
                    <p><strong>Please correct the following errors:</strong></p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <?php foreach($errors as $error) : ?>
                            <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="fsbhoa-cardholder-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">

                <input type="hidden" name="action" value="<?php echo esc_attr($form_post_hook_action); ?>" />
                <?php if ($is_edit_mode) : ?>
                    <input type="hidden" name="cardholder_id" value="<?php echo esc_attr($item_id_for_edit); ?>" />
                <?php endif; ?>
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( remove_query_arg( 'message' ) ); ?>" />
                <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>
                <?php
                // We no longer use a table, just call the render functions for our sections
                require_once plugin_dir_path( __FILE__ ) . 'views/view-cardholder-profile-section.php';
                fsbhoa_render_profile_section( $form_data );

                require_once plugin_dir_path( __FILE__ ) . 'views/view-cardholder-address-section.php';
                fsbhoa_render_address_section( $form_data );

                require_once plugin_dir_path( __FILE__ ) . 'views/view-cardholder-rfid-section.php';
                fsbhoa_render_rfid_section( $form_data, $is_edit_mode );

                require_once plugin_dir_path( __FILE__ ) . 'views/view-cardholder-photo-section.php';
                fsbhoa_render_photo_section( $form_data, $is_edit_mode, !empty($errors) );
                ?>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo esc_html( $submit_button_text ); ?></button>
                    <a href="<?php echo esc_url($cancel_url); ?>" class="button button-secondary">Cancel</a>
                </p>
            </form>
            <div id="fsbhoa-cropper-dialog" title="Crop Photo" style="display:none;"><div id="fsbhoa-cropper-image-container"></div></div>
        </div>
        <?php
    }
}
