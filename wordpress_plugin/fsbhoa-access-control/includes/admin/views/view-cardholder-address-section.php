<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the Address section of the cardholder form.
 *
 * @param array $form_data The current data for the form.
 */
function fsbhoa_render_address_section( $form_data ) {
    ?>
    <tr>
        <th scope="row"><label for="resident_type"><?php esc_html_e( 'Resident Type', 'fsbhoa-ac' ); ?></label></th>
        <td>
            <select name="resident_type" id="resident_type">
                <?php $current_resident_type = isset($form_data['resident_type']) ? $form_data['resident_type'] : ''; ?>
                <option value="" <?php selected($current_resident_type, ''); ?>>-- Select Type --</option>
                <option value="Resident Owner" <?php selected($current_resident_type, 'Resident Owner'); ?>>Resident Owner</option>
                <option value="Tenant" <?php selected($current_resident_type, 'Tenant'); ?>>Tenant</option>
                <option value="Staff" <?php selected($current_resident_type, 'Staff'); ?>>Staff</option>
                <option value="Contractor" <?php selected($current_resident_type, 'Contractor'); ?>>Contractor</option>
                <option value="Other" <?php selected($current_resident_type, 'Other'); ?>>Other</option>
            </select>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="fsbhoa_property_search_input"><?php esc_html_e( 'Property Address', 'fsbhoa-ac' ); ?></label></th>
        <td>
            <input type="text" id="fsbhoa_property_search_input" name="property_address_display" class="regular-text" placeholder="<?php esc_attr_e( 'Start typing address...', 'fsbhoa-ac' ); ?>" value="<?php echo esc_attr($form_data['property_address_display']); ?>">
            <input type="hidden" name="property_id" id="fsbhoa_property_id_hidden" value="<?php echo esc_attr($form_data['property_id']); ?>">
            <p class="description"><?php esc_html_e( 'Type 1+ characters to search.', 'fsbhoa-ac' ); ?> <span id="fsbhoa_property_clear_selection" style="display: <?php echo empty($form_data['property_id']) ? 'none' : 'inline'; ?>; margin-left:10px; color: #0073aa; cursor:pointer;"><?php esc_html_e('[Clear Selection]', 'fsbhoa-ac'); ?></span></p>
        </td>
    </tr>
    <?php
}

/**
 * Validates Address-related data from a form submission.
 *
 * @param array $form_data The sanitized form data.
 * @return array An array of error messages.
 */
 function fsbhoa_validate_address_data( $post_data ) {
    $errors = array();
    $sanitized_data = array();
    $allowed_resident_types = array('Resident Owner', 'Tenant', 'Staff', 'Contractor', 'Other');

    $sanitized_data['resident_type'] = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';
    $sanitized_data['property_id']   = isset($post_data['property_id']) && !empty($post_data['property_id']) ? absint($post_data['property_id']) : null;
    
    if ( empty($sanitized_data['resident_type']) || !in_array( $sanitized_data['resident_type'], $allowed_resident_types ) ) {
        $errors['resident_type'] = 'A valid Resident Type is required.';
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}




