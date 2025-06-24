<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the add/edit form for a single controller.
 * @param array $form_data The data to populate the form with.
 * @param bool  $is_edit_mode True if we are editing.
 * @param array $errors Any validation errors.
 * @param array $discovered_controllers (new) A list of unconfigured controllers found on the network.
 */
function fsbhoa_render_controller_form( $form_data, $is_edit_mode, $errors = [], $discovered_controllers = [] ) {
    $page_title = $is_edit_mode ? 'Edit Controller' : 'Add New Controller';
    $submit_button_text = $is_edit_mode ? 'Update Controller' : 'Add Controller';
    $form_post_hook_action = $is_edit_mode ? 'fsbhoa_update_controller' : 'fsbhoa_add_controller';
    $nonce_action = $is_edit_mode ? 'fsbhoa_update_controller_' . ($form_data['controller_record_id'] ?? 0) : 'fsbhoa_add_controller';
    $cancel_url = get_permalink(); 
    ?>
    <div class="fsbhoa-frontend-wrap is-form-view">
        <h1><?php echo esc_html( $page_title ); ?></h1>

        <?php if (!empty($errors)) : ?>
            <div class="notice notice-error">
                <p><strong>Please correct the following errors:</strong></p>
                <ul>
                    <?php foreach($errors as $error) : ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- New Warning Area for IP Mismatch -->
        <div id="ip_address_warning" class="notice notice-warning" style="display:none; margin-bottom: 1em;">
             <p><strong>Warning:</strong> The discovered IP address for this controller is different from the one saved. Submitting this form will update the IP address.</p>
        </div>

        <form id="fsbhoa-controller-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($form_post_hook_action); ?>" />
            <?php if ($is_edit_mode) : ?>
                <input type="hidden" name="controller_record_id" value="<?php echo esc_attr($form_data['controller_record_id']); ?>" />
            <?php endif; ?>
            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>" />
            <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>
            
            <div class="fsbhoa-form-section">
                <div class="form-row is-multi-column">
                    <div class="form-field">
                        <label for="friendly_name">Name</label>
                        <input name="friendly_name" type="text" id="friendly_name" value="<?php echo esc_attr($form_data['friendly_name'] ?? ''); ?>" required>
                        <p class="description">A descriptive name for the controller.</p>
                    </div>
                    <div class="form-field">
                        <label for="uhppoted_device_id">Device ID (Serial Number)</label>
                        <input name="uhppoted_device_id" type="number" id="uhppoted_device_id" value="<?php echo esc_attr($form_data['uhppoted_device_id'] ?? ''); ?>" required>
                         <p class="description">The 9-digit serial number from the device.</p>
                    </div>
                    <div class="form-field">
                        <label for="ip_address">IP Address</label>
                        <input name="ip_address" type="text" id="ip_address" value="<?php echo esc_attr($form_data['ip_address'] ?? ''); ?>">
                        <p class="description">Populated by discovery or set manually.</p>
                    </div>
                </div>
                <div class="form-row">
                     <div class="form-field is-full-width">
                        <label for="location_description">Location Description</label>
                        <textarea name="location_description" id="location_description" rows="3"><?php echo esc_textarea($form_data['location_description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field is-full-width">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" rows="5"><?php echo esc_textarea($form_data['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
            
            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo esc_html( $submit_button_text ); ?></button>
                <a href="<?php echo esc_url($cancel_url); ?>" class="button button-secondary">Cancel</a>
            </p>
        </form>
    </div>
    <style>
       .is-multi-column { display: flex; align-items: flex-end; gap: 15px; }
       .is-multi-column .form-field { flex: 1; }
    </style>

    <?php
}


