
/**
 * Handles JavaScript functionality for the Hardware Management pages,
 * specifically initializing the DataTables for the controller and gate lists.
 */
jQuery(document).ready(function($) {

    // Initialize the DataTables library on the controller list table.
    var controllerTable = $('#fsbhoa-controller-table');
    if (controllerTable.length && controllerTable.find('tbody tr td').length > 1) {
        controllerTable.DataTable({
            'paging': false, // As originally designed, no pagination
            'searching': false, // No search box for this simple list
            'info': false,
            'autoWidth': true,
            'order': [
                [1, 'asc']
            ], // Default sort by the 2nd column (Friendly Name)
            'columnDefs': [{
                'orderable': false,
                'targets': 'no-sort'
            }]
        });
    }



    // Handler for the new Factory Reset button
    // Note: We use a delegated event handler attached to the body in case the form is loaded via AJAX.
    $('body').on('click', '#fsbhoa-factory-reset-button', function() {
        if (!confirm('WARNING: This will completely wipe all cards and settings from this controller. This cannot be undone. Are you absolutely sure you want to proceed?')) {
            return;
        }

        const button = $(this);
        const controllerId = button.data('controller-id');
        const originalText = button.text();

        button.text('Resetting...').prop('disabled', true);

        $.ajax({
            url: fsbhoa_hardware_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'fsbhoa_factory_reset',
                nonce: fsbhoa_hardware_vars.reset_nonce,
                controller_id: controllerId
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data);
                    window.location.reload(); // Reload the page to show the sync banner
                } else {
                    alert('Error: ' + response.data);
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                alert('An unknown error occurred while communicating with the server.');
                button.text(originalText).prop('disabled', false);
            }
        });
    });
});

