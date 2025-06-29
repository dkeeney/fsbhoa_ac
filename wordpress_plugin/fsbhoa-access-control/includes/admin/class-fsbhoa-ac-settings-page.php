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
        // ---  Backend Services Settings ---

		// Add the new section to the page
		add_settings_section(
			'fsbhoa_ac_services_section',
			__( 'Backend Service Settings', 'fsbhoa-ac' ),
			array( $this, 'render_services_section_info' ),
			$this->settings_page_slug
		);

		// Register the setting for the Controller REST Port
		register_setting($this->option_group, 'fsbhoa_ac_rest_port', 'absint');
		add_settings_field(
			'fsbhoa_ac_rest_port_field',
			__( 'Controller REST Service Port', 'fsbhoa-ac' ),
			array( $this, 'render_rest_port_field' ),
			$this->settings_page_slug,
			'fsbhoa_ac_services_section'
		);

		// Register the setting for the Real-Time Event Port
		register_setting($this->option_group, 'fsbhoa_ac_event_port', 'absint');
		add_settings_field(
			'fsbhoa_ac_event_port_field',
			__( 'Real-Time Event Service Port', 'fsbhoa-ac' ),
			array( $this, 'render_event_port_field' ),
			$this->settings_page_slug,
			'fsbhoa_ac_services_section'
		);

		// Register the setting for the Print Service Port
		register_setting($this->option_group, 'fsbhoa_ac_print_port', 'absint');
		add_settings_field(
			'fsbhoa_ac_print_port_field',
			__( 'Zebra Print Service Port', 'fsbhoa-ac' ),
			array( $this, 'render_print_port_field' ),
			$this->settings_page_slug,
			'fsbhoa_ac_services_section'
		);


        // Register the setting for the Event Service Log Path
		register_setting($this->option_group, 'fsbhoa_ac_event_log_path', 'sanitize_text_field');
		add_settings_field(
			'fsbhoa_ac_event_log_path_field',
			__( 'Real-Time Event Service Log Path', 'fsbhoa-ac' ),
			array( $this, 'render_event_log_path_field' ),
			$this->settings_page_slug,
			'fsbhoa_ac_services_section'
		);

		// Register the setting for the Print Service Log Path
		register_setting($this->option_group, 'fsbhoa_ac_print_log_path', 'sanitize_text_field');
		add_settings_field(
			'fsbhoa_ac_print_log_path_field',
			__( 'Zebra Print Service Log Path', 'fsbhoa-ac' ),
			array( $this, 'render_print_log_path_field' ),
			$this->settings_page_slug,
			'fsbhoa_ac_services_section'
        );

        // Register the setting for the TLS Certificate Path
		register_setting($this->option_group, 'fsbhoa_ac_tls_cert_path', 'sanitize_text_field');
		add_settings_field(
			'fsbhoa_ac_tls_cert_path_field',
			__( 'TLS Certificate Path (.crt)', 'fsbhoa-ac' ),
			array( $this, 'render_tls_cert_path_field' ),
			$this->settings_page_slug,
			'fsbhoa_ac_services_section'
		);

		// Register the setting for the TLS Key Path
		register_setting($this->option_group, 'fsbhoa_ac_tls_key_path', 'sanitize_text_field');
		add_settings_field(
			'fsbhoa_ac_tls_key_path_field',
			__( 'TLS Key Path (.key)', 'fsbhoa-ac' ),
			array( $this, 'render_tls_key_path_field' ),
			$this->settings_page_slug,
			'fsbhoa_ac_services_section'
		);
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

    // ---  RENDER FUNCTIONS FOR SERVICE PORTS ---

	public function render_services_section_info() {
		echo '<p>' . esc_html__( 'Configure the network ports for the backend Go services that this plugin communicates with.', 'fsbhoa-ac' ) . '</p>';
	}

	public function render_rest_port_field() {
		$port = get_option( 'fsbhoa_ac_rest_port', 8082 ); // Default to 8082
		echo '<input type="number" name="fsbhoa_ac_rest_port" value="' . esc_attr( $port ) . '" class="small-text" min="1024" max="65535" />';
		echo '<p class="description">' . esc_html__( 'The port for the uhppoted-rest service that manages controllers.', 'fsbhoa-ac' ) . '</p>';
	}

	public function render_event_port_field() {
		$port = get_option( 'fsbhoa_ac_event_port', 8081 ); // Default to 8083
		echo '<input type="number" name="fsbhoa_ac_event_port" value="' . esc_attr( $port ) . '" class="small-text" min="1024" max="65535" />';
		echo '<p class="description">' . esc_html__( 'The WebSocket port for the real-time event service.', 'fsbhoa-ac' ) . '</p>';
	}

	public function render_print_port_field() {
		$port = get_option( 'fsbhoa_ac_print_port', 8083 ); // Defaulting to 8081, adjust if needed
		echo '<input type="number" name="fsbhoa_ac_print_port" value="' . esc_attr( $port ) . '" class="small-text" min="1024" max="65535" />';
		echo '<p class="description">' . esc_html__( 'The port for the Zebra card printing service.', 'fsbhoa-ac' ) . '</p>';
	}

    public function render_event_log_path_field() {
		// Default to a standard log location, but this can be changed by the admin.
		$path = get_option( 'fsbhoa_ac_event_log_path', '/var/log/fsbhoa_event_service.log' );
		echo '<input type="text" name="fsbhoa_ac_event_log_path" value="' . esc_attr( $path ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Absolute path to the log file for the event service. The user running the service needs write permissions to this file/directory.', 'fsbhoa-ac' ) . '</p>';
	}

	public function render_print_log_path_field() {
		$path = get_option( 'fsbhoa_ac_print_log_path', '/var/log/fsbhoa_print_service.log' );
		echo '<input type="text" name="fsbhoa_ac_print_log_path" value="' . esc_attr( $path ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Absolute path to the log file for the print service.', 'fsbhoa-ac' ) . '</p>';
	}

    public function render_tls_cert_path_field() {
		$path = get_option( 'fsbhoa_ac_tls_cert_path', '/etc/ssl/nas.local/nas.local.crt' );
		echo '<input type="text" name="fsbhoa_ac_tls_cert_path" value="' . esc_attr( $path ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Absolute path to the SSL/TLS certificate file for secure WebSockets (WSS).', 'fsbhoa-ac' ) . '</p>';
	}

	public function render_tls_key_path_field() {
		$path = get_option( 'fsbhoa_ac_tls_key_path', '/etc/ssl/nas.local/nas.local.key' );
		echo '<input type="text" name="fsbhoa_ac_tls_key_path" value="' . esc_attr( $path ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Absolute path to the SSL/TLS private key file.', 'fsbhoa-ac' ) . '</p>';
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
