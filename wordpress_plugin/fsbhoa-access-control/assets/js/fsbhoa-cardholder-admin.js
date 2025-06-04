jQuery(document).ready(function($) {
    // --- Property Autocomplete Variables and Code ---
    var propertySearchInput = $('#fsbhoa_property_search_input');
    var propertyIdHiddenInput = $('#fsbhoa_property_id_hidden');
    var selectedPropertyDisplay = $('#fsbhoa_selected_property_display'); 
    var clearSelectionButton = $('#fsbhoa_property_clear_selection');
    var noResultsDisplay = $('#fsbhoa_property_search_no_results'); 

    if (propertySearchInput.length) { // Ensure the element exists
        propertySearchInput.autocomplete({
            source: function(request, response) { 
                noResultsDisplay.empty().hide(); 
                $.ajax({
                    url: fsbhoa_cardholder_ajax_obj.ajax_url, // from wp_localize_script
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
                        noResultsDisplay.text('Error during property search.').show();
                    }
                });
            },
            minLength: 1, 
            select: function(event, ui) {  
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
            response: function(event, ui) {  
                if (ui.content && ui.content.length === 0 && propertySearchInput.val().length >= 1) { 
                    noResultsDisplay.text('No properties found matching your search.').show();
                    // Clear hidden ID if no valid selection is made from an empty list
                    if(propertySearchInput.val() !== selectedPropertyDisplay.text().split(' (ID:')[0].replace('Selected: ', '')) {
                       // propertyIdHiddenInput.val('').trigger('change'); // Be careful not to clear valid existing if user just typed more
                    }
                } else { 
                    noResultsDisplay.empty().hide(); 
                }
            },
            change: function(event, ui) { 
                if (!ui.item) { 
                    if ($(this).val() === '') { // If text input is cleared
                        propertyIdHiddenInput.val('').trigger('change');
                        selectedPropertyDisplay.empty().hide();
                        clearSelectionButton.hide();
                        noResultsDisplay.empty().hide();
                    }
                    // If text remains but doesn't match a selection, don't clear ID, user might be typing
                    // Or, add logic to clear ID if text doesn't match any valid selected property label
                }
            }
        });

        clearSelectionButton.on('click', function(e) {  
            e.preventDefault(); 
            propertySearchInput.val(''); 
            propertyIdHiddenInput.val('').trigger('change');
            selectedPropertyDisplay.empty().hide(); 
            $(this).hide(); 
            noResultsDisplay.empty().hide();
            propertySearchInput.focus();
        });

        // Initial state for property clear button and selected display
        if (propertyIdHiddenInput.val() === '' || propertyIdHiddenInput.val() === '0' || propertyIdHiddenInput.val() === null) { 
            clearSelectionButton.hide(); 
            selectedPropertyDisplay.empty().hide();
        } else { 
            if (propertySearchInput.val() !== '') { 
                 selectedPropertyDisplay.text('Selected: ' + propertySearchInput.val() + ' (ID: ' + propertyIdHiddenInput.val() + ')').show();
                 clearSelectionButton.show();
            } else { // Only ID is there (e.g. sticky form without display text yet)
                clearSelectionButton.show(); // At least allow clearing
            }
        }
    }


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
    let initialMainPhotoSrc = (mainPhotoPreviewImg.length && mainPhotoPreviewImg.attr('src') && mainPhotoPreviewImg.attr('src') !== '#') ? mainPhotoPreviewImg.attr('src') : null;
    let initialNoPhotoDisplay = noPhotoMessage.length ? noPhotoMessage.css('display') : 'none';

    function updateMainPhotoPreview(imageDataUrl) {
        if (imageDataUrl && imageDataUrl !== '#') {
            mainPhotoPreviewImg.attr('src', imageDataUrl).show();
            noPhotoMessage.hide();
            if (removeCurrentPhotoCheckbox.length) {
                removeCurrentPhotoCheckbox.prop('checked', false).closest('label').show();
            }
        } else { 
            mainPhotoPreviewImg.attr('src', '#').hide();
            noPhotoMessage.css('display', initialNoPhotoDisplay);
            if (removeCurrentPhotoCheckbox.length && initialMainPhotoSrc) {
                removeCurrentPhotoCheckbox.closest('label').show();
            } else if (removeCurrentPhotoCheckbox.length) {
                removeCurrentPhotoCheckbox.closest('label').hide();
            }
        }
    }

    if (startWebcamButton.length) {
        startWebcamButton.on('click', function() {
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                navigator.mediaDevices.getUserMedia({ video: true })
                    .then(function(mediaStream) {
                        stream = mediaStream;
                        videoElement.srcObject = stream;
                        $(videoElement).show(); 
                        fileUploadSection.hide(); 
                        webcamPhotoDataInput.val(''); 
                        webcamStatus.text('Webcam active. Position and click "Capture Photo".').css('color', 'inherit');
                        startWebcamButton.hide();
                        webcamActiveControls.show(); 
                        // Current photo in main preview remains visible until capture
                    })
                    .catch(function(err) { /* ... error handling ... */ });
            } else { /* ... unsupported message ... */ }
        });

        stopWebcamButton.on('click', function() {
            if (stream) { stream.getTracks().forEach(function(track) { track.stop(); }); stream = null; }
            $(videoElement).hide();
            webcamStatus.text('Webcam stopped. Choose an option.');
            fileUploadSection.show(); 
            startWebcamButton.show();
            webcamActiveControls.hide();
            if (webcamPhotoDataInput.val() === '') { // If no snapshot was finalized
                updateMainPhotoPreview(initialMainPhotoSrc); // Revert to original DB photo or "no photo"
            }
        });

        capturePhotoButton.on('click', function() {
            if (stream && videoElement.readyState === videoElement.HAVE_ENOUGH_DATA) {
                var videoWidth = videoElement.videoWidth; var videoHeight = videoElement.videoHeight;
                canvasElement.width = videoWidth; canvasElement.height = videoHeight;
                var context = canvasElement.getContext('2d');
                context.drawImage(videoElement, 0, 0, videoWidth, videoHeight);
                var imageDataUrl = canvasElement.toDataURL('image/jpeg', 0.9);
                updateMainPhotoPreview(imageDataUrl); 
                var base64ImageData = imageDataUrl.replace(/^data:image\/(png|jpeg|gif);base64,/, "");
                webcamPhotoDataInput.val(base64ImageData);
                fileUploadInput.val(''); 
                webcamStatus.text('Photo captured! This will be used if you save.');
            } else { /* ... webcam not ready message ... */ }
        });
    }
    
    if (fileUploadInput.length) {
        fileUploadInput.on('change', function(event) {
            if (this.files && this.files[0]) {
                webcamPhotoDataInput.val(''); 
                webcamStatus.text('File selected. This will be used if you save.');
                var reader = new FileReader();
                reader.onload = function(e) { updateMainPhotoPreview(e.target.result); }
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    
    // Initial setup for main photo preview area
    if (mainPhotoPreviewImg.length) {
        if (!mainPhotoPreviewImg.attr('src') || mainPhotoPreviewImg.attr('src') === '#') {
            updateMainPhotoPreview(null); 
        } else {
            noPhotoMessage.hide();
            if (removeCurrentPhotoCheckbox.length) { removeCurrentPhotoCheckbox.closest('label').show(); }
        }
        if (!mainPhotoPreviewImg.is(':visible') && removeCurrentPhotoCheckbox.length) {
            removeCurrentPhotoCheckbox.closest('label').hide();
        }
    }

// --- Card Status Toggle UI Logic (No AJAX on click, updates hidden fields) ---
    var statusUiToggleCheckbox = $('#fsbhoa_card_status_ui_toggle'); 

    if (statusUiToggleCheckbox.length) { 

        var submittedStatusHidden = $('#fsbhoa_submitted_card_status');
        var submittedIssueDateHidden = $('#fsbhoa_submitted_card_issue_date');
        var statusDisplaySpan = $('#fsbhoa_card_status_display');
        var issueDateDisplaySpan = $('#fsbhoa_card_issue_date_display');
        var issueDateWrapper = $('#fsbhoa_issue_date_wrapper'); 
        var toggleLabelSpan = $('#fsbhoa_card_status_toggle_ui_label');
        // var residentTypeDropdown = $('#resident_type'); // Not directly needed by this JS logic now
        // var contractorExpiryDateInput = $('#card_expiry_date_contractor_input'); // Not changed by this JS toggle logic

        function getTodayDateYYYYMMDD_forStatusToggle() {
            var today = new Date();
            var dd = String(today.getDate()).padStart(2, '0');
            var mm = String(today.getMonth() + 1).padStart(2, '0'); //January is 0!
            var yyyy = today.getFullYear();
            return yyyy + '-' + mm + '-' + dd;
        }

        function updateStatusDisplayFromCheckbox() {

            var isCheckedForActive = statusUiToggleCheckbox.is(':checked'); 

            if (isCheckedForActive) { // User wants card to be 'active'
                if (submittedStatusHidden.length) submittedStatusHidden.val('active');
                if (statusDisplaySpan.length) statusDisplaySpan.text('Active');
                if (toggleLabelSpan.length) toggleLabelSpan.text('Card is Active (Click to Disable)');

                var today = getTodayDateYYYYMMDD_forStatusToggle();
                if (submittedIssueDateHidden.length) submittedIssueDateHidden.val(today);
                if (issueDateDisplaySpan.length) issueDateDisplaySpan.text(today);
                if (issueDateWrapper.length) issueDateWrapper.show(); 
                
            } else { // User wants card to be 'disabled'
                if (submittedStatusHidden.length) submittedStatusHidden.val('disabled');
                if (statusDisplaySpan.length) statusDisplaySpan.text('Disabled');
                if (toggleLabelSpan.length) toggleLabelSpan.text('Card is Inactive (Click to Activate)');
                // When disabling, JS does not change the submitted_issue_date hidden field.
                // The PHP handler will use the existing issue date from the DB if status becomes disabled.
                // Issue date display might hide or show existing if desired, but for simplicity,
                // if status is 'disabled', issue date might not be as relevant to display prominently.
                // Let's keep it showing the value JS last set it to (today, if it was just activated then disabled)
                // or what PHP loaded it with. The PHP save logic is the source of truth.
            }
        }

        // Attach event listener
        statusUiToggleCheckbox.on('change', function() {
            updateStatusDisplayFromCheckbox();
        });

        // Optional: Call once on load to synchronize UI text with initial checkbox state,
        // IF PHP isn't already perfectly setting the initial text for statusDisplaySpan and toggleLabelSpan.
        // PHP *should* be doing this based on $form_data['card_status'].
        // If the initial display text IS correct, you don't need this initial call.
        // updateStatusDisplayFromCheckbox(); 

    } else {
        console.log('FSBHOA JS: UI Status Toggle Checkbox NOT FOUND on this page.');
    }
});
