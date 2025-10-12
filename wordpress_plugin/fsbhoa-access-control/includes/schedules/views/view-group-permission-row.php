<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/admin/views/view-group-permission-row.php

if (!defined('WPINC')) {
    die;
}

/**
 * View template for a single row in the group permissions editor.
 *
 * @var int|string  $index           The numerical index or '{{INDEX}}' for the template.
 * @var object|null $perm            The permission object from the database, or null for a new row.
 * @var array       $all_doors       List of all available doors.
 * @var array       $all_controllers List of all available controllers.
 */

// NEW: Determine the selected value for the dropdown based on the new schema
$selected_value = '';
if (isset($perm)) {
    if ($perm->door_id !== null) {
        $selected_value = 'gate-' . $perm->door_id;
    } elseif ($perm->controller_id !== null) {
        $selected_value = 'controller-' . $perm->controller_id;
    } elseif ($perm->door_id === null && $perm->controller_id === null) {
        $selected_value = 'all';
    }
}
?>
<tr class="permission-row">
    <td class="permission-row-actions">
        <input type="checkbox" class="is-enabled-checkbox" name="permissions[<?php echo $index; ?>][is_enabled]" value="1" <?php checked($perm->is_enabled ?? 1, 1); ?> style="display: none;">
        <button type="button" class="button-link-delete toggle-permission-status" title="Toggle Status">
            <span class="dashicons <?php echo ($perm->is_enabled ?? 1) ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
        </button>
        <button type="button" class="button-link-delete remove-permission-rule" title="Remove this rule"><span class="dashicons dashicons-trash"></span></button>
    </td>
    <td>
        <select name="permissions[<?php echo $index; ?>][door_id]" required>
            <option value=""><?php _e('-- Select a Target --', 'fsbhoa-ac'); ?></option>
            <option value="all" <?php selected($selected_value, 'all'); ?>>All Gates</option>

            <?php if (!empty($all_controllers)) : ?>
                <optgroup label="Controllers">
                    <?php foreach ($all_controllers as $controller) : ?>
                        <?php $controller_value = 'controller-' . $controller->controller_record_id; ?>
                        <option value="<?php echo esc_attr($controller_value); ?>" <?php selected($selected_value, $controller_value); ?>>
                            <?php echo esc_html($controller->friendly_name); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>

            <?php if (!empty($all_doors)) : ?>
                <optgroup label="Individual Gates">
                    <?php foreach ($all_doors as $door) : ?>
                        <?php $gate_value = 'gate-' . $door->door_record_id; ?>
                        <option value="<?php echo esc_attr($gate_value); ?>" <?php selected($selected_value, $gate_value); ?>>
                            <?php echo esc_html($door->friendly_name); ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>
    </td>
    <td>
        <input type="time" name="permissions[<?php echo $index; ?>][start_time]" value="<?php echo esc_attr($perm->start_time ?? ''); ?>" required>
    </td>
    <td>
        <input type="time" name="permissions[<?php echo $index; ?>][end_time]" value="<?php echo esc_attr($perm->end_time ?? ''); ?>" required>
    </td>
    <td class="days-of-week">
        <label title="Monday"><input type="checkbox" name="permissions[<?php echo $index; ?>][on_mon]" value="1" <?php checked($perm->on_mon ?? 0, 1); ?>> M</label>
        <label title="Tuesday"><input type="checkbox" name="permissions[<?php echo $index; ?>][on_tue]" value="1" <?php checked($perm->on_tue ?? 0, 1); ?>> T</label>
        <label title="Wednesday"><input type="checkbox" name="permissions[<?php echo $index; ?>][on_wed]" value="1" <?php checked($perm->on_wed ?? 0, 1); ?>> W</label>
        <label title="Thursday"><input type="checkbox" name="permissions[<?php echo $index; ?>][on_thu]" value="1" <?php checked($perm->on_thu ?? 0, 1); ?>> T</label>
        <label title="Friday"><input type="checkbox" name="permissions[<?php echo $index; ?>][on_fri]" value="1" <?php checked($perm->on_fri ?? 0, 1); ?>> F</label>
        <label title="Saturday"><input type="checkbox" name="permissions[<?php echo $index; ?>][on_sat]" value="1" <?php checked($perm->on_sat ?? 0, 1); ?>> S</label>
        <label title="Sunday"><input type="checkbox" name="permissions[<?php echo $index; ?>][on_sun]" value="1" <?php checked($perm->on_sun ?? 0, 1); ?>> S</label>
    </td>
</tr>


