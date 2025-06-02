<?php
/**
 * Handles the admin page for Cardholder management.
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
     * Hooks into WordPress actions.
     *
     * @since 0.1.10 
     */
    public function __construct() {
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
        add_action('admin_post_fsbhoa_do_add_cardholder', array($this, 'handle_add_cardholder_submission'));
        add_action('admin_post_fsbhoa_do_update_cardholder', array($this, 'handle_update_cardholder_submission'));
    }

    /**
     * Handles the actual deletion of a cardholder.
     * @since 0.1.7
     */
    public function handle_delete_cardholder_action() {
        // ... (This method is already complete and working from response #104 / #105)
        if (!isset($_GET['cardholder_id']) || !is_numeric($_GET['cardholder_id'])) {
            wp_die(esc_html__('Invalid cardholder ID for deletion.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 400, 'back_link' => true));
        }
        $item_id_to_delete = absint($_GET['cardholder_id']);
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'fsbhoa_delete_cardholder_nonce_' . $item_id_to_delete)) {
            wp_die(esc_html__('Security check failed. Could not delete cardholder.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }

        global $wpdb;
        $table_name = 'ac_cardholders';
        $result = $wpdb->delete($table_name, array('id' => $item_id_to_delete), array('%d'));
        
        $base_redirect_url = admin_url('admin.php');
        $redirect_url = add_query_arg(array('page' => 'fsbhoa_ac_cardholders'), $base_redirect_url);

        if ($result === false) {
            $redirect_url = add_query_arg(array('message' => 'cardholder_delete_error'), $redirect_url);
        } elseif ($result === 0) {
            $redirect_url = add_query_arg(array('message' => 'cardholder_delete_not_found'), $redirect_url);
        } else {
            $redirect_url = add_query_arg(array('message' => 'cardholder_deleted_successfully', 'deleted_id' => $item_id_to_delete), $redirect_url);
        }
        
        wp_redirect(esc_url_raw($redirect_url));
        exit;
    }

    /**
     * AJAX callback to search properties.
     * @since 0.1.5
     */
    public function ajax_search_properties_callback() {
        // ... (This method is already complete and working from response #100 / #101)
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
     * Handles submission for adding a new cardholder.
     * Hooked to 'admin_post_fsbhoa_do_add_cardholder'.
     * @since 0.1.10
     */
    public function handle_add_cardholder_submission() {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';

        if (!isset($_POST['fsbhoa_add_cardholder_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {
            wp_die(esc_html__('Security check failed (add cardholder).', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }

        $form_data = array(); $errors = array(); $photo_binary_data_for_db = null;
        // Sanitize and collect all form data (as in previous full render_add_new_cardholder_form POST block)
        $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $form_data['email']         = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
        $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
        $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';

        // Photo handling (from previous full render_add_new_cardholder_form POST block)
        if (isset($_POST['webcam_photo_data']) && !empty($_POST['webcam_photo_data'])) { /* process webcam, set $photo_binary_data_for_db or $errors */ 
            $base64 = sanitize_text_field(wp_unslash($_POST['webcam_photo_data']));
            $decoded = base64_decode($base64, true);
            if ($decoded) $photo_binary_data_for_db = $decoded; else $errors['photo'] = 'Invalid webcam data.';
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] == UPLOAD_ERR_OK) { /* process file, set $photo_binary_data_for_db or $errors */ 
            // Full file validation logic from previous response
            $file_info = wp_check_filetype_and_ext($_FILES['cardholder_photo']['tmp_name'], $_FILES['cardholder_photo']['name']);
            $allowed_mime_types = array('image/jpeg', 'image/png', 'image/gif');
            if (empty($file_info['type']) || !in_array($file_info['type'], $allowed_mime_types)) { $errors['cardholder_photo'] = __('Invalid file type. JPG, PNG, GIF only.', 'fsbhoa-ac'); }
            elseif ($_FILES['cardholder_photo']['size'] > 2 * 1024 * 1024) { $errors['cardholder_photo'] = __('File too large. Max 2MB.', 'fsbhoa-ac'); }
            else { $file_upload_content = file_get_contents($_FILES['cardholder_photo']['tmp_name']);
                if ($file_upload_content === false) { $errors['cardholder_photo'] = __('Could not read uploaded file.', 'fsbhoa-ac'); } 
                else { $photo_binary_data_for_db = $file_upload_content; }
            }
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] != UPLOAD_ERR_NO_FILE) { $errors['cardholder_photo'] = __('File upload error. Code: ', 'fsbhoa-ac') . $_FILES['cardholder_photo']['error'];}


        // All field validations (from previous full render_add_new_cardholder_form POST block)
        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other'); 
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Other');
        if (empty($form_data['first_name'])) { $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); }
        // ... all other validations ...
        if (empty($form_data['last_name'])) { $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['email']) && !is_email($form_data['email'])) { $errors['email'] = __( 'Valid email required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['phone'])) { $phone_regex = '/^(?:1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}$/'; if (!preg_match($phone_regex, $form_data['phone'])) { $errors['phone'] = __( 'Valid phone format required.', 'fsbhoa-ac' );}}
        if (!in_array($form_data['phone_type'], $allowed_phone_types)) { $errors['phone_type'] = __('Invalid phone type.', 'fsbhoa-ac'); }
        elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) { $errors['phone_type'] = __('Select phone type.', 'fsbhoa-ac');}
        if (!in_array($form_data['resident_type'], $allowed_resident_types)) { $errors['resident_type'] = __('Invalid resident type.', 'fsbhoa-ac');}
        if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) { $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac'); }


        // Duplicate Check for Add Mode
        if (empty($errors)) { 
            $existing_cardholder = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s", $form_data['first_name'], $form_data['last_name'] ) );
            if ($existing_cardholder) { $errors['duplicate'] = sprintf(__( 'A cardholder named %1$s %2$s already exists (ID: %3$d).', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $existing_cardholder->id); }
        }

        $add_form_url = admin_url('admin.php?page=fsbhoa_ac_cardholders&action=add');
        $list_page_url = admin_url('admin.php?page=fsbhoa_ac_cardholders');

        if (empty($errors)) {
            $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';
            $data_to_insert = array(
                'first_name' => $form_data['first_name'], 'last_name' => $form_data['last_name'],
                'email' => $form_data['email'], 'phone' => $phone_to_store,
                'phone_type' => $form_data['phone_type'], 'resident_type' => $form_data['resident_type'],
                'property_id' => !empty($form_data['property_id']) ? $form_data['property_id'] : null,
                'photo' => $photo_binary_data_for_db,
            );
            $current_data_formats = array(); foreach (array_keys($data_to_insert) as $key) { $current_data_formats[] = ($key === 'property_id' ? '%d' : '%s'); }
            
            $result = $wpdb->insert($cardholder_table_name, $data_to_insert, $current_data_formats);

            if ($result) { 
                $redirect_url = add_query_arg(array('message' => 'cardholder_added_successfully', 'added_id' => $wpdb->insert_id), $list_page_url);
            } else { 
                $redirect_url = add_query_arg(array('message' => 'cardholder_add_dberror'), $add_form_url);
            }
        } else {
            $error_keys = implode(',', array_keys($errors));
            $redirect_url = add_query_arg(array('message' => 'cardholder_validation_error', 'error_fields' => $error_keys), $add_form_url);
            // For passing actual error messages and form data back for sticky form, would need sessions or transients.
            // For now, just a generic error and user re-enters.
        }
        wp_redirect(esc_url_raw($redirect_url));
        exit;
    }

    /**
     * Handles submission for updating an existing cardholder.
     * Hooked to 'admin_post_fsbhoa_do_update_cardholder'.
     * @since 0.1.10
     */
    public function handle_update_cardholder_submission() {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';
        $item_id_for_edit = isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0;

        if ($item_id_for_edit <= 0) {
            wp_die(esc_html__('Invalid cardholder ID for update.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 400, 'back_link' => true));
        }
        if (!isset($_POST['fsbhoa_update_cardholder_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_update_cardholder_nonce'])), 'fsbhoa_update_cardholder_action_' . $item_id_for_edit)) {
            wp_die(esc_html__('Security check failed (update cardholder).', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }

        $form_data = array(); $errors = array(); $photo_binary_data_for_db = null;
        // Sanitize and collect form data
        $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        // ... (collect all other fields from $_POST into $form_data as in add handler) ...
        $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $form_data['email']         = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
        $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
        $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';

        // Photo handling
        if (isset($_POST['webcam_photo_data']) && !empty($_POST['webcam_photo_data'])) { /* process webcam */ }
        elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] == UPLOAD_ERR_OK) { /* process file */ }
        elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] != UPLOAD_ERR_NO_FILE) { $errors['cardholder_photo'] = 'File upload error Code: ' . $_FILES['cardholder_photo']['error'];}
        // (This is a simplified photo handling block for brevity, copy full logic from handle_add_cardholder_submission)


        // All field validations (as in add handler)
        // ... (all validations) ...

        // Duplicate Check for Edit Mode
        if (empty($errors)) { 
            $sql_prepare_args = array($form_data['first_name'], $form_data['last_name'], $item_id_for_edit);
            $duplicate_sql = "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s AND id != %d";
            $existing_cardholder = $wpdb->get_row($wpdb->prepare($duplicate_sql, $sql_prepare_args));
            if ($existing_cardholder) { $errors['duplicate'] = 'Another cardholder with this name exists.'; }
        }

        $edit_form_url = admin_url('admin.php?page=fsbhoa_ac_cardholders&action=edit_cardholder&cardholder_id=' . $item_id_for_edit);
        $list_page_url = admin_url('admin.php?page=fsbhoa_ac_cardholders');

        if (empty($errors)) {
            $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';
            $data_to_update = array( /* ... all fields ... */ );
            if ($photo_binary_data_for_db !== null) { $data_to_update['photo'] = $photo_binary_data_for_db; } 
            elseif (isset($_POST['remove_current_photo']) && $_POST['remove_current_photo'] === '1') { $data_to_update['photo'] = null; }
            // ... (construct $data_to_update and $current_data_formats) ...

            $result = $wpdb->update($cardholder_table_name, $data_to_update, array('id' => $item_id_for_edit), $current_data_formats, array('%d'));

            if ($result === false) { $redirect_url = add_query_arg(array('message' => 'cardholder_update_dberror'), $edit_form_url); }
            elseif ($result === 0) { $redirect_url = add_query_arg(array('message' => 'cardholder_no_changes'), $edit_form_url); }
            else { $redirect_url = add_query_arg(array('message' => 'cardholder_updated_successfully', 'updated_id' => $item_id_for_edit), $list_page_url); }
        } else {
            $redirect_url = add_query_arg(array('message' => 'cardholder_validation_error_edit'), $edit_form_url);
            // Again, passing specific errors and sticky data for edit form from admin-post is complex without sessions/transients.
        }
        wp_redirect(esc_url_raw($redirect_url));
        exit;
    }


    /**
     * Handles page routing for cardholder admin.
     * @since 0.1.7
     */
    public function render_page() {
        // ... (existing code, but message display logic below is now in render_cardholders_list_page and render_add_new_cardholder_form) ...
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : ''; 
        if ('add' === $action || 'edit_cardholder' === $action ) { 
            $this->render_add_new_cardholder_form($action);
        } else {
            $this->render_cardholders_list_page();
        }
    }

/**
     * Renders the list of cardholders.
     * @since 0.1.9 
     */
    public function render_cardholders_list_page() {
        error_log('FSBHOA DEBUG CH_LIST: render_cardholders_list_page() called.');

        // Check if the List Table class exists before trying to use it
        if (!class_exists('Fsbhoa_Cardholder_List_Table')) {
            error_log('FSBHOA DEBUG CH_LIST: Fsbhoa_Cardholder_List_Table class NOT FOUND!');
            echo '<div class="error"><p>Error: Cardholder List Table class not found. Please check plugin file inclusions.</p></div>';
            return;
        } else {
            error_log('FSBHOA DEBUG CH_LIST: Fsbhoa_Cardholder_List_Table class IS found.');
        }

        $cardholder_list_table = new Fsbhoa_Cardholder_List_Table();
        error_log('FSBHOA DEBUG CH_LIST: Fsbhoa_Cardholder_List_Table object instantiated.');

        $cardholder_list_table->prepare_items();
        error_log('FSBHOA DEBUG CH_LIST: prepare_items() called.');
        // To see if items were found by prepare_items:
        // error_log('FSBHOA DEBUG CH_LIST: List table items count: ' . count($cardholder_list_table->items));


        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>
            <a href="?page=fsbhoa_ac_cardholders&action=add" class="page-title-action">
                <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
            </a>
            <?php 
            // ... (your existing message display logic - ensure it's the correct one) ...
            if (isset($_GET['message'])) {
                $message_code = sanitize_key($_GET['message']);
                $processed_id = 0; // Simplified for this example
                if (isset($_GET['added_id'])) $processed_id = absint($_GET['added_id']);
                if (isset($_GET['updated_id'])) $processed_id = absint($_GET['updated_id']);
                if (isset($_GET['deleted_id'])) $processed_id = absint($_GET['deleted_id']);

                $message_text = ''; $notice_class = 'notice-info';
                switch ($message_code) {
                    case 'cardholder_added_successfully':    $message_text = sprintf(esc_html__('Cardholder added! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    case 'cardholder_updated_successfully':  $message_text = sprintf(esc_html__('Cardholder updated! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    case 'cardholder_deleted_successfully':  $message_text = sprintf(esc_html__('Cardholder deleted! ID: %d', 'fsbhoa-ac'), $processed_id); $notice_class = 'updated'; break;
                    // Add other error cases here as needed
                    case 'cardholder_validation_error': $message_text = esc_html__('Validation failed when adding/updating cardholder.', 'fsbhoa-ac'); $notice_class = 'error'; break;
                    case 'cardholder_add_dberror': $message_text = esc_html__('DB error when adding cardholder.', 'fsbhoa-ac'); $notice_class = 'error'; break;

                }
                if (!empty($message_text)) {
                    echo '<div id="message" class="' . esc_attr($notice_class) . ' notice is-dismissible"><p>' . $message_text . '</p></div>';
                }
            }
            ?>
            <form method="post">
                <input type="hidden" name="page" value="<?php echo esc_attr( isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '' ); ?>" />
                <?php 
                error_log('FSBHOA DEBUG CH_LIST: About to call display() for cardholder list table.');
                $cardholder_list_table->display(); 
                error_log('FSBHOA DEBUG CH_LIST: After calling display() for cardholder list table.');
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Renders the form for adding or editing a cardholder.
     * This method now primarily handles form display and GET request logic for edit.
     * All POST submissions are handled by dedicated admin_post_ action handlers.
     * @since 0.1.10
     * @param string $current_page_action ('add' or 'edit_cardholder')
     */
    public function render_add_new_cardholder_form($current_page_action = 'add') {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders'; // Not used for DB ops here anymore, but kept for consistency if needed
        $property_table_name = 'ac_property';

        $form_data = array( /* Default empty values, same as before */
            'first_name'    => '', 'last_name'     => '', 'email'         => '', 'phone'         => '',
            'phone_type'    => '', 'resident_type' => '', 'property_id'   => '', 
            'property_address_display' => '', 'photo'         => null, 
        );
        // $errors array is no longer populated here from POST, but messages from GET can be displayed
        $item_id_for_edit = null; 
        $is_edit_mode = ($current_page_action === 'edit_cardholder' && isset($_GET['cardholder_id']));

        // If in edit mode (this is a GET request to show the edit form), fetch existing data
        if ($is_edit_mode) {
            $item_id_for_edit = absint($_GET['cardholder_id']);
            if ($item_id_for_edit > 0) {
                $cardholder_to_edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$cardholder_table_name} WHERE id = %d", $item_id_for_edit), ARRAY_A);
                if ($cardholder_to_edit) {
                    $form_data = array_merge($form_data, $cardholder_to_edit);
                    if (!empty($form_data['property_id'])) {
                        $property_address = $wpdb->get_var($wpdb->prepare("SELECT street_address FROM {$property_table_name} WHERE property_id = %d", $form_data['property_id']));
                        if ($property_address) { $form_data['property_address_display'] = $property_address; }
                    }
                } else { echo '<div class="error"><p>' . esc_html__('Cardholder not found for editing.', 'fsbhoa-ac') . '</p></div>'; return; }
            } else { echo '<div class="error"><p>' . esc_html__('Invalid Cardholder ID for editing.', 'fsbhoa-ac') . '</p></div>'; return; }
        }
        
        // --- Display messages passed back from admin-post handler via GET parameters ---
        if (isset($_GET['message'])) {
            $message_code = sanitize_key($_GET['message']);
            $error_fields_str = isset($_GET['error_fields']) ? sanitize_text_field($_GET['error_fields']) : '';
            $message_text = ''; $notice_class = 'error'; // Default to error

            switch ($message_code) {
                case 'cardholder_validation_error': // From failed add or update attempts
                    $message_text = __('Submission failed. Please correct the indicated fields and try again.', 'fsbhoa-ac');
                    if ($error_fields_str) $message_text .= ' Problem fields: ' . esc_html($error_fields_str);
                    break;
                case 'cardholder_add_dberror':
                case 'cardholder_update_dberror':
                    $message_text = __('Error saving cardholder to database. Please try again.', 'fsbhoa-ac');
                    break;
                // Success messages are usually shown on the list page after redirect.
                // Only error messages would typically redirect back to the form.
            }
            if (!empty($message_text)) {
                echo '<div id="message" class="' . esc_attr($notice_class) . ' notice is-dismissible"><p>' . $message_text . '</p></div>';
            }
            // Note: This simplified message display for form errors doesn't re-populate form data (sticky form).
            // Full sticky form on error after redirect from admin-post requires sessions or transients.
            // For now, the user will see the error and the form will be blank (for add) or show original DB data (for edit).
        }

        // Form rendering variables
        $page_title = $is_edit_mode ? __( 'Edit Cardholder', 'fsbhoa-ac' ) : __( 'Add New Cardholder', 'fsbhoa-ac' );
        $submit_button_text = $is_edit_mode ? __( 'Update Cardholder', 'fsbhoa-ac' ) : __( 'Add Cardholder', 'fsbhoa-ac' ); // Changed "Save Basic..."
        $submit_button_name = $is_edit_mode ? 'submit_update_cardholder' : 'submit_add_cardholder';
        
        $current_item_id_for_nonce_action = ($is_edit_mode && $item_id_for_edit) ? $item_id_for_edit : 0;
        $nonce_action = $is_edit_mode ? ('fsbhoa_update_cardholder_action_' . $current_item_id_for_nonce_action) : 'fsbhoa_add_cardholder_action';
        $nonce_name   = $is_edit_mode ? 'fsbhoa_update_cardholder_nonce' : 'fsbhoa_add_cardholder_nonce';
        
        // The form now always posts to admin-post.php
        // The hidden "action" field value will determine which admin_post_ hook is triggered.
        $form_post_action_name = $is_edit_mode ? 'fsbhoa_do_update_cardholder' : 'fsbhoa_do_add_cardholder';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data"> 
                
                <input type="hidden" name="action" value="<?php echo esc_attr($form_post_action_name); ?>" />
                
                <?php if ($is_edit_mode && $item_id_for_edit) : ?>
                    <input type="hidden" name="cardholder_id" value="<?php echo esc_attr($item_id_for_edit); ?>" />
                <?php endif; ?>
                <?php wp_nonce_field( $nonce_action, $nonce_name ); // Nonce action matches handler ?>
                
                <table class="form-table">
                    <tbody>
                        <?php // All table rows for inputs as in response #109 HTML snippet ?>
                        <tr><th scope="row"><label for="first_name"><?php esc_html_e( 'First Name', 'fsbhoa-ac' ); ?></label></th><td><input type="text" name="first_name" id="first_name" class="regular-text" value="<?php echo esc_attr(isset($form_data['first_name']) ? $form_data['first_name'] : ''); ?>" required></td></tr>
                        <tr><th scope="row"><label for="last_name"><?php esc_html_e( 'Last Name', 'fsbhoa-ac' ); ?></label></th><td><input type="text" name="last_name" id="last_name" class="regular-text" value="<?php echo esc_attr(isset($form_data['last_name']) ? $form_data['last_name'] : ''); ?>" required></td></tr>
                        <tr><th scope="row"><label for="email"><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></label></th><td><input type="email" name="email" id="email" class="regular-text" value="<?php echo esc_attr(isset($form_data['email']) ? $form_data['email'] : ''); ?>"><p class="description"><?php esc_html_e( 'Optional.', 'fsbhoa-ac' ); ?></p></td></tr>
                        <tr><th scope="row"><label for="phone"><?php esc_html_e( 'Phone Number', 'fsbhoa-ac' ); ?></label></th><td><input type="tel" name="phone" id="phone" class="regular-text" style="width: 15em; margin-right: 1em;" value="<?php echo esc_attr(isset($form_data['phone']) ? $form_data['phone'] : ''); ?>"><select name="phone_type" id="phone_type" style="vertical-align: baseline;"><option value="" <?php selected(isset($form_data['phone_type']) ? $form_data['phone_type'] : '', ''); ?>>-- Select Type --</option><option value="Mobile" <?php selected(isset($form_data['phone_type']) ? $form_data['phone_type'] : '', 'Mobile'); ?>>Mobile</option><option value="Home" <?php selected(isset($form_data['phone_type']) ? $form_data['phone_type'] : '', 'Home'); ?>>Home</option><option value="Work" <?php selected(isset($form_data['phone_type']) ? $form_data['phone_type'] : '', 'Work'); ?>>Work</option><option value="Other" <?php selected(isset($form_data['phone_type']) ? $form_data['phone_type'] : '', 'Other'); ?>>Other</option></select></td></tr>
                        <tr><th scope="row"><label for="resident_type"><?php esc_html_e( 'Resident Type', 'fsbhoa-ac' ); ?></label></th><td><select name="resident_type" id="resident_type"><option value="" <?php selected(isset($form_data['resident_type']) ? $form_data['resident_type'] : '', ''); ?>>-- Select Type --</option><option value="Resident Owner" <?php selected(isset($form_data['resident_type']) ? $form_data['resident_type'] : '', 'Resident Owner'); ?>>Resident Owner</option><option value="Non-resident Owner" <?php selected(isset($form_data['resident_type']) ? $form_data['resident_type'] : '', 'Non-resident Owner'); ?>>Non-resident Owner</option><option value="Tenant" <?php selected(isset($form_data['resident_type']) ? $form_data['resident_type'] : '', 'Tenant'); ?>>Tenant</option><option value="Staff" <?php selected(isset($form_data['resident_type']) ? $form_data['resident_type'] : '', 'Staff'); ?>>Staff</option><option value="Contractor" <?php selected(isset($form_data['resident_type']) ? $form_data['resident_type'] : '', 'Contractor'); ?>>Contractor</option><option value="Other" <?php selected(isset($form_data['resident_type']) ? $form_data['resident_type'] : '', 'Other'); ?>>Other</option></select></td></tr>
                        <tr><th scope="row"><label for="fsbhoa_property_search_input"><?php esc_html_e( 'Property Address', 'fsbhoa-ac' ); ?></label></th><td><input type="text" id="fsbhoa_property_search_input" name="property_address_display" class="regular-text" placeholder="<?php esc_attr_e( 'Start typing address...', 'fsbhoa-ac' ); ?>" value="<?php echo esc_attr(isset($form_data['property_address_display']) ? $form_data['property_address_display'] : ''); ?>"><input type="hidden" name="property_id" id="fsbhoa_property_id_hidden" value="<?php echo esc_attr(isset($form_data['property_id']) ? $form_data['property_id'] : ''); ?>"><p class="description"><?php esc_html_e( 'Type 1+ characters to search.', 'fsbhoa-ac' ); ?> <span id="fsbhoa_property_clear_selection" style="display: <?php echo (isset($form_data['property_id']) && !empty($form_data['property_id'])) ? 'inline' : 'none'; ?>; margin-left:10px; color: #0073aa; cursor:pointer;"><?php esc_html_e('[Clear Selection]', 'fsbhoa-ac'); ?></span></p><div id="fsbhoa_selected_property_display" style="margin-top:5px; font-style:italic;"><?php if ($is_edit_mode && !empty($form_data['property_id']) && !empty($form_data['property_address_display'])) { echo 'Currently assigned: ' . esc_html($form_data['property_address_display']); } ?></div><div id="fsbhoa_property_search_no_results" style="color: #dc3232; margin-top: 5px; min-height: 1em;"></div></td></tr>
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
    }

} // end class Fsbhoa_Cardholder_Admin_Page
?>
