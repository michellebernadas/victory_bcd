<?php
/**
 * Get Connected inbox — Statistics tab. Same shape as the Members statistics tab:
 * stat cards, chart cards with PNG export, then a summary table with CSV / Excel /
 * PDF / Print. Needs $jrStats from JoinRequest::stats(). The goal of the whole inbox
 * is people connected to a Victory Group, so that is the number everything is
 * measured against.
 */
$S      = $jrStats;
$total  = (int)$S['total'];
$by     = $S['byStatus'];
$conn   = (int)($by['placed'] ?? 0);
$notYet = (int)($by['new'] ?? 0) + (int)($by['open'] ?? 0);
$pct    = function ($n) use ($total) { return $total > 0 ? round($n / $total * 100) . '%' : '0%'; };
$lsLabel = function ($k) { return $k === '' ? 'Not given' : (GroupMatcher::LIFE_STAGES[$k] ?? $k); };
$gLabel  = function ($k) { return $k === '' ? 'Not given' : (GroupMatcher::GENDERS[$k] ?? $k); };

$monthLabels = []; $monthRecv = []; $monthConn = [];
foreach ($S['months'] as $ym => $v) { $monthLabels[] = date('M Y', strtotime($ym . '-01')); $monthRecv[] = $v[0]; $monthConn[] = $v[1]; }
$lsLabels = []; $lsRecv = []; $lsConn = [];
foreach ($S['lifeStage'] as $k => $v) { $lsLabels[] = $lsLabel($k); $lsRecv[] = $v[0]; $lsConn[] = $v[1]; }
$gLabels = []; $gCounts = [];
foreach ($S['gender'] as $k => $v) { $gLabels[] = $gLabel($k); $gCounts[] = $v[0]; }

$summaryRows = [['Total requests', $total]];
foreach (JoinRequest::STATUSES as $k => $m) $summaryRows[] = [$m['label'], (int)($by[$k] ?? 0)];
$summaryRows[] = ['Not contacted yet (New + Open)', $notYet];
$summaryRows[] = ['Waiting 7+ days without contact', (int)$S['waiting7']];
$summaryRows[] = ['Received in the last 30 days', (int)$S['last30']];
foreach ($S['asked'] as $label => $n) $summaryRows[] = ['Asked about: ' . $label, $n];
foreach ($S['lifeStage'] as $k => $v) { $summaryRows[] = ['Life stage: ' . $lsLabel($k), $v[0]]; $summaryRows[] = ['Life stage: ' . $lsLabel($k) . ' (connected)', $v[1]]; }
foreach ($S['gender'] as $k => $v) $summaryRows[] = ['Gender: ' . $gLabel($k), $v[0]];
foreach ($S['months'] as $ym => $v) $summaryRows[] = [date('M Y', strtotime($ym . '-01')) . ' received', $v[0]];
?>

<!-- Stat cards -->
<div class="row mb-4 g-3">
    <div class="col-6 col-md-4 col-xl-2"><div class="card h-100 border-primary"><div class="card-body text-center py-3">
        <div class="display-6 fw-bold text-primary mb-0"><?php echo $total; ?></div><div class="small text-muted mt-1">Total requests</div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body text-center py-3">
        <div class="display-6 fw-bold text-danger mb-0"><?php echo $notYet; ?></div><div class="small text-muted mt-1">Not contacted yet <span class="text-danger-emphasis fw-semibold">(<?php echo $pct($notYet); ?>)</span></div>
        <div class="small text-muted">New <?php echo (int)($by['new'] ?? 0); ?> &middot; Open <?php echo (int)($by['open'] ?? 0); ?></div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body text-center py-3">
        <div class="display-6 fw-bold text-primary mb-0"><?php echo (int)($by['contacted'] ?? 0); ?></div><div class="small text-muted mt-1">Contacted <span class="text-primary-emphasis fw-semibold">(<?php echo $pct((int)($by['contacted'] ?? 0)); ?>)</span></div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card h-100 border-success"><div class="card-body text-center py-3">
        <div class="display-6 fw-bold text-success mb-0"><?php echo $conn; ?></div><div class="small text-muted mt-1">Connected to a Victory Group <span class="text-success-emphasis fw-semibold">(<?php echo $pct($conn); ?>)</span></div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card h-100<?php echo $S['waiting7'] > 0 ? ' border-warning' : ''; ?>"><div class="card-body text-center py-3">
        <div class="display-6 fw-bold text-warning-emphasis mb-0"><?php echo (int)$S['waiting7']; ?></div><div class="small text-muted mt-1">Waiting 7+ days <span class="d-block">with no contact</span></div></div></div></div>
    <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body text-center py-3">
        <div class="display-6 fw-bold text-info mb-0"><?php echo (int)$S['last30']; ?></div><div class="small text-muted mt-1">Received in the last 30 days</div></div></div></div>
</div>

<?php if ($total === 0): ?>
<div class="alert alert-info"><i class="bi bi-info-circle-fill me-2"></i>No requests yet, so there is nothing to chart. Statistics appear as people use the Get Connected form.</div>
<?php endif; ?>

<!-- Charts -->
<div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card h-100">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="small fw-bold text-white"><i class="bi bi-funnel me-2"></i>Where requests are now</span>
            <button class="btn btn-sm btn-outline-light" onclick="exportJrChartPng('jrStatusChart','get_connected_by_status')"><i class="bi bi-download me-1"></i>PNG</button>
        </div>
        <div class="card-body" style="max-height:280px;"><canvas id="jrStatusChart" style="max-height:240px;"></canvas></div>
    </div></div>
    <div class="col-lg-6"><div class="card h-100">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="small fw-bold text-white"><i class="bi bi-graph-up me-2"></i>Requests per month <span class="text-white-50 fw-normal">(last 12 months)</span></span>
            <button class="btn btn-sm btn-outline-light" onclick="exportJrChartPng('jrMonthChart','get_connected_per_month')"><i class="bi bi-download me-1"></i>PNG</button>
        </div>
        <div class="card-body" style="max-height:280px;"><canvas id="jrMonthChart" style="max-height:240px;"></canvas></div>
    </div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card h-100">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="small fw-bold text-white"><i class="bi bi-bar-chart me-2"></i>By life stage <span class="text-white-50 fw-normal">(received vs connected)</span></span>
            <button class="btn btn-sm btn-outline-light" onclick="exportJrChartPng('jrLifeChart','get_connected_by_life_stage')"><i class="bi bi-download me-1"></i>PNG</button>
        </div>
        <div class="card-body" style="max-height:320px;"><canvas id="jrLifeChart" style="max-height:280px;"></canvas></div>
    </div></div>
    <div class="col-lg-3"><div class="card h-100">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="small fw-bold text-white"><i class="bi bi-pie-chart me-2"></i>Group asked about</span>
            <button class="btn btn-sm btn-outline-light" onclick="exportJrChartPng('jrAskedChart','get_connected_group_asked')"><i class="bi bi-download me-1"></i>PNG</button>
        </div>
        <div class="card-body" style="max-height:320px;"><canvas id="jrAskedChart" style="max-height:280px;"></canvas></div>
    </div></div>
    <div class="col-lg-3"><div class="card h-100">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="small fw-bold text-white"><i class="bi bi-pie-chart me-2"></i>Gender</span>
            <button class="btn btn-sm btn-outline-light" onclick="exportJrChartPng('jrGenderChart','get_connected_by_gender')"><i class="bi bi-download me-1"></i>PNG</button>
        </div>
        <div class="card-body" style="max-height:320px;"><canvas id="jrGenderChart" style="max-height:280px;"></canvas></div>
    </div></div>
</div>

<!-- Summary table -->
<div class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span class="text-white fw-semibold"><i class="bi bi-table me-2"></i>Get Connected Summary
            <span class="badge bg-white text-dark border ms-1"><?php echo count($summaryRows); ?></span></span>
        <div class="btn-group btn-group-sm">
            <button class="btn btn-sm btn-outline-light" onclick="exportJrSummaryCsv()"  title="CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
            <button class="btn btn-sm btn-outline-light" onclick="exportJrSummaryXlsx()" title="Excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
            <button class="btn btn-sm btn-outline-light" onclick="exportJrSummaryPdf()"  title="PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</button>
            <button class="btn btn-sm btn-outline-light" onclick="printJrSummary()"      title="Print"><i class="bi bi-printer me-1"></i>Print</button>
        </div>
    </div>
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-sm table-hover mb-0" id="jrSummaryTable">
            <thead class="table-light"><tr><th>Metric</th><th class="text-center" style="width:120px">Count</th></tr></thead>
            <tbody>
            <?php foreach ($summaryRows as $r): ?>
                <tr><td><?php echo htmlspecialchars($r[0]); ?></td><td class="text-center fw-bold"><?php echo (int)$r[1]; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div></div>
</div>

<script>
window.JR_STATS = <?php echo json_encode([
    'status'  => ['labels' => array_map(function ($m) { return $m['label']; }, array_values(JoinRequest::STATUSES)),
                  'data'   => array_map(function ($k) use ($by) { return (int)($by[$k] ?? 0); }, array_keys(JoinRequest::STATUSES))],
    'months'  => ['labels' => $monthLabels, 'received' => $monthRecv, 'connected' => $monthConn],
    'life'    => ['labels' => $lsLabels, 'received' => $lsRecv, 'connected' => $lsConn],
    'asked'   => ['labels' => array_keys($S['asked']), 'data' => array_values($S['asked'])],
    'gender'  => ['labels' => $gLabels, 'data' => $gCounts],
], JSON_HEX_TAG | JSON_HEX_AMP); ?>;

function exportJrChartPng(id, name) {
    var c = document.getElementById(id); if (!c) return;
    var a = document.createElement('a'); a.href = c.toDataURL('image/png');
    a.download = name + '_' + new Date().toISOString().slice(0,10) + '.png'; a.click();
}

window.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined' || !window.JR_STATS) return;
    var S = window.JR_STATS;
    var palette = ['rgba(13,110,253,.8)','rgba(25,135,84,.8)','rgba(255,193,7,.8)','rgba(220,53,69,.8)','rgba(13,202,240,.8)','rgba(102,16,242,.8)','rgba(253,126,20,.8)','rgba(108,117,125,.6)'];
    var blue = 'rgba(13,110,253,.75)', green = 'rgba(25,135,84,.85)';
    var pieOpts = { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 8 } } } };
    var intAxis = { beginAtZero: true, ticks: { precision: 0 } };

    // Same colours as the status badges: New red, Open yellow, Contacted blue, Connected green.
    new Chart(document.getElementById('jrStatusChart'), { type: 'bar',
        data: { labels: S.status.labels, datasets: [{ label: 'Requests', data: S.status.data,
                backgroundColor: ['rgba(220,53,69,.8)','rgba(255,193,7,.85)','rgba(13,110,253,.8)','rgba(25,135,84,.85)'], borderWidth: 0 }] },
        options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: intAxis, y: { grid: { display: false } } } } });

    new Chart(document.getElementById('jrMonthChart'), { type: 'bar',
        data: { labels: S.months.labels, datasets: [
            { label: 'Received', data: S.months.received, backgroundColor: blue, borderWidth: 0 },
            { label: 'Connected (of those)', data: S.months.connected, backgroundColor: green, borderWidth: 0 } ] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }, scales: { y: intAxis, x: { grid: { display: false } } } } });

    new Chart(document.getElementById('jrLifeChart'), { type: 'bar',
        data: { labels: S.life.labels, datasets: [
            { label: 'Received', data: S.life.received, backgroundColor: blue, borderWidth: 0 },
            { label: 'Connected', data: S.life.connected, backgroundColor: green, borderWidth: 0 } ] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }, scales: { y: intAxis, x: { grid: { display: false } } } } });

    new Chart(document.getElementById('jrAskedChart'), { type: 'pie',
        data: { labels: S.asked.labels, datasets: [{ data: S.asked.data, backgroundColor: palette, borderWidth: 1 }] }, options: pieOpts });
    new Chart(document.getElementById('jrGenderChart'), { type: 'pie',
        data: { labels: S.gender.labels, datasets: [{ data: S.gender.data, backgroundColor: palette, borderWidth: 1 }] }, options: pieOpts });
});

// ── Summary exports (same pattern as the Members summary) ──
function _jrSummaryData() {
    var tbl = document.getElementById('jrSummaryTable');
    return { headers: ['Metric', 'Count'], rows: Array.from(tbl.querySelectorAll('tbody tr')).map(function (tr) {
        return Array.from(tr.querySelectorAll('td')).map(function (td) { return td.textContent.trim(); }); }) };
}
function exportJrSummaryCsv() {
    var d = _jrSummaryData(), csv = [d.headers.map(function (h) { return '"' + h + '"'; }).join(',')];
    d.rows.forEach(function (r) { csv.push(r.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',')); });
    var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' }));
    a.download = 'get_connected_summary_' + new Date().toISOString().slice(0,10) + '.csv'; a.click();
}
function exportJrSummaryXlsx() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var d = _jrSummaryData(), ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
    ws['!cols'] = [{ wch: 52 }, { wch: 10 }];
    var wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Get Connected');
    XLSX.writeFile(wb, 'get_connected_summary_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
function exportJrSummaryPdf() {
    if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
    var d = _jrSummaryData(), doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    doc.setFontSize(13); doc.text('Get Connected — Statistics Summary', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: ' + new Date().toLocaleDateString(), 40, 52);
    doc.autoTable({ head: [d.headers], body: d.rows, startY: 62, styles: { fontSize: 9, cellPadding: 4 },
        headStyles: { fillColor: [13,110,253], textColor: 255, fontStyle: 'bold' }, alternateRowStyles: { fillColor: [245,247,250] },
        columnStyles: { 1: { halign: 'center', cellWidth: 60 } }, margin: { left: 40, right: 40 } });
    doc.save('get_connected_summary_' + new Date().toISOString().slice(0,10) + '.pdf');
}
function printJrSummary() {
    var d = _jrSummaryData(), w = window.open('', '_blank');
    if (!w) return;
    var esc = function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); };
    w.document.write('<html><head><title>Get Connected Summary</title><style>body{font-family:Arial,sans-serif;font-size:12px;padding:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 8px;text-align:left}td:last-child,th:last-child{text-align:center;width:80px}th{background:#f1f3f5}</style></head><body><h2>Get Connected — Statistics Summary</h2><p>Printed: ' + new Date().toLocaleDateString() + '</p><table><thead><tr>'
        + d.headers.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + d.rows.map(function (r) { return '<tr>' + r.map(function (v) { return '<td>' + esc(v) + '</td>'; }).join('') + '</tr>'; }).join('')
        + '</tbody></table></body></html>');
    w.document.close(); w.focus(); w.print();
}
</script>
