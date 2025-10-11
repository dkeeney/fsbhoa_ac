jQuery(document).ready(function($) {
    'use strict';

    // --- "All Access" UI Toggling ---
    const allAccessCheckbox = $('#has_all_access');
    const permissionsWrapper = $('#permissions-details-wrapper');
    const addRuleButton = $('#add-permission-rule');

    function togglePermissionsVisibility() {
        const isAllAccess = allAccessCheckbox.is(':checked');
        
        if (isAllAccess) {
            permissionsWrapper.hide();
        } else {
            permissionsWrapper.show();
        }

        // ADD THIS LINE: to enable/disable the button
        addRuleButton.prop('disabled', isAllAccess);
    }

    // Run on page load to set the correct initial state
    togglePermissionsVisibility();
    // Add a change event listener to the checkbox
    allAccessCheckbox.on('change', togglePermissionsVisibility);


    // --- Add/Remove Permission Rule Rows ---
    let permissionIndex = $('#permissions-container .permission-row').length;

    $('#add-permission-rule').on('click', function() {
        // Get the template HTML from the hidden table body.
        let template = $('#permission-row-template').html();
        if (!template) {
            alert('Error: Permission row template not found.');
            return;
        }

        // Replace the '{{INDEX}}' placeholder with the new, unique index.
        template = template.replace(/{{INDEX}}/g, permissionIndex);

        // Append the new row to the table.
        $('#permissions-container').append(template);

        // Increment the index for the next row.
        permissionIndex++;
    });

    $('#permissions-container').on('click', '.remove-permission-rule', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to remove this permission rule?')) {
            $(this).closest('.permission-row').remove();
        }
    });

    $('#permissions-container').on('click', '.toggle-permission-status', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $checkbox = $button.siblings('.is-enabled-checkbox');
        const $icon = $button.find('.dashicons');
        const isCurrentlyEnabled = $checkbox.prop('checked');
        $checkbox.prop('checked', !isCurrentlyEnabled);

        if ($checkbox.prop('checked')) {
            $icon.removeClass('dashicons-no-alt').addClass('dashicons-yes-alt');
        } else {
            $icon.removeClass('dashicons-yes-alt').addClass('dashicons-no-alt');
        }
    });
});
