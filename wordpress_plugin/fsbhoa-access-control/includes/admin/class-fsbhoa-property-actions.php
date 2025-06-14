<?php
/**
 * Handles all admin-post actions for Property management.
 */
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Property_Actions {

    public function __construct() {
        add_action('admin_post_fsbhoa_delete_property', array($this, 'handle_delete_property_action'));
        add_action('admin_post_fsbhoa_add_property', array($this, 'handle_add_or_update_property'));
        add_action('admin_post_fsbhoa_update_property', array($this, 'handle_add_or_update_property'));
    }

    public function handle_delete_property_action() {
        if (!isset($_GET['property_id']) || !is_numeric($_GET['property_id'])) {
            wp_die('Invalid property ID for deletion.', 'Error', array('response' => 400, 'back_link' => true));
        }
        $item_id_to_delete = absint($_GET['property_id']);

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'fsbhoa_delete_property_nonce_' . $item_id_to_delete)) {
            wp_die('Security check failed. Could not delete property.', 'Error', array('response' => 403, 'back_link' => true));
        }

        global $wpdb;
        $table_name = 'ac_property';
        $result = $wpdb->delete($table_name, array('property_id' => $item_id_to_delete), array('%d'));

        // Get the URL of the page that submitted the request
        $redirect_url = wp_get_referer() ? wp_get_referer() : home_url('/');
        // Make sure the 'view' parameter is correctly set for the properties list
        $redirect_url = add_query_arg('view', 'properties', remove_query_arg(array('action', 'property_id'), $redirect_url));

        if ($result === false) {
            $final_redirect_url = add_query_arg('message', 'delete_error', $redirect_url);
        } else {
            $final_redirect_url = add_query_arg(array('message' => 'deleted_successfully', 'deleted_id' => $item_id_to_delete), $redirect_url);
        }

        wp_redirect(esc_url_raw($final_redirect_url));
        exit;
    }

    public function handle_add_or_update_property() {
        global $wpdb;
        $table_name = 'ac_property';
        $is_update = (isset($_POST['action']) && $_POST['action'] === 'fsbhoa_update_property');
        $item_id = $is_update ? (isset($_POST['property_id']) ? absint($_POST['property_id']) : 0) : 0;

        // Nonce verification
        $nonce_action = $is_update ? 'fsbhoa_update_property_action_' . $item_id : 'fsbhoa_add_property_action';
        check_admin_referer($nonce_action, 'fsbhoa_property_nonce');

        // Get the URL of the page that submitted the request
        $redirect_url = wp_get_referer() ? wp_get_referer() : home_url('/');
        // Make sure the 'view' parameter is correctly set for the properties list
        $redirect_url = add_query_arg('view', 'properties', remove_query_arg(array('action', 'property_id'), $redirect_url));

        // --- Validation Logic ---
        $errors = array();
        $data_to_save = array();
        $data_to_save['street_address'] = isset($_POST['street_address']) ? trim(sanitize_text_field(wp_unslash($_POST['street_address']))) : '';
        $data_to_save['notes'] = isset($_POST['notes']) ? trim(sanitize_textarea_field(wp_unslash($_POST['notes']))) : '';
    
        if (empty($data_to_save['street_address'])) {
            $errors[] = 'Street Address is required.';
        } else {
            // --- Uniqueness Check with DB Error Handling ---
            if ($is_update) {
                // For an update, check for duplicates excluding the current item
                $query = $wpdb->prepare("SELECT property_id FROM {$table_name} WHERE street_address = %s AND property_id != %d", $data_to_save['street_address'], $item_id);
            } else {
                // For a new entry, check for any duplicates
                $query = $wpdb->prepare("SELECT property_id FROM {$table_name} WHERE street_address = %s", $data_to_save['street_address']);
            }

            $is_duplicate = $wpdb->get_var($query);

            if ($wpdb->last_error) {
                error_log('FSBHOA DB Error (Property Uniqueness Check): ' . $wpdb->last_error);
                wp_die('A database error occurred while verifying the address. Please go back and try again.');
            }

            if ($is_duplicate) {
                $errors[] = 'This Street Address is already in use by another property.';
            }
        }
    

        if (empty($errors)) {
            if ($is_update) {
                $result = $wpdb->update($table_name, $data_to_save, array('property_id' => $item_id));
                $message = 'property_updated';
            } else {
                $result = $wpdb->insert($table_name, $data_to_save);
                $message = 'property_added';
            }
    
            if ($result === false) {
                error_log('FSBHOA DB Error (Property Insert/Update): ' . $wpdb->last_error);
                wp_die('There was a database error while saving the property. Please go back and try again.');
            }
    
            // Redirect on success
            wp_redirect(add_query_arg('message', $message, $redirect_url));
            exit;
    
        } else {
            // A more graceful error handling would use transients to pass errors back to the form.
            // For now, this will clearly display the validation errors.
            wp_die('Please correct the following errors: <br/>' . implode('<br/>', $errors));
        }
    }
}
