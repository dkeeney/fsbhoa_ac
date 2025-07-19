jQuery(document).ready(function($) {
    // Note: cardholderId is now passed via a data attribute on the button
    let cardholderId = $('#fsbhoa-start-print-btn').data('cardholder-id');
    let pollingInterval;
    // Note: Using the localized variable now
    let cardholderListPageUrl = fsbhoa_print_vars.cardholder_page_url; 

    // Step 1: User clicks the "Start Print" button
    $('#fsbhoa-start-print-btn').on('click', function() {
        $(this).prop('disabled', true);
        showStatus('Contacting print service...', 'normal');

        $.ajax({
            url: fsbhoa_print_vars.ajax_url, // Use the localized variable
            method: 'POST',
            data: {
                action: 'fsbhoa_submit_print_job',
                cardholder_id: cardholderId,
                security: fsbhoa_print_vars.nonce
            },
            success: function(response) {
                if (response.status === 'queued') {
                    showStatus('Print job is queued. Waiting for completion...', 'normal');
                    pollStatus(response.system_job_id);
                } else {
                    let errorMsg = response.message || 'An unknown error occurred on the print server.';
                    showStatus('Error submitting job: ' + errorMsg, 'error');
                }
            },
            error: function(xhr) {
                let errorMsg = xhr.responseJSON ? xhr.responseJSON.message : 'Could not reach the WordPress backend.';
                showStatus('Failed to submit print job: ' + errorMsg, 'error');
            }
        });
    });

    // Step 2: Poll for the status
    function pollStatus(systemJobId) {
        pollingInterval = setInterval(function() {
            $.ajax({
                url: fsbhoa_print_vars.ajax_url, // Use the localized variable
                method: 'POST',
                data: {
                    action: 'fsbhoa_check_print_status',
                    system_job_id: systemJobId,
                    security: fsbhoa_print_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        let status = response.data.status.toUpperCase();
                        let message = response.data.status_message || status;
                        showStatus('Status: ' + message, 'normal');

                        if (status === 'COMPLETED_OK') {
                            clearInterval(pollingInterval);
                            showRfidScan();
                        } else if (status.includes('ERROR') || status.includes('FAILED')) {
                            clearInterval(pollingInterval);
                            showStatus('Print failed: ' + message, 'error');
                        }
                    }
                }
            });
        }, 3000); // Poll every 3 seconds
    }

    // Step 3: Listen for RFID input
    $('#fsbhoa-rfid-input').on('input', function() {
        if ($(this).val().length >= 8) {
            $(this).prop('disabled', true);
            saveRfidAndActivate($(this).val());
        }
    });

    // Step 4: Save RFID and redirect
    function saveRfidAndActivate(rfid) {
        showStatus('Saving RFID and activating card...', 'normal');
        $.ajax({
            url: fsbhoa_print_vars.ajax_url, // Use the localized variable
            method: 'POST',
            data: {
                action: 'fsbhoa_save_rfid',
                cardholder_id: cardholderId,
                rfid_id: rfid,
                security: fsbhoa_print_vars.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    if(cardholderListPageUrl) {
                        window.location.href = cardholderListPageUrl;
                    } else {
                        showStatus('Card activated! Please return to the cardholder list.', 'success');
                    }
                } else {
                    showStatus('Error activating card: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showStatus('A critical error occurred while saving the RFID.', 'error');
            }
        });
    }
    
    // --- UI Helper Functions ---
    function showStatus(message, type) {
        $('#fsbhoa-initial-section, #fsbhoa-rfid-section').hide();
        $('#fsbhoa-status-section').show();
        let statusDiv = $('#fsbhoa-status-section .status-message');
        statusDiv.text(message).removeClass('status-error status-success');
        if (type === 'error') {
            statusDiv.addClass('status-error');
        } else if (type === 'success') {
            statusDiv.addClass('status-success');
        }
    }

    function showRfidScan() {
        $('#fsbhoa-initial-section, #fsbhoa-status-section').hide();
        $('#fsbhoa-rfid-section').show();
        $('#fsbhoa-rfid-input').focus();
    }
});

