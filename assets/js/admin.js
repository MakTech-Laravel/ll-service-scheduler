/**
 * LL Service Scheduler — Admin JS
 * Handles dynamic add/remove rows for property sizes, cities, and time slots.
 */
jQuery(function ($) {
    'use strict';

    /* ──────────────────────────────────────────
       Generic "add row" buttons
       Used for: property sizes, cities
       Markup: <button class="ll-add" data-target="wrap-id" data-name="field_name[]" data-placeholder="hint">
    ────────────────────────────────────────── */
    $(document).on('click', '.ll-add', function () {
        var $btn  = $(this);
        var wrapId      = $btn.data('target');
        var fieldName   = $btn.data('name');
        var placeholder = $btn.data('placeholder') || '';

        var $row = $('<div>', { class: 'll-row', style: 'display:flex;gap:8px;margin-bottom:6px;align-items:center;' });
        var $input = $('<input>', {
            type: 'text',
            name: fieldName,
            class: 'regular-text',
            placeholder: placeholder,
            val: ''
        });
        var $rm = $('<button>', {
            type: 'button',
            class: 'button ll-rm',
            title: 'Remove',
            text: '✕'
        });

        $row.append($input).append($rm);
        $('#' + wrapId).append($row);
        $input.focus();
    });

    /* ──────────────────────────────────────────
       Generic "remove row" buttons
    ────────────────────────────────────────── */
    $(document).on('click', '.ll-rm', function () {
        $(this).closest('.ll-row').fadeOut(150, function () { $(this).remove(); });
    });

    /* ──────────────────────────────────────────
       Time Slots: add new row
    ────────────────────────────────────────── */
    var slotIndex = parseInt($('#ll-slots-body tr').length, 10);

    $('#ll-add-slot').on('click', function () {
        var html =
            '<tr>' +
            '<td><input type="text" name="time_slots[' + slotIndex + '][label]" ' +
                'class="widefat" placeholder="e.g. 9:00 AM – 10:00 AM"></td>' +
            '<td><input type="time" name="time_slots[' + slotIndex + '][start]" value="09:00"></td>' +
            '<td><input type="time" name="time_slots[' + slotIndex + '][end]"   value="10:00"></td>' +
            '<td><button type="button" class="button ll-rm-slot">Remove</button></td>' +
            '</tr>';

        $('#ll-slots-body').append(html);
        slotIndex++;
        updateSlotCount();
        // Focus the new label input
        $('#ll-slots-body tr:last-child input[type="text"]').focus();
    });

    /* ──────────────────────────────────────────
       Time Slots: remove row
    ────────────────────────────────────────── */
    $(document).on('click', '.ll-rm-slot', function () {
        $(this).closest('tr').fadeOut(150, function () {
            $(this).remove();
            updateSlotCount();
        });
    });

    function updateSlotCount() {
        var count = $('#ll-slots-body tr').length;
        $('#ll-slot-count').text(count);
    }

    /* ──────────────────────────────────────────
       Confirm before leaving with unsaved changes
       (simple dirty-tracking)
    ────────────────────────────────────────── */
    var isDirty = false;
    $('form input, form select, form textarea').on('change input', function () {
        isDirty = true;
    });
    $('form').on('submit', function () {
        isDirty = false;
    });
    $(window).on('beforeunload', function () {
        if (isDirty) return 'You have unsaved changes. Are you sure you want to leave?';
    });

});
