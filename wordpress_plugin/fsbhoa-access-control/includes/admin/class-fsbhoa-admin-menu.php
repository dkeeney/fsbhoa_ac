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
    public function display_cardholders_page() {
        // We will build this out later
        echo '<h1>' . esc_html__( 'Cardholder Management', 'fsbhoa-ac' ) . '</h1>';
        // Placeholder for where the cardholder list table and "Add New" button will go
    }

    // We can add methods here later for enqueueing admin scripts and styles
    // public function enqueue_styles() { ... }
    // public function enqueue_scripts() { ... }

} // end class Fsbhoa_Admin_Menu

?>