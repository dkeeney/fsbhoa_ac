<?php
/**
 * Handles the admin page for Cardholder management.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Cardholder_Admin_Page {

    /**
     * Constructor.
     * Hooks into WordPress actions.
     *
     * @since 0.1.7 
     */
    public function __construct() {
        // AJAX hook for property search (used in the add/edit cardholder form)
        add_action('wp_ajax_fsbhoa_search_properties', array($this, 'ajax_search_properties_callback'));
        
        // Hook for handling the delete cardholder action
        add_action('admin_post_fsbhoa_delete_cardholder', array($this, 'handle_delete_cardholder_action'));
    }

/**
     * Handles the actual deletion of a cardholder.
     * This function is hooked to 'admin_post_fsbhoa_delete_cardholder'.
     *
     * @since 0.1.7
     */
    public function handle_delete_cardholder_action() {

        if (!isset($_GET['cardholder_id']) || !is_numeric($_GET['cardholder_id'])) {
            error_log('FSBHOA DEBUG CH: Invalid or missing cardholder_id in _GET for delete.');
            wp_die(esc_html__('Invalid cardholder ID for deletion.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 400, 'back_link' => true));
        }
        $item_id_to_delete = absint($_GET['cardholder_id']);

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!wp_verify_nonce($nonce, 'fsbhoa_delete_cardholder_nonce_' . $item_id_to_delete)) {
            error_log('FSBHOA DEBUG CH: Nonce verification FAILED for fsbhoa_delete_cardholder_nonce_' . $item_id_to_delete);
            wp_die(esc_html__('Security check failed. Could not delete cardholder.', 'fsbhoa-ac'), esc_html__('Error', 'fsbhoa-ac'), array('response' => 403, 'back_link' => true));
        }

        global $wpdb;
        $table_name = 'ac_cardholders'; 
        $result = $wpdb->delete(
            $table_name,
            array('id' => $item_id_to_delete),
            array('%d') 
        );

        // Construct base redirect URL (this method worked for Properties)
        $base_redirect_url = admin_url('admin.php');
        $base_redirect_url = add_query_arg(array('page' => 'fsbhoa_ac_cardholders'), $base_redirect_url);

        $final_redirect_url = ''; 

        if ($result === false) {
            $final_redirect_url = add_query_arg(array('message' => 'cardholder_delete_error'), $base_redirect_url);
        } elseif ($result === 0) {
            $final_redirect_url = add_query_arg(array('message' => 'cardholder_delete_not_found'), $base_redirect_url);
        } else { 
            $final_redirect_url = add_query_arg(array('message' => 'cardholder_deleted_successfully', 'deleted_id' => $item_id_to_delete), $base_redirect_url);
        }
        
        
        if (empty($final_redirect_url) || !is_string($final_redirect_url)) {
            $final_redirect_url = admin_url('admin.php?page=fsbhoa_ac_cardholders'); 
        }

        wp_redirect(esc_url_raw($final_redirect_url));
        exit;
    }

    /**
     * AJAX callback to search properties.
     * Outputs JSON.
     * @since 0.1.5
     */
    public function ajax_search_properties_callback() {
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');
        global $wpdb;
        $table_name = 'ac_property';
        $search_term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $results = array();
        if (strlen($search_term) >= 1) {
            $wildcard_search_term = '%' . $wpdb->esc_like($search_term) . '%';
            // In WP_List_Table for cardholders, we selected p.street_address and c.property_id
            // Here we need property_id and street_address for the autocomplete
            $properties = $wpdb->get_results( 
                $wpdb->prepare( 
                    "SELECT property_id, street_address FROM {$table_name} WHERE street_address LIKE %s ORDER BY street_address ASC LIMIT 20", 
                    $wildcard_search_term 
                ), ARRAY_A // Changed to ARRAY_A to match usage if any, or can be OBJECT
            );
            if ($properties) {
                foreach ($properties as $property) {
                    // jQuery UI Autocomplete usually expects 'label' and 'value', 'id' is custom for us.
                    $results[] = array( 
                        'id'    => $property['property_id'], 
                        'label' => $property['street_address'], 
                        'value' => $property['street_address'] 
                    );
                }
            }
        }
        wp_send_json_success($results);
    }

    /**
     * Handles the display of the cardholder admin page, routing to list or form.
     * @since 0.1.3 (Updated 0.1.7 for edit_cardholder action name)
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : ''; 
        
        if ('add' === $action || 'edit_cardholder' === $action ) { // Use 'edit_cardholder' for clarity
            $this->render_add_new_cardholder_form($action);
        } else {
            $this->render_cardholders_list_page();
        }
    }

    /**
     * Renders the list of cardholders using WP_List_Table.
     * Includes logic to display action feedback messages.
     *
     * @since 0.1.6 (Updated 0.1.7 for messages)
     */
    public function render_cardholders_list_page() {
        // Create an instance of our package class...
        $cardholder_list_table = new Fsbhoa_Cardholder_List_Table();
        // Fetch, prepare, sort, and filter our data...
        $cardholder_list_table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>
            
            <a href="?page=fsbhoa_ac_cardholders&action=add" class="page-title-action">
                <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
            </a>

            <?php // --- Display Messages for Cardholder Actions ---
            if (isset($_GET['message'])) {
                $message_code = sanitize_key($_GET['message']);
                // Use 'processed_id' to handle IDs from various actions (delete, update, add)
                $processed_id = 0;
                if (isset($_GET['deleted_id'])) {
                    $processed_id = absint($_GET['deleted_id']);
                } elseif (isset($_GET['updated_id'])) { // For future edit functionality
                    $processed_id = absint($_GET['updated_id']);
                } elseif (isset($_GET['added_id'])) { // If add form redirects here with an ID
                     $processed_id = absint($_GET['added_id']);
                }
                
                $message_text = ''; // Initialize message text
                $notice_class = ''; // Initialize notice class

                switch ($message_code) {
                    case 'cardholder_deleted_successfully':
                        $message_text = sprintf(esc_html__('Cardholder (ID: %d) deleted successfully.', 'fsbhoa-ac'), $processed_id);
                        $notice_class = 'updated'; // Green success
                        break;
                    case 'cardholder_delete_error':
                        $message_text = esc_html__('Error deleting cardholder. Please try again.', 'fsbhoa-ac');
                        $notice_class = 'error'; // Red error
                        break;
                    case 'cardholder_delete_not_found':
                        $message_text = esc_html__('Cardholder not found or already deleted.', 'fsbhoa-ac');
                        $notice_class = 'notice-warning'; // Yellow warning
                        break;
                    // Add cases for 'cardholder_added_successfully' and 'cardholder_updated_successfully' here
                    // when the add/edit forms redirect back to this list page with such messages.
                    // Example:
                    // case 'cardholder_added_successfully':
                    //     $message_text = sprintf(esc_html__('Cardholder (ID: %d) added successfully.', 'fsbhoa-ac'), $processed_id);
                    //     $notice_class = 'updated';
                    //     break;
                }

                if (!empty($message_text) && !empty($notice_class)) {
                    echo '<div id="message" class="' . esc_attr($notice_class) . ' notice is-dismissible"><p>' . $message_text . '</p></div>';
                }
            }
            // --- END Display Messages ---
            ?>

            <form method="post">
                <?php // For plugins, we also need to ensure that the form posts back to our current page for bulk actions ?>
                <input type="hidden" name="page" value="<?php echo esc_attr( isset($_REQUEST['page']) ? sanitize_text_field(wp_unslash($_REQUEST['page'])) : '' ); ?>" />
                <?php
                // Now we can render the completed list table
                $cardholder_list_table->display();
                ?>
            </form>
        </div>
        <?php
    }

/**
     * Renders the form for adding or editing a cardholder.
     * Includes validation and database insertion/update logic.
     *
     * @since 0.1.7 (Updated for Edit Cardholder functionality)
     * @param string $current_page_action The action determining the view ('add' or 'edit_cardholder' from GET).
     */
    public function render_add_new_cardholder_form($current_page_action = 'add') {
        global $wpdb;
        $cardholder_table_name = 'ac_cardholders';
        $property_table_name = 'ac_property';

        $form_data = array(
            'first_name'    => '', 'last_name'     => '',
            'email'         => '', 'phone'         => '',
            'phone_type'    => '', 'resident_type' => '',
            'property_id'   => '', 
            'property_address_display' => '' // For displaying current property address in edit mode
        );
        $errors = array();
        $item_id_for_edit = null; 
        $is_edit_mode = ($current_page_action === 'edit_cardholder' && isset($_GET['cardholder_id']));

        // Define allowed types for dropdowns (used in validation and form rendering)
        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other');
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Other');

        // If in edit mode, fetch existing data for the cardholder
        if ($is_edit_mode) {
            $item_id_for_edit = absint($_GET['cardholder_id']);
            if ($item_id_for_edit > 0) {
                $cardholder_to_edit = $wpdb->get_row(
                    $wpdb->prepare("SELECT * FROM {$cardholder_table_name} WHERE id = %d", $item_id_for_edit), 
                    ARRAY_A
                );

                if ($cardholder_to_edit) {
                    // Pre-populate form_data with existing values
                    $form_data['first_name']    = $cardholder_to_edit['first_name'];
                    $form_data['last_name']     = $cardholder_to_edit['last_name'];
                    $form_data['email']         = $cardholder_to_edit['email'];
                    $form_data['phone']         = $cardholder_to_edit['phone']; // DB stores digits
                    $form_data['phone_type']    = $cardholder_to_edit['phone_type'];
                    $form_data['resident_type'] = $cardholder_to_edit['resident_type'];
                    $form_data['property_id']   = $cardholder_to_edit['property_id'];

                    // If there's a property_id, fetch its street_address for display
                    if (!empty($form_data['property_id'])) {
                        $property_address = $wpdb->get_var(
                            $wpdb->prepare("SELECT street_address FROM {$property_table_name} WHERE property_id = %d", $form_data['property_id'])
                        );
                        if ($property_address) {
                            $form_data['property_address_display'] = $property_address;
                        }
                    }
                } else {
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error: Cardholder not found for editing.', 'fsbhoa-ac') . '</p></div>';
                    return; // Stop form rendering
                }
            } else {
                 echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error: Invalid Cardholder ID for editing.', 'fsbhoa-ac') . '</p></div>';
                 return;
            }
        }

        // --- POST Request Handling ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Determine action from POST (hidden field or submit button name)
            $posted_action_type = isset($_POST['form_action_type']) ? sanitize_key($_POST['form_action_type']) : '';
            $posted_cardholder_id = isset($_POST['cardholder_id']) ? absint($_POST['cardholder_id']) : 0;

            // Repopulate $form_data with SUBMITTED values for validation and sticky form on error
            $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
            $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
            $form_data['email']         = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
            $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
            $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
            $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
            $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';
            // If property_address_display was submitted (it might not be if JS clears it), update form_data for sticky display
            $form_data['property_address_display'] = isset($_POST['property_address_display']) ? sanitize_text_field(wp_unslash($_POST['property_address_display'])) : '';


            $errors = array(); 

            // --- UPDATE LOGIC ---
            if ($posted_action_type === 'update_cardholder' && isset($_POST['submit_update_cardholder']) && $posted_cardholder_id > 0 && $is_edit_mode && $posted_cardholder_id === $item_id_for_edit) {

                if (isset($_POST['fsbhoa_update_cardholder_nonce']) &&
                    wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_update_cardholder_nonce'])), 'fsbhoa_update_cardholder_action_' . $item_id_for_edit)) {

                    // $form_data already contains the sanitized POSTed values from the top of the POST handling block.
                    // $errors was reset to an empty array just before this.

                    // ----- Perform ALL validations for update -----
                    if (empty($form_data['first_name'])) {
                        $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' );
                    } elseif (strlen($form_data['first_name']) > 100) {
                        $errors['first_name'] = __( 'First Name is too long (max 100 characters).', 'fsbhoa-ac' );
                    }

                    if (empty($form_data['last_name'])) {
                        $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' );
                    } elseif (strlen($form_data['last_name']) > 100) {
                        $errors['last_name'] = __( 'Last Name is too long (max 100 characters).', 'fsbhoa-ac' );
                    }

                    if (!empty($form_data['email']) && !is_email($form_data['email'])) {
                        $errors['email'] = __( 'Please enter a valid email address.', 'fsbhoa-ac' );
                    } elseif (strlen($form_data['email']) > 255) {
                        $errors['email'] = __( 'Email is too long (max 255 characters).', 'fsbhoa-ac' );
                    }

                    if (!empty($form_data['phone'])) {
                        $phone_regex = '/^(?:1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}$/';
                        if (!preg_match($phone_regex, $form_data['phone'])) {
                            $errors['phone'] = __( 'Please enter a valid phone number format.', 'fsbhoa-ac' );
                        } elseif (strlen($form_data['phone']) > 30) {
                             $errors['phone'] = __( 'Phone number is too long (max 30 characters).', 'fsbhoa-ac' );
                        }
                    }

                    if (!in_array($form_data['phone_type'], $allowed_phone_types)) { // $allowed_phone_types defined at top of function
                        $errors['phone_type'] = __('Invalid phone type selected.', 'fsbhoa-ac');
                    } elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) {
                        $errors['phone_type'] = __('Please select a phone type if a phone number is entered.', 'fsbhoa-ac');
                    }

                    if (!in_array($form_data['resident_type'], $allowed_resident_types)) { // $allowed_resident_types defined at top
                        $errors['resident_type'] = __('Invalid resident type selected.', 'fsbhoa-ac');
                    }
                    // Add required check for resident_type if needed:
                    // elseif (empty($form_data['resident_type'])) { $errors['resident_type'] = __('Resident type is required.', 'fsbhoa-ac'); }


                    if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) {
                        // This check assumes property_id from hidden field is numeric due to absint.
                        // A more robust check would ensure it's a valid ID from ac_property if a value is given.
                        // However, since it's populated by our autocomplete, this basic check is often sufficient.
                        $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac');
                    }
                    // ----- End of individual field validations -----


                    // Duplicate Check (Adjusted for Edit Mode - only if no other errors)
                    if (empty($errors)) {
                        $sql_prepare_args = array($form_data['first_name'], $form_data['last_name'], $item_id_for_edit);
                        $duplicate_sql = "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s AND id != %d";

                        $existing_cardholder = $wpdb->get_row($wpdb->prepare($duplicate_sql, $sql_prepare_args));
                        if ($existing_cardholder) {
                            $errors['duplicate'] = sprintf(__( 'Another cardholder named %1$s %2$s already exists (ID: %3$d).', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $existing_cardholder->id);
                        }
                    }

                    // If still no errors after all validations, proceed with update
                    if (empty($errors)) {
                        $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';

                        $data_to_update = array(
                            'first_name'    => $form_data['first_name'],
                            'last_name'     => $form_data['last_name'],
                            'email'         => $form_data['email'],
                            'phone'         => $phone_to_store,
                            'phone_type'    => $form_data['phone_type'],
                            'resident_type' => $form_data['resident_type'],
                            'property_id'   => !empty($form_data['property_id']) ? $form_data['property_id'] : null,
                            // updated_at will be handled by MySQL's ON UPDATE CURRENT_TIMESTAMP
                        );
                        $where = array('id' => $item_id_for_edit);

                        $data_formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%d'); // For data_to_update
                        $where_formats = array('%d'); // For where clause

                        $result = $wpdb->update($cardholder_table_name, $data_to_update, $where, $data_formats, $where_formats);

                        if ($result === false) {
                            echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error updating cardholder data. Please try again.', 'fsbhoa-ac') . '</p></div>';
                            // error_log('FSBHOA Cardholder Update Error: ' . $wpdb->last_error . ' for ID: ' . $item_id_for_edit);
                        } elseif ($result === 0) {
                            echo '<div id="message" class="updated notice is-dismissible"><p>' . esc_html__('No changes detected for the cardholder.', 'fsbhoa-ac') . '</p></div>';
                        } else {
                            echo '<div id="message" class="updated notice is-dismissible"><p>' . sprintf(
                                esc_html__('Cardholder %1$s %2$s updated successfully!', 'fsbhoa-ac'),
                                esc_html($form_data['first_name']), // Show the new name
                                esc_html($form_data['last_name'])
                            ) . '</p></div>';
                            // $form_data already holds the latest (now saved) values, so the form will display them.
                            // If we wanted to fetch the property_address_display again based on a changed property_id:
                            if (!empty($form_data['property_id'])) {
                                $property_address = $wpdb->get_var( $wpdb->prepare("SELECT street_address FROM {$property_table_name} WHERE property_id = %d", $form_data['property_id']) );
                                if ($property_address) { $form_data['property_address_display'] = $property_address; } else { $form_data['property_address_display'] = '';}
                            } else {
                                $form_data['property_address_display'] = '';
                            }
                        }
                    }
                    // If $errors is not empty, they will be displayed by the common error block later.
                    // $form_data (with the user's attempted changes) will repopulate the form.
                } else { // Nonce check failed
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Security check failed for update. Please try again.', 'fsbhoa-ac') . '</p></div>';
                    $errors['security'] = 'Nonce failed'; // Set an error to ensure the common error display logic runs
                }
                // END UPDATE LOGIC
            } elseif ($posted_action_type === 'add_cardholder' && isset($_POST['submit_add_cardholder']) && !$is_edit_mode ) {
                if (isset($_POST['fsbhoa_add_cardholder_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {
                    
                    // All your existing validation logic for add (from response #75, adapted)
                    if (empty($form_data['first_name'])) { $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); }
                    // ... (include all other validations: last_name, email, phone, phone_type, resident_type, property_id) ...
                    if (!empty($form_data['email']) && !is_email($form_data['email'])) { $errors['email'] = __( 'Please enter a valid email address.', 'fsbhoa-ac' ); }
                    if (!empty($form_data['phone'])) {
                        $phone_regex = '/^(?:1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}$/';
                        if (!preg_match($phone_regex, $form_data['phone'])) { $errors['phone'] = __( 'Please enter a valid phone number format.', 'fsbhoa-ac' );}
                    }
                    if (!in_array($form_data['phone_type'], $allowed_phone_types)) { $errors['phone_type'] = __('Invalid phone type selected.', 'fsbhoa-ac'); }
                    elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) { $errors['phone_type'] = __('Please select a phone type.', 'fsbhoa-ac');}
                    if (!in_array($form_data['resident_type'], $allowed_resident_types)) { $errors['resident_type'] = __('Invalid resident type selected.', 'fsbhoa-ac');}
                    if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) { $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac'); }


                    // Duplicate Check (for add mode)
                    if (empty($errors)) { 
                        $existing_cardholder = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$cardholder_table_name} WHERE first_name = %s AND last_name = %s", $form_data['first_name'], $form_data['last_name'] ) );
                        if ($existing_cardholder) {
                            $errors['duplicate'] = sprintf(__( 'A cardholder named %1$s %2$s already exists (ID: %3$d).', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $existing_cardholder->id);
                        }
                    }

                    if (empty($errors)) {
                        $phone_to_store = !empty($form_data['phone']) ? preg_replace('/[^0-9]/', '', $form_data['phone']) : '';
                        $data_to_insert = array(
                            'first_name' => $form_data['first_name'], 'last_name' => $form_data['last_name'],
                            'email' => $form_data['email'], 'phone' => $phone_to_store,
                            'phone_type' => $form_data['phone_type'], 'resident_type' => $form_data['resident_type'],
                            'property_id' => !empty($form_data['property_id']) ? $form_data['property_id'] : null,
                        );
                        $data_formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%d');
                        $result = $wpdb->insert($cardholder_table_name, $data_to_insert, $data_formats);

                        if ($result === false) {
                            echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error saving cardholder data.', 'fsbhoa-ac') . '</p></div>';
                        } else {
                            echo '<div id="message" class="updated notice is-dismissible"><p>' . sprintf(__( 'Cardholder %1$s %2$s added successfully! Record ID: %3$d', 'fsbhoa-ac' ), esc_html($form_data['first_name']), esc_html($form_data['last_name']), $wpdb->insert_id) . '</p></div>';
                            $form_data = array_fill_keys(array_keys($form_data), ''); // Clear for next add
                        }
                    }
                } else {
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Security check failed for add. Please try again.', 'fsbhoa-ac') . '</p></div>';
                    $errors['security'] = 'Nonce failed';
                }
            } 

            if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST') {
                echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Please correct the errors below and try again:', 'fsbhoa-ac') . '</p><ul>';
                foreach ($errors as $error_message_text) { 
                   echo '<li>' . esc_html($error_message_text) . '</li>';
                }
                echo '</ul></div>';
            }
        } // --- End POST Request Handling ---

        // Determine Page Title and Submit Button Text for actual form rendering
        $page_title = $is_edit_mode ? __( 'Edit Cardholder', 'fsbhoa-ac' ) : __( 'Add New Cardholder', 'fsbhoa-ac' );
        $submit_button_text = $is_edit_mode ? __( 'Update Cardholder', 'fsbhoa-ac' ) : __( 'Save Basic Info & Proceed to Photo', 'fsbhoa-ac' );
        $submit_button_name = $is_edit_mode ? 'submit_update_cardholder' : 'submit_add_cardholder';
        
        $nonce_action = $is_edit_mode ? ('fsbhoa_update_cardholder_action_' . $item_id_for_edit) : 'fsbhoa_add_cardholder_action';
        $nonce_name   = $is_edit_mode ? 'fsbhoa_update_cardholder_nonce' : 'fsbhoa_add_cardholder_nonce';
        $hidden_form_action_type = $is_edit_mode ? 'update_cardholder' : 'add_cardholder';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <form method="POST" 
                  action="?page=fsbhoa_ac_cardholders&action=<?php echo esc_attr($is_edit_mode ? 'edit_cardholder' : 'add'); ?><?php echo $is_edit_mode && $item_id_for_edit ? '&cardholder_id=' . esc_attr($item_id_for_edit) : ''; ?>">
                
                <input type="hidden" name="form_action_type" value="<?php echo esc_attr($hidden_form_action_type); ?>" />
                <?php if ($is_edit_mode && $item_id_for_edit) : ?>
                    <input type="hidden" name="cardholder_id" value="<?php echo esc_attr($item_id_for_edit); ?>" />
                <?php endif; ?>
                <?php wp_nonce_field( $nonce_action, $nonce_name ); ?>
                
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="first_name"><?php esc_html_e( 'First Name', 'fsbhoa-ac' ); ?></label></th>
                            <td><input type="text" name="first_name" id="first_name" class="regular-text" value="<?php echo esc_attr($form_data['first_name']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="last_name"><?php esc_html_e( 'Last Name', 'fsbhoa-ac' ); ?></label></th>
                            <td><input type="text" name="last_name" id="last_name" class="regular-text" value="<?php echo esc_attr($form_data['last_name']); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="email"><?php esc_html_e( 'Email', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <input type="email" name="email" id="email" class="regular-text" value="<?php echo esc_attr($form_data['email']); ?>">
                                <p class="description"><?php esc_html_e( 'Optional.', 'fsbhoa-ac' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="phone"><?php esc_html_e( 'Phone Number', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <input type="tel" name="phone" id="phone" class="regular-text" style="width: 15em; margin-right: 1em;" value="<?php echo esc_attr($form_data['phone']); ?>">
                                <select name="phone_type" id="phone_type" style="vertical-align: baseline;">
                                    <option value="" <?php selected($form_data['phone_type'], ''); ?>><?php esc_html_e( '-- Select Type --', 'fsbhoa-ac' ); ?></option>
                                    <option value="Mobile" <?php selected($form_data['phone_type'], 'Mobile'); ?>><?php esc_html_e( 'Mobile', 'fsbhoa-ac' ); ?></option>
                                    <option value="Home" <?php selected($form_data['phone_type'], 'Home'); ?>><?php esc_html_e( 'Home', 'fsbhoa-ac' ); ?></option>
                                    <option value="Work" <?php selected($form_data['phone_type'], 'Work'); ?>><?php esc_html_e( 'Work', 'fsbhoa-ac' ); ?></option>
                                    <option value="Other" <?php selected($form_data['phone_type'], 'Other'); ?>><?php esc_html_e( 'Other', 'fsbhoa-ac' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="resident_type"><?php esc_html_e( 'Resident Type', 'fsbhoa-ac' ); ?></label></th>
                            <td>
                                <select name="resident_type" id="resident_type">
                                    <option value="" <?php selected($form_data['resident_type'], ''); ?>><?php esc_html_e( '-- Select Type --', 'fsbhoa-ac' ); ?></option>
                                    <option value="Resident Owner" <?php selected($form_data['resident_type'], 'Resident Owner'); ?>><?php esc_html_e( 'Resident Owner', 'fsbhoa-ac' ); ?></option>
                                    <option value="Non-resident Owner" <?php selected($form_data['resident_type'], 'Non-resident Owner'); ?>><?php esc_html_e( 'Non-resident Owner', 'fsbhoa-ac' ); ?></option>
                                    <option value="Tenant" <?php selected($form_data['resident_type'], 'Tenant'); ?>><?php esc_html_e( 'Tenant', 'fsbhoa-ac' ); ?></option>
                                    <option value="Staff" <?php selected($form_data['resident_type'], 'Staff'); ?>><?php esc_html_e( 'Staff', 'fsbhoa-ac' ); ?></option>
                                    <option value="Contractor" <?php selected($form_data['resident_type'], 'Contractor'); ?>><?php esc_html_e( 'Contractor', 'fsbhoa-ac' ); ?></option>
                                    <option value="Other" <?php selected($form_data['resident_type'], 'Other'); ?>><?php esc_html_e( 'Other', 'fsbhoa-ac' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fsbhoa_property_search_input"><?php esc_html_e( 'Property Address', 'fsbhoa-ac' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="fsbhoa_property_search_input" name="property_address_display" class="regular-text" 
                                       placeholder="<?php esc_attr_e( 'Start typing address...', 'fsbhoa-ac' ); ?>" 
                                       value="<?php echo esc_attr($form_data['property_address_display']); // Pre-fill if editing ?>">
                                <input type="hidden" name="property_id" id="fsbhoa_property_id_hidden" 
                                       value="<?php echo esc_attr($form_data['property_id']); ?>">
                                <p class="description">
                                    <?php esc_html_e( 'Type 1+ characters of the address to search. Select from suggestions.', 'fsbhoa-ac' ); ?>
                                    <span id="fsbhoa_property_clear_selection" style="display: <?php echo empty($form_data['property_id']) ? 'none' : 'inline'; ?>; margin-left:10px; color: #0073aa; cursor:pointer;"><?php esc_html_e('[Clear Selection]', 'fsbhoa-ac'); ?></span>
                                </p>
                                <div id="fsbhoa_property_search_no_results" style="color: #dc3232; margin-top: 5px; min-height: 1em;"></div>
                                <div id="fsbhoa_selected_property_display" style="margin-top:5px; font-style:italic;">
                                    <?php 
                                    if ($is_edit_mode && !empty($form_data['property_id']) && !empty($form_data['property_address_display'])) {
                                        echo 'Currently assigned: ' . esc_html($form_data['property_address_display']);
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( $submit_button_text, 'primary', $submit_button_name ); ?>
            </form>
            <p><a href="?page=fsbhoa_ac_cardholders"><?php esc_html_e( '&larr; Back to Cardholders List', 'fsbhoa-ac' ); ?></a></p>
        </div>
        <?php
    }

} // end class Fsbhoa_Cardholder_Admin_Page
?>
