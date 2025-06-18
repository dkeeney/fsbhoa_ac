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
     * @var      string    $settings_page_slug
     */
    private $settings_page_slug = 'fsbhoa_ac_settings_page';

    /**
     * The option group for photo settings and other things.
     * @var      string    $option_group
     */
    private $option_group = 'fsbhoa_ac_settings_group';

    /**
     * The option group for import settings.
     * @var      string    Address sufix to remove.
     */
    private $display_option_group = 'fsbhoa_ac_display_settings_group';

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
    }

    /**
     * Adds a submenu page under the main FSBHOA Access menu.
     *
     * @since    1.0.0
     */
    public function add_plugin_admin_menu() {
        // Add the main top-level menu page.
        add_menu_page(
            __('FSBHOA Settings', 'fsbhoa-ac'),            // Page title
            __('FSBHOA AC', 'fsbhoa-ac'),                  // Menu title
            'manage_options',                             // Capability
            'fsbhoa_ac_main_menu',                        // The unique slug for this menu
            array( $this, 'render_settings_page_html' ),  // The callback to render the settings page
            'dashicons-id-alt',                           // Icon
            25                                            // Position
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
            $this->option_group,
            'fsbhoa_ac_photo_width',
            array( $this, 'sanitize_dimension_callback' )
        );

        register_setting(
            $this->option_group,
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
        // --- Address import Settings Group ---
        register_setting($this->display_option_group, 'fsbhoa_ac_address_suffix', 'sanitize_text_field');

        add_settings_section('fsbhoa_ac_display_options_section', __('Display Options', 'fsbhoa-ac'), array( $this, 'render_display_section_info' ), $this->settings_page_slug);
        add_settings_field('fsbhoa_ac_address_suffix_field', __('Address Suffix to Remove', 'fsbhoa-ac'), array( $this, 'render_address_suffix_field' ), $this->settings_page_slug, 'fsbhoa_ac_display_options_section');
        
        // --- Display Settings Group ---
        register_setting($this->option_group, 'fsbhoa_ac_address_suffix', 'sanitize_text_field');

        add_settings_section('fsbhoa_ac_display_options_section', __('Display Options', 'fsbhoa-ac'), array( $this, 'render_display_section_info' ), $this->settings_page_slug);

        add_settings_field('fsbhoa_ac_address_suffix_field', __('Address Suffix to Remove', 'fsbhoa-ac'), array( $this, 'render_address_suffix_field' ), $this->settings_page_slug, 'fsbhoa_ac_display_options_section');
        //
        //
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

    // ---  RENDER FUNCTIONS FOR IMPORT DISPLAY SECTION ---
    public function render_display_section_info() {
        echo '<p>' . esc_html__( 'Settings that control how data is displayed in lists throughout the application.', 'fsbhoa-ac' ) . '</p>';
    }


    public function render_address_suffix_field() {
        $suffix = get_option( 'fsbhoa_ac_address_suffix', '' );
        echo '<input type="text" name="fsbhoa_ac_address_suffix" value="' . esc_attr( $suffix ) . '" class="regular-text" placeholder="e.g., Bakersfield, CA 93306" />';
        echo '<p class="description">' . esc_html__( 'This text will be removed from the end of property addresses in display lists.', 'fsbhoa-ac' ) . '</p>';
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
                settings_fields( $this->option_group );
                // Print out all sections and fields registered for this page
                do_settings_sections( $this->settings_page_slug );
                // Output submit button
                submit_button( __( 'Save Settings', 'fsbhoa-ac' ) );
                ?>
            </form>
        </div>
        <?php
    }
}
