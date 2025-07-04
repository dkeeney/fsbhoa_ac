<?php
// =================================================================================================
// FILE: includes/admin/views/view-task-form.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the add/edit form for a single task.
 */
function fsbhoa_render_task_form( $form_data, $adapt_to_options, $is_edit_mode, $errors = [] ) {
    $page_title = $is_edit_mode ? 'Edit Task' : 'Add New Task';
    $submit_button_text = $is_edit_mode ? 'Update Task' : 'Add Task';
    $form_post_hook_action = $is_edit_mode ? 'fsbhoa_update_task' : 'fsbhoa_add_task';
    $nonce_action = $is_edit_mode ? 'fsbhoa_update_task_' . $form_data['id'] : 'fsbhoa_add_task';
    $cancel_url = add_query_arg('view', 'tasks', get_permalink());
    ?>
    <div class="fsbhoa-frontend-wrap is-wide-view">
        <h1><?php echo esc_html( $page_title ); ?></h1>

        <?php if (!empty($errors)) : ?>
            <div class="notice notice-error"><p>Please correct the errors: <?php echo esc_html(implode(', ', $errors)); ?></p></div>
        <?php endif; ?>

        <form id="fsbhoa-task-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($form_post_hook_action); ?>" />
            <?php if ($is_edit_mode) : ?>
                <input type="hidden" name="task_id" value="<?php echo esc_attr($form_data['id']); ?>" />
            <?php endif; ?>
            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>" />
            <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>
            
            <div class="fsbhoa-form-section">
                <div class="form-row">
                    <div class="form-field">
                        <label for="adapt_to">Adapt To</label>
                        <select name="adapt_to" id="adapt_to" required>
                            <option value="">-- Select Target --</option>
                            <?php foreach ($adapt_to_options as $option): ?>
                                <option value="<?php echo esc_attr($option['value']); ?>" <?php selected($form_data['adapt_to_selected'], $option['value']); ?>>
                                    <?php echo esc_html($option['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="task_type">Task</label>
                        <select name="task_type" id="task_type" required>
                             <option value="1" <?php selected($form_data['task_type'], 1); ?>>Unlock by Card (Controlled)</option>
                             <option value="2" <?php selected($form_data['task_type'], 2); ?>>Unlock (Normally Open)</option>
                             <option value="3" <?php selected($form_data['task_type'], 3); ?>>Locked (Normally Closed)</option>
                        </select>
                    </div>
                </div>
                <!-- Combined Time and Date Row -->
                <div class="form-row">
                    <div class="form-field">
                        <label for="start_time">Activation Time</label>
                        <input type="time" name="start_time" id="start_time" value="<?php echo esc_attr($form_data['start_time']); ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="valid_from">Activation Date</label>
                        <input type="date" name="valid_from" id="valid_from" value="<?php echo esc_attr($form_data['valid_from']); ?>" required>
                    </div>
                     <div class="form-field">
                        <label for="valid_to">Deactivation Date</label>
                        <input type="date" name="valid_to" id="valid_to" value="<?php echo esc_attr($form_data['valid_to']); ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field is-full-width">
                        <label>Days of the Week</label>
                        <div class="weekday-checkbox-group">
                            <?php 
                                $days = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
                                foreach ($days as $key => $label):
                            ?>
                                <label><input type="checkbox" name="on_<?php echo $key; ?>" value="1" <?php checked($form_data['on_'.$key], 1); ?>> <?php echo $label; ?></label>
                            <?php endforeach; ?>
                        </div>
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
    <style>.weekday-checkbox-group label { display: inline-block; margin-right: 15px; }</style>
    <?php
}

