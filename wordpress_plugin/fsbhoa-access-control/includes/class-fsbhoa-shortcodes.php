<?php
/**
 * Handles the front-end shortcodes for the plugin.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Shortcodes {

    public function __construct() {
        add_shortcode( 'fsbhoa_cardholder_management', array( $this, 'render_cardholder_management_shortcode' ) );
        add_shortcode( 'fsbhoa_print_card', array( $this, 'render_print_card_shortcode' ) );
        add_shortcode( 'fsbhoa_hardware_management', array( $this, 'render_hardware_management_shortcode' ) );
        add_shortcode( 'fsbhoa_live_monitor', array( $this, 'render_live_monitor_shortcode' ) );
        add_shortcode( 'fsbhoa_reports', array( $this, 'render_reports_shortcode' ) );
        add_shortcode( 'fsbhoa_usage_analytics', array( $this, 'render_analytics_shortcode' ) );
        add_shortcode( 'fsbhoa_amenity_management', array( $this, 'render_amenity_management_shortcode' ) );
        add_shortcode( 'fsbhoa_groups_page', [$this, 'render_groups_page']);
        add_shortcode( 'fsbhoa_cardholder_report', array( $this, 'render_cardholder_report_shortcode' ) );
        add_shortcode( 'fsbhoa_task_list', array( $this, 'render_task_list_shortcode' ) );
        add_shortcode( 'fsbhoa_archived_cardholders', array( $this, 'render_archived_cardholders_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_shortcode_assets' ) );
        add_action( 'wp_body_open', array( $this, 'display_sync_banner' ) );
    }

    public function render_cardholder_management_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        $current_view = 'cardholders';

        if ( isset( $_GET['view'] ) ) {
            $current_view = sanitize_key( $_GET['view'] );
        } else {
            $atts = shortcode_atts(
                [ 'view' => 'cardholders' ],
                $atts,
                'fsbhoa_cardholder_management'
            );
            $current_view = sanitize_key( $atts['view'] );
        }

        ob_start();

        if ( $current_view === 'properties' ) {
            if ( class_exists('Fsbhoa_Property_Admin_Page') ) {
                $property_admin_page = new Fsbhoa_Property_Admin_Page();
                $property_admin_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Property management class not found.', 'fsbhoa-ac' ) . '</p>';
            }
        } else {
            if ( class_exists('Fsbhoa_Cardholder_Admin_Page') ) {
                $cardholder_admin_page = new Fsbhoa_Cardholder_Admin_Page();
                $cardholder_admin_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Cardholder management class not found.', 'fsbhoa-ac' ) . '</p>';
            }
        }

        return ob_get_clean();
    }

    public function enqueue_shortcode_assets() {
        global $post;

        if ( ! is_a( $post, 'WP_Post' )
            || (! has_shortcode( $post->post_content, 'fsbhoa_cardholder_management' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_import_form' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_print_card' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_hardware_management' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_live_monitor' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_reports' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_usage_analytics' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_amenity_management' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_groups_page' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_cardholder_report' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_task_list' )
            && ! has_shortcode( $post->post_content, 'fsbhoa_archived_cardholders' )
            ) ) {
            return;
        }


        // These are needed by most pages, so we'll load them globally for simplicity.
        wp_enqueue_script('jquery');
        wp_enqueue_style('dashicons');
        wp_enqueue_style('fsbhoa-shared-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-shared-styles.css', array(), FSBHOA_AC_PLUGIN_VERSION);
        ////////wp_enqueue_style('jquery-ui-style', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/jquery-ui/jquery-ui.css', array(), '1.12.1');

        // ASSETS FOR: sync.  They are used by every shortcode
        wp_enqueue_script('fsbhoa-sync-script', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-sync-admin.js', ['jquery'], FSBHOA_AC_PLUGIN_VERSION, true);
        wp_localize_script('fsbhoa-sync-script', 'fsbhoa_sync_vars', [ 'ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('fsbhoa_sync_nonce')]);



        // ASSETS FOR: [fsbhoa_cardholder_management]
        if ( has_shortcode( $post->post_content, 'fsbhoa_cardholder_management' ) ) {
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_script('jquery-ui-dialog');

            wp_enqueue_style('datatables-style', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.dataTables.css', array(), '2.0.8');
            wp_enqueue_script('datatables-script', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.js', array('jquery'), '2.0.8', true);
            wp_enqueue_style('datatables-select-css', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/select.dataTables.css', ['datatables-style']);
            wp_enqueue_script('datatables-select', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.select.js', ['jquery', 'datatables-script'], '2.0.2', true);

            wp_enqueue_style('croppie-style', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/croppie/croppie.min.css', array(), '2.6.5');
            wp_enqueue_script('croppie-script', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/croppie/croppie.min.js', array('jquery'), '2.6.5', true);

            wp_enqueue_script('canvg-script', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/canvg.min.js', array(), '1.1', true);
            wp_enqueue_style('fsbhoa-cardholder-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-cardholder-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script('fsbhoa-photo-croppie', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-photo-croppie.js', array('jquery', 'jquery-ui-dialog', 'croppie-script'), FSBHOA_AC_PLUGIN_VERSION, true);

            $app_script_handle = 'fsbhoa-cardholder-admin-script';
            wp_enqueue_script($app_script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-cardholder-admin.js', array('jquery', 'jquery-ui-autocomplete', 'datatables-script', 'fsbhoa-photo-croppie', 'canvg-script'), FSBHOA_AC_PLUGIN_VERSION, true);

            $ajax_settings = array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'property_search_nonce' => wp_create_nonce('fsbhoa_property_search_nonce'),
                'cardholder_search_nonce' => wp_create_nonce('fsbhoa_cardholder_search_nonce'),
                'export_nonce' => wp_create_nonce('fsbhoa_export_nonce'),
                'print_report_nonce' => wp_create_nonce('fsbhoa_print_report_nonce')
            );
            wp_localize_script($app_script_handle, 'fsbhoa_ajax_settings', $ajax_settings);

            wp_enqueue_style('fsbhoa-property-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-property-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script('fsbhoa-property-admin', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-property-admin.js', array('jquery', 'datatables-script'), FSBHOA_AC_PLUGIN_VERSION, true);
        }

        // ASSETS FOR: [fsbhoa_archived_cardholders] (includes list, preview, and merge)
        if ( has_shortcode( $post->post_content, 'fsbhoa_archived_cardholders' ) ) {
            // Enqueue assets needed for this page
            wp_enqueue_style('datatables-style', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.dataTables.css', array(), '2.0.8');
            wp_enqueue_script('datatables-script', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.js', array('jquery'), '2.0.8', true);
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_style('fsbhoa-archived-cardholder-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-archived-cardholder-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);

            $handle = 'fsbhoa-archived-cardholder-script';
            wp_enqueue_script($handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-archived-cardholder.js', ['jquery', 'jquery-ui-autocomplete', 'datatables-script'], FSBHOA_AC_PLUGIN_VERSION, true);

            // Localize settings to OUR new handle
            $ajax_settings = array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'cardholder_search_nonce' => wp_create_nonce('fsbhoa_cardholder_search_nonce')
            );
            wp_localize_script($handle, 'fsbhoa_ajax_settings', $ajax_settings);
        }

        // ASSETS FOR: [fsbhoa_print_card]
        if ( has_shortcode( $post->post_content, 'fsbhoa_print_card' ) ) {
            wp_enqueue_style('fsbhoa-print-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-print-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script('fsbhoa-print-workflow', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-print-workflow.js', array('jquery'), FSBHOA_AC_PLUGIN_VERSION, true);
            wp_localize_script('fsbhoa-print-workflow', 'fsbhoa_print_vars', array(
                'ajax_url' => admin_url('admin-ajax.php'), 
                'nonce'    => wp_create_nonce('fsbhoa_print_card_nonce'), 
                'cardholder_page_url' => get_permalink(get_page_by_path('cardholder'))
            ));
        }

        // ASSETS FOR: [fsbhoa_hardware_management] (Controllers, Gates, Tasks)
        if ( has_shortcode( $post->post_content, 'fsbhoa_hardware_management' ) ) {
            // Needs DataTables for its lists
            wp_enqueue_style('datatables-style', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.dataTables.css', array(), '2.0.8');
            wp_enqueue_script('datatables-script', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.js', array('jquery'), '2.0.8', true);

            // Specific styles and scripts for this page
            wp_enqueue_style('fsbhoa-controller-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-controller-styles.css', ['fsbhoa-shared-styles'], FSBHOA_AC_PLUGIN_VERSION);

            $handle = 'fsbhoa-hardware-admin'; // The handle is defined here
            wp_enqueue_script($handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-hardware-admin.js', ['jquery', 'datatables-script'], FSBHOA_AC_PLUGIN_VERSION, true);
            wp_localize_script(
                $handle,
                'fsbhoa_hardware_vars',
                array(
                    'ajax_url'      => admin_url('admin-ajax.php'),
                    'discovery_nonce' => wp_create_nonce('fsbhoa_discovery_nonce'), // For other functions in that file
                    'reset_nonce'   => wp_create_nonce('fsbhoa_factory_reset_nonce')   // For our new button
                )
            );

        }

        // ASSETS FOR: [fsbhoa_task_list]
        if ( has_shortcode( $post->post_content, 'fsbhoa_task_list' ) ) {
            // This page uses DataTables for the list
            wp_enqueue_style('datatables-style', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.dataTables.css', array(), '2.0.8');
            wp_enqueue_script('datatables-script', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.js', array('jquery'), '2.0.8', true);

            // Enqueue the new dedicated script for the task list
            wp_enqueue_script(
                'fsbhoa-task-list-script', // A new, unique handle
                FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-task-list.js', // The new filename
                ['jquery', 'datatables-script'], // Dependencies
                FSBHOA_AC_PLUGIN_VERSION,
                true // In footer
            );
            wp_enqueue_style(
                'fsbhoa-task-list-styles', 
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-task-list-styles.css', 
                ['fsbhoa-shared-styles'], 
                FSBHOA_AC_PLUGIN_VERSION,
            );
        }

        // ASSETS FOR: [fsbhoa_reports]
        if ( has_shortcode( $post->post_content, 'fsbhoa_reports' ) ) {
            // Needs DataTables for the report list
            wp_enqueue_style('datatables-style', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.dataTables.css', array(), '2.0.8');
            wp_enqueue_script('datatables-script', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/dataTables.js', array('jquery'), '2.0.8', true);
            
            // Needs the Datepicker widget from jQuery UI
            wp_enqueue_script('jquery-ui-datepicker');

            // Its own specific styles and scripts
            $script_handle = 'fsbhoa-reports-admin';
            wp_enqueue_style('fsbhoa-reports-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-reports-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-reports-admin.js', array('jquery', 'datatables-script', 'jquery-ui-datepicker'), FSBHOA_AC_PLUGIN_VERSION, true);
            
            // Localize data for the reports script
            wp_localize_script($script_handle, 'fsbhoa_reports_vars', array(
                'rest_nonce' => wp_create_nonce( 'wp_rest' ), 
                'export_nonce' => wp_create_nonce( 'fsbhoa_export_nonce' )
            ));
        }


        // ASSETS FOR: [fsbhoa_usage_analytics]
        if ( has_shortcode( $post->post_content, 'fsbhoa_usage_analytics' ) ) {
            // Reuses the same stylesheet as the reports page
            wp_enqueue_style('fsbhoa-reports-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-reports-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            
            // Needs the Chart.js library
            wp_enqueue_script('chart-js', FSBHOA_AC_PLUGIN_URL . 'assets/vendor/chart.min.js', array(), '4.4.3', true);
            
            // Its own specific script
            $script_handle = 'fsbhoa-analytics-admin';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-analytics-admin.js', array('jquery', 'chart-js'), FSBHOA_AC_PLUGIN_VERSION, true);
            
            // Localize data for the analytics script
            wp_localize_script($script_handle, 'fsbhoa_reports_vars', array('rest_nonce' => wp_create_nonce( 'wp_rest' )));
        }


        // ASSETS FOR: [fsbhoa_import_form]
        if ( has_shortcode( $post->post_content, 'fsbhoa_import_form' ) ) {
            wp_enqueue_style('fsbhoa-import-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-import-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script('fsbhoa-import-workflow', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-import-workflow.js', array('jquery'), FSBHOA_AC_PLUGIN_VERSION, true);
            wp_enqueue_script('jquery-ui-dialog'); 
            wp_enqueue_style('wp-jquery-ui-dialog'); 
        }

        // ASSETS FOR: [fsbhoa_live_monitor]
        if ( has_shortcode( $post->post_content, 'fsbhoa_live_monitor' ) ) {
            wp_enqueue_style('tailwindcss-cdn', 'https://cdn.tailwindcss.com');
            wp_enqueue_style('fsbhoa-live-monitor-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-monitor.css', array(), FSBHOA_AC_PLUGIN_VERSION);
            
            $script_handle = 'fsbhoa-live-monitor-script';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-live-monitor.js', [], FSBHOA_AC_PLUGIN_VERSION, true);

            $ws_port = get_option('fsbhoa_ac_monitor_port', 8082);
            $ws_host = get_option('fsbhoa_ac_wp_host', 'access.fsbhoa.com');
            $ws_url = sprintf('wss://%s:%d/ws', $ws_host, $ws_port);

            wp_localize_script($script_handle, 'fsbhoa_monitor_vars', [ 'ws_url' => $ws_url, 'nonce' => wp_create_nonce('wp_rest') ]);
        }

        // ASSETS FOR: [fsbhoa_amenity_management]
        if ( has_shortcode( $post->post_content, 'fsbhoa_amenity_management' ) ) {
            wp_enqueue_style(
                'fsbhoa-amenity-styles', 
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-amenity-styles.css', 
                array('fsbhoa-shared-styles'), 
                FSBHOA_AC_PLUGIN_VERSION,
            );
            wp_enqueue_media();
            wp_enqueue_script(
                'fsbhoa-amenity-admin', 
                FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-amenity-admin.js', 
                array('jquery'), 
                FSBHOA_AC_PLUGIN_VERSION, 
                true,
            );
            wp_localize_script(
                'fsbhoa-amenity-admin',
                'fsbhoa_amenity_data',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('fsbhoa_amenity_nonce')
                )
            );
        }

        // ASSETS FOR: [fsbhoa_groups_page]
        if (has_shortcode($post->post_content, 'fsbhoa_groups_page')) {
            $css_path = FSBHOA_AC_PLUGIN_DIR . 'assets/css/fsbhoa-groups.css';
            wp_enqueue_style(
                'fsbhoa-groups-style',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-groups.css',
                ['fsbhoa-shared-styles'],
                filemtime($css_path)
            );

            $js_path = FSBHOA_AC_PLUGIN_DIR . 'assets/js/groups-admin.js';
            wp_enqueue_script(
                'fsbhoa-groups-admin-js',
                FSBHOA_AC_PLUGIN_URL . 'assets/js/groups-admin.js',
                ['jquery'],
                filemtime($js_path),
                true
            );
        }

        // ASSETS FOR: [fsbhoa_cardholder_report]
        if ( has_shortcode( $post->post_content, 'fsbhoa_cardholder_report' ) ) {
            wp_enqueue_style(
                'fsbhoa-print-report-styles',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-print-report-styles.css',
                array('fsbhoa-shared-styles'),
                FSBHOA_AC_PLUGIN_VERSION
            );
        }
    }

    public function render_print_card_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have permission to view this page.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-print-card.php';
        fsbhoa_render_printable_card_view();
        return ob_get_clean();
    }

    public function render_hardware_management_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        if ( isset( $_GET['discovery-results'] ) ) {
            ob_start();
            fsbhoa_render_discovery_results_view();
            return ob_get_clean();
        }

        $current_view = 'controllers';
        if ( isset( $_GET['view'] ) ) {
            $current_view = sanitize_key( $_GET['view'] );
        } else {
            $atts = shortcode_atts(
                [ 'view' => 'controllers' ],
                $atts,
                'fsbhoa_hardware_management'
            );
            $current_view = sanitize_key( $atts['view'] );
        }

        ob_start();

        if ( $current_view === 'controllers' ) {
            if ( class_exists('Fsbhoa_Controller_Admin_Page') ) {
                $controller_page = new Fsbhoa_Controller_Admin_Page();
                $controller_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Controller management class not found.', 'fsbhoa-ac' ) . '</p>';
            }
        }
        elseif ( $current_view === 'gates' ) {
            if ( class_exists('Fsbhoa_Gate_Admin_Page') ) {
                $gate_page = new Fsbhoa_Gate_Admin_Page();
                $gate_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Gate management class not found.', 'fsbhoa-ac' ) . '</p>';
            }
        }
        return ob_get_clean();
    }

    public function render_live_monitor_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-live-monitor.php';
        fsbhoa_render_live_monitor_view();
        return ob_get_clean();
    }

    public function render_reports_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();

        if ( class_exists('Fsbhoa_Reports_Admin_Page') ) {
            $reports_page = new Fsbhoa_Reports_Admin_Page();
            $reports_page->render_page();
        } else {
            echo '<p>' . esc_html__( 'Error: Reports class not found.', 'fsbhoa-ac' ) . '</p>';
        }

        return ob_get_clean();
    }

    public function render_analytics_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();

        if ( class_exists('Fsbhoa_Analytics_Admin_Page') ) {
            $analytics_page = new Fsbhoa_Analytics_Admin_Page();
            $analytics_page->render_page();
        } else {
            echo '<p>' . esc_html__( 'Error: Analytics class not found.', 'fsbhoa-ac' ) . '</p>';
        }

        return ob_get_clean();
    }

    public function render_amenity_management_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();

        if ( class_exists('Fsbhoa_Amenity_Admin_Page') ) {
            $amenity_page = new Fsbhoa_Amenity_Admin_Page();
            $amenity_page->render_page();
        } else {
            echo '<p>' . esc_html__( 'Error: Amenity admin class not found.', 'fsbhoa-ac' ) . '</p>';
        }

        return ob_get_clean();
    }

    /**
     * Renders the Access Groups management page.
     *
     * This shortcode handler now acts as a simple entry point,
     * instantiating the controller class that handles the actual page rendering.
     *
     * @param array $atts Shortcode attributes (not used).
     * @return string The HTML for the page.
     */
    public function render_groups_page($atts) {
        // Security check: Only users with 'manage_options' can see this page.
        if (!current_user_can('manage_options')) {
            return '<p>' . __('You do not have sufficient permissions to access this page.', 'fsbhoa-ac') . '</p>';
        }

        // Check if the controller class exists before trying to use it.
        if (class_exists('FSBHOA_Groups_Admin_Page')) {
            $groups_page = new FSBHOA_Groups_Admin_Page();
            return $groups_page->render_page();
        } else {
            return '<div class="error"><p>' . esc_html__('Error: The Groups Admin Page class was not found.', 'fsbhoa-ac') . '</p></div>';
        }
    }

    /**
     * Renders the printable report page for selected cardholders.
     */
    public function render_cardholder_report_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have permission to view this page.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/reports/view-cardholder-report.php';
        fsbhoa_render_cardholder_report_view();
        return ob_get_clean();
    }

    /**
     * Renders the Task List page.
     */
    public function render_task_list_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have permission to view this page.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();
        if ( class_exists('Fsbhoa_Task_Admin_Page') ) {
            $task_page = new Fsbhoa_Task_Admin_Page();
            $task_page->render_page();
        } else {
            echo '<p>Error: Task Admin Page class not found.</p>';
        }
        return ob_get_clean();
    }

    /**
     * Renders the Deleted Cardholders management page.
     */
    public function render_archived_cardholders_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        ob_start();
        if ( class_exists('Fsbhoa_Archived_Cardholder_Admin_Page') ) {
            $archived_cardholder_page = new Fsbhoa_Archived_Cardholder_Admin_Page();
            $archived_cardholder_page->render_page();
        } else {
            echo '<p>' . esc_html__( 'Error: Archived Cardholder management class not found.', 'fsbhoa-ac' ) . '</p>';
        }
        return ob_get_clean();
    }

    /**
     * Checks for pending changes and displays a sync banner if needed.
     * This is hooked into wp_body_open to be theme-independent.
     */
    public function display_sync_banner() {
        global $wpdb;
        $table_name = 'ac_pending_changes';

        // This is a very fast query.
        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        // If there are no pending changes, do nothing.
        if ($pending_count == 0) {
            return;
        }

        // If there are pending changes, display the banner.
        $sync_page_url = get_permalink(get_page_by_path('hardware-management')); // Assumes your sync page has this slug
        $sync_url = add_query_arg('view', 'sync', $sync_page_url);
?>
        <div id="fsbhoa-sync-banner">
            <div class="fsbhoa-sync-banner-content">
                <span class="dashicons dashicons-warning"></span>
                <span id="fsbhoa-sync-banner-message">There are pending changes that need to be pushed to the controllers.</span>
                
                <button id="fsbhoa-sync-banner-button" class="button button-primary">Push Changes Now</button>
            </div>
        </div>
        <?php
    }
}

