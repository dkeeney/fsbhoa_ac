<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the cardholder photo management section.
 *
 * @param array $form_data The current data for the form.
 * @param bool  $is_edit_mode True if editing an existing cardholder.
 */
function fsbhoa_render_photo_section( $form_data, $is_edit_mode ) {
?>
<div class="fsbhoa-form-section" id="fsbhoa_photo_section">
    <div class="form-row">
        <!-- Photo Preview Area -->
        <div class="form-field" id="fsbhoa_main_photo_preview_area">
            <label><?php esc_html_e( 'Photo Preview', 'fsbhoa-ac' ); ?></label>
            <img id="fsbhoa_photo_preview_main_img" src="<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'data:image/jpeg;base64,' . base64_encode($form_data['photo']) : '#'; ?>" alt="Photo Preview" style="width: 150px; height: 188px; border: 1px solid #ddd; padding: 2px; object-fit: cover; display:<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'block' : 'none'; ?>; background-color: #f0f0f0;">
            <p id="fsbhoa_no_photo_message" style="margin-top: 5px; <?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'display:none;' : 'display:block;'; ?>"><em><?php esc_html_e('No photo available.', 'fsbhoa-ac'); ?></em></p>
            <button type="button" id="fsbhoa-crop-photo-btn" class="button" style="display:none; margin-top: 10px;"><?php esc_html_e( 'Crop Photo', 'fsbhoa-ac' ); ?></button>
        </div>

        <!-- Photo Upload Controls -->
        <div class="form-field">
            <label><?php esc_html_e( 'Update Photo', 'fsbhoa-ac' ); ?></label>
            <div id="fsbhoa_file_upload_section" style="margin-bottom: 1em;">
                <strong><?php esc_html_e('Option 1: Upload File', 'fsbhoa-ac'); ?></strong>
                <input type="file" name="cardholder_photo_file_input" id="cardholder_photo_file_input" accept="image/jpeg,image/png">
            </div>
            <div id="fsbhoa_webcam_section">
                <strong><?php esc_html_e('Option 2: Use Webcam', 'fsbhoa-ac'); ?></strong>
                <div id="fsbhoa_webcam_controls" style="margin-top: 5px;">
                    <button type="button" id="fsbhoa_start_webcam_button" class="button"><?php esc_html_e( 'Start Webcam', 'fsbhoa-ac' ); ?></button>
                    <div id="fsbhoa_webcam_active_controls" style="display:none;">
                        <button type="button" id="fsbhoa_capture_photo_button" class="button-primary"><?php esc_html_e( 'Capture Photo', 'fsbhoa-ac' ); ?></button>
                        <button type="button" id="fsbhoa_stop_webcam_button" class="button"><?php esc_html_e( 'Stop Webcam', 'fsbhoa-ac' ); ?></button>
                    </div>
                </div>
                <div id="fsbhoa_webcam_container" style="display:none; margin-top:10px; max-width: 320px;">
                    <video id="fsbhoa_webcam_video" autoplay muted playsinline style="width:100%; border:1px solid #ccc;"></video>
                    <canvas id="fsbhoa_webcam_canvas" style="display:none;"></canvas>
                </div>
            </div>
             <?php if ($is_edit_mode && !empty($form_data['photo'])): ?>
                <label style="display: block; margin-top: 15px;">
                   <input type="checkbox" name="remove_current_photo" value="1"> <?php esc_html_e('Remove current photo', 'fsbhoa-ac'); ?>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <!-- Notes Field -->
        <div class="form-field" style="flex-basis: 100%;">
            <label for="notes"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></label>
            <textarea name="notes" id="notes" rows="3" class="large-text"><?php echo esc_textarea($form_data['notes']); ?></textarea>
        </div>
    </div>

    <!-- Hidden field for the cropped photo data -->
    <input type="hidden" name="cropped_photo_data" id="fsbhoa_cropped_photo_data" value="">
</div>
<?php
}


/**
 * Validates and processes photo data from a form submission.
 *
 * @param array $post_data The $_POST superglobal.
 * @param array $files_data The $_FILES superglobal.
 * @return array An array with 'error' and 'data' keys.
 */
function fsbhoa_validate_photo_data( $post_data, $files_data ) {
    $errors = array();
    $sanitized_data = array();

    $sanitized_data['notes'] = isset($post_data['notes']) ? sanitize_textarea_field(wp_unslash($post_data['notes'])) : '';

    // Prioritize cropped data
    if ( ! empty( $post_data['cropped_photo_data'] ) ) {
        $decoded_data = base64_decode( $post_data['cropped_photo_data'], true );
        if ( $decoded_data ) {
            $sanitized_data['photo'] = $decoded_data;
        } else {
            $errors['photo'] = __( 'Invalid cropped photo data submitted.', 'fsbhoa-ac' );
        }
    // Fallback to file upload if no cropped data
    } elseif ( isset( $files_data['cardholder_photo_file_input'] ) && $files_data['cardholder_photo_file_input']['error'] === UPLOAD_ERR_OK ) {

        // Check file type
        $file_info = wp_check_filetype( $files_data['cardholder_photo_file_input']['name'] );
        $allowed_mime_types = array('jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif');
        if ( ! in_array( $file_info['type'], $allowed_mime_types ) ) {
            $errors['photo'] = __( 'Invalid file type. Please upload a JPG, PNG, or GIF.', 'fsbhoa-ac' );
        }

        // Check file size (e.g., 2MB limit)
        if ( empty($errors) && $files_data['cardholder_photo_file_input']['size'] > 2 * 1024 * 1024 ) {
            $errors['photo'] = __( 'File is too large. Maximum size is 2MB.', 'fsbhoa-ac' );
        }

        // If all checks pass, get the file content
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
        'data'  => $sanitized_data,
    );
}
