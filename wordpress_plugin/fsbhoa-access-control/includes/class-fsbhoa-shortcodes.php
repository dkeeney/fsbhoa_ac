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
        add_shortcode( 'fsbhoa_print_card', array( $this, 'render_print_card_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_shortcode_assets' ) );
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

    /**
     *  This function handles loading all CSS and JS for the shortcode.
     * It runs on the 'wp_enqueue_scripts' hook and checks the current view.
     */
    public function enqueue_shortcode_assets() {
        // First, check if we are on a page that is actually displaying our shortcode.
        // This is good practice to prevent loading assets on every single page.
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) 
           || (! has_shortcode( $post->post_content, 'fsbhoa_cardholder_management' ) 
            && ! has_shortcode( $post->post_content, 'fsbhoa_import_form' )  
            && ! has_shortcode( $post->post_content, 'fsbhoa_print_card' )) ) {
            return;
        }

        // --- Load assets needed for all views ---
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style('fsbhoa-shared-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-shared-styles.css', array(), FSBHOA_AC_VERSION);
        wp_enqueue_style('datatables-style', 'https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css');
        wp_enqueue_script('datatables-script', 'https://cdn.datatables.net/2.0.8/js/dataTables.js', array('jquery'), '2.0.8', true);


        // --- CARDHOLDER-SPECIFIC ASSETS ---
        $app_script_handle = 'fsbhoa-cardholder-admin-script';
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style('croppie-style', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css', array(), '2.6.5');
        wp_enqueue_style('fsbhoa-cardholder-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-cardholder-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_VERSION);
        wp_enqueue_script('croppie-script', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js', array('jquery'), '2.6.5', true);
        wp_enqueue_script('fsbhoa-photo-croppie', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-photo-croppie.js', array('jquery', 'jquery-ui-dialog', 'croppie-script'), FSBHOA_AC_VERSION, true);
        wp_enqueue_script($app_script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-cardholder-admin.js', array('jquery', 'jquery-ui-autocomplete', 'datatables-script', 'fsbhoa-photo-croppie'), FSBHOA_AC_VERSION, true);
            

        // --- PRINT PAGE ---
        if ( has_shortcode( $post->post_content, 'fsbhoa_print_card' ) ) {
            // Enqueue our stylesheet
            wp_enqueue_style(
                'fsbhoa-print-styles',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-print-styles.css',
                array(),
                FSBHOA_AC_VERSION
            );

            // Enqueue our new workflow script
            wp_enqueue_script(
                'fsbhoa-print-workflow',
                FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-print-workflow.js',
                array('jquery'),
                FSBHOA_AC_VERSION,
                true
            );

            // Localize the script, passing the AJAX URL and a security nonce
            wp_localize_script(
                'fsbhoa-print-workflow',
                'fsbhoa_print_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('fsbhoa_print_card_nonce'),
                    'cardholder_page_url' => get_permalink(get_page_by_path('cardholder'))
                )
            );
        }

        
        // Localized data for the scripts
        $photo_settings = array('width'  => get_option('fsbhoa_ac_photo_width', 640), 'height' => get_option('fsbhoa_ac_photo_height', 800));
        wp_localize_script($app_script_handle, 'fsbhoa_photo_settings', $photo_settings);
        $ajax_settings = array('ajax_url' => admin_url('admin-ajax.php'), 'property_search_nonce' => wp_create_nonce('fsbhoa_property_search_nonce'));
        wp_localize_script($app_script_handle, 'fsbhoa_ajax_settings', $ajax_settings);

        // --- PROPERTY-SPECIFIC ASSETS ---
        wp_enqueue_style('fsbhoa-property-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-property-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_VERSION);
        wp_enqueue_script('fsbhoa-property-admin', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-property-admin.js', array('jquery', 'datatables-script'), FSBHOA_AC_VERSION, true);


        // ---  for Import Styles ---
        if ( has_shortcode( $post->post_content, 'fsbhoa_import_form' ) ) {
            wp_enqueue_style(
                'fsbhoa-import-styles',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-import-styles.css',
                array(),
                FSBHOA_AC_VERSION
            );
        }
    }


    /**
     * Renders the printable ID card view by loading the dedicated view file.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string The HTML output for the printable card.
     */
    public function render_print_card_shortcode( $atts ) {
        // Security check: Only logged-in users with the right capability can view.
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have permission to view this page.', 'fsbhoa-ac' ) . '</p>';
        }

        // Capture the output from our dedicated view file.
        ob_start();
    
        // Include the view file that does all the work.
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-print-card.php';
    
        // Call the main function from that file.
        fsbhoa_render_printable_card_view();

        return ob_get_clean();
    }

}
