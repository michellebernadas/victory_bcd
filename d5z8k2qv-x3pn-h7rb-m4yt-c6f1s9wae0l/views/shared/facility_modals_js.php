<script>
/**
 * Facility Booking — modal wiring.
 *
 * Opens each workflow modal with the row's data, runs the live availability
 * advisory on the request form, and loads the read-only detail and the
 * append-only approval history over AJAX (same shape as the Review History
 * modal on the Settings page).
 */
(function () {
    'use strict';

    var STATUS_LABELS = <?php echo json_encode(array_map(function ($m) { return $m['label']; }, FacilityRequest::STATUSES)); ?>;
    var STATUS_BADGES = <?php echo json_encode(array_map(function ($m) { return $m['badge']; }, FacilityRequest::STATUSES)); ?>;
    var CONFIRMED     = <?php echo json_encode(FacilityRequest::BLOCKING_STATUS); ?>;
    var TYPE_LABELS   = <?php echo json_encode(FacilityRequest::REQUEST_TYPES); ?>;

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function show(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
    function el(id)   { return document.getElementById(id); }

    /**
     * The workflow modals are rendered only for accounts that may perform that
     * step (facility_modals.php, via config/permissions.php), so their markup
     * is simply absent for everyone else. Each opener checks for its own modal
     * first and does nothing if it is missing, rather than throwing on a null
     * element. The matching button is hidden too, so reaching here means a
     * stale cached page or a hand-typed console call — in both cases silence is
     * the right answer; the server would refuse the POST regardless.
     */
    function missing(id) { return !document.getElementById(id); }
    function fmtTime(t) {
        var m = String(t || '').match(/^(\d{1,2}):(\d{2})/);
        if (!m) return '';
        var h = +m[1], mm = m[2], ap = h >= 12 ? 'PM' : 'AM', h12 = h % 12 || 12;
        return h12 + ':' + mm + ' ' + ap;
    }
    function metaLine(d) {
        var bits = [];
        if (d.ministry) bits.push(esc(d.ministry));
        bits.push(d.facility ? esc(d.facility) : 'No room requested');
        if (d.date) bits.push(esc(d.date));
        var t = [fmtTime(d.start), fmtTime(d.end)].filter(Boolean).join(' – ');
        if (t) bits.push(t);
        return bits.join(' · ');
    }

    /** Conflict banner shared by the review / decision / confirm modals. */
    function conflictBanner(hard, soft) {
        var html = '';
        if (hard > 0) {
            html += '<div class="alert alert-danger small py-2">'
                 +  '<i class="bi bi-exclamation-octagon-fill me-1"></i><strong>Scheduling conflict.</strong> '
                 +  'This overlaps ' + hard + ' <strong>confirmed</strong> reservation(s) in the same room. '
                 +  'A new request does not override a confirmed reservation (policy section 11) — '
                 +  'refer the conflict to the Minister (policy section 13).</div>';
        }
        if (soft > 0) {
            html += '<div class="alert alert-warning small py-2">'
                 +  '<i class="bi bi-exclamation-triangle me-1"></i><strong>Possible clash.</strong> '
                 +  soft + ' other request(s) overlap this slot but are not yet confirmed, so nothing is '
                 +  'reserved. Worth resolving before this one is confirmed.</div>';
        }
        return html;
    }

    // ── Step 1: request form ────────────────────────────────────────────────

    window.openFacilityRequestForm = function (date, hour) {
        var form = el('frForm');
        if (form) form.reset();
        var adv = el('fr_advisory');
        if (adv) adv.innerHTML = '';

        // Prefill from a calendar click. An hour means a week-view slot, so
        // offer a sensible default one-hour booking the requestor can adjust.
        if (date && el('fr_date')) el('fr_date').value = date;
        if (hour && el('fr_start')) {
            el('fr_start').value = hour.slice(0, 5);
            var h = parseInt(hour.slice(0, 2), 10);
            if (el('fr_end')) el('fr_end').value = ('0' + Math.min(23, h + 1)).slice(-2) + ':00';
        }
        if (window.jQuery) {
            ['#fr_member', '#fr_ministry'].forEach(function (sel) {
                if (jQuery(sel).data('select2')) jQuery(sel).val('').trigger('change.select2');
            });
        }
        clearFormErrors();
        syncRoomRequired();
        checkTimes();
        checkAvailability();
        show('facilityRequestModal').show();
    };

    // Picking a member fills the contact block rather than making it retyped.
    // Name / email may already hold the account prefill, so a member pick
    // replaces those two; everything else is only filled when empty.
    document.addEventListener('change', function (e) {
        if (e.target.id !== 'fr_member') return;
        var opt = e.target.options[e.target.selectedIndex];
        if (!opt || !opt.value) return;
        var set = function (id, v, force) { var n = el(id); if (n && v && (force || !n.value)) n.value = v; };
        set('fr_name',     opt.dataset.name, true);
        set('fr_contact',  opt.dataset.contact);
        set('fr_email',    opt.dataset.email, true);
        set('fr_ack_name', opt.dataset.name, true);
        var min = el('fr_ministry'), v = opt.dataset.ministry;
        if (min && v && !min.value) {
            if (!Array.prototype.some.call(min.options, function (o) { return o.value === v; })) {
                min.add(new Option(v, v));
            }
            min.value = v;
            if (window.jQuery && jQuery(min).data('select2')) jQuery(min).trigger('change.select2');
        }
    });
    // Select2 changes don't bubble as native events; mirror them.
    if (window.jQuery) {
        jQuery(document).on('select2:select', '#fr_member', function () {
            this.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    // ── Request form validation (mirrors FacilityRequest::validatePortalForm) ──
    // The server re-checks everything; this just says it before the round trip.
    // Lead time is never part of it — that is a warning, not a block.

    function isRoomRequest() {
        var box = el('fr_type_facility_room');
        return !!(box && box.checked);
    }
    function checkedTypes() {
        return Array.prototype.slice.call(document.querySelectorAll('.fr-type:checked'));
    }
    function syncRoomRequired() {
        var need = isRoomRequest();
        document.querySelectorAll('.fr-room-req').forEach(function (s) { s.classList.toggle('d-none', !need); });
        var fac = el('fr_facility');
        if (fac && fac.options.length) {
            fac.options[0].textContent = need ? 'Choose a room…' : 'No specific room (equipment / resource only)';
        }
        var msg = el('fr_room_msg');
        if (msg && !need) { msg.innerHTML = ''; if (fac) fac.classList.remove('is-invalid'); }
    }
    function minutes(t) {
        var m = String(t || '').match(/^(\d{1,2}):(\d{2})/);
        return m ? (+m[1] * 60 + +m[2]) : null;
    }
    /** Inline end-after-start message next to the time fields. */
    function checkTimes() {
        var box = el('fr_time_msg'), s = el('fr_start'), e = el('fr_end');
        if (!box || !s || !e) return true;
        var a = minutes(s.value), b = minutes(e.value);
        var bad = a !== null && b !== null && b <= a;
        e.classList.toggle('is-invalid', bad);
        box.innerHTML = bad
            ? '<span class="text-danger"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>End time must be after the start time.</span>'
            : '';
        return !bad;
    }
    function clearFormErrors() {
        var box = el('fr_errors');
        if (box) { box.classList.add('d-none'); box.innerHTML = ''; }
        document.querySelectorAll('#frForm .is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
        var rm = el('fr_room_msg'); if (rm) rm.innerHTML = '';
    }
    function validateRequestForm() {
        clearFormErrors();
        var errs = [], mark = function (id) { var n = el(id); if (n) n.classList.add('is-invalid'); };
        var val = function (id) { var n = el(id); return n ? String(n.value || '').trim() : ''; };

        if (!checkedTypes().length) errs.push('Choose at least one type of request.');
        if (!val('fr_name'))  { errs.push('Name is required.'); mark('fr_name'); }
        if (!val('fr_event')) { errs.push('Event / Activity is required.'); mark('fr_event'); }
        if (!val('fr_date'))  { errs.push('Date needed is required.'); mark('fr_date'); }
        if (isRoomRequest()) {
            if (!val('fr_facility')) {
                errs.push('A Facility / Room request needs a room (or untick "Facility / Room" for an equipment-only request).');
                mark('fr_facility');
                var rm = el('fr_room_msg');
                if (rm) rm.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>Choose the room you need.</span>';
            }
            if (!val('fr_start')) { errs.push('Start time is required for a room booking.'); mark('fr_start'); }
            if (!val('fr_end'))   { errs.push('End time is required for a room booking.'); mark('fr_end'); }
        }
        if (!checkTimes()) errs.push('End time must be after the start time.');
        var ack = el('fr_ack');
        if (ack && !ack.checked) { errs.push('Please tick the acknowledgment in section F.'); mark('fr_ack'); }

        if (errs.length) {
            var box = el('fr_errors');
            box.innerHTML = '<strong>Please fix the following before submitting:</strong><ul class="mb-0 mt-1">'
                + errs.map(function (m) { return '<li>' + esc(m) + '</li>'; }).join('') + '</ul>';
            box.classList.remove('d-none');
            box.focus();
            return false;
        }
        return true;
    }

    document.addEventListener('submit', function (e) {
        if (e.target.id === 'frForm' && !validateRequestForm()) e.preventDefault();
    });
    document.addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('fr-type')) syncRoomRequired();
        if (e.target.id === 'fr_start' || e.target.id === 'fr_end') checkTimes();
        if (e.target.id === 'fr_facility' && e.target.value) {
            e.target.classList.remove('is-invalid');
            var rm = el('fr_room_msg'); if (rm) rm.innerHTML = '';
        }
    });

    // Minister modal: the button says what the chosen decision will do.
    var DECISION_LABEL = {
        approved: 'Approve & send to Facilities for confirmation',
        declined: 'Decline this request',
        for_clarification: 'Return to requestor for clarification'
    };
    document.addEventListener('change', function (e) {
        if (e.target.id !== 'fm_decision') return;
        var l = el('fm_submit_label'); if (l) l.textContent = DECISION_LABEL[e.target.value] || 'Record decision';
        var b = el('fm_submit');
        if (b) b.className = 'btn ' + (e.target.value === 'declined' ? 'btn-danger' : 'btn-purple');
    });

    /**
     * Live conflict + lead-time advisory. Purely informational: the submit
     * button is never disabled, because policy section 11 allows last-minute
     * requests and the Minister — not this form — decides on a clash.
     */
    var checkTimer = null;
    function checkAvailability() {
        var box = el('fr_advisory');
        if (!box) return;
        var facility = el('fr_facility') ? el('fr_facility').value : '';
        var date  = el('fr_date')  ? el('fr_date').value  : '';
        var start = el('fr_start') ? el('fr_start').value : '';
        var end   = el('fr_end')   ? el('fr_end').value   : '';
        if (!date) { box.innerHTML = ''; return; }

        var qs = 'facility_id=' + encodeURIComponent(facility)
               + '&date_needed=' + encodeURIComponent(date)
               + '&start_time=' + encodeURIComponent(start)
               + '&end_time=' + encodeURIComponent(end);

        fetch('index.php?action=ajaxFacilityAvailability&' + qs)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var html = '';

                if (d.lead && d.lead.warn) {
                    html += '<div class="alert alert-warning small py-2 mb-2">'
                         +  '<i class="bi bi-stopwatch me-1"></i><strong>Short notice.</strong> '
                         +  esc(d.lead.message)
                         +  '<div class="mt-1 fst-italic">You can still submit this request.</div></div>';
                }

                if (!d.checked) {
                    if (!facility) {
                        html += '<div class="alert alert-light border small py-2 mb-0">'
                             +  '<i class="bi bi-info-circle me-1"></i>No room selected, so no availability '
                             +  'check applies — an equipment-only request occupies no space.</div>';
                    }
                } else if (d.hard.length) {
                    html += '<div class="alert alert-danger small py-2 mb-2">'
                         +  '<i class="bi bi-exclamation-octagon-fill me-1"></i>'
                         +  '<strong>This room is already reserved at that time.</strong><ul class="mb-1 mt-1">';
                    d.hard.forEach(function (c) {
                        html += '<li>' + esc(c.event) + ' — ' + esc(c.ministry || 'n/a') + ', '
                             +  fmtTime(c.start) + '–' + fmtTime(c.end) + ' <span class="badge bg-success">CONFIRMED</span></li>';
                    });
                    html += '</ul>You may still submit — the Minister decides on conflicting requests '
                         +  '(policy section 13) — but a confirmed reservation is not overridden automatically.</div>';
                } else if (d.soft.length) {
                    html += '<div class="alert alert-warning small py-2 mb-2">'
                         +  '<i class="bi bi-exclamation-triangle me-1"></i>'
                         +  '<strong>Another request already covers this slot</strong>, though nothing is '
                         +  'confirmed yet:<ul class="mb-0 mt-1">';
                    d.soft.forEach(function (c) {
                        html += '<li>' + esc(c.event) + ' — ' + fmtTime(c.start) + '–' + fmtTime(c.end)
                             +  ' <span class="badge bg-' + (STATUS_BADGES[c.status] || 'secondary') + '">'
                             +  esc(c.status_label) + '</span></li>';
                    });
                    html += '</ul></div>';
                } else {
                    html += '<div class="alert alert-success small py-2 mb-0">'
                         +  '<i class="bi bi-check-circle me-1"></i>No conflicting reservation found for this '
                         +  'room and time. Availability is still subject to Facilities review and Minister approval.</div>';
                }
                box.innerHTML = html;
            })
            .catch(function () { box.innerHTML = ''; });
    }

    ['fr_facility', 'fr_date', 'fr_start', 'fr_end'].forEach(function (id) {
        document.addEventListener('change', function (e) {
            if (e.target.id !== id) return;
            clearTimeout(checkTimer);
            checkTimer = setTimeout(checkAvailability, 150);
        });
    });

    // ── Steps 2–4 + cancel ──────────────────────────────────────────────────

    /** Workflow modals can be opened from the detail modal; never stack two. */
    function hideDetail() {
        var det = document.getElementById('facilityDetailModal');
        if (det && det.classList.contains('show')) bootstrap.Modal.getOrCreateInstance(det).hide();
    }

    window.openFacilitiesReview = function (d) {
        if (missing('facilitiesReviewModal')) return;
        hideDetail();
        el('fvForm').action ='index.php?action=facilitiesReviewRequest&id=' + d.id;
        el('fv_event').textContent = d.event || '(untitled)';
        el('fv_meta').textContent  = '';
        el('fv_meta').innerHTML    = metaLine(d);
        el('fv_conflicts').innerHTML = conflictBanner(d.hard, d.soft);
        // Default the conflict answer to what the calendar actually says.
        el('fv_conflict').value = (d.hard > 0 || d.soft > 0) ? 'yes' : 'none';
        show('facilitiesReviewModal').show();
    };

    window.openMinisterDecision = function (d) {
        if (missing('ministerDecisionModal')) return;
        hideDetail();
        el('fmForm').action ='index.php?action=ministerDecideRequest&id=' + d.id;
        el('fm_event').textContent = d.event || '(untitled)';
        el('fm_meta').innerHTML    = metaLine(d);
        el('fm_conflicts').innerHTML = conflictBanner(d.hard, d.soft);
        var dec = el('fm_decision');
        if (dec) { dec.value = 'approved'; dec.dispatchEvent(new Event('change', { bubbles: true })); }
        show('ministerDecisionModal').show();
    };

    window.openConfirmRequest = function (d) {
        if (missing('confirmRequestModal')) return;
        el('fcfForm').action ='index.php?action=confirmFacilityRequest&id=' + d.id;
        el('fcf_event').textContent = d.event || '(untitled)';
        el('fcf_meta').innerHTML    = metaLine(d);
        el('fcf_conflicts').innerHTML = conflictBanner(d.hard, d.soft);

        // The override is only offered when there is actually something to
        // override, so it can't be ticked out of habit.
        var wrap = el('fcf_force_wrap'), box = el('fcf_force');
        if (box) box.checked = false;
        if (wrap) wrap.classList.toggle('d-none', !(d.hard > 0));
        hideDetail();
        show('confirmRequestModal').show();
    };

    window.openCancelRequest = function (d) {
        if (missing('cancelRequestModal')) return;
        hideDetail();
        el('fxForm').action ='index.php?action=cancelFacilityRequest&id=' + d.id;
        el('fx_event').textContent = d.event || '(untitled)';
        el('fx_meta').innerHTML    = metaLine(d);
        show('cancelRequestModal').show();
    };

    // ── Read-only detail ────────────────────────────────────────────────────

    window.openFacilityDetail = function (id) {
        var loading = el('fd_loading'), body = el('fd_body');
        loading.classList.remove('d-none');
        body.classList.add('d-none');
        body.innerHTML = '';
        el('fd_history_btn').onclick = function () { window.openFacilityHistory(id, ''); };
        var act = el('fd_action_btn'), cxl = el('fd_cancel_btn'), wait = el('fd_waiting');
        act.classList.add('d-none'); act.onclick = null;
        cxl.classList.add('d-none'); cxl.onclick = null;
        wait.innerHTML = '';
        show('facilityDetailModal').show();

        fetch('index.php?action=ajaxFacilityRequestDetail&id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                loading.classList.add('d-none');
                if (!d || !d.request) {
                    body.innerHTML = '<div class="text-muted small">Request not found.</div>';
                    body.classList.remove('d-none');
                    return;
                }
                body.innerHTML = detailHtml(d);
                body.classList.remove('d-none');
                wireDetailActions(d);
            })
            .catch(function () {
                loading.classList.add('d-none');
                body.innerHTML = '<div class="text-danger small">Could not load this request.</div>';
                body.classList.remove('d-none');
            });
    };

    /**
     * The ONE next action this viewer may take (server-decided via
     * facilityNextAction()), or whose turn it is when they cannot act.
     */
    var NEXT_OPENER = { review: 'openFacilitiesReview', decide: 'openMinisterDecision', confirm: 'openConfirmRequest' };
    var NEXT_CLASS  = { review: 'btn-primary', decide: 'btn-purple', confirm: 'btn-success' };
    var NEXT_ICON   = { review: 'bi-clipboard-check', decide: 'bi-person-check', confirm: 'bi-calendar2-check' };
    function wireDetailActions(d) {
        var act = el('fd_action_btn'), cxl = el('fd_cancel_btn'), wait = el('fd_waiting');
        var n = d.next_action;
        if (n && NEXT_OPENER[n.key] && typeof window[NEXT_OPENER[n.key]] === 'function') {
            act.className = 'btn ' + NEXT_CLASS[n.key];
            act.innerHTML = '<i class="bi ' + NEXT_ICON[n.key] + ' me-1" aria-hidden="true"></i>' + esc(n.label);
            act.title = n.hint || '';
            act.onclick = function () { window[NEXT_OPENER[n.key]](d.payload); };
        } else if (d.waiting_for) {
            wait.innerHTML = '<i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Waiting for ' + esc(d.waiting_for);
        }
        if (d.can_cancel && typeof window.openCancelRequest === 'function' && !missing('cancelRequestModal')) {
            cxl.classList.remove('d-none');
            cxl.onclick = function () { window.openCancelRequest(d.payload); };
        }
    }

    function row(label, value) {
        if (value === null || value === undefined || String(value).trim() === '') return '';
        return '<div class="col-12 col-md-6 mb-2"><div class="text-muted" style="font-size:.7rem">'
             + esc(label) + '</div><div class="small">' + esc(value) + '</div></div>';
    }

    function detailHtml(d) {
        var r = d.request;
        var yn = function (v) { return Number(v) ? 'Yes' : 'No'; };

        var html = '<div class="d-flex align-items-center gap-2 mb-2">'
                 + '<span class="badge bg-' + (d.status_badge || 'secondary')
                 + (d.is_official ? ' fb-official' : '') + '">' + esc(d.status_label) + '</span>';
        html += d.is_official
            ? '<span class="small text-success fw-semibold">Official reservation — this blocks the room.</span>'
            : '<span class="small text-muted">Not an official reservation — this reserves nothing.</span>';
        html += '</div>';

        html += '<h6 class="fw-bold mb-2">' + esc(r.event_activity || '(untitled)') + '</h6>';
        // Server-rendered 4-step stepper (views/shared/facility_stepper.php).
        if (d.stepper_html) html += d.stepper_html;
        if (r.status === 'for_clarification') {
            html += '<div class="alert alert-warning small py-2"><i class="bi bi-question-circle me-1" aria-hidden="true"></i>'
                 +  '<strong>Waiting on the requestor.</strong> The Minister asked for more information'
                 +  (r.minister_comments ? ': "' + esc(r.minister_comments) + '"' : '.') + '</div>';
        }

        if (d.hard_count > 0 || d.soft_count > 0) {
            html += conflictBanner(d.hard_count, d.soft_count);
        }
        if (Number(r.lead_time_warning)) {
            html += '<div class="alert alert-secondary small py-2"><i class="bi bi-stopwatch me-1"></i>'
                 +  'Submitted on short notice: ' + esc(r.lead_days_actual) + ' day(s) of lead time '
                 +  'against a recommended ' + esc(r.default_lead_days || '3') + '. Advisory only.</div>';
        }

        html += '<div class="row g-0">';
        html += row('Requestor', r.requestor_name);
        html += row('Ministry / Department', r.ministry);
        html += row('Ministry Leader', r.ministry_leader);
        html += row('Contact Number', r.contact_number);
        html += row('Email', r.email);
        html += row('Type of Request', (r.request_type || '').split(',').filter(Boolean).map(function (t) {
            return TYPE_LABELS[t] || t.replace(/_/g, ' ');
        }).join(', '));
        html += row('Purpose', r.purpose);
        html += row('Date Needed', r.date_needed);
        html += row('Time', [fmtTime(r.start_time), fmtTime(r.end_time)].filter(Boolean).join(' – '));
        html += row('Expected Attendance', r.expected_attendance);
        html += row('Facility / Room', r.facility_name || 'No room requested (nothing occupied)');
        html += row('Preferred Setup', r.preferred_setup);
        html += row('Chairs', r.num_chairs);
        html += row('Tables', r.num_tables);
        html += row('Other Requirements', r.other_requirements);
        html += row('Equipment Requested', r.equipment_requested);
        html += row('Quantity', r.equipment_quantity);
        html += row('Person Responsible', r.person_responsible);
        html += row('Technical Assistance', r.technical_assistance);
        html += row('Technical Details', r.technical_assistance_details);
        html += row('Additional Requirements', [
            Number(r.needs_setup)     ? 'Setup' : '',
            Number(r.needs_technical) ? 'Technical' : '',
            Number(r.needs_security)  ? 'Security / access' : '',
            Number(r.needs_cleaning)  ? 'Cleaning' : '',
            Number(r.needs_other)     ? 'Other' : ''
        ].filter(Boolean).join(', '));
        html += row('Additional Details', r.additional_details);
        html += row('Acknowledged', yn(r.acknowledged) + (r.acknowledged_name ? ' — ' + r.acknowledged_name : ''));
        html += '</div>';

        // Facilities review block (policy section 8)
        if (r.fac_reviewed_at) {
            html += '<h6 class="fw-bold text-primary border-bottom pb-1 mt-3">Facilities Review</h6><div class="row g-0">';
            html += row('Available', r.fac_available);
            html += row('Schedule Conflict', r.fac_schedule_conflict);
            html += row('Setup Required', r.fac_setup_required);
            html += row('Technical Required', r.fac_technical_required);
            html += row('Other Considerations', r.fac_other_considerations);
            html += row('Recommendation', (r.fac_recommendation || '').replace(/_/g, ' '));
            html += row('Reviewed By', (r.fac_reviewed_by_name || 'Unknown') + ' — ' + r.fac_reviewed_at);
            html += '</div>';
        }

        // Minister decision block (policy section 9)
        if (r.minister_at) {
            html += '<h6 class="fw-bold text-purple border-bottom pb-1 mt-3">Minister Decision</h6><div class="row g-0">';
            html += row('Decision', (r.minister_decision || '').replace(/_/g, ' ').toUpperCase());
            html += row('Comments / Conditions', r.minister_comments);
            html += row('Minister', (r.minister_by_name || 'Unknown') + ' — ' + r.minister_at);
            html += '</div>';
        }

        // Confirmation (policy section 20)
        if (r.confirmed_at) {
            html += '<h6 class="fw-bold text-success border-bottom pb-1 mt-3">Facilities Confirmation</h6><div class="row g-0">';
            html += row('Confirmed By', (r.confirmed_by_name || 'Unknown') + ' — ' + r.confirmed_at);
            html += '</div>';
        }
        if (r.cancelled_at) {
            html += '<div class="alert alert-secondary small py-2 mt-3 mb-0">'
                 +  '<i class="bi bi-slash-circle me-1"></i>Cancelled on ' + esc(r.cancelled_at) + '.</div>';
        }
        return html;
    }

    // ── Append-only approval history ────────────────────────────────────────

    var ACTION_LABEL = {
        submitted: 'Submitted', facilities_reviewed: 'Facilities Reviewed',
        approved: 'Approved (Minister step)', declined: 'Declined (Minister step)',
        clarification_requested: 'Returned for Clarification',
        confirmed: 'CONFIRMED — official reservation',
        cancelled: 'Cancelled', edited: 'Edited'
    };
    var ACTION_ICON = {
        submitted: 'bi-send text-info', facilities_reviewed: 'bi-clipboard-check text-primary',
        approved: 'bi-hand-thumbs-up text-purple', declined: 'bi-x-circle text-danger',
        clarification_requested: 'bi-question-circle text-warning',
        confirmed: 'bi-calendar2-check text-success',
        cancelled: 'bi-slash-circle text-secondary', edited: 'bi-pencil text-secondary'
    };

    window.openFacilityHistory = function (id, title) {
        var loading = el('fh_loading'), list = el('fh_list'), empty = el('fh_empty');
        el('fh_title').textContent = title || ('#' + id);
        loading.classList.remove('d-none');
        list.classList.add('d-none');
        list.innerHTML = '';
        empty.classList.add('d-none');
        show('facilityHistoryModal').show();

        fetch('index.php?action=ajaxFacilityRequestHistory&id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                loading.classList.add('d-none');
                var entries = (data && data.entries) ? data.entries : [];
                if (!entries.length) { empty.classList.remove('d-none'); return; }
                entries.forEach(function (e) {
                    var li = document.createElement('li');
                    li.className = 'list-group-item';
                    var when = e.performed_at
                        ? new Date(e.performed_at.replace(' ', 'T')).toLocaleString('en-US',
                            { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
                        : '';
                    var who   = e.performed_by_name || 'Unknown';
                    var icon  = ACTION_ICON[e.action] || 'bi-clock-history text-secondary';
                    var label = ACTION_LABEL[e.action] || e.action;
                    var move  = (e.from_status && e.from_status !== e.to_status)
                        ? '<div class="small text-muted">'
                          + esc(STATUS_LABELS[e.from_status] || e.from_status) + ' &rarr; '
                          + '<span class="badge bg-' + (STATUS_BADGES[e.to_status] || 'secondary')
                          + (e.to_status === CONFIRMED ? ' fb-official' : '') + '">'
                          + esc(STATUS_LABELS[e.to_status] || e.to_status) + '</span></div>'
                        : '';
                    li.innerHTML = '<div class="small text-muted">' + esc(when) + '</div>'
                        + '<div><i class="bi ' + icon + ' me-1"></i><strong>' + esc(label) + '</strong> by ' + esc(who) + '</div>'
                        + move
                        + (e.notes ? '<div class="small fst-italic mt-1">"' + esc(e.notes) + '"</div>' : '');
                    list.appendChild(li);
                });
                list.classList.remove('d-none');
            })
            .catch(function () {
                loading.classList.add('d-none');
                empty.classList.remove('d-none');
                empty.textContent = 'Could not load the approval history.';
            });
    };
})();
</script>
