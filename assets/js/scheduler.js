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
    var BLOCKED_DAYS   = cfg.blockedDays  || []; // global blocked days (0=Sun…6=Sat)
    var TIME_SLOTS     = cfg.timeSlots    || []; // global time slots
    var MODE           = cfg.selectionMode || 'multiple';
    var AJAX_URL       = cfg.ajaxUrl      || '';
    var NONCE          = cfg.nonce        || '';
    var SERVICE_DATA   = cfg.serviceData  || {}; // per-service overrides keyed by post ID

    function complementDays(available) {
        var blocked = [];
        for (var d = 0; d <= 6; d++) {
            if (available.indexOf(d) === -1) blocked.push(d);
        }
        return blocked;
    }

    function unionDays(a, b) {
        var merged = a.slice();
        b.forEach(function (d) {
            if (merged.indexOf(d) === -1) merged.push(d);
        });
        return merged;
    }

    /**
     * Blocked weekdays for one service (respects combine vs replace mode).
     */
    function getServiceBlockedDays(serviceId) {
        var sd = SERVICE_DATA[serviceId];
        if (!sd || !sd.useCustom) {
            return BLOCKED_DAYS.slice();
        }
        var available  = sd.availableDays || [];
        var svcBlocked = complementDays(available);
        if (sd.scheduleMode === 'replace') {
            return svcBlocked;
        }
        return unionDays(BLOCKED_DAYS, svcBlocked);
    }

    /**
     * Union of blocked days across selected services (date must be allowed by all).
     */
    function getEffectiveBlockedDays() {
        if (!selectedServices.length) return BLOCKED_DAYS.slice();
        var merged = [];
        selectedServices.forEach(function (s) {
            getServiceBlockedDays(s.id).forEach(function (d) {
                if (merged.indexOf(d) === -1) merged.push(d);
            });
        });
        return merged;
    }

    /**
     * Get effective time slots: if the FIRST selected service has custom slots, use those.
     * Otherwise fall back to global.
     */
    function getEffectiveTimeSlots() {
        if (!selectedServices.length) return TIME_SLOTS;
        var first = SERVICE_DATA[selectedServices[0].id];
        if (first && first.useCustom && first.timeSlots && first.timeSlots.length) {
            return first.timeSlots;
        }
        return TIME_SLOTS;
    }

    /**
     * Check if a date string (YYYY-MM-DD) falls in any service's days-off range.
     */
    function isDateInDaysOff(dateStr) {
        for (var i = 0; i < selectedServices.length; i++) {
            var sd = SERVICE_DATA[selectedServices[i].id];
            if (!sd || !sd.useCustom || !sd.daysOff) continue;
            for (var j = 0; j < sd.daysOff.length; j++) {
                var off = sd.daysOff[j];
                if (off.start && dateStr >= off.start && dateStr <= (off.end || off.start)) {
                    return true;
                }
            }
        }
        return false;
    }

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
        initFilters();
        initServiceCardVideos();
        initCalendar();
        initSubmit();
        applyServiceFilters();
    }

    /**
     * Ensure service card videos autoplay after load (browser policy-safe).
     */
    function initServiceCardVideos() {
        function playVideo(video) {
            if (!video || video.tagName !== 'VIDEO') {
                return;
            }
            video.muted = true;
            video.defaultMuted = true;
            video.playsInline = true;
            video.setAttribute('muted', '');
            video.setAttribute('playsinline', '');
            video.setAttribute('webkit-playsinline', '');
            var promise = video.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch(function () {});
            }
        }

        function playAllVideos() {
            document.querySelectorAll('.ll-svc-right video.ll-svc-media').forEach(playVideo);
        }

        document.querySelectorAll('.ll-svc-right video.ll-svc-media').forEach(function (video) {
            if (video.readyState >= 2) {
                playVideo(video);
            }
            video.addEventListener('loadeddata', function () { playVideo(video); }, { once: true });
            video.addEventListener('canplay', function () { playVideo(video); }, { once: true });
        });

        if (document.readyState === 'complete') {
            playAllVideos();
        } else {
            window.addEventListener('load', playAllVideos);
        }
    }

    /* ══════════════════════════════════════════
       SERVICE FILTERS (property size / city / address)
    ══════════════════════════════════════════ */
    function parseJsonAttr(str) {
        if (!str) return [];
        try {
            var parsed = JSON.parse(str);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function parseJsonObjectAttr(str) {
        if (!str) return {};
        try {
            var parsed = JSON.parse(str);
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return parsed;
            }
            return {};
        } catch (e) {
            return {};
        }
    }

    function normalizeFilterText(value) {
        return (value || '').trim().toLowerCase();
    }

    function parseDataList(item, attr) {
        return parseJsonAttr(item.getAttribute('data-' + attr) || '');
    }

    function parseSizePrices(item) {
        return parseJsonObjectAttr(item.getAttribute('data-size-prices') || '');
    }

    function parseCitySizePrices(item) {
        return parseJsonObjectAttr(item.getAttribute('data-city-size-prices') || '');
    }

    function findCityPriceKey(city, map) {
        if (!city || !map || typeof map !== 'object') return null;
        if (Object.prototype.hasOwnProperty.call(map, city)) return city;
        var cityLower = normalizeFilterText(city);
        var keys = Object.keys(map);
        for (var i = 0; i < keys.length; i++) {
            if (normalizeFilterText(keys[i]) === cityLower) {
                return keys[i];
            }
        }
        return null;
    }

    /** Partial match: user text is contained in service address, or the reverse. */
    function partialTextMatch(userText, serviceText) {
        var user    = normalizeFilterText(userText);
        var service = normalizeFilterText(serviceText);
        if (!user || !service) return false;
        return service.indexOf(user) !== -1 || user.indexOf(service) !== -1;
    }

    function listContainsValue(list, value) {
        if (!value || !list.length) return true;
        var norm = normalizeFilterText(value);
        for (var i = 0; i < list.length; i++) {
            if (normalizeFilterText(list[i]) === norm) {
                return true;
            }
        }
        return false;
    }

    function listMatchesPartial(list, userInput) {
        if (!userInput || !list.length) return true;
        for (var i = 0; i < list.length; i++) {
            if (partialTextMatch(userInput, list[i])) {
                return true;
            }
        }
        return false;
    }

    function serviceMatchesFilters(item, propSize, city, address) {
        var sizes     = parseDataList(item, 'sizes');
        var cities    = parseDataList(item, 'cities');
        var addresses = parseDataList(item, 'addresses');

        if (propSize && sizes.length && sizes.indexOf(propSize) === -1) {
            return false;
        }
        if (city && cities.length && !listContainsValue(cities, city)) {
            return false;
        }
        if (address && addresses.length && !listMatchesPartial(addresses, address)) {
            return false;
        }
        return true;
    }

    function applyServiceFilters() {
        var propSizeEl = document.getElementById('llPropSize');
        var cityEl     = document.getElementById('llCity');
        var addressEl  = document.getElementById('llAddress');
        var propSize   = propSizeEl ? propSizeEl.value : '';
        var city       = cityEl ? cityEl.value : '';
        var address    = addressEl ? addressEl.value : '';
        var changed    = false;

        document.querySelectorAll('.ll-svc-item').forEach(function (item) {
            var visible = serviceMatchesFilters(item, propSize, city, address);
            item.classList.toggle('ll-svc-hidden', !visible);
            item.style.display = visible ? '' : 'none';

            if (!visible) {
                var cb = item.querySelector('.ll-svc-cb');
                if (cb && cb.checked) {
                    cb.checked = false;
                    onServiceChange(item, cb);
                    changed = true;
                }
            }
        });

        document.querySelectorAll('.ll-cat-group').forEach(function (group) {
            var visibleCount = 0;
            group.querySelectorAll('.ll-svc-item').forEach(function (item) {
                if (!item.classList.contains('ll-svc-hidden')) visibleCount++;
            });
            group.style.display = visibleCount > 0 ? '' : 'none';
        });

        if (changed && elCalGrid) renderCalendar();

        updateServiceCardPrices(propSize, city);
    }

    function formatPriceDisplay(amount) {
        var n = parseFloat(amount) || 0;
        if (n <= 0) return '';
        return n % 1 === 0 ? n.toLocaleString() : n.toFixed(2);
    }

    /**
     * Update service card prices when property size or city changes.
     */
    function updateServiceCardPrices(propSize, city) {
        if (typeof city === 'undefined') {
            var cityEl = document.getElementById('llCity');
            city = cityEl ? cityEl.value : '';
        }

        document.querySelectorAll('.ll-svc-item').forEach(function (item) {
            var priceEl = item.querySelector('.ll-svc-price');
            if (!priceEl) return;

            var price = getServicePrice(item, propSize, city);
            if (price <= 0) {
                priceEl.textContent = '';
                return;
            }

            var formatted = formatPriceDisplay(price);
            if (propSize) {
                priceEl.textContent = '$' + formatted;
            } else {
                priceEl.textContent = 'From: $' + formatted;
            }
        });
    }

    function initFilters() {
        var propSizeEl = document.getElementById('llPropSize');
        var cityEl     = document.getElementById('llCity');
        var addressEl  = document.getElementById('llAddress');

        if (propSizeEl) {
            propSizeEl.addEventListener('change', function () {
                applyServiceFilters();
                updateSummary();
            });
        }
        if (cityEl) {
            cityEl.addEventListener('change', function () {
                applyServiceFilters();
                updateSummary();
            });
        }
        if (addressEl) {
            addressEl.addEventListener('input', applyServiceFilters);
            addressEl.addEventListener('change', applyServiceFilters);
        }
    }

    /* ══════════════════════════════════════════
       SERVICE SELECTION
    ══════════════════════════════════════════ */
    function initServiceCheckboxes() {
        document.querySelectorAll('.ll-svc-item').forEach(function (item) {
            var cb = item.querySelector('.ll-svc-cb');
            if (!cb) return;

            // Card is a <label> — clicking anywhere toggles the checkbox natively.
            // Listen only to change to avoid double-toggle when clicking the card.
            cb.addEventListener('change', function () {
                onServiceChange(item, cb);
            });
        });
    }

    function onServiceChange(item, cb) {
        var id    = parseInt(item.dataset.id, 10);
        var title = item.dataset.title || '';
        var price = 0;

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
                selectedServices.push({ id: id, title: title, el: item, price: price });
            }
            item.classList.add('ll-checked');

        } else {
            selectedServices = selectedServices.filter(function (s) { return s.id !== id; });
            item.classList.remove('ll-checked');
        }

        updateSummary();
        toggleBookingSection();

        if (cb.checked) {
            scrollToNextServiceCategory(item);
        }
        // Re-render calendar so per-service blocked days apply immediately
        if (elCalGrid) renderCalendar();
    }

    function currentPropertySize() {
        var propSizeEl = document.getElementById('llPropSize');
        return propSizeEl ? propSizeEl.value : '';
    }

    function filtersAreComplete() {
        var propSizeEl = document.getElementById('llPropSize');
        var cityEl     = document.getElementById('llCity');
        var addressEl  = document.getElementById('llAddress');

        if (!propSizeEl || !propSizeEl.value.trim()) return false;
        if (!cityEl || !cityEl.value.trim()) return false;
        if (!addressEl || !addressEl.value.trim()) return false;
        return true;
    }

    function getFiltersErrorMessage() {
        var propSizeEl = document.getElementById('llPropSize');
        var cityEl     = document.getElementById('llCity');
        var addressEl  = document.getElementById('llAddress');

        if (!propSizeEl || !propSizeEl.value.trim()) {
            return 'Please select a Property Size before continuing.';
        }
        if (!cityEl || !cityEl.value.trim()) {
            return 'Please select a City before continuing.';
        }
        if (!addressEl || !addressEl.value.trim()) {
            return 'Please enter a Service Area / Address before continuing.';
        }
        return '';
    }

    function scrollToFilters() {
        var filters = document.querySelector('.ll-filters');
        if (filters) {
            filters.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function currentCity() {
        var cityEl = document.getElementById('llCity');
        return cityEl ? cityEl.value : '';
    }

    function getServicePrice(item, propSize, city) {
        if (typeof city === 'undefined') {
            city = currentCity();
        }

        var sizePrices = parseSizePrices(item);
        var citySizePrices = parseCitySizePrices(item);

        if (propSize && city) {
            var cityKey = findCityPriceKey(city, citySizePrices);
            if (cityKey && citySizePrices[cityKey] && Object.prototype.hasOwnProperty.call(citySizePrices[cityKey], propSize)) {
                var citySizePrice = parseFloat(citySizePrices[cityKey][propSize]);
                if (!isNaN(citySizePrice) && citySizePrice > 0) {
                    return citySizePrice;
                }
            }
        }

        if (propSize && sizePrices && Object.prototype.hasOwnProperty.call(sizePrices, propSize)) {
            var sizePrice = parseFloat(sizePrices[propSize]);
            if (!isNaN(sizePrice) && sizePrice > 0) {
                return sizePrice;
            }
        }

        if (!propSize && city) {
            var cityOnlyKey = findCityPriceKey(city, citySizePrices);
            if (cityOnlyKey && citySizePrices[cityOnlyKey]) {
                var cityMins = [];
                Object.keys(citySizePrices[cityOnlyKey]).forEach(function (key) {
                    var val = parseFloat(citySizePrices[cityOnlyKey][key]);
                    if (!isNaN(val) && val > 0) cityMins.push(val);
                });
                if (cityMins.length) return Math.min.apply(null, cityMins);
            }
        }

        if (!propSize && !city) {
            var allMins = [];
            Object.keys(citySizePrices).forEach(function (cityName) {
                Object.keys(citySizePrices[cityName]).forEach(function (sizeKey) {
                    var amount = parseFloat(citySizePrices[cityName][sizeKey]);
                    if (!isNaN(amount) && amount > 0) allMins.push(amount);
                });
            });
            if (sizePrices && typeof sizePrices === 'object') {
                Object.keys(sizePrices).forEach(function (key) {
                    var val = parseFloat(sizePrices[key]);
                    if (!isNaN(val) && val > 0) allMins.push(val);
                });
            }
            if (allMins.length) return Math.min.apply(null, allMins);
        }

        if (!propSize && sizePrices && typeof sizePrices === 'object') {
            var mins = [];
            Object.keys(sizePrices).forEach(function (key) {
                var val = parseFloat(sizePrices[key]);
                if (!isNaN(val) && val > 0) mins.push(val);
            });
            if (mins.length) return Math.min.apply(null, mins);
        }

        return parseFloat(item.dataset.basePrice) || parseFloat(item.dataset.price) || 0;
    }

    function toggleBookingSection() {
        if (selectedServices.length > 0) {
            elBooking.hidden = false;
        } else {
            elBooking.hidden = true;
            // Reset date/time selections when no services chosen
            selectedDate = null;
            selectedTime = null;
            elTimes.hidden = true;
        }
    }

    /**
     * Scroll to the next visible service category after checking a service.
     */
    function scrollToNextServiceCategory(item) {
        var currentGroup = item.closest('.ll-cat-group');
        if (!currentGroup) return;

        var groups = document.querySelectorAll('.ll-cat-group');
        var passedCurrent = false;
        var target = null;

        for (var i = 0; i < groups.length; i++) {
            if (groups[i] === currentGroup) {
                passedCurrent = true;
                continue;
            }
            if (passedCurrent && groups[i].offsetParent !== null) {
                target = groups[i];
                break;
            }
        }

        if (!target) return;

        var scrollTarget = target.querySelector('.ll-cat-title') || target;
        setTimeout(function () {
            var offset = 20;
            var top = scrollTarget.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        }, 80);
    }

    function updateSummary() {
        var total = 0;
        var html  = '';
        var propSize = currentPropertySize();
        var city     = currentCity();
        selectedServices.forEach(function (s) {
            var itemEl = s.el || document.querySelector('.ll-svc-item[data-id="' + s.id + '"]');
            var price = itemEl ? getServicePrice(itemEl, propSize, city) : (s.price || 0);
            s.price = price;
            total += price;
            html += '<div class="ll-summary-item">' +
                        '<span>' + escHtml(s.title) + '</span>' +
                        '<span>$' + price.toFixed(2) + '</span>' +
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

        var now       = new Date();
        var todayStr  = formatDate(now.getFullYear(), now.getMonth(), now.getDate());

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

            var jsDay     = new Date(calYear, calMonth, d).getDay(); // 0=Sun ... 6=Sat
            var dateStr   = formatDate(calYear, calMonth, d);
            var isPast    = dateStr < todayStr;
            var effectiveBlocked = getEffectiveBlockedDays();
            var isBlocked = effectiveBlocked.indexOf(jsDay) !== -1 || isDateInDaysOff(dateStr);

            if (isPast || isBlocked) {
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

                (function (ds, targetCell) {
                    targetCell.addEventListener('click', function () {
                        onDateSelect(ds);
                        elCalGrid.querySelectorAll('.ll-cal-cell').forEach(function (c) {
                            c.classList.remove('ll-cal-selected');
                        });
                        targetCell.classList.add('ll-cal-selected');
                    });
                    targetCell.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            targetCell.click();
                        }
                    });
                }(dateStr, cell));
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

    function isDateBookable(dateStr) {
        var now      = new Date();
        var todayStr = formatDate(now.getFullYear(), now.getMonth(), now.getDate());
        if (dateStr < todayStr) return false;
        if (isDateInDaysOff(dateStr)) return false;
        var parts = dateStr.split('-');
        var jsDay = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)).getDay();
        return getEffectiveBlockedDays().indexOf(jsDay) === -1;
    }

    /* ══════════════════════════════════════════
       TIME SLOTS
    ══════════════════════════════════════════ */
    function renderTimeSlots() {
        elTimesGrid.innerHTML = '';
        selectedTime = null;

        var slots = getEffectiveTimeSlots();
        if (!slots.length) {
            elTimesGrid.innerHTML = '<p style="color:#888;font-style:italic;">No time slots configured. Please contact the administrator.</p>';
            return;
        }

        slots.forEach(function (slot) {
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
        if (!filtersAreComplete()) {
            showMsg(getFiltersErrorMessage(), 'error');
            scrollToFilters();
            return false;
        }
        if (!selectedServices.length) {
            showMsg('Please select at least one service.', 'error');
            return false;
        }
        if (!selectedDate || !isDateBookable(selectedDate)) {
            showMsg('Please select a valid booking date on the calendar.', 'error');
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
