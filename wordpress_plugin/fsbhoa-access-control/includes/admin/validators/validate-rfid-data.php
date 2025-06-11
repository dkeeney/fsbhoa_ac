<?php
/**
 * Validator for Cardholder RFID Data.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin/validators
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Validates RFID-related data and determines final card status and dates.
 *
 * @param array $post_data      The sanitized $_POST superglobal.
 * @param array $existing_data  The existing cardholder data from the DB.
 * @param int   $cardholder_id  The ID of the cardholder being edited.
 * @param bool  $is_edit_mode   True if we are in edit mode.
 * @return array An array with 'errors' and 'data' keys.
 */
function fsbhoa_validate_rfid_data( $post_data, $existing_data, $cardholder_id, $is_edit_mode ) {
    global $wpdb;
    $errors = array();
    $sanitized_data = array();

    if ( ! $is_edit_mode ) {
        $sanitized_data['card_status'] = 'inactive'; // Default for new cardholders
        $sanitized_data['card_expiry_date'] = '2099-12-31';
        return array( 'errors' => $errors, 'data' => $sanitized_data );
    }

    $table_name = 'ac_cardholders';

    $submitted_rfid = isset($post_data['rfid_id']) ? sanitize_text_field(wp_unslash(trim($post_data['rfid_id']))) : '';
    $submitted_status = isset($post_data['submitted_card_status']) ? sanitize_key(wp_unslash($post_data['submitted_card_status'])) : null;
    $submitted_expiry_date = isset($post_data['card_expiry_date']) ? sanitize_text_field(wp_unslash($post_data['card_expiry_date'])) : '';
    $resident_type = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';

    // --- VALIDATION ---
    if ( ! empty($submitted_rfid) ) {
        if ( ! preg_match('/^[a-zA-Z0-9]{8}$/', $submitted_rfid) ) {
            $errors['rfid_id'] = __('RFID ID must be 8 alphanumeric characters.', 'fsbhoa-ac');
        } elseif ( $submitted_rfid !== $existing_data['rfid_id'] ) {
            $is_duplicate = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_name} WHERE rfid_id = %s AND id != %d", $submitted_rfid, $cardholder_id));
            if ( $is_duplicate ) {
                $errors['rfid_id_duplicate'] = __('This RFID ID is already assigned to another cardholder.', 'fsbhoa-ac');
            }
        }
    }

    if ( $resident_type === 'Contractor' && $submitted_status === 'active' ) {
        if ( empty($submitted_expiry_date) ) {
            $errors['card_expiry_date'] = __('An active Contractor card requires an expiry date.', 'fsbhoa-ac');
        } elseif ( strtotime($submitted_expiry_date) < time() ) {
            $errors['card_expiry_date_past'] = __('The expiry date for a Contractor cannot be in the past.', 'fsbhoa-ac');
        }
    }

    if ( ! empty($errors) ) {
        return array( 'errors' => $errors, 'data' => array() );
    }

    // --- DATA PROCESSING ---
    $sanitized_data['rfid_id'] = !empty($submitted_rfid) ? $submitted_rfid : null;

    if ( $sanitized_data['rfid_id'] !== $existing_data['rfid_id'] ) {
        // RFID was added, changed, or removed
        if ( ! is_null( $sanitized_data['rfid_id'] ) ) {
            $sanitized_data['card_status'] = 'active';
            $sanitized_data['card_issue_date'] = current_time('mysql', 1); // GMT
        } else {
            $sanitized_data['card_status'] = 'inactive';
            $sanitized_data['card_issue_date'] = null;
        }
    } else {
        // RFID did not change, so status is based on the UI toggle
        $sanitized_data['card_status'] = ($submitted_status === 'active') ? 'active' : 'disabled';
    }

    if ( $sanitized_data['card_status'] === 'active' ) {
        if ( $resident_type === 'Contractor' ) {
            $sanitized_data['card_expiry_date'] = $submitted_expiry_date;
        } else {
            $sanitized_data['card_expiry_date'] = '2099-12-31';
        }
    } else {
        $sanitized_data['card_expiry_date'] = null; // Inactive or disabled cards have NULL expiry
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}

