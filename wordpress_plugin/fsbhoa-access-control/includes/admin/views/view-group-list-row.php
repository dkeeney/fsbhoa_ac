<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/admin/views/view-group-list-row.php

if (!defined('WPINC')) {
    die;
}

/**
 * View template for a single row in the hierarchical group list.
 *
 * @var object $item      The group object to render.
 * @var bool   $is_child  (Optional) Whether this row should be indented.
 */

global $wpdb;

// Set default for is_child if not passed.
$is_child = $is_child ?? false;

// Create secure URLs for each action.
$delete_url = wp_nonce_url(admin_url('admin-post.php?action=fsbhoa_delete_group&group_id=' . $item->group_id), 'fsbhoa_delete_group_action_' . $item->group_id, 'fsbhoa_delete_group_nonce');
$edit_url = add_query_arg(['action' => 'edit', 'group_id' => $item->group_id]);
$toggle_url = wp_nonce_url(admin_url('admin-post.php?action=fsbhoa_toggle_group_status&group_id=' . $item->group_id), 'fsbhoa_toggle_status_action_' . $item->group_id, 'fsbhoa_toggle_status_nonce');

?>
<tr class="<?php if ($is_child) echo 'child-row'; ?>">
    <td class="actions-column">
        <a href="<?php echo esc_url($edit_url); ?>" title="Edit Group"><span class="dashicons dashicons-edit"></span></a>
        <a href="<?php echo esc_url($delete_url); ?>" title="Delete Group" onclick="return confirm('Are you sure you want to permanently delete this group?');" class="delete-link"><span class="dashicons dashicons-trash"></span></a>
        <?php if ($item->is_enabled) : ?>
            <a href="<?php echo esc_url($toggle_url); ?>" title="Disable Group"><span class="dashicons dashicons-yes-alt" style="color: green;"></span></a>
        <?php else : ?>
            <a href="<?php echo esc_url($toggle_url); ?>" title="Enable Group"><span class="dashicons dashicons-no-alt" style="color: red;"></span></a>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($is_child) : ?>
            <span class="child-indent">—</span>
        <?php endif; ?>
        <strong><?php echo esc_html($item->group_name); ?></strong>
    </td>
    <td>
        <?php if ($item->is_enabled) { echo 'Enabled'; } else { echo 'Disabled'; } ?>
    </td>
    <td>
        <?php
            if ($item->valid_from === '2020-01-01' && $item->valid_to === '2099-12-31') {
                echo '<em>Always Active</em>';
            } else {
                echo esc_html(date_create($item->valid_from)->format('M j, Y') . ' – ' . date_create($item->valid_to)->format('M j, Y'));
            }
        ?>
    </td>
    <td>
        <?php 
            // For a child group, members are inherited, so we don't show a count.
            if ($is_child) {
                echo '<em>(Inherited)</em>';
            } elseif ($item->is_default) {
                // For the default group, all cardholders are members implicitly.
                $member_count = $wpdb->get_var("SELECT COUNT(*) FROM ac_cardholders");
                echo absint($member_count);
            } else {
                $member_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ac_cardholder_groups WHERE group_id = %d", $item->group_id));
                echo absint($member_count);
            }
        ?>
    </td>
</tr>
