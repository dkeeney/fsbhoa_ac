<?php
/**
 * Handles all AJAX and admin-post actions for Cardholder management.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Cardholder_Actions {

    /**
     * Constructor.
     * Hooks into WordPress actions.
     *
     * @since 0.1.11
     */
    public function __construct() {
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
        add_action('admin_post_fsbhoa_do_add_cardholder', array($this, 'handle_add_cardholder_submission'));
        add_action('admin_post_fsbhoa_do_update_cardholder', array($this, 'handle_update_cardholder_submission'));
    }

    /**
     * AJAX callback to search properties.
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
     * Handles the actual deletion of a cardholder.
     * @since 0.1.7
     */
    public function handle_delete_cardholder_action() {
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
     * Handles submission for adding a new cardholder.
     * @since 0.1.10
     */
    public function handle_add_cardholder_submission() {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';

        if (!isset($_POST['fsbhoa_add_cardholder_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {
            wp_die(esc_html__('Security check failed (add cardholder).', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }

        $form_data = array(); $errors = array(); $photo_binary_data_for_db = null;
        
        $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $form_data['email']         = isset($_POST['email']) ? trim(sanitize_text_field(wp_unslash($_POST['email']))) : '';
        $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
        $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
        $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';

        if (isset($_POST['webcam_photo_data']) && !empty($_POST['webcam_photo_data'])) { 
            $base64 = sanitize_text_field(wp_unslash($_POST['webcam_photo_data'])); $decoded = base64_decode($base64, true);
            if ($decoded) $photo_binary_data_for_db = $decoded; else $errors['cardholder_photo'] = __('Invalid webcam data.', 'fsbhoa-ac');
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] == UPLOAD_ERR_OK) { 
            $file_info = wp_check_filetype_and_ext($_FILES['cardholder_photo']['tmp_name'], $_FILES['cardholder_photo']['name']);
            $allowed_mime_types = array('image/jpeg', 'image/png', 'image/gif');
            if (empty($file_info['type']) || !in_array($file_info['type'], $allowed_mime_types)) { $errors['cardholder_photo'] = __('Invalid file type. JPG, PNG, GIF only.', 'fsbhoa-ac'); }
            elseif ($_FILES['cardholder_photo']['size'] > 2 * 1024 * 1024) { $errors['cardholder_photo'] = __('File too large. Max 2MB.', 'fsbhoa-ac'); }
            else { $file_upload_content = file_get_contents($_FILES['cardholder_photo']['tmp_name']);
                if ($file_upload_content === false) { $errors['cardholder_photo'] = __('Could not read file.', 'fsbhoa-ac'); } 
                else { $photo_binary_data_for_db = $file_upload_content; }
            }
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] != UPLOAD_ERR_NO_FILE) { $errors['cardholder_photo'] = __('File upload error Code: ', 'fsbhoa-ac') . $_FILES['cardholder_photo']['error'];}
        
        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other'); 
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Caregiver', 'Other');
        if (empty($form_data['first_name'])) { $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); }
        if (empty($form_data['last_name'])) { $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['email']) && !is_email($form_data['email'])) { $errors['email'] = __( 'Valid email required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['phone'])) { $phone_regex = '/^(?:1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}$/'; if (!preg_match($phone_regex, $form_data['phone'])) { $errors['phone'] = __( 'Valid phone format required.', 'fsbhoa-ac' );}}
        if (!in_array($form_data['phone_type'], $allowed_phone_types)) { $errors['phone_type'] = __('Invalid phone type.', 'fsbhoa-ac'); }
        elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) { $errors['phone_type'] = __('Select phone type.', 'fsbhoa-ac');}
        if (!in_array($form_data['resident_type'], $allowed_resident_types)) { $errors['resident_type'] = __('Invalid resident type.', 'fsbhoa-ac');}
        if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) { $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac'); }
        
        if (empty($errors)) { 
            $existing_cardholder = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s", $form_data['first_name'], $form_data['last_name'] ) );
            if ($existing_cardholder) { $errors['duplicate'] = sprintf(__( 'A cardholder named %1$s %2$s already exists (ID: %3$d).', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $existing_cardholder->id); }
        }

        $add_form_url = admin_url('admin.php?page=fsbhoa_ac_cardholders&action=add');
        $list_page_url = admin_url('admin.php?page=fsbhoa_ac_cardholders');

        if (empty($errors)) {
            $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';
            $email_to_save = sanitize_email($form_data['email']); // Final sanitization for DB
            
            $data_to_insert = array(
                'first_name' => $form_data['first_name'], 'last_name' => $form_data['last_name'],
                'email' => $email_to_save, 'phone' => $phone_to_store,
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
            $user_id = get_current_user_id();
            $form_data_transient_key = 'fsbhoa_add_ch_data_' . $user_id;
            $errors_transient_key = 'fsbhoa_add_ch_errors_' . $user_id;
   
            error_log('FSBHOA ADD CH (HANDLER - ERROR PATH): Validation errors occurred.');
            error_log('FSBHOA ADD CH (HANDLER - ERROR PATH): Errors array: ' . print_r($errors, true));
            error_log('FSBHOA ADD CH (HANDLER - ERROR PATH): Form data to save in transient (' . $form_data_transient_key . '): ' . print_r($form_data, true));

            set_transient($form_data_transient_key, $form_data, MINUTE_IN_SECONDS * 5);
            set_transient($errors_transient_key, $errors, MINUTE_IN_SECONDS * 5);

            // $add_form_url is defined earlier in this function as admin_url('admin.php?page=fsbhoa_ac_cardholders&action=add')
            $redirect_url = add_query_arg(array('message' => 'cardholder_validation_error'), $add_form_url);
            error_log('FSBHOA ADD CH (HANDLER - ERROR PATH): Redirecting to: ' . $redirect_url);
            wp_redirect(esc_url_raw($redirect_url));
            exit;
        }
        wp_redirect(esc_url_raw($redirect_url));
        exit;
    }

/**
     * Handles submission for updating an existing cardholder.
     * Hooked to 'admin_post_fsbhoa_do_update_cardholder'.
     * @since 0.1.11 (Added more redirect logging)
     */
    public function handle_update_cardholder_submission() {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';
        // error_log('FSBHOA UPDATE CH (HANDLER): POST data: ' . print_r($_POST, true)); // Optional: log all POST

        $item_id_for_edit = isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0;

        if ($item_id_for_edit <= 0) {
            wp_die(esc_html__('Invalid cardholder ID for update.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 400, 'back_link' => true));
        }
        if (!isset($_POST['fsbhoa_update_cardholder_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_update_cardholder_nonce'])), 'fsbhoa_update_cardholder_action_' . $item_id_for_edit)) {
            wp_die(esc_html__('Security check failed (update cardholder).', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }
        error_log('FSBHOA UPDATE CH (HANDLER): Nonce verified for ID ' . $item_id_for_edit);

        $form_data = array(); $errors = array(); $photo_binary_data_for_db = null;
        
        // Sanitize and collect form data (ensure all fields are covered)
        $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $form_data['email']         = isset($_POST['email']) ? trim(sanitize_text_field(wp_unslash($_POST['email']))) : '';
        $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
        $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
        $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';
        error_log('FSBHOA UPDATE CH (HANDLER): Form data collected: ' . print_r($form_data, true));

        // Photo handling (ensure this logic is complete from previous versions)
        if (isset($_POST['webcam_photo_data']) && !empty($_POST['webcam_photo_data'])) {
            $base64 = sanitize_text_field(wp_unslash($_POST['webcam_photo_data'])); $decoded = base64_decode($base64, true);
            if ($decoded) { $photo_binary_data_for_db = $decoded; error_log('FSBHOA UPDATE CH (HANDLER): Webcam photo processed.');}
            else { $errors['photo'] = __('Invalid webcam data.', 'fsbhoa-ac'); error_log('FSBHOA UPDATE CH (HANDLER): Invalid webcam data.');}
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] == UPLOAD_ERR_OK) {
            $file_info = wp_check_filetype_and_ext($_FILES['cardholder_photo']['tmp_name'], $_FILES['cardholder_photo']['name']);
            $allowed_mime_types = array('image/jpeg', 'image/png', 'image/gif');
            if (empty($file_info['type']) || !in_array($file_info['type'], $allowed_mime_types)) { $errors['cardholder_photo'] = __('Invalid file type.', 'fsbhoa-ac'); }
            elseif ($_FILES['cardholder_photo']['size'] > 2 * 1024 * 1024) { $errors['cardholder_photo'] = __('File too large.', 'fsbhoa-ac'); }
            else { $file_upload_content = file_get_contents($_FILES['cardholder_photo']['tmp_name']);
                if ($file_upload_content === false) { $errors['cardholder_photo'] = __('Could not read file.', 'fsbhoa-ac'); } 
                else { $photo_binary_data_for_db = $file_upload_content; error_log('FSBHOA UPDATE CH (HANDLER): File photo processed.');}
            }
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] != UPLOAD_ERR_NO_FILE) { 
            $errors['cardholder_photo'] = __('File upload error Code: ', 'fsbhoa-ac') . $_FILES['cardholder_photo']['error'];
            error_log('FSBHOA UPDATE CH (HANDLER): File upload error code: ' . $_FILES['cardholder_photo']['error']);
        }

        // Field Validations (ensure all are present)
        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other'); 
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Caregiver', 'Other');
        if (empty($form_data['first_name'])) { $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); }
        if (empty($form_data['last_name'])) { $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); }
        // ... (all other validations for length, email format, phone format, select types, property_id) ...
        if (!empty($form_data['email']) && !is_email($form_data['email'])) { $errors['email'] = __( 'Valid email required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['phone'])) { $phone_regex = '/^(?:1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}$/'; if (!preg_match($phone_regex, $form_data['phone'])) { $errors['phone'] = __( 'Valid phone format required.', 'fsbhoa-ac' );}}
        if (!in_array($form_data['phone_type'], $allowed_phone_types)) { $errors['phone_type'] = __('Invalid phone type.', 'fsbhoa-ac'); }
        elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) { $errors['phone_type'] = __('Select phone type.', 'fsbhoa-ac');}
        if (!in_array($form_data['resident_type'], $allowed_resident_types)) { $errors['resident_type'] = __('Invalid resident type.', 'fsbhoa-ac');}
        if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) { $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac'); }


        // Duplicate Check for Edit Mode
        if (empty($errors)) { 
            $sql_prepare_args = array($form_data['first_name'], $form_data['last_name'], $item_id_for_edit);
            $duplicate_sql = "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s AND id != %d";
            $existing_cardholder = $wpdb->get_row($wpdb->prepare($duplicate_sql, $sql_prepare_args));
            if ($existing_cardholder) { 
                $errors['duplicate'] = sprintf(__( 'Another cardholder named %1$s %2$s already exists (ID: %3$d).', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $existing_cardholder->id);
            }
        }
        error_log('FSBHOA UPDATE CH (HANDLER): Errors after validation: ' . print_r($errors, true));

        $edit_form_url = admin_url('admin.php?page=fsbhoa_ac_cardholders&action=edit_cardholder&cardholder_id=' . $item_id_for_edit);
        $list_page_url = admin_url('admin.php?page=fsbhoa_ac_cardholders');
        $redirect_url = $edit_form_url; // Default redirect to edit form if something goes wrong early

        if (empty($errors)) {
            $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';
            $data_to_update = array(
                'first_name'    => $form_data['first_name'], 'last_name'     => $form_data['last_name'],
                'email'         => $form_data['email'],      'phone'         => $phone_to_store,
                'phone_type'    => $form_data['phone_type'],'resident_type' => $form_data['resident_type'],
                'property_id'   => !empty($form_data['property_id']) ? $form_data['property_id'] : null,
            );
            if ($photo_binary_data_for_db !== null) { 
                $data_to_update['photo'] = $photo_binary_data_for_db; 
            } elseif (isset($_POST['remove_current_photo']) && $_POST['remove_current_photo'] === '1') { 
                $data_to_update['photo'] = null; 
            }
            
            $current_data_formats = array(); 
            foreach (array_keys($data_to_update) as $key) { 
                $current_data_formats[] = ($key === 'property_id' ? '%d' : '%s'); 
            }
            
            error_log('FSBHOA UPDATE CH (HANDLER): Data for DB update: ' . print_r($data_to_update, true));
            $result = $wpdb->update($cardholder_table_name, $data_to_update, array('id' => $item_id_for_edit), $current_data_formats, array('%d'));
            error_log('FSBHOA UPDATE CH (HANDLER): $wpdb->update result: ' . print_r($result, true));

            if ($result === false) { 
                $redirect_url = add_query_arg(array('message' => 'cardholder_update_dberror'), $edit_form_url); 
                error_log('FSBHOA UPDATE CH (HANDLER): DB update error. Redirecting to: ' . $redirect_url);
            } elseif ($result === 0) { 
                $redirect_url = add_query_arg(array('message' => 'cardholder_no_changes'), $edit_form_url); 
                error_log('FSBHOA UPDATE CH (HANDLER): No changes in DB. Redirecting to: ' . $redirect_url);
            } else { 
                $redirect_url = add_query_arg(array('message' => 'cardholder_updated_successfully', 'updated_id' => $item_id_for_edit), $list_page_url); 
                error_log('FSBHOA UPDATE CH (HANDLER): Update successful. Redirecting to: ' . $redirect_url);
            }
        } else { // Validation errors occurred
            $user_id = get_current_user_id();
            set_transient('fsbhoa_edit_ch_data_' . $item_id_for_edit . '_' . $user_id, $form_data, MINUTE_IN_SECONDS * 5);
            set_transient('fsbhoa_edit_ch_errors_' . $item_id_for_edit . '_' . $user_id, $errors, MINUTE_IN_SECONDS * 5);
            
            $redirect_url = add_query_arg(array('message' => 'cardholder_validation_error_edit'), $edit_form_url);
            error_log('FSBHOA UPDATE CH (HANDLER): Validation errors found. Redirecting to: ' . $redirect_url);
        }
        
        // Ensure headers are not already sent BEFORE this crucial redirect
        if (headers_sent($file, $line)) {
            error_log('FSBHOA UPDATE CH (HANDLER): CRITICAL - Headers ALREADY SENT before final redirect. Output started at ' . $file . ':' . $line);
            // If headers sent, redirect will fail. Can't do much here other than log.
            // The blank screen issue will persist if this happens.
        } else {
            wp_redirect(esc_url_raw($redirect_url));
            exit;
        }
        // If exit wasn't called due to headers_sent, something went wrong.
        error_log('FSBHOA UPDATE CH (HANDLER): Reached end of function without exit after redirect attempt - this should not happen if redirect was successful.');
    }
} // end class Fsbhoa_Cardholder_Actions
?>
