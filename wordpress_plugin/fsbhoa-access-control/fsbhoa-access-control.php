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
    define( 'FSBHOA_AC_PLUGIN_VERSION', '0.1.6' ); // Keep this in sync
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
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/list-tables/class-fsbhoa-cardholder-list-table.php';


/**
 * Begins execution of the plugin's admin parts.
 *
 * @since    0.1.0
 */
function run_fsbhoa_access_control_admin() {
    // Setup Admin Menu
    if (class_exists('Fsbhoa_Admin_Menu')) {
        $plugin_admin_menu = new Fsbhoa_Admin_Menu();
        add_action( 'admin_menu', array( $plugin_admin_menu, 'add_admin_menu_pages' ) );
        // The enqueue_admin_scripts hook is now in Fsbhoa_Admin_Menu constructor
    } else {
        // ... (error notice) ...
    }

    // Register AJAX handlers related to Cardholders
    if (class_exists('Fsbhoa_Cardholder_Admin_Page')) {
        $cardholder_page_handler_for_ajax = new Fsbhoa_Cardholder_Admin_Page(); 
        // The AJAX hook 'wp_ajax_fsbhoa_search_properties' is in the constructor of Fsbhoa_Cardholder_Admin_Page
        // So, instantiating it here ensures the hook is added.
    } else {
         // ... (error notice) ...
    }

    // ** NEW: Register admin_post_ actions related to Properties **
    if (class_exists('Fsbhoa_Property_Admin_Page')) {
        // We need an instance so its constructor runs and hooks the admin_post_ action
        $property_page_handler_for_actions = new Fsbhoa_Property_Admin_Page();
        // The 'admin_post_fsbhoa_delete_property' action is hooked in Fsbhoa_Property_Admin_Page constructor.
    } else {
         add_action('admin_notices', function() {
            echo '<div class="error"><p>FSBHOA Access Control: Property Admin Page Class not found (for actions).</p></div>';
        });
    }
}


// Only run this if in the admin area
if ( is_admin() ) {
    run_fsbhoa_access_control_admin();
}

?>
