<?php
/**
 * Handles the DISPLAY of admin pages for Cardholder management.
 * Action processing (add, update, delete submissions, AJAX) is handled by Fsbhoa_Cardholder_Actions class,
 * except for the property search AJAX which is still homed here for now.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Cardholder_Admin_Page {

    /**
     * Constructor.
     * Hooks into WordPress actions for AJAX.
     *
     * @since 0.1.12 
     */
    public function __construct() {
        // AJAX hook for property search (used in the add/edit cardholder form)
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
    }

    /**
     * AJAX callback to search properties.
     * Outputs JSON.
     * @since 0.1.5
     */
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

    /**
     * Handles page routing for cardholder admin display.
     * @since 0.1.11
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : ''; 
        // error_log('FSBHOA RENDER CARDHOLDER PAGE (render_page): $_GET array: ' . print_r($_GET, true)); // User requested to keep this

        if ('add' === $action || 'edit_cardholder' === $action ) { 
            $this->render_add_new_cardholder_form($action);
        } else {
            $this->render_cardholders_list_page();
        }
    }

    /**
     * Renders the list of cardholders.
     * @since 0.1.11 (Message handling updated)
     */
    public function render_cardholders_list_page() {
        $cardholder_list_table = new Fsbhoa_Cardholder_List_Table();
        $cardholder_list_table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>
            <a href="?page=fsbhoa_ac_cardholders&action=add" class="page-title-action">
                <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
            </a>
            <?php 
            if (isset($_GET['message'])) {
                $message_code = sanitize_key($_GET['message']);
                $processed_id = 0; 
                if (isset($_GET['added_id'])) $processed_id = absint($_GET['added_id']);
                if (isset($_GET['updated_id'])) $processed_id = absint($_GET['updated_id']);
                if (isset($_GET['deleted_id'])) $processed_id = absint($_GET['deleted_id']);

                $message_text = ''; $notice_class = 'notice-info'; // Default
                switch ($message_code) {
                    case 'cardholder_added_successfully':    $message_text = sprintf(esc_html__('Cardholder added! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    case 'cardholder_updated_successfully':  $message_text = sprintf(esc_html__('Cardholder updated! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    case 'cardholder_deleted_successfully':  $message_text = sprintf(esc_html__('Cardholder deleted! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    case 'cardholder_add_dberror':           $message_text = esc_html__('Database error during add. Cardholder not saved.', 'fsbhoa-ac'); $notice_class = 'error'; break;
                    case 'cardholder_update_dberror':        $message_text = esc_html__('Database error during update. Changes not saved.', 'fsbhoa-ac'); $notice_class = 'error'; break;
                    case 'cardholder_delete_error':          $message_text = esc_html__('Error deleting cardholder.', 'fsbhoa-ac'); $notice_class = 'error'; break;
                    case 'cardholder_delete_not_found':      $message_text = esc_html__('Cardholder not found for deletion.', 'fsbhoa-ac'); $notice_class = 'notice-warning'; break;
                    // Note: cardholder_validation_error messages are now shown on the form page itself.
                }
                if (!empty($message_text)) {
                    echo '<div id="message" class="' . esc_attr($notice_class) . ' notice is-dismissible"><p>' . $message_text . '</p></div>';
                }
            }
            ?>
            <form method="post"><input type="hidden" name="page" value="<?php echo esc_attr( isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '' ); ?>" /><?php $cardholder_list_table->display(); ?></form>
        </div>
        <?php
    }
/**
     * Renders the form for adding or editing a cardholder.
     *
     * @since 0.1.13 (Ensuring all HTML fields, including hidden ones for JS toggle, are present)
     * @param string $current_page_action ('add' or 'edit_cardholder')
     */
    public function render_add_new_cardholder_form($current_page_action = 'add') {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';
        $property_table_name = 'ac_property';

        $form_data = array(
            'first_name'    => '', 'last_name'     => '',
            'email'         => '', 'phone'         => '',
            'phone_type'    => '', 'resident_type' => '',
            'property_id'   => '', 'property_address_display' => '',
            'photo'         => null,
            'rfid_id'       => '',
            'notes'         => '',
            'card_status'   => 'inactive',
            'card_issue_date' => '',
            'card_expiry_date' => '',
        );
        $display_specific_errors = array();

        $item_id_for_edit = null;
        $is_edit_mode = ($current_page_action === 'edit_cardholder' && isset($_GET['cardholder_id']));
        $user_id = get_current_user_id();
        $loaded_from_transient = false;

        // --- Populate $form_data: From Transient or DB ---
        if ($is_edit_mode) {
            $item_id_for_edit = absint($_GET['cardholder_id']);
            if (isset($_GET['message']) &&
                ($_GET['message'] === 'cardholder_validation_error_edit' ||
                 $_GET['message'] === 'cardholder_update_dberror' ||
                 $_GET['message'] === 'cardholder_no_changes') ) {

                $form_transient_key = 'fsbhoa_edit_ch_data_' . $item_id_for_edit . '_' . $user_id;
                $errors_transient_key = 'fsbhoa_edit_ch_errors_' . $item_id_for_edit . '_' . $user_id;

                $transient_form_data = get_transient($form_transient_key);
                if ($transient_form_data !== false) {
                    $form_data = array_merge($form_data, $transient_form_data);
                    delete_transient($form_transient_key);
                    $loaded_from_transient = true;
                }
                $transient_errors = get_transient($errors_transient_key);
                if ($transient_errors !== false) {
                    $display_specific_errors = $transient_errors;
                    delete_transient($errors_transient_key);
                }
            }

            if (!$loaded_from_transient && $item_id_for_edit > 0) {
                if ($_SERVER['REQUEST_METHOD'] === 'GET' || !isset($_POST['submit_update_cardholder'])) {
                    $cardholder_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cardholder_table_name} WHERE id = %d", $item_id_for_edit), ARRAY_A);
                    if ($cardholder_to_edit) {
                        $form_data = array_merge($form_data, $cardholder_to_edit);
                        if (!empty($form_data['property_id'])) {
                            $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM {$property_table_name} WHERE property_id = %d", $form_data['property_id']));
                            if ($property_address) { $form_data['property_address_display'] = $property_address; }
                        }
                    } else { echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Cardholder not found for editing.', 'fsbhoa-ac') . '</p></div>'; return; }
                }
            } elseif (!$loaded_from_transient && $item_id_for_edit <= 0 && $current_page_action === 'edit_cardholder') {
                 echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Invalid Cardholder ID for editing.', 'fsbhoa-ac') . '</p></div>'; return;
            }
        } else { // Add mode
            if (isset($_GET['message']) && $_GET['message'] === 'cardholder_validation_error') {
                $form_transient_key = 'fsbhoa_add_ch_data_' . $user_id;
                $errors_transient_key = 'fsbhoa_add_ch_errors_' . $user_id;
                $transient_form_data = get_transient($form_transient_key);
                if ($transient_form_data !== false) { $form_data = array_merge($form_data, $transient_form_data); delete_transient($form_transient_key); $loaded_from_transient = true; }
                $transient_errors = get_transient($errors_transient_key);
                if ($transient_errors !== false) { $display_specific_errors = $transient_errors; delete_transient($errors_transient_key); }
            }
            if (empty($form_data['phone_type']) && !$loaded_from_transient) { $form_data['phone_type'] = 'Mobile'; }
            if ((!isset($form_data['card_status']) || empty($form_data['card_status'])) && !$loaded_from_transient) { $form_data['card_status'] = 'inactive';}
        }

        if (!empty($display_specific_errors)) {
            echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Please correct the errors highlighted below:', 'fsbhoa-ac') . '</p><ul>';
            foreach ($display_specific_errors as $field_key => $error_msg) { echo '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $field_key))) . ':</strong> ' . esc_html($error_msg) . '</li>'; }
            echo '</ul></div>';
        } elseif (isset($_GET['message']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $message_code = sanitize_key($_GET['message']);
            $notice_class = 'error'; $message_text = '';
            switch ($message_code) {
                case 'cardholder_add_dberror': $message_text = esc_html__('Error saving new cardholder. Please try again.', 'fsbhoa-ac'); break;
                case 'cardholder_update_dberror': $message_text = esc_html__('Error updating cardholder. Please try again.', 'fsbhoa-ac'); break;
                case 'cardholder_no_changes': $message_text = esc_html__('No changes were detected for the cardholder.', 'fsbhoa-ac'); $notice_class = 'notice-info'; break;
                case 'cardholder_validation_error': case 'cardholder_validation_error_edit':
                    if(empty($display_specific_errors)) $message_text = __('Submission failed. Please check form values and try again.', 'fsbhoa-ac'); break;
            }
            if (!empty($message_text)) { echo '<div id="message" class="' . esc_attr($notice_class) . ' notice is-dismissible"><p>' . $message_text . '</p></div>'; }
        }

        $page_title = $is_edit_mode ? __( 'Edit Cardholder', 'fsbhoa-ac' ) : __( 'Add New Cardholder', 'fsbhoa-ac' );
        $submit_button_text = $is_edit_mode ? __( 'Update Cardholder', 'fsbhoa-ac' ) : __( 'Add Cardholder', 'fsbhoa-ac' );
        $submit_button_name = $is_edit_mode ? 'submit_update_cardholder' : 'submit_add_cardholder';
        $current_item_id_for_nonce_action = ($is_edit_mode && $item_id_for_edit) ? $item_id_for_edit : 0;
        $nonce_action = $is_edit_mode ? ('fsbhoa_update_cardholder_action_' . $current_item_id_for_nonce_action) : 'fsbhoa_add_cardholder_action';
        $nonce_name   = $is_edit_mode ? 'fsbhoa_update_cardholder_nonce' : 'fsbhoa_add_cardholder_nonce';
        $form_post_hook_action = $is_edit_mode ? 'fsbhoa_do_update_cardholder' : 'fsbhoa_do_add_cardholder';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="<?php echo esc_attr($form_post_hook_action); ?>" />
                <?php if ($is_edit_mode && $item_id_for_edit) : ?>
                    <input type="hidden" name="cardholder_id" value="<?php echo esc_attr($item_id_for_edit); ?>" />
                <?php endif; ?>
                <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>

                <table class="form-table">
                    <tbody>
                        <tr><th scope="row"><label for="first_name"><?php esc_html_e( 'First Name', 'fsbhoa-ac' ); ?></label></th><td><input type="text" name="first_name" id="first_name" class="regular-text" value="<?php echo esc_attr($form_data['first_name']); ?>" required></td></tr>
                        <tr><th scope="row"><label for="last_name"><?php esc_html_e( 'Last Name', 'fsbhoa-ac' ); ?></label></th><td><input type="text" name="last_name" id="last_name" class="regular-text" value="<?php echo esc_attr($form_data['last_name']); ?>" required></td></tr>
                        <tr><th scope="row"><label for="email"><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></label></th><td><input type="email" name="email" id="email" class="regular-text" value="<?php echo esc_attr($form_data['email']); ?>"><p class="description"><?php esc_html_e( 'Optional.', 'fsbhoa-ac' ); ?></p></td></tr>
                        <tr>
                            <th scope="row"><label for="phone"><?php esc_html_e( 'Phone Number', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <input type="tel" name="phone" id="phone" class="regular-text" style="width: 15em; margin-right: 1em;" value="<?php echo esc_attr($form_data['phone']); ?>">
                                <select name="phone_type" id="phone_type" style="vertical-align: baseline;">
                                    <?php $current_phone_type = isset($form_data['phone_type']) ? $form_data['phone_type'] : ''; ?>
                                    <option value="" <?php selected($current_phone_type, ''); ?>>-- Select Type --</option>
                                    <option value="Mobile" <?php selected($current_phone_type, 'Mobile'); ?>>Mobile</option>
                                    <option value="Home" <?php selected($current_phone_type, 'Home'); ?>>Home</option>
                                    <option value="Work" <?php selected($current_phone_type, 'Work'); ?>>Work</option>
                                    <option value="Other" <?php selected($current_phone_type, 'Other'); ?>>Other</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="resident_type"><?php esc_html_e( 'Resident Type', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <select name="resident_type" id="resident_type">
                                    <?php $current_resident_type = isset($form_data['resident_type']) ? $form_data['resident_type'] : ''; ?>
                                    <option value="" <?php selected($current_resident_type, ''); ?>>-- Select Type --</option>
                                    <option value="Resident Owner" <?php selected($current_resident_type, 'Resident Owner'); ?>>Resident Owner</option>
                                    <option value="Non-resident Owner" <?php selected($current_resident_type, 'Non-resident Owner'); ?>>Non-resident Owner</option>
                                    <option value="Tenant" <?php selected($current_resident_type, 'Tenant'); ?>>Tenant</option>
                                    <option value="Staff" <?php selected($current_resident_type, 'Staff'); ?>>Staff</option>
                                    <option value="Contractor" <?php selected($current_resident_type, 'Contractor'); ?>>Contractor</option>
                                    <option value="Caregiver" <?php selected($current_resident_type, 'Caregiver'); ?>>Caregiver</option>
                                    <option value="Other" <?php selected($current_resident_type, 'Other'); ?>>Other</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fsbhoa_property_search_input"><?php esc_html_e( 'Property Address', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <input type="text" id="fsbhoa_property_search_input" name="property_address_display" class="regular-text" placeholder="<?php esc_attr_e( 'Start typing address...', 'fsbhoa-ac' ); ?>" value="<?php echo esc_attr($form_data['property_address_display']); ?>">
                                <input type="hidden" name="property_id" id="fsbhoa_property_id_hidden" value="<?php echo esc_attr($form_data['property_id']); ?>">
                                <p class="description"><?php esc_html_e( 'Type 1+ characters to search.', 'fsbhoa-ac' ); ?> <span id="fsbhoa_property_clear_selection" style="display: <?php echo empty($form_data['property_id']) ? 'none' : 'inline'; ?>; margin-left:10px; color: #0073aa; cursor:pointer;"><?php esc_html_e('[Clear Selection]', 'fsbhoa-ac'); ?></span></p>
                                <div id="fsbhoa_selected_property_display" style="margin-top:5px; font-style:italic;"><?php if ($is_edit_mode && !empty($form_data['property_id']) && !empty($form_data['property_address_display'])) { echo 'Currently assigned: ' . esc_html($form_data['property_address_display']); } ?></div>
                                <div id="fsbhoa_property_search_no_results" style="color: #dc3232; margin-top: 5px; min-height: 1em;"></div>
                            </td>
                        </tr>

                        <tr id="fsbhoa_rfid_details_section" <?php if (!$is_edit_mode) echo 'style="display:none;"'; ?>>
                            <th scope="row"><label for="rfid_id"><?php esc_html_e( 'RFID & Card Details', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <div style="display: flex; flex-wrap: wrap; align-items: flex-start; gap: 10px 25px;">
                                    <div style="margin-bottom: 5px; flex-shrink: 0;">
                                        <label for="rfid_id" style="display: block; font-weight: bold; margin-bottom: .2em;"><?php esc_html_e( 'RFID Card ID', 'fsbhoa-ac' ); ?></label>
                                        <input type="text" name="rfid_id" id="rfid_id" class="regular-text"
                                               value="<?php echo esc_attr($form_data['rfid_id']); ?>"
                                               maxlength="8" pattern="[a-zA-Z0-9]{8}" title="<?php esc_attr_e('8-digit alphanumeric RFID.', 'fsbhoa-ac'); ?>"
                                               style="width: 10em;">
                                    </div>

                                    <?php // This section for status, dates, toggle is only shown in edit mode ?>
                                    <div style="margin-bottom: 5px; flex-shrink: 0;"> <?php // Status and Toggle Group ?>
                                        <strong style="display: block; margin-bottom: .2em;"><?php esc_html_e( 'Status:', 'fsbhoa-ac' ); ?></strong>
                                        <span id="fsbhoa_card_status_display" style="padding: 3px 0; display: inline-block; min-width: 7em;"><?php echo esc_html(ucwords($form_data['card_status'])); ?></span>
                                        <?php
                                        // Show toggle only if an RFID is actually present for this record
                                        $show_ui_toggle = !empty($form_data['rfid_id']);
                                        if ($show_ui_toggle) :
                                            $is_card_active_for_ui_toggle = (isset($form_data['card_status']) && $form_data['card_status'] === 'active');
                                        ?>
                                        <label style="margin-left: 10px; white-space: nowrap;">
                                            <input type="checkbox" id="fsbhoa_card_status_ui_toggle" value="active" <?php checked($is_card_active_for_ui_toggle); ?>>
                                            <span id="fsbhoa_card_status_toggle_ui_label"><?php echo $is_card_active_for_ui_toggle ? esc_html__('Card is Active (Click to Disable)', 'fsbhoa-ac') : esc_html__('Card is Inactive (Click to Activate)', 'fsbhoa-ac'); ?></span>
                                        </label>
                                        <?php endif; ?>
                                    </div>

                                    <div style="display: flex; align-items: baseline; gap: 5px 15px; flex-shrink: 0; flex-wrap:nowrap;"> <?php // Dates Group ?>
                                        <?php if (!empty($form_data['card_issue_date']) && $form_data['card_issue_date'] !== '0000-00-00') : ?>
                                        <div style="white-space: nowrap;" id="fsbhoa_issue_date_wrapper">
                                            <strong style="margin-right: .3em;"><?php esc_html_e( 'Issued:', 'fsbhoa-ac' ); ?></strong>
                                            <span id="fsbhoa_card_issue_date_display"><?php echo esc_html($form_data['card_issue_date']); ?></span>
                                        </div>
                                        <?php endif; ?>

                                        <?php $current_resident_type_for_expiry_display = isset($form_data['resident_type']) ? $form_data['resident_type'] : ''; ?>
                                        <?php if ($current_resident_type_for_expiry_display === 'Contractor') : ?>
                                            <div style="white-space: nowrap;" id="fsbhoa_expiry_date_wrapper_contractor">
                                                <label for="card_expiry_date_contractor_input" style="font-weight: bold; margin-right: .3em;"><?php esc_html_e( 'Expires (Contractor):', 'fsbhoa-ac' ); ?></label>
                                                <input type="date" name="card_expiry_date" id="card_expiry_date_contractor_input" class="regular-text"
                                                       value="<?php echo esc_attr((isset($form_data['card_expiry_date']) && $form_data['card_expiry_date'] && $form_data['card_expiry_date'] !== '0000-00-00') ? $form_data['card_expiry_date'] : FSBHOA_WAY_OUT_EXPIRY_DATE); ?>"
                                                       style="width: 10em;">
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="submitted_card_status" id="fsbhoa_submitted_card_status" value="<?php echo esc_attr($form_data['card_status']); ?>">
                                <input type="hidden" name="submitted_card_issue_date" id="fsbhoa_submitted_card_issue_date" value="<?php echo esc_attr($form_data['card_issue_date']); ?>">

                                <p class="description" style="margin-top: .5em;">
                                    <?php if (!$is_edit_mode) : echo esc_html__( 'RFID details managed after cardholder is added (on the Edit screen).', 'fsbhoa-ac' );
                                          else: echo esc_html__( 'Use checkbox to update card status. RFID assignment also activates card. Contractors require an expiry date for active cards.', 'fsbhoa-ac' ); endif; ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="notes"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></label></th>
                            <td><textarea name="notes" id="notes" rows="3" class="large-text"><?php echo esc_textarea(isset($form_data['notes']) ? $form_data['notes'] : ''); ?></textarea></td>
                        </tr>

                        <tr>
                            <th scope="row"><label><?php esc_html_e( 'Cardholder Photo', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <div style="display: flex; flex-wrap: wrap; gap: 20px;">
                                    <div id="fsbhoa_main_photo_preview_area" style="flex-basis: 200px; margin-bottom: 10px; text-align: center;">
                                        <strong><?php esc_html_e('Current Photo Preview', 'fsbhoa-ac'); ?></strong><br>
                                        <img id="fsbhoa_photo_preview_main_img" src="<?php echo ($is_edit_mode && isset($form_data['photo']) && !empty($form_data['photo'])) ? 'data:image/jpeg;base64,' . base64_encode($form_data['photo']) : '#'; ?>" alt="<?php esc_attr_e('Photo Preview', 'fsbhoa-ac'); ?>" style="max-width: 150px; max-height: 150px; border: 1px solid #ddd; padding: 2px; margin-top: 5px; <?php if (!($is_edit_mode && isset($form_data['photo']) && !empty($form_data['photo']))) echo 'display:none;'; ?>">
                                        <p id="fsbhoa_no_photo_message" style="<?php if ($is_edit_mode && isset($form_data['photo']) && !empty($form_data['photo'])) echo 'display:none;'; ?>"><em><?php esc_html_e('No photo selected/uploaded.', 'fsbhoa-ac'); ?></em></p>
                                        <?php if ($is_edit_mode && isset($form_data['photo']) && !empty($form_data['photo'])) : ?>
                                            <label style="display: block; margin-top: 5px;"><input type="checkbox" name="remove_current_photo" id="fsbhoa_remove_current_photo_checkbox" value="1"> <?php esc_html_e('Remove current photo', 'fsbhoa-ac'); ?></label>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex-grow: 1;">
                                        <div id="fsbhoa_file_upload_section" style="margin-bottom:15px;"><strong><?php esc_html_e('Option 1: Upload Photo File', 'fsbhoa-ac'); ?></strong><br><input type="file" name="cardholder_photo" id="cardholder_photo_file_input" accept="image/jpeg,image/png,image/gif"><p class="description"><?php esc_html_e('JPG, PNG, GIF, max 2MB.', 'fsbhoa-ac'); ?></p></div>
                                        <div id="fsbhoa_webcam_section" style="margin-bottom:15px;"><strong><?php esc_html_e('Option 2: Use Webcam', 'fsbhoa-ac'); ?></strong><br><button type="button" id="fsbhoa_start_webcam_button" class="button"><?php esc_html_e('Start Webcam', 'fsbhoa-ac'); ?></button><div id="fsbhoa_webcam_active_controls" style="display:none; margin-top:5px;"><button type="button" id="fsbhoa_capture_photo_button" class="button"><?php esc_html_e('Capture Photo', 'fsbhoa-ac'); ?></button> <button type="button" id="fsbhoa_stop_webcam_button" class="button"><?php esc_html_e('Stop Webcam', 'fsbhoa-ac'); ?></button></div><div id="fsbhoa_webcam_container" style="margin-top:10px;"><video id="fsbhoa_webcam_video" width="320" height="240" autoplay style="border:1px solid #ccc; display:none;"></video><canvas id="fsbhoa_webcam_canvas" style="display:none;"></canvas></div><input type="hidden" name="webcam_photo_data" id="fsbhoa_webcam_photo_data"><p class="description" id="fsbhoa_webcam_status"></p></div>
                                    </div>
                                </div>
                                <p class="description" style="clear:both; margin-top: 10px;"><?php esc_html_e('The most recently uploaded file or captured webcam photo will be saved.', 'fsbhoa-ac'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( $submit_button_text, 'primary', $submit_button_name ); ?>
                <a href="?page=fsbhoa_ac_cardholders" class="button button-secondary" style="margin-left: 10px; vertical-align: top;"><?php esc_html_e( 'Cancel', 'fsbhoa-ac' ); ?></a>
            </form>
        </div>
        <?php
    } // End render_add_new_cardholder_form


} // end class Fsbhoa_Cardholder_Admin_Page
?>
