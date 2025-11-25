<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Renders the add/edit form for a single controller and its associated gates.
 * This version uses a more compact, single-row layout for gates.
 */
function fsbhoa_render_controller_form( $form_data, $is_edit_mode, $errors = [], $amenities = [] ) {
    $page_title = $is_edit_mode ? 'Edit Controller & Gates' : 'Add New Controller';
    $submit_button_text = $is_edit_mode ? 'Update Controller & Gates' : 'Add Controller';
    $form_post_hook_action = $is_edit_mode ? 'fsbhoa_update_controller' : 'fsbhoa_add_controller';
    $nonce_action = $is_edit_mode ? 'fsbhoa_update_controller_' . ($form_data['controller_record_id'] ?? 0) : 'fsbhoa_add_controller';
    $cancel_url = remove_query_arg(['action', 'controller_id']);
    ?>
    <div class="fsbhoa-frontend-wrap is-form-view">
        <h1><?php echo esc_html( $page_title ); ?></h1>

        <?php if (!empty($errors)) : ?>
            <div class="notice notice-error is-dismissible">
                <p><strong>Please correct the following errors:</strong></p>
                <ul>
                    <?php foreach($errors as $error) : ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form id="fsbhoa-controller-form" method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($form_post_hook_action); ?>" />
            <?php if ($is_edit_mode) : ?>
                <input type="hidden" name="controller_record_id" value="<?php echo esc_attr($form_data['controller_record_id']); ?>" />
            <?php endif; ?>
            <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); ?>" />
            <?php wp_nonce_field( $nonce_action, '_wpnonce' ); ?>

            <!-- === Controller Details Section === -->
            <div class="fsbhoa-form-section">
                <h2>Controller Details</h2>
                <div class="form-row is-multi-column">
                    <div class="form-field">
                        <label for="friendly_name">Name</label>
                        <input name="friendly_name" type="text" id="friendly_name" value="<?php echo esc_attr($form_data['friendly_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-field">
                        <label for="uhppoted_device_id">Device ID (Serial)</label>
                        <input name="uhppoted_device_id" type="number" id="uhppoted_device_id" value="<?php echo esc_attr($form_data['uhppoted_device_id'] ?? ''); ?>" required>
                    </div>
                     <div class="form-field">
                        <label for="door_count">Controller Model</label>
                        <select name="door_count" id="door_count">
                            <option value="1" <?php selected($form_data['door_count'], 1); ?>>1-Door</option>
                            <option value="2" <?php selected($form_data['door_count'], 2); ?>>2-Door</option>
                            <option value="4" <?php selected($form_data['door_count'], 4); ?>>4-Door</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="ip_address">IP Address</label>
                        <input name="ip_address" type="text" id="ip_address" value="<?php echo esc_attr($form_data['ip_address'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-field is-full-width">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" rows="3"><?php echo esc_textarea($form_data['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- === Associated Gates/Doors Section (only in edit mode) === -->
            <?php if ($is_edit_mode) : ?>
            <div class="fsbhoa-form-section" id="gates-section">
                <h2>Associated Gates/Doors</h2>
                <div class="gates-container">
                    <div class="gate-form-row gate-header-row">
                        <div class="gate-slot-label">&nbsp;</div>
                        <div class="form-field gate-name-field">
                            <strong>Gate Name</strong>
                        </div>
                        <div class="form-field gate-role-field">
                            <strong>Role/Amenity</strong>
                        </div>
                        <div class="form-field gate-notes-field">
                            <strong>Notes</strong>
                        </div>
                    </div>
                    <?php for ($i = 1; $i <= $form_data['door_count']; $i++) : 
                        $door_data = $form_data['doors'][$i] ?? null;
                        $door_record_id = $door_data['door_record_id'] ?? '';
                        $door_name = $door_data['friendly_name'] ?? '';
                        $door_notes = $door_data['notes'] ?? '';
                    ?>
                        <div class="gate-form-row">
                            <input type="hidden" name="gates[<?php echo $i; ?>][door_record_id]" value="<?php echo esc_attr($door_record_id); ?>">
                            
                            <div class="gate-slot-label">
                                <strong>Slot #<?php echo $i; ?></strong>
                            </div>
                            <div class="form-field gate-name-field">
                                <label for="gate_name_<?php echo $i; ?>">Gate Name</label>
                                <input type="text" id="gate_name_<?php echo $i; ?>" name="gates[<?php echo $i; ?>][friendly_name]" value="<?php echo esc_attr($door_name); ?>" placeholder="(Unused)">
                            </div>
                            <div class="form-field gate-role-field">
                                <label for="gate_amenity_role_<?php echo $i; ?>">Amenity Role</label>

                                <?php 
                                // Get all active amenities from the database
                                global $wpdb;
                                $amenities = $wpdb->get_results("SELECT id, name FROM ac_amenities WHERE is_active = 1 ORDER BY display_order ASC", ARRAY_A);
                                
                                // Define the hardcoded system roles that are NOT amenities
                                $system_roles = [
                                     ''                       => '— Not Set —',
                                     'NO_AMENITY'             => 'No Amenity',
                                     'AFTER_HOURS_ACCESS'     => 'After Hours Entry',
                                     'UNUSED'                 => 'Unused Gate Slot',
                                ];

                                // Combine system roles and dynamic amenities into one associative array
                                $combined_roles = $system_roles;

                                // Add dynamic amenities with a unique prefix (AMENITY_ID_) to distinguish them from system roles
                                foreach ($amenities as $amenity) {
                                    // We use the unique amenity ID prefixed by 'AMENITY_' as the dropdown value
                                    $combined_roles['AMENITY_' . $amenity['id']] = 'Amenity: ' . esc_html($amenity['name']);
                                }


                                $current_role = $door_data['amenity_role'] ?? '';
                                ?>
                                <select name="gates[<?php echo $i; ?>][amenity_role]" id="gate_amenity_role_<?php echo $i; ?>">
                                    <?php foreach ($combined_roles as $value => $label) : ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($current_role, $value); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-field gate-notes-field">
                                <label for="gate_notes_<?php echo $i; ?>">Notes</label>
                                <input type="text" id="gate_notes_<?php echo $i; ?>" name="gates[<?php echo $i; ?>][notes]" value="<?php echo esc_attr($door_notes); ?>">
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo esc_html( $submit_button_text ); ?></button>
                <a href="<?php echo esc_url($cancel_url); ?>" class="button button-secondary">Cancel</a>

                <?php // Only show the reset button in edit mode ?>
                <?php if ($is_edit_mode) : ?>
                    <button type="button" 
                            id="fsbhoa-factory-reset-button" 
                            class="button button-link-delete" 
                            data-controller-id="<?php echo esc_attr($form_data['controller_record_id']); ?>" 
                            style="margin-left: 10px; float: right;">
                        Factory Reset
                    </button>
                <?php endif; ?>
            </p>
        </form>
    </div>
    <style>
        .is-multi-column { display: flex; align-items: flex-end; gap: 15px; }
        .is-multi-column .form-field { flex: 1; }
        .fsbhoa-form-section { margin-bottom: 2em; padding-bottom: 1.5em; border-bottom: 1px solid #ddd; }
        
        /* New styles for compact gate rows */
        .gate-form-row {
            display: flex;
            align-items: center; /* Vertically center items in the row */
            gap: 15px; /* Space between elements */
            margin-bottom: 10px; /* Space between rows */
        }
        .gate-slot-label {
            flex-basis: 80px; /* Fixed width for the "Slot #" label */
            flex-shrink: 0;
        }
        .gate-name-field {
            flex: 1 1 20%; /* Grow and shrink, base size 30% */
        }
        .gate-role-field {
            flex: 1 1 30%; /* Give the amenity dropdown some space */
        }
        .gate-notes-field {
            flex: 1 1 50%; /* Grow and shrink, base size 60% */
        }
        .gate-form-row .form-field label {
            display: none; /* Hide labels as the fields are now self-explanatory */
        }
        .gate-form-row .form-field input {
            width: 100%; /* Make inputs fill their container */
        }
        /* *** STYLES FOR THE GATE HEADER *** */
        .gate-header-row {
            font-size: 0.9em;
            color: #555;
            margin-bottom: 5px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ccc;
            align-items: center;
        }

        .gate-header-row .form-field {
            /* Reset padding/margin that might be inherited from generic WordPress form-field styles */
            padding: 0;
            margin: 0;
        }

        /* Ensure the text itself is aligned correctly */
        .gate-header-row .gate-name-field strong,
        .gate-header-row .gate-role-field strong,
        .gate-header-row .gate-notes-field strong {
            display: block; /* Treat the <strong> tag as a block for correct positioning */
            line-height: 1.5; /* Optional: Adjust line height if the text is still too tight */
        }
        .gate-header-row .form-field {
            flex-direction: row; /* Override default column direction for header text */
        }
        .gate-header-row .gate-name-field { 
            flex: 1 1 20%; 
            text-align: left; /* ADD THIS */
        }
        .gate-header-row .gate-role-field { 
            flex: 1 1 30%; 
            text-align: left; /* ADD THIS */
        }
        .gate-header-row .gate-notes-field { 
            flex: 1 1 50%; 
            text-align: left; /* ADD THIS */
        }
    </style>
    <?php
}


