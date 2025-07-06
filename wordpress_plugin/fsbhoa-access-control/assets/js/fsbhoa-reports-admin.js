jQuery(document).ready(function($) {

    // --- DataTable Initialization ---
    const accessLogTable = $('#fsbhoa-access-log-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "/wp-json/fsbhoa/v1/reports/access-log",
            "type": "POST",
            "headers": {
                "X-WP-Nonce": fsbhoa_reports_vars.rest_nonce
            },
            "data": function (d) {
                // Append custom filter data to the request
                d.start_date = $('#start_date').val();
                d.end_date = $('#end_date').val();
                d.gate_id = $('#gate_id').val();
                d.show_photo = $('#show-photo').is(':checked');
            }
        },
        "columns": [
            { "data": "event_timestamp" },
            {
                "data": "photo",
                "orderable": false,
                "className": "photo-column",
                "render": function(data, type, row) {
                    if (data) {
                        return '<img src="data:image/jpeg;base64,' + data + '" alt="photo">';
                    }
                    return '';
                }
            },
            { "data": "cardholder" },
            { "data": "resident_type", "className": "type-column" },
            { "data": "property" },
            { "data": "gate_name" },
            { "data": "access_granted", "orderable": false },
            { "data": "event_description", "orderable": false }
        ],
        "columnDefs": [
            {
                "targets": 1, // Photo column is now the second column (index 1)
                "visible": false // Initially hidden
            }
        ],
        "order": [[ 0, "desc" ]], // Default sort by the first column (timestamp)
        "searching": true,
        "dom": 'rtip',
        "pageLength": 100,
        "lengthMenu": [100, 200, 500, 1000]
    });

    // --- Custom Control Handlers ---

    // Live search box
    $('#fsbhoa-live-search').on('keyup', function() {
        accessLogTable.search($(this).val()).draw();
    });

    // Standard filters
    $('#start_date, #end_date, #gate_id').on('change', function() {
        accessLogTable.draw();
    });

    // Custom page length control
    $('#fsbhoa-page-length').on('change', function() {
        accessLogTable.page.len($(this).val()).draw();
    });

        // Photo checkbox now targets column index 1
    $('#show-photo').on('change', function() {
        const column = accessLogTable.column(1);
        column.visible($(this).is(':checked'));
        // Redraw the table to fetch photo data from the server
        accessLogTable.draw();
    });

    // Clear Filters Button now targets column index 1
    $('#fsbhoa-clear-filters').on('click', function() {
        // ... same ...
        $('#start_date, #end_date, #fsbhoa-live-search').val('');
        $('#gate_id').val('');
        $('#show-photo').prop('checked', false);
        accessLogTable.column(1).visible(false);
        accessLogTable.search('').draw();
    });

    // ** handler for the Export button **
    $('#fsbhoa-export-button').on('click', function(e) {
        e.preventDefault();

        // Get current filters
        const startDate = $('#start_date').val();
        const endDate = $('#end_date').val();
        const gateId = $('#gate_id').val();
        const search = $('#fsbhoa-live-search').val();

        // Build the URL with query parameters
        const nonce = fsbhoa_reports_vars.export_nonce;
        let url = `/wp-admin/admin-post.php?action=fsbhoa_export_access_log&nonce=${nonce}`;

        if (startDate) url += `&start_date=${encodeURIComponent(startDate)}`;
        if (endDate) url += `&end_date=${encodeURIComponent(endDate)}`;
        if (gateId) url += `&gate_id=${encodeURIComponent(gateId)}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;

        // Trigger the download by setting the window location
        window.location.href = url;
    });

});

