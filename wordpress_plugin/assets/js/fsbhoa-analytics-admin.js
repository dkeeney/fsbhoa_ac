jQuery(document).ready(function($) {
    // --- Global Chart Variables ---
    let gateUsageChart = null;
    let peakHoursChart = null;
    let amenityUsageChart = null;

    // --- Analytics Logic ---
    function fetchAndRenderCharts() {
        const year = $('#analytics-year').val();
        const month = $('#analytics-month').val();

        fetchAndRenderSummary(year, month);

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
                renderAmenityUsageChart(data.amenityUsage);
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

    function renderAmenityUsageChart(amenityData) {
        const ctx = document.getElementById('amenity-usage-chart');
        if (!ctx) return;

        // Clean up labels by removing the "Amenity: " prefix
        const labels = amenityData.map(item => item.amenity_name_clean.replace('Amenity: ', ''));
        const data = amenityData.map(item => parseInt(item.count, 10));

        if (amenityUsageChart) {
            amenityUsageChart.destroy();
        }
        amenityUsageChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Check-ins',
                    data: data,
                    backgroundColor: 'rgba(75, 192, 192, 0.6)', // Greenish color
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
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
        $('#amenity-usage-chart-container').append('<p class="chart-note">* \'Courts\' amenity is access to all courts outside Lodge hours.</p>');
    }

    function fetchAndRenderSummary(year, month) {
        const tableBody = $('#attendance-summary-table tbody');
        
        $.ajax({
            url: `/wp-json/fsbhoa/v1/reports/daily-summary?year=${year}&month=${month}`,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', fsbhoa_reports_vars.rest_nonce);
            },
            success: function(data) {
                // 1. Update Headers with dynamic dates
                if (data.meta) {
                    $('#header-today').text(data.meta.today_label);
                    $('#header-yesterday').text(data.meta.yesterday_label);
                    $('#header-month').text(data.meta.month_label);
                }

                // 2. Build Table Rows
                tableBody.empty();
                
                if (data.summary && data.summary.length > 0) {
                    data.summary.forEach(row => {
                        // Bold the 'TOTALS' row
                        const isTotal = row.amenity === 'TOTALS';
                        const style = isTotal ? 'font-weight: bold; background-color: #f0f0f1;' : '';
                        
                        // Clean up amenity name (remove 'Amenity: ' prefix if present)
                        const name = row.amenity.replace('Amenity: ', '');

                        const html = `
                            <tr style="${style}">
                                <td>${name}</td>
                                <td>${row.today}</td>
                                <td>${row.yesterday}</td>
                                <td>${row.month}</td>
                            </tr>
                        `;
                        tableBody.append(html);
                    });
                } else {
                    tableBody.html('<tr><td colspan="4">No attendance data found for this period.</td></tr>');
                }
            },
            error: function(err) {
                console.error("Error fetching summary:", err);
                tableBody.html('<tr><td colspan="4" style="color:red;">Error loading summary data.</td></tr>');
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

