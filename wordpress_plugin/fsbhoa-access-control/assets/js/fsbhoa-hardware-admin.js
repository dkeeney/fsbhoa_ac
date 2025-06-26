
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

});

