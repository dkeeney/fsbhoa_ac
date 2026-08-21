jQuery(function($) {
    const propertyTable = $('#fsbhoa-property-table');

    // Only run if the property table element exists on the page.
    if (propertyTable.length && propertyTable.find('tbody tr.no-items').length === 0) {

        // Initialize the DataTable and store its instance.
        const dataTableInstance = propertyTable.DataTable({
            "dom": 'tip',
            "pageLength": 100,
            "stateSave": true,
            "columnDefs": [
                { "targets": 0, "orderable": false, "searchable": false },
                { "targets": 1 },
                { "targets": 2, "orderable": false }
            ],
            // ... (keep your stateSaveParams and stateLoadParams if you wish)
        });

        // When the user types in our custom search box...
        $('#fsbhoa-property-search-input').on('keyup', function() {
            // ...apply that value to the DataTable's search and redraw the table.
            dataTableInstance.search(this.value).draw();
        });

        // When the user changes our custom "Show entries" dropdown
        $('#fsbhoa-property-length-menu').on('change', function() {
            dataTableInstance.page.len(this.value).draw();
        });
    }
});
