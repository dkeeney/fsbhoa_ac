<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for
 * enqueueing the admin-specific stylesheet and JavaScript.
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
        $this->version = FSBHOA_AC_VERSION; // Assumes FSBHOA_AC_VERSION is defined in main file
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

        // Add a submenu page for Cardholders (example)
        add_submenu_page(
            'fsbhoa_ac_main_menu',                      // Parent slug
            __( 'Cardholders', 'fsbhoa-ac' ),           // Page title
            __( 'Cardholders', 'fsbhoa-ac' ),           // Menu title
            'manage_options',                           // Capability
            'fsbhoa_ac_cardholders',                    // Menu slug
            array( $this, 'display_cardholders_page' )  // Function to display the page
        );
        
        // Add more submenu pages here (Print Queue, Settings, Logs, Controller Mgmt)
    }

    /**
     * Callback function to display the main admin page content.
     *
     * @since    0.1.0
     */
    public function display_main_admin_page() {
        // For now, just a placeholder
        echo '<h1>' . esc_html__( 'FSBHOA Access Control - Main Page', 'fsbhoa-ac' ) . '</h1>';
        echo '<p>' . esc_html__( 'Welcome to the main settings page.', 'fsbhoa-ac' ) . '</p>';
    }

/**
     * Callback function to display the cardholders page content.
     *
     * @since    0.1.0
     */
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
            // Call a method to display the add/edit form
            $this->render_add_new_cardholder_form($action); // Pass action for context if needed
        } else {
            // Display the list of cardholders (current placeholder)
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
                // For WP_List_Table, if we were to use one directly here.
                // $list_table = new Your_Cardholders_List_Table();
                // $list_table->prepare_items();
                // $list_table->display();
                ?>
            </form>
            <p><em><?php esc_html_e( '(Cardholder list table functionality to be implemented.)', 'fsbhoa-ac' ); ?></em></p>

        </div>
        <?php
    }
    /**
     * Renders the form for adding a new cardholder.
     *
     * @since 0.1.1
     * @param string $action Current action ('add' or 'edit')
     */
    public function render_add_new_cardholder_form($action = 'add') {
        // Security check: Nonce verification will be added here for POST requests.
        // Data saving logic will also go here.

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_add_cardholder'])) {
            // Verify nonce (important for security)
            if (isset($_POST['fsbhoa_add_cardholder_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fsbhoa_add_cardholder_nonce'])), 'fsbhoa_add_cardholder_action')) {
                // Nonce is valid, process the data
                echo '<div id="message" class="updated notice is-dismissible"><p>Form submitted! (Data processing not yet implemented)</p><pre>';
                // Sanitize and display POST data (for debugging purposes only)
                // In a real application, you would save this data to the database.
                print_r(array_map('sanitize_text_field', wp_unslash($_POST)));
                echo '</pre></div>';
            } else {
                // Nonce is invalid
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
                                <label for="rfid_id"><?php esc_html_e( 'RFID ID', 'fsbhoa-ac' ); ?></label>
                            </th>
                            <td>
                                <input type="text" name="rfid_id" id="rfid_id" class="regular-text" value="" required>
                                <p class="description"><?php esc_html_e( 'Enter the 8-digit RFID card ID.', 'fsbhoa-ac' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="first_name"><?php esc_html_e( 'First Name', 'fsbhoa-ac' ); ?></label>
                            </th>
                            <td>
                                <input type="text" name="first_name" id="first_name" class="regular-text" value="" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="last_name"><?php esc_html_e( 'Last Name', 'fsbhoa-ac' ); ?></label>
                            </th>
                            <td>
                                <input type="text" name="last_name" id="last_name" class="regular-text" value="" required>
                            </td>
                        </tr>
                        </tbody>
                </table>

                <?php submit_button( __( 'Add Cardholder', 'fsbhoa-ac' ), 'primary', 'submit_add_cardholder' ); ?>
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
