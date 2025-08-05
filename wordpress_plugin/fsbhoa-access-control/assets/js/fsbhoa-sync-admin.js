jQuery(document).ready(function($) {
    const syncStatusSpan = $('#fsbhoa-sync-banner-message');
    const syncBanner = $('#fsbhoa-sync-banner');
    let intervalId = null;

    // Helper function to update the message in the banner
    function updateBannerMessage(type, message) {
        syncBanner.show(); // Ensure banner is visible when a message is shown
        syncStatusSpan.html(message); // Use html to render the <strong> tag
        
        // You can add color changes based on type if you want
        if (type === 'success') {
            syncBanner.css('background-color', '#46b450'); // Green
        } else if (type === 'error') {
            syncBanner.css('background-color', '#dc3232'); // Red
        }
    }

    // Function to start the sync process
    function startSync() {
        const bannerButton = $('#fsbhoa-sync-banner-button');
        if (bannerButton.prop('disabled')) return;

        bannerButton.prop('disabled', true).text('Syncing...');
        updateBannerMessage('info', 'Sync process started. Please wait...');
        console.log('SYNC: Kicking off sync process...');

        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_sync_all_controllers',
            nonce: fsbhoa_sync_vars.nonce
        }, function(response) {
            if (response.success) {
                console.log('SYNC: Backend acknowledged start. Beginning to poll for status.');
                intervalId = setInterval(checkSyncStatus, 5000);
            } else {
                const errorMessage = response.data ? response.data.message : 'Could not start sync.';
                updateBannerMessage('error', `Error: ${errorMessage}`);
                bannerButton.prop('disabled', false).text('Push Changes Now');
                console.error('SYNC: Failed to start sync process. Server response:', response);
            }
        }).fail(function(xhr, textStatus, errorThrown) {
            updateBannerMessage('error', 'Error: Communication with server failed.');
            bannerButton.prop('disabled', false).text('Push Changes Now');
            console.error('SYNC: Initial AJAX request failed.');
            console.error('Status:', xhr.status, textStatus);
            console.error('Response Text:', xhr.responseText);
        });
    }

    // Function to poll for the sync status
    function checkSyncStatus() {
        const bannerButton = $('#fsbhoa-sync-banner-button');

        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_get_sync_status',
            nonce: fsbhoa_sync_vars.nonce
        }, function(response) {
            console.log('SYNC Polling Response:', response);

            if (response.success && response.data) {
                updateBannerMessage('info', response.data.message);

                if (response.data.status === 'complete' || response.data.status === 'failed') {
                    console.log(`SYNC: Received "${response.data.status}" status. Stopping poller.`);
                    clearInterval(intervalId);
                    bannerButton.prop('disabled', false).text('Push Changes Now');
                    
                    const noticeType = response.data.status === 'complete' ? 'success' : 'error';
                    const message = response.data.status === 'complete' ? `${response.data.message} <strong>Done!</strong>` : `Error: ${response.data.message}`;
                    updateBannerMessage(noticeType, message);

                    // Hide the banner after a short delay to allow reading the final message.
                    setTimeout(function() {
                        syncBanner.slideUp();
                    }, 5000); // 5 seconds
                }
            }
        }).fail(function(xhr){
            updateBannerMessage('error', 'Error: Lost connection while checking status.');
            clearInterval(intervalId);
            bannerButton.prop('disabled', false).text('Push Changes Now');
            console.error('SYNC: Status polling request failed.', xhr.responseText);

            setTimeout(function() {
                syncBanner.slideUp();
            }, 8000);
        });
    }

    // Main button click handler, attached directly to the banner button
    $('body').on('click', '#fsbhoa-sync-banner-button', function(e) {
        e.preventDefault();
        if (!confirm('This will push all pending changes to all controllers. This may take a minute. Continue?')) {
            return;
        }
        startSync();
    });
});
