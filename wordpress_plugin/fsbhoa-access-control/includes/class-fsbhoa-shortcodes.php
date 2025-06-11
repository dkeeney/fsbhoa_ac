<?php
/**
 * Handles the front-end shortcodes for the plugin.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Shortcodes {

    public function __construct() {
        add_shortcode( 'fsbhoa_cardholder_management', array( $this, 'render_cardholder_management_shortcode' ) );
    }

    /**
     * Renders the main shortcode content.
     * Can now display different views based on the 'view' attribute.
     * e.g., [fsbhoa_cardholder_management view="properties"]
     *
     * @param array|string $atts Shortcode attributes.
     * @return string The HTML output for the shortcode.
     */
    public function render_cardholder_management_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }


        // Process the 'view' attribute with defaults
        $atts = shortcode_atts(
            array(
                'view' => 'cardholders', // Default to the cardholders view
            ),
            $atts,
            'fsbhoa_cardholder_management'
        );

        $current_view = sanitize_key( $atts['view'] );

        $this->enqueue_scripts();
        ob_start();

        // Conditionally render the correct page based on the view
        if ( $current_view === 'properties' ) {
            if ( class_exists('Fsbhoa_Property_Admin_Page') ) {
                $property_admin_page = new Fsbhoa_Property_Admin_Page();
                $property_admin_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Property management class not found.', 'fsbhoa-ac' ) . '</p>';
            }
        } else {
            // Default to the cardholder view
            if ( class_exists('Fsbhoa_Cardholder_Admin_Page') ) {
                $cardholder_admin_page = new Fsbhoa_Cardholder_Admin_Page();
                $cardholder_admin_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Cardholder management class not found.', 'fsbhoa-ac' ) . '</p>';
            }
        }

        return ob_get_clean();
    }

    private function enqueue_scripts() {
        $app_script_handle = 'fsbhoa-cardholder-app';

        // Enqueue Styles
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style('datatables-style', 'https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css');
        wp_enqueue_style('croppie-style', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css');
        wp_enqueue_style('fsbhoa-admin-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-admin-styles.css');
    
        // Enqueue Library Scripts
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_script('datatables-script', 'https://cdn.datatables.net/2.0.8/js/dataTables.js', array('jquery'), '2.0.8', true);
        wp_enqueue_script('croppie-script', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js', array('jquery'), '2.6.5', true );
        
        // Enqueue our custom scripts with dependency chain
        wp_enqueue_script('fsbhoa-photo-croppie', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-photo-croppie.js', array('jquery', 'jquery-ui-dialog', 'croppie-script'), FSBHOA_AC_VERSION, true);
        wp_enqueue_script($app_script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-cardholder-admin.js', array('fsbhoa-photo-croppie'), FSBHOA_AC_VERSION, true);
    
        // Localize data
        $photo_settings = array( 'width'  => get_option('fsbhoa_ac_photo_width', 640), 'height' => get_option('fsbhoa_ac_photo_height', 800) );
        wp_localize_script($app_script_handle, 'fsbhoa_photo_settings', $photo_settings);
        
        $ajax_settings = array( 'ajax_url' => admin_url('admin-ajax.php'), 'property_search_nonce'  => wp_create_nonce('fsbhoa_property_search_nonce') );
        wp_localize_script($app_script_handle, 'fsbhoa_ajax_settings', $ajax_settings);

        // Safely trigger our app's initialization script after all other scripts are loaded.
        $inline_script = "jQuery(document).ready(function(){ if(window.FSBHOA_Cardholder_App){ window.FSBHOA_Cardholder_App.init(); } });";
        wp_add_inline_script( $app_script_handle, $inline_script, 'after' );
    }
}

