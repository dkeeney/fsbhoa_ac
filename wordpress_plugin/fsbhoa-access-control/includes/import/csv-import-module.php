<?php
/**
 * Module for handling CSV data import via a front-end shortcode.
 * This file handles the UI for import.  The logic for each import 
 * is in separate source files.
 *
 * Shortcode: [fsbhoa_import_form]
 * Version: 2.1 (Smart Synchronization with Error Handling)
 *
 *
 * Gemini: please do not remove this comment block.
 * To clear the database of all records, do the following;
SET FOREIGN_KEY_CHECKS=0;

DELETE FROM `ac_property`;
DELETE FROM `ac_cardholders`;
DELETE FROM `ac_access_log`;
 
ALTER TABLE `ac_property` AUTO_INCREMENT = 1;
ALTER TABLE `ac_cardholders` AUTO_INCREMENT = 1;
ALTER TABLE `ac_access_log` AUTO_INCREMENT = 1;
 
SET FOREIGN_KEY_CHECKS=1;
 *******************************/




if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Registers the shortcode with WordPress.
 */
add_action('init', 'fsbhoa_register_import_shortcode');
function fsbhoa_register_import_shortcode() {
    add_shortcode('fsbhoa_import_form', 'fsbhoa_render_import_shortcode');
}


/**
 * Renders the HTML for the import form and triggers the file processing.
 */
function fsbhoa_render_import_shortcode() {
    ob_start();


    // The two logic files are now included here
    require_once plugin_dir_path( __FILE__ ) . 'homeowner-import-logic.php';
    require_once plugin_dir_path( __FILE__ ) . 'tenant-import-logic.php';


    if (!current_user_can('manage_options')) {
        echo "<p>You do not have sufficient permissions to perform this action.</p>";
        return ob_get_clean();
    }

    // variables to hold the reports
    $homeowner_results_html = '';
    $tenant_results_html = '';


    // Check which form was submitted by checking the name of the submit button
    if (isset($_POST['fsbhoa_import_nonce']) && wp_verify_nonce($_POST['fsbhoa_import_nonce'], 'fsbhoa_import_action')) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {

            if (isset($_POST['submit_homeowners'])) {
                ob_start();
                fsbhoa_handle_csv_upload_frontend($_FILES['csv_file']);
                $homeowner_results_html = ob_get_clean();
            } elseif (isset($_POST['submit_tenants'])) {
                ob_start();
                fsbhoa_handle_tenant_csv_upload($_FILES['csv_file']);
                $tenant_results_html = ob_get_clean();
            }

        } else {
            echo '<div class="fsbhoa-notice error"><p>Error during file upload or no file selected. Please try again.</p></div>';
        }
    }

    ?>
    <div class="fsbhoa-import-wrapper">
       <div class="fsbhoa-importers-container">
         <div class="import-section">
            <h2>Homeowner Import</h2>

            <?php if (!empty($homeowner_results_html)) : ?>
                <div class="import-results">
                    <?php echo $homeowner_results_html; // This is a safe echo because the source is our own function ?>
                </div>
            <?php endif; ?>

            <p>This tool imports or synchronizes homeowner data from a CSV file. 
                The columns <br>can be in any order, but the file should contain a 
                header row with titles similar <br>to the following:</p>
            <ul style="list-style: disc; padding-left: 40px;">
                <li><code>Property Address</code></li>
                <li><code>First Name</code></li>
                <li><code>Last Name</code></li>
                <li><code>Second Owner First Name</code></li>
                <li><code>Second Owner Last Name</code></li>
                <li><code>Phone</code></li>
                <li><code>Email</code></li>
            </ul>
        
            <form method="post" action="" enctype="multipart/form-data" class="fsbhoa-form">
                <?php wp_nonce_field('fsbhoa_import_action', 'fsbhoa_import_nonce'); ?>
                <p>
                    <label for="csv_file_homeowner"><strong>Select Homeowner CSV File:</strong></label><br>
                    <input type="file" id="csv_file_homeowner" name="csv_file" accept=".csv" required>
                </p>
                <p>
                    <label><input type="checkbox" name="csv_has_header" value="1" checked> First row of CSV contains headers</label>
                </p>
                <p>
                    <input type="submit" name="submit_homeowners" class="button-primary" value="Run Homeowner Sync">
                </p>
            </form>
         </div>
         <div class="import-section">
            <h2>Tenant Import</h2>

            <?php if (!empty($tenant_results_html)) : ?>
                 <div class="import-results">
                    <?php echo $tenant_results_html; // This is a safe echo ?>
                </div>
            <?php endif; ?>

            <p>This tool processes a tenant list. For each property in this file, it will **permanently delete**
               any existing "Owners" at that address and insert the new tenant(s).</p>
            <p>Expected Columns:</p>
            <ul style="list-style: disc; padding-left: 40px;">
                <li><code>Address</code> </li>
                <li><code>Phone</code> </li>
                <li><code>Email</code></li>
                <li><code>Tenant Name(s)</code> </li>
            </ul>
            <p> Other columes will be ignored.</p>
            <form method="post" action="" enctype="multipart/form-data" class="fsbhoa-form">
                <?php wp_nonce_field('fsbhoa_import_action', 'fsbhoa_import_nonce'); ?>
                <p>
                    <label for="csv_file_tenant"><strong>Select Tenant CSV File:</strong></label><br>
                    <input type="file" id="csv_file_tenant" name="csv_file" accept=".csv" required>
                </p>
                 <p>
                    <label><input type="checkbox" name="csv_has_header" value="1" checked> First row of CSV contains headers</label>
                </p>
                <p>
                    <input type="submit" name="submit_tenants" class="button-primary" value="Run Tenant Import">
                </p>
            </form>
          </div>
       </div>
    </div>
    <?php

    return ob_get_clean();
}


