jQuery(document).ready(function($) {
    'use strict';

    let isFsbhoaSaving = false;

    // Initialize the DataTables library on the groups list table.
    var groupTable = $('#fsbhoa-schedule-group-table');
    if (groupTable.length) {
        groupTable.DataTable({
            'paging': false,
            'searching': false,
            'info': false,
            'order': [[1, 'asc']], // Default sort by Group Name
            'columnDefs': [{'orderable': false, 'targets': 'no-sort'}]
        });
    }

    // Initialize the DataTables library on the tasks list table.
    var taskTable = $('#fsbhoa-schedule-task-table');
    if (taskTable.length) {
        taskTable.DataTable({
            'paging': false,
            'searching': false,
            'info': false,
            'order': [], // No default sort
            'columnDefs': [{'orderable': false, 'targets': 'no-sort'}]
        });
    }

    // If in add mode (group_id == 0) then force cursor to group_name field.
    if ($('input[name="group_id"]').val() == "0") {
        $('#group_name').focus();
    }
    
    // Add New Permission Rule (Single Handler)
    function addPermissionRowHandler(e) {
        e.preventDefault();
        e.stopImmediatePropagation(); // This stops the event from reaching any other listeners

        // GUARD: Don't allow adding rules if name is blank
        if ($('#group_name').val().trim() === "") {
            $('#group_name').focus();
            $('#sync-status-indicator').html('<span class="dashicons dashicons-warning" style="color: #d63638;"></span> Name required before adding rules');
            return;
        }

        // Safety check: Prevent clicking again until this cycle is done
        const $btn = $(this);
        if ($btn.data('is-adding')) return;
        $btn.data('is-adding', true);

        // 1. Calculate the next index
        const index = $('#permissions-container tr').length;

        // 2. Grab the template and replace the placeholder
        let rowHtml = $('#permission-row-template').html();
        if (!rowHtml) {
            console.error('Template not found!');
            return;
        }
        rowHtml = rowHtml.replace(/{{INDEX}}/g, index);

        // 3. Append to table
        const $newRow = $(rowHtml).appendTo('#permissions-container');
        
        // 4. Focus the first select box so the user can start typing
        $newRow.find('select').first().focus();

        // 5. Trigger the AJAX sync
        saveAndRefreshVisualizer();

        // Reset safety check
        setTimeout(() => { $btn.data('is-adding', false); }, 500);
    }
    $('#add-permission-rule').off('click').on('click', addPermissionRowHandler);

    // Handler for the "Copy From" button
    $('body').on('click', '#fsbhoa-copy-rules-button', function(e) {
        if (!confirm('This will replace all permissions and tasks for the current schedule. Are you sure?')) {
            return;
        }
        const button = $(this);
        const form = button.closest('form');
        const originalText = button.text();
        button.text('Copying...').prop('disabled', true);

        // Get data directly from the form fields
        const destId = form.find('input[name="destination_schedule_id"]').val();
        const sourceId = form.find('select[name="source_schedule_id"]').val();

        $.ajax({
            url: fsbhoa_schedules_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fsbhoa_copy_schedule_rules',
                nonce: fsbhoa_schedules_vars.nonce,
                destination_schedule_id: destId,
                source_schedule_id: sourceId
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + response.data);
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert('An unknown AJAX error occurred: ' + textStatus + ' - ' + errorThrown);
                button.text(originalText).prop('disabled', false);
            }
        });
    });

    // Handler for the "X" delete button on the tabs
    $('body').on('click', '.fsbhoa-tab-delete-x', function(e) {
        // Stop the browser from following the tab's main link
        e.preventDefault();
        e.stopPropagation();

        if (!confirm('Are you sure you want to permanently delete this schedule and all its rules?')) {
            return;
        }

        // Get the schedule ID from the parent tab's link
        const tabLink = $(this).closest('a');
        const url = new URL(tabLink.attr('href'));
        const scheduleId = url.searchParams.get('schedule_id');

        $.ajax({
            url: fsbhoa_schedules_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fsbhoa_delete_schedule',
                nonce: fsbhoa_schedules_vars.nonce,
                schedule_id: scheduleId
            },
            success: function(response) {
                if (response.success) {
                    // On success, redirect back to the main Schedules page (Default tab)
                    const baseUrl = window.location.href.split('?')[0];
                    window.location.href = baseUrl;
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert('An unknown AJAX error occurred: ' + textStatus + ' - ' + errorThrown);
            }
        });
    });

    // Toggle Permission Enabled/Disabled Status
    $('body').on('click', '.toggle-permission-status', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('.permission-row');
        var $checkbox = $row.find('.is-enabled-checkbox');
        var $icon = $btn.find('.dashicons');

        if ($checkbox.val() == "1") {
            // Disable it
            $checkbox.val("0").prop('checked', false);
            $icon.removeClass('dashicons-yes-alt').addClass('dashicons-no-alt');
            $row.addClass('row-disabled').css('opacity', '0.5');
        } else {
            // Enable it
            $checkbox.val("1").prop('checked', true);
            $icon.removeClass('dashicons-no-alt').addClass('dashicons-yes-alt');
            $row.removeClass('row-disabled').css('opacity', '1');
        }
    });

    // Handle Removing a row
    $('body').on('click', '.remove-permission-rule', function(e) {
        e.preventDefault();
        if (confirm('Remove this rule?')) {
            $(this).closest('tr').fadeOut(300, function() {
                $(this).remove();
            });
        }
    });

    // Handle the Exit button with a safety delay for pending saves
    $(document).on('mousedown', '#exit-editor-btn', function() {
        // We use mousedown because it fires BEFORE the blur event 
        // This lets us "unhook" the focus kick-back so they can actually leave
        window.fsbhoa_is_exiting = true;
        saveAndRefreshVisualizer(); // Force one last save immediately
    });

    // Update the blur handler slightly to check for the exit flag
    $(document).on('blur', '#group_name', function() {
        if (window.fsbhoa_is_exiting) return; // Let them leave!

        const val = $(this).val().trim();
        const groupId = $('input[name="group_id"]').val();
    
        if (groupId == "0" && val === "") {
            $(this).focus();
        } else {
            // Delay the blur save slightly. If a click on a button happens, 
            // the button's handler can "cancel" this redundant save.
            setTimeout(() => {
                if (!window.fsbhoa_is_saving_from_click) {
                    saveAndRefreshVisualizer();
                }
                window.fsbhoa_is_saving_from_click = false;
            }, 100);
        }
    });

    /**
     * Validates the time logic before sending to the server
     */
    function validatePermissions() {
        let isValid = true;
        $('.permission-row').each(function() {
            const start = $(this).find('input[name*="[start_time]"]').val();
            const end = $(this).find('input[name*="[end_time]"]').val();

            if (start && end) {
                // Convert HH:MM to minutes for comparison
                const sParts = start.split(':');
                const eParts = end.split(':');
                const sMin = (parseInt(sParts[0]) * 60) + parseInt(sParts[1]);
                const eMin = (parseInt(eParts[0]) * 60) + parseInt(eParts[1]);

                if (sMin >= eMin && eMin !== 0) { // Allow 00:00 as end-of-day
                    $(this).css('background-color', '#fff0f0'); // Highlight the error row
                    isValid = false;
                } else {
                    $(this).css('background-color', ''); // Reset if fixed
                }
            }
        });
        return isValid;
    }

    /**
     * Updated Save and Refresh with Validation
     */
    function saveAndRefreshVisualizer() {
        // 2. THE LOCK: If already saving, ignore this request
        if (isFsbhoaSaving) return;

        const $nameField = $('#group_name');
        const groupName = $nameField.val().trim();
        const groupId = $('input[name="group_id"]').val();
        const $status = $('#sync-status-indicator');

        // IF NEW AND NAME IS EMPTY, STOP.
        if (groupId == "0" && groupName === "") {
            $status.html('<span class="dashicons dashicons-warning" style="color: #d63638;"></span> Group Name Required');
            $nameField.addClass('field-error').css('border-color', '#d63638');
            return false; 
        }
        $nameField.removeClass('field-error').css('border-color', '');
        isFsbhoaSaving = true; // SET LOCK

    
        // Visual feedback: show "Saving..."
        $status.html('<span class="dashicons dashicons-update spin" style="font-size: 16px; vertical-align: middle;"></span> Saving changes...');
        $('#fsbhoa-visualizer-container').css('opacity', '0.5');
        const $form = $('.form-actions-bar').closest('form');
console.log("Form Data: ", $form.serialize());
    
        $.ajax({
            url: fsbhoa_schedules_vars.ajax_url,
            type: 'POST',
            data: $form.serialize() + '&action=fsbhoa_ajax_save_and_refresh',
            success: function(response) {
                if (response.success) {
                    $('#fsbhoa-visualizer-container').replaceWith(response.data.html);
                    $status.html('<span class="dashicons dashicons-saved"></span> All changes saved');

                    // If this was a NEW group, the server should return the new ID
                    if (response.data.new_group_id) {
                        // Update the hidden input so future saves use the real ID
                        $('input[name="group_id"]').val(response.data.new_group_id);
                        console.log("Success: ID updated to " + response.data.new_group_id);
                        
                        // Update the URL without reloading the page so "Exit" works
                        const newUrl = window.location.href.replace('group_id=0', 'group_id=' + response.data.new_group_id);
                        window.history.replaceState({path: newUrl}, '', newUrl);
                    }
                    $status.html('<span class="dashicons dashicons-saved"></span> Saved');
                }
            },
            complete: function() {
                isFsbhoaSaving = false; // RELEASE LOCK
            }
        });
    }

    // 1. Listen for the Save Button click
    $('body').on('click', '.form-actions-bar #submit', function(e) {
        e.preventDefault();
        saveAndRefreshVisualizer();
    });

    // 2. Listen for ANY change in the permissions table (times, days, gates)
    // Ensure the cursor is forced back to name field if they try to skip the name
    $(document).on('blur', '#group_name', function() {
        if (window.fsbhoa_is_exiting) return;

        const val = $(this).val().trim();
        if (val === "") {
            $(this).focus();
            $('#sync-status-indicator').html('<span class="dashicons dashicons-warning" style="color: #d63638;"></span> Group Name Required');
        } else {
            saveAndRefreshVisualizer();
        }
    });


    // 3. Special case: If a row is removed or toggled, refresh too
    $('body').on('click', '.remove-permission-rule, .toggle-permission-status, #add-permission-rule', function() {
        window.fsbhoa_is_saving_from_click = true;
        // Wait slightly for the row to fade out before syncing
        setTimeout(saveAndRefreshVisualizer, 400);
    });


    // --- "All Access" UI Toggling (Ported from groups-admin.js) ---
    function togglePermissionsVisibility() {
        const allAccessCheckbox = $('#has_all_access');
        const permissionsWrapper = $('#permissions-details-wrapper');
        const visualizerWrapper = $('#fsbhoa-visualizer-wrapper');
        const addRuleButton = $('#add-permission-rule');
        const isAllAccess = allAccessCheckbox.is(':checked');

        if (isAllAccess) {
            permissionsWrapper.hide();
            visualizerWrapper.hide(); // Hide the timeline
        } else {
            permissionsWrapper.show();
            visualizerWrapper.show(); // Show the timeline
        }
        addRuleButton.prop('disabled', isAllAccess);
    }

    // Initialize state
    togglePermissionsVisibility();

    // Listen for changes and sync to DB
    $(document).on('change', '#has_all_access', function() {
        togglePermissionsVisibility();
        saveAndRefreshVisualizer(); // Ensure the "All Access" setting is saved instantly
    });

    // Handle the compact day-picker clicks
$('body').on('change', '.day-label input', function() {
    const $label = $(this).closest('.day-label');
    if ($(this).is(':checked')) {
        $label.addClass('active');
    } else {
        $label.removeClass('active');
    }
    saveAndRefreshVisualizer(); // Trigger sync
});

});
