<?php
// FILE: includes/admin/views/view-task-list.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the list of scheduled tasks.
 */
function fsbhoa_render_task_list_view() {
    global $wpdb;
    
    // This is a more complex query to get friendly names for controllers and doors.
    $tasks = $wpdb->get_results(
        "SELECT 
            t.*, 
            c.friendly_name as controller_name,
            d.friendly_name as door_name
         FROM ac_task_list t
         LEFT JOIN ac_controllers c ON t.controller_id = c.controller_record_id
         LEFT JOIN ac_doors d ON t.door_number = d.door_record_id
         ORDER BY t.id DESC",
        ARRAY_A
    );
    
    if ( $wpdb->last_error ) {
        echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve tasks. ' . esc_html( $wpdb->last_error ) . '</p></div>';
    }

    $current_page_url = get_permalink();
    ?>
    <div class="fsbhoa-frontend-wrap is-wide-view">
        <h1><?php esc_html_e( 'Task List Management', 'fsbhoa-ac' ); ?></h1>
        
        <div class="fsbhoa-table-controls">
            <!-- CORRECTED LINK -->
            <a href="<?php echo esc_url( add_query_arg(['view' => 'tasks', 'action' => 'add'], $current_page_url) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Add New Task', 'fsbhoa-ac' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg('view', 'controllers', $current_page_url) ); ?>" class="button button-secondary" style="margin-left: 5px;">Manage Controllers</a>
            <a href="<?php echo esc_url( add_query_arg('view', 'gates', $current_page_url) ); ?>" class="button button-secondary" style="margin-left: 5px;">Manage Gates</a>
        </div>

        <div class="table-wrapper" style="overflow-x: auto;">
            <table id="fsbhoa-task-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th class="no-sort fsbhoa-actions-column">Actions</th>
                        <th class="task-id-column">Task ID</th>
                        <th>Adapt To</th>
                        <th>Task</th>
                        <th class="task-time-column">Time</th>
                        <th class="day-col">Mon</th>
                        <th class="day-col">Tue</th>
                        <th class="day-col">Wed</th>
                        <th class="day-col">Thu</th>
                        <th class="day-col">Fri</th>
                        <th class="day-col">Sat</th>
                        <th class="day-col">Sun</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty($tasks) ) : foreach ( $tasks as $task ) : ?>
                        <tr>
                            <td class="fsbhoa-actions-column">
                                <?php
                                $edit_url = add_query_arg(['view' => 'tasks', 'action' => 'edit', 'task_id' => absint($task['id'])], $current_page_url);
                                $delete_nonce = wp_create_nonce('fsbhoa_delete_task_nonce_' . $task['id']);
                                $delete_url = add_query_arg(['action'=> 'fsbhoa_delete_task', 'task_id' => absint($task['id']), '_wpnonce'=> $delete_nonce], admin_url('admin-post.php'));
                                ?>
                                <a href="<?php echo esc_url($edit_url); ?>" title="Edit"><span class="dashicons dashicons-edit"></span></a>
                                <a href="<?php echo esc_url($delete_url); ?>" title="Delete" onclick="return confirm('Are you sure?');"><span class="dashicons dashicons-trash"></span></a>
                            </td>
                            <td class="task-id-column"><?php echo esc_html( $task['id'] ); ?></td>
                            <td>
                                <?php 
                                    if (!empty($task['door_name'])) {
                                        echo 'Gate: ' . esc_html($task['door_name']);
                                    } elseif (!empty($task['controller_name'])) {
                                        echo 'Controller: ' . esc_html($task['controller_name']);
                                    } else {
                                        echo '(All)';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php 
                                    $task_map = [0 => 'Unlock by Card', 1 => 'Unlock', 2 => 'Locked'];
                                    echo esc_html($task_map[$task['task_type']] ?? 'Unknown');
                                ?>
                            </td>
                            <td class="task-time-column"><?php echo esc_html( date("g:i A", strtotime($task['start_time'])) ); ?></td>
                            <td class="day-col"><?php echo $task['on_mon'] ? '✓' : ''; ?></td>
                            <td class="day-col"><?php echo $task['on_tue'] ? '✓' : ''; ?></td>
                            <td class="day-col"><?php echo $task['on_wed'] ? '✓' : ''; ?></td>
                            <td class="day-col"><?php echo $task['on_thu'] ? '✓' : ''; ?></td>
                            <td class="day-col"><?php echo $task['on_fri'] ? '✓' : ''; ?></td>
                            <td class="day-col"><?php echo $task['on_sat'] ? '✓' : ''; ?></td>
                            <td class="day-col"><?php echo $task['on_sun'] ? '✓' : ''; ?></td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="12"><?php esc_html_e( 'No tasks found.', 'fsbhoa-ac' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <style>
        .day-col {
            text-align: center;
            padding-left: 5px;
            padding-right: 5px;
            width: 35px; /* Give a fixed small width */
        }
        .task-id-column {
            text-align: center;
            width: 40px; /* Give a fixed small width */
            padding-left: 5px;
            padding-right: 5px;
        }
        .task-time-column {
            width: 80px; /* Give a fixed small width */
            padding-left: 5px;
            padding-right: 5px;
        }
        .fsbhoa-actions-column {
             width: 50px; /* Give a fixed small width */
            padding-left: 5px;
            padding-right: 5px;
        }
    </style>
    <?php
}

