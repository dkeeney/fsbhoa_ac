<?php
/**
 * FSBHOA Access Control Settings Page
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     Your Name <you@example.com>
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Ac_Settings_Page {

    /**
     * The unique identifier of this plugin's settings page.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $settings_page_slug
     */
    private $settings_page_slug = 'fsbhoa_ac_settings_page';

    /**
     * The option group for photo settings.
     *
     * @since    1.0.0
     * @access   private
     * @var      string    $photo_option_group
     */
    private $photo_option_group = 'fsbhoa_ac_photo_settings_group';


    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_submenu_page' ) );
        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
    }

    /**
     * Adds a submenu page under the main FSBHOA Access menu.
     *
     * @since    1.0.0
     */
    public function add_settings_submenu_page() {
        add_submenu_page(
            'fsbhoa_ac_main_menu',                         // Parent slug (your main plugin menu slug)
            __( 'FSBHOA Settings', 'fsbhoa-ac' ),          // Page title
            __( 'Settings', 'fsbhoa-ac' ),                 // Menu title
            'manage_options',                            // Capability required
            $this->settings_page_slug,                     // Menu slug for this settings page
            array( $this, 'render_settings_page_html' )    // Callback function to display page content
        );
    }

    /**
     * Initializes the WordPress Settings API for the plugin options.
     *
     * @since    1.0.0
     */
    public function settings_api_init() {
        // Register the photo settings group
        register_setting(
            $this->photo_option_group,
            'fsbhoa_ac_photo_width',
            array( $this, 'sanitize_dimension_callback' )
        );

        register_setting(
            $this->photo_option_group,
            'fsbhoa_ac_photo_height',
            array( $this, 'sanitize_dimension_callback' )
        );

        // Add the photo editor settings section
        add_settings_section(
            'fsbhoa_ac_photo_editor_section',
            __( 'Photo Editor Settings', 'fsbhoa-ac' ),
            array( $this, 'render_photo_editor_section_info' ),
            $this->settings_page_slug
        );

        // Add photo width field
        add_settings_field(
            'fsbhoa_ac_photo_width_field',
            __( 'Photo Width (px)', 'fsbhoa-ac' ),
            array( $this, 'render_photo_width_field' ),
            $this->settings_page_slug,
            'fsbhoa_ac_photo_editor_section',
            array( 'label_for' => 'fsbhoa_ac_photo_width_input' )
        );

        // Add photo height field
        add_settings_field(
            'fsbhoa_ac_photo_height_field',
            __( 'Photo Height (px)', 'fsbhoa-ac' ),
            array( $this, 'render_photo_height_field' ),
            $this->settings_page_slug,
            'fsbhoa_ac_photo_editor_section',
            array( 'label_for' => 'fsbhoa_ac_photo_height_input' )
        );
        
        // TODO: Add more settings sections and fields here for other plugin options later
    }

    /**
     * Sanitizes the dimension input to ensure it's a positive integer.
     *
     * @since    1.0.0
     * @param    string    $input    The input value.
     * @return   int                 The sanitized positive integer.
     */
    public function sanitize_dimension_callback( $input ) {
        return absint( $input );
    }

    /**
     * Renders the informational text for the photo editor settings section.
     *
     * @since    1.0.0
     */
    public function render_photo_editor_section_info() {
        echo '<p>' . esc_html__( 'Configure the output dimensions for photos processed by the ID card editor.', 'fsbhoa-ac' ) . '</p>';
    }

    /**
     * Renders the input field for the photo width setting.
     *
     * @since    1.0.0
     */
    public function render_photo_width_field() {
        $width = get_option( 'fsbhoa_ac_photo_width', 640 ); // Default to 640
        echo '<input type="number" id="fsbhoa_ac_photo_width_input" name="fsbhoa_ac_photo_width" value="' . esc_attr( $width ) . '" class="small-text" min="1" />';
    }

    /**
     * Renders the input field for the photo height setting.
     *
     * @since    1.0.0
     */
    public function render_photo_height_field() {
        $height = get_option( 'fsbhoa_ac_photo_height', 800 ); // Default to 800
        echo '<input type="number" id="fsbhoa_ac_photo_height_input" name="fsbhoa_ac_photo_height" value="' . esc_attr( $height ) . '" class="small-text" min="1" />';
    }

    /**
     * Renders the HTML for the settings page.
     *
     * @since    1.0.0
     */
    public function render_settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                // Output nonce, action, and option_page fields for the photo settings group
                settings_fields( $this->photo_option_group );
                // Print out all sections and fields registered for this page
                do_settings_sections( $this->settings_page_slug );
                // Output submit button
                submit_button( __( 'Save Photo Settings', 'fsbhoa-ac' ) );
                ?>
            </form>
        </div>
        <?php
    }
}
