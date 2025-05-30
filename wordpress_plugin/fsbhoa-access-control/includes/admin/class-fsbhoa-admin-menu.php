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
        if (defined('FSBHOA_AC_VERSION')) {
            $this->version = FSBHOA_AC_VERSION;
        } else {
            // Make sure this version aligns with your main plugin file or is appropriate
            $this->version = defined('FSBHOA_AC_PLUGIN_VERSION') ? FSBHOA_AC_PLUGIN_VERSION : '0.1.3'; 
        }
    }

    public function add_admin_menu_pages() {
        add_menu_page(
            __( 'FSBHOA Access Control', 'fsbhoa-ac' ),
            __( 'FSBHOA Access', 'fsbhoa-ac' ),
            'manage_options',
            'fsbhoa_ac_main_menu',
            array( $this, 'display_main_admin_page' ),
            'dashicons-id-alt',
            26
        );

        add_submenu_page(
            'fsbhoa_ac_main_menu',
            __( 'Cardholders', 'fsbhoa-ac' ),
            __( 'Cardholders', 'fsbhoa-ac' ),
            'manage_options',
            'fsbhoa_ac_cardholders',
            array( $this, 'display_cardholders_page_callback' ) // Changed callback name for clarity
        );

        // When we add Properties admin:
        // add_submenu_page(
        //     'fsbhoa_ac_main_menu',
        //     __( 'Properties', 'fsbhoa-ac' ),
        //     __( 'Properties', 'fsbhoa-ac' ),
        //     'manage_options',
        //     'fsbhoa_ac_properties',
        //     array( $this, 'display_properties_page_callback' ) 
        // );
    }

    public function display_main_admin_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'FSBHOA Access Control - Main Page', 'fsbhoa-ac' ) . '</h1>';
        echo '<p>' . esc_html__( 'Welcome to the main settings page. Manage cardholders, access logs, and controller settings from here.', 'fsbhoa-ac' ) . '</p>';
        echo '</div>';
    }

    /**
     * Callback for the Cardholders submenu page.
     * Instantiates and calls the dedicated Cardholder admin page handler.
     *
     * @since 0.1.3
     */
    public function display_cardholders_page_callback() {
        // The Fsbhoa_Cardholder_Admin_Page class should have been loaded by the main plugin file
        if (class_exists('Fsbhoa_Cardholder_Admin_Page')) {
            $cardholder_admin_page = new Fsbhoa_Cardholder_Admin_Page();
            $cardholder_admin_page->render_page(); // This method will handle action routing
        } else {
            echo '<div class="wrap"><h2>' . esc_html__('Error: Cardholder admin class not found.', 'fsbhoa-ac') . '</h2></div>';
        }
    }

    // We will add display_properties_page_callback() here later
    // when we create class-fsbhoa-property-admin-page.php

} // end class Fsbhoa_Admin_Menu
?>

