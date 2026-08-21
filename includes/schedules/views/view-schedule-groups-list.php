<?php
if ( ! defined( 'WPINC' ) ) { die; }

function fsbhoa_render_schedule_groups_list( $schedule_id ) {
    global $wpdb;
    
    $all_groups = $wpdb->get_results("SELECT * FROM ac_groups ORDER BY group_name ASC");
    if ($wpdb->last_error) {
        echo '<div class="notice notice-error"><p>Database error fetching groups: ' . esc_html($wpdb->last_error) . '</p></div>';
        return;
    }

    $member_counts_raw = $wpdb->get_results("SELECT group_id, COUNT(*) as count FROM ac_cardholder_groups GROUP BY group_id", OBJECT_K);
    $member_counts = [];
    if ($member_counts_raw) {
        foreach ($member_counts_raw as $group_id => $data) {
            $member_counts[$group_id] = $data->count;
        }
    }
    
    $schedules_page_url = get_permalink(get_page_by_path('schedules'));
    ?>
    <table id="fsbhoa-schedule-group-table-<?php echo esc_attr($schedule_id); ?>" class="display" style="width:100%">
        <thead>
            <tr>
                <th class="no-sort fsbhoa-actions-column" style="width: 120px;">Actions</th>
                <th style="width: 120px;">Access Type</th>
                <th style="width: 20%;">Group Name</th>
                <th>Description</th>
                <th style="width: 80px;">Members</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($all_groups)) : ?>
                <tr><td colspan="5">No permission groups found.</td></tr>
            <?php else : foreach ($all_groups as $group) : ?>
                <tr>
                    <td class="fsbhoa-actions-column actions-column">
                        <?php
                            $edit_url = add_query_arg(['action' => 'edit_group_schedule', 'group_id' => $group->group_id, 'schedule_id' => $schedule_id], $schedules_page_url);
                            
                            if ($schedule_id == 1) { // Only show Toggle and Delete on the Normal schedule
                                $toggle_action = 'fsbhoa_schedule_toggle_group_' . $group->group_id;
                                $toggle_url = wp_nonce_url(admin_url('admin-post.php?action=fsbhoa_schedule_toggle_group&group_id=' . $group->group_id), $toggle_action);
                                $delete_action = 'fsbhoa_schedule_delete_group_' . $group->group_id;
                                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=fsbhoa_schedule_delete_group&group_id=' . $group->group_id), $delete_action);
                            }
                            
                            $is_enabled = (bool) $group->is_enabled;
                            $toggle_icon = $is_enabled ? 'dashicons-yes-alt' : 'dashicons-no-alt';
                            $toggle_title = $is_enabled ? 'Enabled. Click to disable.' : 'Disabled. Click to enable.';
                        ?>
                        <a href="<?php echo esc_url($edit_url); ?>" class="fsbhoa-action-icon" title="Edit schedule rules for this group"><span class="dashicons dashicons-edit"></span></a>
                        
                        <?php if ($schedule_id == 1) : ?>
                            <a href="<?php echo esc_url($toggle_url); ?>" class="fsbhoa-action-icon" title="<?php echo esc_attr($toggle_title); ?>"><span class="dashicons <?php echo $toggle_icon; ?>"></span></a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="fsbhoa-action-icon" title="Delete Group" onclick="return confirm('Are you sure you want to permanently delete this group?');"><span class="dashicons dashicons-trash"></span></a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($group->has_all_access) : ?>
                            <span style="font-weight: bold; color: #135e96;">All Access</span>
                        <?php else: ?>
                            <span>Scheduled</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?php echo esc_html($group->group_name); ?></strong></td>
                    <td><?php echo esc_html($group->group_description); ?></td>
                    <td><?php echo absint($member_counts[$group->group_id] ?? 0); ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
}
