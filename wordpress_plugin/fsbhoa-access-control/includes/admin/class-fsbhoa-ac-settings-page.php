<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Ac_Settings_Page {
    private const DEFAULT_PRINT_API_TOKEN = 'eZdaPzde/0JGMirn6DV4VPSErRerexAiqZBCQj/T3Vg=';

    private $parent_slug = 'fsbhoa_ac_main_menu';
    private $event_service_config_path = '/var/lib/fsbhoa/event_service.json';
    private $monitor_service_config_path = '/var/lib/fsbhoa/monitor_service.json';
    private $event_service_option_group = 'fsbhoa_event_service_options';
    private $monitor_settings_option_group = 'fsbhoa_monitor_options';
    private $print_service_config_path = '/var/lib/fsbhoa/zebra_print_service.json';

    public function __construct() {
        // Automatically set the default API token if it doesn't exist
        if (!get_option('fsbhoa_ac_print_api_token')) {
            update_option('fsbhoa_ac_print_api_token', self::DEFAULT_PRINT_API_TOKEN);
        }
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        
        // New, Active Handlers
        add_action( 'wp_ajax_fsbhoa_save_monitor_settings', array( $this, 'ajax_save_monitor_settings' ) );
        add_action( 'wp_ajax_fsbhoa_save_general_settings', array( $this, 'ajax_save_general_settings' ) );
        add_action( 'wp_ajax_fsbhoa_save_event_settings', array( $this, 'ajax_save_event_settings' ) );
	add_action( 'wp_ajax_fsbhoa_save_print_settings', array( $this, 'ajax_save_print_settings' ) );
    }

    public function add_plugin_admin_menu() {
        add_menu_page('FSBHOA General Settings', 'FSBHOA AC', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page' ), 'dashicons-id-alt', 25);
        add_submenu_page($this->parent_slug, 'General Settings', 'General Settings', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page' ));
        add_submenu_page($this->parent_slug, 'Event Service Config', 'Event Service', 'manage_options', 'fsbhoa_event_service_settings', array( $this, 'render_event_service_page' ));
        add_submenu_page($this->parent_slug, 'Print Service Config', 'Print Service', 'manage_options', 'fsbhoa_print_service_settings', array( $this, 'render_print_service_page' ));
        add_submenu_page($this->parent_slug, 'Live Monitor Settings', 'Monitor Settings', 'manage_options', 'fsbhoa_monitor_settings', array( $this, 'render_monitor_settings_page' ));
        add_submenu_page($this->parent_slug, 'Kiosk Settings', 'Kiosk', 'manage_options', 'fsbhoa_kiosk_settings', array( $this, 'render_kiosk_settings_page' ));
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
        $wp_host          = get_option('fsbhoa_ac_wp_host', 'nas.fsbhoa.com');
        $wp_port          = get_option('fsbhoa_ac_wp_port', 443);
        $event_log_path   = get_option('fsbhoa_ac_event_log_path', '');
        $debug_mode       = get_option('fsbhoa_ac_debug_mode', 'on');
        $test_stub        = get_option('fsbhoa_ac_test_stub', 'on');

        // --- Build and write monitor_service.json ---
        $monitor_config = [
            'listen_addr'       => ':' . absint($monitor_port),
            'wordpress_api'     => get_site_url() . '/wp-json/fsbhoa/v1/monitor/event',
            'tls_cert_path'     => sanitize_text_field($tls_cert_path),
            'tls_key_path'      => sanitize_text_field($tls_key_path),
            'event_service_url' => sprintf('https://127.0.0.1:%d', absint($websocket_port)),
        ];
        $this->write_config_file($this->monitor_service_config_path, $monitor_config);

        // --- Build and write event_service.json ---
        $event_config = [
            'bindAddress'       => sanitize_text_field($bind_addr),
            'broadcastAddress'  => sanitize_text_field($broadcast_addr),
            'listenPort'        => absint($listen_port),
            'callbackHost'      => sanitize_text_field($callback_host),
            'webSocketPort'     => absint($websocket_port),
            'wpURL'             => sprintf('https://%s:%d',  $wp_host, absint($wp_port)),
            'tlsCert'           => sanitize_text_field($tls_cert_path),
            'tlsKey'            => sanitize_text_field($tls_key_path),
            'logFile'           => sanitize_text_field($event_log_path),
            'debug'             => ($debug_mode === 'on'),
            'enableTestStub'    => ($test_stub === 'on'),
            'monitorServiceURL' => sprintf('https://127.0.0.1:%d', absint($monitor_port)),
        ];
        $this->write_config_file($this->event_service_config_path, $event_config);

        $print_config = [
            'port'      => (int) get_option('fsbhoa_ac_print_port', 8081),
            'api_url'   => get_site_url() . '/wp-json/fsbhoa/v1/print_log_update',
            'api_token' => get_option('fsbhoa_ac_print_api_token', ''),
        ];
        $this->write_config_file($this->print_service_config_path, $print_config);

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

        // NEW Section: Service Communication (Moved from Event Service)
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
        
        // Register all General settings
        register_setting($general_option_group, 'fsbhoa_ac_photo_width', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_photo_height', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_address_suffix', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_wp_host', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_wp_port', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_tls_cert_path', 'sanitize_text_field');
        register_setting($general_option_group, 'fsbhoa_ac_tls_key_path', 'sanitize_text_field');

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
        add_settings_field('fsbhoa_ac_card_back_url_field', 'Card Back Logo', array($this, 'render_media_uploader_field'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_card_back_url', 'desc' => 'Select an image from the Media Library for the back of the card.']);
        add_settings_field('fsbhoa_ac_print_template_path_field', 'Print Template JSON Path', array($this, 'render_field_callback'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_template_path', 'type' => 'text', 'desc' => 'Full server path to the print template JSON file.']);

        register_setting($print_service_option_group, 'fsbhoa_ac_print_port', 'absint');
        register_setting($print_service_option_group, 'fsbhoa_ac_card_back_url', 'esc_url_raw');
        register_setting($print_service_option_group, 'fsbhoa_ac_print_template_path', 'sanitize_text_field');
	register_setting($print_service_option_group, 'fsbhoa_ac_print_api_token', 'sanitize_text_field');


        // --- MONITOR SETTINGS ---

        register_setting($this->monitor_settings_option_group, 'fsbhoa_monitor_map_url', 'esc_url_raw');
        register_setting($this->monitor_settings_option_group, 'fsbhoa_ac_monitor_port', 'absint');



        // --- KIOSK SETTINGS ---
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
            array($this, 'render_field_callback'),
            $kiosk_page_slug,
            'fsbhoa_ac_kiosk_logo_section',
            [
                'id' => 'fsbhoa_kiosk_logo_url',
                'type' => 'url',
                'desc' => 'URL for the logo displayed on the kiosk idle screen.'
            ]
        );

        register_setting(
            $kiosk_option_group,
            'fsbhoa_kiosk_logo_url',
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
            <h1>Live Monitor Settings</h1>
            <p>Use these tools to configure the Live Monitor service and its map display.</p>
            <hr>
    
            <h2>Gate Position Editor</h2>
            <p class="description">Upload a map image, then drag the gate markers to their correct positions. All settings will be saved with the button at the bottom.</p>
            <div id="fsbhoa-editor-area" style="display: flex; gap: 20px; margin-top: 1em;">
                <div id="fsbhoa-map-editor-container" style="position: relative; border: 2px solid #ccc; flex-basis: 70%; min-height: 400px;">
                    <img id="fsbhoa-map-editor-bg" src="<?php echo esc_url(get_option('fsbhoa_monitor_map_url', '')); ?>" style="max-width: 100%; display: block; opacity: 0.7;">
                </div>
                <div id="fsbhoa-gate-legend" style="flex-basis: 30%;">
                    <h3>Gate Legend</h3>
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
        <div class="wrap">
            <h1>Kiosk Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('fsbhoa_kiosk_options');
                do_settings_sections('fsbhoa_kiosk_settings');
                submit_button('Save Kiosk Settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_admin_assets($hook) {
        // For General, Event, & Print Settings Pages
        $settings_pages = [
            'toplevel_page_fsbhoa_ac_main_menu',
            'fsbhoa-ac_page_fsbhoa_event_service_settings',
            'fsbhoa-ac_page_fsbhoa_print_service_settings'
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

        // For System Status Page
        if ($hook === 'fsbhoa-ac_page_fsbhoa_system_status') {
            // Load the shared styles for the status indicator colors
            wp_enqueue_style('fsbhoa-shared-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-shared-styles.css', array(), FSBHOA_AC_VERSION);

            $script_handle = 'fsbhoa-system-status-script';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-system-status.js', array('jquery'), FSBHOA_AC_VERSION, true);

            wp_localize_script(
                $script_handle,
                'fsbhoa_system_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('fsbhoa_system_status_nonce'),
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
}
