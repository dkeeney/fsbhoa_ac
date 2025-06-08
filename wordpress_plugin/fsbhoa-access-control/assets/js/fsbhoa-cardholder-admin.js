jQuery(function($) {

    const App = {
        isInitialized: false,
        vars: {},

        init: function() {
            if (this.isInitialized) { return; }
            this.isInitialized = true;
            
            this.cacheSelectors();
            this.initLibraries();
            this.bindEvents();
            this.updateMainPhotoDisplay(this.vars.initialMainPhotoSrc);
        },

        cacheSelectors: function() {
            this.vars = {
                mainPhotoPreviewImg: $('#fsbhoa_photo_preview_main_img'),
                noPhotoMessage: $('#fsbhoa_no_photo_message'),
                cropPhotoButton: $('#fsbhoa-crop-photo-btn'),
                croppedPhotoDataInput: $('#fsbhoa_cropped_photo_data'),
                fileUploadSection: $('#fsbhoa_file_upload_section'),
                fileUploadInput: $('#cardholder_photo_file_input'),
                startWebcamButton: $('#fsbhoa_start_webcam_button'),
                webcamContainer: $('#fsbhoa_webcam_container'),
                videoElement: document.getElementById('fsbhoa_webcam_video'),
                webcamActiveControls: $('#fsbhoa_webcam_active_controls'),
                stopWebcamButton: $('#fsbhoa_stop_webcam_button'),
                capturePhotoButton: $('#fsbhoa_capture_photo_button'),
                canvasElement: document.getElementById('fsbhoa_webcam_canvas'),
                propertySearchInput: $('#fsbhoa_property_search_input'),
                propertyIdHiddenInput: $('#fsbhoa_property_id_hidden'),
                selectedPropertyDisplay: $('#fsbhoa_selected_property_display'),
                clearSelectionButton: $('#fsbhoa_property_clear_selection'),
                stream: null,
                initialMainPhotoSrc: ($('#fsbhoa_photo_preview_main_img').attr('src') && $('#fsbhoa_photo_preview_main_img').attr('src') !== '#') ? $('#fsbhoa_photo_preview_main_img').attr('src') : null,
            };
        },

        initLibraries: function() {
            if ($('#fsbhoa-cardholder-table').length) {
                $('#fsbhoa-cardholder-table').DataTable();
            }
            if (typeof FSBHOA_Croppie !== 'undefined') {
                FSBHOA_Croppie.init((croppedImageDataURL) => {
                    this.updateMainPhotoDisplay(croppedImageDataURL);
                    if (this.vars.croppedPhotoDataInput.length) {
                        this.vars.croppedPhotoDataInput.val(croppedImageDataURL.replace(/^data:image\/(png|jpeg|gif);base64,/, ""));
                    }
                });
            }
        },
        
        bindEvents: function() {
            const appContainer = $('#fsbhoa-cardholder-management-wrap');
            if (!appContainer.length) { return; }

            appContainer.on('click', '#fsbhoa-crop-photo-btn', () => {
                const imageSrc = this.vars.mainPhotoPreviewImg.attr('src');
                if (imageSrc && imageSrc !== '#') {
                    const photoSettings = (typeof fsbhoa_photo_settings !== 'undefined') ? fsbhoa_photo_settings : {};
                    FSBHOA_Croppie.open(imageSrc, photoSettings);
                }
            });

            appContainer.on('click', '#fsbhoa_start_webcam_button', () => {
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
                    .catch((err) => { alert('Could not access webcam: ' + err.name); });
            });

            appContainer.on('click', '#fsbhoa_stop_webcam_button', () => {
                if (this.vars.stream) { this.vars.stream.getTracks().forEach(track => track.stop()); this.vars.stream = null; }
                this.vars.webcamContainer.hide();
                this.vars.startWebcamButton.show();
                this.vars.webcamActiveControls.hide();
                this.vars.fileUploadSection.show();
            });

            appContainer.on('click', '#fsbhoa_capture_photo_button', () => {
                if (this.vars.stream && this.vars.videoElement.readyState >= 2) {
                    const canvasElement = document.getElementById('fsbhoa_webcam_canvas');
                    if (canvasElement) {
                        canvasElement.width = this.vars.videoElement.videoWidth;
                        canvasElement.height = this.vars.videoElement.videoHeight;
                        canvasElement.getContext('2d').drawImage(this.vars.videoElement, 0, 0);
                        const imageDataUrl = canvasElement.toDataURL('image/jpeg', 0.9);
                        this.vars.stopWebcamButton.trigger('click');
                        this.updateMainPhotoDisplay(imageDataUrl);
                    }
                }
            });

            appContainer.on('change', '#cardholder_photo_file_input', (e) => {
                if (e.target.files && e.target.files[0]) {
                    const reader = new FileReader();
                    reader.onload = (event) => { this.updateMainPhotoDisplay(event.target.result); };
                    reader.readAsDataURL(e.target.files[0]);
                }
            });
        },

        updateMainPhotoDisplay: function(imageDataUrl) {
            if (imageDataUrl && imageDataUrl !== '#') {
                this.vars.mainPhotoPreviewImg.attr('src', imageDataUrl).show();
                if (this.vars.noPhotoMessage) this.vars.noPhotoMessage.hide();
                if (this.vars.cropPhotoButton) this.vars.cropPhotoButton.show();
            } else { 
                this.vars.mainPhotoPreviewImg.attr('src', '#').hide();
                if (this.vars.noPhotoMessage) this.vars.noPhotoMessage.show();
                if (this.vars.cropPhotoButton) this.vars.cropPhotoButton.hide();
            }
        }
    };

    // The single entry point to initialize the app on first interaction.
    $('#fsbhoa-cardholder-management-wrap').one('click', 'button, input[type="file"]', function() {
        if (!App.isInitialized) {
            App.init();
        }
    });
    // Also initialize immediately if an image is already present on page load.
    if ($('#fsbhoa_photo_preview_main_img').attr('src') && $('#fsbhoa_photo_preview_main_img').attr('src') !== '#') {
         if (!App.isInitialized) {
            App.init();
         }
    }
});
