<?php
/**
 * Module for handling CSV data import via a front-end shortcode.
 * This file now acts as a loader for the new class-based import system.
 *
 * Shortcode: [fsbhoa_import_form]
 * Version: 2.1 (Smart Synchronization with Error Handling)
 */

if (!defined('WPINC')) {
    die;
}

// Include the new class-based logic
require_once plugin_dir_path(__FILE__) . 'class-fsbhoa-import-v2.php';

/**
 * Registers the shortcode with WordPress.
 */
add_action('init', 'fsbhoa_register_import_shortcode_v2');
function fsbhoa_register_import_shortcode_v2()
{
    add_shortcode('fsbhoa_import_form', 'fsbhoa_render_import_shortcode_v2');
}

/**
 * Renders the HTML for the import form by calling the new class.
 */
function fsbhoa_render_import_shortcode_v2()
{
    // Instantiate the new import class and call its main render method
    $importer = new Fsbhoa_Import_V2();
    return $importer->render_shortcode_page();
}
