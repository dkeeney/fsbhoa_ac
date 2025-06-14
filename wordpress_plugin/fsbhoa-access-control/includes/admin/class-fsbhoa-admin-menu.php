<?php
/**
 * The admin-specific functionality of the plugin.
 * Handles creation of admin menus.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Admin_Menu {

    private $plugin_name;
    private $version;

    public function __construct() {
        $this->plugin_name = 'fsbhoa-ac';
        // Ensure FSBHOA_AC_PLUGIN_VERSION is defined in your main plugin file
        $this->version = defined('FSBHOA_AC_PLUGIN_VERSION') ? FSBHOA_AC_PLUGIN_VERSION : '0.1.6'; // Bump version

        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu_pages() {
    }

    public function display_main_admin_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'FSBHOA Access Control - Main Page', 'fsbhoa-ac' ) . '</h1>';
        echo '<p>' . esc_html__( 'Welcome to the main settings page. Manage cardholders, properties, access logs, and controller settings from here.', 'fsbhoa-ac' ) . '</p>'; // Updated text
        echo '</div>';
    }

    public function display_cardholders_page_callback() {
        if (class_exists('Fsbhoa_Cardholder_Admin_Page')) {
            $cardholder_admin_page = new Fsbhoa_Cardholder_Admin_Page();
            $cardholder_admin_page->render_page();
        } else {
            echo '<div class="wrap"><h2>' . esc_html__('Error: Cardholder admin class not found.', 'fsbhoa-ac') . '</h2></div>';
        }
    }

    /**
     * Callback for the Properties submenu page.
     * Instantiates and calls the dedicated Property admin page handler.
     *
     * @since 0.1.4
     */
    public function display_properties_page_callback() {
        // The Fsbhoa_Property_Admin_Page class should have been loaded by the main plugin file
        if (class_exists('Fsbhoa_Property_Admin_Page')) {
            $property_admin_page = new Fsbhoa_Property_Admin_Page();
            $property_admin_page->render_page(); // This method will handle action routing
        } else {
            echo '<div class="wrap"><h2>' . esc_html__('Error: Property admin class not found.', 'fsbhoa-ac') . '</h2></div>';
        }
    }


}?>
