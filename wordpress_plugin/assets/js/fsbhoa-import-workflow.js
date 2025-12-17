jQuery(function($) {
    // --- Initialize the CSV info dialog ---
    const dialog = $("#csv-info-dialog").dialog({
        autoOpen: false,
        modal: true,
        width: 600, // Or whatever width you prefer
        buttons: {
            "Close": function() {
                $(this).dialog("close");
            }
        }
    });

    // --- Open the dialog when the link is clicked ---
    $('#open-csv-info-dialog').on('click', function(e) {
        e.preventDefault();
        dialog.dialog("open");
    });

});


