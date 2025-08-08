jQuery(function($) {

    const App = {
        isInitialized: false,
        vars: {},

init: function() {
            if (this.isInitialized) { return; }
            this.isInitialized = true;

            this.cacheSelectors();

            if (this.vars.cardholderTable && this.vars.cardholderTable.length) {
                this.initDataTable();
                this.bindTableControlEvents();
            }

            if (this.vars.cardholderForm && this.vars.cardholderForm.length) {
                this.initFormLibraries();
                this.bindFormEvents();
                this.updateMainPhotoDisplay(this.vars.initialMainPhotoSrc);
                this.handleResidentTypeChange();
            }
            
        },

        cacheSelectors: function() {
            this.vars = {
                // Page-level containers
                cardholderForm: $('#fsbhoa-cardholder-form'),
                cardholderTable: $('#fsbhoa-cardholder-table'),

                //  Custom table controls
                customLengthMenu: $('#fsbhoa-custom-length-menu'),
                customSearchInput: $('#fsbhoa-custom-search-input'),


                // RFID Section
                rfidInput: $('#rfid_id'),
                statusUiToggleCheckbox: $('#fsbhoa_card_status_ui_toggle'),
                statusToggleContainer: $('#fsbhoa_card_status_toggle_container'),
                submittedStatusHidden: $('#fsbhoa_submitted_card_status'),
                statusDisplaySpan: $('#fsbhoa_card_status_display'),
                toggleLabelSpan: $('#fsbhoa_card_status_toggle_ui_label'),
                issueDateDisplay: $('#fsbhoa_card_issue_date_display'),
                issueDateHidden: $('#fsbhoa_submitted_card_issue_date'),
                contractorExpiryContainer: $('#fsbhoa_expiry_date_wrapper_contractor'),
                residentTypeInput: $('#resident_type'),

                // Photo Section (from your file)
                mainPhotoPreviewImg: $('#fsbhoa_photo_preview_main_img'),  // the preview window
                noPhotoMessage: $('#fsbhoa_no_photo_message'),
                cropPhotoButton: $('#fsbhoa-crop-photo-btn'),
                photoBase64Input: $('#fsbhoa_photo_base64'),
                fileUploadSection: $('#fsbhoa_file_upload_section'),
                fileUploadInput: $('#cardholder_photo_file_input'),
                startWebcamButton: $('#fsbhoa_start_webcam_button'),
                webcamContainer: $('#fsbhoa_webcam_container'),
                videoElement: document.getElementById('fsbhoa_webcam_video'),
                webcamActiveControls: $('#fsbhoa_webcam_active_controls'),
                stopWebcamButton: $('#fsbhoa_stop_webcam_button'),
                capturePhotoButton: $('#fsbhoa_capture_photo_button'),
                canvasElement: document.getElementById('fsbhoa_webcam_canvas'),
                removePhotoButton: $('#fsbhoa_remove_photo_button'),
		exportPhotoButton: $('#fsbhoa-export-photo-btn'),
                printIdButton: $('#print-id-card-button'),
                firstNameInput: $('#first_name'),
                lastNameInput: $('#last_name'),
                webcamErrorMessage: $('#fsbhoa_webcam_error_message'),
                stream: null,
                initialMainPhotoSrc: ($('#fsbhoa_photo_preview_main_img').attr('src') && $('#fsbhoa_photo_preview_main_img').attr('src') !== '#') ? $('#fsbhoa_photo_preview_main_img').attr('src') : null,

                // Property Section (from your file)
                propertySearchInput: $('#fsbhoa_property_search_input'),
                propertyIdHiddenInput: $('#fsbhoa_property_id_hidden'),
                selectedPropertyDisplay: $('#fsbhoa_selected_property_display'),

            };
        },

        initDataTable: function() {
             if (!this.vars.cardholderTable.length) { return; }

             // Find the "Add New" button and hide the original, we will use a clone.
             //const $addNewButton = $('.fsbhoa-frontend-wrap .button-primary').first().clone(true);
             //$('.fsbhoa-frontend-wrap .button-primary').first().hide();

             if (!this.vars.cardholderTable.length) { return; }

             //  We now initialize the table and store its instance.
             // The 'dom' option is set to only show the table itself ('t'), plus info and pagination ('ip').
             // The default controls ('l' and 'f') are removed because we built our own.
             this.dataTableInstance = this.vars.cardholderTable.DataTable({
                "dom": 'tip', // 't' = table, 'i' = info, 'p' = pagination
                "pageLength": 100,       // Set a default page length
                "stateSave": false,      //  This makes the setting sticky
                // --- OPTIONS FOR BULK SELECTION for export ---
                "columnDefs": [ {
                    "targets": 0, // Target the first column
                    "orderable": false,
                    "className": 'select-checkbox',
                    "data": null,
                    "defaultContent": ''
                } ],
                "select": {
                    "style": 'multi+shift', // allow multiple rows to be selected (shift-click enabled)
                    "selector": 'td:first-child'
                },
                "order": [[ 2, 'asc' ]] // Default sort by the Name column (now index 2)

             });
        },

        //  This function binds events for our custom HTML controls
        bindTableControlEvents: function() {
            if (!this.dataTableInstance) { return; }
        
            // When the user types in our custom search box
            $('#fsbhoa-cardholder-search-input').on('keyup', (e) => {
                this.dataTableInstance.search(e.target.value).draw();
            });

            // When the user changes our custom "Show entries" dropdown
            $('#fsbhoa-custom-length-menu').on('change', (e) => {
                this.dataTableInstance.page.len(e.target.value).draw();
            });

            // *** START: CODE FOR EXPORT BUTTON ***
            $('#fsbhoa-export-selected-button').on('click', (e) => {
                e.preventDefault();
                
                // Use the DataTables API to get the DOM nodes of the selected rows
                const selectedNodes = this.dataTableInstance.rows({ selected: true }).nodes();
                const ids = [];

                // Use a standard 'for' loop for robust iteration
                for (let i = 0; i < selectedNodes.length; i++) {
                    const id = $(selectedNodes[i]).data('cardholder-id');
                    if (id) {
                        ids.push(id);
                    }
                }

                
                if (ids.length === 0) {
                    alert('Please select at least one cardholder to export.');
                    return;
                }
                
                const exportNonce = fsbhoa_ajax_settings.export_nonce;
                const adminPostUrl = fsbhoa_ajax_settings.ajax_url.replace('admin-ajax.php', 'admin-post.php');
                const form = $('<form>', { 'method': 'POST', 'action': adminPostUrl });

                form.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_export_selected' }));
                form.append($('<input>', { 'type': 'hidden', 'name': '_wpnonce', 'value': exportNonce }));
                
                ids.forEach(id => {
                    form.append($('<input>', { 'type': 'hidden', 'name': 'cardholder[]', 'value': id }));
                });

                $('body').append(form);
                form.submit();
                form.remove();
            });
            // *** END: CODE FOR EXPORT BUTTON ***

            // *** START: CODE FOR PRINT REPORT BUTTON ***
            $('#fsbhoa-print-report-button').on('click', (e) => {
                e.preventDefault();
                
                const selectedNodes = this.dataTableInstance.rows({ selected: true }).nodes();
                const ids = [];

                for (let i = 0; i < selectedNodes.length; i++) {
                    const id = $(selectedNodes[i]).data('cardholder-id');
                    if (id) {
                        ids.push(id);
                    }
                }
                
                if (ids.length === 0) {
                    alert('Please select at least one cardholder to print a report for.');
                    return;
                }
                
                // --- Get the current sort order from the DataTable instance ---
                const order = this.dataTableInstance.order()[0]; // e.g., [2, 'asc']
                const orderColumnIndex = order[0];
                const orderDirection = order[1];

                const printNonce = fsbhoa_ajax_settings.print_report_nonce;
                const adminPostUrl = fsbhoa_ajax_settings.ajax_url.replace('admin-ajax.php', 'admin-post.php');

                const form = $('<form>', {
                    'method': 'POST',
                    'action': adminPostUrl,
                    'target': '_blank'
                });

                form.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_print_report' }));
                form.append($('<input>', { 'type': 'hidden', 'name': '_wpnonce', 'value': printNonce }));
                
                // --- Add the sort order to the form submission ---
                form.append($('<input>', { 'type': 'hidden', 'name': 'orderby_col', 'value': orderColumnIndex }));
                form.append($('<input>', { 'type': 'hidden', 'name': 'order_dir', 'value': orderDirection }));

                ids.forEach(id => {
                    form.append($('<input>', { 'type': 'hidden', 'name': 'cardholder_ids[]', 'value': id }));
                });

                $('body').append(form);
                form.submit();
                form.remove();
            });
            // *** END:  CODE FOR PRINT REPORT BUTTON ***
        },


        initFormLibraries: function() {
            console.log("TRACE: 4. initFormLibraries() started.");
            if (typeof FSBHOA_Croppie !== 'undefined') {
                FSBHOA_Croppie.init((croppedImageDataURL) => {
                    this.updateMainPhotoDisplay(croppedImageDataURL);
                    if (this.vars.photoBase64Input.length) {
                        // Strip the "data:image/jpeg;base64," part before setting the value
                        const base64Data = croppedImageDataURL.split(',')[1] || "";
                        //console.log('DEBUG 3: Setting hidden field with base64 data (first 50 chars):', base64Data.substring(0, 50));
                        this.vars.photoBase64Input.val(base64Data);
                    }
                    else {
                       console.error('DEBUG FAILED: Could not find the hidden photo input field (#fsbhoa_photo_base64)!');
                    }

                });
                console.log("TRACE: 5. Croppie initialized.");
            }
            this.initPropertyAutocomplete();
            console.log("TRACE: 6. initFormLibraries() finished.");
        },

        initPropertyAutocomplete: function() {
            if (this.vars.propertySearchInput.length) {
                this.vars.propertySearchInput.autocomplete({
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
                            this.vars.propertySearchInput.val(ui.item.label);
                            this.vars.propertyIdHiddenInput.val(ui.item.id);
                        }
                        return false;
                    }
                });
            }
        },

        bindFormEvents: function() {
            const formContainer = this.vars.cardholderForm;
            if (!formContainer.length) { return; }


            formContainer.on('click', '#fsbhoa-crop-photo-btn', () => this.handleCropButtonClick());
            formContainer.on('click', '#fsbhoa_card_status_ui_toggle', () => this.updateStatusDisplayFromCheckbox());
            formContainer.on('click', '#fsbhoa_start_webcam_button', () => this.startWebcam());
            formContainer.on('click', '#fsbhoa_stop_webcam_button', () => this.stopWebcam());
            formContainer.on('click', '#fsbhoa_capture_photo_button', () => this.captureWebcamPhoto());
            formContainer.on('change', '#cardholder_photo_file_input', (e) => this.handleFileSelect(e));
            formContainer.on('input', '#rfid_id', () => this.handleRfidInputChange());
            formContainer.on('click', '#fsbhoa_remove_photo_button', () => this.handleRemovePhotoButtonClick());
            formContainer.on('click', '#fsbhoa-export-photo-btn', () => this.handleExportPhotoClick());
            formContainer.on('click', '#print-id-card-button', () => this.handlePrintIdClick());
            formContainer.on('change', '#resident_type', () => this.handleResidentTypeChange());
        },

        handleRemovePhotoButtonClick: function() {
            // Clear the visual preview by calling our central display function.
            // Passing 'null' clears the image.
            // This also hides all of the buttons that take action on the image.
            this.updateMainPhotoDisplay(null);
        },

        handlePrintIdClick: function() {
            // Create a new hidden input field to act as our flag.
            var afterSaveInput = $('<input>')
                .attr('type', 'hidden')
                .attr('name', 'fsbhoa_after_save_action')
                .val('print');

            // Add the hidden input to the form.
            this.vars.cardholderForm.append(afterSaveInput);

            // Now, trigger the main form's "Save" button.
            $('#submit').click();
        },

        handleExportPhotoClick: function() {
            const base64Data = this.vars.photoBase64Input.val();
            const firstName = this.vars.firstNameInput.val() || 'photo';
            const lastName = this.vars.lastNameInput.val() || 'export';

            if (!base64Data) {
                alert('No photo to export.');
                return;
            }

            // Create the full data URL
            const dataUrl = 'data:image/png;base64,' + base64Data;
            
            // Sanitize names for a safe filename
            const safeFirstName = firstName.trim().toLowerCase().replace(/[^a-z0-9]/gi, '_');
            const safeLastName = lastName.trim().toLowerCase().replace(/[^a-z0-9]/gi, '_');
            const filename = safeFirstName + '.' + safeLastName + '.png';

            // Create a temporary link element and trigger the browser download
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },

        handleResidentTypeChange: function() {
            if (!this.vars.residentTypeInput) return;
        
            const selectedType = this.vars.residentTypeInput.val();
            if (selectedType === 'Contractor') {
                this.vars.contractorExpiryContainer.show();
            } else {
                this.vars.contractorExpiryContainer.hide();
            }
        },
        
        handleCropButtonClick: function() {
            const imageSrc = this.vars.mainPhotoPreviewImg.attr('src');
            if (imageSrc && imageSrc !== '#') {
                const photoSettings = (typeof fsbhoa_photo_settings !== 'undefined') ? fsbhoa_photo_settings : {};
                FSBHOA_Croppie.open(imageSrc, photoSettings);
            }
        },

        startWebcam: function() {
           // First, hide any previous error messages
           this.vars.webcamErrorMessage.hide().text('');

           // Check if the browser supports the mediaDevices API (requires HTTPS or localhost)
           if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
               const errorMessage = 'Webcam requires a secure connection (HTTPS). Please use an https:// URL to access this page.';
               this.vars.webcamErrorMessage.text(errorMessage).show();
               console.error(errorMessage);
               return; // Stop the function here
           }
           this.vars.removePhotoButton.hide();
           this.vars.exportPhotoButton.hide();
           this.vars.printIdButton.hide();

           // If the check passes, proceed with trying to access the camera
           navigator.mediaDevices.getUserMedia({ video: true })
               .then((mediaStream) => {
                   this.vars.stream = mediaStream;
                   if (this.vars.videoElement) {
                       this.vars.videoElement.srcObject = this.vars.stream;
                       this.vars.videoElement.play();
                       this.vars.webcamContainer.show();
                   }
                   this.vars.startWebcamButton.hide();
                   this.vars.webcamActiveControls.show();
                   this.vars.fileUploadSection.hide();
               })
               .catch((err) => {
                   // This will now catch other errors, like the user denying permission
                   let errorMessage = 'Could not access webcam.';
                   if (err.name === "NotAllowedError" || err.name === "PermissionDeniedError") {
                       errorMessage = 'Webcam access was denied. Please allow camera access in your browser settings.';
                   } else if (err.name === "NotFoundError" || err.name === "DevicesNotFoundError") {
                       errorMessage = 'No webcam was found. Please ensure a camera is connected and enabled.';
                   }
                   this.vars.webcamErrorMessage.text(errorMessage).show();
                   console.error('Webcam Error:', err);
               });
        },

        stopWebcam: function() {
            if (this.vars.stream) { 
                this.vars.stream.getTracks().forEach(track => track.stop()); 
                this.vars.stream = null; 
            }
            this.updateControlsVisibility();
        },

        captureWebcamPhoto: function() {
            if (this.vars.stream) {
                this.vars.canvasElement.width = this.vars.videoElement.videoWidth;
                this.vars.canvasElement.height = this.vars.videoElement.videoHeight;
                this.vars.canvasElement.getContext('2d').drawImage(this.vars.videoElement, 0, 0);
            
                const imageDataUrl = this.vars.canvasElement.toDataURL('image/jpeg', 0.9);
            
                this.updateMainPhotoDisplay(imageDataUrl); // Update the main photo
                this.stopWebcam(); // Stop the webcam stream
            }
        },

        handleFileSelect: function(e) {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = (event) => { this.updateMainPhotoDisplay(event.target.result); };
                reader.readAsDataURL(e.target.files[0]);
            }
        },

        handleRfidInputChange: function() {
            const rfidValue = this.vars.rfidInput.val();
            const today = new Date().toISOString().slice(0, 10);

            if (rfidValue && rfidValue.length === 8) {
                this.vars.statusDisplaySpan.text('Active');
                this.vars.submittedStatusHidden.val('active');
                this.vars.issueDateDisplay.text(today);
                this.vars.issueDateHidden.val(today);
                this.vars.statusToggleContainer.show();
                this.vars.statusUiToggleCheckbox.prop('checked', true);
                this.vars.toggleLabelSpan.text('Card is Active (Click to Disable)');
                if (this.vars.residentTypeInput.val() === 'Contractor') {
                    this.vars.contractorExpiryContainer.show();
                }
            } else {
                this.vars.statusDisplaySpan.text('Inactive');
                this.vars.submittedStatusHidden.val('inactive');
                this.vars.issueDateDisplay.text('N/A');
                this.vars.issueDateHidden.val('');
                this.vars.statusToggleContainer.hide();
                this.vars.contractorExpiryContainer.hide();
            }
        },

        // This function only handles the image data.
        updateMainPhotoDisplay: function(imageDataUrl) {
            const base64Data = (imageDataUrl && imageDataUrl !== '#') ? imageDataUrl.split(',')[1] || "" : "";
            const finalSrc = (imageDataUrl && imageDataUrl !== '#') ? imageDataUrl : '#';

            this.vars.mainPhotoPreviewImg.attr('src', finalSrc);
            this.vars.photoBase64Input.val(base64Data);

            // After updating data, let the central function handle the UI
            this.updateControlsVisibility();
        },

        // This function controls button visiblty based on the current state.
        startWebcam: function() {
            // First, hide any previous error messages
            this.vars.webcamErrorMessage.hide().text('');

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                const errorMessage = 'Webcam requires a secure connection (HTTPS).';
                this.vars.webcamErrorMessage.text(errorMessage).show();
                return;
            }

            // Try to access the camera
            navigator.mediaDevices.getUserMedia({ video: true })
                .then((mediaStream) => {
                    // SUCCESS: The stream is active
                    this.vars.stream = mediaStream;
                    if (this.vars.videoElement) {
                        this.vars.videoElement.srcObject = this.vars.stream;
                        this.vars.videoElement.play();
                    }
                    // Now, let the central function update the UI
                    this.updateControlsVisibility();
                })
                .catch((err) => {
                    // ERROR: Could not get stream
                    console.error('Webcam Error:', err);
                    // Let the central function update the UI even on error
                    this.updateControlsVisibility();
                });
        },

        updateControlsVisibility: function() {

        const hiddenInputValue = this.vars.photoBase64Input.val();

            const hasPhoto = this.vars.photoBase64Input.val() !== '';
            const isWebcamActive = !!this.vars.stream;

            // Show/hide the "no photo" message
            this.vars.noPhotoMessage.toggle(!hasPhoto && !isWebcamActive);

            // Show/hide the main photo preview
            this.vars.mainPhotoPreviewImg.toggle(hasPhoto);

            // Show/hide the action buttons (Remove, Export, Print)
            const showActionButtons = hasPhoto && !isWebcamActive;
            this.vars.removePhotoButton.toggle(showActionButtons);
            this.vars.exportPhotoButton.toggle(showActionButtons);
            this.vars.printIdButton.toggle(showActionButtons);

            // Show/hide the crop button
            this.vars.cropPhotoButton.toggle(showActionButtons);

            // Show/hide the initial controls (Start Webcam, Upload)
            this.vars.startWebcamButton.toggle(!isWebcamActive);
            this.vars.fileUploadSection.toggle(!isWebcamActive);

            // Show/hide the active webcam UI
            this.vars.webcamContainer.toggle(isWebcamActive);
            this.vars.webcamActiveControls.toggle(isWebcamActive);
        },

        updateStatusDisplayFromCheckbox: function() {
            if (!this.vars.statusUiToggleCheckbox.length) return;
            const isChecked = this.vars.statusUiToggleCheckbox.is(':checked');
            const today = new Date().toISOString().slice(0, 10);
            const wasPreviouslyDisabled = this.vars.submittedStatusHidden.val() === 'disabled';

            if (isChecked) {
                this.vars.submittedStatusHidden.val('active');
                this.vars.statusDisplaySpan.text('Active');
                this.vars.toggleLabelSpan.text('(Click to Disable)');
                if (wasPreviouslyDisabled) {
                    this.vars.issueDateDisplay.text(today);
                    this.vars.issueDateHidden.val(today);
                }
            } else {
                this.vars.submittedStatusHidden.val('disabled');
                this.vars.statusDisplaySpan.text('Disabled');
                this.vars.toggleLabelSpan.text('(Click to Activate)');
            }
        }
    };

    App.init();
});


