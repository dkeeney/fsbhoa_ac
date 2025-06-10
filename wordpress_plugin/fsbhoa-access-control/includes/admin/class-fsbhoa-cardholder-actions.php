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

    public function __construct() {
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'fsbhoa_ajax_search_properties_callback'));
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
        add_action('admin_post_fsbhoa_do_add_cardholder', array($this, 'handle_add_or_update_cardholder'));
        add_action('admin_post_fsbhoa_do_update_cardholder', array($this, 'handle_add_or_update_cardholder'));
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
     * Handles BOTH Add and Update submissions.
     */
    public function handle_add_or_update_cardholder() {
        $debug = true;

        global $wpdb;
        $table_name = 'ac_cardholders';
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_do_update_cardholder' );
        
        $form_page_url = wp_get_referer() ? wp_get_referer() : home_url('/');
        $list_page_url = remove_query_arg( array('action', 'cardholder_id', 'message'), $form_page_url );
        
        $item_id = $is_update ? (isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0) : 0;

        $nonce_action = $is_update ? 'fsbhoa_update_cardholder_action_' . $item_id : 'fsbhoa_add_cardholder_action';
        check_admin_referer($nonce_action, '_wpnonce');


        
        // --- Sanitize all incoming data ---
        require_once plugin_dir_path( __DIR__ ) . 'admin/views/view-cardholder-profile-section.php';
        require_once plugin_dir_path( __DIR__ ) . 'admin/views/view-cardholder-address-section.php';
        require_once plugin_dir_path( __DIR__ ) . 'admin/views/view-cardholder-photo-section.php';
        require_once plugin_dir_path( __DIR__ ) . 'admin/views/view-cardholder-rfid-section.php';
        
        // --- Perform Validations by calling each component's validation function ---
        $existing_data = $is_update ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $item_id), ARRAY_A) : array();

        $profile_results = fsbhoa_validate_profile_data($_POST);
        $address_results = fsbhoa_validate_address_data($_POST);
        $photo_results   = fsbhoa_validate_photo_data($_POST, $_FILES);
        $rfid_results    = fsbhoa_validate_rfid_data($_POST, $existing_data, $item_id, $is_update);
        $profile_results = fsbhoa_validate_profile_data($_POST);
            
            // If debug mode,  dump each section's  output
        if ( defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE ) {
            error_log('--- FSBHOA Form Submission Debug ---');
            error_log('Profile Results: ' . print_r($profile_results, true));
            error_log('Address Results: ' . print_r($address_results, true));
            error_log('Photo Results: ' . print_r($photo_results, true));
            error_log('RFID Results: ' . print_r($rfid_results, true));
        } 

        $errors = array_merge($profile_results['errors'], $address_results['errors'], $photo_results['errors'], $rfid_results['errors']);
        
        // Combine sanitized data from all validation functions
        $form_data = array_merge($profile_results['data'], $address_results['data'], $rfid_results['data']);

        if ( ! empty($errors) ) {
            // If errors, save data to transient and redirect back to the form
            $user_id = get_current_user_id();
            $transient_key = 'fsbhoa_form_feedback_' . ($is_update ? 'edit_' . $item_id . '_' : 'add_') . $user_id;
            set_transient($transient_key, array('errors' => $errors, 'data' =>  wp_unslash($_POST)), MINUTE_IN_SECONDS * 5);
            wp_redirect( add_query_arg( array('message' => 'validation_error'), $form_page_url ) );
            exit;
        }

        // If validation passes, combine all sanitized data and save to DB
        $data_to_save = array_merge($profile_results['data'], $address_results['data'], $photo_results['data'], $rfid_results['data']);
        
        if ( $is_update ) {
            $wpdb->update( $table_name, $data_to_save, array('id' => $item_id) );
            $message_code = 'cardholder_updated';
        } else {
            $wpdb->insert( $table_name, $data_to_save );
            $message_code = 'cardholder_added';
        }

        wp_redirect( add_query_arg( array('message' => $message_code), $list_page_url ) );
        exit;
    }


} // end class Fsbhoa_Cardholder_Actions
?>
