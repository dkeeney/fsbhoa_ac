<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Ac_Settings_Page {

    private $parent_slug = 'fsbhoa_ac_main_menu';
    private $config_path = '/var/lib/fsbhoa/event_service.conf';
    private $event_service_option_group = 'fsbhoa_event_service_options';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'settings_api_init' ) );
        add_action( 'admin_init', array( $this, 'intercept_event_service_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_monitor_assets' ) );
        add_action( 'wp_ajax_fsbhoa_save_gate_positions', array( $this, 'save_gate_positions_callback' ) );
    }

    public function intercept_event_service_save() {
        if ( isset( $_POST['option_page'] ) && $_POST['option_page'] === $this->event_service_option_group ) {
            $this->save_event_service_config();
        }
    }

    public function add_plugin_admin_menu() {
        add_menu_page('FSBHOA General Settings', 'FSBHOA AC', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page' ), 'dashicons-id-alt', 25);
        add_submenu_page($this->parent_slug, 'General Settings', 'General Settings', 'manage_options', $this->parent_slug, array( $this, 'render_general_settings_page' ));
        add_submenu_page($this->parent_slug, 'Event Service Config', 'Event Service', 'manage_options', 'fsbhoa_event_service_settings', array( $this, 'render_event_service_page' ));
        add_submenu_page($this->parent_slug, 'Print Service Config', 'Print Service', 'manage_options', 'fsbhoa_print_service_settings', array( $this, 'render_print_service_page' ));
        add_submenu_page($this->parent_slug, 'Live Monitor Settings', 'Monitor Settings', 'manage_options', 'fsbhoa_monitor_settings', array( $this, 'render_monitor_settings_page' ));
    }

    public function settings_api_init() {
        // --- GENERAL SETTINGS ---
        $general_option_group = 'fsbhoa_general_options';
        $general_page_slug = $this->parent_slug;
        register_setting($general_option_group, 'fsbhoa_ac_photo_width', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_photo_height', 'absint');
        register_setting($general_option_group, 'fsbhoa_ac_address_suffix', 'sanitize_text_field');
        add_settings_section('fsbhoa_ac_photo_editor_section', 'Photo Editor Settings', null, $general_page_slug);
        add_settings_field('fsbhoa_ac_photo_width_field', 'Photo Width (px)', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_photo_editor_section', ['id' => 'fsbhoa_ac_photo_width', 'type' => 'number', 'default' => 640]);
        add_settings_field('fsbhoa_ac_photo_height_field', 'Photo Height (px)', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_photo_editor_section', ['id' => 'fsbhoa_ac_photo_height', 'type' => 'number', 'default' => 800]);
        add_settings_section('fsbhoa_ac_display_options_section', 'Display Options', null, $general_page_slug);
        add_settings_field('fsbhoa_ac_address_suffix_field', 'Address Suffix to Remove', array($this, 'render_field_callback'), $general_page_slug, 'fsbhoa_ac_display_options_section', ['id' => 'fsbhoa_ac_address_suffix', 'type' => 'text', 'default' => 'Bakersfield, CA 93306', 'desc' => 'This text will be removed from property addresses in display lists.']);

        // --- EVENT SERVICE SETTINGS ---
        $event_service_page_slug = 'fsbhoa_event_service_settings';
        add_settings_section('fsbhoa_event_service_section', null, null, $event_service_page_slug);
        $event_fields = [
            'fsbhoa_ac_bind_addr' => ['label' => 'Bind Address', 'default' => '0.0.0.0:0'],
            'fsbhoa_ac_broadcast_addr' => ['label' => 'Broadcast Address', 'default' => '192.168.42.255:60000'],
            'fsbhoa_ac_listen_port' => ['label' => 'Event Listener Port', 'type' => 'number', 'default' => 60002],
            'fsbhoa_ac_callback_host' => ['label' => 'Event Callback Host IP', 'default' => '192.168.42.99'],
            'fsbhoa_ac_websocket_port' => ['label' => 'WebSocket Service Port', 'type' => 'number', 'default' => 8083],
            'fsbhoa_ac_wp_protocol' => ['label' => 'WordPress API Protocol', 'default' => 'https'],
            'fsbhoa_ac_wp_host' => ['label' => 'WordPress API Host', 'default' => 'nas.fsbhoa.com'],
            'fsbhoa_ac_wp_port' => ['label' => 'WordPress API Port', 'type' => 'number', 'default' => 443],
            'fsbhoa_ac_tls_cert_path' => ['label' => 'TLS Certificate Path', 'default' => '/etc/letsencrypt/live/nas.fsbhoa.com/fullchain.pem'],
            'fsbhoa_ac_tls_key_path' => ['label' => 'TLS Key Path', 'default' => '/etc/letsencrypt/live/nas.fsbhoa.com/privkey.pem'],
            'fsbhoa_ac_event_log_path' => ['label' => 'Event Service Log Path', 'default' => '', 'desc' => 'Leave empty for console output.'],
            'fsbhoa_ac_debug_mode' => ['label' => 'Debug Mode', 'type' => 'checkbox', 'default' => 'on'],
            'fsbhoa_ac_test_stub' => ['label' => 'Enable Test Stub', 'type' => 'checkbox', 'default' => 'on'],
        ];
        foreach ($event_fields as $id => $field) {
            register_setting($this->event_service_option_group, $id, ['sanitize_callback' => 'sanitize_text_field']);
            add_settings_field($id . '_field', $field['label'], array($this, 'render_field_callback'), $event_service_page_slug, 'fsbhoa_event_service_section', ['id' => $id] + $field);
        }

        // --- PRINT SERVICE SETTINGS ---
        $print_service_option_group = 'fsbhoa_print_service_options';
        $print_service_page_slug = 'fsbhoa_print_service_settings';
        add_settings_section('fsbhoa_print_service_section', null, null, $print_service_page_slug);
        add_settings_field('fsbhoa_ac_print_port_field', 'Zebra Print Service Port', array($this, 'render_field_callback'), $print_service_page_slug, 'fsbhoa_print_service_section', ['id' => 'fsbhoa_ac_print_port', 'type' => 'number', 'default' => 8081]);
        register_setting($print_service_option_group, 'fsbhoa_ac_print_port', 'absint');

        // --- MONITOR SETTINGS ---
        $monitor_settings_option_group = 'fsbhoa_monitor_options';
        register_setting($monitor_settings_option_group, 'fsbhoa_monitor_map_url', 'esc_url_raw');
    }

    public function save_event_service_config() {
        $config_data = [
            'bindAddress'      => get_option('fsbhoa_ac_bind_addr', '0.0.0.0:0'),
            'broadcastAddress' => get_option('fsbhoa_ac_broadcast_addr', '192.168.42.255:60000'),
            'listenPort'       => (int) get_option('fsbhoa_ac_listen_port', 60002),
            'callbackHost'     => get_option('fsbhoa_ac_callback_host', '192.168.42.99'),
            'webSocketPort'    => (int) get_option('fsbhoa_ac_websocket_port', 8083),
            'wpURL'            => sprintf('%s://%s:%d', get_option('fsbhoa_ac_wp_protocol', 'https'), get_option('fsbhoa_ac_wp_host', 'nas.fsbhoa.com'), (int) get_option('fsbhoa_ac_wp_port', 443)),
            'tlsCert'          => get_option('fsbhoa_ac_tls_cert_path', ''),
            'tlsKey'           => get_option('fsbhoa_ac_tls_key_path', ''),
            'logFile'          => get_option('fsbhoa_ac_event_log_path', ''),
            'debug'            => (get_option('fsbhoa_ac_debug_mode', 'on') === 'on'),
            'enableTestStub'   => (get_option('fsbhoa_ac_test_stub', 'on') === 'on'),
        ];
        $json_data = json_encode($config_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_dir(dirname($this->config_path))) {
            mkdir(dirname($this->config_path), 0755, true);
        }
        file_put_contents($this->config_path, $json_data);
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
        <div class="wrap">
            <h1>General Plugin Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('fsbhoa_general_options');
                do_settings_sections($this->parent_slug);
                submit_button('Save General Settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function render_event_service_page() {
        ?>
        <div class="wrap">
            <h1>Event Service Configuration</h1>
            <p>These settings control the `event_service` Go application. The configuration file will be automatically generated at <code><?php echo esc_html($this->config_path); ?></code> when you save changes.</p>
            <form action="options.php" method="post">
                <?php
                settings_fields($this->event_service_option_group);
                do_settings_sections('fsbhoa_event_service_settings');
                submit_button('Save Settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function render_print_service_page() {
        ?>
        <div class="wrap">
            <h1>Print Service Configuration</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('fsbhoa_print_service_options');
                do_settings_sections('fsbhoa_print_service_settings');
                submit_button('Save Print Settings');
                ?>
            </form>
        </div>
        <?php
    }

    public function render_monitor_settings_page() {
        ?>
        <div class="wrap">
            <h1>Live Monitor Settings</h1>
            <p>Use this tool to upload your map and position your gates for the live monitor page.</p>
            <hr>

            <form action="options.php" method="post">
                <?php
                // This handles the saving of our 'fsbhoa_monitor_map_url' option
                settings_fields('fsbhoa_monitor_options');
                ?>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row">Monitor Map</th>
                            <td>
                                <input type="hidden" id="fsbhoa_monitor_map_url" name="fsbhoa_monitor_map_url" value="<?php echo esc_attr(get_option('fsbhoa_monitor_map_url', '')); ?>" />
                                <button type="button" class="button" id="fsbhoa_monitor_map_url-button">Upload or Change Map Image</button>
                                <?php submit_button('Save Map'); ?>
                                <p class="description">Upload your map, then click "Save Map" to update the URL.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </form>

            <hr>

            <h2>Gate Position Editor</h2>
            <p class="description">Drag the gate markers to their correct positions on the map below, then click "Save Gate Positions".</p>
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
                <button type="button" class="button button-primary" id="fsbhoa-save-gate-positions">Save Gate Positions</button>
                <span id="fsbhoa-save-positions-feedback" style="margin-left: 10px;"></span>
            </p>
        </div>
        <?php
    }

    public function enqueue_monitor_assets($hook) {
        if ($hook !== 'fsbhoa-ac_page_fsbhoa_monitor_settings') {
            return;
        }
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

    public function save_gate_positions_callback() {
        check_ajax_referer('fsbhoa_monitor_settings_nonce', 'nonce');
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Permission denied.', 403);
        }
        if ( ! isset($_POST['gates']) || ! is_array($_POST['gates']) ) {
            wp_send_json_error('Invalid data.', 400);
        }
        
        global $wpdb;
        $doors_table = 'ac_doors';
        $success = true;

        foreach ( $_POST['gates'] as $gate ) {
            $door_id = absint($gate['id']);
            $map_x   = intval($gate['x']);
            $map_y   = intval($gate['y']);

            if ($door_id > 0) {
                $result = $wpdb->update(
                    $doors_table,
                    array('map_x' => $map_x, 'map_y' => $map_y),
                    array('door_record_id' => $door_id),
                    array('%d', '%d'),
                    array('%d')
                );
                if ($result === false) {
                    $success = false;
                }
            }
        }

        if ($success) {
            wp_send_json_success('Gate positions saved successfully.');
        } else {
            wp_send_json_error('An error occurred while saving some gate positions.');
        }
    }
}
