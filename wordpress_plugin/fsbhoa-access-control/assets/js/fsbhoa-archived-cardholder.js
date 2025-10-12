jQuery(function($) {

    /**
     * Initializes the DataTables functionality for the archived cardholders list.
     */
    function initArchivedCardholderTable() {
        var archivedTableElement = $('#fsbhoa-archived-cardholder-table');

        if (archivedTableElement.length) {
            var archivedTable = archivedTableElement.DataTable({
                "dom": 'tip',
                "paging": true,
                "info": true,
                "order": [[4, "desc"]], // Default sort by the 'Date Archived' column
                "columnDefs": [
                    { "orderable": false, "targets": 0 } // Disable sorting on the actions column
                ]
            });
            // Link custom controls to the new table instance
            $('#fsbhoa-archived-cardholder-search-input').on('keyup', function() { archivedTable.search($(this).val()).draw(); });
            $('#fsbhoa-archived-custom-length-menu').on('change', function() { archivedTable.page.len($(this).val()).draw(); });
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
                        const detailsHtml = `<p><strong>Name:</strong> ${ui.item.first_name} ${ui.item.last_name}</p><p><strong>Address:</strong> ${ui.item.street_address || 'N/A'}</p><p>This is the record that will be updated.</p>`;
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

    /**
     * Initializes the inline notes editor functionality.
     */
    function initNotesEditor() {
        const dialog = $('#fsbhoa-notes-editor-dialog');
        const table = $('#fsbhoa-archived-cardholder-table');

        // Open the dialog when an edit button is clicked
        table.on('click', '.edit-notes-button', function() {
            const button = $(this);
            const cell = button.closest('td');
            const row = button.closest('tr');
            const cardholderId = cell.data('cardholder-id');
            const currentNotes = cell.find('.notes-text').text();
            const cardholderName = row.find('td:nth-child(2)').text();

            // Populate the dialog
            dialog.find('#notes-editor-cardholder-name').text(cardholderName);
            dialog.find('#notes-editor-textarea').val(currentNotes);
            dialog.find('#notes-editor-cardholder-id').val(cardholderId);
            
            dialog.dialog('open');
        });

        // Initialize the jQuery UI Dialog
        dialog.dialog({
            autoOpen: false,
            modal: true,
            width: 500,
            buttons: {
                "Save Notes": function() {
                    const newNotes = $('#notes-editor-textarea').val();
                    const cardholderId = $('#notes-editor-cardholder-id').val();
                    const currentDialog = $(this);

                    $.ajax({
                        url: fsbhoa_ajax_settings.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fsbhoa_update_archived_notes',
                            nonce: fsbhoa_ajax_settings.notes_nonce,
                            cardholder_id: cardholderId,
                            notes: newNotes
                        },
                        success: function(response) {
                            if (response.success) {
                                // Update the text in the table cell
                                const cell = table.find(`td[data-cardholder-id="${cardholderId}"]`);
                                cell.find('.notes-text').text(newNotes);
                                currentDialog.dialog('close');
                            } else {
                                alert('Error: ' + response.data);
                            }
                        },
                        error: function() {
                            alert('An unknown AJAX error occurred.');
                        }
                    });
                },
                "Cancel": function() {
                    $(this).dialog('close');
                }
            }
        });
    }


    // Run the initializers
    initArchivedCardholderTable();
    initMergeTool();
    initNotesEditor();
});

