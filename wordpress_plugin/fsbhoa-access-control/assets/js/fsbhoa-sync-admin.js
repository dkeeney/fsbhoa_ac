jQuery(document).ready(function($) {
    // Find the sync button and the status message container
    const syncButton = $('#fsbhoa-sync-all-button');
    const syncStatus = $('#fsbhoa-sync-status');
    let intervalId = null;

    // Handle the button click
    syncButton.on('click', function(e) {
        e.preventDefault();

        if (syncButton.hasClass('disabled')) {
            return;
        }

        // Using a custom modal is preferred, but confirm is a placeholder
        if (!confirm('This will push all active cardholders and tasks to all controllers. This may take a minute. Continue?')) {
            return;
        }

        // Disable button and show initial status message
        syncButton.addClass('disabled').text('Syncing...');
        syncStatus.text('Sync process started. Please wait...').show();
        console.log('SYNC: Kicking off sync process...');

        // Start the background sync process
        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_sync_all_controllers',
            nonce: fsbhoa_sync_vars.nonce
        }, function(response) {
            if (response.success) {
                // If the job started successfully, begin polling for status
                console.log('SYNC: Backend acknowledged start. Beginning to poll for status.');
                intervalId = setInterval(checkSyncStatus, 5000); // Check every 5 seconds
            } else {
                const errorMessage = response.data.message || 'Could not start sync.';
                syncStatus.text('Error: ' + errorMessage);
                syncButton.removeClass('disabled').text('Sync All Controllers');
                console.error('SYNC: Failed to start sync process.', response);
            }
        }).fail(function(xhr) {
            // Handle cases where the initial AJAX call fails completely
            syncStatus.text('Error: Communication with server failed.');
            syncButton.removeClass('disabled').text('Sync All Controllers');
            console.error('SYNC: Initial AJAX request failed.', xhr.responseText);
        });
    });

    // Function to poll for the sync status
    function checkSyncStatus() {
        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_get_sync_status',
            nonce: fsbhoa_sync_vars.nonce
        }, function(response) {
            // --- NEW DEBUGGING ---
            // Log the entire response object to see exactly what we get from the server.
            console.log('SYNC Polling Response:', response); 
            // --- END DEBUGGING ---

            if (response.success && response.data) {
                // Update the visible status message on the page
                syncStatus.text(response.data.message);
                
                // Check if the status is 'complete'
                if (response.data.status === 'complete') {
                    console.log('SYNC: Received "complete" status. Stopping poller and resetting button.');
                    clearInterval(intervalId);
                    syncButton.removeClass('disabled').text('Sync All Controllers');
                    syncStatus.append(' <strong style="color:green;">Done!</strong>');
                }
            } else {
                console.warn('SYNC: Polling response was not successful or data was missing.', response);
            }
        }).fail(function(xhr){
            // Handle polling failures
            console.error('SYNC: Status polling request failed.', xhr.responseText);
            syncStatus.text('Error: Lost connection while checking status.');
            clearInterval(intervalId); // Stop polling on error
            syncButton.removeClass('disabled').text('Sync All Controllers');
        });
    }
});

