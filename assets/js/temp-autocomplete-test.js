jQuery(function($) {
    const propertySearchInput = $('#fsbhoa_property_search_input');
    const propertyIdHiddenInput = $('#fsbhoa_property_id_hidden');

    if (propertySearchInput.length) {
        propertySearchInput.autocomplete({
            source: (request, response) => {
                $.ajax({
                    url: fsbhoa_ajax_settings.ajax_url,
                    dataType: "json",
                    data: {
                        action: 'fsbhoa_search_properties',
                        term: request.term,
                        security: fsbhoa_ajax_settings.property_search_nonce
                    },
                    success: (data) => {
                        if (data.success) {
                            response(data.data.length ? data.data : [{ label: 'No results found', value: '' }]);
                        } else {
                            response([]);
                        }
                    }
                });
            },
            minLength: 1,
            select: (event, ui) => {
                event.preventDefault();
                if (ui.item && ui.item.id) {
                    propertySearchInput.val(ui.item.label);
                    propertyIdHiddenInput.val(ui.item.id);
                }
                return false;
            }
        });
    }
});
