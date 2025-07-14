<?php
if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_System_Status_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
    }

    /**
     * Adds the submenu page to the main FSBHOA menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'fsbhoa_ac_main_menu',           // The parent slug
            'System Status',                 // Page title
            'System Status',                 // Menu title
            'manage_options',                // Capability
            'fsbhoa_system_status',          // Menu slug
            array( $this, 'render_page' )    // Callback function to render the page
        );
    }

    /**
     * Renders the HTML for the status page.
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1>System Services Status</h1>
            <p>This page shows the status of the backend services and allows you to start, stop, and restart them.</p>

            <table class="wp-list-table widefat striped" style="margin-top: 20px; max-width: 800px;">
                <thead>
                    <tr>
                        <th style="width: 30%;">Service</th>
                        <th style="width: 20%;">Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Event Service</strong><br><em style="font-size:12px;">(fsbhoa-event-service.service)</em></td>
                        <td><span id="status-fsbhoa-event-service.service" class="fsbhoa-status-indicator">Checking...</span></td>
                        <td>
                            <button class="button service-command-btn" data-command="start" data-service="fsbhoa-event-service.service">Start</button>
                            <button class="button service-command-btn" data-command="stop" data-service="fsbhoa-event-service.service">Stop</button>
                            <button class="button button-primary service-command-btn" data-command="restart" data-service="fsbhoa-event-service.service">Restart</button>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Zebra Print Service</strong><br><em style="font-size:12px;">(zebra_print_service.service)</em></td>
                        <td><span id="status-zebra_print_service.service" class="fsbhoa-status-indicator">Checking...</span></td>
                        <td>
                            <button class="button service-command-btn" data-command="start" data-service="zebra_print_service.service">Start</button>
                            <button class="button service-command-btn" data-command="stop" data-service="zebra_print_service.service">Stop</button>
                            <button class="button button-primary service-command-btn" data-command="restart" data-service="zebra_print_service.service">Restart</button>
                        </td>
                    </tr>
                    <tr>
                          <td><strong>Monitor Service</strong><br><em style="font-size:12px;">(monitor_service.service)</em></td>
                          <td><span id="status-monitor_service.service" class="fsbhoa-status-indicator">Checking...</span></td>
                          <td>
                              <button class="button service-command-btn" data-command="start" data-service="monitor_service.service">Start</button>
                              <button class="button service-command-btn" data-command="stop" data-service="monitor_service.service">Stop</button>
                              <button class="button button-primary service-command-btn" data-command="restart" data-service="monitor_service.service">Restart</button>
                          </td>
                      </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
