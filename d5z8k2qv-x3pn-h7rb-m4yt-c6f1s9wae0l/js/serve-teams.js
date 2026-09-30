/**
 * Serve Teams page behaviour — DataTable, Select2 people pickers, edit modal
 * pre-fill and exports. People pickers reuse the existing ajaxSearchMembers
 * endpoint so a team member is linked to members.id whenever possible.
 */
/* global $, bootstrap, XLSX */
(function () {
    'use strict';

    var CFG = {};

    /**
     * Runs each step independently: one failing step must not silently take out
     * the others (a Select2 exception used to kill the auto-fill and the option
     * editor, which then looked like "function is not defined" on click).
     */
    function runStep(name, fn) {
        try { fn(); }
        catch (e) { if (window.console) console.error('[serve-teams] ' + name + ' failed:', e); }
    }

    window.initServeTeamsPage = function () {
        CFG = window.SERVE_CONFIG || { serviceDefaults: {} };
        if (!window.jQuery) return;
        // Document-delegated handlers don't need the DOM to exist yet, so they
        // are bound immediately — never behind the ready callback, and never
        // behind a step that might throw.
        runStep('initServiceAutofill', initServiceAutofill);
        runStep('initValidation',      initValidation);
        $(function () {
            runStep('initTable',   initTable);
            runStep('initSelects', initSelects);
        });
    };

    // ── Service → Day / Time auto-fill ──────────────────────────────────────

    /**
     * Picking a service fills in its usual day and time, and offers its extra
     * slots when it runs more than once (Sunday Worship). Defaults only — the
     * user can change anything afterwards, and an existing value is never
     * overwritten unless they actually change the service.
     */
    function applyServiceDefaults($form, opts) {
        opts = opts || {};
        var svc = $form.find('.st-service').val() || '';
        var def = (CFG.serviceDefaults || {})[svc];
        var $slotWrap = $form.find('.st-slot-wrap');
        var $slot     = $form.find('.st-slot');
        var $note     = $form.find('.st-service-note');

        // Rebuild the slot list for this service. The field stays visible for
        // EVERY service — a single-session service simply offers its one usual
        // time — so the slot is always available, not just for Sunday Worship.
        var prev = $slot.val();
        $slot.find('option').not('[value=""]').remove();
        var times = (def && def.times) ? def.times : [];
        times.forEach(function (t) { $slot.append(new Option(fmtTime(t), t)); });

        var $slotHint = $form.find('.st-slot-hint');
        if (!svc) {
            // Nothing chosen yet — keep the slot out of the way.
            $slotWrap.hide();
            $slot.val('');
        } else {
            $slotWrap.show();
            if (times.length) {
                // Keep the previous pick if this service still offers it,
                // otherwise default to the service's usual time.
                var keep = (prev && times.indexOf(prev) !== -1) ? prev : (def.time || '');
                $slot.val(opts.force ? (def.time || '') : (keep || ''));
                if ($slot.hasClass('select2-hidden-accessible')) $slot.trigger('change.select2');
                if ($slotHint.length) {
                    $slotHint.text(times.length > 1
                        ? times.length + ' times available for this service.'
                        : 'This service runs once, at its usual time.');
                }
            } else {
                $slot.val('');
                if ($slotHint.length) {
                    $slotHint.html('No times set for this service yet — add them under '
                        + '<a href="index.php?action=serveTeams&tab=options">Service &amp; Place Options</a>.');
                }
            }
        }

        if (!def) {
            if ($note.length) $note.text('Picking a service fills in its usual day and time — you can still change them.');
            return;
        }

        // Only overwrite Day / Time when the user changed the service, or the
        // fields are still empty — never clobber a deliberate edit.
        var $day  = $form.find('.st-day');
        var $time = $form.find('.st-time');
        if (opts.force || !($day.val() || []).length) {
            $day.val(def.day ? [def.day] : []);
            if ($day.hasClass('select2-hidden-accessible')) $day.trigger('change.select2');
        }
        if (opts.force || !$time.val()) $time.val(def.time || '');

        if ($note.length) {
            var bits = [];
            if (def.day)  bits.push(def.day);
            if (def.time) bits.push(fmtTime(def.time));
            $note.html(bits.length
                ? '<i class="bi bi-magic me-1"></i>Usual schedule: <strong>' + bits.join(' · ')
                  + '</strong>' + (def.notes ? ' — ' + escapeHtml(def.notes) : '') + '. You can change it.'
                : (def.notes ? escapeHtml(def.notes) : 'Schedule varies — set Day and Time manually.'));
        }
    }

    function fmtTime(t) {
        var m = /^(\d{1,2}):(\d{2})/.exec(String(t || ''));
        if (!m) return String(t || '');
        var h = parseInt(m[1], 10), ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12; if (h === 0) h = 12;
        return h + ':' + m[2] + ' ' + ap;
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function initServiceAutofill() {
        // force=true: the user picked a different service, so refresh the defaults.
        $(document).on('change', '.st-service', function () {
            applyServiceDefaults($(this).closest('form'), { force: true });
        });
    }

    // ── Required-field checks ───────────────────────────────────────────────

    /**
     * Select2 hides the underlying <select>, so the browser cannot focus it to
     * report a native validation message — a required multi-select would block
     * submission with no visible reason. This checks the values directly and
     * shows one clear message instead.
     */
    function initValidation() {
        $(document).on('submit', '.st-form', function (e) {
            var $form = $(this);
            var $err  = $form.find('.st-form-error');
            var checks = [
                ['.st-name',     'Team Name'],
                ['.st-ministry', 'Ministry'],
                ['.st-service',  'Service'],
                ['.st-day',      'Day'],
                ['.st-time',     'Call Time'],
                ['.st-place',    'Place'],
                ['.st-leaders',  'At least one Team Leader'],
                ['.st-members',  'At least one Team Member']
            ];
            var missing = [];
            checks.forEach(function (c) {
                var v = $form.find(c[0]).val();
                var empty = (v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length));
                if (empty) missing.push(c[1]);
            });
            if (missing.length) {
                e.preventDefault();
                if ($err.length) {
                    $err.html('<i class="bi bi-exclamation-triangle-fill me-1"></i>Please complete: <strong>'
                        + missing.map(escapeHtml).join(', ') + '</strong>.').show();
                    $err[0].scrollIntoView({ block: 'nearest' });
                }
                return false;
            }
            if ($err.length) $err.hide();
        });
    }

    // ── Team roster modal ───────────────────────────────────────────────────

    /**
     * Shows a team's full leader + member list. The table cell only carries a
     * count and the first two names, so a 20-person team no longer stretches its
     * row taller than the rest of the table put together.
     *
     * Assigned at load time so the cells' inline onclick always resolves.
     */
    function rosterList(el, people) {
        el.innerHTML = '';
        if (!people || !people.length) {
            var none = document.createElement('div');
            none.className = 'text-muted small';
            none.textContent = 'None';
            el.appendChild(none);
            return;
        }
        people.forEach(function (p, i) {
            var row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2';

            var num = document.createElement('span');
            num.className = 'text-muted';
            num.style.cssText = 'font-size:10px;min-width:18px;text-align:right;';
            num.textContent = (i + 1) + '.';
            row.appendChild(num);

            if (p.member_id) {
                var a = document.createElement('a');
                a.href = 'index.php?action=memberProfile&id=' + p.member_id;
                a.className = 'small text-decoration-none';
                a.innerHTML = '<i class="bi bi-person-fill me-1 text-success"></i>';
                a.appendChild(document.createTextNode(p.name));
                row.appendChild(a);
            } else {
                var span = document.createElement('span');
                span.className = 'small';
                span.appendChild(document.createTextNode(p.name));
                var warn = document.createElement('i');
                warn.className = 'bi bi-exclamation-triangle-fill text-warning ms-1';
                warn.title = 'Not in the Members list';
                span.appendChild(warn);
                row.appendChild(span);
            }
            el.appendChild(row);
        });
    }

    window.openTeamRosterModal = function (t) {
        var sub = document.getElementById('rosterSub');
        if (sub) {
            sub.textContent = '— ' + t.team + (t.service ? ' · ' + t.service : '');
        }
        var lc = document.getElementById('rosterLeaderCount');
        var mc = document.getElementById('rosterMemberCount');
        if (lc) lc.textContent = (t.leaders || []).length;
        if (mc) mc.textContent = (t.members || []).length;
        rosterList(document.getElementById('rosterLeaders'), t.leaders);
        rosterList(document.getElementById('rosterMembers'), t.members);

        var el = document.getElementById('teamRosterModal');
        if (el) (bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el)).show();
    };

    // ── Service / Place option editor (admin tab) ───────────────────────────

    // Assigned at load time, NOT inside a ready callback — the option table uses
    // inline onclick handlers, so these must exist as soon as the script parses,
    // independently of whether any later init step succeeds.
    function setField(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val === null || val === undefined ? '' : val;
    }

    window.openAddServeOption = function (type, label) {
        var form = document.getElementById('serveOptionForm');
        if (!form) return;
        form.action = 'index.php?action=addServeOption';
        var title = document.getElementById('serveOptionModalTitle');
        if (title) title.textContent = 'Add ' + (label || 'Value');
        setField('so_type',  type);
        setField('so_name',  '');
        setField('so_day',   '');
        setField('so_time',  '');
        setField('so_slots', '');
        setField('so_notes', '');
        var act = document.getElementById('so_active');
        if (act) act.checked = true;
        toggleServiceOnly(form, type === 'service');
        var note = document.getElementById('so_usage_note');
        if (note) note.style.display = 'none';
        showOptionModal();
    };

    window.openEditServeOption = function (o) {
        var form = document.getElementById('serveOptionForm');
        if (!form) return;
        form.action = 'index.php?action=updateServeOption&id=' + o.id;
        var title = document.getElementById('serveOptionModalTitle');
        if (title) title.textContent = 'Edit ' + (o.label || 'Value');
        setField('so_type',  o.option_type);
        setField('so_name',  o.name);
        setField('so_day',   o.default_day);
        setField('so_time',  o.default_time);
        setField('so_slots', o.time_options);
        setField('so_notes', o.notes);
        var act = document.getElementById('so_active');
        if (act) act.checked = !!o.is_active;
        toggleServiceOnly(form, o.option_type === 'service');
        var note = document.getElementById('so_usage_note');
        if (note) note.style.display = 'none';
        showOptionModal();
    };

    function toggleServiceOnly(form, show) {
        form.querySelectorAll('.so-service-only').forEach(function (el) {
            el.style.display = show ? '' : 'none';
        });
    }

    function showOptionModal() {
        var el = document.getElementById('serveOptionModal');
        if (el) (bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el)).show();
    }

    function initTable() {
        if (typeof $.fn.DataTable === 'undefined' || !$('#serveTeamsTable').length) return;
        window.serveTeamsTable = $('#serveTeamsTable').DataTable({
            responsive: true,
            pageLength: 25,
            order: [[1, 'asc']],
            columnDefs: [{ targets: 0, orderable: false, searchable: false }],
            language: { search: 'Search:', lengthMenu: 'Show _MENU_ entries per page' },
            drawCallback: function () {
                this.api().column(0, { page: 'current' }).nodes().each(function (cell, i) { cell.innerHTML = i + 1; });
            }
        });
    }

    function peopleSelect2($el) {
        $el.select2({
            dropdownParent: $el.closest('.modal'),
            placeholder: 'Search members, or type a new name…',
            allowClear: true,
            closeOnSelect: false,
            tags: true,                       // allow unregistered names
            minimumInputLength: 0,
            ajax: {
                url: 'index.php?action=ajaxSearchMembers',
                dataType: 'json',
                delay: 200,
                data: function (p) { return { q: p.term || '' }; },
                processResults: function (d) { return { results: (d && d.results) ? d.results : [] }; },
                cache: true
            }
        });
    }

    function initSelects() {
        if (typeof $.fn.select2 === 'undefined') return;
        $('.st-people').each(function () { peopleSelect2($(this)); });
        $('.st-day, .st-place, .st-ministry, .st-service, .st-slot, .st-status').each(function () {
            var $el  = $(this);
            var opts = { dropdownParent: $el.closest('.modal'), width: '100%' };
            if ($el.data('tags')) opts.tags = true;
            if (this.multiple) {
                opts.closeOnSelect = false;
                opts.allowClear    = true;
                // Select2 THROWS when allowClear is set without a placeholder.
                // That exception used to abort the whole init chain, which is why
                // the service auto-fill and the option editor silently vanished.
                opts.placeholder = $el.data('placeholder') || 'Select…';
            }
            $el.select2(opts);
        });
    }

    /** Sets a multi-select's values, injecting options for labels we already know. */
    function setMulti($sel, values, labels) {
        if (!$sel.length) return;
        $sel.find('option').remove();
        (values || []).forEach(function (v, i) {
            $sel.append(new Option((labels && labels[i]) || v, v, true, true));
        });
        $sel.val(values || []).trigger('change');
    }

    window.openEditTeamModal = function (t) {
        var form = document.getElementById('editTeamForm');
        if (!form) return;
        form.action = 'index.php?action=updateServeTeam&id=' + t.id;

        form.querySelector('[name="name"]').value          = t.name || '';
        form.querySelector('[name="meetup_time"]').value   = t.meetup_time || '';
        form.querySelector('[name="notes"]').value         = t.notes || '';

        function setOne(name, value) {
            var $sel = $(form).find('[name="' + name + '"]');
            if (!$sel.length) return;
            value = value || '';
            if (value && !$sel.find('option[value="' + value.replace(/"/g, '\\"') + '"]').length) {
                $sel.append(new Option(value, value, false, false));
            }
            $sel.val(value);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.trigger('change.select2');
        }
        setOne('ministry',      t.ministry);
        setOne('service_name',  t.service_name);
        setOne('meeting_place', t.meeting_place);
        setOne('team_status',   t.team_status || 'active');

        var days = String(t.day_of_week || '').split(',').map(function (d) { return d.trim(); }).filter(Boolean);
        var $day = $(form).find('[name="day_of_week[]"]');
        $day.val(days);
        if ($day.hasClass('select2-hidden-accessible')) $day.trigger('change.select2');

        // Rebuild the slot list for the saved service, THEN restore the saved
        // slot — force:false so the record's own Day/Time win over the defaults.
        applyServiceDefaults($(form), { force: false });
        setOne('service_time', t.service_time);

        setMulti($(form).find('.st-leaders'), t.leader_ids, t.leader_labels);
        setMulti($(form).find('.st-members'), t.member_ids, t.member_labels);
        $(form).find('.st-form-error').hide();

        var modalEl = document.getElementById('editTeamModal');
        (bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl)).show();
    };

    // ── Exports (respect current filtering + column visibility) ─────────────
    function tableData() {
        var headers = [];
        document.querySelectorAll('#serveTeamsTable thead th').forEach(function (th, i, all) {
            if (i === all.length - 1) return;
            headers.push(th.textContent.replace(/\s+/g, ' ').trim());
        });
        var rows = [];
        if (!window.serveTeamsTable) return { headers: headers, rows: rows };
        window.serveTeamsTable.rows({ search: 'applied' }).every(function () {
            var tds = this.node().querySelectorAll('td');
            var row = [];
            for (var i = 0; i < tds.length - 1; i++) {
                // The Leaders / Members cells only DISPLAY a count plus two names,
                // so they publish the complete list in data-export. Without this
                // the exports would silently lose most of the roster.
                if (tds[i].dataset && tds[i].dataset.export !== undefined) {
                    row.push(tds[i].dataset.export);
                    continue;
                }
                var c = tds[i].cloneNode(true);
                c.querySelectorAll('button, style').forEach(function (el) { el.remove(); });
                row.push(c.textContent.replace(/\s+/g, ' ').trim());
            }
            rows.push(row);
        });
        return { headers: headers, rows: rows };
    }

    window.exportServeTeamsCsv = function () {
        var d = tableData();
        var lines = [d.headers.map(function (h) { return '"' + h.replace(/"/g, '""') + '"'; }).join(',')];
        d.rows.forEach(function (r) {
            lines.push(r.map(function (c) { return '"' + (c || '').replace(/"/g, '""') + '"'; }).join(','));
        });
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'serve_teams_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
    };

    window.exportServeTeamsExcel = function () {
        var d = tableData();
        if (typeof XLSX === 'undefined') { window.exportServeTeamsCsv(); return; }
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Serve Teams');
        XLSX.writeFile(wb, 'serve_teams_' + new Date().toISOString().slice(0, 10) + '.xlsx');
    };

    window.printServeTeams = function () {
        var d = tableData();
        var html = '<!DOCTYPE html><html><head><title>Serve Teams</title>'
            + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
            + 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
            + 'th{background:#1742f5;color:#fff}tr:nth-child(even){background:#f5f7fa}'
            + '@media print{@page{size:landscape}}</style></head><body><h2>Serve Teams</h2>'
            + '<p>Printed: ' + new Date().toLocaleDateString() + '</p><table><thead><tr>'
            + d.headers.map(function (h) { return '<th>' + h + '</th>'; }).join('')
            + '</tr></thead><tbody>'
            + d.rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + (c || '—') + '</td>'; }).join('') + '</tr>'; }).join('')
            + '</tbody></table></body></html>';
        var w = window.open('', '_blank');
        w.document.write(html); w.document.close(); w.focus();
        setTimeout(function () { w.print(); }, 400);
    };
})();
