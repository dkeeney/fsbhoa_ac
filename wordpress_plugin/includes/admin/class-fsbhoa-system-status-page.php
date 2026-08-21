<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_System_Status_Page {

    private $parent_slug = 'fsbhoa_ac_main_menu';
    private $page_slug = 'fsbhoa_system_status';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public static function get_services() {
        // Core services only
        $core_services = [
            'fsbhoa_monitor'      => 'Monitor Service',
            'fsbhoa_printer'      => 'Print Service',
            'fsbhoa_kiosk'        => 'Kiosk Service',
        ];

        // Ask plugins for their services
        return apply_filters('fsbhoa_system_services', $core_services);
    }

    /**
     * Enqueue JS and Define the Nonce
     */
    public function enqueue_admin_assets($hook) {
        // CHECK 1: Ensure we are on a "page" inside the admin
        if ( ! isset($_GET['page']) ) {
            return;
        }

        // CHECK 2: strictly check if the page slug matches "System Status"
        if ( $_GET['page'] !== 'fsbhoa_system_status' ) {
            return;
        }

        // 1. Load the Styles (Greed/Red dots)
        wp_enqueue_style(
            'fsbhoa-shared-styles', 
            plugin_dir_url(__FILE__) . '../../assets/css/fsbhoa-shared-styles.css', 
            array(), 
            '1.0.0'
        );

        // 2. Load the Script
        wp_enqueue_script(
            'fsbhoa-system-status-js',
            plugin_dir_url(__FILE__) . '../../assets/js/fsbhoa-system-status.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // 3. Pass Variables to JS (The fix for your error)
        wp_localize_script('fsbhoa-system-status-js', 'fsbhoa_admin', array(
            'ajax_url'      => admin_url('admin-ajax.php'),
            'power_nonce'   => wp_create_nonce('fsbhoa_power_action'),      // For Reboot/Shutdown
            'service_nonce' => wp_create_nonce('fsbhoa_system_status_nonce') // For Start/Stop Services
        ));
    }

    public function add_admin_menu() {
        add_submenu_page(
            $this->parent_slug,
            'System Status',
            'System Status',
            'manage_options',
            $this->page_slug,
            array( $this, 'render_page' )
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>System Services Status</h1>
            <p>This page shows the real-time status of the backend Go services that run on the server. The official `systemd` service name is listed in small text below each friendly name.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 25%;">Service Name</th>
                        <th style="width: 15%;">Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( self::get_services() as $service_id => $service_name ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $service_name ); ?></strong><br><small><?php echo esc_html( $service_id ); ?></small></td>
                            <td>
                                <span id="status-<?php echo esc_attr( $service_id ); ?>" class="fsbhoa-status-indicator">
                                    Checking...
                                </span>
                            </td>
                            <td>
                                <button class="button service-command-btn" data-service="<?php echo esc_attr( $service_id ); ?>" data-command="start">Start</button>
                                <button class="button service-command-btn" data-service="<?php echo esc_attr( $service_id ); ?>" data-command="stop">Stop</button>
                                <button class="button button-primary service-command-btn" data-service="<?php echo esc_attr( $service_id ); ?>" data-command="restart">Restart</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="card" style="margin-top: 20px; border-left: 4px solid #dc3232; padding: 20px;">
                 <h2 style="margin-top: 0;">⚠️ Server Power Controls</h2>
                 <p>These actions affect the physical server. Reboot will restart the computer immediately and be available after about 2 min.. Shutdown will happen after 60 seconds and then must be manually restarted.</p>
            
                 <div style="display: flex; gap: 15px; align-items: center;">
                     <button id="btn-server-reboot" class="button button-large" style="background: #f0ad4e; color: white; border-color: #eea236;">
                         <span class="dashicons dashicons-update" style="margin-top: 3px;"></span> Restart Server
                     </button>

                     <button id="btn-server-shutdown" class="button button-large" style="background: #d9534f; color: white; border-color: #d43f3a;">
                         <span class="dashicons dashicons-off" style="margin-top: 3px;"></span> Power Off
                     </button>
                 </div>
            
                 <div id="power-action-status" style="margin-top: 15px; font-weight: bold;">
                 </div>
            </div>
        </div>
        <?php
    }
}

