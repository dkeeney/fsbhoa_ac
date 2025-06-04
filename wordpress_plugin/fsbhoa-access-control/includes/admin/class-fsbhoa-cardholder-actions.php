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

if (!defined('FSBHOA_WAY_OUT_EXPIRY_DATE')) {
    define('FSBHOA_WAY_OUT_EXPIRY_DATE', '2099-12-31'); // Far future date
}

class Fsbhoa_Cardholder_Actions {

    public function __construct() {
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
        add_action('admin_post_fsbhoa_do_add_cardholder', array($this, 'handle_add_cardholder_submission'));
        add_action('admin_post_fsbhoa_do_update_cardholder', array($this, 'handle_update_cardholder_submission'));
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

    public function handle_delete_cardholder_action() {
        if (!isset($_GET['cardholder_id']) || !is_numeric($_GET['cardholder_id'])) { wp_die(esc_html__('Invalid cardholder ID for deletion.','fsbhoa-ac'), esc_html__('Error','fsbhoa-ac'), array('response'=>400,'back_link'=>true)); }
        $item_id_to_delete = absint($_GET['cardholder_id']);
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'fsbhoa_delete_cardholder_nonce_' . $item_id_to_delete)) { wp_die(esc_html__('Security check failed. Could not delete cardholder.','fsbhoa-ac'), esc_html__('Error','fsbhoa-ac'), array('response'=>403,'back_link'=>true)); }
        
        global $wpdb; $table_name = 'ac_cardholders';
        $result = $wpdb->delete($table_name, array('id' => $item_id_to_delete), array('%d'));
        
        $base_redirect_url = admin_url('admin.php'); 
        $redirect_url = add_query_arg(array('page' => 'fsbhoa_ac_cardholders'), $base_redirect_url);

        if ($result === false) { $redirect_url = add_query_arg(array('message' => 'cardholder_delete_error'), $redirect_url); }
        elseif ($result === 0) { $redirect_url = add_query_arg(array('message' => 'cardholder_delete_not_found'), $redirect_url); }
        else { $redirect_url = add_query_arg(array('message' => 'cardholder_deleted_successfully', 'deleted_id' => $item_id_to_delete), $redirect_url); }
        
        wp_redirect(esc_url_raw($redirect_url)); exit;
    }

/**
     * Handles submission for adding a new cardholder.
     * @since 0.1.13 (Corrected to allow DB defaults for date fields on new add)
     */
    public function handle_add_cardholder_submission() {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';

        if (!isset($_POST['fsbhoa_add_cardholder_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {
            wp_die(esc_html__('Security check failed (add cardholder).', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }

        $form_data = array(); $errors = array(); $photo_binary_data_for_db = null;
        
        // Sanitize and collect form data from $_POST
        $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $form_data['email']         = isset($_POST['email']) ? trim(sanitize_text_field(wp_unslash($_POST['email']))) : '';
        $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
        $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
        $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';
        $form_data['notes']         = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        // rfid_id, card_expiry_date_input are NOT submitted from the current "Add New Cardholder" form

        // Photo handling (ensure this logic is complete from previous versions)
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
        
        // Field Validations (as before)
        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other'); 
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Caregiver', 'Other');
        if (empty($form_data['first_name'])) { $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); }
        if (empty($form_data['last_name'])) { $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['email']) && !is_email($form_data['email'])) { $errors['email'] = __( 'Valid email required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['phone'])) { 
            $phone_digits_val = preg_replace('/[^0-9]/', '', $form_data['phone']);
            if (!preg_match('/^[\d\s\(\)\-\.]+$/', $form_data['phone'])) { $errors['phone'] = __( 'Phone number contains invalid characters.', 'fsbhoa-ac' );}
            elseif (strlen($phone_digits_val) !== 10) { $errors['phone'] = __( 'Phone number must resolve to exactly 10 digits.', 'fsbhoa-ac' );}
            if (strlen($form_data['phone']) > 30) { $errors['phone'] = isset($errors['phone']) ? $errors['phone'] . ' ' . __( 'Entry too long.', 'fsbhoa-ac') : __( 'Phone entry too long.', 'fsbhoa-ac');}
        }
        if (!in_array($form_data['phone_type'], $allowed_phone_types)) { $errors['phone_type'] = __('Invalid phone type.', 'fsbhoa-ac'); }
        elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) { $errors['phone_type'] = __('Select phone type.', 'fsbhoa-ac');}
        if (!in_array($form_data['resident_type'], $allowed_resident_types)) { $errors['resident_type'] = __('Invalid resident type.', 'fsbhoa-ac');}
        if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) { $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac'); }
        // No RFID validation here as it's not submitted from the Add form.
        
        if (empty($errors)) { 
            $existing_cardholder = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s", $form_data['first_name'], $form_data['last_name'] ) );
            if ($existing_cardholder) { $errors['duplicate_name'] = sprintf(__( 'A cardholder named %1$s %2$s already exists (ID: %3$d).', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $existing_cardholder->id); }
        }

        $add_form_url = admin_url('admin.php?page=fsbhoa_ac_cardholders&action=add');
        $list_page_url = admin_url('admin.php?page=fsbhoa_ac_cardholders');
        $redirect_url = '';

        if (empty($errors)) {
            $email_to_save = sanitize_email($form_data['email']);
            $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';
            
            $data_to_insert = array(
                'first_name'    => $form_data['first_name'], 
                'last_name'     => $form_data['last_name'],
                'email'         => $email_to_save,      
                'phone'         => $phone_to_store,
                'phone_type'    => $form_data['phone_type'],
                'resident_type' => $form_data['resident_type'],
                'property_id'   => !empty($form_data['property_id']) ? $form_data['property_id'] : null,
                'notes'         => $form_data['notes'], 
                'photo'         => $photo_binary_data_for_db,
                'card_status'   => 'inactive', 
                // We OMIT rfid_id, card_issue_date, and card_expiry_date.
                // This allows their database defaults (NULL for rfid_id and issue_date, 
                // and your '2099-12-31' for expiry_date) to be applied.
            );
            
            // Dynamically build formats based on keys ACTUALLY in $data_to_insert
            $current_data_formats = array(); 
            foreach (array_keys($data_to_insert) as $key) { 
                $current_data_formats[] = ($key === 'property_id' ? '%d' : '%s'); 
            }
            
            $result = $wpdb->insert($cardholder_table_name, $data_to_insert, $current_data_formats);

            if ($result) { 
                $redirect_url = add_query_arg(array('message' => 'cardholder_added_successfully', 'added_id' => $wpdb->insert_id), $list_page_url);
            } else { 
                // Log the actual DB error if insert fails
                error_log("FSBHOA Add Cardholder DB Insert Error: " . $wpdb->last_error);
                $redirect_url = add_query_arg(array('message' => 'cardholder_add_dberror'), $add_form_url);
            }
        } else { // Validation errors
            $user_id = get_current_user_id();
            set_transient('fsbhoa_add_ch_form_data_' . $user_id, $form_data, MINUTE_IN_SECONDS * 5);
            set_transient('fsbhoa_add_ch_errors_' . $user_id, $errors, MINUTE_IN_SECONDS * 5);
            $redirect_url = add_query_arg(array('message' => 'cardholder_validation_error'), $add_form_url);
        }
        wp_redirect(esc_url_raw($redirect_url)); 
        exit;
    }

    /**
     * Handles submission for updating an existing cardholder.
     * @since 0.1.12
     */
    public function handle_update_cardholder_submission() {
error_log('FSBHOA UPDATE CH (HANDLER): Submitted raw POST card_expiry_date: "' . (isset($_POST['card_expiry_date']) ? $_POST['card_expiry_date'] : 'NOT SET') . '"');

        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';
        $item_id_for_edit = isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0;

        if ($item_id_for_edit <= 0) { wp_die(esc_html__('Invalid cardholder ID for update.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 400, 'back_link' => true));}
        if (!isset($_POST['fsbhoa_update_cardholder_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_update_cardholder_nonce'])), 'fsbhoa_update_cardholder_action_' . $item_id_for_edit)) { wp_die(esc_html__('Security check failed (update cardholder).', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));}

        $form_data = array(); $errors = array(); $photo_binary_data_for_db = null;
        
        $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
        $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
        $form_data['email']         = isset($_POST['email']) ? trim(sanitize_text_field(wp_unslash($_POST['email']))) : '';
        $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
        $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
        $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
        $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';
        $form_data['rfid_id']       = isset($_POST['rfid_id']) ? sanitize_text_field(wp_unslash(trim($_POST['rfid_id']))) : '';
        $form_data['notes']         = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
        $form_data['card_expiry_date_input'] = isset($_POST['card_expiry_date']) ? sanitize_text_field(wp_unslash($_POST['card_expiry_date'])) : '';
    $js_submitted_status = isset($_POST['submitted_card_status']) ? sanitize_text_field(wp_unslash($_POST['submitted_card_status'])) : null;
    $js_submitted_issue_date = isset($_POST['submitted_card_issue_date']) ? sanitize_text_field(wp_unslash($_POST['submitted_card_issue_date'])) : null;


        // Photo handling
        if (isset($_POST['webcam_photo_data']) && !empty($_POST['webcam_photo_data'])) {
            $base64 = sanitize_text_field(wp_unslash($_POST['webcam_photo_data'])); $decoded = base64_decode($base64, true);
            if ($decoded) $photo_binary_data_for_db = $decoded; else $errors['cardholder_photo'] = __('Invalid webcam data.', 'fsbhoa-ac');
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] == UPLOAD_ERR_OK) {
            $file_info = wp_check_filetype_and_ext($_FILES['cardholder_photo']['tmp_name'], $_FILES['cardholder_photo']['name']);
            $allowed_mime_types = array('image/jpeg', 'image/png', 'image/gif');
            if (empty($file_info['type']) || !in_array($file_info['type'], $allowed_mime_types)) { $errors['cardholder_photo'] = __('Invalid file type.', 'fsbhoa-ac'); }
            elseif ($_FILES['cardholder_photo']['size'] > 2 * 1024 * 1024) { $errors['cardholder_photo'] = __('File too large.', 'fsbhoa-ac'); }
            else { $file_upload_content = file_get_contents($_FILES['cardholder_photo']['tmp_name']);
                if ($file_upload_content === false) { $errors['cardholder_photo'] = __('Could not read file.', 'fsbhoa-ac'); } 
                else { $photo_binary_data_for_db = $file_upload_content; }
            }
        } elseif (isset($_FILES['cardholder_photo']) && $_FILES['cardholder_photo']['error'] != UPLOAD_ERR_NO_FILE) { $errors['cardholder_photo'] = __('File upload error Code: ', 'fsbhoa-ac') . $_FILES['cardholder_photo']['error'];}

        // Field Validations
        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other'); 
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Caregiver', 'Other');
        if (empty($form_data['first_name'])) { $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); }
        if (empty($form_data['last_name'])) { $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['email']) && !is_email($form_data['email'])) { $errors['email'] = __( 'Valid email required.', 'fsbhoa-ac' ); }
        if (!empty($form_data['phone'])) { 
            $phone_digits_val = preg_replace('/[^0-9]/', '', $form_data['phone']);
            if (!preg_match('/^[\d\s\(\)\-\.]+$/', $form_data['phone'])) { $errors['phone'] = __( 'Phone number contains invalid characters.', 'fsbhoa-ac' );}
            elseif (strlen($phone_digits_val) !== 10) { $errors['phone'] = __( 'Phone number must resolve to exactly 10 digits.', 'fsbhoa-ac' );}
            if (strlen($form_data['phone']) > 30) { $errors['phone'] = isset($errors['phone']) ? $errors['phone'] . ' ' . __( 'Entry too long.', 'fsbhoa-ac') : __( 'Phone entry too long.', 'fsbhoa-ac');}
        }
        if (!in_array($form_data['phone_type'], $allowed_phone_types)) { $errors['phone_type'] = __('Invalid phone type.', 'fsbhoa-ac'); }
        elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) { $errors['phone_type'] = __('Select phone type.', 'fsbhoa-ac');}
        if (!in_array($form_data['resident_type'], $allowed_resident_types)) { $errors['resident_type'] = __('Invalid resident type.', 'fsbhoa-ac');}
        if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) { $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac'); }

        $existing_cardholder_data = $wpdb->get_row($wpdb->prepare("SELECT rfid_id, card_status, card_issue_date, card_expiry_date, resident_type FROM {$cardholder_table_name} WHERE id = %d", $item_id_for_edit), ARRAY_A);
        if (!$existing_cardholder_data) { $errors['load_cardholder'] = __('Could not load existing cardholder data for update processing.', 'fsbhoa-ac'); }


        if (empty($errors) && !empty($form_data['rfid_id'])) { // Only validate RFID if other fields are okay and RFID is not empty
            if (!preg_match('/^[a-zA-Z0-9]{8}$/', $form_data['rfid_id'])) {
                $errors['rfid_id'] = __('RFID ID must be 8 alphanumeric characters.', 'fsbhoa-ac');
            } elseif ($form_data['rfid_id'] !== $existing_cardholder_data['rfid_id']) { 
                $found_rfid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$cardholder_table_name} WHERE rfid_id = %s AND id != %d", $form_data['rfid_id'], $item_id_for_edit));
                if ($found_rfid) { $errors['rfid_id'] = __('This RFID ID is already assigned to another cardholder.', 'fsbhoa-ac');}
            }
        }
        if (empty($errors) && $form_data['resident_type'] === 'Contractor' && (!empty($form_data['rfid_id']) || !empty($existing_cardholder_data['rfid_id']) ) ) {

error_log('FSBHOA UPDATE CH (HANDLER): Contractor Expiry Date Input for validation: "' . $form_data['card_expiry_date_input'] . '"');

            if (empty($form_data['card_expiry_date_input'])) { $errors['card_expiry_date'] = __('Expiry date is required for Contractors with an RFID.', 'fsbhoa-ac'); } 
            else { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_data['card_expiry_date_input'])) { $errors['card_expiry_date'] = __('Invalid expiry date format (YYYY-MM-DD).', 'fsbhoa-ac'); }
                   else { 
                       $issue_date_for_check = $existing_cardholder_data['card_issue_date'] ? $existing_cardholder_data['card_issue_date'] : current_time('Y-m-d');
                       if ($form_data['rfid_id'] !== $existing_cardholder_data['rfid_id'] && !empty($form_data['rfid_id'])) $issue_date_for_check = current_time('Y-m-d'); // If RFID is new/changed
                       if (strtotime($form_data['card_expiry_date_input']) <= strtotime($issue_date_for_check)) { $errors['card_expiry_date'] = __('Expiry date must be after the issue date.', 'fsbhoa-ac'); }
                   }
            }
        }
        
        if (empty($errors)) { 
            $sql_prepare_args = array($form_data['first_name'], $form_data['last_name'], $item_id_for_edit);
            $duplicate_sql = "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s AND id != %d";
            $existing_cardholder_name = $wpdb->get_row($wpdb->prepare($duplicate_sql, $sql_prepare_args));
            if ($existing_cardholder_name) { $errors['duplicate_name'] = sprintf(__( 'Another cardholder with this name exists (ID: %3$d).', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $existing_cardholder_name->id); }
        }

        $edit_form_url = admin_url('admin.php?page=fsbhoa_ac_cardholders&action=edit_cardholder&cardholder_id=' . $item_id_for_edit);
        $list_page_url = admin_url('admin.php?page=fsbhoa_ac_cardholders');
        $redirect_url = '';

        if (empty($errors)) {
            $email_to_save = sanitize_email($form_data['email']);
            $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';
            
            $data_to_update = array(
                'first_name'    => $form_data['first_name'], 'last_name'     => $form_data['last_name'],
                'email'         => $email_to_save,      'phone'         => $phone_to_store,
                'phone_type'    => $form_data['phone_type'],'resident_type' => $form_data['resident_type'],
                'property_id'   => !empty($form_data['property_id']) ? $form_data['property_id'] : null,
                'notes'         => $form_data['notes'],
            );
            $submitted_rfid = $form_data['rfid_id'];
            $rfid_field_was_in_post = array_key_exists('rfid_id', $_POST);

            $final_rfid = $existing_cardholder_data['rfid_id'];
            $final_card_status = $existing_cardholder_data['card_status'];
            $final_issue_date = $existing_cardholder_data['card_issue_date'];
            $final_expiry_date = $existing_cardholder_data['card_expiry_date'];

            $rfid_is_newly_assigned_or_changed = false;

            if ($rfid_field_was_in_post) {
                if (!empty($submitted_rfid) && $submitted_rfid !== $existing_cardholder_data['rfid_id']) {
                    $final_rfid = $submitted_rfid;
                    $final_issue_date = current_time('Y-m-d'); // RFID assignment sets issue date
                    $final_card_status = 'active';
                    $rfid_is_newly_assigned_or_changed = true;
                } elseif (empty($submitted_rfid) && !empty($existing_cardholder_data['rfid_id'])) {
                    $final_rfid = null; $final_card_status = 'inactive';
                    $final_issue_date = null; $final_expiry_date = null;
                    $rfid_is_newly_assigned_or_changed = true;
                }
            }

            // If RFID didn't change status, consider status from JS-driven hidden field
            if (!$rfid_is_newly_assigned_or_changed && $js_submitted_status !== null) {
                if ($js_submitted_status === 'active' && $final_card_status !== 'active') { // JS wants to activate
                    $final_card_status = 'active';
                    // Use issue date from JS if it was set (JS sets it to today on activation)
                    if (!empty($js_submitted_issue_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $js_submitted_issue_date)) {
                        $final_issue_date = $js_submitted_issue_date;
                    } else { // Fallback if JS didn't send a valid date (should not happen)
                        $final_issue_date = current_time('Y-m-d');
                    }
                    $rfid_is_newly_assigned_or_changed = true; // Treat as "dates need recalculation for expiry"
                } elseif ($js_submitted_status === 'disabled' && $final_card_status === 'active') { // JS wants to disable
                    $final_card_status = 'disabled';
                    // When disabling, issue and expiry dates remain unchanged from existing.
                    $final_issue_date = $existing_cardholder_data['card_issue_date'];
                    $final_expiry_date = $existing_cardholder_data['card_expiry_date'];
                }
                // If JS submitted status is same as current (after RFID logic), no change from this block.
            }

            // Set/Recalculate expiry date if card is now active (due to RFID or JS toggle)
            if ($final_card_status === 'active') {
                if ($form_data['resident_type'] === 'Contractor') {
                    if (!empty($form_data['card_expiry_date_input']) &&
                        preg_match('/^\d{4}-\d{2}-\d{2}$/', $form_data['card_expiry_date_input']) &&
                        $form_data['card_expiry_date_input'] !== FSBHOA_WAY_OUT_EXPIRY_DATE &&
                        strtotime($form_data['card_expiry_date_input']) > strtotime($final_issue_date)) {
                        $final_expiry_date = $form_data['card_expiry_date_input'];
                    } else {
                        $final_expiry_date = FSBHOA_WAY_OUT_EXPIRY_DATE;
                    }
                } else { // Not a contractor
                    $final_expiry_date = FSBHOA_WAY_OUT_EXPIRY_DATE;
                }
            } elseif ($final_card_status === 'inactive') {
                $final_expiry_date = null;
            }
            // If status is 'disabled', expiry date remains as is ($final_expiry_date already holds existing).

            $data_to_update['rfid_id'] = $final_rfid;
            $data_to_update['card_status'] = $final_card_status;
            $data_to_update['card_issue_date'] = ($final_card_status !== 'inactive') ? $final_issue_date : null;
            $data_to_update['card_expiry_date'] = ($final_card_status !== 'inactive') ? $final_expiry_date : null;




            if ($photo_binary_data_for_db !== null) { $data_to_update['photo'] = $photo_binary_data_for_db; } 
            elseif (isset($_POST['remove_current_photo']) && $_POST['remove_current_photo'] === '1') { $data_to_update['photo'] = null; }
            
            $current_data_formats = array(); 
            $update_needed = false;
            // Only include fields in $data_to_update if they are actually changing from existing DB values
            // Or if they are new (like photo)
            // This is complex. For now, let's just update all collected fields if $data_to_update is not empty.
            // $wpdb->update returns number of rows affected. If it's 0, it means data was same.

            if (empty($data_to_update)) { $result = 0; } 
            else { 
                // We need to ensure we don't try to update 'id' or other non-column keys if they sneaked into $data_to_update
                // The keys used for $data_to_update are explicitly set, so this should be fine.
                foreach (array_keys($data_to_update) as $key) { $current_data_formats[] = ($key === 'property_id' || $key === 'id' ? '%d' : '%s'); }
                $result = $wpdb->update($cardholder_table_name, $data_to_update, array('id' => $item_id_for_edit), $current_data_formats, array('%d'));
            }

            if ($result === false) { $redirect_url = add_query_arg(array('message' => 'cardholder_update_dberror'), $edit_form_url); }
            elseif ($result === 0 && !(isset($data_to_update['photo']) && $photo_binary_data_for_db === null && isset($_POST['remove_current_photo']) && $_POST['remove_current_photo'] === '1') ) { 
                 $redirect_url = add_query_arg(array('message' => 'cardholder_no_changes'), $edit_form_url); 
            } else { $redirect_url = add_query_arg(array('message' => 'cardholder_updated_successfully', 'updated_id' => $item_id_for_edit), $list_page_url); }
        } else { // Validation errors
            $user_id = get_current_user_id();
            set_transient('fsbhoa_edit_ch_data_' . $item_id_for_edit . '_' . $user_id, $form_data, MINUTE_IN_SECONDS * 5);
            set_transient('fsbhoa_edit_ch_errors_' . $item_id_for_edit . '_' . $user_id, $errors, MINUTE_IN_SECONDS * 5);
            $redirect_url = add_query_arg(array('message' => 'cardholder_validation_error_edit'), $edit_form_url);
        }
        wp_redirect(esc_url_raw($redirect_url));
        exit;
    }
} // end class Fsbhoa_Cardholder_Actions
?>
