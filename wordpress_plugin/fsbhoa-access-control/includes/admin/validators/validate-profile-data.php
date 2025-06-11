/**
 * Validator for Cardholder Profile Data.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin/validators
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Validates Profile-related data from a form submission.
 *
 * @param array $post_data The raw $_POST data.
 * @return array An array with 'errors' and 'data' keys.
 */
function fsbhoa_validate_profile_data( $post_data ) {
    $errors = array();
    $sanitized_data = array();

    // Sanitize all profile fields first
    $sanitized_data['first_name']    = isset($post_data['first_name']) ? sanitize_text_field(wp_unslash($post_data['first_name'])) : '';
    $sanitized_data['last_name']     = isset($post_data['last_name']) ? sanitize_text_field(wp_unslash($post_data['last_name'])) : '';
    $raw_email = isset($post_data['email']) ? trim(wp_unslash($post_data['email'])) : '';
    $sanitized_data['phone_type']    = isset($post_data['phone_type']) ? sanitize_text_field(wp_unslash($post_data['phone_type'])) : '';

    // --- Validation Logic ---
    if ( empty($sanitized_data['first_name']) ) {
        $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' );
    }
    if ( empty($sanitized_data['last_name']) ) {
        $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' );
    }

    // --- EMAIL VALIDATION ---
    if ( ! empty($raw_email) ) {
        if ( ! is_email( $raw_email ) ) {
            $errors['email'] = __( 'Please enter a valid email address format (e.g., name@domain.com).', 'fsbhoa-ac' );
        }
    }
    $sanitized_data['email'] = sanitize_email($raw_email);

    // --- PHONE VALIDATION ---
    $raw_phone = isset($post_data['phone']) ? trim(wp_unslash($post_data['phone'])) : '';
    if ( ! empty($raw_phone) ) {
        // Strip all non-digit characters for validation
        $phone_digits_only = preg_replace('/[^0-9]/', '', $raw_phone);

        // If it starts with '1', remove it for the count
        if ( strlen($phone_digits_only) === 11 && substr($phone_digits_only, 0, 1) === '1' ) {
            $phone_digits_only = substr($phone_digits_only, 1);
        }

        if ( strlen($phone_digits_only) !== 10 ) {
            $errors['phone'] = __( 'Phone number must be a valid 10-digit number.', 'fsbhoa-ac' );
        }
        if ( empty($sanitized_data['phone_type']) ) {
            $errors['phone_type'] = __( 'Please select a phone type if a number is entered.', 'fsbhoa-ac' );
        }

        // Only store the clean 10-digit number if there are no errors
        if ( ! isset($errors['phone']) ) {
            $sanitized_data['phone'] = $phone_digits_only;
        } else {
             $sanitized_data['phone'] = $raw_phone; // Keep original for sticky field on error
        }
    } else {
        $sanitized_data['phone'] = null; // No phone provided
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}

