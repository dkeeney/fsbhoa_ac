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
        $this->version = defined('FSBHOA_AC_PLUGIN_VERSION') ? FSBHOA_AC_PLUGIN_VERSION : '0.1.4'; // Bump version

        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu_pages() {
        add_menu_page(
            __( 'FSBHOA Access Control', 'fsbhoa-ac' ),
            __( 'FSBHOA Access', 'fsbhoa-ac' ),
            'manage_options', // Capability
            'fsbhoa_ac_main_menu', // Menu slug
            array( $this, 'display_main_admin_page' ),
            'dashicons-id-alt', // Icon
            26 // Position
        );

        add_submenu_page(
            'fsbhoa_ac_main_menu',
            __( 'Cardholders', 'fsbhoa-ac' ),
            __( 'Cardholders', 'fsbhoa-ac' ),
            'manage_options',
            'fsbhoa_ac_cardholders',
            array( $this, 'display_cardholders_page_callback' )
        );

        // Add a submenu page for Properties
        add_submenu_page(
            'fsbhoa_ac_main_menu',                      // Parent slug
            __( 'Properties', 'fsbhoa-ac' ),            // Page title
            __( 'Properties', 'fsbhoa-ac' ),            // Menu title
            'manage_options',                           // Capability
            'fsbhoa_ac_properties',                     // Menu slug (unique)
            array( $this, 'display_properties_page_callback' ) // Callback function
        );
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

/**
     * Enqueue scripts and styles for the admin area.
     *
     * @since 0.1.5
     * @param string $hook_suffix The current admin page.
     */
    public function enqueue_admin_scripts($hook_suffix) {
        $screen = get_current_screen();
        
        // Define an array of your plugin's admin page screen IDs
        // You'll need to confirm/add the screen ID for the Properties page
        // The screen ID for 'fsbhoa_ac_properties' is likely 'fsbhoa-access_page_fsbhoa_ac_properties'
        // or 'toplevel_page_fsbhoa_ac_main_menu_fsbhoa_ac_properties'
        $plugin_screen_ids = array(
            'fsbhoa-access_page_fsbhoa_ac_cardholders', // From previous setup
            'toplevel_page_fsbhoa_ac_main_menu_fsbhoa_ac_cardholders', // Fallback for cardholders
            // Add Property page screen ID here - VERIFY THIS by temporary error_log($screen->id) on that page
            // Example: (likely one of these, adjust fsbhoa_ac_main_menu if your top level hook is different)
             'fsbhoa-access_page_fsbhoa_ac_properties', 
             'toplevel_page_fsbhoa_ac_main_menu_fsbhoa_ac_properties'
        );

        if ($screen && in_array($screen->id, $plugin_screen_ids)) {
            // Enqueue jQuery UI Autocomplete
            wp_enqueue_script('jquery-ui-autocomplete');
            
            // Enqueue our custom JS for cardholder form (if on cardholder page)
            if ($screen->id === 'fsbhoa-access_page_fsbhoa_ac_cardholders' || $screen->id === 'toplevel_page_fsbhoa_ac_main_menu_fsbhoa_ac_cardholders') {
                wp_enqueue_script(
                    'fsbhoa-cardholder-admin-script',
                    FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-cardholder-admin.js',
                    array('jquery', 'jquery-ui-autocomplete'),
                    defined('FSBHOA_AC_PLUGIN_VERSION') ? FSBHOA_AC_PLUGIN_VERSION : '0.1.5',
                    true 
                );
                wp_localize_script(
                    'fsbhoa-cardholder-admin-script',
                    'fsbhoa_cardholder_ajax_obj',
                    array(
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'property_search_nonce' => wp_create_nonce('fsbhoa_property_search_nonce'),
                        'property_search_action' => 'fsbhoa_search_properties'
                    )
                );
            }

            // ** Enqueue our custom admin CSS for all our plugin pages **
            wp_enqueue_style(
                'fsbhoa-admin-styles', // Handle
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-admin-styles.css', // Path to CSS file
                array(), // Dependencies
                defined('FSBHOA_AC_PLUGIN_VERSION') ? FSBHOA_AC_PLUGIN_VERSION : '0.1.5' // Version
            );
        }
    }
} // end class Fsbhoa_Admin_Menu
?>
