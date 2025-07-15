// assets/js/fsbhoa-test-suite.js
jQuery(document).ready(function($) {
    const runButton = $('#run-test-suite');
    const resultsDiv = $('#test-results');

    runButton.on('click', function() {
        runButton.prop('disabled', true).text('Running...');
        resultsDiv.html('<p>Starting test suite...</p>');

        // Define the sequence of tests
        runTestStep('run_hardware_test', '1. Triggering hardware event from event_service...')
            .then(() => runTestStep('verify_hardware_test', '2. Verifying hardware event in database...'))
            .then(() => runTestStep('run_kiosk_test', '3. Triggering kiosk sign-in via REST API...'))
            .then(() => runTestStep('verify_kiosk_test', '4. Verifying kiosk event in database...'))
            .then(() => {
                logResult('--- Test Suite Complete ---', 'success');
                runButton.prop('disabled', false).text('Run Full Test Suite');
            })
            .catch((errorMsg) => {
                logResult(`--- TEST HALTED: ${errorMsg} ---`, 'error');
                runButton.prop('disabled', false).text('Run Full Test Suite');
            });
    });

    function runTestStep(step, message) {
        logResult(message, 'running');
        // Add a delay to allow services to process
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                $.post(fsbhoa_test_vars.ajax_url, {
                    action: 'fsbhoa_run_regression_test',
                    nonce: fsbhoa_test_vars.nonce,
                    test_step: step
                })
                .done(function(response) {
                    if (response.success) {
                        logResult(response.data, 'success');
                        resolve();
                    } else {
                        logResult(response.data, 'error');
                        reject(response.data);
                    }
                })
                .fail(function() {
                    const errorMsg = 'AJAX request failed for step: ' + step;
                    logResult(errorMsg, 'error');
                    reject(errorMsg);
                });
            }, 2000); // 2-second delay
        });
    }

    function logResult(message, status) {
        let color = '#333'; // Default
        if (status === 'success') color = 'green';
        if (status === 'error') color = 'red';
        if (status === 'running') message = `<i>${message}</i>`;
        
        resultsDiv.append(`<div style="color:${color}; margin-bottom:5px;">${message}</div>`);
        resultsDiv.scrollTop(resultsDiv[0].scrollHeight);
    }
});

