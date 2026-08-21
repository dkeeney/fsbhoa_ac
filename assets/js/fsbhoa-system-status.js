jQuery(document).ready(function($) {

    // Configuration
    const SLOW_POLL_MS = 5000; 
    const FAST_POLL_MS = 1000; 
    var pollTimer = null;
    var isFastPolling = false;

    // ---------------------------------------------------------
    // 1. POLLING CONTROL
    // ---------------------------------------------------------

    function startPolling(interval) {
        if (pollTimer) clearInterval(pollTimer);
        // Don't run immediately here to avoid double-firing, just set the interval
        pollTimer = setInterval(refreshAllServices, interval);
        
        isFastPolling = (interval === FAST_POLL_MS);
        // console.log('Polling speed set to: ' + interval + 'ms');
    }

    function refreshAllServices() {
        $('.fsbhoa-status-indicator').each(function() {
            var serviceId = $(this).attr('id').replace('status-', '');
            checkServiceStatus(serviceId);
        });
    }

    // ---------------------------------------------------------
    // 2. SERVICE STATUS CHECKS
    // ---------------------------------------------------------

    function checkServiceStatus(serviceId) {
        var $indicator = $('#status-' + serviceId.replace(/\./g, '\\.'));
        
        $.post(fsbhoa_admin.ajax_url, { 
            action: 'fsbhoa_manage_service',
            command: 'status',
            service: serviceId,
            nonce: fsbhoa_admin.service_nonce,
            global: false 
        }, function(response) {
            // SUCCESS: Server is responding
            $indicator.removeClass('is-running is-stopped is-offline');

            if (response.success) {
                // 1. Update Lights
                if (response.data.status === 'active' || response.data.status === 'running') {
                    $indicator.addClass('is-running').text('Running');
                } else {
                    $indicator.addClass('is-stopped').text('Stopped');
                }

                // 2. RESET POWER BUTTONS (The Fix)
                // If we are getting success AND we are in 'Slow Mode', the server is fully back.
                // We check !isFastPolling to ensure we don't unlock them 1 second after clicking reboot
                // (before the server has had a chance to actually die).
                if (!isFastPolling && $('#btn-server-reboot').is(':disabled')) {
                    $('#btn-server-reboot, #btn-server-shutdown').prop('disabled', false);
                    $('#power-action-status').text(''); // Clear the "Rebooting..." text
                }

            } else {
                $indicator.addClass('is-stopped').text('Error');
            }
        }).fail(function() {
            // FAILURE: Connection Lost
            $indicator.removeClass('is-running is-stopped')
                      .addClass('is-offline')
                      .text('Connection Lost');
            
            // If we catch the server dying (while Fast Polling), switch back to Slow
            if (isFastPolling) {
                startPolling(SLOW_POLL_MS);
            }
        });
    }

    // Start normal polling on load
    startPolling(SLOW_POLL_MS);
    refreshAllServices(); // Run once immediately on load

    // ---------------------------------------------------------
    // 3. SERVICE CONTROL BUTTONS
    // ---------------------------------------------------------

    $('.service-command-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var service = $btn.data('service');
        var command = $btn.data('command');
        var $indicator = $('#status-' + service.replace(/\./g, '\\.'));

        $indicator.removeClass('is-running is-stopped is-offline').text('Processing...');
        $btn.prop('disabled', true);

        $.post(fsbhoa_admin.ajax_url, { 
            action: 'fsbhoa_manage_service',
            service: service,
            command: command,
            nonce: fsbhoa_admin.service_nonce
        }, function(response) {
            if (response.success) {
                setTimeout(function() {
                    $btn.prop('disabled', false);
                    checkServiceStatus(service);
                }, 2000);
            } else {
                alert('Error: ' + (response.data.message || 'Command failed'));
                $btn.prop('disabled', false);
            }
        });
    });

    // ---------------------------------------------------------
    // 4. SERVER POWER CONTROLS
    // ---------------------------------------------------------

    function sendPowerCommand(action, confirmMsg, successMsg) {
        if (!confirm(confirmMsg)) return;

        var $status = $('#power-action-status');
        $status.text('Sending command...').css('color', '#000');
        
        // Lock buttons
        $('#btn-server-reboot, #btn-server-shutdown').prop('disabled', true);

        $.post(fsbhoa_admin.ajax_url, {
            action: action,
            nonce:  fsbhoa_admin.power_nonce 
        })
        .done(function(response) {
            if (response.success) {
                $status.html('✅ <strong>' + successMsg + '</strong>').css('color', 'green');
                // Speed up polling to catch the shutdown moment
                startPolling(FAST_POLL_MS); 
            } else {
                $status.text('❌ Error: ' + (response.data || 'Unknown error')).css('color', 'red');
                $('#btn-server-reboot, #btn-server-shutdown').prop('disabled', false);
            }
        })
        .fail(function() {
            $status.text('⚠️ Connection Lost (Server is rebooting)').css('color', '#e65100');
        });
    }

    $('#btn-server-reboot').click(function(e) {
        e.preventDefault();
        sendPowerCommand('fsbhoa_system_reboot', 'Are you sure you want to REBOOT?', 'Reboot initiated. Watching for restart...');
    });

    $('#btn-server-shutdown').click(function(e) {
        e.preventDefault();
        sendPowerCommand('fsbhoa_system_shutdown', 'DANGER: Power Off?', 'Shutdown initiated. wait 60 seconds. manually start.');
    });

});
