<?php
// includes/admin/class-fsbhoa-test-suite-page.php

if ( ! defined( 'WPINC' ) ) { die; }

class Fsbhoa_Test_Suite_Page {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'fsbhoa_ac_main_menu',
            'System Diagnostics',
            'Diagnostics',
            'manage_options',
            'fsbhoa_diagnostics',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'fsbhoa-ac_page_fsbhoa_diagnostics') {
            return;
        }
        wp_enqueue_script(
            'fsbhoa-test-suite-js',
            FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-test-suite.js',
            array('jquery'),
            FSBHOA_AC_PLUGIN_VERSION,
            true
        );
        wp_localize_script('fsbhoa-test-suite-js', 'fsbhoa_test_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('fsbhoa_test_suite_nonce')
        ]);
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>System Communications Test Suite</h1>
            <p>Click the button below to run a series of tests to ensure all services are communicating correctly.</p>
            <p>
                <button id="run-test-suite" class="button button-primary">Run Full Test Suite</button>
            </p>
            <hr>
            <h2>Test Results:</h2>
            <div id="test-results" style="font-family: monospace; background: #f1f1f1; padding: 15px; border-radius: 4px; max-height: 500px; overflow-y: auto;">
                <p>Waiting to start...</p>
            </div>
        </div>
        <?php
    }
}
