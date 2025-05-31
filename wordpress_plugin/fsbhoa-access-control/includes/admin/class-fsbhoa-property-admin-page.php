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
     * Handles the display of the property admin page, routing to list or form.
     *
     * @since 0.1.4 
     */
    public function render_page() {
        // Determine the action from GET request.
        // We use $_REQUEST for 'action' and 'property_id' initially to catch them from GET.
        // The $action variable will be 'add', 'edit', or empty (for list view).
        $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : ''; 
        
        if ('add' === $action || 'edit' === $action) {
            $this->render_add_new_property_form($action);
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
        $property_list_table = new Fsbhoa_Property_List_Table();
        $property_list_table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Property Management', 'fsbhoa-ac' ); ?></h1>
            
            <a href="?page=fsbhoa_ac_properties&action=add" class="page-title-action">
                <?php echo esc_html__( 'Add New Property', 'fsbhoa-ac' ); ?>
            </a>

            <form method="post">
                <input type="hidden" name="page" value="<?php echo esc_attr( sanitize_text_field(wp_unslash($_REQUEST['page'])) ); ?>" />
                <?php
                $property_list_table->display();
                ?>
            </form>
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
        $is_edit_mode = false;

        // Determine if we are in edit mode based on GET parameters for initial form load
        if ($current_view_action === 'edit' && isset($_GET['property_id'])) {
            $item_id = absint($_GET['property_id']);
            if ($item_id > 0) {
                $property_to_edit = $wpdb->get_row(
                    $wpdb->prepare("SELECT street_address, notes FROM {$table_name} WHERE property_id = %d", $item_id),
                    ARRAY_A
                );

                if ($property_to_edit) {
                    $form_data = $property_to_edit; // Pre-populate form data with existing values
                    $is_edit_mode = true; // This flag is for rendering the form in edit mode
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
            // Determine action from POST (hidden field or submit button name)
            $posted_action = isset($_POST['action_type']) ? sanitize_key($_POST['action_type']) : ''; // Hidden field 'action_type'
            $property_id_from_post = isset($_POST['property_id']) ? absint($_POST['property_id']) : 0; // From hidden field if editing

            // Common data sanitization from POST
            $form_data['street_address'] = isset($_POST['street_address']) ? trim(sanitize_text_field(wp_unslash($_POST['street_address']))) : '';
            $form_data['notes']          = isset($_POST['notes']) ? trim(sanitize_textarea_field(wp_unslash($_POST['notes']))) : '';
            
            $errors = array(); // Reset errors for POST processing

            // --- UPDATE LOGIC ---
            if ($posted_action === 'update_property' && isset($_POST['submit_update_property']) && $property_id_from_post > 0) {
                $is_edit_mode = true; // Confirm we are in an update context based on POST
                $item_id = $property_id_from_post; // Use ID from POST for security

                if (isset($_POST['fsbhoa_update_property_nonce']) && 
                    wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_update_property_nonce'])), 'fsbhoa_update_property_action_' . $item_id)) {
                    
                    // Validate Street Address for Update
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
                        $data_to_update = array(
                            'street_address' => $form_data['street_address'],
                            'notes'          => $form_data['notes'],
                        );
                        $where = array('property_id' => $item_id);
                        $result = $wpdb->update($table_name, $data_to_update, $where, array('%s', '%s'), array('%d'));

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
            } elseif ($posted_action === 'add_property' && isset($_POST['submit_add_property'])) {
                // $is_edit_mode should be false here if $current_view_action was 'add'
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
                        $data_to_insert = array(
                            'street_address' => $form_data['street_address'],
                            'notes'          => $form_data['notes'],
                        );
                        $result = $wpdb->insert($table_name, $data_to_insert, array('%s', '%s'));

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
            } // End add/update POST type check

            // Common error display if any validation above populated $errors
            if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST') { // Ensure we only show POST errors if they exist
                echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Please correct the errors below and try again:', 'fsbhoa-ac') . '</p><ul>';
                foreach ($errors as $error) {
                   echo '<li>' . esc_html($error) . '</li>';
                }
                echo '</ul></div>';
            }
        } // --- End POST Request Handling ---


        // Determine Page Title and Submit Button Text for form rendering
        // This $is_edit_mode is based on the initial GET request (or if POST was an update attempt)
        // Re-evaluate $is_edit_mode for rendering based on whether $item_id is set AND an actual record was loaded
        // (or if it's a POST update submission that might have failed validation but should still show edit form)
        $render_as_edit = ($current_view_action === 'edit' && !empty($item_id));
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_update_property']) && !empty($_POST['property_id'])) {
            $render_as_edit = true; // If it was an update POST, ensure form renders as edit
            if (empty($item_id)) $item_id = absint($_POST['property_id']); // Ensure item_id is set for nonce generation
        }


        $page_title = $render_as_edit ? __( 'Edit Property', 'fsbhoa-ac' ) : __( 'Add New Property', 'fsbhoa-ac' );
        $submit_button_text = $render_as_edit ? __( 'Update Property', 'fsbhoa-ac' ) : __( 'Add Property', 'fsbhoa-ac' );
        $submit_button_name = $render_as_edit ? 'submit_update_property' : 'submit_add_property';
        $nonce_action = $render_as_edit ? ('fsbhoa_update_property_action_' . $item_id) : 'fsbhoa_add_property_action';
        $nonce_name   = $render_as_edit ? 'fsbhoa_update_property_nonce' : 'fsbhoa_add_property_nonce';
        $hidden_action_type = $render_as_edit ? 'update_property' : 'add_property';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>

            <form method="POST" action=""> 
                <input type="hidden" name="action_type" value="<?php echo esc_attr($hidden_action_type); ?>" />
                <?php if ($render_as_edit && $item_id) : ?>
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

