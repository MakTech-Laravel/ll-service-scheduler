/**
 * LL Service Scheduler — Service Meta Box JS
 * Handles: custom schedule toggle, per-service time slots, days off rows
 */
jQuery(function ($) {
    'use strict';

    /* ── Toggle custom schedule panel ── */
    function toggleCustomFields() {
        var checked = $('#llSvcUseCustom').is(':checked');
        if (checked) {
            $('#llSvcCustomFields').slideDown(200);
        } else {
            $('#llSvcCustomFields').slideUp(200);
        }
    }

    $('#llSvcUseCustom').on('change', toggleCustomFields);
    toggleCustomFields(); // run on page load

    /* ── Per-service time slots ── */
    var svcSlotIdx = $('#llSvcSlotsBody tr').length;

    $('#llAddSvcSlot').on('click', function () {
        var row = '<tr>' +
            '<td><input type="text" name="_ll_svc_time_slots[' + svcSlotIdx + '][label]" class="widefat" placeholder="9:00 AM – 10:00 AM"></td>' +
            '<td><input type="time" name="_ll_svc_time_slots[' + svcSlotIdx + '][start]" value="09:00"></td>' +
            '<td><input type="time" name="_ll_svc_time_slots[' + svcSlotIdx + '][end]"   value="10:00"></td>' +
            '<td><button type="button" class="button ll-rm-svc-slot">✕</button></td>' +
            '</tr>';
        $('#llSvcSlotsBody').append(row);
        svcSlotIdx++;
        $('#llSvcSlotsBody tr:last-child input[type="text"]').focus();
    });

    $(document).on('click', '.ll-rm-svc-slot', function () {
        $(this).closest('tr').fadeOut(150, function () { $(this).remove(); });
    });

    /* ── Days off rows ── */
    var daysOffIdx = $('#llSvcDaysOffWrap .ll-days-off-row').length;

    $('#llAddDaysOff').on('click', function () {
        var row = '<div class="ll-days-off-row">' +
            '<input type="text" name="_ll_svc_days_off[' + daysOffIdx + '][label]" placeholder="Label (optional)" style="width:180px;">' +
            ' <label>From: <input type="date" name="_ll_svc_days_off[' + daysOffIdx + '][start]"></label>' +
            ' <label>To: <input type="date" name="_ll_svc_days_off[' + daysOffIdx + '][end]"></label>' +
            ' <button type="button" class="button ll-rm-days-off">Remove</button>' +
            '</div>';
        $('#llSvcDaysOffWrap').append(row);
        daysOffIdx++;
    });

    $(document).on('click', '.ll-rm-days-off', function () {
        $(this).closest('.ll-days-off-row').fadeOut(150, function () { $(this).remove(); });
    });

    /* ── Day label toggle on checkbox change ── */
    $(document).on('change', '.ll-day-checkboxes input[type="checkbox"]', function () {
        var $lbl = $(this).closest('.ll-day-label');
        if ($(this).is(':checked')) {
            $lbl.addClass('ll-day-blocked').removeClass('');
        } else {
            $lbl.removeClass('ll-day-blocked');
        }
    });
});
