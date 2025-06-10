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
<div class="fsbhoa-form-section">
    <h3>Photo & Notes</h3>
    <div class="form-row">
        <div id="fsbhoa_main_photo_preview_area" style="flex-basis: 200px; text-align: center;">
            <strong style="display:block; margin-bottom: 5px;">Photo Preview</strong>
            <img id="fsbhoa_photo_preview_main_img" src="<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'data:image/jpeg;base64,' . base64_encode($form_data['photo']) : '#'; ?>" alt="Photo Preview" style="max-width: 150px; height: 188px; border: 1px solid #ddd; padding: 2px; margin-top: 5px; object-fit: cover; display:<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'block' : 'none'; ?>;">
            <p id="fsbhoa_no_photo_message" style="display:<?php echo ($is_edit_mode && !empty($form_data['photo'])) ? 'none' : 'block'; ?>;"><em>No photo.</em></p>
            <button type="button" id="fsbhoa-crop-photo-btn" class="button" style="display:none; margin-top: 10px;">Crop Photo</button>
        </div>
        <div style="flex-grow: 1; display: flex; flex-direction: column; gap: 20px;">
            <div id="fsbhoa_file_upload_section">
                <strong>Option 1: Upload Photo File</strong><br>
                <input type="file" name="cardholder_photo_file_input" id="cardholder_photo_file_input" accept="image/jpeg,image/png,image/gif">
            </div>
            <div id="fsbhoa_webcam_section">
                <strong>Option 2: Use Webcam</strong><br>
                <button type="button" id="fsbhoa_start_webcam_button" class="button">Start WebCam</button>
                <div id="fsbhoa_webcam_active_controls" style="display:none; margin-top:5px;">
                    <button type="button" id="fsbhoa_capture_photo_button" class="button">Capture Photo</button> 
                    <button type="button" id="fsbhoa_stop_webcam_button" class="button">Stop WebCam</button>
                </div>
                <div id="fsbhoa_webcam_container" style="display:none; margin-top:10px;">
                    <video id="fsbhoa_webcam_video" autoplay muted playsinline style="max-width:100%; border:1px solid #ccc;"></video>
                    <canvas id="fsbhoa_webcam_canvas" style="display:none;"></canvas>
                </div>
            </div>
        </div>
        <div class="form-field" style="flex-basis: 100%; margin-top: 1em;">
             <label for="notes">Notes</label>
             <textarea name="notes" id="notes" rows="4" class="large-text"><?php echo esc_textarea($form_data['notes']); ?></textarea>
        </div>
    </div>
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
