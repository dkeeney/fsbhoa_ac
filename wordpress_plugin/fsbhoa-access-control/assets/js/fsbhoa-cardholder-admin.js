jQuery(document).ready(function($) {
    // --- Property Autocomplete Variables and Code (Keep As Is) ---
    var propertySearchInput = $('#fsbhoa_property_search_input');
    var propertyIdHiddenInput = $('#fsbhoa_property_id_hidden');
    var selectedPropertyDisplay = $('#fsbhoa_selected_property_display'); 
    var clearSelectionButton = $('#fsbhoa_property_clear_selection');
    var noResultsDisplay = $('#fsbhoa_property_search_no_results'); 

    propertySearchInput.autocomplete({
        source: function(request, response) { /* ... your existing working source ... */ 
            noResultsDisplay.empty().hide();
            $.ajax({
                url: fsbhoa_cardholder_ajax_obj.ajax_url,
                dataType: "json",
                data: { action: fsbhoa_cardholder_ajax_obj.property_search_action, term: request.term, security: fsbhoa_cardholder_ajax_obj.property_search_nonce },
                success: function(data) { if (data.success) { response(data.data); } else { response([]); } },
                error: function() { response([]); }
            });
        },
        minLength: 1, 
        select: function(event, ui) { /* ... your existing working select ... */ 
            event.preventDefault(); 
            if (ui.item && ui.item.id && ui.item.label) {
                propertySearchInput.val(ui.item.label);
                propertyIdHiddenInput.val(ui.item.id).trigger('change');
                selectedPropertyDisplay.text('Selected: ' + ui.item.label + ' (ID: ' + ui.item.id + ')').show();
                clearSelectionButton.show();
                noResultsDisplay.empty().hide();
            }
            return false; 
        },
        response: function(event, ui) { /* ... your existing working response ... */ 
            if (ui.content && ui.content.length === 0) { noResultsDisplay.text('No properties found.').show(); } 
            else { noResultsDisplay.empty().hide(); }
        },
        change: function(event, ui) { /* ... your existing working change ... */
            if (!ui.item) { 
                if ($(this).val() === '') {
                    propertyIdHiddenInput.val('').trigger('change');
                    selectedPropertyDisplay.empty().hide();
                    clearSelectionButton.hide();
                    noResultsDisplay.empty().hide();
                }
            }
        }
    });
    clearSelectionButton.on('click', function(e) { /* ... your existing working clear ... */ 
        e.preventDefault(); propertySearchInput.val(''); propertyIdHiddenInput.val('').trigger('change');
        selectedPropertyDisplay.empty().hide(); $(this).hide(); noResultsDisplay.empty().hide();
        propertySearchInput.focus();
    });
    if (propertyIdHiddenInput.val() === '' || propertyIdHiddenInput.val() === '0') { clearSelectionButton.hide(); selectedPropertyDisplay.empty().hide(); }
    else { if (propertySearchInput.val() !== '') { selectedPropertyDisplay.text('Selected: ' + propertySearchInput.val() + ' (ID: ' + propertyIdHiddenInput.val() + ')').show(); clearSelectionButton.show(); } }


    // --- Webcam Photo Capture Logic ---
    var fileUploadSection = $('#fsbhoa_file_upload_section');
    var fileUploadInput = $('#cardholder_photo_file_input'); 

    var startWebcamButton = $('#fsbhoa_start_webcam_button');
    var webcamActiveControls = $('#fsbhoa_webcam_active_controls');
    var stopWebcamButton = $('#fsbhoa_stop_webcam_button');
    var capturePhotoButton = $('#fsbhoa_capture_photo_button');
    
    var videoElement = document.getElementById('fsbhoa_webcam_video');
    var canvasElement = document.getElementById('fsbhoa_webcam_canvas');
    
    var mainPhotoPreviewImg = $('#fsbhoa_photo_preview_main_img');
    var noPhotoMessage = $('#fsbhoa_no_photo_message');
    var webcamPhotoDataInput = $('#fsbhoa_webcam_photo_data');
    var webcamStatus = $('#fsbhoa_webcam_status');
    
    var removeCurrentPhotoCheckbox = $('#fsbhoa_remove_current_photo_checkbox');

    let stream = null;
    // Store the original src of the main preview if it's a DB photo, to revert if webcam is stopped without capture
    let initialMainPhotoSrc = (mainPhotoPreviewImg.attr('src') && mainPhotoPreviewImg.attr('src') !== '#') ? mainPhotoPreviewImg.attr('src') : null;
    let initialNoPhotoDisplay = noPhotoMessage.css('display');


    function updateMainPhotoPreview(imageDataUrl) {
        if (imageDataUrl) {
            mainPhotoPreviewImg.attr('src', imageDataUrl).show();
            noPhotoMessage.hide();
            // If a new photo is previewed (either upload or webcam), uncheck "remove"
            if (removeCurrentPhotoCheckbox.length) {
                removeCurrentPhotoCheckbox.prop('checked', false).closest('label').show();
            }
        } else { // Clearing the preview
            mainPhotoPreviewImg.attr('src', '#').hide();
            noPhotoMessage.css('display', initialNoPhotoDisplay); // Show "no photo" if it was originally there
             if (removeCurrentPhotoCheckbox.length && initialMainPhotoSrc) { // Only show remove if there was an original DB photo
                removeCurrentPhotoCheckbox.closest('label').show();
            } else if (removeCurrentPhotoCheckbox.length) {
                removeCurrentPhotoCheckbox.closest('label').hide();
            }
        }
    }

    startWebcamButton.on('click', function() {
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(mediaStream) {
                    stream = mediaStream;
                    videoElement.srcObject = stream;
                    $(videoElement).show(); 
                    
                    fileUploadSection.hide(); // Hide file upload option
                    webcamPhotoDataInput.val(''); // Clear any previous webcam data
                    // DO NOT hide mainPhotoPreviewImg here - keep current photo visible

                    webcamStatus.text('Webcam active. Position and click "Capture Photo".');
                    startWebcamButton.hide();
                    webcamActiveControls.show(); 
                })
                .catch(function(err) {
                    console.error("Error accessing webcam: ", err);
                    webcamStatus.text('Error accessing webcam: ' + err.name);
                    fileUploadSection.show(); // Show file upload again if webcam fails
                    startWebcamButton.show();
                    webcamActiveControls.hide();
                });
        } else {
            webcamStatus.text('getUserMedia not supported by this browser.');
        }
    });

    stopWebcamButton.on('click', function() {
        if (stream) {
            stream.getTracks().forEach(function(track) { track.stop(); });
            stream = null;
        }
        $(videoElement).hide();
        webcamStatus.text('Webcam stopped. Choose an option.');
        
        fileUploadSection.show(); 
        startWebcamButton.show();
        webcamActiveControls.hide();

        // If no new webcam photo was captured and stored in webcamPhotoDataInput,
        // ensure the main preview reverts to the initial database photo (if any).
        if (webcamPhotoDataInput.val() === '') {
            if (initialMainPhotoSrc) {
                updateMainPhotoPreview(initialMainPhotoSrc);
            } else {
                updateMainPhotoPreview(null); // Show "no photo" message
            }
        }
        // If webcamPhotoDataInput has a value, it means a snapshot was taken, and
        // mainPhotoPreviewImg was already updated by capturePhotoButton.
    });

    capturePhotoButton.on('click', function() {
        if (stream && videoElement.readyState === videoElement.HAVE_ENOUGH_DATA) {
            var videoWidth = videoElement.videoWidth;
            var videoHeight = videoElement.videoHeight;
            canvasElement.width = videoWidth;
            canvasElement.height = videoHeight;
            var context = canvasElement.getContext('2d');
            context.drawImage(videoElement, 0, 0, videoWidth, videoHeight);
            var imageDataUrl = canvasElement.toDataURL('image/jpeg', 0.9);
            
            updateMainPhotoPreview(imageDataUrl); // Display snapshot in the main preview area
            
            var base64ImageData = imageDataUrl.replace(/^data:image\/(png|jpeg|gif);base64,/, "");
            webcamPhotoDataInput.val(base64ImageData);
            fileUploadInput.val(''); // Clear file input as webcam photo now takes precedence

            webcamStatus.text('Photo captured! This will be used if you save.');
        } else {
            webcamStatus.text('Webcam not ready. Please start webcam.');
        }
    });

    fileUploadInput.on('change', function(event) {
        if (this.files && this.files[0]) {
            webcamPhotoDataInput.val(''); // Clear any webcam data
            webcamStatus.text('File selected. This will be used if you save.');
            
            var reader = new FileReader();
            reader.onload = function(e) {
                updateMainPhotoPreview(e.target.result); // Show file preview in main area
            }
            reader.readAsDataURL(this.files[0]);
        }
    });
    
    // Initial setup for main photo preview area
    if (!mainPhotoPreviewImg.attr('src') || mainPhotoPreviewImg.attr('src') === '#') {
        updateMainPhotoPreview(null); // Show "no photo message"
    } else {
        // If PHP pre-filled the src, ensure no-photo message is hidden
        noPhotoMessage.hide();
        if (removeCurrentPhotoCheckbox.length) { // if checkbox exists
             removeCurrentPhotoCheckbox.closest('label').show();
        }
    }
    // Hide remove checkbox if no photo is actually displayed
    if (!mainPhotoPreviewImg.is(':visible') && removeCurrentPhotoCheckbox.length) {
        removeCurrentPhotoCheckbox.closest('label').hide();
    }
});
