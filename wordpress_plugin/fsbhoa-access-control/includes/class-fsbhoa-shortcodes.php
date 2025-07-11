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

    /**
     * Renders the main shortcode content.
     * Can now display different views based on the 'view' attribute.
     * e.g., [fsbhoa_cardholder_management view="properties"]
     *
     * @param array|string $atts Shortcode attributes.
     * @return string The HTML output for the shortcode.
     */
    public function render_cardholder_management_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        // Prioritize the URL query string for the view, then fall back to shortcode attributes.
        $current_view = 'cardholders'; // Set a default

        if ( isset( $_GET['view'] ) ) {
            // If 'view' is in the URL, use it.
            $current_view = sanitize_key( $_GET['view'] );
        } else {
            // Otherwise, check the shortcode attributes.
            $atts = shortcode_atts(
                [
                    'view' => 'cardholders', // Default if no attribute is set
                ],
                $atts,
                'fsbhoa_cardholder_management'
            );
            $current_view = sanitize_key( $atts['view'] );
        }


        ob_start();

        // Conditionally render the correct page based on the view
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
            // Default to the cardholder view
            if ( class_exists('Fsbhoa_Cardholder_Admin_Page') ) {
                $cardholder_admin_page = new Fsbhoa_Cardholder_Admin_Page();
                $cardholder_admin_page->render_page();
            } else {
                echo '<p>' . esc_html__( 'Error: Cardholder management class not found.', 'fsbhoa-ac' ) . '</p>';
            }
        }

        return ob_get_clean();
    }

    /**
     *  This function handles loading all CSS and JS for the shortcode.
     * It runs on the 'wp_enqueue_scripts' hook and checks the current view.
     */
    public function enqueue_shortcode_assets() {
        // First, check if we are on a page that is actually displaying our shortcode.
        // This is good practice to prevent loading assets on every single page.
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

        // --- Load assets needed for all views ---
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style('fsbhoa-shared-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-shared-styles.css', array(), FSBHOA_AC_VERSION);
        wp_enqueue_style('datatables-style', 'https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css');
        wp_enqueue_script('datatables-script', 'https://cdn.datatables.net/2.0.8/js/dataTables.js', array('jquery'), '2.0.8', true);


        // --- CARDHOLDER-SPECIFIC ASSETS ---
        $app_script_handle = 'fsbhoa-cardholder-admin-script';
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style('croppie-style', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css', array(), '2.6.5');
        wp_enqueue_style('fsbhoa-cardholder-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-cardholder-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_VERSION);
        wp_enqueue_script('croppie-script', 'https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js', array('jquery'), '2.6.5', true);
        wp_enqueue_script('fsbhoa-photo-croppie', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-photo-croppie.js', array('jquery', 'jquery-ui-dialog', 'croppie-script'), FSBHOA_AC_VERSION, true);
        wp_enqueue_script($app_script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-cardholder-admin.js', array('jquery', 'jquery-ui-autocomplete', 'datatables-script', 'fsbhoa-photo-croppie'), FSBHOA_AC_VERSION, true);
            

        // --- PRINT PAGE & PREVIEW STYLES ---
        $current_view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
        if ( has_shortcode( $post->post_content, 'fsbhoa_print_card' ) || $current_view === 'deleted' ) {
            // Enqueue our stylesheet
            wp_enqueue_style(
                'fsbhoa-print-styles',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-print-styles.css',
                array(),
                FSBHOA_AC_VERSION
            );

            // Enqueue our new workflow script
            wp_enqueue_script(
                'fsbhoa-print-workflow',
                FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-print-workflow.js',
                array('jquery'),
                FSBHOA_AC_VERSION,
                true
            );

            // Localize the script, passing the AJAX URL and a security nonce
            wp_localize_script(
                'fsbhoa-print-workflow',
                'fsbhoa_print_vars',
                array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('fsbhoa_print_card_nonce'),
                    'cardholder_page_url' => get_permalink(get_page_by_path('cardholder'))
                )
            );
        }

        // --- HARDWARE MANAGEMENT (CONTROLLERS, DOORS) ---
        if ( has_shortcode( $post->post_content, 'fsbhoa_hardware_management' ) 
            || has_shortcode( $post->post_content, 'fsbhoa_cardholder_management' ) ) {

			if ( has_shortcode( $post->post_content, 'fsbhoa_hardware_management' ) ) {

                // Enqueue the new controller-specific stylesheet
                wp_enqueue_style(
                    'fsbhoa-controller-styles',
                    FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-controller-styles.css',
                    ['fsbhoa-shared-styles'], // Depends on shared styles
                    FSBHOA_AC_VERSION
                );

  			    // Enqueue the new dedicated JavaScript file for hardware page formatting.
			    wp_enqueue_script(
				    'fsbhoa-hardware-admin',
				    FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-hardware-admin.js',
				    ['jquery', 'datatables-script'],
				    FSBHOA_AC_VERSION,
				    true
			    );
            }

            // ---  CONTROLLER SYNC SCRIPT ---
            wp_enqueue_script(
                'fsbhoa-sync-script',
                FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-sync-admin.js',
                ['jquery'],
                FSBHOA_AC_VERSION,
                true
            );

            wp_localize_script(
                'fsbhoa-sync-script',
                'fsbhoa_sync_vars',
                [
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce'    => wp_create_nonce('fsbhoa_sync_nonce')
                ]
            );
        }

        // --- REPORTS-SPECIFIC ASSETS ---
        if ( has_shortcode( $post->post_content, 'fsbhoa_reports' ) ) {
            $script_handle = 'fsbhoa-reports-admin'; // Define a handle for our script

            wp_enqueue_style('fsbhoa-reports-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-reports-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-reports-admin.js', array('jquery', 'datatables-script'), FSBHOA_AC_PLUGIN_VERSION, true);

            // Needed for date picker
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-style', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');

            // Pass the nonce to the script
            wp_localize_script(
                $script_handle,
                'fsbhoa_reports_vars',
                array(
                    'rest_nonce'   => wp_create_nonce( 'wp_rest' ),
                    'export_nonce' => wp_create_nonce( 'fsbhoa_export_nonce' )
                )
            );
        }
        // --- Load assets for the USAGE ANALYTICS report ---
        if ( has_shortcode( $post->post_content, 'fsbhoa_usage_analytics' ) ) {
            wp_enqueue_style('fsbhoa-shared-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-shared-styles.css', array(), FSBHOA_AC_PLUGIN_VERSION);
            wp_enqueue_style('fsbhoa-reports-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-reports-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION);

            $script_handle = 'fsbhoa-analytics-admin';
            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), '4.4.3', true);
            wp_enqueue_script($script_handle, FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-analytics-admin.js', array('jquery', 'chart-js'), FSBHOA_AC_PLUGIN_VERSION, true);

            wp_localize_script(
                $script_handle,
                'fsbhoa_reports_vars',
                array(
                    'rest_nonce' => wp_create_nonce( 'wp_rest' )
                )
            );
        }
        
        // Localized data for the scripts
        $photo_settings = array('width'  => get_option('fsbhoa_ac_photo_width', 640), 'height' => get_option('fsbhoa_ac_photo_height', 800));
        wp_localize_script($app_script_handle, 'fsbhoa_photo_settings', $photo_settings);
        $ajax_settings = array('ajax_url' => admin_url('admin-ajax.php'), 'property_search_nonce' => wp_create_nonce('fsbhoa_property_search_nonce'));
        wp_localize_script($app_script_handle, 'fsbhoa_ajax_settings', $ajax_settings);

        // --- PROPERTY-SPECIFIC ASSETS ---
        wp_enqueue_style('fsbhoa-property-styles', FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-property-styles.css', array('fsbhoa-shared-styles'), FSBHOA_AC_VERSION);
        wp_enqueue_script('fsbhoa-property-admin', FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-property-admin.js', array('jquery', 'datatables-script'), FSBHOA_AC_VERSION, true);


        // ---  for Import Styles ---
        if ( has_shortcode( $post->post_content, 'fsbhoa_import_form' ) ) {
            wp_enqueue_style(
                'fsbhoa-import-styles',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-import-styles.css',
                array(),
                FSBHOA_AC_VERSION
            );
        }
        // ---  for Deleted Cardholders View ---
        // Check if we are on the deleted cardholders view
        $current_view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
        if ( $current_view === 'deleted' ) {

            $deleted_table_js = "
                jQuery(document).ready(function($) {
                    // Check if the table itself exists
                    var deletedTableElement = $('#fsbhoa-deleted-cardholder-table');
                    if ( deletedTableElement.length ) {

                        // --- NEW CHECK ---
                        // Only initialize if there is data. A data row has multiple cells,
                        // the 'no results' row has only one cell.
                        if ( deletedTableElement.find('tbody tr td').length > 1 ) {
                            var deletedTable = deletedTableElement.DataTable({
                                'paging': true,
                                'searching': true,
                                'info': true,
                                'autoWidth': true,
                                'order': [[ 3, 'desc' ]], // Default sort by the 4th column (Date Deleted) descending
                                'columnDefs': [
                                    { 'orderable': false, 'targets': 'no-sort' }
                                ],
                                'dom': 'lrtip' // Hides default search box
                            });

                            $('#fsbhoa-deleted-cardholder-search-input').on('keyup', function() {
                                deletedTable.search($(this).val()).draw();
                            });
                        }
                    }
                });
            ";

            wp_add_inline_script( 'datatables-script', $deleted_table_js );
        }


        // --- LIVE MONITOR ASSETS ---
		if ( has_shortcode( $post->post_content, 'fsbhoa_live_monitor' ) ) {
            // This provides the utility classes (e.g., h-64, relative, flex) needed for the layout.
            wp_enqueue_style('tailwindcss-cdn', 'https://cdn.tailwindcss.com');

            wp_enqueue_style(
                'fsbhoa-live-monitor-styles',
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-monitor.css',
                array(),
                FSBHOA_AC_VERSION
            );

			$script_handle = 'fsbhoa-live-monitor-script';
			wp_enqueue_script(
				$script_handle,
				FSBHOA_AC_PLUGIN_URL . 'assets/js/fsbhoa-live-monitor.js',
				[], // No dependencies
				FSBHOA_AC_VERSION,
				true
			);

			// Pass the configurable WebSocket URL to the script
			$ws_port = get_option('fsbhoa_ac_event_port', 8083);
            $ws_host = get_option('fsbhoa_ac_wp_host', 'localhost');
			$ws_url = sprintf('wss://%s:%d/ws', $ws_host, $ws_port);

			wp_localize_script(
				$script_handle,
				'fsbhoa_monitor_vars',
                [
                    'ws_url' => $ws_url,
                    'nonce'  => wp_create_nonce('wp_rest') 
                ]
			);
		}

        // --- Load assets for the AMENITY MANAGEMENT page ---
        if ( has_shortcode( $post->post_content, 'fsbhoa_amenity_management' ) ) {
            wp_enqueue_style(
                'fsbhoa-amenity-styles', 
                FSBHOA_AC_PLUGIN_URL . 'assets/css/fsbhoa-amenity-styles.css', 
                array('fsbhoa-shared-styles'), FSBHOA_AC_PLUGIN_VERSION
            ); 
        }

    }


    /**
     * Renders the printable ID card view by loading the dedicated view file.
     *
     * @param array|string $atts Shortcode attributes.
     * @return string The HTML output for the printable card.
     */
    public function render_print_card_shortcode( $atts ) {
        // Security check: Only logged-in users with the right capability can view.
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have permission to view this page.', 'fsbhoa-ac' ) . '</p>';
        }

        // Capture the output from our dedicated view file.
        ob_start();
    
        // Include the view file that does all the work.
        require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-print-card.php';
    
        // Call the main function from that file.
        fsbhoa_render_printable_card_view();

        return ob_get_clean();
    }

    /**
     * Renders the hardware management shortcode content (Controllers, Doors, etc.).
     * e.g., [fsbhoa_hardware_management view="doors"]
     */
    public function render_hardware_management_shortcode( $atts ) {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
        }

        // --- Check if we should render the discovery results page ---
        if ( isset( $_GET['discovery-results'] ) ) {
            ob_start();
            fsbhoa_render_discovery_results_view();
            return ob_get_clean();
        }

        // Handle the view from the URL first, then the shortcode attribute
        $current_view = 'controllers'; // Default to the controllers view
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

    /**
	 * Renders the live activity monitor page.
	 */
	public function render_live_monitor_shortcode( $atts ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have sufficient permissions.', 'fsbhoa-ac' ) . '</p>';
		}

		ob_start();
		require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/views/view-live-monitor.php';
		fsbhoa_render_live_monitor_view();
		return ob_get_clean();
    }

/**
     * Renders the reports page.
     * e.g., [fsbhoa_reports]
     */
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

    /**
     * Renders the usage analytics page.
     * e.g., [fsbhoa_usage_analytics]
     */
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

/**
     * Renders the Amenity Management page.
     * e.g., [fsbhoa_amenity_management]
     */
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
