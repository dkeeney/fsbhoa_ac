jQuery(document).ready(function($) {
    'use strict';


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

    // Handler for the "Copy From" button
    $('body').on('click', '#fsbhoa-copy-rules-button', function(e) {
        if (!confirm('This will replace all permissions and tasks for the current schedule. Are you sure?')) {
            return;
        }
        alert('Delete X clicked!');

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
});
