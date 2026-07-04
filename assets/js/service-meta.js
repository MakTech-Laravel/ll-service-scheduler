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

    /* ── Service address tags (preset dropdown + custom input) ── */
    function svcAddressExists(val) {
        var norm = (val || '').trim().toLowerCase();
        if (!norm) return true;
        var exists = false;
        $('#llSvcAddressTags input[type="hidden"]').each(function () {
            if ($(this).val().trim().toLowerCase() === norm) {
                exists = true;
                return false;
            }
        });
        return exists;
    }

    function svcAddAddressTag(val) {
        var clean = (val || '').trim();
        if (!clean || svcAddressExists(clean)) {
            return;
        }
        var tag = $('<span class="ll-svc-address-tag">' +
            '<input type="hidden" name="_ll_svc_addresses[]">' +
            '<span class="ll-svc-address-tag-text"></span>' +
            '<button type="button" class="ll-svc-address-rm" aria-label="Remove">&times;</button>' +
            '</span>');
        tag.find('input').val(clean);
        tag.find('.ll-svc-address-tag-text').text(clean);
        $('#llSvcAddressTags').append(tag);
    }

    $('#llSvcAddPresetAddress').on('click', function () {
        svcAddAddressTag($('#llSvcAddressPreset').val());
        $('#llSvcAddressPreset').val('');
    });

    $('#llSvcAddCustomAddress').on('click', function () {
        var $input = $('#llSvcAddressCustom');
        svcAddAddressTag($input.val());
        $input.val('').focus();
    });

    $('#llSvcAddressCustom').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#llSvcAddCustomAddress').trigger('click');
        }
    });

    $(document).on('click', '.ll-svc-address-rm', function () {
        $(this).closest('.ll-svc-address-tag').fadeOut(150, function () { $(this).remove(); });
    });

    /* ── Allow video in Featured Image picker (services only) ── */
    if (typeof wp !== 'undefined' && wp.media && wp.media.featuredImage && wp.media.featuredImage.frame) {
        var originalFeaturedFrame = wp.media.featuredImage.frame;

        wp.media.featuredImage.frame = function () {
            var frame = originalFeaturedFrame.apply(this, arguments);

            frame.off('open.llSchedFeatured').on('open.llSchedFeatured', function () {
                var state = frame.state();
                if (!state) {
                    return;
                }

                var library = state.get('library');
                if (library && library.props) {
                    library.props.set('type', '');
                }

                var featuredState = frame.states.get('featured-image');
                if (featuredState) {
                    var featuredLibrary = featuredState.get('library');
                    if (featuredLibrary && featuredLibrary.props) {
                        featuredLibrary.props.set('type', '');
                    }
                }
            });

            return frame;
        };
    }

    /* ── Day label styling on checkbox change ── */
    $(document).on('change', '.ll-day-checkboxes input[type="checkbox"]', function () {
        var $lbl = $(this).closest('.ll-day-label');
        if ($lbl.hasClass('ll-day-available')) {
            $lbl.toggleClass('ll-day-open', $(this).is(':checked'));
            return;
        }
        $lbl.toggleClass('ll-day-open', $(this).is(':checked'));
    });

    /* ── Service card media (image / GIF / video) ── */
    var cardMediaFrame;

    function renderCardMediaPreview(attachment) {
        var $preview = $('#llSvcCardMediaPreview');
        $preview.empty();

        if (!attachment) {
            $preview.html('<span class="ll-svc-card-media-placeholder">No media selected — Featured Image will be used if set.</span>');
            $('#llSvcCardMediaRemove').hide();
            return;
        }

        var mime = attachment.mime || attachment.type || '';
        if (mime.indexOf('video/') === 0) {
            $preview.html('<video src="' + attachment.url + '" muted loop playsinline></video>');
        } else {
            var thumb = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
            $preview.html('<img src="' + thumb + '" alt="">');
        }
        $('#llSvcCardMediaRemove').show();
    }

    $('#llSvcCardMediaPick').on('click', function (e) {
        e.preventDefault();

        if (cardMediaFrame) {
            cardMediaFrame.open();
            return;
        }

        cardMediaFrame = wp.media({
            title: 'Select Service Card Media',
            button: { text: 'Use this media' },
            library: { type: ['image', 'video'] },
            multiple: false
        });

        cardMediaFrame.on('select', function () {
            var attachment = cardMediaFrame.state().get('selection').first().toJSON();
            $('#llSvcCardMediaId').val(attachment.id);
            renderCardMediaPreview(attachment);
        });

        cardMediaFrame.open();
    });

    $('#llSvcCardMediaRemove').on('click', function (e) {
        e.preventDefault();
        $('#llSvcCardMediaId').val('');
        renderCardMediaPreview(null);
    });
});
