/**
 * Handles interactivity for the Monitor Settings page.
 * - Media uploader for the map image.
 * - Draggable gate markers for position setting.
 */
jQuery(document).ready(function($) {

    'use strict';

    const mapUploaderButton = $('#fsbhoa_monitor_map_url-button');
    const mapHiddenInput = $('#fsbhoa_monitor_map_url');
    const mapEditorImage = $('#fsbhoa-map-editor-bg');

    // --- Part 1: Media Uploader ---
    mapUploaderButton.on('click', function(e) {
        e.preventDefault();
        const frame = wp.media({
            title: 'Select or Upload a Map Image',
            button: {
                text: 'Use this image'
            },
            library: {
                type: 'image'
            },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            mapHiddenInput.val(attachment.url);
            mapEditorImage.attr('src', attachment.url);
        });
        frame.open();
    });

    // --- Part 2: Gate Position Editor ---
    const mapContainer = $('#fsbhoa-map-editor-container');
    const legendList = $('#fsbhoa-gate-legend ol');
    const GATES_API_URL = '/wp-json/fsbhoa/v1/monitor/gates';
    let gatePositions = {};

    function initializeGateEditor() {
        if (!mapContainer.length) return;

        $.getJSON(GATES_API_URL, function(gates) {
                mapContainer.find('.gate-marker').remove();
                legendList.empty();

                if (!gates || gates.length === 0) {
                    legendList.append('<li>No gates configured. Please add gates under Hardware Management.</li>');
                    return;
                }

                gates.forEach(function(gate, index) {
                    const markerNumber = index + 1;
                    gatePositions[gate.door_record_id] = {
                        x: gate.map_x,
                        y: gate.map_y
                    };

                    const marker = $('<div class="gate-marker"></div>')
                        .attr('id', 'gate-marker-' + gate.door_record_id)
                        .attr('data-id', gate.door_record_id)
                        .attr('title', gate.friendly_name)
                        .css({
                            left: gate.map_x + '%',
                            top: gate.map_y + '%'
                        })
                        .text(markerNumber);

                    mapContainer.append(marker);

                    const listItem = $('<li></li>').html(`<b>${markerNumber}:</b> ${gate.friendly_name}`);
                    legendList.append(listItem);
                });
                makeMarkersDraggable();
            })
            .fail(function() {
                legendList.empty().append('<li>Could not load gate data. Check server logs.</li>');
            });
    }

    function makeMarkersDraggable() {
        let activeMarker = null;
        mapContainer.on('mousedown', '.gate-marker', function(e) {
            e.preventDefault();
            activeMarker = $(this);
        });
        $(document).on('mousemove', function(e) {
            if (!activeMarker) return;
            e.preventDefault();
            const containerRect = mapContainer[0].getBoundingClientRect();
            let x = e.clientX - containerRect.left;
            let y = e.clientY - containerRect.top;
            let xPercent = Math.max(0, Math.min(100, (x / containerRect.width) * 100));
            let yPercent = Math.max(0, Math.min(100, (y / containerRect.height) * 100));
            activeMarker.css({
                left: xPercent + '%',
                top: yPercent + '%'
            });
            const gateId = activeMarker.data('id');
            gatePositions[gateId] = {
                x: xPercent,
                y: yPercent
            };
        });
        $(document).on('mouseup', function(e) {
            activeMarker = null;
        });
    }


    // Find the main Save button in the form. The ID is 'submit' by default.
    const mainSaveButton = $('#submit');

    mainSaveButton.on('click', function(e) {
        e.preventDefault(); // Stop the form from submitting immediately

        const feedback = $('#fsbhoa-save-positions-feedback');
        feedback.text('Saving...').css('color', 'blue').show();

        // Step 1: Prepare and send the gate positions via AJAX.
        const gateData = {
            action: 'fsbhoa_save_gate_positions',
            nonce: fsbhoa_monitor_settings_vars.nonce,
            gates: Object.keys(gatePositions).map(id => ({
                id: id,
                x: Math.round(gatePositions[id].x),
                y: Math.round(gatePositions[id].y)
            }))
        };

        $.post(fsbhoa_monitor_settings_vars.ajax_url, gateData)
            .done(function(response) {
                if (response.success) {
                    console.log('Gate positions saved successfully.');
                    // Step 2: Gate positions saved, now submit the main form for other settings.
                    $('form[action="options.php"]').submit();
                } else {
                    feedback.text('Error saving gate positions: ' + response.data).css('color', 'red');
                }
            })
            .fail(function() {
                feedback.text('Request failed while saving gate positions.').css('color', 'red');
            });
    });


    initializeGateEditor();
});

