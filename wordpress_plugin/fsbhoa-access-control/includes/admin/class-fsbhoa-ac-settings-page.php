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
            'fsbhoa_ac_wp_host' => ['label' => 'WordPress API Host', 'default' => 'nas.local'],
            'fsbhoa_ac_wp_port' => ['label' => 'WordPress API Port', 'type' => 'number', 'default' => 443],
            'fsbhoa_ac_tls_cert_path' => ['label' => 'TLS Certificate Path', 'default' => '/etc/ssl/nas.local/nas.local.crt'],
            'fsbhoa_ac_tls_key_path' => ['label' => 'TLS Key Path', 'default' => '/etc/ssl/nas.local/nas.local.key'],
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
    }

    public function save_event_service_config() {
        $config_data = [
            'bindAddress'      => get_option('fsbhoa_ac_bind_addr', '0.0.0.0:0'),
            'broadcastAddress' => get_option('fsbhoa_ac_broadcast_addr', '192.168.42.255:60000'),
            'listenPort'       => (int) get_option('fsbhoa_ac_listen_port', 60002),
            'callbackHost'     => get_option('fsbhoa_ac_callback_host', '192.168.42.99'),
            'webSocketPort'    => (int) get_option('fsbhoa_ac_websocket_port', 8083),
            'wpURL'            => sprintf('%s://%s:%d', get_option('fsbhoa_ac_wp_protocol', 'https'), get_option('fsbhoa_ac_wp_host', 'nas.local'), (int) get_option('fsbhoa_ac_wp_port', 443)),
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
}

