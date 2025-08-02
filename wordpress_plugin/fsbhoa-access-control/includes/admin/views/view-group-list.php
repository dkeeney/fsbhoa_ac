<?php
// File: wordpress_plugin/fsbhoa-access-control/includes/admin/views/view-group-list.php

if (!defined('WPINC')) {
    die;
}

/**
 * View template for displaying a simplified, hierarchical list of Access Groups.
 */

global $wpdb;

// --- Data Fetching ---
// Fetch all groups, ordered by name, to build the hierarchy.
$all_items = $wpdb->get_results("SELECT * FROM ac_groups ORDER BY group_name ASC");

if ($wpdb->last_error) {
    echo '<div class="error"><p>Database error fetching groups: ' . esc_html($wpdb->last_error) . '</p></div>';
    return;
}

// --- Organize into a Hierarchy ---
$top_level_groups = [];
$child_groups = [];

foreach ($all_items as $item) {
    if ($item->parent_group_id) {
        // This is a child group.
        if (!isset($child_groups[$item->parent_group_id])) {
            $child_groups[$item->parent_group_id] = [];
        }
        $child_groups[$item->parent_group_id][] = $item;
    } else {
        // This is a top-level (base) group.
        $top_level_groups[] = $item;
    }
}

?>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th scope="col" class="manage-column column-actions">Actions</th>
            <th scope="col" class="manage-column">Group Name</th>
            <th scope="col" class="manage-column">Status</th>
            <th scope="col" class="manage-column">Validity Period</th>
            <th scope="col" class="manage-column">Members</th>
        </tr>
    </thead>
    <tbody id="the-list">
        <?php if (!empty($top_level_groups)) : ?>
            <?php foreach ($top_level_groups as $item) : ?>
                <?php // --- Render the Parent Row --- ?>
                <?php include 'view-group-list-row.php'; ?>

                <?php // --- Render any Child Rows --- ?>
                <?php if (isset($child_groups[$item->group_id])) : ?>
                    <?php foreach ($child_groups[$item->group_id] as $child_item) : ?>
                        <?php 
                            // Pass the child item and an indent flag to the row template.
                            $item = $child_item; 
                            $is_child = true;
                            include 'view-group-list-row.php';
                            $is_child = false; // Reset for the next loop
                        ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else : ?>
            <tr class="no-items">
                <td class="colspanchange" colspan="5"><?php _e('No groups found.', 'fsbhoa-ac'); ?></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

