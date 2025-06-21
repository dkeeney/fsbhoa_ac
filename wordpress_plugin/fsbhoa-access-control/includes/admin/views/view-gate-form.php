<?php

// =================================================================================================
// FILE: includes/admin/views/view-gate-form.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the add/edit form for a single gate.
 * @param array $form_data The data to populate the form with.
 * @param array $available_slots A list of available controller slots.
 * @param bool  $is_edit_mode True if we are editing.
 * @param array $errors Any validation errors.
 */
function fsbhoa_render_gate_form( $form_data, $available_slots, $is_edit_mode, $errors = [] ) {
    $page_title = $is_edit_mode ? 'Edit Gate' : 'Add New Gate';
    $submit_button_text = $is_edit_mode ? 'Update Gate' : 'Add Gate';
    $form_post_hook_action = $is_edit_mode ? 'fsbhoa_update_gate' : 'fsbhoa_add_gate';
    $nonce_action = $is_edit_mode ? 'fsbhoa_update_gate_' . $form_data['door_record_id'] : 'fsbhoa_add_gate';
    $cancel_url = add_query_arg('view', 'gates', get_permalink());
    ?>
    <div class="fsbhoa-frontend-wrap is-form-view">
        <h1><?php echo esc_html( $page_title ); ?></h1>

        <?php if (!empty($errors)) : ?>
            <div class="notice notice-error"><p>Please correct the errors: <?php echo esc_html(implode(', ', $errors)); ?></p></div>
        <?php endif; ?>

        <form id="fsbhoa-gate-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($form_post_hook_action); ?>" />
            <?php if ($is_edit_mode) : ?>
                <input type="hidden" name="door_record_id" value="<?php echo esc_attr($form_data['door_record_id']); ?>" />
            <?php endif; ?>
            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>" />
            <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>
            
            <div class="fsbhoa-form-section">
                <div class="form-row">
                    <div class="form-field">
                        <label for="gate_name">Gate Name</label>
                        <input name="gate_name" type="text" id="gate_name" value="<?php echo esc_attr($form_data['friendly_name']); ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="controller_slot">Controller Slot</label>
                        <select name="controller_slot" id="controller_slot" required>
                            <option value="">-- Select an Available Slot --</option>
                            <?php if ($is_edit_mode): ?>
                                <option value="<?php echo esc_attr($form_data['controller_record_id'] . '-' . $form_data['door_number_on_controller']); ?>" selected>
                                    <?php echo esc_html($form_data['controller_name'] . ' - Slot ' . $form_data['door_number_on_controller']); ?> (Current)
                                </option>
                            <?php endif; ?>
                            <?php foreach ($available_slots as $slot): ?>
                                <option value="<?php echo esc_attr($slot['value']); ?>"><?php echo esc_html($slot['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field is-full-width">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" rows="5"><?php echo esc_textarea($form_data['notes']); ?></textarea>
                    </div>
                </div>
            </div>
            
            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo esc_html( $submit_button_text ); ?></button>
                <a href="<?php echo esc_url($cancel_url); ?>" class="button button-secondary">Cancel</a>
            </p>
        </form>
    </div>
    <?php
}

