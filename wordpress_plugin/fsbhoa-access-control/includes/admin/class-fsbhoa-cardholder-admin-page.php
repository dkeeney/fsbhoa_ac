<?php
/**
 * Handles the DISPLAY of admin pages for Cardholder management.
 * Action processing is handled by Fsbhoa_Cardholder_Actions class.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Cardholder_Admin_Page {

    // Constructor is REMOVED from this class. Hooks are in Fsbhoa_Cardholder_Actions.

    /**
     * Handles page routing for cardholder admin display.
     * @since 0.1.11 (Refactored)
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : ''; 
        // error_log('FSBHOA RENDER CARDHOLDER PAGE: $_GET array: ' . print_r($_GET, true)); // Keep for GET param debugging

        if ('add' === $action || 'edit_cardholder' === $action ) { 
            $this->render_add_new_cardholder_form($action);
        } else {
            $this->render_cardholders_list_page();
        }
    }

    /**
     * Renders the list of cardholders.
     * @since 0.1.9 (Message handling updated in 0.1.11)
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

                $message_text = ''; $notice_class = 'notice-info';
                switch ($message_code) {
                    case 'cardholder_added_successfully':    $message_text = sprintf(esc_html__('Cardholder added! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    case 'cardholder_updated_successfully':  $message_text = sprintf(esc_html__('Cardholder updated! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    case 'cardholder_deleted_successfully':  $message_text = sprintf(esc_html__('Cardholder deleted! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    // Error messages are now primarily shown on the form page itself after redirect.
                    // We might only show very generic errors here if a handler couldn't redirect to form.
                    case 'cardholder_add_dberror': case 'cardholder_update_dberror': $message_text = esc_html__('Database error during save.', 'fsbhoa-ac'); $notice_class = 'error'; break;
                    case 'cardholder_delete_error':          $message_text = esc_html__('Error deleting cardholder.', 'fsbhoa-ac'); $notice_class = 'error'; break;
                    case 'cardholder_delete_not_found':      $message_text = esc_html__('Cardholder not found for deletion.', 'fsbhoa-ac'); $notice_class = 'notice-warning'; break;
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
     * This method now primarily handles form display, GET request logic for edit,
     * and retrieving/displaying data and errors from transients after validation failure redirects.
     * POST submissions are handled by dedicated admin_post_ action handlers.
     *
     * @since 0.1.11 (Revised for transient-based sticky forms and errors)
     * @param string $current_page_action ('add' or 'edit_cardholder' from GET).
     */
    public function render_add_new_cardholder_form($current_page_action = 'add') {
        global $wpdb;
        // These table names are only used for fetching property address for display in edit mode
        $cardholder_table_name = 'ac_cardholders'; 
        $property_table_name = 'ac_property';

        // Initialize form data with defaults
        $form_data = array(
            'first_name'    => '', 'last_name'     => '', 
            'email'         => '', 'phone'         => '',
            'phone_type'    => '', 'resident_type' => '', 
            'property_id'   => '', 'property_address_display' => '',
            'photo'         => null, // This will hold current photo blob if editing
        );
        $display_specific_errors = array(); // For errors retrieved from transient
        
        $item_id_for_edit = null; 
        $is_edit_mode = ($current_page_action === 'edit_cardholder' && isset($_GET['cardholder_id']));
        $user_id = get_current_user_id();
        $loaded_from_transient = false;

        if ($is_edit_mode) {
            $item_id_for_edit = absint($_GET['cardholder_id']);
            // Try to load from transient first if coming back from a validation error on this specific item
            if (isset($_GET['message']) && $_GET['message'] === 'cardholder_validation_error_edit') {
                error_log('FSBHOA RENDER EDIT FORM: Validation error message detected from GET for item ID: ' . $item_id_for_edit);
                
                $form_transient_key = 'fsbhoa_edit_ch_data_' . $item_id_for_edit . '_' . $user_id;
                $errors_transient_key = 'fsbhoa_edit_ch_errors_' . $item_id_for_edit . '_' . $user_id;
                error_log('FSBHOA RENDER EDIT FORM: Attempting to get transients. Keys: ' . $form_transient_key . ', ' . $errors_transient_key);

                $transient_form_data = get_transient($form_transient_key);
                if ($transient_form_data !== false) { // Check against false as empty array is valid
                    error_log('FSBHOA RENDER EDIT FORM: Found form_data transient. Merging. Content: ' . print_r($transient_form_data, true));
                    $form_data = array_merge($form_data, $transient_form_data); 
                    delete_transient($form_transient_key);
                    $loaded_from_transient = true;
                } else {
                    error_log('FSBHOA RENDER EDIT FORM: Form_data transient NOT found or expired for key: ' . $form_transient_key);
                }

                $transient_errors = get_transient($errors_transient_key);
                if ($transient_errors !== false) {
                    error_log('FSBHOA RENDER EDIT FORM: Found errors transient. Using for display. Errors: ' . print_r($transient_errors, true));
                    $display_specific_errors = $transient_errors; 
                    delete_transient($errors_transient_key);
                } else {
                    error_log('FSBHOA RENDER EDIT FORM: Errors transient NOT found or expired for key: ' . $errors_transient_key);
                }
            }
            
            // If not loaded from transient (e.g., initial GET load of edit form), fetch from DB
            if (!$loaded_from_transient && $item_id_for_edit > 0) { 
                // Only fetch if it's a GET request; POST data should persist via transient for errors
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    error_log('FSBHOA RENDER EDIT FORM: Not loaded from transient (initial GET), fetching from DB for item ID: ' . $item_id_for_edit);
                    $cardholder_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cardholder_table_name} WHERE id = %d", $item_id_for_edit), ARRAY_A);
                    if ($cardholder_to_edit) {
                        $form_data = array_merge($form_data, $cardholder_to_edit); // Populate with DB data
                        // Fetch property address display if property_id is set
                        if (!empty($form_data['property_id'])) {
                            $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM {$property_table_name} WHERE property_id = %d", $form_data['property_id']));
                            if ($property_address) { $form_data['property_address_display'] = $property_address; }
                        }
                    } else { echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Cardholder not found for editing.', 'fsbhoa-ac') . '</p></div>'; return; }
                }
            } elseif (!$loaded_from_transient && $item_id_for_edit <= 0 && $current_page_action === 'edit_cardholder') { 
                 echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Invalid Cardholder ID for editing.', 'fsbhoa-ac') . '</p></div>'; return;
            }

        } else { // ADD mode
            if (isset($_GET['message']) && $_GET['message'] === 'cardholder_validation_error') {
                error_log('FSBHOA RENDER ADD FORM: Validation error message detected from GET.');
                $form_transient_key = 'fsbhoa_add_ch_data_' . $user_id;
                $errors_transient_key = 'fsbhoa_add_ch_errors_' . $user_id;
                error_log('FSBHOA RENDER ADD FORM: Attempting to get transients. Keys: ' . $form_transient_key . ', ' . $errors_transient_key);

                $transient_form_data = get_transient($form_transient_key);
                if ($transient_form_data !== false) {
                    error_log('FSBHOA RENDER ADD FORM: Found form_data transient. Merging. Content: ' . print_r($transient_form_data, true));
                    $form_data = array_merge($form_data, $transient_form_data);
                    delete_transient($form_transient_key);
                    $loaded_from_transient = true;
                } else {
                     error_log('FSBHOA RENDER ADD FORM: Form_data transient NOT found for key: ' . $form_transient_key);
                }
                $transient_errors = get_transient($errors_transient_key);
                if ($transient_errors !== false) {
                    error_log('FSBHOA RENDER ADD FORM: Found errors transient. Using for display. Errors: ' . print_r($transient_errors, true));
                    $display_specific_errors = $transient_errors;
                    delete_transient($errors_transient_key);
                } else {
                    error_log('FSBHOA RENDER ADD FORM: Errors transient NOT found for key: ' . $errors_transient_key);
                }
            }
            // Default phone type for truly NEW ADD form (not a reload from error with transient data)
            if (empty($form_data['phone_type']) && !$loaded_from_transient) {
                $form_data['phone_type'] = 'Mobile';
            }
        }
        
        // --- Display messages (either specific validation errors from transient, or generic GET messages) ---
        if (!empty($display_specific_errors)) {
            error_log('FSBHOA RENDER FORM: Displaying specific errors from $display_specific_errors array.');
            echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Please correct the errors highlighted below:', 'fsbhoa-ac') . '</p><ul>';
            foreach ($display_specific_errors as $field_key => $error_msg) {
                // Make field key more readable if needed, e.g., ucwords(str_replace('_', ' ', $field_key))
                echo '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $field_key))) . ':</strong> ' . esc_html($error_msg) . '</li>';
            }
            echo '</ul></div>';
        } elseif (isset($_GET['message'])) { 
            // Handle other generic messages if no specific errors were loaded from transient
            $message_code = sanitize_key($_GET['message']);
            error_log('FSBHOA RENDER FORM: Displaying generic message from GET: ' . $message_code);
            $notice_class = 'error'; 
            $message_text = '';
            switch ($message_code) {
                // These cases are primarily for errors if transient failed or for non-validation messages.
                case 'cardholder_add_dberror':
                    $message_text = esc_html__('Error saving new cardholder to database. Please try again.', 'fsbhoa-ac'); break;
                case 'cardholder_update_dberror':
                    $message_text = esc_html__('Error updating cardholder in database. Please try again.', 'fsbhoa-ac'); break;
                case 'cardholder_no_changes':
                    $message_text = esc_html__('No changes were detected for the cardholder.', 'fsbhoa-ac'); $notice_class = 'notice-info'; break;
                 // 'cardholder_validation_error' and 'cardholder_validation_error_edit' would ideally be handled by $display_specific_errors
                 // but can have a fallback generic message if transients failed.
                case 'cardholder_validation_error':
                case 'cardholder_validation_error_edit':
                    $message_text = __('Submission failed. Please ensure all required fields are correct and try again.', 'fsbhoa-ac'); break;
            }
            if (!empty($message_text)) {
                echo '<div id="message" class="' . esc_attr($notice_class) . ' notice is-dismissible"><p>' . $message_text . '</p></div>';
            }
        }

        // Form rendering variables
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
                        <tr><th scope="row"><label for="fsbhoa_property_search_input"><?php esc_html_e( 'Property Address', 'fsbhoa-ac' ); ?></label></th><td><input type="text" id="fsbhoa_property_search_input" name="property_address_display" class="regular-text" placeholder="<?php esc_attr_e( 'Start typing address...', 'fsbhoa-ac' ); ?>" value="<?php echo esc_attr($form_data['property_address_display']); ?>"><input type="hidden" name="property_id" id="fsbhoa_property_id_hidden" value="<?php echo esc_attr($form_data['property_id']); ?>"><p class="description"><?php esc_html_e( 'Type 1+ characters to search.', 'fsbhoa-ac' ); ?> <span id="fsbhoa_property_clear_selection" style="display: <?php echo empty($form_data['property_id']) ? 'none' : 'inline'; ?>; margin-left:10px; color: #0073aa; cursor:pointer;"><?php esc_html_e('[Clear Selection]', 'fsbhoa-ac'); ?></span></p><div id="fsbhoa_selected_property_display" style="margin-top:5px; font-style:italic;"><?php if ($is_edit_mode && !empty($form_data['property_id']) && !empty($form_data['property_address_display'])) { echo 'Currently assigned: ' . esc_html($form_data['property_address_display']); } ?></div><div id="fsbhoa_property_search_no_results" style="color: #dc3232; margin-top: 5px; min-height: 1em;"></div></td></tr>
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
    } // end render_add_new_cardholder_form()

} // end class Fsbhoa_Cardholder_Admin_Page
?>
