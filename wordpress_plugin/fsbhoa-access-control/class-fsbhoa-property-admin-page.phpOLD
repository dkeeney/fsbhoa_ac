<?php
/**
 * Handles the admin page for Property management.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Property_Admin_Page {


    /**
     * Handles the display of the property admin page (list, add, edit forms).
     * Delete logic has been moved to handle_delete_property_action().
     *
     * @since 0.1.6 
     */
    public function render_page() {
        // The delete logic is now GONE from here.

        error_log('FSBHOA DEBUG: render_page() called. $_GET array: ' . print_r($_GET, true));

        $action_for_view = isset($_GET['action']) ? sanitize_key($_GET['action']) : ''; 
        
        // Display messages on the list page (after redirect from delete, or other actions)
        if (empty($action_for_view) && isset($_GET['message'])) {
            $message_code = sanitize_key($_GET['message']);

            // for DEBUGGING
            error_log('FSBHOA DEBUG: Message code received on property list page: "' . $message_code . '"'); 

            $deleted_id = isset($_GET['deleted_id']) ? absint($_GET['deleted_id']) : 0;
            $message_text = '';

            switch ($message_code) {
                case 'property_added':
                    $message_text = esc_html__('Property added successfully.', 'fsbhoa-ac');
                    break;
                case 'property_updated':
                    $message_text = esc_html__('Property updated successfully.', 'fsbhoa-ac');
                    break;

                case 'deleted_successfully':
                    $message_text = sprintf(esc_html__('Property (ID: %d) deleted successfully.', 'fsbhoa-ac'), $deleted_id);
                    echo '<div id="message" class="updated notice is-dismissible"><p>' . $message_text . '</p></div>';
                    break;
                case 'delete_error':
                    $message_text = esc_html__('Error deleting property. Please try again.', 'fsbhoa-ac');
                    echo '<div id="message" class="error notice is-dismissible"><p>' . $message_text . '</p></div>';
                    break;
                case 'delete_not_found':
                    $message_text = esc_html__('Property not found or already deleted.', 'fsbhoa-ac');
                    echo '<div id="message" class="notice notice-warning is-dismissible"><p>' . $message_text . '</p></div>';
                    break;
                // You can add other message codes here if needed from other actions
            }
        }
        else
            error_log('FSBHOA DEBUG: render_page() called: in else block ' . print_r($_GET, true));
        
        if ('add' === $action_for_view || 'edit' === $action_for_view) {
            $this->render_add_new_property_form($action_for_view);
        } else {
            $this->render_property_list_page();
        }
    }

    /**
     * Renders the list of properties using a DataTables-ready HTML structure,
     * matching the cardholder list view.
     */
    public function render_property_list_page() {
        // We get the data using our static method from the old List Table class.
        $properties = class_exists('Fsbhoa_Property_List_Table') ? Fsbhoa_Property_List_Table::get_properties(999, 1) : array();
        $current_page_url = get_permalink();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Property Management', 'fsbhoa-ac' ); ?></h1>

            <div class="fsbhoa-table-controls">
                <a href="<?php echo esc_url( add_query_arg('action', 'add', $current_page_url) ); ?>" class="button button-primary">
                    <?php echo esc_html__( 'Add New Property', 'fsbhoa-ac' ); ?>
                </a>

                <div class="fsbhoa-table-right-controls">
                    <div class="fsbhoa-control-group">
                        <label for="fsbhoa-property-length-menu">Show</label>
                        <select name="fsbhoa-property-length-menu" id="fsbhoa-property-length-menu">
                            <option value="100">100</option>
                            <option value="250">250</option>
                            <option value="500">500</option>
                            <option value="-1">All</option>
                        </select>
                        <span>entries</span>
                    </div>
                    <div class="fsbhoa-control-group">
                        <label for="fsbhoa-property-search-input">Search:</label>
                        <input type="search" id="fsbhoa-property-search-input" placeholder="Search...">
                    </div>
                </div>
            </div>

            <table id="fsbhoa-property-table" class="display" style="width:100%">
                <thead>
                    <tr>
                        <th class="no-sort fsbhoa-actions-column"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Street Address', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty($properties) ) : foreach ( $properties as $property ) : ?>
                        <tr>
                            <td class="fsbhoa-actions-column">
                                <?php
                                $edit_url = add_query_arg(array('action' => 'edit', 'property_id' => absint($property['property_id'])), $current_page_url);
                                $delete_nonce = wp_create_nonce('fsbhoa_delete_property_nonce_' . $property['property_id']);
                                $delete_url = add_query_arg(array('action'=> 'fsbhoa_delete_property', 'property_id' => absint($property['property_id']), '_wpnonce'=> $delete_nonce), admin_url('admin-post.php'));
                                ?>
                                <a href="<?php echo esc_url($edit_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Edit Property', 'fsbhoa-ac'); ?>">
                                    <span class="dashicons dashicons-edit"></span>
                                </a>
                                <a href="<?php echo esc_url($delete_url); ?>" class="fsbhoa-action-icon" title="<?php esc_attr_e('Delete Property', 'fsbhoa-ac'); ?>" onclick="return confirm('Are you sure you want to delete this property?');">
                                    <span class="dashicons dashicons-trash"></span>
                                </a>
                            </td>
                            <td><strong><?php echo esc_html( $property['street_address'] ); ?></strong></td>
                            <td><?php echo esc_html( $property['notes'] ); ?></td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="3"><?php esc_html_e( 'No properties found.', 'fsbhoa-ac' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Renders the form for adding or editing a property.
     * Includes validation and database insertion/update logic.
     *
     * @since 0.1.5 
     * @param string $current_view_action The action determining the view ('add' or 'edit' from GET).
     */
public function render_add_new_property_form($current_view_action = 'add') {
        // Ensure the submit_button() function is available when run from a shortcode
        if ( ! function_exists( 'submit_button' ) ) {
            require_once ABSPATH . 'wp-admin/includes/template.php';
        }

        global $wpdb;
        $table_name = 'ac_property';
        $form_data = array('street_address' => '', 'notes' => '');
        $item_id = null;
        $is_edit_mode = ($current_view_action === 'edit' && isset($_GET['property_id']));

        if ($is_edit_mode) {
            $item_id = absint($_GET['property_id']);
            $property_to_edit = $wpdb->get_row($wpdb->prepare("SELECT street_address, notes FROM {$table_name} WHERE property_id = %d", $item_id), ARRAY_A);
            
            if ($wpdb->last_error) {
                error_log('FSBHOA DB Error (Get Property for Edit): ' . $wpdb->last_error);
                wp_die('A database error occurred while retrieving property details. Please go back and try again.');
            }

            if ($property_to_edit) {
                $form_data = $property_to_edit;
            } else {
                wp_die('Error: The property you are trying to edit could not be found. It may have been deleted.');
            }
        }

        $page_title = $is_edit_mode ? 'Edit Property' : 'Add New Property';
        $submit_button_text = $is_edit_mode ? 'Update Property' : 'Add Property';
        $form_action = $is_edit_mode ? 'fsbhoa_update_property' : 'fsbhoa_add_property';
        $nonce_action = $is_edit_mode ? 'fsbhoa_update_property_action_' . $item_id : 'fsbhoa_add_property_action';
        $properties_list_url = add_query_arg('view', 'properties', get_permalink());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($page_title); ?></h1>

            <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>" />
                <?php if ($is_edit_mode) : ?>
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($item_id); ?>" />
                <?php endif; ?>
                <?php wp_nonce_field($nonce_action, 'fsbhoa_property_nonce'); ?>

                <div class="fsbhoa-form-section">
                    <div class="form-row">
                        <div class="form-field" style="flex-basis: 100%;">
                            <label for="street_address"><?php esc_html_e( 'Street Address', 'fsbhoa-ac' ); ?></label>
                            <input type="text" name="street_address" id="street_address" class="regular-text"
                                   value="<?php echo esc_attr($form_data['street_address']); ?>" required>
                            <p class="description"><?php esc_html_e( 'Example: 123 Main St. This must be unique.', 'fsbhoa-ac' ); ?></p>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-field" style="flex-basis: 100%;">
                            <label for="notes"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></label>
                            <textarea name="notes" id="notes" rows="5" class="large-text"><?php echo esc_textarea($form_data['notes']); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Optional notes about the property.', 'fsbhoa-ac' ); ?></p>
                        </div>
                    </div>
                </div>
                <?php submit_button($submit_button_text); ?>
            </form>
            <p><a href="<?php echo esc_url($properties_list_url); ?>"><?php esc_html_e('&larr; Back to Properties List', 'fsbhoa-ac'); ?></a></p>
        </div>
        <?php
    }
} // end class Fsbhoa_Property_Admin_Page
?>
