jQuery(document).ready(function($) {
    var propertySearchInput = $('#fsbhoa_property_search_input');
    var propertyIdHiddenInput = $('#fsbhoa_property_id_hidden');
    var selectedPropertyDisplay = $('#fsbhoa_selected_property_display'); // For displaying selected address
    var clearSelectionButton = $('#fsbhoa_property_clear_selection');

    // Function to update display and hidden field
    function selectProperty(property) {
        if (property && property.id && property.label) {
            propertySearchInput.val(property.label); // Display selected address in text box
            propertyIdHiddenInput.val(property.id).trigger('change'); // Set hidden ID field and trigger change
            selectedPropertyDisplay.text('Selected: ' + property.label + ' (ID: ' + property.id + ')');
            clearSelectionButton.show();
        } else {
            // Clear if no valid property is selected (e.g., user clears text input)
            propertyIdHiddenInput.val('').trigger('change');
            selectedPropertyDisplay.text('');
            clearSelectionButton.hide();
        }
    }
    
    // Initialize the text input with the address if property_id is already set (e.g. sticky form with error)
    // This part is a bit trickier as we don't have the address string readily available here without another AJAX call.
    // For a simpler sticky experience initially, if propertyIdHiddenInput has a value on page load,
    // we might just leave the text box blank and expect the user to re-search if they want to change it,
    // or we could make an AJAX call to get the address for that ID.
    // Let's keep it simple for now: if propertyIdHiddenInput has a value, the JS won't pre-fill the text input on load,
    // but the hidden ID will still be submitted. The user would re-search if they want to change it.

    propertySearchInput.autocomplete({
        source: function(request, response) {
            $.ajax({
                url: fsbhoa_cardholder_ajax_obj.ajax_url,
                dataType: "json",
                data: {
                    action: fsbhoa_cardholder_ajax_obj.property_search_action,
                    term: request.term,
                    security: fsbhoa_cardholder_ajax_obj.property_search_nonce // Nonce
                },
                success: function(data) {
                    if (data.success) {
                        response(data.data); // Pass the array of property objects to autocomplete
                    } else {
                        response([]); // No results or error
                    }
                },
                error: function() {
                    response([]); // Handle AJAX error
                }
            });
        },
        minLength: 1, // Start searching from 1 character
        select: function(event, ui) {
            event.preventDefault(); // Prevent default action (like value in input changing before we want)
            selectProperty(ui.item); // ui.item is the selected object from our AJAX response
            return false; // Prevent value from being inserted into input by autocomplete itself if we manually set it
        },
        focus: function(event, ui) {
            // Optional: prevent value inserted on focus
            event.preventDefault();
            // $(this).val(ui.item.label); // Could show label on focus
        },
        // If the user types and then clicks away or hits enter without selecting
        // we might want to clear the hidden ID if the text doesn't match a valid selection.
        change: function(event, ui) {
            if (!ui.item) { // If no item was selected from the suggestions
                // Check if the current text input value matches a known label (this is harder without keeping state)
                // For simplicity now, if they change the text and don't select, clear the ID.
                // A more robust solution would re-validate or ensure a selection is made.
                var currentText = $(this).val();
                if (propertyIdHiddenInput.val() !== '' && currentText === '') { // If they cleared the text box
                     selectProperty(null); // Clear selection
                } else if (propertyIdHiddenInput.val() !== '' && selectedPropertyDisplay.text().indexOf(currentText) === -1) {
                    // If text changed and doesn't match what was selected, clear previous ID.
                    // This logic might need refinement.
                    // selectProperty(null); // Potentially too aggressive
                }
            }
        }
    });

    // Clear selection button
    clearSelectionButton.on('click', function(e) {
        e.preventDefault();
        propertySearchInput.val(''); // Clear the visible input
        selectProperty(null);      // Clear hidden field and display
        propertySearchInput.focus();
    });

    // If there's already a property ID on page load (e.g. sticky form after error), show clear button
    if (propertyIdHiddenInput.val() !== '') {
        // We don't have the address here to display in propertySearchInput or selectedPropertyDisplay
        // without another AJAX call. For now, just show the clear button if an ID exists.
        // Ideally, the PHP would also pass the address for the current property_id if set.
        // For now, we can at least enable clearing it.
        // Let's try to pre-fill if ID exists, but this is a placeholder for a better solution
        if (propertyIdHiddenInput.val()) {
             // To make this fully work, we'd need the street_address for this ID.
             // The PHP side `render_add_new_cardholder_form` should pass this if property_id is set in $form_data.
             // For now, let's assume if $form_data['property_id'] is set, we might need to also set a $form_data['property_address_display']
             // For a quick fix here, if property_id_hidden has value, we show the clear button.
             // The visual consistency of the text input field would be handled by the PHP setting its value attribute if we had it.
            clearSelectionButton.show();
            // If you also stored the selected address in another hidden field or data attribute from PHP, you could populate it here.
        }
    }

});
