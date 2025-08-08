jQuery(function($) {

    /**
     * Initializes the DataTables functionality for the deleted cardholders list.
     */
    function initDeletedCardholderTable() {
        var deletedTableElement = $('#fsbhoa-deleted-cardholder-table');
        
        if (deletedTableElement.length) {
            var deletedTable = deletedTableElement.DataTable({
                "dom": 'tip',
                "paging": true,
                "info": true,
                "order": [[4, "desc"]],
                "columnDefs": [
                    { "orderable": false, "targets": 0 }
                ]
            });
            $('#fsbhoa-deleted-cardholder-search-input').on('keyup', function() { deletedTable.search($(this).val()).draw(); });
            $('#fsbhoa-deleted-custom-length-menu').on('change', function() { deletedTable.page.len($(this).val()).draw(); });
        }
    }

    /**
     * Initializes the autocomplete search for the merge tool.
     */
    function initMergeTool() {
        var mergeToolContainer = $('.fsbhoa-merge-tool');
        if (mergeToolContainer.length) {
            var searchInput = $('#fsbhoa_destination_search');
            var detailsDiv = $('#fsbhoa_destination_details');
            var hiddenIdInput = $('#fsbhoa_destination_id_hidden');
            var confirmButton = $('#fsbhoa-confirm-merge-button');

            searchInput.autocomplete({
                source: (request, response) => {
                    $.ajax({
                        url: fsbhoa_ajax_settings.ajax_url,
                        dataType: "json",
                        data: {
                            action: 'fsbhoa_search_cardholders',
                            term: request.term,
                            security: fsbhoa_ajax_settings.cardholder_search_nonce
                        },
                        success: (data) => {
                            response(data.success ? data.data : []);
                        }
                    });
                },
                minLength: 2,
                select: (event, ui) => {
                    event.preventDefault();
                    if (ui.item && ui.item.id) {
                        const detailsHtml = `<p><strong>Name:</strong> ${ui.item.first_name} ${ui.item.last_name}</p><p>This is the record that will be updated.</p>`;
                        detailsDiv.html(detailsHtml).show();
                        hiddenIdInput.val(ui.item.id);
                        confirmButton.prop('disabled', false);
                        searchInput.val(ui.item.label);
                    }
                    return false;
                }
            });
        }
    }

    // Run the initializers
    initDeletedCardholderTable();
    initMergeTool();

});

