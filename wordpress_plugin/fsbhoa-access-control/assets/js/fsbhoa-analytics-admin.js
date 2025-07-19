jQuery(document).ready(function($) {
    // --- Global Chart Variables ---
    let gateUsageChart = null;
    let peakHoursChart = null;

    // --- Analytics Logic ---
    function fetchAndRenderCharts() {
        const year = $('#analytics-year').val();
        const month = $('#analytics-month').val();

        // Show a loading indicator (optional but good UX)
        $('.chart-wrapper').append('<div class="loading-spinner"></div>');

        $.ajax({
            url: `/wp-json/fsbhoa/v1/reports/usage-analytics?year=${year}&month=${month}`,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fsbhoa_reports_vars.rest_nonce);
            },
            success: function(data) {
                $('.loading-spinner').remove();
                renderGateUsageChart(data.gateUsage);
                renderPeakHoursChart(data.hourlyUsage);
            },
            error: function(err) {
                console.error("Error fetching analytics data:", err);
                $('.loading-spinner').remove();
                $('#gate-usage-chart-container').html('<p class="notice notice-error">Could not load chart data.</p>');
            }
        });
    }

    function renderGateUsageChart(gateData) {
        const ctx = document.getElementById('gate-usage-chart');
        if (!ctx) return;

        const labels = gateData.map(item => item.friendly_name);
        const data = gateData.map(item => item.count);

        if (gateUsageChart) {
            gateUsageChart.destroy();
        }
        gateUsageChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Granted Swipes',
                    data: data,
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }]
            },
            options: { 
                scales: { 
                    y: { 
                        beginAtZero: true, 
                        ticks: { 
                            stepSize: 1, // Ensure y-axis only shows whole numbers
                            precision: 0
                        } 
                    } 
                } 
            }
        });
    }

    function renderPeakHoursChart(hourlyData) {
        const ctx = document.getElementById('peak-hours-chart');
        if (!ctx) return;
        
        const labels = Array.from({ length: 24 }, (_, i) => {
            const hour = i % 12 === 0 ? 12 : i % 12;
            const ampm = i < 12 ? 'AM' : 'PM';
            return `${hour} ${ampm}`;
        });

        if (peakHoursChart) {
            peakHoursChart.destroy();
        }
        peakHoursChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Granted Swipes per Hour',
                    data: hourlyData,
                    fill: true,
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    tension: 0.1
                }]
            },
            options: { 
                scales: { 
                    y: { 
                        beginAtZero: true, 
                        ticks: { 
                            stepSize: 1,
                            precision: 0
                        } 
                    } 
                } 
            }
        });
    }

    // --- Event Handlers ---

    // Trigger chart render on filter change
    $('#analytics-month, #analytics-year').on('change', function() {
        fetchAndRenderCharts();
    });

    // Initial load
    fetchAndRenderCharts();
});

