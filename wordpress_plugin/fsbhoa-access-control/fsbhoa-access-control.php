<?php
/**
 * Plugin Name:       FSBHOA Access Control
 * Plugin URI:        https://your-hoa-website.com/fsbhoa-access-control (Update this)
 * Description:       Manages HOA resident photo IDs, access control, and card printing for FSBHOA.
 * Version:           0.1.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            FSBHOA IT Committee
 * Author URI:        https://your-hoa-website.com/ (Update this)
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fsbhoa-ac
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin version and path constants
define( 'FSBHOA_AC_VERSION', '0.1.0' );
define( 'FSBHOA_AC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSBHOA_AC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Define FSBHOA_AC_PLUGIN_VERSION if not already defined elsewhere, e.g., in this file
if ( ! defined( 'FSBHOA_AC_PLUGIN_VERSION' ) ) {
    define( 'FSBHOA_AC_PLUGIN_VERSION', '0.1.5' ); // Keep this in sync
}

// Activation / Deactivation Hooks
function fsbhoa_ac_activate() {
    // Activation code can go here later
    // Example: require_once FSBHOA_AC_PLUGIN_DIR . 'includes/class-fsbhoa-activator.php';
    // Fsbhoa_Ac_Activator::activate();
}
register_activation_hook( __FILE__, 'fsbhoa_ac_activate' );

function fsbhoa_ac_deactivate() {
    // Deactivation code can go here later
    // Example: require_once FSBHOA_AC_PLUGIN_DIR . 'includes/class-fsbhoa-deactivator.php';
    // Fsbhoa_Ac_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'fsbhoa_ac_deactivate' );


/**
 * Load core plugin classes for admin area.
 */
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-admin-menu.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-cardholder-admin-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-property-admin-page.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/list-tables/class-fsbhoa-property-list-table.php';

/**
 * Begins execution of the plugin's admin parts.
 *
 * @since    0.1.0
 */
function run_fsbhoa_access_control_admin() {
    if (class_exists('Fsbhoa_Admin_Menu')) { // Check if class exists before new-ing
        $plugin_admin_menu = new Fsbhoa_Admin_Menu();
        add_action( 'admin_menu', array( $plugin_admin_menu, 'add_admin_menu_pages' ) );
    } else {
        // Optionally, add an admin notice if the class isn't found
        add_action('admin_notices', function() {
            echo '<div class="error"><p>FSBHOA Access Control: Admin Menu Class not found.</p></div>';
        });
    }

    // ** Register AJAX handlers related to Cardholders **
    if (class_exists('Fsbhoa_Cardholder_Admin_Page')) {
        // We need an instance to hook a non-static method
        $cardholder_page_handler_for_ajax = new Fsbhoa_Cardholder_Admin_Page(); 
        add_action('wp_ajax_fsbhoa_search_properties', array($cardholder_page_handler_for_ajax, 'ajax_search_properties_callback'));
    } else {
         add_action('admin_notices', function() {
            echo '<div class="error"><p>FSBHOA Access Control: Cardholder Admin Page Class not found (for AJAX).</p></div>';
        });
    }

}

// Only run this if in the admin area
if ( is_admin() ) {
    run_fsbhoa_access_control_admin();
}

?>
