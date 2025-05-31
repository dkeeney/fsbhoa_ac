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
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ('add' === $action || 'edit' === $action) { // 'edit' for future use
            $this->render_add_new_property_form($action);
        } else {
            $this->render_property_list_page();
        }
    }

    /**
     * Renders the list of properties.
     * (Currently a placeholder, will later integrate WP_List_Table)
     *
     * @since 0.1.4
     */
    public function render_property_list_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Property Management', 'fsbhoa-ac' ); ?></h1>
            <a href="?page=fsbhoa_ac_properties&action=add" class="page-title-action">
                <?php echo esc_html__( 'Add New Property', 'fsbhoa-ac' ); ?>
            </a>
            <p><?php esc_html_e( 'This area will display a list of all properties.', 'fsbhoa-ac' ); ?></p>
            <form method="post"><?php // TODO: WordPress List Table for properties will go here ?></form>
            <p><em><?php esc_html_e( '(Property list table functionality to be implemented.)', 'fsbhoa-ac' ); ?></em></p>
        </div>
        <?php
    }

    /**
     * Renders the form for adding or editing a property.
     * Includes validation and database insertion logic.
     * Schema: property_id (AI, PK), street_address (VARCHAR(200), NOT NULL, UNIQUE), notes (TEXT)
     *
     * @since 0.1.4
     * @param string $action Current action ('add' or 'edit')
     */
    public function render_add_new_property_form($action = 'add') {
        $form_data = array(
            'street_address' => '',
            'notes'          => '',
        );
        $errors = array();

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_add_property'])) {
            if (isset($_POST['fsbhoa_add_property_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_property_nonce'])), 'fsbhoa_add_property_action')) {
                
                $form_data['street_address'] = isset($_POST['street_address']) ? trim(sanitize_text_field(wp_unslash($_POST['street_address']))) : '';
                $form_data['notes']          = isset($_POST['notes']) ? trim(sanitize_textarea_field(wp_unslash($_POST['notes']))) : '';

                // Validate Street Address
                if (empty($form_data['street_address'])) {
                    $errors['street_address'] = __( 'Street Address is required.', 'fsbhoa-ac' );
                } elseif (strlen($form_data['street_address']) > 200) {
                    $errors['street_address'] = __( 'Street Address is too long (max 200 characters).', 'fsbhoa-ac' );
                } else {
                    // TODO: Add uniqueness check for street_address later by querying the DB
                    // This check should be done only if other errors are not present for this field.
                    // For now, we'll rely on the database unique constraint to catch it, which isn't ideal for UX.
                }

                // Notes field is optional, max length if any? For TEXT, usually not an issue from form.

                if (empty($errors)) {
                    global $wpdb;
                    $table_name = 'ac_property'; // Your property table name

                    $data_to_insert = array(
                        'street_address' => $form_data['street_address'],
                        'notes'          => $form_data['notes'],
                    );

                    $data_formats = array('%s', '%s'); // Both are strings

                    $result = $wpdb->insert($table_name, $data_to_insert, $data_formats);

                    if ($result === false) {
                        $db_error = $wpdb->last_error;
                        $user_message = esc_html__('Error saving property data. Please try again.', 'fsbhoa-ac');
                        if (stripos($db_error, 'Duplicate entry') !== false && stripos($db_error, 'idx_street_address_unique') !== false) {
                            $user_message = esc_html__('Error: This Street Address already exists.', 'fsbhoa-ac');
                        }
                        echo '<div id="message" class="error notice is-dismissible"><p>' . $user_message . '</p></div>';
                        // error_log('FSBHOA Property Insert Error: ' . $db_error);
                    } else {
                        echo '<div id="message" class="updated notice is-dismissible"><p>' . sprintf(
                            esc_html__('Property "%1$s" added successfully! Record ID: %2$d', 'fsbhoa-ac'),
                            esc_html($form_data['street_address']),
                            $wpdb->insert_id
                        ) . '</p></div>';
                        // Clear form data for a fresh form
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
            <h1><?php echo esc_html__( 'Add New Property', 'fsbhoa-ac' ); // TODO: Edit mode title ?></h1>
            <form method="POST" action="?page=fsbhoa_ac_properties&action=add">
                <?php wp_nonce_field( 'fsbhoa_add_property_action', 'fsbhoa_add_property_nonce' ); ?>
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
                <?php submit_button( __( 'Add Property', 'fsbhoa-ac' ), 'primary', 'submit_add_property' ); ?>
            </form>
            <p><a href="?page=fsbhoa_ac_properties"><?php esc_html_e( '&larr; Back to Properties List', 'fsbhoa-ac' ); ?></a></p>
        </div>
        <?php
    }
} // end class Fsbhoa_Property_Admin_Page
?>

