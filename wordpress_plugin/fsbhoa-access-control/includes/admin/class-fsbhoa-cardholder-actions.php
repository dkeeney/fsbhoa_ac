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
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
        add_action('admin_post_fsbhoa_do_add_cardholder', array($this, 'handle_add_or_update_cardholder'));
        add_action('admin_post_fsbhoa_do_update_cardholder', array($this, 'handle_add_or_update_cardholder'));
    }

    public function ajax_search_properties_callback() {
        // This is the correct and only place for this AJAX handler.
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');
        global $wpdb;
        $table_name = 'ac_property';
        $search_term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $results = array();
        if (strlen($search_term) >= 1) {
            $wildcard_search_term = '%' . $wpdb->esc_like($search_term) . '%';
            $properties = $wpdb->get_results( $wpdb->prepare( "SELECT property_id, street_address FROM {$table_name} WHERE street_address LIKE %s ORDER BY street_address ASC LIMIT 20", $wildcard_search_term ), ARRAY_A );

            // --- Error checking for the AJAX SELECT query ---
            if ( $wpdb->last_error ) {
                error_log('FSBHOA AJAX Property Search DB Error: ' . $wpdb->last_error);
                wp_send_json_error(array('message' => 'Database query failed.'));
                return; // Stop execution
            }

            if ($properties) {
                foreach ($properties as $property) {
                    $results[] = array( 'id' => $property['property_id'], 'label' => $property['street_address'], 'value' => $property['street_address'] );
                }
            }
        }
        wp_send_json_success($results);
    }

    public function handle_delete_cardholder_action() {
        if ( ! isset($_GET['cardholder_id']) || ! is_numeric($_GET['cardholder_id']) ) {
            wp_die( esc_html__( 'Invalid cardholder ID for deletion.', 'fsbhoa-ac' ), esc_html__( 'Error', 'fsbhoa-ac' ), array( 'response' => 400, 'back_link' => true ) );
        }

        $item_id_to_delete = absint( $_GET['cardholder_id'] );
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'fsbhoa_delete_cardholder_nonce_' . $item_id_to_delete ) ) {
            wp_die( esc_html__( 'Security check failed. Could not delete cardholder.', 'fsbhoa-ac' ), esc_html__( 'Error', 'fsbhoa-ac' ), array( 'response' => 403, 'back_link' => true ) );
        }


        // 1. Call the new global archive function.
        $result = fsbhoa_archive_and_delete_cardholder( $item_id_to_delete );

        // 2. Build the redirect URL back to the page the user came from.
        $redirect_url = wp_get_referer();
        if ( ! $redirect_url ) {
            // Provide a sensible fallback if the referer is not available for some reason.
            // You may need to adjust the page slug 'cardholder' if it's different.
            $redirect_url = get_permalink( get_page_by_path('cardholder') );
        }

        // Clean up the URL from any action parameters
        $redirect_url = remove_query_arg( array( 'action', 'cardholder_id', '_wpnonce' ), $redirect_url );

        // 3. Check the result and add the appropriate message to the URL.
        if ( is_wp_error( $result ) ) {
            // If the function returned an error, add an error notice.
            // The error message from the function is already HTML-safe.
            $error_string = $result->get_error_message();
            $redirect_url = add_query_arg( array( 'message' => 'cardholder_delete_error', 'error' => urlencode($error_string) ), $redirect_url );
        } else {
            // If it returned true, it was successful.
            $redirect_url = add_query_arg( array( 'message' => 'cardholder_deleted_successfully' ), $redirect_url );
        }


        wp_safe_redirect( $redirect_url );
        exit;
    }



    /**
     * Handles BOTH Add and Update submissions.
     */
    public function handle_add_or_update_cardholder() {
        global $wpdb;
        $table_name = 'ac_cardholders';
        $is_update = ( isset($_POST['action']) && $_POST['action'] === 'fsbhoa_do_update_cardholder' );

  // --- NEW DEBUG LOG #1 ---
        $photo_size = isset($_POST['photo_base64']) ? strlen($_POST['photo_base64']) : '0 (or not set)';
        error_log("--- ACTIONS: POST received. Size of photo_base64: " . $photo_size);

        $form_page_url = wp_get_referer() ? wp_get_referer() : home_url('/');
        $list_page_url = remove_query_arg( array('action', 'cardholder_id', 'message'), $form_page_url );

        $item_id = $is_update ? (isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0) : 0;

        $nonce_action = $is_update ? 'fsbhoa_update_cardholder_action_' . $item_id : 'fsbhoa_add_cardholder_action';
        check_admin_referer($nonce_action, '_wpnonce');

        // ---  fetching existing data ---
        $existing_data = array();
        if ($is_update) {
            $existing_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $item_id), ARRAY_A);
            if ($wpdb->last_error) {
                wp_die(esc_html__('Database error: Could not retrieve cardholder data for editing. Please go back and try again. Error: ') . esc_html($wpdb->last_error), 'Database Error', array('back_link' => true));
            }
            if ($existing_data === null) {
                 wp_die(esc_html__('Error: The cardholder you are trying to edit could not be found. It may have been deleted.'), 'Not Found', array('back_link' => true));
            }
        }


        // --- Include the view files which now also contain the validation functions ---
        $view_path = FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/';
        require_once $view_path . 'view-cardholder-profile-section.php';
        require_once $view_path . 'view-cardholder-address-section.php';
        require_once $view_path . 'view-cardholder-photo-section.php';
        require_once $view_path . 'view-cardholder-rfid-section.php';

        // --- Perform Validations by calling each component's validation function ---
        $profile_results = fsbhoa_validate_profile_data($_POST);
        $address_results = fsbhoa_validate_address_data($_POST);
        $photo_results   = fsbhoa_validate_photo_data($_POST, $_FILES);
        $rfid_results    = fsbhoa_validate_rfid_data($_POST, $existing_data, $item_id, $is_update);

        // GEMINI_PROTECTED_BLOCK: FSBHOA_DEBUG_OUTPUT   DO NOT REMOVE
        // ---  Full Debugging Block ---
        if ( defined('FSBHOA_DEBUG_MODE') && FSBHOA_DEBUG_MODE ) {
            error_log('--- FSBHOA Cardholder Submission Debug ---');
            error_log('ACTION: ' . ($is_update ? 'UPDATE' : 'ADD'));
            error_log('ITEM ID: ' . $item_id);
            error_log('RAW POST: ' . print_r(wp_unslash($_POST), true));
            error_log('--- Validation Results ---');
            error_log('PROFILE: ' . print_r($profile_results, true));
            error_log('ADDRESS: ' . print_r($address_results, true));
            error_log('RFID: ' . print_r($rfid_results, true));
            error_log('PHOTO: ' . print_r($photo_results, true));
        }
        // --- End Debugging Block ---

        // --- Combine results ---
        $errors = array_merge($profile_results['errors'], $address_results['errors'], $photo_results['errors'], $rfid_results['errors']);
        $data_to_save = array_merge($existing_data, $profile_results['data'], $address_results['data'], $photo_results['data'], $rfid_results['data']);

       if ( empty($errors) ) {
            if ( $is_update ) {
                $result = $wpdb->update( $table_name, $data_to_save, array('id' => $item_id) );
                if ($result === false) {
                    if (strpos($wpdb->last_error, 'Duplicate entry') !== false && strpos($wpdb->last_error, 'idx_rfid_id_unique') !== false) {
                        $errors['db_error'] = 'Database Error: That RFID ID is already in use by another cardholder.';
                    } else {
                        $errors['db_error'] = 'A database error occurred while updating. Please try again.';
                    }
                    error_log('FSBHOA DB Update Error: ' . $wpdb->last_error);
                }
            } else {
                $result = $wpdb->insert( $table_name, $data_to_save );
                if ($result === false) {
                    $errors['db_error'] = 'A database error occurred while adding the new cardholder. Please try again.';
                    error_log('FSBHOA DB Insert Error: ' . $wpdb->last_error);
                }
            }
        }
        
        if ( ! empty($errors) ) {
            // If errors, save data to transient and redirect back to the form
            $user_id = get_current_user_id();
            $transient_key = 'fsbhoa_form_feedback_' . ($is_update ? 'edit_' . $item_id . '_' : 'add_') . $user_id;
            // We store the raw POST data so "sticky" fields work as the user expects
            set_transient($transient_key, array('errors' => $errors, 'data' =>  wp_unslash($_POST)), MINUTE_IN_SECONDS * 5);
            wp_redirect( add_query_arg( array('message' => 'validation_error'), $form_page_url ) );
            exit;
        }
       
        // If we reach here, the operation was successful.
        $message_code = $is_update ? 'cardholder_updated' : 'cardholder_added';
        wp_redirect( add_query_arg( array('message' => $message_code), $list_page_url ) );
        exit;
    }

}
