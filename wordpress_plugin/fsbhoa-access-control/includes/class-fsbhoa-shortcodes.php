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
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_shortcode_assets' ) );
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
        } elseif ( $current_view === 'deleted' ) {
            if ( class_exists('Fsbhoa_Deleted_Cardholder_Admin_Page') ) {
                $deleted_cardholder_page = new Fsbhoa_Deleted_Cardholder_Admin_Page();
                $deleted_cardholder_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Deleted Cardholder management class not found.', 'fsbhoa-ac' ) . '</p>';
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
            ) ) {
            return;
        }

        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style('fsbhoa-shared-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-shared-styles.css', array(), FSBHOA_AC_PLUGIN_VERSION);
        wp_enqueue_style('datatables-style', 'https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css');
        wp_enqueue_script('datatables-script', 'https://cdn.datatables.net/2.0.8/js/dataTables.js', array('jquery'), '2.0.8', true);

        $app_script_handle = 'fsbhoa-cardholder-admin-script';
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style('croppie-style', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css', array(), '2.6.5');
        wp_enqueue_style('fsbhoa-cardholder-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-cardholder-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
        wp_enqueue_script('croppie-script', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js', array('jquery'), '2.6.5', true);
        wp_enqueue_script('fsbhoa-photo-croppie', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-photo-croppie.js', array('jquery', 'jquery-ui-dialog', 'croppie-script'), FSBHOA_AC_PLUGIN_VERSION, true);
        wp_enqueue_script($app_script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-cardholder-admin.js', array('jquery', 'jquery-ui-autocomplete', 'datatables-script', 'fsbhoa-photo-croppie'), FSBHOA_AC_PLUGIN_VERSION, true);

        $current_view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
        if ( has_shortcode( $post->post_content, 'fsbhoa_print_card' ) || $current_view === 'deleted' ) {
            wp_enqueue_style('fsbhoa-print-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-print-styles.css', array(), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script('fsbhoa-print-workflow', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-print-workflow.js', array('jquery'), FSBHOA_AC_PLUGIN_VERSION, true);
            wp_localize_script('fsbhoa-print-workflow', 'fsbhoa_print_vars', array('ajax_url' => admin_url('admin-ajax.php'), 'nonce'  => wp_create_nonce('fsbhoa_print_card_nonce'), 'cardholder_page_url' => get_permalink(get_page_by_path('cardholder'))));
        }

        if ( has_shortcode( $post->post_content, 'fsbhoa_hardware_management' ) || has_shortcode( $post->post_content, 'fsbhoa_cardholder_management' ) ) {
            if ( has_shortcode( $post->post_content, 'fsbhoa_hardware_management' ) ) {
                wp_enqueue_style('fsbhoa-controller-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-controller-styles.css', ['fsbhoa-shared-styles'], FSBHOA_AC_PLUGIN_VERSION);
                wp_enqueue_script('fsbhoa-hardware-admin', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-hardware-admin.js', ['jquery', 'datatables-script'], FSBHOA_AC_PLUGIN_VERSION, true);
            }
            wp_enqueue_script('fsbhoa-sync-script', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-sync-admin.js', ['jquery'], FSBHOA_AC_PLUGIN_VERSION, true);
            wp_localize_script('fsbhoa-sync-script', 'fsbhoa_sync_vars', [ 'ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('fsbhoa_sync_nonce')]);
        }

        if ( has_shortcode( $post->post_content, 'fsbhoa_reports' ) ) {
            $script_handle = 'fsbhoa-reports-admin';
            wp_enqueue_style('fsbhoa-reports-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-reports-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-reports-admin.js', array('jquery', 'datatables-script'), FSBHOA_AC_PLUGIN_VERSION, true);
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');
            wp_localize_script($script_handle, 'fsbhoa_reports_vars', array('rest_nonce' => wp_create_nonce( 'wp_rest' ), 'export_nonce' => wp_create_nonce( 'fsbhoa_export_nonce' )));
        }

        if ( has_shortcode( $post->post_content, 'fsbhoa_usage_analytics' ) ) {
            wp_enqueue_style('fsbhoa-shared-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-shared-styles.css', array(), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_style('fsbhoa-reports-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-reports-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            $script_handle = 'fsbhoa-analytics-admin';
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.3', true);
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-analytics-admin.js', array('jquery', 'chart-js'), FSBHOA_AC_PLUGIN_VERSION, true);
            wp_localize_script($script_handle, 'fsbhoa_reports_vars', array('rest_nonce' => wp_create_nonce( 'wp_rest' )));
        }

        $photo_settings = array('width'  => get_option('fsbhoa_ac_photo_width', 640), 'height' => get_option('fsbhoa_ac_photo_height', 800));
        wp_localize_script($app_script_handle, 'fsbhoa_photo_settings', $photo_settings);
        $ajax_settings = array('ajax_url' => admin_url('admin-ajax.php'), 'property_search_nonce' => wp_create_nonce('fsbhoa_property_search_nonce'));
        wp_localize_script($app_script_handle, 'fsbhoa_ajax_settings', $ajax_settings);

        wp_enqueue_style('fsbhoa-property-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-property-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
        wp_enqueue_script('fsbhoa-property-admin', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-property-admin.js', array('jquery', 'datatables-script'), FSBHOA_AC_PLUGIN_VERSION, true);

        if ( has_shortcode( $post->post_content, 'fsbhoa_import_form' ) ) {
            wp_enqueue_style('fsbhoa-import-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-import-styles.css', array(), FSBHOA_AC_PLUGIN_VERSION);
        }

        $current_view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
        if ( $current_view === 'deleted' ) {
            $deleted_table_js = "
                jQuery(document).ready(function($) {
                    var deletedTableElement = $('#fsbhoa-deleted-cardholder-table');
                    if ( deletedTableElement.length && deletedTableElement.find('tbody tr td').length > 1 ) {
                        var deletedTable = deletedTableElement.DataTable({
                            'paging': true, 'searching': true, 'info': true, 'autoWidth': true,
                            'order': [[ 3, 'desc' ]], 'columnDefs': [ { 'orderable': false, 'targets': 'no-sort' } ], 'dom': 'lrtip'
                        });
                        $('#fsbhoa-deleted-cardholder-search-input').on('keyup', function() { deletedTable.search($(this).val()).draw(); });
                    }
                });
            ";
            wp_add_inline_script( 'datatables-script', $deleted_table_js );
        }

        if ( has_shortcode( $post->post_content, 'fsbhoa_live_monitor' ) ) {
            wp_enqueue_style('tailwindcss-cdn', 'https://cdn.tailwindcss.com');
            wp_enqueue_style('fsbhoa-live-monitor-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-monitor.css', array(), FSBHOA_AC_PLUGIN_VERSION);
            $script_handle = 'fsbhoa-live-monitor-script';
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-live-monitor.js', [], FSBHOA_AC_PLUGIN_VERSION, true);

            // Pass the WebSocket URL for the new monitor_service
            $ws_port = get_option('fsbhoa_ac_monitor_port', 8082); // Use the new setting
            $ws_host = get_option('fsbhoa_ac_wp_host', 'nas.fsbhoa.com');
            $ws_url = sprintf('wss://%s:%d/ws', $ws_host, $ws_port); // Use secure wss://

            wp_localize_script($script_handle, 'fsbhoa_monitor_vars', [ 'ws_url' => $ws_url, 'nonce'  => wp_create_nonce('wp_rest') ]);
        }

        if ( has_shortcode( $post->post_content, 'fsbhoa_amenity_management' ) ) {
            wp_enqueue_style('fsbhoa-amenity-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-amenity-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
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
        elseif ( $current_view === 'tasks' ) {
            if ( class_exists('Fsbhoa_Task_Admin_Page') ) {
                $task_page = new Fsbhoa_Task_Admin_Page();
                $task_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Task List management class not found.', 'fsbhoa-ac' ) . '</p>';
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
}

