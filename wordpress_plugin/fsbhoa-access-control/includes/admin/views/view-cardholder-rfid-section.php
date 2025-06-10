<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the RFID & Card Details section of the cardholder form.
 *
 * @param array $form_data    The current data for the form.
 * @param bool  $is_edit_mode True if editing an existing cardholder.
 */
function fsbhoa_render_rfid_section( $form_data, $is_edit_mode ) {
?>
<tr id="fsbhoa_rfid_details_section" <?php if (!$is_edit_mode) echo 'style="display:none;"'; ?>>
    <th scope="row"><label for="rfid_id"><?php esc_html_e( 'RFID & Card Details', 'fsbhoa-ac' ); ?></label></th>
    <td>
        <div style="display: flex; flex-wrap: wrap; align-items: flex-start; gap: 10px 25px;">
            <div style="margin-bottom: 5px; flex-shrink: 0;">
                <label for="rfid_id" style="display: block; font-weight: bold; margin-bottom: .2em;"><?php esc_html_e( 'RFID Card ID', 'fsbhoa-ac' ); ?></label>
                <input type="text" name="rfid_id" id="rfid_id" class="regular-text" value="<?php echo esc_attr($form_data['rfid_id']); ?>" maxlength="8" pattern="[a-zA-Z0-9]{8}" title="<?php esc_attr_e('8-digit alphanumeric RFID.', 'fsbhoa-ac'); ?>" style="width: 10em;">
            </div>
            <div style="margin-bottom: 5px; flex-shrink: 0;">
                <?php if (!empty($form_data['rfid_id'])) : $is_card_active_for_ui_toggle = (isset($form_data['card_status']) && $form_data['card_status'] === 'active'); ?>
                    <strong style="display: block; margin-bottom: .2em;"><?php esc_html_e( 'Status:', 'fsbhoa-ac' ); ?></strong>
                    <span id="fsbhoa_card_status_display" style="padding: 3px 0; display: inline-block; min-width: 7em;"><?php echo esc_html(ucwords($form_data['card_status'])); ?></span>
                    <label style="margin-left: 10px; white-space: nowrap;">
                        <input type="checkbox" id="fsbhoa_card_status_ui_toggle" value="active" <?php checked(isset($form_data['card_status']) && $form_data['card_status'] === 'active'); ?>>
                        <span id="fsbhoa_card_status_toggle_ui_label"><?php echo (isset($form_data['card_status']) && $form_data['card_status'] === 'active') ? esc_html__('Card is Active (Click to Disable)', 'fsbhoa-ac') : esc_html__('Card is Inactive (Click to Activate)', 'fsbhoa-ac'); ?></span>
                    </label>
                <?php endif; ?>
            </div>
            <div style="display: flex; align-items: baseline; gap: 5px 15px; flex-shrink: 0; flex-wrap:nowrap;">
                <?php if (!empty($form_data['card_issue_date']) && $form_data['card_issue_date'] !== '0000-00-00') : ?>
                    <div style="white-space: nowrap;" id="fsbhoa_issue_date_wrapper">
                        <strong style="margin-right: .3em;"><?php esc_html_e( 'Issued:', 'fsbhoa-ac' ); ?></strong>
                        <span id="fsbhoa_card_issue_date_display"><?php echo esc_html($form_data['card_issue_date']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (isset($form_data['resident_type']) && $form_data['resident_type'] === 'Contractor') : ?>
                    <div style="white-space: nowrap;" id="fsbhoa_expiry_date_wrapper_contractor">
                        <label for="card_expiry_date_contractor_input" style="font-weight: bold; margin-right: .3em;"><?php esc_html_e( 'Expires (Contractor):', 'fsbhoa-ac' ); ?></label>
                        <input type="date" name="card_expiry_date" id="card_expiry_date_contractor_input" value="<?php echo esc_attr((isset($form_data['card_expiry_date']) && $form_data['card_expiry_date'] && $form_data['card_expiry_date'] !== '0000-00-00') ? $form_data['card_expiry_date'] : ''); ?>" style="width: 10em;">
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <input type="hidden" name="submitted_card_status" id="fsbhoa_submitted_card_status" value="<?php echo esc_attr($form_data['card_status']); ?>">
        <input type="hidden" name="submitted_card_issue_date" id="fsbhoa_submitted_card_issue_date" value="<?php echo esc_attr($form_data['card_issue_date']); ?>">
        <p class="description" style="margin-top: .5em;"><?php if (!$is_edit_mode) { echo esc_html__( 'RFID details are managed on the Edit screen after a cardholder is added.', 'fsbhoa-ac' ); } else { echo esc_html__( 'Use the checkbox to toggle card status. For Contractors, an expiry date is required for an active card.', 'fsbhoa-ac' ); } ?></p>
    </td>
</tr>
<?php
}




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

    // This logic only applies in edit mode.
    if ( ! $is_edit_mode ) {
        return array( 'errors' => $errors, 'data' => array() );
    }

    $table_name = $wpdb->prefix . 'ac_cardholders';
    
    // Sanitize all RFID-related inputs
    $submitted_rfid = isset($post_data['rfid_id']) ? sanitize_text_field(wp_unslash(trim($post_data['rfid_id']))) : '';
    $submitted_status = isset($post_data['submitted_card_status']) ? sanitize_text_field(wp_unslash($post_data['submitted_card_status'])) : null;
    $submitted_issue_date = isset($post_data['submitted_card_issue_date']) ? sanitize_text_field(wp_unslash($post_data['submitted_card_issue_date'])) : null;
    $submitted_expiry_date = isset($post_data['card_expiry_date']) ? sanitize_text_field(wp_unslash($post_data['card_expiry_date'])) : '';
    $resident_type = isset($post_data['resident_type']) ? sanitize_text_field(wp_unslash($post_data['resident_type'])) : '';

    // --- Validation Logic ---

    // 1. Validate RFID format and uniqueness if it was submitted and has changed.
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

    // 2. For Contractors with an active card, an expiry date is required.
    if ( $resident_type === 'Contractor' && $submitted_status === 'active' ) {
        if ( empty($submitted_expiry_date) ) {
            $errors['card_expiry_date'] = __('An active Contractor card requires an expiry date.', 'fsbhoa-ac');
        } elseif ( strtotime($submitted_expiry_date) <= time() ) {
            $errors['card_expiry_date'] = __('The expiry date must be in the future.', 'fsbhoa-ac');
        }
    }

    // If there are validation errors, stop here and return them.
    if ( ! empty($errors) ) {
        return array( 'errors' => $errors, 'data' => array() );
    }

    // --- Data Processing Logic ---
    // If validation passes, determine the final values to be saved.

    // RFID ID
    $sanitized_data['rfid_id'] = $submitted_rfid;

    // Card Status and Issue Date
    if ( !empty($submitted_rfid) && $submitted_rfid !== $existing_data['rfid_id'] ) {
        // If a new RFID is assigned, card becomes active and issue date is set to today.
        $sanitized_data['card_status'] = 'active';
        $sanitized_data['card_issue_date'] = current_time('Y-m-d');
    } elseif ( empty($submitted_rfid) && !empty($existing_data['rfid_id']) ) {
        // If an RFID is removed, card becomes inactive and dates are cleared.
        $sanitized_data['card_status'] = 'inactive';
        $sanitized_data['card_issue_date'] = null;
        $sanitized_data['card_expiry_date'] = null;
    } else {
        // Otherwise, trust the status from the checkbox UI.
        $sanitized_data['card_status'] = ($submitted_status === 'active') ? 'active' : 'disabled';
        $sanitized_data['card_issue_date'] = $submitted_issue_date;
    }

    // Expiry Date
    if ( $sanitized_data['card_status'] === 'active' ) {
        if ( $resident_type === 'Contractor' ) {
            $sanitized_data['card_expiry_date'] = $submitted_expiry_date;
        } else {
            // Non-contractors get a far-future expiry date.
            $sanitized_data['card_expiry_date'] = '2099-12-31';
        }
    } else {
        // Inactive or disabled cards have no expiry date.
        $sanitized_data['card_expiry_date'] = null;
    }

    return array( 'errors' => $errors, 'data' => $sanitized_data );
}
