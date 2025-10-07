jQuery(document).ready(function($) {
    'use strict';

    // Start the index for new rows based on how many already exist on the page.
    let permissionIndex = $('#permissions-container .permission-row').length;

    /**
     * Handles adding a new permission rule row to the form.
     */
    $('#add-permission-rule').on('click', function() {
        // Get the template HTML from the hidden table body.
        let template = $('#permission-row-template').html();
        
        // Replace the '{{INDEX}}' placeholder with the new, unique index.
        template = template.replace(/{{INDEX}}/g, permissionIndex);
        
        // Append the new row to the table.
        $('#permissions-container').append(template);
        
        // Increment the index for the next row.
        permissionIndex++;
    });

    /**
     * Handles removing a permission rule row.
     */
    $('#permissions-container').on('click', '.remove-permission-rule', function(e) {
        e.preventDefault();
        
        if (confirm('Are you sure you want to remove this permission rule?')) {
            $(this).closest('.permission-row').remove();
        }
    });

    /**
     * Handles toggling the enabled/disabled status of a permission rule.
     */
    $('#permissions-container').on('click', '.toggle-permission-status', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        // Find the hidden checkbox that is next to this button
        const $checkbox = $button.siblings('.is-enabled-checkbox');
        const $icon = $button.find('.dashicons');

        // Toggle the 'checked' property of the hidden checkbox
        const isCurrentlyEnabled = $checkbox.prop('checked');
        $checkbox.prop('checked', !isCurrentlyEnabled);

        // Update the visual icon based on the new state
        if ($checkbox.prop('checked')) { // It is now enabled
            $icon.removeClass('dashicons-no-alt').addClass('dashicons-yes-alt');
        } else { // It is now disabled
            $icon.removeClass('dashicons-yes-alt').addClass('dashicons-no-alt');
        }
    });

    /**
     * Function to toggle the visibility of the permissions section based on the checkbox.
     */
    function togglePermissionsVisibility() {
        if ($('#has_all_access').is(':checked')) {
            $('#permissions-details-wrapper').hide();
        } else {
            $('#permissions-details-wrapper').show();
        }
    }

    // Run the function once on page load to set the initial state.
    togglePermissionsVisibility();

    // Add a change event listener to the checkbox to run the function whenever it's clicked.
    $('#has_all_access').on('change', function() {
        togglePermissionsVisibility();
    });

});

