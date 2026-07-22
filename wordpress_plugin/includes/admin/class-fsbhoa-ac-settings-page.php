<?php
if ( ! defined( 'WPINC' ) ) { die; }

require_once FSBHOA_AC_PLUGIN_DIR . 'includes/fsbhoa-sync-functions.php';

class Fsbhoa_Ac_Settings_Page {
    private const DEFAULT_PRINT_API_TOKEN = 'eZdaPzde/0JGMirn6DV4VPSErRerexAiqZBCQj/T3Vg=';

    private $parent_slug = 'fsbhoa_ac_main_menu';

    // Where to write the config files for the services
    private $event_service_config_path = '/var/lib/fsbhoa/event_service.json';
    private $monitor_service_config_path = '/var/lib/fsbhoa/monitor_service.json';
    private $event_service_option_group = 'fsbhoa_event_service_options';
    private $monitor_settings_option_group = 'fsbhoa_monitor_options';
    private $print_service_config_path = '/var/lib/fsbhoa/zebra_print_service.json';
    private $kiosk_config_path           = '/var/lib/fsbhoa/kiosk.json';

    public function __construct() {
        $this->ensure_system_users_exist();

        // Automatically set the default API token if it doesn't exist
        if (!get_option('fsbhoa_ac_print_api_token')) {
            update_option('fsbhoa_ac_print_api_token', self::DEFAULT_PRINT_API_TOKEN);
        }
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_fsbhoa_save_monitor_settings', array( $this, 'ajax_save_monitor_settings' ) );
        add_action( 'wp_ajax_fsbhoa_save_general_settings', array( $this, 'ajax_save_general_settings' ) );
        add_action( 'wp_ajax_fsbhoa_save_event_settings', array( $this, 'ajax_save_event_settings' ) );
	add_action( 'wp_ajax_fsbhoa_save_print_settings', array( $this, 'ajax_save_print_settings' ) );
        add_action( 'wp_ajax_fsbhoa_save_kiosk_settings', array( $this, 'ajax_save_kiosk_settings' ) );
        add_action( 'wp_ajax_fsbhoa_generate_api_key', array( $this, 'ajax_generate_api_key' ) );
        add_action('wp_ajax_fsbhoa_save_pool_alarm', [$this, 'ajax_save_pool_alarm']);
        add_action('wp_ajax_fsbhoa_trigger_pool_alarm', [$this, 'ajax_trigger_pool_alarm']);
        add_action('update_option', array($this, 'trigger_config_update_on_save'), 10, 3);
    }

    public function add_plugin_admin_menu() {
        add_menu_page('FSBHOA General Settings', 'FSBHOA AC', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page' ), 'dashicons-id-alt', 25);
        add_submenu_page($this->parent_slug, 'General Settings', 'General Settings', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page' ));
        add_submenu_page($this->parent_slug, 'Event Service Config', 'Event Service', 'manage_options', 'fsbhoa_event_service_settings', array( $this, 'render_event_service_page' ));
        add_submenu_page($this->parent_slug, 'Print Service Config', 'Print Service', 'manage_options', 'fsbhoa_print_service_settings', array( $this, 'render_print_service_page' ));
        add_submenu_page($this->parent_slug, 'Live Monitor Settings', 'Monitor Settings', 'manage_options', 'fsbhoa_monitor_settings', array( $this, 'render_monitor_settings_page' ));
        add_submenu_page($this->parent_slug, 'Kiosk Settings', 'Kiosk', 'manage_options', 'fsbhoa_kiosk_settings', array( $this, 'render_kiosk_settings_page' ));
        add_submenu_page(
            $this->parent_slug,
            'Pool Intrusion Alarm',
            'Pool Alarm',
            'manage_options',
            'fsbhoa-ac-pool-alarm',
            [$this, 'render_pool_alarm_page']
        );
    }

    /**
     * Master function to write ALL service configuration files.
     * This is the single source of truth for generating configs. It reads all
     * values directly from the database to ensure consistency.
     * It should be called after any relevant options have been updated.
     *
     * @return void
     */
    private function update_all_service_configs() {
        // --- Gather all settings from the database ---
        $monitor_port     = get_option('fsbhoa_ac_monitor_port', 8082);
        $websocket_port   = get_option('fsbhoa_ac_websocket_port', 8083);
        $tls_cert_path    = get_option('fsbhoa_ac_tls_cert_path', '/etc/letsencrypt/live/nas.fsbhoa.com/fullchain.pem');
        $tls_key_path     = get_option('fsbhoa_ac_tls_key_path', '/etc/letsencrypt/live/nas.fsbhoa.com/privkey.pem');
        $bind_addr        = get_option('fsbhoa_ac_bind_addr', '0.0.0.0:0');
        $broadcast_addr   = get_option('fsbhoa_ac_broadcast_addr', '192.168.42.255:60000');
        $listen_port      = get_option('fsbhoa_ac_listen_port', 60002);
        $callback_host    = get_option('fsbhoa_ac_callback_host', '192.168.42.99');
        $wp_host          = get_option('fsbhoa_ac_wp_host', 'access.fsbhoa.com');
        // Determine the correct protocol based on whether TLS is configured.
        $protocol         = (!empty($tls_cert_path) && !empty($tls_key_path)) ? 'https' : 'http';
        $wp_port          = get_option('fsbhoa_ac_wp_port', 443);
        $event_log_path   = get_option('fsbhoa_ac_event_log_path', '');
        $debug_mode       = get_option('fsbhoa_ac_debug_mode', 'on');
        $test_stub        = get_option('fsbhoa_ac_test_stub', 'on');
        $kiosk_port       = get_option('fsbhoa_kiosk_port', 8080);

        $pool_alarm_enabled     = get_option('fsbhoa_pool_alarm_enabled', '0');
        $pool_alarm_enable_url  = get_option('fsbhoa_pool_alarm_enable_url', '');
        $pool_alarm_disable_url = get_option('fsbhoa_pool_alarm_disable_url', '');
        $pool_alarm_gates       = get_option('fsbhoa_pool_alarm_gates', []);

        // --- Build and write monitor_service.json ---
        $monitor_config = [
            'listen_addr'       => ':' . absint($monitor_port),
            'wordpress_api'     => get_site_url() . '/wp-json/fsbhoa/v1/monitor/event',
            'tls_cert_path'     => sanitize_text_field($tls_cert_path),
            'tls_key_path'      => sanitize_text_field($tls_key_path),
            'event_service_url' => sprintf('%s://%s:%d', $protocol, $wp_host, absint($websocket_port)),
            'photo_event_limit' => (int) get_option('fsbhoa_ac_monitor_photo_limit', 3),
            'kiosk_service_url' => sprintf('%s://%s:%d', $protocol, $wp_host, absint($kiosk_port)),
            'api_key'           => get_option('fsbhoa_ac_kiosk_api_key', ''),
        ];
        $this->write_config_file($this->monitor_service_config_path, $monitor_config);

        // --- Build and write event_service.json ---
        $event_config = [
            'bindAddress'       => sanitize_text_field($bind_addr),
            'broadcastAddress'  => sanitize_text_field($broadcast_addr),
            'listenPort'        => absint($listen_port),
            'callbackHost'      => sanitize_text_field($callback_host),
            'webSocketPort'     => absint($websocket_port),
            'wpURL'             => sprintf('%s://%s:%d',  $protocol, $wp_host, absint($wp_port)),
            'tlsCert'           => sanitize_text_field($tls_cert_path),
            'tlsKey'            => sanitize_text_field($tls_key_path),
            'logFile'           => sanitize_text_field($event_log_path),
            'debug'             => ($debug_mode === 'on'),
            'enableTestStub'    => ($test_stub === 'on'),
            'monitorServiceURL' => sprintf('%s://%s:%d', $protocol, $wp_host, absint($monitor_port)),
            'pool_alarm'        => [
                'enabled'       => ($pool_alarm_enabled === '1'),
                'enable_url'    => $pool_alarm_enable_url,
                'disable_url'   => $pool_alarm_disable_url,
                'trigger_gates' => array_map('intval', (array) $pool_alarm_gates)
            ]
        ];
        $this->write_config_file($this->event_service_config_path, $event_config);

        $print_config = [
            'port'      => (int) get_option('fsbhoa_ac_print_port', 8081),
            'api_url'   => get_site_url() . '/wp-json/fsbhoa/v1/print_log_update',
            'api_token' => get_option('fsbhoa_ac_print_api_token', ''),
            'printer_name' => get_option('fsbhoa_ac_printer_name', 'Zebra-ZC300'),
            'debug_mode'   => (get_option('fsbhoa_ac_print_debug_mode', 'off') === 'on'),
        ];
        $this->write_config_file($this->print_service_config_path, $print_config);

        // --- Build and write kiosk.json ---
	    $kiosk_config = [
		    'wordpress_api_base_url' => get_site_url(),
            'api_key'                => get_option('fsbhoa_ac_kiosk_api_key', ''),
		    'ssl_cert_path'          => sanitize_text_field($tls_cert_path),
		    'ssl_key_path'           => sanitize_text_field($tls_key_path),
		    'port'                   => ':' . absint(get_option('fsbhoa_kiosk_port', 8080)),
		    'log_file'               => sanitize_text_field(get_option('fsbhoa_kiosk_log_file', '/var/log/fsbhoa/kiosk.log')),
            'max_guests'             => (int) get_option('fsbhoa_kiosk_max_guests', 8),
            'monitor_service_url'    => sprintf('%s://%s:%d', $protocol, $wp_host, absint($monitor_port)),
            'event_service_url'      => sprintf('%s://%s:%d', ($protocol === 'https' ? 'wss' : 'ws'), $wp_host, absint($websocket_port)),
	    ];
	    $this->write_config_file($this->kiosk_config_path, $kiosk_config);

        // NOTE: Future config files (e.g., for print_service) can be added here.
    }
    
    // Helper function to write JSON config files
    private function write_config_file($path, $data) {
        $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $config_dir = dirname($path);
        if (!is_dir($config_dir)) {
            mkdir($config_dir, 0755, true);
        }
        file_put_contents($path, $json_data);
    }



    public function settings_api_init() {
        // --- Option Groups ---
        $general_option_group = 'fsbhoa_general_options';
        $event_service_option_group = 'fsbhoa_event_service_options';
        $print_service_option_group = 'fsbhoa_print_service_options';
        $monitor_settings_option_group = 'fsbhoa_monitor_options';
        $kiosk_option_group = 'fsbhoa_kiosk_options';

        // --- Page Slugs ---
        $general_page_slug = $this->parent_slug;
        $event_service_page_slug = 'fsbhoa_event_service_settings';
        $print_service_page_slug = 'fsbhoa_print_service_settings';
        $kiosk_page_slug = 'fsbhoa_kiosk_settings';

        // ====================================================================
        // --- GENERAL SETTINGS ---
        // ====================================================================
        // Section: Photo Editor
        add_settings_section('fsbhoa_ac_photo_editor_section', 'Photo Editor Settings', null, $general_page_slug);
        add_settings_field('fsbhoa_ac_photo_width_field', 'Photo Width (px)', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_photo_editor_section', ['id' => 'fsbhoa_ac_photo_width', 'type' => 'number', 'default' => 640]);
        add_settings_field('fsbhoa_ac_photo_height_field', 'Photo Height (px)', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_photo_editor_section', ['id' => 'fsbhoa_ac_photo_height', 'type' => 'number', 'default' => 800]);
        
        // Section: Display Options
        add_settings_section('fsbhoa_ac_display_options_section', 'Display Options', null, $general_page_slug);
        add_settings_field('fsbhoa_ac_address_suffix_field', 'Address Suffix to Remove', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_display_options_section', ['id' => 'fsbhoa_ac_address_suffix', 'type' => 'text', 'default' => 'Bakersfield, CA 93306', 'desc' => 'This text will be removed from property addresses in display lists.']);

        // Service Communication 
        add_settings_section('fsbhoa_ac_service_comm_section', 'Service Communication Settings', null, $general_page_slug);
        $comm_fields = [
            'fsbhoa_ac_wp_host'         => ['label' => 'WordPress API Host', 'default' => 'nas.fsbhoa.com'],
            'fsbhoa_ac_wp_port'         => ['label' => 'WordPress API Port', 'type' => 'number', 'default' => 443],
            'fsbhoa_ac_tls_cert_path'   => ['label' => 'TLS Certificate Path', 'default' => '/etc/letsencrypt/live/nas.fsbhoa.com/fullchain.pem'],
            'fsbhoa_ac_tls_key_path'    => ['label' => 'TLS Key Path', 'default' => '/etc/letsencrypt/live/nas.fsbhoa.com/privkey.pem'],
        ];
        foreach ($comm_fields as $id => $field) {
            add_settings_field($id . '_field', $field['label'], array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_service_comm_section', ['id' => $id] + $field);
        }
        add_settings_section('fsbhoa_ac_api_keys_section', 'API Key Settings', null, $general_page_slug);
        add_settings_section('fsbhoa_ac_operational_section', 'Operational Settings', null, $general_page_slug);
        add_settings_field(
            'fsbhoa_ac_rate_limit_minutes_field',
            'Swipe Rate-Limit (Minutes)',
            array($this, 'render_field_callback'),
            $general_page_slug,
            'fsbhoa_ac_operational_section',
            [
                'id'      => 'fsbhoa_ac_rate_limit_minutes',
                'type'    => 'number',
                'default' => 10,
                'desc'    => 'Ignore duplicate swipes from the same person at the same location within this time. Set to 0 to disable.'
            ]
        );

        add_settings_field(
            'fsbhoa_ac_api_key_field',
            'CSV Import API Key',
            array($this, 'render_api_key_field'),
            $general_page_slug,
            'fsbhoa_ac_api_keys_section',
            ['id' => 'fsbhoa_ac_api_key', 'desc' => 'Secret key used to authorize automated CSV imports via the REST API.']
        );
        // Section: Amenity Tracking
        add_settings_section('fsbhoa_ac_amenity_tracking_section', 'Amenity Tracking Settings', null, $general_page_slug);
        add_settings_field(
            'fsbhoa_ac_amenity_clear_minutes_field',
            'Amenity Clearing Window (Minutes)',
            array($this, 'render_field_callback'),
            $general_page_slug,
            'fsbhoa_ac_amenity_tracking_section',
            [
                'id'      => 'fsbhoa_ac_amenity_clear_minutes',
                'type'    => 'number',
                'default' => 10,
                'desc'    => 'The time window (in minutes) to look back for a previous entry (Kiosk/Entry Gate) to be cleared by an INNER_GATE swipe. Set to 0 to disable clearing logic.'
            ]
        );
        add_settings_field(
            'fsbhoa_ac_default_court_amenity_name_field', 
            'After Hours Court Default Name',
            array($this, 'render_field_callback'), 
            $general_page_slug,
            'fsbhoa_ac_amenity_tracking_section',
            [
                'id'      => 'fsbhoa_ac_default_court_amenity_name', 
                'type'    => 'text',
                'default' => 'Courts', // Set a reasonable default name
                'desc'    => 'The descriptive name (e.g., "Courts") used to log amenity usage when a resident uses the After Hours Entry (West Gate) but not an inner amenity gate.'
            ]
        );
        
        // Register all General settings
        register_setting($general_option_group, 'fsbhoa_ac_photo_width', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_photo_height', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_address_suffix', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_wp_host', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_wp_port', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_tls_cert_path', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_tls_key_path', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_api_key', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_rate_limit_minutes', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_amenity_clear_minutes', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_default_court_amenity_name', 'sanitize_text_field');

        // ====================================================================
        // --- EVENT SERVICE SETTINGS ---
        // ====================================================================
        add_settings_section('fsbhoa_event_service_section', null, null, $event_service_page_slug);
        $event_fields = [
            'fsbhoa_ac_bind_addr'        => ['label' => 'Bind Address', 'default' => '0.0.0.0:0'],
            'fsbhoa_ac_broadcast_addr'   => ['label' => 'Broadcast Address', 'default' => '192.168.42.255:60000'],
            'fsbhoa_ac_listen_port'      => ['label' => 'Event Listener Port', 'type' => 'number', 'default' => 60002],
            'fsbhoa_ac_callback_host'    => ['label' => 'Event Callback Host IP', 'default' => '192.168.42.99'],
            'fsbhoa_ac_websocket_port'   => ['label' => 'WebSocket Service Port', 'type' => 'number', 'default' => 8083],
            'fsbhoa_ac_event_log_path'   => ['label' => 'Event Service Log Path', 'default' => '', 'desc' => 'Leave empty for console output.'],
            'fsbhoa_ac_debug_mode'       => ['label' => 'Debug Mode', 'type' => 'checkbox', 'default' => 'on'],
            'fsbhoa_ac_sync_dry_run'    => ['label' => 'Enable Sync Dry Run', 'type' => 'checkbox', 'desc' => 'Calculates all changes but does not send commands to controllers. Logs intended actions instead.'],
            'fsbhoa_ac_test_stub'        => ['label' => 'Enable Test Stub', 'type' => 'checkbox', 'default' => 'on'],
        ];
        foreach ($event_fields as $id => $field) {
            register_setting($event_service_option_group, $id, ['sanitize_callback' => 'sanitize_text_field']);
            add_settings_field($id . '_field', $field['label'], array($this, 'render_field_callback'), $event_service_page_slug, 'fsbhoa_event_service_section', ['id' => $id] + $field);
        }


        // ====================================================================
        // --- PRINT SERVICE SETTINGS ---
        // ====================================================================
        add_settings_section('fsbhoa_print_service_section', 'Print Service Settings', null, $print_service_page_slug);
        add_settings_field('fsbhoa_ac_print_port_field', 'Zebra Print Service Port', array($this, 'render_field_callback'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_port', 'type' => 'number', 'default' => 8081]);
        add_settings_field('fsbhoa_ac_printer_name_field', 'CUPS Printer Name', array($this, 'render_field_callback'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_printer_name', 'type' => 'text', 'default' => 'Zebra-ZC300', 'desc' => 'The exact name of the printer queue in CUPS.']);
        add_settings_field('fsbhoa_ac_print_debug_mode_field', 'Debug Mode (Dry Run)', array($this, 'render_field_callback'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_debug_mode', 'type' => 'checkbox', 'desc' => 'If checked, the service will only generate the image file in /var/tmp and will NOT send it to the printer.']);
        add_settings_field('fsbhoa_ac_card_back_url_field', 'Card Back Logo', array($this, 'render_media_uploader_field'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_card_back_url', 'desc' => 'Select an image from the Media Library for the back of the card.']);
        add_settings_field('fsbhoa_ac_print_template_path_field', 'Print Template JSON Path', array($this, 'render_field_callback'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_template_path', 'type' => 'text', 'desc' => 'Full server path to the print template JSON file.']);

        register_setting($print_service_option_group, 'fsbhoa_ac_print_port', 'absint');
        register_setting($print_service_option_group, 'fsbhoa_ac_printer_name', 'sanitize_text_field');
        register_setting($print_service_option_group, 'fsbhoa_ac_print_debug_mode', 'sanitize_text_field');
        register_setting($print_service_option_group, 'fsbhoa_ac_card_back_url', 'esc_url_raw');
        register_setting($print_service_option_group, 'fsbhoa_ac_print_template_path', 'sanitize_text_field');
	register_setting($print_service_option_group, 'fsbhoa_ac_print_api_token', 'sanitize_text_field');


        // ====================================================================
        // --- MONITOR SETTINGS ---
        // ====================================================================

        register_setting($this->monitor_settings_option_group, 'fsbhoa_monitor_map_url', 'esc_url_raw');
        register_setting($this->monitor_settings_option_group, 'fsbhoa_ac_monitor_port', 'absint');
        register_setting($this->monitor_settings_option_group, 'fsbhoa_ac_monitor_photo_limit', 'absint');


        // ====================================================================
        // --- KIOSK SETTINGS ---
        // ====================================================================
        $kiosk_option_group = 'fsbhoa_kiosk_options';
        $kiosk_page_slug = 'fsbhoa_kiosk_settings';

        add_settings_section(
            'fsbhoa_ac_kiosk_logo_section',
            'Display Settings',
            null,
            $kiosk_page_slug
        );

        add_settings_field(
            'fsbhoa_kiosk_logo_url_field',
            'Kiosk Logo URL',
            array($this, 'render_media_uploader_field'),
            $kiosk_page_slug,
            'fsbhoa_ac_kiosk_logo_section',
            [
                'id' => 'fsbhoa_kiosk_logo_url',
                'type' => 'url',
                'desc' => 'URL for the logo displayed in heading of the kiosk idle screen.'
            ]
        );
        register_setting(
            $kiosk_option_group,
            'fsbhoa_kiosk_logo_url',
            'esc_url_raw'
        );

        add_settings_field(
            'fsbhoa_kiosk_splash_url_field',
            'Kiosk Splash Image',
            array($this, 'render_media_uploader_field'),
            $kiosk_page_slug,
            'fsbhoa_ac_kiosk_logo_section',
            [
                'id' => 'fsbhoa_kiosk_splash_url',
                'type' => 'url',
                'desc' => 'Image displayed for 2 seconds after a resident makes a selection. If blank, display selected icon instead.'
            ]
        );
        register_setting(
            $kiosk_option_group,
            'fsbhoa_kiosk_splash_url',
            'esc_url_raw'
        );

        add_settings_field(
            'fsbhoa_kiosk_name_field',                  // Field ID
            'Kiosk Display Name',                       // Field Title
            array($this, 'render_field_callback'),      // Re-use your existing render function
            $kiosk_page_slug,                           // Page slug
            'fsbhoa_ac_kiosk_logo_section',             // Section to display in
            [                                           // Arguments
                'id' => 'fsbhoa_kiosk_name', 
                'type' => 'text', 
                'default' => 'Front Desk Kiosk',
                'desc' => 'The name displayed for kiosk events on the Real-time Display.'
            ]
        );

        register_setting(
            $kiosk_option_group,
            'fsbhoa_kiosk_name',
            'sanitize_text_field'
        );

        // Add Port setting
	add_settings_field(
		'fsbhoa_kiosk_port_field',
		'Kiosk Service Port',
		array($this, 'render_field_callback'),
		$kiosk_page_slug,
		'fsbhoa_ac_kiosk_logo_section',
		[
			'id'      => 'fsbhoa_kiosk_port',
			'type'    => 'number',
			'default' => 8080,
			'desc'    => 'The port the kiosk service listens on for secure HTTPS connections.'
		]
	);
	register_setting($kiosk_option_group, 'fsbhoa_kiosk_port', 'absint');

        // Add API Key
        add_settings_field(
            'fsbhoa_ac_kiosk_api_key_field',
            'Kiosk API Key',
            array($this, 'render_api_key_field'),
            $kiosk_page_slug,
            'fsbhoa_ac_kiosk_logo_section',
            [
                'id' => 'fsbhoa_ac_kiosk_api_key',
                'desc' => 'Secret key used by the Kiosk service to authorize with WordPress.'
            ]
        );
        register_setting($kiosk_option_group, 'fsbhoa_ac_kiosk_api_key', 'sanitize_text_field');

	// Add Log File setting
	add_settings_field(
		'fsbhoa_kiosk_log_file_field',
		'Kiosk Log File Path',
		array($this, 'render_field_callback'),
		$kiosk_page_slug,
		'fsbhoa_ac_kiosk_logo_section',
		[
			'id'      => 'fsbhoa_kiosk_log_file',
			'type'    => 'text',
			'default' => '/var/log/fsbhoa/kiosk.log',
			'desc'    => 'Full server path to the kiosk service log file.'
		]
	);
	register_setting($kiosk_option_group, 'fsbhoa_kiosk_log_file', 'sanitize_text_field');

        add_settings_field(
            'fsbhoa_kiosk_max_guests_field',
            'Max Guests',
            array($this, 'render_field_callback'),
            $kiosk_page_slug,
            'fsbhoa_ac_kiosk_logo_section',
            [
                'id'      => 'fsbhoa_kiosk_max_guests',
                'type'    => 'number',
                'default' => 8,
                'desc'    => 'The maximum number of guests a resident can sign in (e.g., 8 creates buttons for 0-8 guests).'
            ]
        );
        register_setting($kiosk_option_group, 'fsbhoa_kiosk_max_guests', 'absint');
    }


    public function render_field_callback($args) {
        $id      = $args['id'];
        $type    = $args['type'] ?? 'text';
        $default = $args['default'] ?? '';
        $desc    = $args['desc'] ?? '';
        $value   = get_option($id, $default);

        if ($type === 'checkbox') {
            echo "<input type='checkbox' name='{$id}' value='on' " . checked($value, 'on', false) . " />";
        } else {
            echo "<input type='{$type}' name='{$id}' value='" . esc_attr($value) . "' class='regular-text' />";
        }
        if ($desc) {
            echo "<p class='description'>" . esc_html($desc) . "</p>";
        }
    }

    public function render_general_settings_page() {
        ?>
        <div class="wrap" id="fsbhoa-general-settings-page">
            <h1>General Plugin Settings</h1>
            <?php
                // We manually render the sections and fields without a <form> tag
                do_settings_sections($this->parent_slug);
            ?>
             <p class="submit">
                <button type="button" id="fsbhoa-save-general-settings-button" class="button button-primary">Save General Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>
        <?php
    }

    public function render_event_service_page() {
        ?>
        <div class="wrap" id="fsbhoa-event-settings-page">
            <h1>Event Service Configuration</h1>
            <p>These settings control the `event_service` Go application. The configuration file will be automatically generated at <code><?php echo esc_html($this->event_service_config_path); ?></code> when you save changes.</p>
            <?php
                do_settings_sections('fsbhoa_event_service_settings');
            ?>
            <p class="submit">
                <button type="button" id="fsbhoa-save-event-settings-button" class="button button-primary">Save Event Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>
        <?php
    }

    public function render_print_service_page() {
        ?>
        <div class="wrap" id="fsbhoa-print-settings-page">
            <h1>Print Service Configuration</h1>
             <?php
                do_settings_sections('fsbhoa_print_service_settings');
            ?>
            <p class="submit">
                <button type="button" id="fsbhoa-save-print-settings-button" class="button button-primary">Save Print Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>
        <?php
    }

    public function render_monitor_settings_page() {
        ?>
        <div class="wrap">
            <style>
                #fsbhoa-gate-legend ol {
                    margin-top: 0;
                    margin-bottom: 0;
                }
                #fsbhoa-gate-legend li {
                    margin-bottom: 4px; /* Tighter spacing between items */
                    line-height: 1.2;   /* Tighter line height for multi-line text */
                    font-size: 13px;    /* Optional: slightly smaller text if list is long */
                }
            </style>
            <h1>Live Monitor Settings</h1>
            <p>Use these tools to configure the Live Monitor service and its map display.</p>
            <hr>
    
            <h2>Gate Position Editor</h2>
            <p class="description">Upload a map image, then drag the gate markers to their correct positions. All settings will be saved with the button at the bottom.</p>
            <div id="fsbhoa-editor-area" style="display: flex; gap: 20px; margin-top: 1em;">
                
                <div style="flex-basis: 70%;">
                    <div id="fsbhoa-map-editor-container" style="position: relative; border: 2px solid #ccc; display: inline-block;">
                        <img id="fsbhoa-map-editor-bg" src="<?php echo esc_url(get_option('fsbhoa_monitor_map_url', '')); ?>" style="max-width: 100%; display: block; opacity: 0.7;">
                    </div>
                </div>

                <div id="fsbhoa-gate-legend" style="flex-basis: 30%;">
                    <h3 style="margin-top: 0;">Gate Legend</h3>
                    <p class="description">Drag dots on map to set position.</p>
                    <ol style="margin-left: 20px; background: #fff; border: 1px solid #ddd; padding: 10px;"></ol>
                </div>
            </div>
            <p style="margin-top: 15px;">
                <button type="button" class="button" id="fsbhoa_monitor_map_url-button">Upload/Change Map</button>
            </p>
    
            <hr style="margin: 2em 0;">
    
            <h2>Monitor Service Settings</h2>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="fsbhoa_ac_monitor_port">Monitor Service Port (WSS)</label>
                        </th>
                        <td>
                            <input name="fsbhoa_ac_monitor_port" type="number" id="fsbhoa_ac_monitor_port" value="<?php echo esc_attr(get_option('fsbhoa_ac_monitor_port', 8082)); ?>" class="regular-text" />
                            <p class="description">The port the monitor_service listens on for secure WebSocket (WSS) connections.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fsbhoa_monitor_status_group_id">Status Indicator Group</label>
                        </th>
                        <td>
                            <?php 
                            global $wpdb;
                            $groups = $wpdb->get_results("SELECT group_id, group_name FROM ac_groups ORDER BY group_name ASC");
                            $selected_group = get_option('fsbhoa_monitor_status_group_id', 0);
                            ?>
                            <select name="fsbhoa_monitor_status_group_id" id="fsbhoa_monitor_status_group_id">
                                <option value="0">-- None (Always Solid) --</option>
                                <?php foreach ($groups as $g) : ?>
                                    <option value="<?php echo esc_attr($g->group_id); ?>" <?php selected($selected_group, $g->group_id); ?>>
                                        <?php echo esc_html($g->group_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the group (e.g., "Residents") whose schedule will determine if the yellow status dots throb (access allowed) or stay solid (access denied).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fsbhoa_ac_monitor_photo_limit">Photo Event Limit</label>
                        </th>
                        <td>
                            <input name="fsbhoa_ac_monitor_photo_limit" type="number" id="fsbhoa_ac_monitor_photo_limit" value="<?php echo esc_attr(get_option('fsbhoa_ac_monitor_photo_limit', 3)); ?>" class="small-text" />
                            <p class="description">The number of recent events to display with a photo in the activity log.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
    
            <input type="hidden" id="fsbhoa_monitor_map_url" name="fsbhoa_monitor_map_url" value="<?php echo esc_attr(get_option('fsbhoa_monitor_map_url', '')); ?>" />

            <p class="submit">
                <button type="button" id="fsbhoa-save-monitor-settings-button" class="button button-primary">Save All Monitor Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
    
        </div>
        <?php
    }


    public function render_kiosk_settings_page() {
        ?>
        <div class="wrap" id="fsbhoa-kiosk-settings-page">
            <h1>Kiosk Settings</h1>
            <?php
                // We manually render the sections and fields without a <form> tag
                do_settings_sections('fsbhoa_kiosk_settings');
            ?>
            <p class="submit">
                <button type="button" id="fsbhoa-save-kiosk-settings-button" class="button button-primary">Save Kiosk Settings</button>
                <span id="fsbhoa-save-feedback" style="display: none; margin-left: 10px; vertical-align: middle;"></span>
            </p>
        </div>
        <?php
    }

    public function enqueue_admin_assets($hook) {
        // For General, Event, & Print Settings Pages
        $settings_pages = [
            'toplevel_page_fsbhoa_ac_main_menu',
            'fsbhoa-ac_page_fsbhoa_event_service_settings',
            'fsbhoa-ac_page_fsbhoa_print_service_settings',
            'fsbhoa-ac_page_fsbhoa_kiosk_settings',
            'fsbhoa-ac_page_fsbhoa-ac-pool-alarm'
        ];

        if (in_array($hook, $settings_pages)) {
            wp_enqueue_media(); // Needed for the image uploader
            $script_handle = 'fsbhoa-settings-script';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-settings-admin.js', array('jquery'), FSBHOA_AC_VERSION, true);

            wp_localize_script(
                $script_handle,
                'fsbhoa_settings_vars',
                array(
                    'ajax_url'      => admin_url('admin-ajax.php'),
                    'general_nonce' => wp_create_nonce('fsbhoa_general_settings_nonce'),
                    'event_nonce'   => wp_create_nonce('fsbhoa_event_settings_nonce'),
                    'print_nonce'   => wp_create_nonce('fsbhoa_print_settings_nonce'),
                    'kiosk_nonce'   => wp_create_nonce('fsbhoa_kiosk_settings_nonce'),
                    'pool_alarm_nonce' => wp_create_nonce('fsbhoa_pool_alarm_nonce'),
                    'generate_api_key_nonce' => wp_create_nonce('fsbhoa_generate_api_key_nonce'),
                )
            );
        }

        // For Monitor Settings Page
        if ($hook === 'fsbhoa-ac_page_fsbhoa_monitor_settings') {
            wp_enqueue_media();
            wp_enqueue_style('fsbhoa-monitor-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-monitor.css', array(), FSBHOA_AC_VERSION);

            $script_handle = 'fsbhoa-monitor-settings-script';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-monitor-settings.js', array('jquery'), FSBHOA_AC_VERSION, true);

            wp_localize_script(
                $script_handle,
                'fsbhoa_monitor_settings_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('fsbhoa_monitor_settings_nonce'),
                )
            );
        }

    }


    public function ajax_save_monitor_settings() {
        // 1. Security Checks
        check_ajax_referer('fsbhoa_monitor_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        // 2. Process Incoming Data
        $gates_data = isset($_POST['gates']) && is_array($_POST['gates']) ? $_POST['gates'] : [];
        $map_url = isset($_POST['map_url']) ? esc_url_raw($_POST['map_url']) : '';
        $port = isset($_POST['port']) ? absint($_POST['port']) : 8082;
        $status_group = isset($_POST['status_group_id']) ? absint($_POST['status_group_id']) : 0;
        $photo_limit = isset($_POST['photo_limit']) ? absint($_POST['photo_limit']) : 3;
        $errors = [];
        
        // 3. Save Gate Positions to the Database with detailed error checking
        global $wpdb;
        $doors_table = 'ac_doors';
        foreach ($gates_data as $gate) {
            $door_id = absint($gate['id']);
            $map_x = intval($gate['x']); // Stored as integer percentages
            $map_y = intval($gate['y']);

            if ($door_id > 0) {
                $result = $wpdb->update(
                    $doors_table,
                    ['map_x' => $map_x, 'map_y' => $map_y],
                    ['door_record_id' => $door_id],
                    ['%d', '%d'],
                    ['%d']
                );
                
                // If the update fails, capture the specific DB error
                if ($result === false) {
                    $errors[] = "Error updating Gate ID {$door_id}: " . $wpdb->last_error;
                }
            }
        }
        
        // 4. Save Other Settings to wp_options
        update_option('fsbhoa_monitor_map_url', $map_url);
        update_option('fsbhoa_ac_monitor_port', $port);
        update_option('fsbhoa_monitor_status_group_id', $status_group);
        update_option('fsbhoa_ac_monitor_photo_limit', $photo_limit);

        // Trigger Cache Rebuild Immediately so the monitor updates instantly
        if (function_exists('fsbhoa_rebuild_monitor_status_cache')) {
            fsbhoa_rebuild_monitor_status_cache();
        }
        
        // 5. Trigger the Master Config Writer
        // This runs regardless of gate update errors, so the config files are always
        // written based on the options that were successfully saved.
        $this->update_all_service_configs();
        
        // 6. Send Response
        if (empty($errors)) {
            wp_send_json_success('Monitor settings saved and config files updated.');
        } else {
            // Send back the specific database errors
            $error_message = implode("\n", $errors);
            wp_send_json_error($error_message);
        }
    }

    public function ajax_save_general_settings() {
        check_ajax_referer('fsbhoa_general_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        if (!empty($options)) {
            foreach ($options as $option) {
                // All general options are text fields for now, so we can sanitize them the same way.
                // We can add more specific sanitization here if needed in the future.
                update_option(sanitize_key($option['name']), sanitize_text_field($option['value']));
            }
        }

        $this->update_all_service_configs();
        wp_send_json_success('General settings saved.');
    }

    public function ajax_save_event_settings() {
        check_ajax_referer('fsbhoa_event_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        if (!empty($options)) {
            foreach ($options as $option) {
                update_option(sanitize_key($option['name']), sanitize_text_field($option['value']));
            }
        }

        $this->update_all_service_configs();
        wp_send_json_success('Event Service settings saved.');
    }

    public function ajax_save_print_settings() {
        check_ajax_referer('fsbhoa_print_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        if (!empty($options)) {
            foreach ($options as $option) {
                update_option(sanitize_key($option['name']), sanitize_text_field($option['value']));
            }
        }
        
	$this->update_all_service_configs();
        wp_send_json_success('Print Service settings saved.');
    }

    public function ajax_save_kiosk_settings() {
        check_ajax_referer('fsbhoa_kiosk_settings_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        if (!empty($options)) {
            foreach ($options as $option) {
                // Manually save each registered setting for the kiosk page
                if (in_array($option['name'], 
                   ['fsbhoa_kiosk_logo_url', 
                    'fsbhoa_kiosk_splash_url',
                    'fsbhoa_kiosk_name', 
                    'fsbhoa_kiosk_port', 
                    'fsbhoa_kiosk_log_file',
                    'fsbhoa_kiosk_max_guests',
                    'fsbhoa_ac_kiosk_api_key',
                   ])) {
                    update_option(sanitize_key($option['name']), sanitize_text_field($option['value']));
                }
            }
        }

        $this->update_all_service_configs();
        wp_send_json_success('Kiosk settings saved.');
    }


    // For kiosk
    public function trigger_config_update_on_save($option_name, $old_value, $new_value) {
        // A list of options that should trigger a config file rewrite
        $kiosk_options = [
            'fsbhoa_kiosk_logo_url',
            'fsbhoa_kiosk_name',
            'fsbhoa_kiosk_port',
            'fsbhoa_kiosk_log_file'
        ];

        if (in_array($option_name, $kiosk_options)) {
            $this->update_all_service_configs();
        }
    }

    /**
     * Renders the special read-only field for the API key with a generate button.
     */
    public function render_api_key_field($args) {
        $id    = $args['id'];
        $desc  = $args['desc'] ?? '';
        $value = get_option($id, '');
        ?>
        <input type="text" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" readonly="readonly" placeholder="Click generate to create a new key" />
        <button type="button" class="button" id="fsbhoa-generate-<?php echo esc_attr( str_replace('fsbhoa_', '', $id) ); ?>-button">Generate New Key</button>
        <p class="description"><?php echo esc_html($desc); ?></p>
        <?php
    }

    /**
     * AJAX handler to generate a new, cryptographically secure API key.
     */
    public function ajax_generate_api_key() {
        check_ajax_referer('fsbhoa_generate_api_key_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        // Generate a new 32-byte (256-bit) random key and encode it
        $new_key = base64_encode(random_bytes(32));

        wp_send_json_success(['api_key' => $new_key]);
    }

    public function render_media_uploader_field($args) {
        $id    = $args['id'];
        $value = get_option($id, '');
        $desc  = $args['desc'] ?? '';
        ?>
        <input type="text" name="<?php echo esc_attr($id); ?>" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text">
        <button type="button" class="button" id="<?php echo esc_attr($id); ?>-button">Upload/Select Image</button>
        <p class="description"><?php echo esc_html($desc); ?></p>
        <?php
    }

    /**
     * Checks if system-critical properties and users exist and creates them if not.
     * This runs when the settings page is loaded.
     */
    private function ensure_system_users_exist() {
        global $wpdb;
        $cardholders_table = 'ac_cardholders';
        $properties_table = 'ac_property';

        // --- Step 1: Find or Create the "System" Property ---
        $system_property_id = $wpdb->get_var($wpdb->prepare("SELECT property_id FROM {$properties_table} WHERE street_address = %s", 'System'));

        if (!$system_property_id) {
            $wpdb->insert(
                $properties_table,
                [
                    'house_number'   => '0',
                    'street_name'    => 'System',
                    'street_address' => 'System',
                    'origin'         => 'system'
                ]
            );
            $system_property_id = $wpdb->insert_id;
        }

        // --- Step 2: Define System Users ---
        $system_users = [
            [
                'rfid_id'    => '11111111',
                'first_name' => 'System',
                'last_name'  => 'Test User'
            ],
            [
                'rfid_id'    => '22222222',
                'first_name' => 'Kiosk',
                'last_name'  => 'Test User'
            ]
        ];

        // --- Step 3: Loop Through and Create Missing Users ---
        foreach ($system_users as $user_data) {
            $user_exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$cardholders_table} WHERE rfid_id = %s", $user_data['rfid_id']));

            if ($user_exists == 0 && $system_property_id > 0) {
                $wpdb->insert(
                    $cardholders_table,
                    [
                        'rfid_id'           => $user_data['rfid_id'],
                        'first_name'        => $user_data['first_name'],
                        'last_name'         => $user_data['last_name'],
                        'property_id'       => $system_property_id,
                        'card_status'       => 'active',
                        'resident_type'     => 'System',
                        'origin'            => 'system'
                    ]
                );
            }
        }
    }

    public function render_pool_alarm_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Pool Intrusion Alarm Settings</h1>

            <!-- Manual Trigger Buttons Section -->
            <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h2>Manual Controls</h2>
                <p>Use these buttons to manually test or trigger the pool intrusion alarm endpoints without physically swiping a gate.</p>
                <button type="button" id="btn-enable-pool-alarm" class="button button-primary" style="margin-right: 10px;">Test Enable Alarm</button>
                <button type="button" id="btn-disable-pool-alarm" class="button">Test Disable Alarm</button>
                <span id="pool-alarm-action-feedback" style="margin-left: 10px; font-weight: bold;"></span>
            </div>

            <div id="tab-pool-alarm">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pool_alarm_enabled">Integration Enabled</label></th>
                        <td>
                            <input type="checkbox" id="pool_alarm_enabled" name="pool_alarm_enabled" value="1" <?php checked(get_option('fsbhoa_pool_alarm_enabled', '0'), '1'); ?> />
                            <p class="description">Check to enable automatic triggers via the Event Service.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pool_alarm_enable_url">Enable URL</label></th>
                        <td>
                            <?php $enable_url = get_option('fsbhoa_pool_alarm_enable_url', ''); ?>
                            <input type="url" id="pool_alarm_enable_url" name="pool_alarm_enable_url" value="<?php echo esc_attr($enable_url); ?>" class="regular-text" placeholder="http://testbed.fsbhoa.com:8090/resume">
                            <p class="description">The URL that will be triggered to ENABLE the pool alarm.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pool_alarm_disable_url">Disable URL</label></th>
                        <td>
                            <?php $disable_url = get_option('fsbhoa_pool_alarm_disable_url', ''); ?>
                            <input type="url" id="pool_alarm_disable_url" name="pool_alarm_disable_url" value="<?php echo esc_attr($disable_url); ?>" class="regular-text" placeholder="https://testbed.fsbhoa.com:8090/override">
                            <p class="description">The URL that will be triggered to DISABLE the pool alarm.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pool_alarm_trigger_gates">Trigger Gates</label></th>
                        <td>
                            <?php
                            global $wpdb;
                            // Dropped the WP prefix and updated the primary key column
                            $gates = $wpdb->get_results("SELECT door_record_id, friendly_name FROM ac_doors ORDER BY friendly_name ASC");
                            $saved_gates = get_option('fsbhoa_pool_alarm_gates', []);
                            if (!is_array($saved_gates)) $saved_gates = [];
                            ?>
                            <select id="pool_alarm_trigger_gates" name="pool_alarm_trigger_gates[]" multiple="multiple" style="min-width: 300px; height: 150px;">
                                <?php if ($gates): ?>
                                    <?php foreach ($gates as $gate) : ?>
                                        <option value="<?php echo esc_attr($gate->door_record_id); ?>" <?php echo in_array($gate->door_record_id, $saved_gates) ? 'selected' : ''; ?>>
                                            <?php echo esc_html($gate->friendly_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="" disabled>No gates found in database</option>
                                <?php endif; ?>
                            </select>
                            <p class="description">Select the gates that will trigger the disable URL. Hold CTRL (or CMD) to select multiple.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="button" id="fsbhoa-save-pool-alarm-settings-button" class="button button-primary">Save Pool Alarm Settings</button>
                    <span id="fsbhoa-save-feedback" style="display:none; margin-left:10px;"></span>
                </p>
            </div>
        </div>

        <!-- Inline script to handle the newly added Action Buttons instantly -->
        <script>
        jQuery(document).ready(function($) {
            $('#btn-enable-pool-alarm, #btn-disable-pool-alarm').on('click', function() {
                var action = $(this).attr('id') === 'btn-enable-pool-alarm' ? 'enable' : 'disable';
                var feedback = $('#pool-alarm-action-feedback');
                var btn = $(this);

                btn.prop('disabled', true);
                feedback.text('Sending request...').css('color', '#000');

                $.post(ajaxurl, {
                    action: 'fsbhoa_trigger_pool_alarm',
                    nonce: '<?php echo wp_create_nonce("fsbhoa_pool_alarm_nonce"); ?>',
                    alarm_action: action
                }, function(response) {
                    btn.prop('disabled', false);
                    if (response.success) {
                        feedback.text(response.data).css('color', 'green');
                    } else {
                        feedback.text('Error: ' + response.data).css('color', 'red');
                    }
                    setTimeout(function() { feedback.text(''); }, 5000);
                }).fail(function() {
                    btn.prop('disabled', false);
                    feedback.text('AJAX request failed.').css('color', 'red');
                    setTimeout(function() { feedback.text(''); }, 5000);
                });
            });
        });
        </script>
        <?php
    }
    public function ajax_save_pool_alarm() {
        // Verify against the newly matched nonce hook
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fsbhoa_pool_alarm_nonce')) {
            wp_send_json_error('Permission denied.');
        }

        $options = isset($_POST['options']) ? $_POST['options'] : [];
        $trigger_gates = [];
        $disable_url = '';
        $enable_url = '';
        $enabled = '0';

        foreach ($options as $opt) {
            if ($opt['name'] === 'pool_alarm_disable_url') {
                $disable_url = esc_url_raw($opt['value']);
            } elseif ($opt['name'] === 'pool_alarm_enable_url') {
                $enable_url = esc_url_raw($opt['value']);
            } elseif ($opt['name'] === 'pool_alarm_enabled') {
                // FIX 1: Actually check the payload value
                $enabled = ($opt['value'] === 'on') ? '1' : '0';
            } elseif ($opt['name'] === 'pool_alarm_trigger_gates[]') {
                // FIX 2: Handle the array correctly
                if (is_array($opt['value'])) {
                    foreach ($opt['value'] as $gate_id) {
                        $trigger_gates[] = intval($gate_id);
                    }
                } else {
                    // Fallback in case only a single string gets passed somehow
                    $trigger_gates[] = intval($opt['value']);
                }
            }
        }

        update_option('fsbhoa_pool_alarm_enabled', $enabled);
        update_option('fsbhoa_pool_alarm_enable_url', $enable_url);
        update_option('fsbhoa_pool_alarm_disable_url', $disable_url);
        update_option('fsbhoa_pool_alarm_gates', array_unique($trigger_gates));

        // Rebuild the JSON files
        $this->update_all_service_configs();

        wp_send_json_success('Pool Alarm settings saved.');
    }

    public function ajax_trigger_pool_alarm() {
        check_ajax_referer('fsbhoa_pool_alarm_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied.', 403);
        }

        $action = isset($_POST['alarm_action']) ? sanitize_text_field($_POST['alarm_action']) : '';
        $url = '';

        if ($action === 'enable') {
            $url = get_option('fsbhoa_pool_alarm_enable_url', '');
        } elseif ($action === 'disable') {
            $url = get_option('fsbhoa_pool_alarm_disable_url', '');
        }

        if (empty($url)) {
            wp_send_json_error('The ' . esc_html($action) . ' URL is not configured.');
        }

        // Fire off the HTTP request
        $response = wp_remote_get($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to trigger URL: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 300) {
            wp_send_json_success('Successfully triggered ' . esc_html($action) . ' command (Status: ' . $status_code . ')');
        } else {
            wp_send_json_error('Received HTTP ' . $status_code . ' from alarm system.');
        }
    }
}
