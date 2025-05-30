<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Admin_Menu {

    /**
     * The ID of this plugin.
     *
     * @since    0.1.0
     * @access   private
     * @var      string    $plugin_name    The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    0.1.0
     * @access   private
     * @var      string    $version    The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    0.1.0
     */
    public function __construct() {
        $this->plugin_name = 'fsbhoa-ac'; // Or derive from main plugin file defines
        if (defined('FSBHOA_AC_VERSION')) {
            $this->version = FSBHOA_AC_VERSION;
        } else {
            $this->version = '0.1.0'; // Fallback version
        }
    }

    /**
     * Register the menu for the plugin in the WordPress admin area.
     *
     * @since    0.1.0
     */
    public function add_admin_menu_pages() {
        // Add a top-level menu page
        add_menu_page(
            __( 'FSBHOA Access Control', 'fsbhoa-ac' ), // Page title
            __( 'FSBHOA Access', 'fsbhoa-ac' ),        // Menu title
            'manage_options',                           // Capability required
            'fsbhoa_ac_main_menu',                      // Menu slug
            array( $this, 'display_main_admin_page' ), // Function to display the page
            'dashicons-id-alt',                         // Icon URL or dashicon class
            26                                          // Position
        );

        // Add a submenu page for Cardholders
        add_submenu_page(
            'fsbhoa_ac_main_menu',                      // Parent slug
            __( 'Cardholders', 'fsbhoa-ac' ),           // Page title
            __( 'Cardholders', 'fsbhoa-ac' ),           // Menu title
            'manage_options',                           // Capability
            'fsbhoa_ac_cardholders',                    // Menu slug
            array( $this, 'display_cardholders_page' )  // Function to display the page
        );
        
        // TODO: Add more submenu pages here (Print Queue, Settings, Logs, Controller Mgmt)
    }

    /**
     * Callback function to display the main admin page content.
     *
     * @since    0.1.0
     */
    public function display_main_admin_page() {
        // For now, just a placeholder
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'FSBHOA Access Control - Main Page', 'fsbhoa-ac' ) . '</h1>';
        echo '<p>' . esc_html__( 'Welcome to the main settings page. Manage cardholders, access logs, and controller settings from here.', 'fsbhoa-ac' ) . '</p>';
        echo '</div>';
    }

    /**
     * Callback function to display the cardholders page content.
     * Handles routing to the add new form or the list table.
     *
     * @since    0.1.0
     */
    public function display_cardholders_page() {
        // Check if the 'action' GET parameter is set
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        if ('add' === $action || 'edit' === $action) { // We can add 'edit' later
            $this->render_add_new_cardholder_form($action);
        } else {
            $this->render_cardholders_list_page();
        }
    }

    /**
     * Renders the list of cardholders.
     * (Currently a placeholder, will later integrate WP_List_Table)
     *
     * @since 0.1.1
     */
    public function render_cardholders_list_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'Cardholder Management', 'fsbhoa-ac' ); ?></h1>

            <a href="?page=fsbhoa_ac_cardholders&action=add" class="page-title-action">
                <?php echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' ); ?>
            </a>

            <p>
                <?php esc_html_e( 'This area will display a list of all cardholders. You will be able to edit, view, and manage access credentials from here.', 'fsbhoa-ac' ); ?>
            </p>

            <form method="post">
                <?php
                // TODO: WordPress List Table will go here
                ?>
            </form>
            <p><em><?php esc_html_e( '(Cardholder list table functionality to be implemented.)', 'fsbhoa-ac' ); ?></em></p>
        </div>
        <?php
    }

    /**
     * Renders the form for adding or editing a cardholder.
     *
     * @since 0.1.1
     * @param string $action Current action ('add' or 'edit')
     */
    public function render_add_new_cardholder_form($action = 'add') {
        $form_data = array( // Initialize $form_data
            'first_name' => '',
            'last_name'  => '',
            // Add other fields here as they are added to the form
        );
        $errors = array(); // Initialize $errors

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_add_cardholder'])) {
            if (isset($_POST['fsbhoa_add_cardholder_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {
                
                // Sanitize and collect form data into $form_data
                $form_data['first_name'] = isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '';
                $form_data['last_name']  = isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '';

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

                if (empty($errors)) {
                    // ** START DATABASE INSERTION LOGIC **
                    global $wpdb; 
                    // IMPORTANT: Confirm your actual table name.
                    // If your WordPress prefix (e.g., 'wp_') is DIFFERENT from '_ac'
                    // AND your table is literally named 'ac_cardholders', then use:
                    // $table_name = 'ac_cardholders'; 
                    // If your WordPress prefix IS '_ac', then use:
                    // $table_name = $wpdb->prefix . 'cardholders';
                    // For this paste, I am assuming the table name is literally 'ac_cardholders'
                    // and it does not use the standard WP prefix if that prefix is different.
                    // PLEASE VERIFY THIS FOR YOUR SETUP.
                    $table_name = 'ac_cardholders'; // <--- VERIFY THIS TABLE NAME

                    $data_to_insert = array(
                        'first_name' => $form_data['first_name'],
                        'last_name'  => $form_data['last_name'],
                        // 'rfid_id' will be NULL by default in the DB if you've set it to allow NULLs
                        // 'created_at' => current_time('mysql', 1), // Example if not auto-timestamped
                    );

                    $data_formats = array(
                        '%s', // first_name
                        '%s'  // last_name
                    );

                    $result = $wpdb->insert($table_name, $data_to_insert, $data_formats);

                    if ($result === false) {
                        echo '<div id="message" class="error notice is-dismissible"><p>' . esc_html__('Error saving cardholder data. Please try again or contact an administrator.', 'fsbhoa-ac') . '</p>';
                        // For debugging, you could log $wpdb->last_error but don't display it publicly.
                        // error_log('FSBHOA Cardholder Insert Error: ' . $wpdb->last_error);
                    } else {
                        echo '<div id="message" class="updated notice is-dismissible"><p>' . sprintf(
                            esc_html__('Cardholder %1$s %2$s added successfully! Record ID: %3$d', 'fsbhoa-ac'),
                            esc_html($form_data['first_name']),
                            esc_html($form_data['last_name']),
                            $wpdb->insert_id // Get the ID of the newly inserted row
                        ) . '</p></div>';
                        
                        $form_data = array_fill_keys(array_keys($form_data), ''); 
                    }
                    // ** END DATABASE INSERTION LOGIC **
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
            <h1>
                <?php
                // TODO: Add logic for 'edit' mode title later
                echo esc_html__( 'Add New Cardholder', 'fsbhoa-ac' );
                ?>
            </h1>

            <form method="POST" action="?page=fsbhoa_ac_cardholders&action=add">
                <?php wp_nonce_field( 'fsbhoa_add_cardholder_action', 'fsbhoa_add_cardholder_nonce' ); ?>

                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="first_name"><?php esc_html_e( 'First Name', 'fsbhoa-ac' ); ?></label>
                            </th>
                            <td>
                                <input type="text" name="first_name" id="first_name" class="regular-text" 
                                       value="<?php echo esc_attr($form_data['first_name']); ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="last_name"><?php esc_html_e( 'Last Name', 'fsbhoa-ac' ); ?></label>
                            </th>
                            <td>
                                <input type="text" name="last_name" id="last_name" class="regular-text" 
                                       value="<?php echo esc_attr($form_data['last_name']); ?>" required>
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

    // We can add methods here later for enqueueing admin scripts and styles
    // public function enqueue_styles() { ... }
    // public function enqueue_scripts() { ... }

} // end class Fsbhoa_Admin_Menu
?>

