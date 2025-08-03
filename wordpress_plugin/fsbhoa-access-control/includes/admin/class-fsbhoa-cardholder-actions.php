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
        add_action('admin_post_fsbhoa_export_selected', array($this, 'handle_export_selected'));        
        add_action('admin_post_fsbhoa_print_report', array($this, 'handle_print_report'));
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
            error_log('--- FSBHOA Cardholder Submission ---');
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
                else {
                    $item_id = $wpdb->insert_id;
                }
            }
            if (empty($errors)) {
                $submitted_groups = isset($_POST['cardholder_groups']) ? (array) $_POST['cardholder_groups'] : [];
                $this->save_cardholder_groups($item_id, $submitted_groups);
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

        // Check if our 'print' flag was sent from the JavaScript
        if ( isset($_POST['fsbhoa_after_save_action']) && $_POST['fsbhoa_after_save_action'] === 'print' ) {
            
            // 1. Get the page that contains the print shortcode
            $print_page_slug = 'print-photo-id'; // <-- Correct slug
            $print_page = get_page_by_path($print_page_slug);

            if ($print_page) {
                $page_url = get_permalink($print_page->ID);
            
                // 2. Add the cardholder ID to the page's URL
                $print_url = add_query_arg('cardholder_id', $item_id, $page_url);
            
                wp_redirect($print_url);
            } else {
                // Fallback if the page with that slug isn't found
                $list_page_url = admin_url('admin.php?page=fsbhoa_cardholder');
                wp_redirect( $list_page_url );
            }

        } else {
            wp_redirect( add_query_arg( array('message' => $message_code), $list_page_url ) );
        }
        exit;
    }


    /**
     * Saves the group memberships for a cardholder.
     *
     * @param int   $cardholder_id The ID of the cardholder being updated.
     * @param array $group_ids     An array of group IDs selected on the form.
     */
    private function save_cardholder_groups($cardholder_id, $group_ids) {
        global $wpdb;

        // Sanitize the incoming group IDs to ensure they are all integers.
        $group_ids = array_map('absint', $group_ids);

        // First, delete all existing non-default group memberships for this cardholder.
        $wpdb->delete('ac_cardholder_groups', ['cardholder_id' => $cardholder_id]);
        if ($wpdb->last_error) {
            // We don't stop execution, but we can log this error.
            error_log('FSBHOA DB Error: Failed to delete old groups for cardholder ' . $cardholder_id . ': ' . $wpdb->last_error);
            return; // Abort if we can't clear old groups.
        }

        // If any groups were selected, loop through and insert them.
        if (!empty($group_ids)) {
            foreach ($group_ids as $group_id) {
                $wpdb->insert(
                    'ac_cardholder_groups',
                    [
                        'cardholder_id' => $cardholder_id,
                        'group_id'      => $group_id,
                    ],
                    [
                        '%d', // format for cardholder_id
                        '%d', // format for group_id
                    ]
                );
                if ($wpdb->last_error) {
                    error_log('FSBHOA DB Error: Failed to insert group ' . $group_id . ' for cardholder ' . $cardholder_id . ': ' . $wpdb->last_error);
                    // Continue trying to insert the others.
                }
            }
        }
    }

/**
     * Handles the CSV export for selected cardholders.
     */
    public function handle_export_selected() {
        if ( empty( $_POST['cardholder'] ) ) {
            return;
        }

        $nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
        if ( ! wp_verify_nonce($nonce, 'fsbhoa_export_nonce') ) {
            wp_die( 'Security check failed. Please go back and try again.' );
        }

        $cardholder_ids = array_map('absint', $_POST['cardholder']);
        if ( empty( $cardholder_ids ) ) {
            return;
        }

        global $wpdb;
        $cardholders_table = 'ac_cardholders';
        $properties_table = 'ac_property';
        $groups_table = 'ac_groups';
        $memberships_table = 'ac_cardholder_groups';
        
        $default_group_names = $wpdb->get_col("SELECT group_name FROM {$groups_table} WHERE is_default = 1");

        $ids_string = implode( ',', $cardholder_ids );
        $sql = "
            SELECT c.*, p.street_address 
            FROM {$cardholders_table} c
            LEFT JOIN {$properties_table} p ON c.property_id = p.property_id
            WHERE c.id IN ({$ids_string})
        ";

        //error_log("EXPORT SQL DEBUG: The generated SQL is: " . $sql);

        $items_to_export = $wpdb->get_results( $sql, ARRAY_A );

        //error_log("EXPORT DEBUG 1: Initial records fetched from DB: " . count($items_to_export));
        
        if ($wpdb->last_error) {
            wp_die('Database error while fetching cardholders for export: ' . esc_html($wpdb->last_error));
        }
        if ( empty( $items_to_export ) ) {
            return;
        }
        
        foreach ($items_to_export as $key => $item) {
            $groups_query = $wpdb->prepare("
                SELECT g.group_name
                FROM {$groups_table} g
                JOIN {$memberships_table} cg ON g.group_id = cg.group_id
                WHERE cg.cardholder_id = %d
            ", $item['id']);
            
            $explicit_group_names = $wpdb->get_col($groups_query);
            $all_group_names = array_merge($default_group_names, $explicit_group_names);
            $items_to_export[$key]['groups'] = implode(', ', array_unique($all_group_names));
        }

        $filename = 'cardholder-export-' . date('Y-m-d') . '.csv';

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );
        
        $headers = array_keys( $items_to_export[0] );
        $photo_key = array_search('photo', $headers);
        if ($photo_key !== false) {
            unset($headers[$photo_key]);
        }
        fputcsv( $output, $headers );

        foreach ( $items_to_export as $item ) {
            if ( isset( $item['photo'] ) ) {
                unset( $item['photo'] );
            }
            fputcsv( $output, $item );
        }

        fclose( $output );
        exit();
    }


    /**
     * Handles the submission for the "Print Report" bulk action.
     * It saves the selected cardholder IDs to a transient and redirects to the print page.
     */
    public function handle_print_report() {
        // 1. Verify the security nonce.
        check_admin_referer('fsbhoa_print_report_nonce');

        // 2. Get and sanitize the array of cardholder IDs.
        if (empty($_POST['cardholder_ids']) || !is_array($_POST['cardholder_ids'])) {
            wp_die('Error: No cardholders were selected. Please go back and select at least one.');
        }
        $cardholder_ids = array_map('absint', $_POST['cardholder_ids']);

        // 3. Get and sanitize the sort order variables.
        $orderby_col = isset($_POST['orderby_col']) ? absint($_POST['orderby_col']) : 2; // Default to column 2 (Name)
        $order_dir = isset($_POST['order_dir']) && in_array(strtolower($_POST['order_dir']), ['asc', 'desc']) ? strtolower($_POST['order_dir']) : 'asc';

        // 4. Get the URL of our "Cardholder Pages" page.
        $print_page_url = get_permalink(get_page_by_path('cardholder-pages'));
        if (!$print_page_url) {
            wp_die('Configuration Error: The "Cardholder Pages" page has not been created in WordPress.');
        }

        // 5. Add all parameters to the URL.
        $url_with_params = add_query_arg(array(
            'selected_ids' => implode(',', $cardholder_ids),
            'orderby_col'  => $orderby_col,
            'order_dir'    => $order_dir
        ), $print_page_url);

        // 6. Redirect the user's browser to the new URL.
        wp_safe_redirect($url_with_params);
        exit;
    }
}
