jQuery(function($) {
    const propertyTable = $('#fsbhoa-property-table');

    // Only run if the property table exists on the page
    // We add a check to see if the row with the "no items" message exists.
    // The 'no-items' class is added by WP_List_Table to that specific row.
    if (propertyTable.length && propertyTable.find('tr.no-items').length === 0) {

        // Initialize the DataTable and store its instance
        const dataTableInstance = propertyTable.DataTable({
            "dom": 'tip', 
            "pageLength": 100,
            "stateSave": true,    // to save the "rows to display" for paging

            // ---  Only save the page length ---
            "stateSaveParams": function (settings, data) {
                // Only save the 'length' property
                return {
                    length: data.length
                };
            },
            "stateLoadParams": function (settings, data) {
                // Only load the 'length' property
                // This prevents the search term from being sticky
                if (data.search) {
                    delete data.search;
                }
            }
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
