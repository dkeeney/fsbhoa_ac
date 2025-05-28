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
 * Load core plugin class for admin area.
 */
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-admin-menu.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    0.1.0
 */
function run_fsbhoa_access_control_admin() {
    $plugin_admin = new Fsbhoa_Admin_Menu(); // Assuming your class is named Fsbhoa_Admin_Menu
    // Add WordPress action hooks here that call methods on $plugin_admin
    // For example, to add the admin menu:
    add_action( 'admin_menu', array( $plugin_admin, 'add_admin_menu_pages' ) );
}

// Only run this if in the admin area
if ( is_admin() ) {
    run_fsbhoa_access_control_admin();
}

?>