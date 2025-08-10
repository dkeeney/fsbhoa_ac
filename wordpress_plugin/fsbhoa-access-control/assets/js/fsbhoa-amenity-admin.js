jQuery(function($) {
        'use strict';
    
        // Keep track of which row is being edited
        let activeEditRow = null;
    
        // Function to switch a row to display mode
        function setDisplayMode(row) {
            row.find('.amenity-name-display, .amenity-actions-display').show();
            row.find('.amenity-name-input, .amenity-actions-edit').hide();
            row.removeClass('is-editing');
            activeEditRow = null;
        }
    
        // Function to switch a row to edit mode
        function setEditMode(row) {
            if (activeEditRow) {
                setDisplayMode(activeEditRow);
            }
            row.find('.amenity-name-display, .amenity-actions-display').hide();
            row.find('.amenity-name-input, .amenity-actions-edit').show();
            row.addClass('is-editing');
            activeEditRow = row;
        }
    
        // EDIT button clicked
        $('#amenity-list-body').on('click', '.edit-amenity-btn', function(e) {
            e.preventDefault();
            setEditMode($(this).closest('tr'));
        });
    
        // CANCEL button clicked
        $('#amenity-list-body').on('click', '.cancel-edit-btn', function() {
            const row = $(this).closest('tr');
            // Restore original name from the display span
            const originalName = row.find('.amenity-name-display').text();
            row.find('.amenity-name-input').val(originalName);
            // Restore original image from the hidden input
            const originalImageUrl = row.find('.amenity-image-url-input').val();
            const imageHtml = originalImageUrl ? '<img class="amenity-list-image" src="' + originalImageUrl + '">' : '';
            row.find('.amenity-image-display').html(imageHtml);
            setDisplayMode(row);
        });
    
        // SAVE button clicked
        $('#amenity-list-body').on('click', '.save-amenity-btn', function() {
            const row = $(this).closest('tr');
            const amenityId = row.data('amenity-id');
            const newName = row.find('.amenity-name-input').val();
            const newImageUrl = row.find('.amenity-image-url-input').val();
            const nonce = $('#add-amenity-form input[name="_wpnonce"]').val();
    
            $.ajax({
                url: fsbhoa_amenity_data.ajax_url,
                type: 'POST',
                data: {
                    action: 'fsbhoa_edit_amenity',
                    nonce: fsbhoa_amenity_data.nonce,
                    amenity_id: amenityId,
                    name: newName,
                    image_url: newImageUrl
                },
                success: function(response) {
                    if (response.success) {
                        const amenity = response.data.amenity;
                        row.find('.amenity-name-display').text(amenity.name);
                        row.find('.amenity-image-url-input').val(amenity.image_url); // Also update the hidden input
                        const imageHtml = amenity.image_url ? '<img class="amenity-list-image" src="' + amenity.image_url + '">' : '';
                        row.find('.amenity-image-display').html(imageHtml);
                        setDisplayMode(row);
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                },
                error: function() {
                    alert('An unexpected error occurred. Please try again.');
                }
            });
        });
    
        // IMAGE clicked while in edit mode
        $('#amenity-list-body').on('click', '.amenity-image-cell', function() {
            const row = $(this).closest('tr');
            if (!row.hasClass('is-editing')) {
                return; // Only allow image changes in edit mode
            }
    
            const frame = wp.media({
                title: 'Select an Image for the Amenity',
                button: { text: 'Use this image' },
                multiple: false
            });
    
            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                row.find('.amenity-image-url-input').val(attachment.url);
                const imageHtml = '<img class="amenity-list-image" src="' + attachment.url + '">';
                row.find('.amenity-image-display').html(imageHtml);
            });
    
            frame.open();
        });
});
