/**
 * LL Service Scheduler — Frontend JS
 * Pure vanilla JS, no jQuery dependency.
 * Config is injected via wp_localize_script as window.llSched
 */
(function () {
    'use strict';

    /* ══════════════════════════════════════════
       CONFIG  (injected from PHP via wp_localize_script)
    ══════════════════════════════════════════ */
    var cfg            = window.llSched || {};
    var AVAILABLE_DAYS = cfg.availableDays  || [0, 6];   // JS day: 0=Sun, 6=Sat
    var TIME_SLOTS     = cfg.timeSlots      || [];
    var MODE           = cfg.selectionMode  || 'multiple'; // 'single' | 'multiple'
    var AJAX_URL       = cfg.ajaxUrl        || '';
    var NONCE          = cfg.nonce          || '';

    /* ══════════════════════════════════════════
       STATE
    ══════════════════════════════════════════ */
    var selectedServices = [];  // [{id, title, price}]
    var selectedDate     = null; // 'YYYY-MM-DD'
    var selectedTime     = null; // {start, end, label}
    var calYear, calMonth;

    /* ══════════════════════════════════════════
       DOM REFERENCES  (set once on DOMContentLoaded)
    ══════════════════════════════════════════ */
    var elBooking, elCalGrid, elCalTitle, elTimes, elTimesGrid,
        elTotal, elSummaryItems, elSubmit, elMsg;

    /* ══════════════════════════════════════════
       INIT
    ══════════════════════════════════════════ */
    function init() {
        if (!document.getElementById('llScheduler')) return;

        elBooking     = document.getElementById('llBooking');
        elCalGrid     = document.getElementById('llCalGrid');
        elCalTitle    = document.getElementById('llCalTitle');
        elTimes       = document.getElementById('llTimes');
        elTimesGrid   = document.getElementById('llTimesGrid');
        elTotal       = document.getElementById('llTotal');
        elSummaryItems = document.getElementById('llSummaryItems');
        elSubmit      = document.getElementById('llSubmit');
        elMsg         = document.getElementById('llMsg');

        initServiceCheckboxes();
        initCalendar();
        initSubmit();
    }

    /* ══════════════════════════════════════════
       SERVICE SELECTION
    ══════════════════════════════════════════ */
    function initServiceCheckboxes() {
        var items = document.querySelectorAll('.ll-svc-item');
        items.forEach(function (item) {
            var cb = item.querySelector('.ll-svc-cb');

            // Clicking anywhere on the card toggles the checkbox
            item.addEventListener('click', function (e) {
                if (e.target === cb) return; // let default checkbox behaviour run
                cb.checked = !cb.checked;
                onServiceChange(item, cb);
            });

            cb.addEventListener('change', function () {
                onServiceChange(item, cb);
            });
        });
    }

    function onServiceChange(item, cb) {
        var id    = parseInt(item.dataset.id, 10);
        var title = item.dataset.title || '';
        var price = parseFloat(item.dataset.price) || 0;

        if (cb.checked) {
            if (MODE === 'single') {
                // Uncheck every other service
                document.querySelectorAll('.ll-svc-item').forEach(function (otherItem) {
                    var otherId = parseInt(otherItem.dataset.id, 10);
                    if (otherId === id) return;
                    var otherCb = otherItem.querySelector('.ll-svc-cb');
                    if (otherCb) otherCb.checked = false;
                    otherItem.classList.remove('ll-checked');
                });
                selectedServices = [];
            }

            // Add to selection if not already in
            if (!selectedServices.find(function (s) { return s.id === id; })) {
                selectedServices.push({ id: id, title: title, price: price });
            }
            item.classList.add('ll-checked');

        } else {
            selectedServices = selectedServices.filter(function (s) { return s.id !== id; });
            item.classList.remove('ll-checked');
        }

        updateSummary();
        toggleBookingSection();
    }

    function toggleBookingSection() {
        if (selectedServices.length > 0) {
            if (elBooking.hidden) {
                elBooking.hidden = false;
                // Scroll into view on first reveal (smooth)
                setTimeout(function () {
                    elBooking.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 80);
            }
        } else {
            elBooking.hidden = true;
            // Reset date/time selections when no services chosen
            selectedDate = null;
            selectedTime = null;
            elTimes.hidden = true;
        }
    }

    function updateSummary() {
        var total = 0;
        var html  = '';
        selectedServices.forEach(function (s) {
            total += s.price;
            html += '<div class="ll-summary-item">' +
                        '<span>' + escHtml(s.title) + '</span>' +
                        '<span>$' + s.price.toFixed(2) + '</span>' +
                    '</div>';
        });
        if (elSummaryItems) elSummaryItems.innerHTML = html;
        if (elTotal)        elTotal.textContent = total.toFixed(2);
    }

    /* ══════════════════════════════════════════
       CALENDAR
    ══════════════════════════════════════════ */
    function initCalendar() {
        var now  = new Date();
        calYear  = now.getFullYear();
        calMonth = now.getMonth(); // 0-indexed

        var btnPrev = document.getElementById('llCalPrev');
        var btnNext = document.getElementById('llCalNext');

        if (btnPrev) {
            btnPrev.addEventListener('click', function () {
                calMonth -= 1;
                if (calMonth < 0) { calMonth = 11; calYear -= 1; }
                renderCalendar();
            });
        }
        if (btnNext) {
            btnNext.addEventListener('click', function () {
                calMonth += 1;
                if (calMonth > 11) { calMonth = 0; calYear += 1; }
                renderCalendar();
            });
        }

        renderCalendar();
    }

    function renderCalendar() {
        var MONTH_NAMES = [
            'January','February','March','April','May','June',
            'July','August','September','October','November','December'
        ];

        if (elCalTitle) {
            elCalTitle.textContent = MONTH_NAMES[calMonth] + ' ' + calYear;
        }

        var today     = new Date();
        today.setHours(0, 0, 0, 0);

        var firstDay  = new Date(calYear, calMonth, 1).getDay(); // 0=Sun
        var daysInMo  = new Date(calYear, calMonth + 1, 0).getDate();

        elCalGrid.innerHTML = '';

        // Monday-first layout: shift Sunday (0) → position 6
        var startOffset = (firstDay === 0) ? 6 : firstDay - 1;

        // Empty leading cells
        for (var i = 0; i < startOffset; i++) {
            var blank = document.createElement('div');
            blank.className = 'll-cal-cell ll-cal-empty';
            elCalGrid.appendChild(blank);
        }

        // Day cells
        for (var d = 1; d <= daysInMo; d++) {
            var cell      = document.createElement('div');
            cell.className = 'll-cal-cell';
            cell.textContent = d;

            var thisDate  = new Date(calYear, calMonth, d);
            var jsDay     = thisDate.getDay(); // 0=Sun ... 6=Sat
            var dateStr   = formatDate(calYear, calMonth, d);
            var isPast    = thisDate < today;
            var isOpen    = AVAILABLE_DAYS.indexOf(jsDay) !== -1;

            if (isPast || !isOpen) {
                cell.classList.add('ll-cal-disabled');
                cell.setAttribute('aria-disabled', 'true');
            } else {
                cell.classList.add('ll-cal-available');
                cell.setAttribute('tabindex', '0');
                cell.setAttribute('role', 'button');
                cell.setAttribute('aria-label', dateStr);

                if (dateStr === selectedDate) {
                    cell.classList.add('ll-cal-selected');
                }

                (function (ds) {
                    cell.addEventListener('click', function () {
                        onDateSelect(ds);
                        // Highlight selected cell
                        elCalGrid.querySelectorAll('.ll-cal-cell').forEach(function (c) {
                            c.classList.remove('ll-cal-selected');
                        });
                        cell.classList.add('ll-cal-selected');
                    });
                    cell.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            cell.click();
                        }
                    });
                }(dateStr));
            }

            elCalGrid.appendChild(cell);
        }
    }

    function onDateSelect(dateStr) {
        selectedDate = dateStr;
        selectedTime = null;
        renderTimeSlots();
        elTimes.hidden = false;
        // Scroll time slots into view
        setTimeout(function () {
            elTimes.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 80);
    }

    function formatDate(y, m, d) {
        return y + '-' +
               String(m + 1).padStart(2, '0') + '-' +
               String(d).padStart(2, '0');
    }

    /* ══════════════════════════════════════════
       TIME SLOTS
    ══════════════════════════════════════════ */
    function renderTimeSlots() {
        elTimesGrid.innerHTML = '';
        selectedTime = null;

        if (!TIME_SLOTS.length) {
            elTimesGrid.innerHTML = '<p style="color:#888;font-style:italic;">No time slots configured. Please contact the administrator.</p>';
            return;
        }

        TIME_SLOTS.forEach(function (slot) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'll-time-btn';
            btn.textContent = slot.label || (slot.start + ' – ' + slot.end);

            btn.addEventListener('click', function () {
                elTimesGrid.querySelectorAll('.ll-time-btn').forEach(function (b) {
                    b.classList.remove('ll-time-selected');
                });
                btn.classList.add('ll-time-selected');
                selectedTime = {
                    start: slot.start,
                    end:   slot.end,
                    label: slot.label || (slot.start + ' – ' + slot.end)
                };
            });

            elTimesGrid.appendChild(btn);
        });
    }

    /* ══════════════════════════════════════════
       FORM SUBMISSION
    ══════════════════════════════════════════ */
    function initSubmit() {
        elSubmit.addEventListener('click', function () {
            if (!validate()) return;
            submit();
        });
    }

    function validate() {
        if (!selectedServices.length) {
            showMsg('Please select at least one service.', 'error');
            return false;
        }
        if (!selectedDate) {
            showMsg('Please select a booking date on the calendar.', 'error');
            // Scroll to calendar
            if (elCalGrid) elCalGrid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        if (!selectedTime) {
            showMsg('Please select a time slot.', 'error');
            if (elTimes) elTimes.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        return true;
    }

    function submit() {
        elSubmit.disabled    = true;
        elSubmit.textContent = 'Processing…';
        showMsg('', '');

        var body = new FormData();
        body.append('action', 'll_sched_book');
        body.append('nonce',  NONCE);

        selectedServices.forEach(function (s) {
            body.append('services[]', s.id);
        });

        body.append('date',          selectedDate);
        body.append('time',          selectedTime.start + '|' + selectedTime.end + '|' + selectedTime.label);
        body.append('property_size', document.getElementById('llPropSize') ? document.getElementById('llPropSize').value : '');
        body.append('city',          document.getElementById('llCity')     ? document.getElementById('llCity').value     : '');
        body.append('address',       document.getElementById('llAddress')  ? document.getElementById('llAddress').value  : '');
        body.append('notes',         document.getElementById('llNotes')    ? document.getElementById('llNotes').value    : '');

        fetch(AJAX_URL, { method: 'POST', body: body })
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (data.success) {
                    showMsg('✓ Added to cart! Redirecting to checkout…', 'success');
                    setTimeout(function () {
                        window.location.href = data.data.redirect;
                    }, 800);
                } else {
                    var msg = (typeof data.data === 'string') ? data.data : 'Something went wrong. Please try again.';
                    showMsg(msg, 'error');
                    elSubmit.disabled    = false;
                    elSubmit.textContent = 'Add to Cart →';
                }
            })
            .catch(function (err) {
                showMsg('Network error. Please check your connection and try again.', 'error');
                elSubmit.disabled    = false;
                elSubmit.textContent = 'Add to Cart →';
            });
    }

    /* ══════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════ */
    function showMsg(text, type) {
        elMsg.textContent = text;
        elMsg.className   = 'll-msg' + (type ? ' ll-msg-' + type : '');
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    /* String.prototype.padStart polyfill for very old browsers */
    if (!String.prototype.padStart) {
        String.prototype.padStart = function (targetLength, padString) {
            padString = padString || ' ';
            var str = String(this);
            while (str.length < targetLength) str = padString + str;
            return str;
        };
    }

    /* ══════════════════════════════════════════
       BOOTSTRAP
    ══════════════════════════════════════════ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init(); // DOM already ready
    }

}());
