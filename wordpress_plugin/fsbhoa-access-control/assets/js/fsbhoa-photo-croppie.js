// assets/js/fsbhoa-photo-croppie.js
var FSBHOA_Croppie = (function($) {

    let croppieInstance = null;
    let onCropCompleteCallback = null;

    function initialize(containerElement, imageSrc, options) {
        if (!containerElement || typeof Croppie === 'undefined') {
            return;
        }
        
        const photoSettings = options || {};
        const vpw = 300;
        const vph = vpw * ((photoSettings.height || 800) / (photoSettings.width || 640));

        if (croppieInstance) {
            croppieInstance.destroy();
        }
        
        croppieInstance = new Croppie(containerElement, {
            viewport: { width: vpw, height: vph },
            boundary: { width: 350, height: 400 },
            showZoomer: true,
            enableOrientation: true
        });
        
        croppieInstance.bind({ url: imageSrc });
    }

    function destroy() {
        if (croppieInstance) {
            croppieInstance.destroy();
            croppieInstance = null;
        }
    }

    function init(callback) {
        onCropCompleteCallback = callback;
        const cropperDialog = $('#fsbhoa-cropper-dialog');

        if (cropperDialog.length) {
            cropperDialog.dialog({
                autoOpen: false,
                resizable: true,
                height: 550,
                width: 450,
                modal: true,
                classes: { "ui-dialog": "wp-dialog" },
                open: function() {
                    const imageSrc = $(this).data('imageSrc');
                    const options = $(this).data('options');
                    const container = document.getElementById('fsbhoa-cropper-image-container');
                    initialize(container, imageSrc, options);
                },
                buttons: {
                    "Apply Crop": function() {
                        if (croppieInstance) {
                            console.log('CROPPIE DEBUG [1]: "Apply Crop" button clicked.');
                            if (typeof onCropCompleteCallback !== 'function') {
                                console.error('CROPPIE DEBUG [2]: FATAL ERROR - The callback function does not exist!');
                            }
                            const photoSettings = (typeof fsbhoa_photo_settings !== 'undefined') ? fsbhoa_photo_settings : {};
                            croppieInstance.result({
                                type: 'base64',
                                size: { width: photoSettings.width, height: photoSettings.height },
                                format: 'jpeg'
                            }).then(function(result) {
                                 console.log('CROPPIE DEBUG [3]: Croppie has generated a result. Firing callback.');
                                if (typeof onCropCompleteCallback === 'function') {
                                    onCropCompleteCallback(result);
                                }
                            });
                        }
                        $(this).dialog("close");
                    },
                    "Cancel": function() {
                        $(this).dialog("close");
                    }
                },
                close: function() {
                    destroy();
                }
            });
        }
    }
    
    function open(imageSrc, options) {
        const cropperDialog = $('#fsbhoa-cropper-dialog');
        if (cropperDialog.length) {
            cropperDialog.data('imageSrc', imageSrc).data('options', options).dialog('open');
        }
    }

    return {
        init: init,
        open: open
    };

})(jQuery);

