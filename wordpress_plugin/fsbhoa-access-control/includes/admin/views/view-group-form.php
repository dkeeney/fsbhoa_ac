<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/admin/views/view-group-form.php

if (!defined('WPINC')) {
    die;
}

/**
 * View template for the Add/Edit Group form.
 *
 * @var int $group_id The ID of the group being edited, or 0 if adding.
 */

global $wpdb;

// --- Data Fetching Logic (with error checks) ---
$group = null;
$permissions = [];
$is_new = ($group_id === 0);

if (!$is_new) {
    $group = $wpdb->get_row($wpdb->prepare("SELECT * FROM ac_groups WHERE group_id = %d", $group_id));
    if ($wpdb->last_error) {
        echo '<div class="error"><p>Database error fetching group data: ' . esc_html($wpdb->last_error) . '</p></div>';
        return;
    }
    $permissions = $wpdb->get_results($wpdb->prepare("SELECT * FROM ac_group_permissions WHERE group_id = %d ORDER BY permission_id ASC", $group_id));
    if ($wpdb->last_error) {
        echo '<div class="error"><p>Database error fetching permissions: ' . esc_html($wpdb->last_error) . '</p></div>';
        return;
    }
}
$all_groups_query = "SELECT group_id, group_name FROM ac_groups";
if (!$is_new) { $all_groups_query .= $wpdb->prepare(" WHERE group_id != %d", $group_id); }
$all_groups_query .= " ORDER BY group_name ASC";
$all_groups = $wpdb->get_results($all_groups_query);
if ($wpdb->last_error) {
    echo '<div class="error"><p>Database error fetching parent groups list: ' . esc_html($wpdb->last_error) . '</p></div>';
    return;
}

$all_doors = $wpdb->get_results("SELECT door_record_id, friendly_name FROM ac_doors ORDER BY friendly_name ASC");
if ($wpdb->last_error) {
    echo '<div class="error"><p>Database error fetching doors list: ' . esc_html($wpdb->last_error) . '</p></div>';
    return;
}
$all_controllers = $wpdb->get_results("SELECT controller_record_id, friendly_name FROM ac_controllers ORDER BY friendly_name ASC");
if ($wpdb->last_error) {
    echo '<div class="error"><p>Database error fetching controllers list: ' . esc_html($wpdb->last_error) . '</p></div>';
    return;
}

?>

<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="fsbhoa_save_group">
    <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
    <?php wp_nonce_field('fsbhoa_save_group', 'fsbhoa_group_nonce'); ?>
    <?php wp_referer_field(); ?>

    <div class="form-single-line-container">
        <div class="form-field-group">
            <label for="group_name"><?php _e('Group Name', 'fsbhoa-ac'); ?></label>
            <input type="text" id="group_name" name="group_name" value="<?php echo esc_attr($group->group_name ?? ''); ?>" required>
        </div>
        <div class="form-field-group">
            <label for="parent_group_id"><?php _e('Parent Group', 'fsbhoa-ac'); ?></label>
            <select id="parent_group_id" name="parent_group_id">
                <option value="0"><?php _e('-- None (Base Group) --', 'fsbhoa-ac'); ?></option>
                <?php foreach ($all_groups as $parent_group) : ?>
                    <option value="<?php echo esc_attr($parent_group->group_id); ?>" <?php selected($group->parent_group_id ?? 0, $parent_group->group_id); ?>>
                        <?php echo esc_html($parent_group->group_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field-group">
            <label for="valid_from"><?php _e('Valid From', 'fsbhoa-ac'); ?></label>
            <input type="date" id="valid_from" name="valid_from" value="<?php echo esc_attr($group->valid_from ?? '2020-01-01'); ?>">
        </div>
        <div class="form-field-group">
            <label for="valid_to"><?php _e('Valid To', 'fsbhoa-ac'); ?></label>
            <input type="date" id="valid_to" name="valid_to" value="<?php echo esc_attr($group->valid_to ?? '2099-12-31'); ?>">
        </div>
    </div>

    <div class="form-row-container">
        <div class="form-full-width">
            <label for="group_description"><?php _e('Description (Notes)', 'fsbhoa-ac'); ?></label>
            <textarea id="group_description" name="group_description" rows="2" class="large-text compact-description"><?php echo esc_textarea($group->group_description ?? ''); ?></textarea>
        </div>
    </div>
    
    <hr>
    
    <div class="permissions-header">
        <h2><?php _e('Group Permissions', 'fsbhoa-ac'); ?></h2>
        <div class="all-access-toggle">
            <label><input type="checkbox" id="has_all_access" name="has_all_access" value="1" <?php checked($group->has_all_access ?? 0, 1); ?>> <?php _e('Unrestricted access', 'fsbhoa-ac'); ?></label>
            <label><input type="checkbox" name="is_default" value="1" <?php checked($group->is_default ?? 0, 1); ?>> <?php _e('Default Group', 'fsbhoa-ac'); ?></label>
 
        </div>
    </div>
    
    <div id="permissions-details-wrapper">
        <table class="wp-list-table widefat striped" id="group-permissions-table">
            <thead>
                <tr>
                    <th class="column-actions" style="width: 80px;">Actions</th>
                    <th><?php _e('Gate', 'fsbhoa-ac'); ?></th>
                    <th style="width: 10%;"><?php _e('Start Time', 'fsbhoa-ac'); ?></th>
                    <th style="width: 10%;"><?php _e('End Time', 'fsbhoa-ac'); ?></th>
                    <th><?php _e('Days', 'fsbhoa-ac'); ?></th>
                </tr>
            </thead>
            <tbody id="permissions-container">
                <?php
                if (!empty($permissions)) {
                    foreach ($permissions as $index => $perm) {
                        include 'view-group-permission-row.php';
                    }
                }
                ?>
            </tbody>
        </table>
    
        <p style="margin-top: 1em;">
            <button type="button" class="button" id="add-permission-rule"><?php _e('Add Permission Rule', 'fsbhoa-ac'); ?></button>
        </p>
    </div>

    <div class="form-actions-bar">
        <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e('Save Group', 'fsbhoa-ac'); ?>">
        <a href="<?php echo esc_url(remove_query_arg(['action', 'group_id'])); ?>" class="button button-secondary"><?php _e('Cancel', 'fsbhoa-ac'); ?></a>
    </div>

</form>

<table style="display: none;">
    <tbody id="permission-row-template">
        <?php 
            $index = '{{INDEX}}';
            $perm = null;
            include 'view-group-permission-row.php';
        ?>
    </tbody>
</table>

