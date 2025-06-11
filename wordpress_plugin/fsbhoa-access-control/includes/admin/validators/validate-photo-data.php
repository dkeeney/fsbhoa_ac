<?php
/**
 * Validator for Cardholder Photo Data.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin/validators
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Validates and processes photo and notes data from a form submission.
 *
 * @param array $post_data The $_POST superglobal.
 * @param array $files_data The $_FILES superglobal.
 * @return array An array with 'errors' and 'data' keys.
 */
function fsbhoa_validate_photo_data( $post_data, $files_data ) {
    $errors = array();
    $sanitized_data = array();

    // Sanitize notes here as it's part of this visual section
    $sanitized_data['notes'] = isset($post_data['notes']) ? sanitize_textarea_field(wp_unslash($post_data['notes'])) : '';

    // Check if the user wants to remove the photo
    if ( ! empty( $post_data['remove_current_photo'] ) ) {
        $sanitized_data['photo'] = null;
        return array('errors' => $errors, 'data' => $sanitized_data);
    }

    // Prioritize cropped data
    if ( ! empty( $post_data['cropped_photo_data'] ) ) {
        // The data URL is expected to be 'data:image/jpeg;base64,....'
        if (strpos($post_data['cropped_photo_data'], 'base64,') !== false) {
            list(, $data) = explode(',', $post_data['cropped_photo_data']);
            $decoded_data = base64_decode( $data, true );
            if ( $decoded_data ) {
                $sanitized_data['photo'] = $decoded_data;
            } else {
                $errors['photo'] = __( 'Invalid cropped photo data submitted.', 'fsbhoa-ac' );
            }
        } else {
             $errors['photo'] = __( 'Invalid photo data format submitted.', 'fsbhoa-ac' );
        }

    // Fallback to direct file upload if no cropped data
    } elseif ( isset( $files_data['cardholder_photo_file_input'] ) && $files_data['cardholder_photo_file_input']['error'] === UPLOAD_ERR_OK ) {
        $file_info = wp_check_filetype( basename( $files_data['cardholder_photo_file_input']['name'] ) );
        $allowed_mime_types = array( 'image/jpeg', 'image/png', 'image/gif' );

        if ( ! in_array( $file_info['type'], $allowed_mime_types ) ) {
            $errors['photo'] = __( 'Invalid file type. Please upload a JPG, PNG, or GIF.', 'fsbhoa-ac' );
        }

        if ( empty($errors) && $files_data['cardholder_photo_file_input']['size'] > 2 * 1024 * 1024 ) { // 2MB limit
            $errors['photo'] = __( 'File is too large. Maximum size is 2MB.', 'fsbhoa-ac' );
        }

        if ( empty($errors) ) {
            $file_content = file_get_contents( $files_data['cardholder_photo_file_input']['tmp_name'] );
            if ( $file_content === false ) {
                $errors['photo'] = __( 'Could not read the uploaded file.', 'fsbhoa-ac' );
            } else {
                $sanitized_data['photo'] = $file_content;
            }
        }
    } elseif ( isset($files_data['cardholder_photo_file_input']) && $files_data['cardholder_photo_file_input']['error'] !== UPLOAD_ERR_NO_FILE ) {
        $errors['photo'] = __( 'There was an error with the file upload.', 'fsbhoa-ac' );
    }

    return array(
        'errors' => $errors,
        'data'   => $sanitized_data,
    );
}
