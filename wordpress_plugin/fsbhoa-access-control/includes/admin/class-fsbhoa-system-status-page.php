<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_System_Status_Page {

    private $parent_slug = 'fsbhoa_ac_main_menu';
    private $page_slug = 'fsbhoa_system_status';
    private $services = [
        'fsbhoa-events.service' => 'Event Service',
        'fsbhoa-monitor.service' => 'Monitor Service',
        'fsbhoa-zebra-printer.service' => 'Print Service',
        'fsbhoa-kiosk.service' => 'Kiosk Service',
    ];

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
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
            <p>This page shows the real-time status of the backend Go services and allows you to manage them.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 25%;">Service Name</th>
                        <th style="width: 15%;">Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $this->services as $service_id => $service_name ) : ?>
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
        </div>
        <?php
    }
}

