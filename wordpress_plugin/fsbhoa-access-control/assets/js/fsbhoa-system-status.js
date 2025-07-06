jQuery(document).ready(function($) {

    // Helper function to update the UI for a single service
    function updateStatusUI(service, statusData) {
        // This is the fix: we escape the '.' in the service name for the jQuery selector.
        const safeServiceId = service.replace(/\./g, '\\.');
        const statusSpan = $(`#status-${safeServiceId}`);

        statusSpan.removeClass('is-running is-stopped');

        console.log(`Updating UI for '${service}' with status: '${statusData.status}'`);

        if (statusData.status === 'running') {
            statusSpan.text('Running').addClass('is-running');
        } else if (statusData.status === 'stopped') {
            statusSpan.text('Stopped').addClass('is-stopped');
        } else {
            statusSpan.text('Unknown');
        }
    }

    // Function to poll for the sync status
    function checkServiceStatus(service) {
        const statusSpan = $(`#status-${service.replace(/\./g, '\\.')}`);
        statusSpan.text('Checking...').removeClass('is-running is-stopped');

        $.post(fsbhoa_system_vars.ajax_url, {
            action: 'fsbhoa_manage_service',
            nonce: fsbhoa_system_vars.nonce,
            service: service,
            command: 'status'
        }, function(response) {
            if (response.success) {
                updateStatusUI(service, response.data);
            } else {
                statusSpan.text('Error');
            }
        });
    }

    // Click handler for all service command buttons
    $('.service-command-btn').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const service = button.data('service');
        const command = button.data('command');

        button.text('Sending...');
        $('.service-command-btn[data-service="' + service + '"]').prop('disabled', true);
        
        $.post(fsbhoa_system_vars.ajax_url, {
            action: 'fsbhoa_manage_service',
            nonce: fsbhoa_system_vars.nonce,
            service: service,
            command: command
        }, function(response) {
            // After sending command, wait a moment then re-check status
            setTimeout(function() {
                checkServiceStatus(service);
                button.text(command.charAt(0).toUpperCase() + command.slice(1)); // Reset button text
                $('.service-command-btn[data-service="' + service + '"]').prop('disabled', false);
            }, 2000); // 2 second delay to give service time to restart
        });
    });

    // Initial status check for all services on page load
    $('.fsbhoa-status-indicator').each(function() {
        const service = $(this).attr('id').replace('status-', '');
        checkServiceStatus(service);
    });
});


