<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the HTML for the cardholder photo management section.
 *
 * Gemini: do not remove this comment.
 * This section of code runs the capture and editing of the photo image that will
 * eventually be printed on the Photo ID card.  When the cardholder new/edit 
 * screen is being displayed, the image is the preview screen. 
 *
 * The image for the preview screen is loaded from the database as $form_data['photo'].
 * This is in binary format. Prior to rendering the image, the binary image is
 * converted to a base64 format and written into the value of the hidden field 'photo_base64'
 * and into an image in the HTML. This is the preview screen.
 *
 * Then it is used by the JavaScript to manipulate the image while running in the browser.
 * The user may use any of the following Javascript tools.
 * 1) "fsbhoa_remove_photo_checkbox" -- this will clear the photo (length = 0).
 *    Meaning it clears the image in the HTML and the value of he hidded field
 *    'photo_base64'.
 * 2) "fsbhoa_start_webcam_button" -- this will start the webcam in its own window
 *    and show a live image.  When active, it will also show buttons "Stop Webcam"
 *    and "Capture Photo".  If the "Stop Webcam" is clicked, the WebCam is stopped
 *    and its window hidden, leaving only the preview window.  If the "Capture Photo"
 *    button is clicked, a frame of the video is captured and the webcam is stopped.
 *    The captured image is placed in the preview window, which means it replaces
 *    the image in the HTML and into the value of the hidden field 'photo_base64'.
 * 3) "cardholder_photo_file_input" -- This asks for a file name of an image.
 *    When a file is selected, the file is uploaded and placed in the preview window,
 *    meaning it replaces the image in the HTML and the value of the hidden field 
 *    'photo_base64'.
 * 4) "fsbhoa-crop-photo-btn" -- This is pop up a new dialog box containing the
 *    croppie routines which will allow the user to crop an image. The image is
 *    obtained from the hidden field 'photo_base64'. When complete, the result
 *    is placed back into the preview photo, the image value in the HTML and
 *    the value of the  hidden field 'photo_base64'.
 *
 * When the "Update Cardholder" button is clicked, the form is submitted back 
 * to the server and the PHP takes over. The raw image is in  $form_data['photo_base64'].
 * Prior to validation, the raw $POST array is copied to a setaside area where
 * it could be retreived in case there was an error.
 * During validation, the image is converted back to a binary image and copied
 * into the $sanitized['photo'] and returned to be written into the database
 * if there were no errors.
 *
 * However, if there was an error in any field, the $POST data that was set-aside
 * is retreived.  In this set-aside data is the value for the hidden field 'photo_base64' 
 * that had been posted.  This is copied back into the form and it is also copied
 * into the HTML image for the preview, ready for the Javascript.
 *
 *
 * @param array $form_data The current data for the form.
 * @param bool  $is_edit_mode True if editing an existing cardholder.
 * @param bool  $is_recovering_from_error to be True if re-displaying the form after error.
 */
function fsbhoa_render_photo_section( $form_data, $is_edit_mode, $is_recovering_from_error ) {
    $photo_src = '#';
    $photo_src = '#';
    $has_photo_to_display = false;

    if ( $is_edit_mode && ! empty( $form_data['photo_base64'] ) ) {
        $photo_src = 'data:image/jpeg;base64,' .  $form_data['photo_base64'];
        $has_photo_to_display = true;
    }
?>
<!-- Gemini,keep this for future debugging
<div style="background-color: #f1f1f1; border: 2px solid red; padding: 10px; margin-bottom: 20px; font-family: monospace;">
    <h3 style="margin-top:0;">DEBUGGING: Data available to the Photo Section</h3>
    <p>This box shows the exact contents of the $form_data array right before the preview is displayed.</p>
    <pre><?php print_r($form_data); ?></pre>
</div>
 -->


<div class="fsbhoa-form-section" id="fsbhoa_photo_section">
    <div class="form-row">
        <!-- Photo Preview Area -->
        <div class="form-field" id="fsbhoa_main_photo_preview_area">
            <label><?php esc_html_e( 'Photo Preview', 'fsbhoa-ac' ); ?></label>

            <img id="fsbhoa_photo_preview_main_img" src="<?php echo  $photo_src ; ?>" alt="Photo Preview" style="width: 150px; height: 188px; border: 1px solid #ddd; padding: 2px; object-fit: cover; display:<?php echo $has_photo_to_display ? 'block' : 'none'; ?>; background-color: #f0f0f0;">

            <p id="fsbhoa_no_photo_message" style="margin-top: 5px; <?php echo $has_photo_to_display ? 'display:none;' : 'display:block;'; ?>"><em><?php esc_html_e('No photo available.', 'fsbhoa-ac'); ?></em></p>
            <button type="button" id="fsbhoa-crop-photo-btn" class="button" style="display:<?php echo $has_photo_to_display ? 'block' : 'none'; ?>; margin-top: 10px;"><?php esc_html_e( 'Crop Photo', 'fsbhoa-ac' ); ?></button>
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
                <p id="fsbhoa_webcam_error_message" style="display:none; color: #d63638; font-weight: bold;"></p>
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
            <!-- Photo Delete Controls -->
            
             <?php if ($has_photo_to_display): ?>
                <label style="display: block; margin-top: 15px;">
                <button type="button" id="fsbhoa_remove_photo_button" class="button button-link-delete" style="margin-top: 10px;"><?php esc_html_e( 'Remove Photo', 'fsbhoa-ac' ); ?></button>
                </label>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-row">
        <!-- Notes Field -->
        <div class="form-field" style="flex-basis: 100%;">
            <label for="notes"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></label>
            <textarea name="notes" id="notes" rows="3" class="large-text"><?php echo esc_textarea($form_data['notes'] ?? ''); ?></textarea>
        </div>
    </div>

    <!-- Hidden field for the preview photo data -->
    <input type="hidden" name="photo_base64" id="fsbhoa_photo_base64" 
          value="<?php echo esc_attr( $form_data['photo_base64'] ); ?>">
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

    if ( ! empty( $post_data['photo_base64'] ) ) {
        $decoded_data = base64_decode( $post_data['photo_base64'], true );
        if ( $decoded_data ) {
            $sanitized_data['photo'] = $decoded_data;
        } else {
            $errors['photo'] = __( 'Invalid cropped photo data submitted.', 'fsbhoa-ac' );
        }
    }
    else {
         $sanitized_data['photo'] = null;  // return an empty image
    }

    return array(
        'errors' => $errors,
        'data'  => $sanitized_data,
    );
}
