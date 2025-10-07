jQuery(document).ready(function($) {
    'use strict';

    const syncBanner = $('#fsbhoa-sync-banner');
    const syncButton = $('#fsbhoa-sync-banner-button');
    const syncMessageSpan = $('#fsbhoa-sync-banner-message');
    let pollingInterval = null;

    function startSync() {
        if (syncButton.prop('disabled')) {
            return;
        }

        syncButton.prop('disabled', true).text('Starting...');
        syncMessageSpan.text('Sync process has been scheduled. Please wait...');

        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_sync_all_controllers',
            nonce: fsbhoa_sync_vars.nonce
        })
        .done(function(response) {
            if (response.success) {
                // Start polling immediately to check for status
                setTimeout(pollSyncStatus, 2000); // Wait 2s for cron to start
                pollingInterval = setInterval(pollSyncStatus, 500); // Then check every 500ms
            } else {
                syncMessageSpan.text('Error: Could not start the sync process.');
                syncButton.prop('disabled', false).text('Push Changes Now');
            }
        })
        .fail(function() {
            syncMessageSpan.text('Error: Failed to communicate with server.');
            syncButton.prop('disabled', false).text('Push Changes Now');
        });
    }

    function pollSyncStatus() {
        $.post(fsbhoa_sync_vars.ajax_url, {
            action: 'fsbhoa_get_sync_status', // Use our new hybrid endpoint
            nonce: fsbhoa_sync_vars.nonce
        })
        .done(function(response) {
            if (response.success) {
                const data = response.data;

                // Case 1: Sync is complete.
                if (parseInt(data.count) === 0) {
                    clearInterval(pollingInterval);
                    syncMessageSpan.html('<strong>Sync complete!</strong>');
                    syncBanner.css('background-color', '#46b450'); // Green

                    setTimeout(function() {
                        syncBanner.slideUp();
                    }, 4000);

                // Case 2: Sync is actively running.
                } else if (data.status === 'in_progress') {
                    syncMessageSpan.text(data.message);
                    syncButton.text('Syncing...'); // Keep button disabled

                // Case 3: Sync is stuck or failed.
                } else { // count > 0 but status is 'idle' (transient expired)
                    clearInterval(pollingInterval);
                    syncMessageSpan.html('<strong>Sync may have failed.</strong> The queue is not empty. Please try again.');
                    syncBanner.css('background-color', '#dc3232'); // Red
                    syncButton.prop('disabled', false).text('Push Changes Now'); // Re-enable button
                }
            } else {
                // If the polling call itself fails, stop trying.
                clearInterval(pollingInterval);
                syncMessageSpan.text('Error: Could not get sync status.');
                syncButton.prop('disabled', false).text('Push Changes Now');
            }
        });
    }

    // Main button click handler
    $('body').on('click', '#fsbhoa-sync-banner-button', function(e) {
        e.preventDefault();
        startSync();
    });
});

