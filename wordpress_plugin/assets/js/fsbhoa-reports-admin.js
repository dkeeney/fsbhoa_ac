jQuery(document).ready(function($) {

    const accessLogTable = $('#fsbhoa-access-log-table').DataTable({
        "processing": true,
        "serverSide": true,
        "ajax": {
            "url": "/wp-json/fsbhoa/v1/reports/access-log",
            "type": "POST",
            "headers": { "X-WP-Nonce": fsbhoa_reports_vars.rest_nonce },
            "data": function (d) {
                d.start_date = $('#start_date').val();
                d.end_date = $('#end_date').val();
                d.gate_id = $('#gate_id').val();
                d.show_photo = $('#show-photo').is(':checked');
            }
        },
        "columns": [
            { "data": null, "defaultContent": "", "className": "select-checkbox", "orderable": false },
            { "data": "event_timestamp" },
            { "data": "photo", "orderable": false, "className": "photo-column", "render": function(data, type, row) { if (data) { return '<img src="data:image/jpeg;base64,' + data + '" alt="photo">'; } return ''; } },
            { "data": "cardholder" },
            { "data": "resident_type", "className": "type-column" },
            { "data": "property" },
            { "data": "gate_name" },
            { "data": "access_granted", "orderable": false },
            { "data": "event_description", "orderable": false }
        ],
        "columnDefs": [ { "targets": 2, "visible": false } ],
        "order": [[ 1, "desc" ]],
        "searching": true,
        "dom": 'rtip',
        "pageLength": 100,
        "lengthMenu": [100, 200, 500, 1000],
        "select": { "style": 'os', "selector": 'td:first-child' }
    });

    // --- Custom Control Handlers ---
    $('#fsbhoa-live-search').on('keyup', function() { accessLogTable.search($(this).val()).draw(); });
    $('#start_date, #end_date, #gate_id').on('change', function() { accessLogTable.draw(); });
    $('#fsbhoa-page-length').on('change', function() { accessLogTable.page.len($(this).val()).draw(); });
    $('#show-photo').on('change', function() {
        accessLogTable.column(2).visible($(this).is(':checked')).draw();
    });
    $('#fsbhoa-clear-filters').on('click', function() {
        $('#start_date, #end_date, #fsbhoa-live-search').val('');
        $('#gate_id').val('');
        $('#show-photo').prop('checked', false);
        accessLogTable.column(2).visible(false);
        accessLogTable.search('').draw();
    });

    // --- NEW: Smart Export Button Handler ---
    $('#fsbhoa-export-button').on('click', function(e) {
        e.preventDefault();

        const selectedData = accessLogTable.rows({ selected: true }).data().toArray();

        // --- Path 1: Export Selected Rows ---
        if (selectedData.length > 0) {
            const logIds = selectedData.map(row => row.log_id);

            const form = $('<form>', {
                'method': 'POST',
                'action': '/wp-admin/admin-post.php'
            }).appendTo('body');

            form.append($('<input>', { 'type': 'hidden', 'name': 'action', 'value': 'fsbhoa_export_selected_logs' }));
            form.append($('<input>', { 'type': 'hidden', 'name': 'nonce', 'value': fsbhoa_reports_vars.export_nonce }));
            form.append($('<input>', { 'type': 'hidden', 'name': 'log_ids', 'value': logIds.join(',') }));

            form.submit();

        // --- Path 2: Export All Filtered Rows ---
        } else {
            const startDate = $('#start_date').val();
            const endDate = $('#end_date').val();
            const gateId = $('#gate_id').val();
            const search = $('#fsbhoa-live-search').val();
            const nonce = fsbhoa_reports_vars.export_nonce;

            let url = `/wp-admin/admin-post.php?action=fsbhoa_export_access_log&nonce=${nonce}`;
            if (startDate) url += `&start_date=${encodeURIComponent(startDate)}`;
            if (endDate) url += `&end_date=${encodeURIComponent(endDate)}`;
            if (gateId) url += `&gate_id=${encodeURIComponent(gateId)}`;
            if (search) url += `&search=${encodeURIComponent(search)}`;
            
            window.location.href = url;
        }
    });
});
