/**
 * LL Service Scheduler — Admin JS v2
 * Handles: settings repeaters, bookings table status/delete, slot count
 */
jQuery(function ($) {
    'use strict';

    var cfg = window.llSchedAdmin || {};

    /* ══════════════════════════════════════════
       SETTINGS PAGE — Generic repeater rows
    ══════════════════════════════════════════ */

    // Add row (property sizes, cities)
    $(document).on('click', '.ll-add', function () {
        var $btn  = $(this);
        var wrapId = $btn.data('target');
        var name   = $btn.data('name');
        var ph     = $btn.data('placeholder') || '';

        var $row = $('<div class="ll-row"></div>');
        $row.append('<input type="text" name="' + name + '" value="" class="regular-text" placeholder="' + ph + '">');
        $row.append('<button type="button" class="button ll-rm">✕</button>');
        $('#' + wrapId).append($row);
        $row.find('input').focus();
    });

    // Remove row
    $(document).on('click', '.ll-rm', function () {
        $(this).closest('.ll-row').fadeOut(150, function () { $(this).remove(); });
    });

    /* ── Time slots (settings page) ── */
    var slotIdx = $('#ll-slots-body tr').length;

    $('#ll-add-slot').on('click', function () {
        var row = '<tr>' +
            '<td><input type="text" name="time_slots[' + slotIdx + '][label]" class="widefat" placeholder="e.g. 9:00 AM – 10:00 AM"></td>' +
            '<td><input type="time" name="time_slots[' + slotIdx + '][start]" value="09:00"></td>' +
            '<td><input type="time" name="time_slots[' + slotIdx + '][end]"   value="10:00"></td>' +
            '<td><button type="button" class="button ll-rm-slot">Remove</button></td>' +
            '</tr>';
        $('#ll-slots-body').append(row);
        slotIdx++;
        updateSlotCount();
        $('#ll-slots-body tr:last-child input[type="text"]').focus();
    });

    $(document).on('click', '.ll-rm-slot', function () {
        $(this).closest('tr').fadeOut(150, function () {
            $(this).remove();
            updateSlotCount();
        });
    });

    function updateSlotCount() {
        $('#ll-slot-count').text($('#ll-slots-body tr').length);
    }

    /* ══════════════════════════════════════════
       BOOKINGS TABLE — Live status update
    ══════════════════════════════════════════ */

    $(document).on('change', '.ll-status-select', function () {
        var $sel   = $(this);
        var id     = parseInt($sel.data('id'), 10);
        var status = $sel.val();

        $sel.prop('disabled', true);

        $.post(cfg.ajaxUrl, {
            action: 'll_sched_update_booking_status',
            nonce:  cfg.nonce,
            id:     id,
            status: status
        }).done(function (res) {
            if (res.success) {
                // Update status badge in same row
                var $row = $('#ll-booking-row-' + id);
                $row.find('.ll-status-badge')
                    .attr('class', 'll-status-badge ll-status-' + status)
                    .text(status.charAt(0).toUpperCase() + status.slice(1));
                showFlash('Status updated ✓', 'success');
            } else {
                showFlash(res.data || 'Update failed.', 'error');
            }
        }).fail(function () {
            showFlash('Network error.', 'error');
        }).always(function () {
            $sel.prop('disabled', false);
        });
    });

    /* ── Delete booking ── */
    $(document).on('click', '.ll-btn-delete-booking', function () {
        var $btn = $(this);
        var id   = parseInt($btn.data('id'), 10);

        if (!confirm('Delete this booking? This cannot be undone.')) return;

        $btn.prop('disabled', true).text('Deleting...');

        $.post(cfg.ajaxUrl, {
            action: 'll_sched_delete_booking',
            nonce:  cfg.nonce,
            id:     id
        }).done(function (res) {
            if (res.success) {
                $('#ll-booking-row-' + id).fadeOut(300, function () { $(this).remove(); });
                showFlash('Booking deleted.', 'success');
            } else {
                showFlash(res.data || 'Delete failed.', 'error');
                $btn.prop('disabled', false).text('Delete');
            }
        });
    });

    /* ── Flash message helper ── */
    function showFlash(msg, type) {
        var cls = type === 'success' ? 'notice-success' : 'notice-error';
        var $el = $('<div class="notice ' + cls + ' is-dismissible" style="margin:10px 0;"><p>' + msg + '</p></div>');
        $('.ll-bookings-filters').before($el);
        setTimeout(function () { $el.fadeOut(400, function () { $el.remove(); }); }, 3000);
    }

    /* ── Unsaved changes warning ── */
    var dirty = false;
    $('form input, form select, form textarea').on('change input', function () { dirty = true; });
    $('form').on('submit', function () { dirty = false; });
    $(window).on('beforeunload', function () {
        if (dirty) return 'You have unsaved changes.';
    });
});
