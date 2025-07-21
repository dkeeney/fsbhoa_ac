/**
 * Handles AJAX saving for general and service settings pages.
 */
jQuery(document).ready(function($) {

    'use strict';

    // --- Reusable Save Function ---
    function handleAjaxSave(button_id, container_id, action, nonce) {
        const saveButton = $(button_id);
        const feedbackSpan = $(container_id).find('#fsbhoa-save-feedback');

        feedbackSpan.text('Saving...').css('color', 'blue').show();
        saveButton.prop('disabled', true);

        const options = [];
        $(container_id + ' .form-table input, ' + container_id + ' .form-table select').each(function() {
            const input = $(this);
            const optionData = {
                name: input.attr('name'),
                value: input.is(':checkbox') ? (input.is(':checked') ? 'on' : 'off') : input.val()
            };
            options.push(optionData);
        });

        const dataToSend = {
            action: action,
            nonce: nonce,
            options: options
        };

        $.post(fsbhoa_settings_vars.ajax_url, dataToSend)
            .done(function(response) {
                if (response.success) {
                    feedbackSpan.text('Success! Reloading...').css('color', 'green');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    feedbackSpan.text('Error: ' + response.data).css('color', 'red');
                    saveButton.prop('disabled', false);
                }
            })
            .fail(function() {
                feedbackSpan.text('Request failed. Check network or server logs.').css('color', 'red');
                saveButton.prop('disabled', false);
            });
    }

    // --- General Settings Saver ---
    $('#fsbhoa-save-general-settings-button').on('click', function() {
        handleAjaxSave(
            '#fsbhoa-save-general-settings-button',
            '#fsbhoa-general-settings-page',
            'fsbhoa_save_general_settings',
            fsbhoa_settings_vars.general_nonce
        );
    });

    // --- Event Service Saver ---
    $('#fsbhoa-save-event-settings-button').on('click', function() {
        handleAjaxSave(
            '#fsbhoa-save-event-settings-button',
            '#fsbhoa-event-settings-page',
            'fsbhoa_save_event_settings',
            fsbhoa_settings_vars.event_nonce
        );
    });

    // --- Print Service Saver ---
    $('#fsbhoa-save-print-settings-button').on('click', function() {
        handleAjaxSave(
            '#fsbhoa-save-print-settings-button',
            '#fsbhoa-print-settings-page',
            'fsbhoa_save_print_settings',
            fsbhoa_settings_vars.print_nonce
        );
    });

    // --- Media Uploader for Print Page Logo ---
    $('#fsbhoa_ac_card_back_url-button').on('click', function(e) {
        e.preventDefault();
        const button = $(this);
        const inputField = $('#fsbhoa_ac_card_back_url');

        const frame = wp.media({
            title: 'Select or Upload Card Back Logo',
            button: { text: 'Use this image' },
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            inputField.val(attachment.url);
        });

        frame.open();
    });

});

