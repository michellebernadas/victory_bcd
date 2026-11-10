<?php
/**
 * Leaders: every VG / LG / Campus leader and intern line, one row per person
 * (or couple, as written) per group. Filters are client-side; every export
 * (Excel, CSV, PDF, Print) uses exactly the rows the filters leave visible.
 */
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';
$leaderRows    = $leaderRows    ?? [];
$leaderSummary = $leaderSummary ?? [];
$glDays  = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)$r['day_of_week']), $leaderRows))));
$glOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$glPos = fn($d) => ($p = array_search($d, $glOrder, true)) === false ? 9 : $p;
usort($glDays, fn($a, $b) => $glPos($a) <=> $glPos($b));
$glRoles = array_keys($leaderSummary);
// ?role=vg_leader / vg_intern (dashboard cards) preselects the Role filter.
$glPreRole = ['vg_leader' => 'VG Leader', 'vg_intern' => 'VG Intern', 'lg_leader' => 'LG Leader',
              'campus_leader' => 'Campus Leader', 'campus_intern' => 'Campus Intern'][$_GET['role'] ?? ''] ?? '';
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-star me-2 text-info"></i>Leaders</h1>
                    <p class="text-muted mb-0">Everyone leading or interning a Victory Group, Leadership Group or Campus group.</p>
                </div>
                <?php if ($leaderRows): ?>
                <div class="btn-group" role="group" aria-label="Export">
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="glExport('xlsx')"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="glExport('csv')"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="glExport('pdf')"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="glExport('print')"><i class="bi bi-printer me-1"></i>Print</button>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$leaderRows): ?>
            <div class="card"><div class="card-body text-center py-5">
                <i class="bi bi-star display-4 text-muted d-block mb-3"></i>
                <h2 class="h5">No leaders yet</h2>
                <p class="text-muted mb-3">Leaders appear here once groups have a leader or intern.</p>
                <a href="index.php?action=victoryGroups&amp;tab=vg" class="btn btn-sm btn-primary"><i class="bi bi-people-fill me-1"></i>Open Victory Groups</a>
            </div></div>
            <?php else: ?>
            <!-- Unique people by role. Connected people count once even if they lead
                 several groups; names not connected yet are counted by name. -->
            <div class="row g-3 mb-3">
                <?php foreach ($leaderSummary as $glRole => $s):
                    $glC = count($s['connected']); $glN = array_sum($s['names']); ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card h-100 text-center"><div class="card-body py-3">
                        <div class="h3 fw-bold mb-0"><?php echo $glC + $glN; ?></div>
                        <div class="small fw-semibold"><?php echo htmlspecialchars($glRole); ?><?php echo ($glC + $glN) === 1 ? '' : 's'; ?></div>
                        <div class="small text-muted" style="font-size:11px">
                            <?php echo $glC; ?> connected<?php if ($glN): ?> &middot; <span title="Not connected to a member profile yet, so counted by the name as written"><?php echo $glN; ?> by name</span><?php endif; ?>
                        </div>
                    </div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="card mb-3"><div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label for="glQ" class="small fw-semibold text-muted mb-1">Search</label>
                        <input type="search" id="glQ" class="form-control form-control-sm" placeholder="Name, group, venue…">
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="glKind" class="small fw-semibold text-muted mb-1">Group type</label>
                        <select id="glKind" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="Victory Group">Victory Group</option>
                            <option value="Leadership Group">Leadership Group</option>
                            <option value="Campus">Campus</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="glRole" class="small fw-semibold text-muted mb-1">Role</label>
                        <select id="glRole" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($glRoles as $r): ?>
                            <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $r === $glPreRole ? 'selected' : ''; ?>><?php echo htmlspecialchars($r); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="glDay" class="small fw-semibold text-muted mb-1">Day</label>
                        <select id="glDay" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach ($glDays as $d): ?>
                            <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label for="glConn" class="small fw-semibold text-muted mb-1">Connected?</label>
                        <select id="glConn" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="Yes">Yes</option>
                            <option value="No">Not yet</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-1 text-md-end">
                        <button type="button" id="glClear" class="btn btn-sm btn-outline-secondary w-100">Clear</button>
                    </div>
                </div>
                <div class="small text-muted mt-2" id="glShowing" aria-live="polite"></div>
            </div></div>
            <div class="card"><div class="card-body p-0"><div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="glTable">
                    <thead class="table-light"><tr>
                        <th>Name</th><th>Role</th><th>Group type</th><th>Group</th><th>Day &amp; time</th><th>Venue</th><th>Connected?</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($leaderRows as $r):
                        $glTime = $r['meeting_time'] ? date('g:i A', strtotime('2000-01-01 ' . $r['meeting_time'])) : '';
                        $glWhen = trim($r['day_of_week'] . ($glTime ? ' ' . $glTime : ''));
                        $glConn = $r['connected'] >= $r['people'] ? 'Yes' : ($r['connected'] > 0 ? 'Partly' : 'No');
                        $glSolo = (int)$r['people'] === 1 && !empty($r['member_id']);
                    ?>
                    <tr data-kind="<?php echo htmlspecialchars($r['kind_label']); ?>" data-role="<?php echo htmlspecialchars($r['role_label']); ?>"
                        data-day="<?php echo htmlspecialchars((string)$r['day_of_week']); ?>" data-conn="<?php echo $glConn === 'Yes' ? 'Yes' : 'No'; ?>">
                        <td data-export="<?php echo htmlspecialchars($r['written_name'] . ($r['people'] === 2 ? ' (2 people)' : '')); ?>">
                            <?php if ($glSolo): ?>
                            <a href="index.php?action=memberProfile&amp;id=<?php echo (int)$r['member_id']; ?>" class="text-decoration-none"><?php echo htmlspecialchars($r['written_name']); ?></a>
                            <?php else: ?>
                            <?php echo htmlspecialchars($r['written_name']); ?>
                            <?php endif; ?>
                            <?php if ($r['people'] === 2): ?>
                            <span class="badge bg-light text-dark border ms-1">2 people</span>
                            <?php foreach (['member_id' => 'member_name', 'member_id_2' => 'member2_name'] as $ic => $in): if (empty($r[$ic])) continue; ?>
                            <a href="index.php?action=memberProfile&amp;id=<?php echo (int)$r[$ic]; ?>" class="small d-block text-decoration-none"><i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($r[$in]); ?></a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($r['multi']): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border ms-1" title="Leads or interns 2 or more groups">leads 2+ groups</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($r['role_label']); ?></td>
                        <td><?php echo htmlspecialchars($r['kind_label']); ?></td>
                        <td><?php echo htmlspecialchars($r['group_name'] ?: '—'); ?><?php if ($r['group_status'] !== 'active'): ?> <span class="badge bg-secondary">inactive</span><?php endif; ?></td>
                        <td><?php echo htmlspecialchars($glWhen ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($r['location'] ?: '—'); ?></td>
                        <td data-export="<?php echo $glConn === 'Partly' ? 'Partly (1 of 2)' : $glConn; ?>">
                            <?php if ($glConn === 'Yes'): ?><span class="badge bg-success">Yes</span>
                            <?php elseif ($glConn === 'Partly'): ?><span class="badge bg-light text-dark border">1 of 2</span>
                            <?php else: ?><span class="badge bg-light text-secondary border">Not yet</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-center text-muted py-4 d-none" id="glNone">No leaders match these filters.</div>
            </div></div></div>
            <?php endif; ?>
        </div>
    </div>
<?php include 'shared/footer.php'; ?>
<script>
(function () {
    var table = document.getElementById('glTable');
    if (!table) return;
    var rows = Array.prototype.slice.call(table.tBodies[0].rows);
    var q = document.getElementById('glQ'), kind = document.getElementById('glKind'), role = document.getElementById('glRole'),
        day = document.getElementById('glDay'), conn = document.getElementById('glConn');
    function apply() {
        var t = q.value.trim().toLowerCase(), n = 0;
        rows.forEach(function (tr) {
            var ok = (!kind.value || tr.dataset.kind === kind.value) && (!role.value || tr.dataset.role === role.value)
                  && (!day.value || tr.dataset.day === day.value) && (!conn.value || tr.dataset.conn === conn.value)
                  && (!t || tr.textContent.toLowerCase().indexOf(t) !== -1);
            tr.classList.toggle('d-none', !ok);
            if (ok) n++;
        });
        document.getElementById('glShowing').textContent = 'Showing ' + n + ' of ' + rows.length + ' rows';
        document.getElementById('glNone').classList.toggle('d-none', n > 0);
    }
    q.addEventListener('input', apply);
    [kind, role, day, conn].forEach(function (el) { el.addEventListener('change', apply); });
    document.getElementById('glClear').addEventListener('click', function () {
        q.value = ''; kind.value = ''; role.value = ''; day.value = ''; conn.value = ''; apply();
    });
    apply();

    // Exactly the visible (filtered) rows; data-export holds the plain-text cell value.
    function data() {
        var head = Array.prototype.map.call(table.tHead.rows[0].cells, function (c) { return c.textContent.trim(); });
        var body = rows.filter(function (tr) { return !tr.classList.contains('d-none'); }).map(function (tr) {
            return Array.prototype.map.call(tr.cells, function (c) {
                return (c.dataset.export !== undefined ? c.dataset.export : c.textContent).replace(/\s+/g, ' ').trim();
            });
        });
        return { head: head, body: body };
    }
    function fname(ext) { return 'leaders_' + new Date().toISOString().slice(0, 10) + '.' + ext; }
    function download(blob, name) {
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = name;
        document.body.appendChild(a); a.click(); a.remove();
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    window.glExport = function (type) {
        var d = data();
        if (type === 'csv') {
            var csv = [d.head].concat(d.body).map(function (r) {
                return r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
            }).join('\r\n');
            download(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }), fname('csv'));
        } else if (type === 'xlsx' && window.XLSX) {
            var wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([d.head].concat(d.body)), 'Leaders');
            XLSX.writeFile(wb, fname('xlsx'));
        } else if (type === 'pdf' && window.jspdf) {
            var doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
            doc.setFontSize(13); doc.text('Leaders', 40, 36);
            doc.setFontSize(9); doc.setTextColor(120);
            doc.text(d.body.length + ' rows · exported ' + new Date().toLocaleDateString(), 40, 52);
            doc.autoTable({ head: [d.head], body: d.body, startY: 62, styles: { fontSize: 8, cellPadding: 3 },
                            headStyles: { fillColor: [23, 66, 245], textColor: 255 } });
            doc.save(fname('pdf'));
        } else if (type === 'print') {
            var w = window.open('', '_blank');
            if (!w) return;
            w.document.write('<!DOCTYPE html><html><head><title>Leaders</title><style>body{font-family:sans-serif;font-size:12px}'
                + 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}th{background:#eef}</style></head><body>'
                + '<h2>Leaders</h2><p>' + d.body.length + ' rows</p><table><thead><tr>' + d.head.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('')
                + '</tr></thead><tbody>' + d.body.map(function (r) { return '<tr>' + r.map(function (v) { return '<td>' + esc(v) + '</td>'; }).join('') + '</tr>'; }).join('')
                + '</tbody></table></body></html>');
            w.document.close(); w.focus(); w.print();
        }
    };
})();
</script>
