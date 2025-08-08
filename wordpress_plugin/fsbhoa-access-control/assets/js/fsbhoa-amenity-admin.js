jQuery(function($) {
    'use strict';

    // This runs when the "Select Image" button is clicked
    $('body').on('click', '.fsbhoa-select-image-btn', function(e) {
        e.preventDefault();

        const button = $(this);
        const imagePreview = button.siblings('.fsbhoa-image-preview');
        const imageUrlInput = button.siblings('.fsbhoa-image-url-input');

        // Create a new media frame
        const frame = wp.media({
            title: 'Select an Image for the Amenity',
            button: {
                text: 'Use this image'
            },
            multiple: false
        });

        // When an image is selected, run this callback
        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            
            // Set the hidden input's value to the image URL
            imageUrlInput.val(attachment.url);
            
            // Update the image preview
            imagePreview.html('<img src="' + attachment.url + '" alt="Amenity Preview">');
        });

        // Finally, open the modal
        frame.open();
    });

    // This runs when the "Remove Image" button is clicked
    $('body').on('click', '.fsbhoa-remove-image-btn', function(e) {
        e.preventDefault();

        const button = $(this);
        const imagePreview = button.siblings('.fsbhoa-image-preview');
        const imageUrlInput = button.siblings('.fsbhoa-image-url-input');

        // Clear the input and the preview
        imageUrlInput.val('');
        imagePreview.html('No image selected.');
    });
});
