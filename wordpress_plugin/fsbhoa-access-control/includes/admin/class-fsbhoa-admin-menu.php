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


/**
     * Enqueue scripts and styles for the admin area.
     *
     * @since 0.1.5
     * @param string $hook_suffix The current admin page.
     */
    public function enqueue_admin_scripts($hook_suffix) {
        $screen = get_current_screen();

        // Define our page hooks based on the actual observed screen IDs
        $cardholder_page_hook = 'fsbhoa-access_page_fsbhoa_ac_cardholders';
        $property_page_hook   = 'fsbhoa-access_page_fsbhoa_ac_properties';

        $plugin_admin_pages = array(
            $cardholder_page_hook, 
            $property_page_hook,  
        );

        if ( $screen && in_array( $screen->id, $plugin_admin_pages ) ) {
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_style(
                'fsbhoa-admin-styles',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-admin-styles.css',
                array(),
                $this->version
            );
        }

        // Scripts specific to the CARDHOLDER admin page
        if ( $screen && $screen->id === $cardholder_page_hook ) {
            // ---  Enqueue DataTables library for the admin list view ---
            wp_enqueue_style('datatables-style', 'https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css');
            wp_enqueue_script('datatables-script', 'https://cdn.datatables.net/2.0.8/js/dataTables.js', array('jquery'), '2.0.8', true);

            // --- START: RE-ADDING CROPPER CSS ---
            // This is to test if the "white screen" issue is due to missing styles.
            wp_enqueue_style(
                'cropperjs-style',
                'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/2.0.0/cropper.min.css', 
                array(),
                '2.0.0'
            );
            // --- END: RE-ADDING CROPPER CSS ---

            // Enqueue Cropper.js JavaScript from CDN in the header
            wp_enqueue_script(
                'cropperjs-script',
                'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/2.0.0/cropper.min.js', 
                array(), 
                '2.0.0', 
                false // Load in header
            );

            // Enqueue your custom cardholder admin script
            wp_enqueue_script(
                'fsbhoa-cardholder-admin-script',
                FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-cardholder-admin.js',
                array('jquery', 'jquery-ui-autocomplete', 'cropperjs-script', 'datatables-script'), 
                $this->version,
                true
            );

            // Localize data for the script
            wp_localize_script(
                'fsbhoa-cardholder-admin-script',
                'fsbhoa_ajax_settings',
                array(
                    'ajax_url'               => admin_url('admin-ajax.php'),
                    'property_search_nonce'  => wp_create_nonce('fsbhoa_property_search_nonce'),
                    'property_search_action' => 'fsbhoa_search_properties'
                )
            );

            $photo_width  = get_option('fsbhoa_ac_photo_width', 640);
            $photo_height = get_option('fsbhoa_ac_photo_height', 800);
            $aspect_ratio = 16/9; 
            if ( is_numeric($photo_width) && is_numeric($photo_height) && (int)$photo_height !== 0 && (int)$photo_width !== 0 ) {
                $aspect_ratio = (int)$photo_width / (int)$photo_height;
            }

            wp_localize_script(
                'fsbhoa-cardholder-admin-script',
                'fsbhoa_photo_settings',
                array(
                    'width'        => absint($photo_width),
                    'height'       => absint($photo_height),
                    'aspect_ratio' => floatval($aspect_ratio)
                )
            );
        }
    }
}?>
