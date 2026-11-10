<?php
/**
 * Worship Service Annual Attendance import — upload + preview screen.
 * Every variable defaulted so it renders cleanly with no batch.
 */
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
require_once 'models/AttendanceImport.php';
$batchId = $batchId ?? '';
$rows    = $rows    ?? [];
$summary = $summary ?? null;
$meta    = $meta    ?? null;
$check   = $check   ?? [];
$error   = $error   ?? '';
$notice  = $notice  ?? '';
include 'shared/header.php';
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex align-items-center flex-wrap gap-2 mb-4">
                <a href="index.php?action=serviceAttendance" class="btn btn-sm btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i>Sunday Attendance</a>
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-upload me-2 text-primary"></i>Import Worship Service Attendance</h1>
                    <p class="text-muted mb-0">Sunday Service, Kids Church, Prayer Meeting and Students&rsquo; Hangout counts from the church&rsquo;s annual workbook.</p>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-octagon-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if ($notice): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($notice); ?>
                <a href="index.php#churchHealth" class="alert-link ms-2">See Church Health</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <?php if (!$summary): ?>
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-file-earmark-excel me-2"></i>Choose the workbook</h6></div>
                        <div class="card-body">
                            <form method="POST" action="index.php?action=attendanceImportUpload" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold" for="aiFile">Worship Service Annual Attendance (.xlsx)</label>
                                    <input type="file" name="import_file" id="aiFile" class="form-control" accept=".xlsx" required>
                                </div>
                                <div class="mb-3" style="max-width:220px">
                                    <label class="form-label fw-semibold" for="aiYear">Worksheet to read</label>
                                    <input type="text" name="sheet_year" id="aiYear" class="form-control" value="2026" pattern="\d{4}" inputmode="numeric">
                                    <div class="form-text">Only this sheet is read. Other sheets (e.g. the hidden 2025 one) are ignored.</div>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload and Check</button>
                                <div class="form-text mt-2"><i class="bi bi-shield-check me-1 text-success"></i>Uploading only reads the file. Nothing is saved until you confirm.</div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>How the sheet is read</h6></div>
                        <div class="card-body small">
                            <ul class="mb-0 ps-3">
                                <li>One block per month. The service times come from the times row above each section.</li>
                                <li><strong>Sunday Service</strong> and <strong>Kids Church</strong> are imported per service time. A blank Kids Church cell is imported as <em>not recorded</em>, never as 0.</li>
                                <li><strong>NO PM</strong>, <strong>NO SH</strong>, blanks and a typed 0 mean no gathering that week, so nothing is imported for them.</li>
                                <li>Formula cells such as <code>=+8+16</code> use their result.</li>
                                <li>Anything already in the portal is left exactly as it is. Uploading the same file again adds nothing.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Preview -->
            <div class="row g-2 mb-3">
                <?php foreach (AttendanceImport::KINDS as $aiK => $aiL):
                    $by = $summary['by'][$aiK] ?? []; ?>
                <div class="col-md-4">
                    <div class="card h-100"><div class="card-body py-2">
                        <div class="fw-semibold mb-1"><?php echo htmlspecialchars($aiK === 'sunday' ? 'Sunday service times' : $aiL . 's'); ?></div>
                        <div class="d-flex flex-wrap gap-1 small">
                            <?php foreach (AttendanceImport::STATUS as $aiS => [$aiSl, $aiSc]): if (empty($by[$aiS])) continue; ?>
                            <span class="badge bg-<?php echo $aiSc; ?><?php echo $aiSc === 'light' || $aiSc === 'warning' ? ' text-dark border' : ''; ?>"><?php echo (int)$by[$aiS] . ' ' . htmlspecialchars(strtolower($aiSl)); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($meta['notes'])): ?>
            <div class="alert alert-light border small">
                <i class="bi bi-info-circle me-1"></i><strong><?php echo htmlspecialchars($meta['file'] ?? ''); ?></strong>
                <ul class="mb-0 mt-1"><?php foreach ($meta['notes'] as $aiN): ?><li><?php echo htmlspecialchars($aiN); ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>

            <?php if ($check): ?>
            <div class="card mb-3">
                <div class="card-header py-2"><span class="fw-semibold small text-white"><i class="bi bi-calculator me-2"></i>Check against the sheet&rsquo;s MONTHLY TOTAL row</span></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 small align-middle" id="aiCheckTable">
                        <thead class="table-light"><tr><th>Month</th><th class="text-end">Sundays</th>
                            <th class="text-end">Sunday Service</th><th class="text-end">Sheet</th>
                            <th class="text-end">Kids Church</th><th class="text-end">Sheet</th>
                            <th class="text-end">Prayer Mtg</th><th class="text-end">Sheet</th>
                            <th class="text-end">Hangout</th><th class="text-end">Sheet</th></tr></thead>
                        <tbody>
                        <?php foreach ($check as $c): if (!$c['sundays'] && !$c['pm'] && !$c['sh'] && !$c['sheet_svc']) continue; ?>
                            <tr><td class="fw-semibold"><?php echo htmlspecialchars(ucfirst(strtolower($c['month']))); ?></td>
                                <td class="text-end"><?php echo (int)$c['sundays']; ?></td>
                                <?php foreach ([['svc', 'sheet_svc'], ['kids', 'sheet_kids'], ['pm', 'sheet_pm'], ['sh', 'sheet_sh']] as [$aiA, $aiB]):
                                    $aiSheet = $c[$aiB]; $aiOk = $aiSheet === null || (int)$aiSheet === (int)$c[$aiA]; ?>
                                <td class="text-end"><?php echo number_format((int)$c[$aiA]); ?></td>
                                <td class="text-end <?php echo $aiOk ? 'text-muted' : 'text-danger fw-bold'; ?>"
                                    <?php echo $aiOk ? '' : 'title="Differs from the sheet — see the notes below"'; ?>><?php echo $aiSheet === null ? '—' : number_format((int)$aiSheet); ?><?php echo $aiOk ? '' : ' <i class="bi bi-exclamation-triangle"></i>'; ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-body py-2 small text-muted">Red figures differ from the sheet. That is usually the sheet&rsquo;s own total formula skipping a row or adding a cell twice. Check those months in the workbook. The import takes each count cell as entered and does not use the sheet&rsquo;s totals.</div>
            </div>
            <?php endif; ?>

            <form method="POST" action="index.php?action=attendanceImportCommit">
                <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batchId); ?>">
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="text-white fw-semibold"><i class="bi bi-list-check me-2"></i>Every cell found (<?php echo (int)$summary['total']; ?>)</span>
                        <select id="aiShow" class="form-select form-select-sm" style="width:auto" aria-label="Show rows">
                            <option value="">All rows</option><option value="flag" selected>Needs a look (check / skipped / cannot import)</option><option value="ready">Will import</option><option value="existing">Already in the portal</option>
                        </select>
                    </div>
                    <div class="table-responsive" style="max-height:520px">
                        <table class="table table-sm table-hover mb-0 small align-middle">
                            <thead class="table-light" style="position:sticky;top:0"><tr><th style="width:2rem"></th><th>Cell</th><th>Kind</th><th>Date</th><th>Time</th>
                                <th class="text-end">Count</th><th class="text-end">Kids Church</th><th>Outcome</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $r):
                                [$aiSl, $aiSc, $aiIcon] = AttendanceImport::STATUS[$r['row_status']] ?? ['?', 'secondary', 'bi-question'];
                                $aiCan = in_array($r['row_status'], ['ready', 'review'], true);
                                $aiFlag = $r['row_status'] === 'existing' ? 'existing' : ($r['row_status'] !== 'ready' || $r['default_off'] ? 'flag' : 'ready'); ?>
                                <tr class="ai-row" data-f="<?php echo $aiFlag; ?>">
                                    <td><?php if ($aiCan): ?><input type="checkbox" class="form-check-input" name="import[]" value="<?php echo (int)$r['id']; ?>"
                                        <?php echo $r['default_off'] ? '' : 'checked'; ?> aria-label="Import <?php echo htmlspecialchars($r['source_cell']); ?>"><?php endif; ?></td>
                                    <td class="text-nowrap text-muted"><?php echo htmlspecialchars($r['source_cell']); ?></td>
                                    <td><?php echo htmlspecialchars(AttendanceImport::KINDS[$r['kind']] ?? $r['kind']); ?></td>
                                    <td class="text-nowrap"><?php echo $r['event_date'] ? date('D, M j, Y', strtotime($r['event_date'])) : '—'; ?></td>
                                    <td class="text-nowrap"><?php echo htmlspecialchars($r['service_name'] ?? ''); ?></td>
                                    <td class="text-end fw-semibold"><?php echo $r['count_a'] === null ? '—' : (int)$r['count_a']; ?></td>
                                    <td class="text-end"><?php echo $r['kind'] !== 'sunday' ? '' : ($r['count_b'] === null ? '<span class="text-muted">not recorded</span>' : (int)$r['count_b']); ?></td>
                                    <td><span class="badge bg-<?php echo $aiSc; ?><?php echo in_array($aiSc, ['light', 'warning'], true) ? ' text-dark border' : ''; ?>"><i class="bi <?php echo $aiIcon; ?> me-1"></i><?php echo htmlspecialchars($aiSl); ?></span>
                                        <?php foreach ($r['msgs'] as $aiM): ?><div class="text-muted" style="font-size:11px"><?php echo htmlspecialchars($aiM); ?></div><?php endforeach; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button type="submit" class="btn btn-success" data-confirm="Import the ticked rows? Rows already in the portal are never changed.">
                        <i class="bi bi-check2-circle me-1"></i>Import <?php echo (int)$summary['import']; ?> ticked row<?php echo (int)$summary['import'] === 1 ? '' : 's'; ?>
                    </button>
                    <a href="index.php?action=attendanceImportDiscard&amp;batch=<?php echo urlencode($batchId); ?>" class="btn btn-outline-secondary">Discard</a>
                    <span class="small text-muted"><?php echo (int)$summary['sundays']; ?> Sundays &middot; ticked rows only.</span>
                </div>
            </form>
            <script>
            (function () {
                var sel = document.getElementById('aiShow');
                function apply() { document.querySelectorAll('tr.ai-row').forEach(function (tr) { tr.classList.toggle('d-none', !!sel.value && tr.dataset.f !== sel.value); }); }
                sel.addEventListener('change', apply); apply();
            })();
            </script>
            <?php endif; ?>
        </div>
    </div>
<?php include 'shared/footer.php'; ?>
