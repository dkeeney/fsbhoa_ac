jQuery(document).ready(function($) {
    var propertySearchInput = $('#fsbhoa_property_search_input');
    var propertyIdHiddenInput = $('#fsbhoa_property_id_hidden');
    var selectedPropertyDisplay = $('#fsbhoa_selected_property_display'); 
    var clearSelectionButton = $('#fsbhoa_property_clear_selection');
    
    // Create a dedicated element for "no results" message if it doesn't exist,
    // or you can reuse selectedPropertyDisplay. Let's create one for clarity.
    // Ensure this div is added in your PHP form HTML, near the property input.
    // Example in PHP (render_add_new_cardholder_form, near property input's description):
    // <div id="fsbhoa_property_search_no_results" style="color: #dc3232; margin-top: 5px;"></div>
    var noResultsDisplay = $('#fsbhoa_property_search_no_results'); 
    // If you prefer to use selectedPropertyDisplay for this message:
    // var noResultsDisplay = selectedPropertyDisplay;


    function selectProperty(property) {
        noResultsDisplay.empty().hide(); // Clear "no results" message on selection
        if (property && property.id && property.label) {
            propertySearchInput.val(property.label); 
            propertyIdHiddenInput.val(property.id).trigger('change'); 
            selectedPropertyDisplay.text('Selected: ' + property.label + ' (ID: ' + property.id + ')').show();
            clearSelectionButton.show();
        } else {
            propertyIdHiddenInput.val('').trigger('change');
            selectedPropertyDisplay.empty().hide();
            clearSelectionButton.hide();
        }
    }
    
    propertySearchInput.autocomplete({
        source: function(request, response) {
            noResultsDisplay.empty().hide(); // Clear "no results" message on new search
            selectedPropertyDisplay.empty().hide(); // Clear previous selection display
            // Do not clear propertyIdHiddenInput here, only on explicit clear or new selection
            
            $.ajax({
                url: fsbhoa_cardholder_ajax_obj.ajax_url,
                dataType: "json",
                data: {
                    action: fsbhoa_cardholder_ajax_obj.property_search_action,
                    term: request.term,
                    security: fsbhoa_cardholder_ajax_obj.property_search_nonce
                },
                success: function(data) {
                    if (data.success) {
                        response(data.data); 
                    } else {
                        response([]); 
                    }
                },
                error: function() {
                    response([]); 
                    noResultsDisplay.text('Error during search. Please try again.').show(); // Optional: Error message
                }
            });
        },
        minLength: 1, 
        select: function(event, ui) {
            event.preventDefault(); 
            selectProperty(ui.item); 
            return false; 
        },
        focus: function(event, ui) {
            event.preventDefault();
        },
        change: function(event, ui) {
            if (!ui.item) { 
                var currentText = $(this).val();
                // If the text field is cleared, or if the text doesn't match the selected property's label
                // (more complex to check perfectly without storing selected label), clear the selection.
                // Simple check: if currentText is empty and hidden ID was set, clear.
                if (currentText === '' && propertyIdHiddenInput.val() !== '') {
                     selectProperty(null); 
                }
                // If text exists but no item selected, it means it was a manual entry or failed search.
                // We don't clear the hidden ID here unless we are sure the text doesn't correspond to it.
                // User might be typing then tabbing out.
            }
            if ($(this).val() === '') { // If input is cleared manually by user
                 selectProperty(null);
            }
        },
        // NEW: response event handler
        response: function(event, ui) {
            if (ui.content && ui.content.length === 0) {
                // No items were returned by the source
                noResultsDisplay.text('No properties found matching your search.').show();
                // We already cleared selectedPropertyDisplay and hidden ID in selectProperty(null) if applicable
                // or if text was cleared. If text remains but no match, don't clear selection yet.
            } else {
                // Items were found, or it's an error (handled by source's error)
                noResultsDisplay.empty().hide();
            }
        }
    });

    clearSelectionButton.on('click', function(e) {
        e.preventDefault();
        propertySearchInput.val(''); 
        selectProperty(null);      
        noResultsDisplay.empty().hide(); // Also clear "no results" message
        propertySearchInput.focus();
    });

    // Initial state for clear button and selected display
    if (propertyIdHiddenInput.val() === '' || propertyIdHiddenInput.val() === '0') {
        clearSelectionButton.hide();
        selectedPropertyDisplay.empty().hide();
    } else {
        // If an ID is pre-filled (edit mode with existing selection), show clear button.
        // The actual text for propertySearchInput and selectedPropertyDisplay is pre-filled by PHP.
        clearSelectionButton.show();
        // If property_address_display was pre-filled by PHP for edit mode:
        if (propertySearchInput.val() !== '' && propertyIdHiddenInput.val() !== '' && propertyIdHiddenInput.val() !== '0') {
             selectedPropertyDisplay.text('Selected: ' + propertySearchInput.val() + ' (ID: ' + propertyIdHiddenInput.val() + ')').show();
        }
    }
});

