<?php
/**
 * Validator for Cardholder Address Data.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin/validators
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Validates Address-related data from a form submission.
 *
 * @param array $post_data The raw $_POST data.
 * @return array An array of error messages and sanitized data.
 */
function fsbhoa_validate_address_data( $post_data ) {
    $errors = array();
    $sanitized_data = array();
    $allowed_resident_types = array('Resident Owner', 'Tenant', 'Staff', 'Contractor', 'Other');

    $sanitized_data['resident_type'] = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';
    $property_address_display = isset( $post_data['property_address_display'] ) ? trim( sanitize_text_field( wp_unslash( $post_data['property_address_display'] ) ) ) : '';
    $property_id = isset( $post_data['property_id'] ) ? $post_data['property_id'] : '';

    if ( empty($sanitized_data['resident_type']) || !in_array( $sanitized_data['resident_type'], $allowed_resident_types ) ) {
        $errors['resident_type'] = 'A valid Resident Type is required.';
    }

    // --- PROPERTY ID VALIDATION ---
    if ( empty( $property_address_display ) ) {
        $sanitized_data['property_id'] = null;
    }
    elseif ( ! empty( $property_id ) ) {
        if ( is_numeric( $property_id ) ) {
            $sanitized_data['property_id'] = absint( $property_id );
        } else {
            $errors['property_id'] = 'An invalid Property ID was submitted. Please re-select the property.';
            $sanitized_data['property_id'] = null;
        }
    }
    elseif ( ! empty( $property_address_display ) && empty( $property_id ) ) {
        $errors['property_id'] = 'You must select a valid property from the search suggestions.';
        $sanitized_data['property_id'] = null;
    }
    else {
        $sanitized_data['property_id'] = null;
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}
