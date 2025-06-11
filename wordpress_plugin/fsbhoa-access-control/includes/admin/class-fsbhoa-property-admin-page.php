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

   // Constructor to hook actions
    public function __construct() {
        // Hook for handling the delete property action
        add_action('admin_post_fsbhoa_delete_property', array($this, 'handle_delete_property_action'));
    }

public function handle_delete_property_action() {

        if (!isset($_GET['property_id']) || !is_numeric($_GET['property_id'])) {
            // ... (error handling for invalid property_id) ...
            wp_die(esc_html__('Invalid property ID for deletion.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 400, 'back_link' => true));
        }
        $item_id_to_delete = absint($_GET['property_id']);

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'fsbhoa_delete_property_nonce_' . $item_id_to_delete)) {
            // ... (error handling for nonce failure) ...
            wp_die(esc_html__('Security check failed. Could not delete property.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }

        global $wpdb;
        $table_name = 'ac_property';
        $result = $wpdb->delete( $table_name, array('property_id' => $item_id_to_delete), array('%d') );

        // --- Construct base redirect URL ---
        $base_redirect_url = admin_url('admin.php');
        $base_redirect_url = add_query_arg(array('page' => 'fsbhoa_ac_properties'), $base_redirect_url);

        $final_redirect_url = ''; 

        if ($result === false) {
            $final_redirect_url = add_query_arg(array('message' => 'delete_error'), $base_redirect_url);
        } elseif ($result === 0) {
            $final_redirect_url = add_query_arg(array('message' => 'delete_not_found'), $base_redirect_url);
        } else { 
            $final_redirect_url = add_query_arg(array('message' => 'deleted_successfully', 'deleted_id' => $item_id_to_delete), $base_redirect_url);
        }
        
        
        if (empty($final_redirect_url) || !is_string($final_redirect_url)) { // Added !is_string check
            $final_redirect_url = admin_url('admin.php?page=fsbhoa_ac_properties'); // More robust fallback
        }

        // Use esc_url_raw for wp_redirect for safety, though add_query_arg usually handles encoding.
        wp_redirect(esc_url_raw($final_redirect_url));
        exit;
    }


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
     * Renders the list of properties using WP_List_Table.
     *
     * @since 0.1.5 
     */
    public function render_property_list_page() {
        // Use the static method from the list table class to fetch data
        $properties = class_exists('Fsbhoa_Property_List_Table') ? Fsbhoa_Property_List_Table::get_properties(999, 1) : array();
        $current_page_url = get_permalink();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Property Management', 'fsbhoa-ac' ); ?></h1>

            <a href="<?php echo esc_url( add_query_arg('action', 'add', $current_page_url) ); ?>" class="page-title-action">
                <?php echo esc_html__( 'Add New Property', 'fsbhoa-ac' ); ?>
            </a>

            <table id="fsbhoa-property-table" class="display" style="width:100%; margin-top: 20px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Street Address', 'fsbhoa-ac' ); ?></th>
                        <th><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></th>
                        <th class="no-sort"><?php esc_html_e( 'Actions', 'fsbhoa-ac' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty($properties) ) : foreach ( $properties as $property ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $property['street_address'] ); ?></strong></td>
                            <td><?php echo esc_html( $property['notes'] ); ?></td>
                            <td>
                                <?php
                                $edit_url = add_query_arg(array('action' => 'edit', 'property_id' => absint($property['property_id'])), $current_page_url);
                                $delete_nonce = wp_create_nonce('fsbhoa_delete_property_nonce_' . $property['property_id']);
                                $delete_url = add_query_arg(array('action'=> 'fsbhoa_delete_property', 'property_id' => absint($property['property_id']), '_wpnonce'=> $delete_nonce), admin_url('admin-post.php'));
                                ?>
                                <a href="<?php echo esc_url($edit_url); ?>">Edit</a> |
                                <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('Are you sure you want to delete this property?');" style="color:#a00;">Delete</a>
                            </td>
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
        global $wpdb;
        $table_name = 'ac_property';

        $form_data = array(
            'street_address' => '',
            'notes'          => '',
        );
        $errors = array();
        $item_id = null; 
        $is_edit_mode_for_form_render = false; // For form structure (button text, title)

        // Determine if we are populating the form for editing based on GET parameters
        if ($current_view_action === 'edit' && isset($_GET['property_id'])) {
            $item_id = absint($_GET['property_id']);
            if ($item_id > 0) {
                $property_to_edit = $wpdb->get_row(
                    $wpdb->prepare("SELECT street_address, notes FROM {$table_name} WHERE property_id = %d", $item_id),
                    ARRAY_A
                );

                if ($property_to_edit) {
                    $form_data = $property_to_edit; 
                    $is_edit_mode_for_form_render = true; 
                } else {
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error: Property not found for editing.', 'fsbhoa-ac') . '</p></div>';
                    return; 
                }
            } else {
                 echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error: Invalid Property ID for editing.', 'fsbhoa-ac') . '</p></div>';
                 return;
            }
        }

        // --- POST Request Handling ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $posted_action_type = isset($_POST['action_type']) ? sanitize_key($_POST['action_type']) : '';
            $property_id_from_post = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0;

            // Always populate $form_data with submitted values for sticky fields and validation
            $form_data['street_address'] = isset($_POST['street_address']) ? trim(sanitize_text_field(wp_unslash($_POST['street_address']))) : '';
            $form_data['notes']          = isset($_POST['notes']) ? trim(sanitize_textarea_field(wp_unslash($_POST['notes']))) : '';
            
            $errors = array(); 

            // --- UPDATE LOGIC ---
            if ($posted_action_type === 'update_property' && isset($_POST['submit_update_property']) && $property_id_from_post > 0) {
                // Ensure $item_id for update context is from POST, and set $is_edit_mode_for_form_render for potential re-display
                $item_id = $property_id_from_post;
                $is_edit_mode_for_form_render = true; 

                if (isset($_POST['fsbhoa_update_property_nonce']) && 
                    wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_update_property_nonce'])), 'fsbhoa_update_property_action_' . $item_id)) {
                    
                    if (empty($form_data['street_address'])) {
                        $errors['street_address'] = __( 'Street Address is required.', 'fsbhoa-ac' );
                    } elseif (strlen($form_data['street_address']) > 200) {
                        $errors['street_address'] = __( 'Street Address is too long (max 200 characters).', 'fsbhoa-ac' );
                    } else {
                        $existing_property_with_address = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT property_id FROM {$table_name} WHERE street_address = %s AND property_id != %d",
                                $form_data['street_address'],
                                $item_id
                            )
                        );
                        if ($existing_property_with_address) {
                            $errors['street_address'] = __( 'This Street Address is already in use by another property.', 'fsbhoa-ac' );
                        }
                    }

                    if (empty($errors)) {
                        $result = $wpdb->update($table_name, 
                                               array('street_address' => $form_data['street_address'], 'notes' => $form_data['notes']), 
                                               array('property_id' => $item_id), 
                                               array('%s', '%s'), 
                                               array('%d'));

                        if ($result === false) {
                            echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error updating property data. Please try again.', 'fsbhoa-ac') . '</p></div>';
                        } elseif ($result === 0) {
                            echo '<div id="message" class="updated notice is-dismissible"><p>' . esc_html__('No changes detected for the property.', 'fsbhoa-ac') . '</p></div>';
                        } else {
                            echo '<div id="message" class="updated notice is-dismissible"><p>' . sprintf(
                                esc_html__('Property "%1$s" updated successfully!', 'fsbhoa-ac'),
                                esc_html($form_data['street_address'])
                            ) . '</p></div>';
                        }
                    }
                } else {
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Security check failed for update. Please try again.', 'fsbhoa-ac') . '</p></div>';
                    $errors['security'] = 'Nonce failed';
                }
            
            // --- ADD LOGIC ---
            } elseif ($posted_action_type === 'add_property' && isset($_POST['submit_add_property'])) {
                 $is_edit_mode_for_form_render = false; // Ensure form renders as "add" if add POST fails

                if (isset($_POST['fsbhoa_add_property_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_property_nonce'])), 'fsbhoa_add_property_action')) {
                    if (empty($form_data['street_address'])) {
                        $errors['street_address'] = __( 'Street Address is required.', 'fsbhoa-ac' );
                    } elseif (strlen($form_data['street_address']) > 200) {
                        $errors['street_address'] = __( 'Street Address is too long (max 200 characters).', 'fsbhoa-ac' );
                    } else {
                        $existing_property = $wpdb->get_var( $wpdb->prepare( "SELECT property_id FROM {$table_name} WHERE street_address = %s", $form_data['street_address'] ) );
                        if ($existing_property) {
                            $errors['street_address'] = __( 'This Street Address already exists in the system.', 'fsbhoa-ac' );
                        }
                    }

                    if (empty($errors)) {
                        $result = $wpdb->insert($table_name, array('street_address' => $form_data['street_address'], 'notes' => $form_data['notes']), array('%s', '%s'));
                        if ($result === false) {
                            $db_error = $wpdb->last_error;
                            $user_message = esc_html__('Error saving property data. Please try again.', 'fsbhoa-ac');
                            if (stripos($db_error, 'Duplicate entry') !== false && stripos($db_error, 'idx_street_address_unique') !== false) {
                                $user_message = esc_html__('Error: This Street Address already exists (DB constraint).', 'fsbhoa-ac');
                            }
                            echo '<div id="message" class="error notice is-dismissible"><p>' . $user_message . '</p></div>';
                        } else {
                            echo '<div id="message" class="updated notice is-dismissible"><p>' . sprintf(
                                esc_html__('Property "%1$s" added successfully! Record ID: %2$d', 'fsbhoa-ac'),
                                esc_html($form_data['street_address']),
                                $wpdb->insert_id
                            ) . '</p></div>';
                            $form_data = array_fill_keys(array_keys($form_data), ''); 
                        }
                    }
                } else {
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Security check failed for add. Please try again.', 'fsbhoa-ac') . '</p></div>';
                    $errors['security'] = 'Nonce failed';
                }
            } 

            if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST') {
                echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Please correct the errors below and try again:', 'fsbhoa-ac') . '</p><ul>';
                foreach ($errors as $error_message_text) { // Changed variable name to avoid conflict
                   echo '<li>' . esc_html($error_message_text) . '</li>';
                }
                echo '</ul></div>';
            }
        } // --- End POST Request Handling ---

        // Determine Page Title and Submit Button Text for actual form rendering
        $page_title = $is_edit_mode_for_form_render ? __( 'Edit Property', 'fsbhoa-ac' ) : __( 'Add New Property', 'fsbhoa-ac' );
        $submit_button_text = $is_edit_mode_for_form_render ? __( 'Update Property', 'fsbhoa-ac' ) : __( 'Add Property', 'fsbhoa-ac' );
        $submit_button_name = $is_edit_mode_for_form_render ? 'submit_update_property' : 'submit_add_property';
        
        // Set $item_id correctly for nonce generation if we are in edit mode for rendering
        // $item_id was set during GET for edit, or from POST for update.
        // If it's an add form display after a failed add POST, $item_id should be null.
        $current_item_id_for_nonce = ($is_edit_mode_for_form_render && !empty($item_id)) ? $item_id : 0;

        $nonce_action = $is_edit_mode_for_form_render ? ('fsbhoa_update_property_action_' . $current_item_id_for_nonce) : 'fsbhoa_add_property_action';
        $nonce_name   = $is_edit_mode_for_form_render ? 'fsbhoa_update_property_nonce' : 'fsbhoa_add_property_nonce';
        $hidden_action_type = $is_edit_mode_for_form_render ? 'update_property' : 'add_property';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>

            <form method="POST" action=""> 
                <input type="hidden" name="action_type" value="<?php echo esc_attr($hidden_action_type); ?>" />
                <?php if ($is_edit_mode_for_form_render && !empty($item_id)) : ?>
                    <input type="hidden" name="property_id" value="<?php echo esc_attr($item_id); ?>" />
                <?php endif; ?>
                <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="street_address"><?php esc_html_e( 'Street Address', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <input type="text" name="street_address" id="street_address" class="regular-text" 
                                       value="<?php echo esc_attr($form_data['street_address']); ?>" required>
                                <p class="description"><?php esc_html_e( 'E.g., 123 Main St, Unit 4B. This must be unique.', 'fsbhoa-ac' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="notes"><?php esc_html_e( 'Notes', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <textarea name="notes" id="notes" rows="5" class="large-text"><?php echo esc_textarea($form_data['notes']); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Optional notes about the property.', 'fsbhoa-ac' ); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( $submit_button_text, 'primary', $submit_button_name ); ?>
            </form>
            <p><a href="?page=fsbhoa_ac_properties"><?php esc_html_e( '&larr; Back to Properties List', 'fsbhoa-ac' ); ?></a></p>
        </div>
        <?php
    }
} // end class Fsbhoa_Property_Admin_Page
?>
