<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
require_once 'models/GatheringAttendance.php';

// Defaults so the page renders cleanly with an empty table.
$records       = $records       ?? [];
$gaStats       = $gaStats       ?? [];
$activeFilters = $activeFilters ?? [];
$gaEditId      = (int)($gaEditId ?? 0);
$gaFilterCount = count(array_filter($activeFilters, fn($v) => $v !== '' && $v !== null));
$gaFilterQs    = http_build_query(array_filter($activeFilters));

include 'shared/header.php';
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-fire me-2 text-primary"></i>Prayer Meeting &amp; Students&rsquo; Hangout</h1>
                    <p class="text-muted mb-0">One head count per gathering per date &mdash; Prayer Meeting (Tue/Wed) and Students&rsquo; Hangout (Fri)</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="index.php#gatheringHealth" class="btn btn-outline-secondary"><i class="bi bi-graph-up me-1"></i>Church Health</a>
                    <?php if (function_exists('canAdminister') && canAdminister()): ?>
                    <a href="index.php?action=attendanceImport" class="btn btn-outline-success"><i class="bi bi-upload me-1"></i>Import spreadsheet</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGaModal">
                        <i class="bi bi-plus-circle me-1"></i>Add Entry
                    </button>
                </div>
            </div>

            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo ['add' => 'Attendance entry has been added.', 'update' => 'Attendance entry has been updated.',
                            'delete' => 'Attendance entry has been deleted.'][$_GET['notif']] ?? 'Done.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_GET['msg'] ?? 'An error occurred.'); ?>
                <?php if ($gaEditId > 0): ?>
                <button type="button" class="btn btn-sm btn-warning ms-2" onclick="gaOpenEditById(<?php echo $gaEditId; ?>)">
                    <i class="bi bi-pencil me-1"></i>Open the existing entry
                </button>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Summary per gathering (respects the date filter) -->
            <div class="row mb-3 g-2">
                <?php foreach (GatheringAttendance::TYPES as $gaKey => $gaDef):
                    $s = $gaStats[$gaKey] ?? ['events' => 0, 'total' => 0, 'average' => 0, 'latest_date' => null, 'latest' => null];
                ?>
                <div class="col-md-6">
                    <div class="card h-100 border-<?php echo $gaDef['color']; ?> border-opacity-50">
                        <div class="card-body py-2">
                            <div class="fw-semibold mb-1"><i class="bi <?php echo $gaDef['icon']; ?> text-<?php echo $gaDef['color']; ?> me-1"></i><?php echo htmlspecialchars($gaDef['label']); ?>
                                <span class="text-muted small fw-normal">&middot; <?php echo htmlspecialchars($gaDef['day']); ?></span></div>
                            <div class="row text-center g-1">
                                <div class="col-4"><div class="h4 fw-bold mb-0 text-<?php echo $gaDef['color']; ?>"><?php echo $s['latest'] === null ? '&mdash;' : (int)$s['latest']; ?></div>
                                    <div class="text-muted" style="font-size:11px;">Latest<?php echo $s['latest_date'] ? ' &middot; ' . date('M j, Y', strtotime($s['latest_date'])) : ''; ?></div></div>
                                <div class="col-4"><div class="h4 fw-bold mb-0"><?php echo $s['events'] ? (int)$s['average'] : '&mdash;'; ?></div>
                                    <div class="text-muted" style="font-size:11px;">Average per <?php echo $gaKey === 'prayer_meeting' ? 'meeting' : 'hangout'; ?></div></div>
                                <div class="col-4"><div class="h4 fw-bold mb-0"><?php echo (int)$s['events']; ?></div>
                                    <div class="text-muted" style="font-size:11px;">Dates recorded</div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="text-white fw-semibold"><i class="bi bi-funnel me-2"></i>Search &amp; Filter
                        <?php if ($gaFilterCount): ?><span class="badge bg-white text-primary ms-1"><?php echo $gaFilterCount; ?> active</span><?php endif; ?>
                    </span>
                    <a href="index.php?action=gatheringAttendance" class="btn btn-sm btn-outline-light"><i class="bi bi-x-circle me-1"></i>Clear</a>
                </div>
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="gatheringAttendance">
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Gathering</label>
                            <select name="gathering_type" class="form-select form-select-sm">
                                <option value="">Both</option>
                                <?php foreach (GatheringAttendance::TYPES as $gaKey => $gaDef): ?>
                                <option value="<?php echo $gaKey; ?>" <?php echo ($activeFilters['gathering_type'] ?? '') === $gaKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($gaDef['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Date from</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($activeFilters['date_from'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Date to</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($activeFilters['date_to'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Search</label>
                            <input type="text" id="gaFilterSearch" class="form-control form-control-sm" placeholder="Notes, date…">
                        </div>
                        <div class="col-md-1"><button type="submit" class="btn btn-sm btn-primary w-100" aria-label="Apply filters"><i class="bi bi-funnel"></i></button></div>
                    </form>
                </div>
            </div>
            <!-- Records -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold"><i class="bi bi-table me-2"></i>Entries
                        <span class="badge bg-white text-dark border" id="gaRecordCount"><?php echo count($records); ?></span></span>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <select id="gaPerPage" class="form-select form-select-sm" style="width:auto;" aria-label="Rows per page">
                            <option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option><option value="-1">All</option>
                        </select>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportGaCSV()"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportGaExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($records)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-calendar-x display-4 mb-3 d-block"></i>
                        No entries<?php echo $gaFilterCount ? ' match these filters' : ' yet'; ?>.<br>
                        <small><?php echo $gaFilterCount
                            ? 'Try adjusting or <a href="index.php?action=gatheringAttendance">clearing the filters</a>.'
                            : 'Use <strong>Add Entry</strong> above to record a Prayer Meeting or Students&rsquo; Hangout.'; ?></small>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="width:100%" id="gaTable">
                            <thead><tr><th>#</th><th>Date</th><th>Gathering</th><th class="text-end">Attendance</th><th>Notes</th><th class="text-center">Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($records as $i => $r):
                                $def = GatheringAttendance::TYPES[$r['gathering_type']] ?? ['label' => $r['gathering_type'], 'color' => 'secondary', 'icon' => 'bi-calendar'];
                                $json = ['id' => (int)$r['id'], 'gathering_type' => $r['gathering_type'], 'event_date' => $r['event_date'],
                                         'attendance' => (int)$r['attendance'], 'notes' => (string)($r['notes'] ?? '')];
                            ?>
                                <tr data-ga-id="<?php echo (int)$r['id']; ?>">
                                    <td class="text-muted small ga-row-num"><?php echo $i + 1; ?></td>
                                    <td class="text-nowrap"><span class="fw-semibold"><?php echo date('M j, Y', strtotime($r['event_date'])); ?></span>
                                        <div class="text-muted" style="font-size:10px;"><?php echo date('l', strtotime($r['event_date'])); ?></div></td>
                                    <td><span class="badge bg-<?php echo $def['color']; ?>-subtle text-<?php echo $def['color']; ?>-emphasis border border-<?php echo $def['color']; ?>-subtle">
                                        <i class="bi <?php echo $def['icon']; ?> me-1"></i><?php echo htmlspecialchars($def['label']); ?></span></td>
                                    <td class="text-end fw-bold"><?php echo (int)$r['attendance']; ?></td>
                                    <td class="small"><?php echo !empty($r['notes']) ? htmlspecialchars($r['notes']) : '<span class="text-muted">—</span>'; ?>
                                        <?php if (!empty($r['recorded_by_name'])): ?><div class="text-muted" style="font-size:10px;"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($r['recorded_by_name']); ?></div><?php endif; ?></td>
                                    <td class="text-center text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit" aria-label="Edit"
                                                onclick='gaOpenEditModal(<?php echo htmlspecialchars(json_encode($json), ENT_QUOTES); ?>)'><i class="bi bi-pencil"></i></button>
                                        <a class="btn btn-sm btn-outline-danger" title="Delete" aria-label="Delete"
                                           href="index.php?action=deleteGatheringAttendance&amp;id=<?php echo (int)$r['id']; ?><?php echo $gaFilterQs ? '&amp;' . htmlspecialchars($gaFilterQs) : ''; ?>"
                                           data-confirm="Delete the <?php echo htmlspecialchars($def['label']); ?> entry for <?php echo date('M j, Y', strtotime($r['event_date'])); ?>? This cannot be undone."><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            $gaModals = [
                ['id' => 'addGaModal',  'form' => 'addGaForm',  'title' => 'Add Entry',  'action' => 'index.php?action=addGatheringAttendance' . ($gaFilterQs ? '&' . $gaFilterQs : ''), 'p' => 'ga_add'],
                ['id' => 'editGaModal', 'form' => 'editGaForm', 'title' => 'Edit Entry', 'action' => '', 'p' => 'ga_edit'],
            ];
            foreach ($gaModals as $m): $p = $m['p']; ?>
            <div class="modal fade" id="<?php echo $m['id']; ?>" tabindex="-1">
                <div class="modal-dialog"><div class="modal-content">
                    <form method="POST" action="<?php echo htmlspecialchars($m['action']); ?>" id="<?php echo $m['form']; ?>" class="ga-form">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="bi bi-fire me-2"></i><?php echo $m['title']; ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold" for="<?php echo $p; ?>_type">Gathering <span class="text-danger">*</span></label>
                                    <select name="gathering_type" id="<?php echo $p; ?>_type" class="form-select" required>
                                        <?php foreach (GatheringAttendance::TYPES as $gaKey => $gaDef): ?>
                                        <option value="<?php echo $gaKey; ?>" <?php echo ($activeFilters['gathering_type'] ?? '') === $gaKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($gaDef['label']); ?> (<?php echo htmlspecialchars($gaDef['day']); ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold" for="<?php echo $p; ?>_date">Date <span class="text-danger">*</span></label>
                                    <input type="date" name="event_date" id="<?php echo $p; ?>_date" class="form-control" required max="<?php echo date('Y-m-d'); ?>">
                                    <div class="form-text small" id="<?php echo $p; ?>_dow"></div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold" for="<?php echo $p; ?>_att">Attendance <span class="text-danger">*</span></label>
                                    <input type="number" name="attendance" id="<?php echo $p; ?>_att" class="form-control" min="0" step="1" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold" for="<?php echo $p; ?>_notes">Notes</label>
                                    <input type="text" name="notes" id="<?php echo $p; ?>_notes" class="form-control" maxlength="255" placeholder="Optional — e.g. week-long prayer event">
                                </div>
                            </div>
                            <div class="alert alert-info py-2 small mt-3 mb-0"><i class="bi bi-info-circle me-1"></i>
                                One entry per gathering per date. If there was no meeting that week, simply don&rsquo;t add one &mdash; don&rsquo;t record a 0.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                        </div>
                    </form>
                </div></div>
            </div>
            <?php endforeach; ?>

        </div>
    </div>

<?php include 'shared/footer.php'; ?>
<script>
(function () {
    var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var USUAL = { prayer_meeting: [2, 3], students_hangout: [5] };
    function dowHint(p) {
        var d = document.getElementById(p + '_date'), t = document.getElementById(p + '_type'), out = document.getElementById(p + '_dow');
        if (!d || !out) return;
        if (!d.value) { out.textContent = ''; return; }
        var dow = new Date(d.value + 'T00:00:00').getDay();
        var usual = USUAL[t.value] || [];
        out.textContent = DAYS[dow] + (usual.length && usual.indexOf(dow) === -1 ? ' — not the usual day for this gathering' : '');
        out.classList.toggle('text-warning-emphasis', usual.length > 0 && usual.indexOf(dow) === -1);
    }
    ['ga_add', 'ga_edit'].forEach(function (p) {
        ['_date', '_type'].forEach(function (s) {
            var el = document.getElementById(p + s);
            if (el) el.addEventListener('change', function () { dowHint(p); });
        });
    });
    window.gaOpenEditModal = function (row) {
        var f = document.getElementById('editGaForm');
        f.action = 'index.php?action=updateGatheringAttendance&id=' + row.id + <?php echo json_encode($gaFilterQs ? '&' . $gaFilterQs : ''); ?>;
        document.getElementById('ga_edit_type').value  = row.gathering_type;
        document.getElementById('ga_edit_date').value  = row.event_date;
        document.getElementById('ga_edit_att').value   = row.attendance;
        document.getElementById('ga_edit_notes').value = row.notes || '';
        dowHint('ga_edit');
        new bootstrap.Modal(document.getElementById('editGaModal')).show();
    };
    window.gaOpenEditById = function (id) {
        var tr = document.querySelector('tr[data-ga-id="' + id + '"]');
        var b = tr ? tr.querySelector('button[onclick^="gaOpenEditModal"]') : null;
        if (b) { b.click(); return; }
        window.location.href = 'index.php?action=gatheringAttendance&edit=' + id;
    };
    document.querySelectorAll('form.ga-form').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button[type="submit"]');
            if (b) { b.disabled = true; b.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving…'; }
        });
    });
})();

$(function () {
    if (!$('#gaTable').length) return;
    window.gaTable = $('#gaTable').DataTable({
        responsive: true, pageLength: 25, order: [], searching: true,
        language: { info: "Showing _START_ to _END_ of _TOTAL_ entries" },
        dom: 'rt<"d-flex justify-content-between align-items-center mt-2 px-2"ip>'
    });
    window.gaTable.on('draw', function () {
        var info = window.gaTable.page.info();
        document.getElementById('gaRecordCount').textContent = info.recordsDisplay;
        window.gaTable.rows({ page: 'current' }).nodes().each(function (row, i) {
            var c = row.querySelector('td.ga-row-num'); if (c) c.textContent = info.start + i + 1;
        });
    });
    $('#gaPerPage').on('change', function () { window.gaTable.page.len(parseInt($(this).val(), 10)).draw(); });
    var t;
    $('#gaFilterSearch').on('input', function () { var v = $(this).val(); clearTimeout(t); t = setTimeout(function () { window.gaTable.search(v).draw(); }, 250); });
    <?php if ($gaEditId > 0): ?>gaOpenEditById(<?php echo $gaEditId; ?>);<?php endif; ?>
});

function gaExportData() {
    var heads = [], rows = [];
    var ths = document.querySelectorAll('#gaTable thead th');
    for (var i = 0; i < ths.length - 1; i++) heads.push(ths[i].textContent.trim());
    if (window.gaTable) window.gaTable.rows({ search: 'applied' }).every(function () {
        var tds = this.node().querySelectorAll('td'), r = [];
        for (var i = 0; i < ths.length - 1; i++) r.push(tds[i] ? tds[i].textContent.replace(/\s+/g, ' ').trim() : '');
        rows.push(r);
    });
    return { heads: heads, rows: rows };
}
function gaFileName(ext) { return 'prayer_students_attendance_' + new Date().toISOString().slice(0, 10) + '.' + ext; }
function exportGaCSV() {
    var d = gaExportData(), q = function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; };
    var csv = [d.heads.map(q).join(',')].concat(d.rows.map(function (r) { return r.map(q).join(','); }));
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' }));
    a.download = gaFileName('csv'); a.click();
}
function exportGaExcel() {
    if (typeof XLSX === 'undefined') { exportGaCSV(); return; }
    var d = gaExportData(), wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([d.heads].concat(d.rows)), 'Prayer & Students');
    XLSX.writeFile(wb, gaFileName('xlsx'));
}
</script>
