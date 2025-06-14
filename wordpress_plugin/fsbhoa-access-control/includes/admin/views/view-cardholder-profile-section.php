<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the main profile section of the cardholder form.
 *
 * @param array $form_data The current data for the form.
 */
function fsbhoa_render_profile_section( $form_data ) {
?>
<div class="fsbhoa-form-section">
    <div class="form-row">
        <div class="form-field">
            <label for="first_name">First Name</label>
            <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr($form_data['first_name']); ?>" required>
        </div>
        <div class="form-field">
            <label for="last_name">Last Name</label>
            <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr($form_data['last_name']); ?>" required>
        </div>
    </div>
    <div class="form-row">
        <div class="form-field">
            <label for="email">Email</label>
            <input type="text" name="email" id="email" value="<?php echo esc_attr($form_data['email']); ?>" pattern=".+@.+\..+" title="Please enter a valid email address (e.g., name@domain.com)">
        </div>
        <div class="form-field">
            <label for="phone">Phone Number</label>
            <input type="tel" name="phone" id="phone" value="<?php echo esc_attr($form_data['phone']); ?>" pattern="[0-9\s\(\)\-\.+]{10,}" title="Please enter a valid 10-digit phone number.">
        </div>
        <div class="form-field">
            <label for="phone_type">Phone Type</label>
            <select name="phone_type" id="phone_type">
                <?php $current_phone_type = isset($form_data['phone_type']) ? $form_data['phone_type'] : 'Mobile'; ?>
                <option value="" <?php selected($current_phone_type, ''); ?>>-- Select --</option>
                <option value="Mobile" <?php selected($current_phone_type, 'Mobile'); ?>>Mobile</option>
                <option value="Home" <?php selected($current_phone_type, 'Home'); ?>>Home</option>
                <option value="Work" <?php selected($current_phone_type, 'Work'); ?>>Work</option>
                <option value="Other" <?php selected($current_phone_type, 'Other'); ?>>Other</option>
            </select>
        </div>
    </div>
</div>
<?php
}
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
    $sanitized_data['email']         = isset($post_data['email']) ? sanitize_email(wp_unslash($post_data['email'])) : '';
    $sanitized_data['phone_type']    = isset($post_data['phone_type']) ? sanitize_text_field(wp_unslash($post_data['phone_type'])) : '';
    $sanitized_data['notes']         = isset($post_data['notes']) ? sanitize_textarea_field(wp_unslash($post_data['notes'])) : '';

    // --- Validation Logic ---
    if ( empty($sanitized_data['first_name']) ) { 
        $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); 
    }
    if ( empty($sanitized_data['last_name']) ) { 
        $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); 
    }
    
    // ---  EMAIL VALIDATION ---
    if ( ! empty($raw_email) ) {
        // Use our strict regex pattern to check for format like name@domain.com
        if ( ! preg_match('/^[^@\s]+@[^@\s\.]+\.[^@\s\.]{2,}$/', $raw_email) ) {
            $errors['email'] = __( 'Please enter a valid email address format (e.g., name@domain.com).', 'fsbhoa-ac' );
        }
    }
    $sanitized_data['email'] = sanitize_email($raw_email);
    
    // Full phone number validation
    $raw_phone = isset($post_data['phone']) ? trim(wp_unslash($post_data['phone'])) : '';
    $sanitized_data['phone'] = $raw_phone; // Keep original for sticky field on error

    if ( ! empty($raw_phone) ) {
        // Strip all non-digit characters for validation
        $phone_digits_only = preg_replace('/[^0-9]/', '', $raw_phone);

        // If it starts with '1', remove it for the count
        if ( strlen($phone_digits_only) === 11 && substr($phone_digits_only, 0, 1) === '1' ) {
            $phone_digits_only = substr($phone_digits_only, 1);
        }

        if ( strlen($phone_digits_only) !== 10 ) {
            $errors['phone'] = __( 'Phone number must be a valid 10-digit North American number.', 'fsbhoa-ac' );
        }
        if ( empty($sanitized_data['phone_type']) ) {
            $errors['phone_type'] = __( 'Please select a phone type if a number is entered.', 'fsbhoa-ac' );
        }

        // If there are no errors, store the clean 10-digit number
        if ( ! isset($errors['phone']) ) {
            $sanitized_data['phone'] = $phone_digits_only;
        }
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}
