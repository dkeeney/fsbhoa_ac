<?php
// FILE: includes/admin/views/view-gate-list.php
// =======================================================
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the list of access control gates (doors).
 */
function fsbhoa_render_gate_list_view() {
    global $wpdb;
    $table_doors = 'ac_doors';
    $table_controllers = 'ac_controllers';

    $gates = $wpdb->get_results(
        "SELECT d.*, c.friendly_name as controller_name
         FROM {$table_doors} d
         LEFT JOIN {$table_controllers} c ON d.controller_record_id = c.controller_record_id
         ORDER BY c.friendly_name ASC, d.door_number_on_controller ASC",
        ARRAY_A
    );
    
    if ( $wpdb->last_error ) {
        echo '<div class="notice notice-error"><p><strong>Database Error:</strong> Could not retrieve gates. ' . esc_html( $wpdb->last_error ) . '</p></div>';
    }

    $current_page_url = get_permalink();
    ?>
    <div class="fsbhoa-frontend-wrap">
        <h1><?php esc_html_e( 'Gate Management', 'fsbhoa-ac' ); ?></h1>
        
        <div class="fsbhoa-table-controls">
            <a href="<?php echo esc_url( add_query_arg(['view' => 'gates', 'action' => 'add'], $current_page_url) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Add New Gate', 'fsbhoa-ac' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg('view', 'controllers', $current_page_url) ); ?>" class="button button-secondary" style="margin-left: 5px;">
                <?php echo esc_html__( 'Manage Controllers', 'fsbhoa-ac' ); ?>
            </a>
        </div>

        <table id="fsbhoa-gate-table" class="display" style="width:100%">
            <thead>
                <tr>
                    <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Gate Name', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Associated Controller', 'fsbhoa-ac' ); ?></th>
                    <th><?php esc_html_e( 'Slot', 'fsbhoa-ac' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty($gates) ) : foreach ( $gates as $gate ) : ?>
                    <tr>
                        <td class="fsbhoa-actions-column">
                            <?php
                            $edit_url = add_query_arg(['view' => 'gates', 'action' => 'edit', 'gate_id' => absint($gate['door_record_id'])], $current_page_url);
                            $delete_nonce = wp_create_nonce('fsbhoa_delete_gate_nonce_' . $gate['door_record_id']);
                            $delete_url = add_query_arg(['action'=> 'fsbhoa_delete_gate', 'gate_id' => absint($gate['door_record_id']), '_wpnonce'=> $delete_nonce], admin_url('admin-post.php'));
                            ?>
                            <a href="<?php echo esc_url($edit_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Edit Gate', 'fsbhoa-ac'); ?>"><span class="dashicons dashicons-edit"></span></a>
                            <a href="<?php echo esc_url($delete_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Delete Gate', 'fsbhoa-ac'); ?>" onclick="return confirm('Are you sure?');"><span class="dashicons dashicons-trash"></span></a>
                        </td>
                        <td><strong><?php echo esc_html( $gate['friendly_name'] ); ?></strong></td>
                        <td><?php echo esc_html( $gate['controller_name'] ?? 'N/A' ); ?></td>
                        <td><?php echo esc_html( $gate['door_number_on_controller'] ); ?></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="4"><?php esc_html_e( 'No gates found.', 'fsbhoa-ac' ); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}


