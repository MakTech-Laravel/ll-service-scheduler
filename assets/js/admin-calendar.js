/**
 * LL Service Scheduler — Admin blocked dates calendar (Settings → Calendar & Days)
 */
jQuery(function ($) {
    'use strict';

    var cfg = window.llSchedAdminCal || {};
    var gridEl = document.getElementById('llAdminCalGrid');
    if (!gridEl) {
        return;
    }

    var calYear;
    var calMonth;
    var blockMode = 'single';
    var rangeStart = null;
    var rangeEnd = null;
    var daysOffIdx = $('#llAdminDaysOffList .ll-admin-days-off-row').length;

    function formatDate(y, m, d) {
        return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }

    function getTodayStr() {
        var now = new Date();
        return formatDate(now.getFullYear(), now.getMonth(), now.getDate());
    }

    function getSavedEntries() {
        var entries = [];
        $('#llAdminDaysOffList .ll-admin-days-off-row').each(function () {
            entries.push({
                label: $(this).find('input[name*="[label]"]').val() || '',
                start: $(this).find('input[name*="[start]"]').val() || '',
                end: $(this).find('input[name*="[end]"]').val() || ''
            });
        });
        return entries;
    }

    function dateInAnyEntry(dateStr, entries) {
        for (var i = 0; i < entries.length; i++) {
            var start = entries[i].start;
            var end = entries[i].end || start;
            if (start && dateStr >= start && dateStr <= end) {
                return true;
            }
        }
        return false;
    }

    function dateInSelection(dateStr) {
        if (blockMode === 'single') {
            return rangeStart === dateStr;
        }
        if (!rangeStart) {
            return false;
        }
        if (!rangeEnd) {
            return dateStr === rangeStart;
        }
        var start = rangeStart <= rangeEnd ? rangeStart : rangeEnd;
        var end = rangeStart <= rangeEnd ? rangeEnd : rangeStart;
        return dateStr >= start && dateStr <= end;
    }

    function updateHint() {
        var $hint = $('#llAdminCalHint');
        if (!$hint.length) {
            return;
        }
        if (blockMode === 'single') {
            $hint.text(rangeStart
                ? 'Selected: ' + rangeStart + '. Click "Add blocked date(s)" to save.'
                : 'Click a day to select it, then click Add blocked date(s).');
        } else {
            if (rangeStart && rangeEnd) {
                $hint.text('Selected range: ' + rangeStart + ' to ' + rangeEnd + '. Click "Add blocked date(s)" to save.');
            } else if (rangeStart) {
                $hint.text('Start: ' + rangeStart + '. Click the end date.');
            } else {
                $hint.text('Click the start date, then the end date, then Add blocked date(s).');
            }
        }
    }

    function renderCalendar() {
        var MONTH_NAMES = [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December'
        ];

        $('#llAdminCalTitle').text(MONTH_NAMES[calMonth] + ' ' + calYear);
        gridEl.innerHTML = '';

        var firstDay = new Date(calYear, calMonth, 1).getDay();
        var daysInMo = new Date(calYear, calMonth + 1, 0).getDate();
        var startOffset = firstDay === 0 ? 6 : firstDay - 1;
        var saved = getSavedEntries();
        var todayStr = getTodayStr();

        for (var i = 0; i < startOffset; i++) {
            var blank = document.createElement('div');
            blank.className = 'll-admin-cal-cell ll-admin-cal-empty';
            gridEl.appendChild(blank);
        }

        for (var d = 1; d <= daysInMo; d++) {
            var dateStr = formatDate(calYear, calMonth, d);
            var isPast = dateStr < todayStr;
            var cell = document.createElement(isPast ? 'div' : 'button');
            if (!isPast) {
                cell.type = 'button';
            }
            cell.className = 'll-admin-cal-cell';
            cell.textContent = d;
            cell.setAttribute('data-date', dateStr);

            if (isPast) {
                cell.classList.add('ll-admin-cal-disabled');
                cell.setAttribute('aria-disabled', 'true');
            }

            if (dateInAnyEntry(dateStr, saved)) {
                cell.classList.add('ll-admin-cal-saved');
            }
            if (!isPast && dateInSelection(dateStr)) {
                cell.classList.add('ll-admin-cal-selected');
            }

            if (!isPast) {
                cell.addEventListener('click', function () {
                    onDayClick(this.getAttribute('data-date'));
                });
            }

            gridEl.appendChild(cell);
        }

        updateHint();
    }

    function onDayClick(dateStr) {
        if (dateStr < getTodayStr()) {
            return;
        }
        if (blockMode === 'single') {
            rangeStart = dateStr;
            rangeEnd = null;
        } else {
            if (!rangeStart || (rangeStart && rangeEnd)) {
                rangeStart = dateStr;
                rangeEnd = null;
            } else {
                rangeEnd = dateStr;
                if (rangeEnd < rangeStart) {
                    var tmp = rangeStart;
                    rangeStart = rangeEnd;
                    rangeEnd = tmp;
                }
            }
        }
        renderCalendar();
    }

    function formatDisplay(start, end) {
        if (start === end) {
            return start;
        }
        return start + ' – ' + end;
    }

    function entryExists(start, end) {
        var exists = false;
        $('#llAdminDaysOffList .ll-admin-days-off-row').each(function () {
            var s = $(this).find('input[name*="[start]"]').val();
            var e = $(this).find('input[name*="[end]"]').val();
            if (s === start && e === end) {
                exists = true;
                return false;
            }
        });
        return exists;
    }

    function addEntry(label, start, end) {
        if (!start || entryExists(start, end)) {
            return;
        }

        var display = formatDisplay(start, end);
        if (label) {
            display = label + ' — ' + display;
        }

        var row = $('<div class="ll-admin-days-off-row"></div>');
        row.attr('data-start', start).attr('data-end', end);
        row.append('<span class="ll-admin-days-off-display">' + $('<span>').text(display).html() + '</span>');
        row.append('<input type="hidden" name="days_off[' + daysOffIdx + '][label]" value="' + $('<span>').text(label).html() + '">');
        row.append('<input type="hidden" name="days_off[' + daysOffIdx + '][start]" value="' + start + '">');
        row.append('<input type="hidden" name="days_off[' + daysOffIdx + '][end]" value="' + end + '">');
        row.append('<button type="button" class="button ll-admin-rm-days-off">Remove</button>');
        $('#llAdminDaysOffList').append(row);
        daysOffIdx++;

        rangeStart = null;
        rangeEnd = null;
        $('#llAdminBlockLabel').val('');
        renderCalendar();
    }

    $('input[name="ll_admin_block_mode_ui"]').on('change', function () {
        blockMode = $(this).val() === 'range' ? 'range' : 'single';
        rangeStart = null;
        rangeEnd = null;
        renderCalendar();
    });

    $('#llAdminCalPrev').on('click', function () {
        calMonth -= 1;
        if (calMonth < 0) {
            calMonth = 11;
            calYear -= 1;
        }
        renderCalendar();
    });

    $('#llAdminCalNext').on('click', function () {
        calMonth += 1;
        if (calMonth > 11) {
            calMonth = 0;
            calYear += 1;
        }
        renderCalendar();
    });

    $('#llAdminAddBlockedDate').on('click', function () {
        var label = ($('#llAdminBlockLabel').val() || '').trim();
        if (blockMode === 'single') {
            if (!rangeStart) {
                return;
            }
            addEntry(label, rangeStart, rangeStart);
            return;
        }
        if (!rangeStart || !rangeEnd) {
            return;
        }
        addEntry(label, rangeStart, rangeEnd);
    });

    $(document).on('click', '.ll-admin-rm-days-off', function () {
        $(this).closest('.ll-admin-days-off-row').fadeOut(150, function () {
            $(this).remove();
            reindexDaysOff();
            renderCalendar();
        });
    });

    function reindexDaysOff() {
        $('#llAdminDaysOffList .ll-admin-days-off-row').each(function (idx) {
            $(this).find('input[name*="[label]"]').attr('name', 'days_off[' + idx + '][label]');
            $(this).find('input[name*="[start]"]').attr('name', 'days_off[' + idx + '][start]');
            $(this).find('input[name*="[end]"]').attr('name', 'days_off[' + idx + '][end]');
        });
        daysOffIdx = $('#llAdminDaysOffList .ll-admin-days-off-row').length;
    }

    var now = new Date();
    calYear = now.getFullYear();
    calMonth = now.getMonth();
    renderCalendar();
});
