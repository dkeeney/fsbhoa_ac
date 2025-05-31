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
     * Renders the list of cardholders.
     * (Currently a placeholder, will later integrate WP_List_Table)
     *
     * @since 0.1.1 (Copied from Fsbhoa_Admin_Menu)
     */
    public function render_cardholders_list_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>
            <a href="?page=fsbhoa_ac_cardholders&action=add" class="page-title-action">
                <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
            </a>
            <p><?php esc_html_e( 'This area will display a list of all cardholders. You will be able to edit, view, and manage access credentials from here.', 'fsbhoa-ac' ); ?></p>
            <form method="post"><?php // TODO: WordPress List Table will go here ?></form>
            <p><em><?php esc_html_e( '(Cardholder list table functionality to be implemented.)', 'fsbhoa-ac' ); ?></em></p>
        </div>
        <?php
    }

    /**
     * Renders the form for adding or editing a cardholder.
     * Includes validation and database insertion logic.
     *
     * @since 0.1.2 (Adapted from Fsbhoa_Admin_Menu, includes latest fields)
     * @param string $action Current action ('add' or 'edit')
     */
    public function render_add_new_cardholder_form($action = 'add') {
        $form_data = array(
            'first_name'    => '',
            'last_name'     => '',
            'email'         => '',
            'phone'         => '',
            'phone_type'    => '',
            'resident_type' => '',
        );
        $errors = array();

        $allowed_phone_types = array('', 'Mobile', 'Home', 'Work', 'Other');
        $allowed_resident_types = array('', 'Resident Owner', 'Non-resident Owner', 'Tenant', 'Staff', 'Contractor', 'Other');

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_add_cardholder'])) {
            if (isset($_POST['fsbhoa_add_cardholder_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {
                
                $form_data['first_name']    = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
                $form_data['last_name']     = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';
                $form_data['email']         = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
                $form_data['phone']         = isset($_POST['phone']) ? trim(sanitize_text_field(wp_unslash($_POST['phone']))) : ''; // Added trim
                $form_data['phone_type']    = isset($_POST['phone_type']) ? sanitize_text_field(wp_unslash($_POST['phone_type'])) : '';
                $form_data['resident_type'] = isset($_POST['resident_type']) ? sanitize_text_field(wp_unslash($_POST['resident_type'])) : '';

                // Validate First Name
                if (empty($form_data['first_name'])) {
                    $errors['first_name'] = __( 'First Name is required.', 'fsbhoa-ac' );
                } elseif (strlen($form_data['first_name']) > 100) {
                    $errors['first_name'] = __( 'First Name is too long (max 100 characters).', 'fsbhoa-ac' );
                }

                // Validate Last Name
                if (empty($form_data['last_name'])) {
                    $errors['last_name'] = __( 'Last Name is required.', 'fsbhoa-ac' );
                } elseif (strlen($form_data['last_name']) > 100) {
                    $errors['last_name'] = __( 'Last Name is too long (max 100 characters).', 'fsbhoa-ac' );
                }

                // Validate Email
                if (!empty($form_data['email']) && !is_email($form_data['email'])) {
                    $errors['email'] = __( 'Please enter a valid email address.', 'fsbhoa-ac' );
                } elseif (strlen($form_data['email']) > 255) {
                    $errors['email'] = __( 'Email is too long (max 255 characters).', 'fsbhoa-ac' );
                }

                // Validate Phone
                if (!empty($form_data['phone'])) {
                    $phone_regex = '/^(?:1[\s.-]?)?(?:\(\d{3}\)|\d{3})[\s.-]?\d{3}[\s.-]?\d{4}$/';
                    if (!preg_match($phone_regex, $form_data['phone'])) {
                        $errors['phone'] = __( 'Please enter a valid phone number format (e.g., 1234567890, (123) 456 7890, 123.456.7890).', 'fsbhoa-ac' );
                    } elseif (strlen($form_data['phone']) > 30) {
                        $errors['phone'] = __( 'Phone number is too long (max 30 characters).', 'fsbhoa-ac' );
                    }
                }
                
                // Validate Phone Type
                if (!in_array($form_data['phone_type'], $allowed_phone_types)) {
                    $errors['phone_type'] = __('Invalid phone type selected.', 'fsbhoa-ac');
                } elseif (!empty($form_data['phone']) && empty($form_data['phone_type'])) {
                    $errors['phone_type'] = __('Please select a phone type if a phone number is entered.', 'fsbhoa-ac');
                }

                // Validate Resident Type
                if (!in_array($form_data['resident_type'], $allowed_resident_types)) {
                    $errors['resident_type'] = __('Invalid resident type selected.', 'fsbhoa-ac');
                }
                // If resident_type becomes mandatory, add:
                // elseif (empty($form_data['resident_type'])) {
                // $errors['resident_type'] = __('Resident type is required.', 'fsbhoa-ac');
                // }

                // ** NEW: Duplicate Check (if no other errors so far from field validations) **
                if (empty($errors)) { // Only check for duplicates if other field validations passed
                    global $wpdb;
                    $table_name = 'ac_cardholders'; // Your table name
                    $existing_cardholder = $wpdb->get_row(
                        $wpdb->prepare(
                            "SELECT id FROM $table_name WHERE first_name = %s AND last_name = %s",
                            $form_data['first_name'],
                            $form_data['last_name']
                        )
                    );

                    if ($existing_cardholder) {
                        $errors['duplicate'] = sprintf(
                            __( 'A cardholder named %1$s %2$s already exists (ID: %3$d). Please verify before adding a duplicate.', 'fsbhoa-ac' ),
                            esc_html($form_data['first_name']),
                            esc_html($form_data['last_name']),
                            $existing_cardholder->id
                        );
                    }
                }
                // ** END NEW: Duplicate Check **
                

                if (empty($errors)) {
                    global $wpdb;
                    $table_name = 'ac_cardholders';

                    // Prepare data for insertion
                    $phone_to_store = $form_data['phone']; // Get the validated phone number
                    if (!empty($phone_to_store)) {
                        // Strip all non-numeric characters for database storage
                        $phone_to_store = preg_replace('/[^0-9]/', '', $phone_to_store);
                    }


                    $data_to_insert = array(
                        'first_name'    => $form_data['first_name'],
                        'last_name'     => $form_data['last_name'],
                        'email'         => $form_data['email'],
                        'phone'         => $phone_to_store,   // stripped version
                        'phone_type'    => $form_data['phone_type'],
                        'resident_type' => $form_data['resident_type'],
                    );
                    $data_formats = array('%s', '%s', '%s', '%s', '%s', '%s');

                    $result = $wpdb->insert($table_name, $data_to_insert, $data_formats);

                    if ($result === false) {
                        echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error saving cardholder data. Please try again or contact an administrator.', 'fsbhoa-ac') . '</p>';
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
                    echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Please correct the errors below and try again.', 'fsbhoa-ac') . '</p>';
                    foreach ($errors as $field => $error_message) {
                       echo '<p><strong>' . esc_html(ucfirst(str_replace('_', ' ', $field))) . ':</strong> ' . esc_html($error_message) . '</p>';
                    }
                    echo '</div>';
                }
            } else {
                echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Security check failed. Please try again.', 'fsbhoa-ac') . '</p></div>';
            }
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); // TODO: Edit mode title ?></h1>
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
                                <p class="description"><?php esc_html_e( 'Optional. Will be used for communications if provided.', 'fsbhoa-ac' ); ?></p>
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
                                <p class="description"><?php esc_html_e( 'Enter phone number and select its type.', 'fsbhoa-ac' ); ?></p>
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
                    </tbody>
                </table>
                <?php submit_button( __( 'Save Basic Info & Proceed to Photo', 'fsbhoa-ac' ), 'primary', 'submit_add_cardholder' ); ?>
            </form>
            <p><a href="?page=fsbhoa_ac_cardholders"><?php esc_html_e( '&larr; Back to Cardholders List', 'fsbhoa-ac' ); ?></a></p>
        </div>
        <?php
    }
} // end class Fsbhoa_Cardholder_Admin_Page
?>

