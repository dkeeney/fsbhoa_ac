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
     * AJAX callback to search properties.
     * Outputs JSON.
     *
     * @since 0.1.5
     */
    public function ajax_search_properties_callback() {
        // Security check: Verify the nonce.
        check_ajax_referer('fsbhoa_property_search_nonce', 'security');

        global $wpdb;
        $table_name = 'ac_property';
        $search_term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $results = array();

        if (strlen($search_term) >= 1) {
            $wildcard_search_term = '%' . $wpdb->esc_like($search_term) . '%';
            $properties = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT property_id, street_address FROM {$table_name} WHERE street_address LIKE %s ORDER BY street_address ASC LIMIT 20",
                    $wildcard_search_term
                )
            );

            if ($properties) {
                foreach ($properties as $property) {
                    $results[] = array(
                        'id'    => $property->property_id,
                        'label' => $property->street_address,
                        'value' => $property->street_address
                    );
                }
            }
        }
        wp_send_json_success($results);
    }


    /**
     * Handles the display of the cardholder admin page, routing to list or form.
     *
     * @since 0.1.3
     */
    public function render_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ('add' === $action || 'edit' === $action) { // 'edit' for future use
            $this->render_add_new_cardholder_form($action);
        } else {
            $this->render_cardholders_list_page();
        }
    }

    /**
     * Renders the list of cardholders using WP_List_Table.
     *
     * @since 0.1.3 (Updated in 0.1.6 to use WP_List_Table)
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

            <?php // For displaying messages (e.g., after delete, though delete handler is not yet for cardholders) ?>
            <?php 
            // Example message display (can be refined later for cardholder specific messages)
            if (isset($_GET['message'])) {
                $message_code = sanitize_key($_GET['message']);
                // You would have a switch here similar to the properties page if you add actions that redirect here
                // For now, this is just a placeholder if a generic 'message' GET param is ever used.
                // echo '<div id="message" class="updated notice is-dismissible"><p>' . esc_html__( 'Action processed: ', 'fsbhoa-ac' ) . esc_html($message_code) . '</p></div>';
            }
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
     * Includes validation and database insertion logic.
     *
     * @since 0.1.5 // Updated version for autocomplete property
     * @param string $action Current action ('add' or 'edit')
     */
    public function render_add_new_cardholder_form($action = 'add') {
        global $wpdb; // Make $wpdb available

        $form_data = array(
            'first_name'    => '',
            'last_name'     => '',
            'email'         => '',
            'phone'         => '',
            'phone_type'    => '',
            'resident_type' => '',
            'property_id'   => '', // For the hidden property_id field
        );
        $errors = array();

        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other');
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Other');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_add_cardholder'])) {
            if (isset($_POST['fsbhoa_add_cardholder_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {

                $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
                $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
                $form_data['email']         = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
                $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : '';
                $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
                $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';
                $form_data['property_id']   = isset($_POST['property_id']) ? absint(wp_unslash($_POST['property_id'])) : '';


                // Validate First Name, Last Name, Email, Phone, Phone Type, Resident Type (as before)
                // ... (keep all your existing validation logic for these fields here) ...
                if (empty($form_data['first_name'])) { $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' ); }
                if (empty($form_data['last_name'])) { $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' ); }
                if (!empty($form_data['email']) && !is_email($form_data['email'])) { $errors['email'] = __( 'Please enter a valid email address.', 'fsbhoa-ac' ); }
                if (!empty($form_data['phone'])) {
                    $phone_regex = '/^(?:1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}$/';
                    if (!preg_match($phone_regex, $form_data['phone'])) { $errors['phone'] = __( 'Please enter a valid phone number format.', 'fsbhoa-ac' );}
                }
                if (!in_array($form_data['phone_type'], $allowed_phone_types)) { $errors['phone_type'] = __('Invalid phone type selected.', 'fsbhoa-ac'); }
                elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) { $errors['phone_type'] = __('Please select a phone type if a phone number is entered.', 'fsbhoa-ac');}
                if (!in_array($form_data['resident_type'], $allowed_resident_types)) { $errors['resident_type'] = __('Invalid resident type selected.', 'fsbhoa-ac');}


                // Validate Property ID (if a value is submitted, ensure it's a positive integer)
                if (!empty($form_data['property_id']) && $form_data['property_id'] <= 0) {
                    $errors['property_id'] = __('Invalid property selection.', 'fsbhoa-ac');
                }
                // Optional: Make property_id required
                // elseif (empty($form_data['property_id'])) {
                //     $errors['property_id'] = __('Please select a property.', 'fsbhoa-ac');
                // }

                // Duplicate Check (if no other errors so far)
                if (empty($errors)) {
                    $cardholder_table_name = 'ac_cardholders'; // Moved table name definition here
                    $existing_cardholder = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id FROM $cardholder_table_name WHERE first_name = %s AND last_name = %s",
                            $form_data['first_name'],
                            $form_data['last_name']
                        )
                    );
                    if ($existing_cardholder) {
                        $errors['duplicate'] = sprintf(
                            __( 'A cardholder named %1$s %2$s already exists (ID: %3$d).', 'fsbhoa-ac' ),
                            esc_html($form_data['first_name']),
                            esc_html($form_data['last_name']),
                            $existing_cardholder->id
                        );
                    }
                }

                if (empty($errors)) {
                    $cardholder_table_name = 'ac_cardholders'; // Already defined if duplicate check ran
                    $phone_to_store = $form_data['phone'];
                    if (!empty($phone_to_store)) {
                        $phone_to_store = preg_replace('/[^0-9]/', '', $phone_to_store);
                    }

                    $data_to_insert = array(
                        'first_name'    => $form_data['first_name'],
                        'last_name'     => $form_data['last_name'],
                        'email'         => $form_data['email'],
                        'phone'         => $phone_to_store,
                        'phone_type'    => $form_data['phone_type'],
                        'resident_type' => $form_data['resident_type'],
                        'property_id'   => !empty($form_data['property_id']) ? $form_data['property_id'] : null,
                    );
                    $data_formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%d');

                    $result = $wpdb->insert($cardholder_table_name, $data_to_insert, $data_formats);

                    if ($result === false) {
                        echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error saving cardholder data.', 'fsbhoa-ac') . '</p>';
                    } else {
                        echo '<div id="message" class="updated notice is-dismissible"><p>' . sprintf(
                            esc_html__('Cardholder %1$s %2$s added successfully! Record ID: %3$d', 'fsbhoa-ac'),
                            esc_html($form_data['first_name']),
                            esc_html($form_data['last_name']),
                            $wpdb->insert_id
                        ) . '</p></div>';
                        $form_data = array_fill_keys(array_keys($form_data), '');
                    }
                } else {
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Please correct the errors below.', 'fsbhoa-ac') . '</p>';
                    foreach ($errors as $field => $error_message) {
                       echo '<p><strong>' . esc_html(ucfirst(str_replace('_', ' ', $field))) . ':</strong> ' . esc_html($error_message) . '</p>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Security check failed.', 'fsbhoa-ac') . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?></h1>
            <form method="POST" action="?page=fsbhoa_ac_cardholders&action=add">
                <?php wp_nonce_field( 'fsbhoa_add_cardholder_action', 'fsbhoa_add_cardholder_nonce' ); ?>
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
                                       value=""> <input type="hidden" name="property_id" id="fsbhoa_property_id_hidden"
                                       value="<?php echo esc_attr($form_data['property_id']); ?>">
                                <p class="description">
                                    <?php esc_html_e( 'Type 1+ characters of the address to search. Select from suggestions.', 'fsbhoa-ac' ); ?>
                                    <span id="fsbhoa_property_clear_selection" style="display:none; margin-left:10px; color: #0073aa; cursor:pointer;"><?php esc_html_e('[Clear Selection]', 'fsbhoa-ac'); ?></span>
                                </p>
                                <div id="fsbhoa_selected_property_display" style="margin-top:5px; font-style:italic;">
                                    <?php
                                    // This part is tricky without JS. If $form_data['property_id'] is set due to a previous error,
                                    // we'd ideally want to show the address. For now, we'll let JS handle the display text field.
                                    // If $form_data['property_id'] is set and validation fails, the hidden field will retain it.
                                    // The visible text field fsbhoa_property_search_input will be blank on reload unless JS repopulates it based on the hidden field.
                                    // We'll address this nuance when writing the JS.
                                    ?>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Save Basic Info & Proceed to Photo', 'fsbhoa-ac' ), 'primary', 'submit_add_cardholder' ); ?>
            </form>
            <p><a href="?page=fsbhoa_ac_cardholders"><?php esc_html_e( '&larr; Back to Cardholders List', 'fsbhoa-ac' ); ?></a></p>
        </div>
        <?php
    } // end render_add_new_cardholder_form()

} // end class Fsbhoa_Cardholder_Admin_Page
?>
