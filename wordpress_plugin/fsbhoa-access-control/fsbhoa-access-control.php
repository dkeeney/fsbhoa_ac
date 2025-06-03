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
// For Cardholder DISPLAY
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-cardholder-admin-page.php'; 
// For Cardholder ACTIONS (new)
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-cardholder-actions.php';
// For Property Display & Actions (already refactored similarly)
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/class-fsbhoa-property-admin-page.php'; 
// List Table classes
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/list-tables/class-fsbhoa-property-list-table.php';
require_once FSBHOA_AC_PLUGIN_DIR . 'includes/admin/list-tables/class-fsbhoa-cardholder-list-table.php';


// In fsbhoa-access-control.php

/**
 * Begins execution of the plugin's admin parts.
 * Initializes admin menu and action handlers.
 */
function run_fsbhoa_access_control_admin() {
    // Setup Admin Menu
    if (class_exists('Fsbhoa_Admin_Menu')) {
        $plugin_admin_menu = new Fsbhoa_Admin_Menu();
        add_action( 'admin_menu', array( $plugin_admin_menu, 'add_admin_menu_pages' ) );
        // Note: The enqueue_admin_scripts hook is added in Fsbhoa_Admin_Menu's constructor
    } else {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>FSBHOA Access Control Plugin Error:</strong> The Fsbhoa_Admin_Menu class is missing. Admin menus may not appear.</p></div>';
        });
    }

    // Instantiate Cardholder ACTIONS handler (its constructor sets up admin_post_ and ajax hooks)
    if (class_exists('Fsbhoa_Cardholder_Actions')) {
        $cardholder_actions_handler = new Fsbhoa_Cardholder_Actions();
    } else {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>FSBHOA Access Control Plugin Error:</strong> The Fsbhoa_Cardholder_Actions class is missing. Cardholder add/edit/delete/search functionality will not work.</p></div>';
        });
    }

    // Instantiate Property Page and ACTIONS handler (its constructor sets up admin_post_ hooks)
    // Note: Fsbhoa_Property_Admin_Page handles both display and its own actions via its constructor.
    if (class_exists('Fsbhoa_Property_Admin_Page')) {
        $property_page_handler = new Fsbhoa_Property_Admin_Page();
        // The menu callback in Fsbhoa_Admin_Menu will call $property_page_handler->render_page()
        // Its constructor should be hooking its own admin_post_ actions.
    } else {
         add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>FSBHOA Access Control Plugin Error:</strong> The Fsbhoa_Property_Admin_Page class is missing. Property management functionality will not work.</p></div>';
        });
    }

    // Note: Fsbhoa_Cardholder_Admin_Page is instantiated by the menu callback in Fsbhoa_Admin_Menu
    // when its specific page is loaded. If it were missing, the callback would show an error.
    // We don't need to instantiate it here just for its hooks if its constructor is now empty of hooks.
}


// Only run this if in the admin area
if ( is_admin() ) {
    run_fsbhoa_access_control_admin();
}

?>
