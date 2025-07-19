jQuery(document).ready(function($) {
    // Find the sync button and the status message container
    const syncButton = $('#fsbhoa-sync-all-button');
    const syncStatus = $('#fsbhoa-sync-status');
    let intervalId = null;

    // Check if the URL has our 'sync_started' flag on page load.
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('sync_started')) {
        startSync(); // This function is created in the next step

        // Clean the URL so a refresh doesn't re-trigger the sync
        urlParams.delete('sync_started');
        const newUrl = window.location.pathname + '?' + urlParams.toString().replace(/&*$/, '');
        history.replaceState(null, '', newUrl);
    }


    // Helper function to show the status notice at the top of the page
    function showNotice(type, message) {
        const noticeContainer = $('#fsbhoa-sync-notice-container');
        let noticeClass = 'notice notice-info is-dismissible';
        if (type === 'success') {
            noticeClass = 'notice notice-success is-dismissible';
        } else if (type === 'error') {
            noticeClass = 'notice notice-error is-dismissible';
        }

        const noticeHTML = `<div class="${noticeClass}"><p><strong>Sync Status:</strong> ${message}</p></div>`;
        noticeContainer.html(noticeHTML).show();
    }

    // Function to start the sync process (can be called by button or automatically)
    function startSync() {
        if (syncButton.hasClass('disabled')) return;

        syncButton.addClass('disabled').text('Syncing...');
        showNotice('info', 'Sync process started. Please wait...');
        console.log('SYNC: Kicking off sync process...');

        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_sync_all_controllers',
            nonce: fsbhoa_sync_vars.nonce
        }, function(response) {
            if (response.success) {
                console.log('SYNC: Backend acknowledged start. Beginning to poll for status.');
                intervalId = setInterval(checkSyncStatus, 5000);
            } else {
                const errorMessage = response.data.message || 'Could not start sync.';
                showNotice('error', `Error: ${errorMessage}`);
                syncButton.removeClass('disabled').text('Sync All Controllers');
                console.error('SYNC: Failed to start sync process.', response);
            }
        }).fail(function(xhr) {
            showNotice('error', 'Error: Communication with server failed.');
            syncButton.removeClass('disabled').text('Sync All Controllers');
            console.error('SYNC: Initial AJAX request failed.', xhr.responseText);
        });
    }

    

    // Updated function to poll for the sync status
    function checkSyncStatus() {
        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_get_sync_status',
            nonce: fsbhoa_sync_vars.nonce
        }, function(response) {
            console.log('SYNC Polling Response:', response);
            if (response.success && response.data) {
                showNotice('info', response.data.message);

                if (response.data.status === 'complete') {
                    console.log('SYNC: Received "complete" status. Stopping poller.');
                    clearInterval(intervalId);
                    syncButton.removeClass('disabled').text('Sync All Controllers');
                    showNotice('success', `${response.data.message} <strong>Done!</strong>`);
                }
            } else {
                console.warn('SYNC: Polling response was not successful or data was missing.', response);
            }
        }).fail(function(xhr){
            showNotice('error', 'Error: Lost connection while checking status.');
            clearInterval(intervalId);
            syncButton.removeClass('disabled').text('Sync All Controllers');
            console.error('SYNC: Status polling request failed.', xhr.responseText);
        });
    }

    // Updated button click handler
    syncButton.on('click', function(e) {
        e.preventDefault();
        if (!confirm('This will push all active cardholders and tasks to all controllers. This may take a minute. Continue?')) {
            return;
        }
        startSync();
    });
});
