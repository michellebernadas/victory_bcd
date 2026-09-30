/**
 * Spiritual Foundations page behaviour.
 *
 * Mirrors the Leadership 1-1-3 page (DataTable + client-side filter hooks +
 * Select2 modals + CSV/Excel/PDF/print export) and adds the topic/session
 * filter. Certificate eligibility is decided server-side and rendered into the
 * row; nothing here re-derives it.
 */
/* global $, bootstrap, XLSX, Chart */
(function () {
    'use strict';

    var CFG = {};

    // ── Session grid helpers ────────────────────────────────────────────────

    /**
     * Opens the per-record topic breakdown. The table cell only carries the
     * compact week-badge strip, so the full topic / date / status list lives
     * here instead of stretching every row to ten lines tall.
     */
    window.openSfTopicsModal = function (d) {
        var body = document.getElementById('sfTopicsModalBody');
        var sub  = document.getElementById('sfTopicsModalSub');
        if (!body) return;

        if (sub) {
            var bits = [d.name];
            if (d.batch) bits.push(d.batch);
            if (d.year)  bits.push(d.year);
            sub.textContent = '— ' + bits.join(' · ');
        }

        body.innerHTML = '';
        (d.rows || []).forEach(function (r) {
            var tr = document.createElement('tr');

            var tdWk = document.createElement('td');
            var b    = document.createElement('span');
            b.className = 'badge ' + r.badge;
            b.style.fontSize = '10px';
            b.textContent = 'W' + r.week;
            tdWk.appendChild(b);

            var tdTopic = document.createElement('td');
            tdTopic.className = 'small fw-semibold';
            tdTopic.textContent = r.topic || '—';

            var tdDate = document.createElement('td');
            tdDate.className = 'small text-muted';
            tdDate.textContent = r.date || '—';

            // Short status text, with the full explanation on hover — the badge
            // in the Week column already carries the code, so the cell stays terse.
            var tdStatus = document.createElement('td');
            tdStatus.className = 'small text-nowrap';
            tdStatus.textContent = r.status || '';
            if (r.hint) tdStatus.title = r.hint;

            tr.appendChild(tdWk); tr.appendChild(tdTopic);
            tr.appendChild(tdDate); tr.appendChild(tdStatus);
            body.appendChild(tr);
        });

        var el = document.getElementById('sfTopicsModal');
        if (el) (bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el)).show();
    };

    function toDateInputVal(d) {
        if (!d) return '';
        if (/^\d{4}-\d{2}-\d{2}$/.test(d)) return d;
        var parts = String(d).split('/');
        if (parts.length === 3 && parts[2].length === 4) {
            return parts[2] + '-' + parts[0].padStart(2, '0') + '-' + parts[1].padStart(2, '0');
        }
        return d;
    }

    /**
     * Fills a modal's rows from a stored {week: status} map. Matching by WEEK
     * (not row position) means a curriculum change can't shift a saved status
     * onto the wrong topic.
     */
    function fillSessionRows(form, weeks) {
        weeks = weeks || {};
        form.querySelectorAll('.sf-session-tbody tr').forEach(function (tr) {
            var wk       = tr.dataset.week;
            var statusEl = tr.querySelector('.sf-status');
            var dateEl   = tr.querySelector('.sf-date');
            var entry    = Object.prototype.hasOwnProperty.call(weeks, wk) ? weeks[wk] : null;
            if (statusEl) statusEl.value = entry !== null && entry !== undefined && entry !== '' ? entry : 'P';
            // Dates are per-batch; the caller fills them separately when known.
            if (dateEl && entry === null) dateEl.value = '';
        });
    }

    /** Fills the date column from the record's stored session dates, in order. */
    function fillSessionDates(form, sessions) {
        var keys = sessions ? Object.keys(sessions) : [];
        form.querySelectorAll('.sf-session-tbody tr').forEach(function (tr, i) {
            var dateEl = tr.querySelector('.sf-date');
            if (dateEl) dateEl.value = i < keys.length ? toDateInputVal(keys[i]) : '';
        });
    }

    // ── Edit modal ──────────────────────────────────────────────────────────
    window.openEditSfModal = function (rec) {
        var form = document.getElementById('editSfForm');
        if (!form) return;
        form.action = 'index.php?action=updateSfRecord&id=' + rec.id;

        ['raw_first_name', 'raw_last_name', 'contact_number'].forEach(function (k) {
            var el = form.querySelector('[name="' + k + '"]');
            if (el) el.value = rec[k] || '';
        });

        function setSelect2(name, value, label) {
            var $sel = $(form).find('[name="' + name + '"]');
            if (!$sel.length) return;
            value = value || '';
            label = label || value;
            if (value && !$sel.find('option[value="' + value + '"]').length) {
                $sel.append(new Option(label, value, false, false));
            }
            $sel.val(value || null);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.trigger('change.select2');
        }
        setSelect2('program_year', String(rec.program_year || ''), String(rec.program_year || ''));
        setSelect2('sc_batch',     rec.sc_batch || '',             rec.sc_batch || '');

        var $mem = $(form).find('[name="member_id"]');
        if ($mem.length) {
            if (rec.member_id) {
                var label = rec.member_name + (rec.member_ministry ? ' — ' + String(rec.member_ministry).toUpperCase() : '');
                if (!$mem.find('option[value="' + rec.member_id + '"]').length) {
                    $mem.append(new Option(label, rec.member_id, true, true));
                } else {
                    $mem.val(rec.member_id);
                }
            } else {
                if (!$mem.find('option[value="__unmatched__"]').length) {
                    $mem.append(new Option('Unmatched (not yet a member)', '__unmatched__', false, false));
                }
                $mem.val('__unmatched__');
            }
            if ($mem.hasClass('select2-hidden-accessible')) $mem.trigger('change.select2');
        }

        fillSessionRows(form, rec.weeks);
        fillSessionDates(form, rec.sessions);

        var modalEl = document.getElementById('editSfModal');
        var modal   = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
        modal.show();
    };

    // ── Export helpers (respect current DataTables filtering + column visibility) ──
    function sfTableData() {
        var headers = [];
        document.querySelectorAll('#sfTable thead th').forEach(function (th, i, all) {
            if (i === all.length - 1) return;               // skip Actions
            headers.push(th.textContent.replace(/\s+/g, ' ').trim());
        });
        var rows = [];
        if (!window.sfTable) return { headers: headers, rows: rows };
        window.sfTable.rows({ search: 'applied' }).every(function () {
            var tr  = this.node();
            var tds = tr.querySelectorAll('td');
            var row = [];
            for (var i = 0; i < tds.length - 1; i++) {
                var c = tds[i].cloneNode(true);
                c.querySelectorAll('button, .progress, style').forEach(function (el) { el.remove(); });
                row.push(c.textContent.replace(/\s+/g, ' ').trim());
            }
            rows.push(row);
        });
        return { headers: headers, rows: rows };
    }

    window.exportSfCsv = function () {
        var d = sfTableData();
        var lines = [d.headers.map(function (h) { return '"' + h.replace(/"/g, '""') + '"'; }).join(',')];
        d.rows.forEach(function (r) {
            lines.push(r.map(function (c) { return '"' + (c || '').replace(/"/g, '""') + '"'; }).join(','));
        });
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'spiritual_foundations_' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
    };

    window.exportSfExcel = function () {
        var d = sfTableData();
        if (typeof XLSX === 'undefined') { window.exportSfCsv(); return; }
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Spiritual Foundations');
        XLSX.writeFile(wb, 'spiritual_foundations_' + new Date().toISOString().slice(0, 10) + '.xlsx');
    };

    window.exportSfPdf = function () {
        var d = sfTableData();
        var JsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : null;
        if (!JsPDF) { alert('PDF export library not loaded.'); return; }
        var doc = new JsPDF({ orientation: 'landscape' });
        doc.setFontSize(13);
        doc.text('Spiritual Foundations Records', 14, 14);
        doc.autoTable({ head: [d.headers], body: d.rows, startY: 20, styles: { fontSize: 7 }, headStyles: { fillColor: [13, 202, 240] } });
        doc.save('spiritual_foundations_' + new Date().toISOString().slice(0, 10) + '.pdf');
    };

    window.printSf = function () {
        var d = sfTableData();
        var html = '<!DOCTYPE html><html><head><title>Spiritual Foundations Records</title>'
            + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
            + 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
            + 'th{background:#0dcaf0;color:#fff}tr:nth-child(even){background:#f5f7fa}'
            + '@media print{@page{size:landscape}}</style></head><body>'
            + '<h2>Spiritual Foundations Records</h2>'
            + '<p>Printed: ' + new Date().toLocaleDateString() + ' &nbsp;|&nbsp; ' + d.rows.length + ' record(s)</p>'
            + '<table><thead><tr>' + d.headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr></thead><tbody>'
            + d.rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + (c || '—') + '</td>'; }).join('') + '</tr>'; }).join('')
            + '</tbody></table></body></html>';
        var w = window.open('', '_blank');
        w.document.write(html); w.document.close(); w.focus();
        setTimeout(function () { w.print(); }, 400);
    };

    /**
     * Exports a chart as PNG at its FULL rendered size.
     *
     * Reads the canvas' backing-store dimensions (canvas.width/height, which
     * Chart.js sets from the container × devicePixelRatio) rather than the CSS
     * box, so the image is never cropped to a stale or scaled size. Also paints
     * a white background first — a bare Chart.js canvas is transparent, which
     * renders as black in most image viewers.
     */
    window.sfExportChartPng = function (canvasId, filename) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        var w = canvas.width, h = canvas.height;
        if (!w || !h) return;

        var out = document.createElement('canvas');
        out.width = w; out.height = h;
        var ctx = out.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, w, h);
        ctx.drawImage(canvas, 0, 0, w, h);

        var link = document.createElement('a');
        link.href = out.toDataURL('image/png');
        link.download = (filename || canvasId) + '_' + new Date().toISOString().slice(0, 10) + '.png';
        link.click();
    };

    // ── Page init ───────────────────────────────────────────────────────────
    window.initSpiritualFoundationsPage = function () {
        CFG = window.SF_CONFIG || {};
        if (!window.jQuery) return;

        $(function () {
            initRecordsTab();
            initStatsCharts();
            initModals();
        });
    };

    function updateFilterBadge() {
        var n = CFG.serverFilters || 0;
        if (window.sfMatchFilter && window.sfMatchFilter !== 'all') n++;
        if (window.sfCertFilter  && window.sfCertFilter  !== 'all') n++;
        if (window.sfTopicWeek) n++;
        var live = (document.getElementById('sfSearch') || {}).value || '';
        if (live.trim() && live !== (CFG.serverSearch || '')) n++;
        var badge = document.getElementById('sfActiveFilterBadge');
        if (badge) { badge.textContent = n + ' active'; badge.style.display = n > 0 ? '' : 'none'; }
        var clear = document.getElementById('sfClearFiltersBtn');
        if (clear) clear.style.display = n > 0 ? '' : 'none';
    }

    function setBtnGroupState(selector, attr, value) {
        document.querySelectorAll(selector).forEach(function (btn) {
            var v = btn.dataset[attr];
            var active = v === value;
            var base = ({ matched: 'success', unmatched: 'secondary', eligible: 'success', not: 'warning' })[v]
                || (selector.indexOf('cert') !== -1 ? 'info' : 'primary');
            btn.className = 'btn btn-sm ' + selector.replace('.', '') + ' '
                + (active ? 'btn-' + base + (base === 'info' ? ' text-white' : '') : 'btn-outline-' + base);
        });
    }

    function initRecordsTab() {
        var tableEl = document.getElementById('sfTable');
        if (!tableEl || typeof $.fn.DataTable === 'undefined') return;

        window.sfMatchFilter = CFG.activeMatch || 'all';
        window.sfCertFilter  = 'all';
        window.sfTopicWeek   = CFG.activeSession ? String(CFG.activeSession) : '';
        window.sfTopicStatus = '';

        window.sfTable = $('#sfTable').DataTable({
            responsive: true,
            pageLength: 25,
            searching: true,
            dom: 'rt<"d-flex justify-content-between align-items-center mt-2 px-2"ip>',
            order: [[1, 'asc']],
            columnDefs: [{ targets: 0, orderable: false, searchable: false }],
            drawCallback: function () {
                this.api().column(0, { page: 'current' }).nodes().each(function (cell, i) { cell.innerHTML = i + 1; });
                var badge = document.getElementById('sfCountBadge');
                if (badge) badge.textContent = this.api().page.info().recordsDisplay;
            }
        });

        // Combined client-side filter: match status + certificate + topic/week.
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
            if (!settings.nTable || settings.nTable.id !== 'sfTable') return true;
            var node = settings.aoData[dataIndex] && settings.aoData[dataIndex].nTr;
            if (!node) return true;

            if (window.sfMatchFilter === 'matched'   && node.getAttribute('data-matched') !== '1') return false;
            if (window.sfMatchFilter === 'unmatched' && node.getAttribute('data-matched') !== '0') return false;
            if (window.sfCertFilter  === 'eligible'  && node.getAttribute('data-cert')    !== '1') return false;
            if (window.sfCertFilter  === 'not'       && node.getAttribute('data-cert')    !== '0') return false;

            if (window.sfTopicWeek) {
                var weeks = {};
                try { weeks = JSON.parse(node.getAttribute('data-weeks') || '{}'); } catch (e) { weeks = {}; }
                if (!Object.prototype.hasOwnProperty.call(weeks, window.sfTopicWeek)) return false;
                if (window.sfTopicStatus) {
                    // Participant-level statuses only. Class-level cancellations are never
                    // stored on an SF participant row, so they are not filterable here.
                    var st = String(weeks[window.sfTopicWeek] || '').trim().toUpperCase();
                    var present  = ['P', 'MUSIC SUMMIT', 'KIDS SUMMIT'].indexOf(st) !== -1;
                    var late     = st === 'L';
                    var notReq   = st === 'NC';
                    var absent   = st === 'A';
                    if (window.sfTopicStatus === 'present'  && !present) return false;
                    if (window.sfTopicStatus === 'late'     && !late)    return false;
                    if (window.sfTopicStatus === 'absent'   && !absent)  return false;
                    if (window.sfTopicStatus === 'nc'       && !notReq)  return false;
                    if (window.sfTopicStatus === 'attended' && !(present || late)) return false;
                }
            }
            return true;
        });

        function redraw() {
            try { $('#sfTable').DataTable().draw(); } catch (e) { /* table not ready */ }
            updateFilterBadge();
        }

        document.querySelectorAll('.sf-match-filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                window.sfMatchFilter = btn.dataset.match;
                setBtnGroupState('.sf-match-filter-btn', 'match', window.sfMatchFilter);
                redraw();
            });
        });
        document.querySelectorAll('.sf-cert-filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                window.sfCertFilter = btn.dataset.cert;
                setBtnGroupState('.sf-cert-filter-btn', 'cert', window.sfCertFilter);
                redraw();
            });
        });

        var topicSel  = document.getElementById('sfTopicFilter');
        var statusSel = document.getElementById('sfTopicStatusFilter');
        function onTopicChange() {
            window.sfTopicWeek   = topicSel ? topicSel.value : '';
            window.sfTopicStatus = statusSel ? statusSel.value : '';
            var badge = document.getElementById('sfTopicBadge');
            if (badge) {
                if (window.sfTopicWeek && topicSel) {
                    badge.style.display = '';
                    badge.innerHTML = '<i class="bi bi-journal-text me-1"></i>' +
                        topicSel.options[topicSel.selectedIndex].text;
                } else {
                    badge.style.display = 'none';
                }
            }
            redraw();
        }
        if (topicSel)  topicSel.addEventListener('change', onTopicChange);
        if (statusSel) statusSel.addEventListener('change', onTopicChange);

        var timer;
        $('#sfSearch').on('input', function () {
            var val = $(this).val();
            clearTimeout(timer);
            timer = setTimeout(function () {
                try { $('#sfTable').DataTable().search(val).draw(); } catch (e) { /* noop */ }
                updateFilterBadge();
            }, 250);
        });
        $('#sfSearchClear').on('click', function () {
            $('#sfSearch').val('');
            try { $('#sfTable').DataTable().search('').draw(); } catch (e) { /* noop */ }
            updateFilterBadge();
        });
        $('#sfPerPage').on('change', function () {
            try { $('#sfTable').DataTable().page.len(parseInt($(this).val(), 10)).draw(); } catch (e) { /* noop */ }
        });

        $(document).on('click', '.sf-cert-btn', function () {
            alert('Certificate for ' + ($(this).data('name') || 'this participant')
                + ' is unlocked — all required sessions are completed.\n\n'
                + 'Certificate generation is not built yet; this button only reflects eligibility.');
        });

        // Apply initial state restored from the URL.
        setBtnGroupState('.sf-match-filter-btn', 'match', window.sfMatchFilter);
        setBtnGroupState('.sf-cert-filter-btn',  'cert',  window.sfCertFilter);
        onTopicChange();
    }

    /**
     * Base options for every SF chart.
     *
     * maintainAspectRatio:false is the fix for the zoomed/cropped rendering:
     * with it off, the canvas fills its .chart-box parent (fixed height, fluid
     * width) instead of deriving its own aspect ratio from the card width and
     * overflowing. resizeDelay smooths window-resize reflow. Same shape the
     * dashboard charts use, just made explicit.
     */
    function baseOptions(extra) {
        var o = {
            responsive: true,
            maintainAspectRatio: false,
            resizeDelay: 120,
            layout: { padding: { top: 4, right: 8, bottom: 0, left: 0 } },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }
        };
        return Object.assign(o, extra || {});
    }

    /** Shared colours, matching the badge language in ProgramAttendance::statusStyle(). */
    var C = {
        present:  'rgba(25,135,84,.85)',    // bg-success
        late:     'rgba(255,193,7,.85)',    // bg-warning
        absent:   'rgba(220,53,69,.85)',    // bg-danger
        complete: 'rgba(25,135,84,.85)',
        pending:  'rgba(255,193,7,.85)',
        info:     'rgba(13,202,240,.85)',
        muted:    'rgba(108,117,125,.75)'
    };

    /** Truncates long topic labels so the axis stays readable. */
    function shortLabel(s, max) {
        s = String(s || '');
        return s.length > (max || 22) ? s.slice(0, (max || 22) - 1) + '…' : s;
    }

    function initStatsCharts() {
        if (typeof Chart === 'undefined' || !window.SF_STATS) return;
        var S = window.SF_STATS;

        // A. Completion status — overall cumulative.
        var elC = document.getElementById('sfCompletionChart');
        if (elC && (S.completion.complete + S.completion.incomplete) > 0) {
            new Chart(elC, {
                type: 'doughnut',
                data: {
                    labels: ['Complete', 'Incomplete'],
                    datasets: [{
                        data: [S.completion.complete, S.completion.incomplete],
                        backgroundColor: [C.complete, C.pending],
                        borderWidth: 1
                    }]
                },
                options: baseOptions({ cutout: '58%' })
            });
        }

        // B. Attendance by topic — stacked P / L / A with an attendance-rate line.
        //    NC is excluded from both the bars and the rate: the topic wasn't
        //    required of that participant in that batch.
        var elW = document.getElementById('sfWeekChart');
        if (elW && S.weekLabels.length) {
            var rate = S.weekLabels.map(function (_, i) {
                var done  = (S.weekPresent[i] || 0) + (S.weekLate[i] || 0);
                var denom = done + (S.weekAbsent[i] || 0);
                return denom > 0 ? Math.round(done / denom * 100) : null;
            });
            // Axis ticks stay short ("W3"); the full topic lives in the tooltip title,
            // so nothing is clipped however narrow the card gets.
            var shortTicks = S.weekLabels.map(function (l) {
                return String(l).split(' · ')[0];
            });

            new Chart(elW, {
                type: 'bar',
                data: {
                    labels: shortTicks,
                    datasets: [
                        { label: 'Present', data: S.weekPresent, backgroundColor: C.present, borderWidth: 0,
                          borderRadius: 3, stack: 'att', order: 2 },
                        { label: 'Late',    data: S.weekLate,    backgroundColor: C.late,    borderWidth: 0,
                          borderRadius: 3, stack: 'att', order: 2 },
                        { label: 'Absent',  data: S.weekAbsent,  backgroundColor: C.absent,  borderWidth: 0,
                          borderRadius: 3, stack: 'att', order: 2 },
                        { label: 'Attendance rate', data: rate, type: 'line', yAxisID: 'y1',
                          borderColor: 'rgba(13,110,253,.9)', backgroundColor: 'rgba(13,110,253,.9)',
                          borderWidth: 2, pointRadius: 3, pointHoverRadius: 5, tension: .3,
                          spanGaps: true, order: 1 }
                    ]
                },
                options: baseOptions({
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x:  { stacked: true, grid: { display: false },
                              ticks: { autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: 11 } } },
                        y:  { stacked: true, beginAtZero: true, position: 'left',
                              title: { display: true, text: 'Participants', font: { size: 10 } },
                              ticks: { stepSize: 1, precision: 0 } },
                        y1: { beginAtZero: true, max: 100, position: 'right',
                              title: { display: true, text: 'Attendance rate %', font: { size: 10 } },
                              grid: { drawOnChartArea: false },
                              ticks: { callback: function (v) { return v + '%'; }, font: { size: 10 } } }
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                        tooltip: {
                            callbacks: {
                                // Full "W3 · Creation, The Fall and Sin" heading.
                                title: function (items) { return S.weekLabels[items[0].dataIndex]; },
                                label: function (item) {
                                    if (item.dataset.yAxisID === 'y1') {
                                        return item.raw === null ? 'Attendance rate: n/a'
                                                                 : 'Attendance rate: ' + item.raw + '%';
                                    }
                                    return item.dataset.label + ': ' + item.raw;
                                },
                                footer: function (items) {
                                    var i = items[0].dataIndex;
                                    var counted = (S.weekPresent[i] || 0) + (S.weekLate[i] || 0) + (S.weekAbsent[i] || 0);
                                    return 'Counted: ' + counted + ' (NC excluded)';
                                }
                            }
                        }
                    }
                })
            });
        }

        // C. Completion rate by batch.
        var elB = document.getElementById('sfBatchChart');
        if (elB && S.batchLabels.length) {
            new Chart(elB, {
                type: 'bar',
                data: {
                    labels: S.batchLabels,
                    datasets: [
                        { label: 'Completed in batch',  data: S.batchDone,    backgroundColor: C.complete, borderWidth: 1 },
                        { label: 'Incomplete in batch', data: S.batchPending, backgroundColor: C.pending,  borderWidth: 1 }
                    ]
                },
                options: baseOptions({
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 } } },
                        y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                    }
                })
            });
        }

        // D. Most missed topics — horizontal, so long topic names stay readable.
        var elM = document.getElementById('sfMissedChart');
        if (elM && S.missedLabels.length) {
            new Chart(elM, {
                type: 'bar',
                data: {
                    labels: S.missedLabels.map(function (l) { return shortLabel(l, 30); }),
                    datasets: [
                        { label: 'Absences',         data: S.missedAbsent,      backgroundColor: C.absent, borderWidth: 1 },
                        { label: 'Still outstanding', data: S.missedOutstanding, backgroundColor: C.muted,  borderWidth: 1 }
                    ]
                },
                options: baseOptions({
                    indexAxis: 'y',
                    scales: {
                        x: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } },
                        y: { grid: { display: false }, ticks: { font: { size: 10 } } }
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                        tooltip: { callbacks: { title: function (items) { return S.missedLabels[items[0].dataIndex]; } } }
                    }
                })
            });
        }
    }

    function initModals() {
        if (typeof $.fn.select2 === 'undefined') return;

        $('.sf-year-select2').each(function () {
            $(this).select2({ dropdownParent: $(this).closest('.modal'), placeholder: 'Year…', tags: true });
        });
        $('.sf-batch-select2').each(function () {
            $(this).select2({ dropdownParent: $(this).closest('.modal'), placeholder: 'Select or type batch name…', allowClear: true, tags: true });
        });
        $('.sf-member-select2').each(function () {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Search or browse members…',
                allowClear: true,
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
        });

        $(document).on('click', '.sf-clear-member', function () {
            var $sel = $(this).closest('form').find('.sf-member-select2');
            if (!$sel.length) return;
            if (!$sel.find('option[value="__unmatched__"]').length) {
                $sel.append(new Option('Unmatched (not yet a member)', '__unmatched__', false, false));
            }
            $sel.val('__unmatched__').trigger('change');
        });

        // Convert the sentinel back to "" so the controller stores NULL.
        $(document).on('submit', '#addSfForm, #editSfForm', function () {
            var $sel = $(this).find('.sf-member-select2');
            if ($sel.length && $sel.val() === '__unmatched__') $sel.val('').trigger('change');
        });

        $(document).on('click', '.sf-mark-all-present', function () {
            $(this).closest('form').find('.sf-status').val('P');
        });

        // Catch-up batch shortcut: everything NC, then set the few topics being retaken.
        $(document).on('click', '.sf-mark-all-nc', function () {
            $(this).closest('form').find('.sf-status').val('NC');
        });

        // Fill week 2..N weekly from the first non-empty date.
        $(document).on('click', '.sf-autofill-dates', function () {
            var rows  = $(this).closest('form').find('.sf-session-tbody tr');
            var first = rows.eq(0).find('.sf-date').val();
            if (!first) { alert('Set the Week 1 date first.'); return; }
            var d = new Date(first + 'T00:00:00');
            rows.each(function (i) {
                if (i === 0) return;
                d.setDate(d.getDate() + 7);
                $(this).find('.sf-date').val(d.toISOString().slice(0, 10));
            });
        });

        // Auto-link participant to an existing member by exact "Last, First" match.
        $(document).on('focusout', '.sf-first, .sf-last', function () {
            var $form = $(this).closest('form');
            var first = ($form.find('.sf-first').val() || '').trim();
            var last  = ($form.find('.sf-last').val()  || '').trim();
            if (!first || !last) return;
            var $mem = $form.find('.sf-member-select2');
            var cur  = $mem.val();
            if (cur && cur !== '' && cur !== '__unmatched__') return;
            $.getJSON('index.php?action=ajaxFindMemberByName', { name: last + ', ' + first }, function (resp) {
                if (!resp || !resp.match) return;
                var m = resp.match;
                var label = m.name + (m.ministry ? ' — ' + String(m.ministry).toUpperCase() : '');
                if (!$mem.find('option[value="' + m.id + '"]').length) {
                    $mem.append(new Option(label, m.id, true, true));
                }
                $mem.val(String(m.id)).trigger('change');
            });
        });
    }
})();
