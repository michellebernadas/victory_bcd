<?php
/**
 * Facilities Request Form import — the review screen.
 *
 * Every variable is defaulted below, so this page renders identically against
 * an empty database and an empty batch, and never emits a notice.
 */
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
require_once 'models/FacilityRequest.php';
include 'shared/header.php';

$batchId       = $batchId       ?? '';
$rows          = $rows          ?? [];
$summary       = $summary       ?? null;
$skippedSheets = $skippedSheets ?? [];
$error         = $error         ?? '';
$notice        = $notice        ?? '';

$fiStatus = [
    'ready'        => ['Ready to import', 'success', 'bi-check-circle-fill'],
    'needs_review' => ['Needs review',    'warning', 'bi-exclamation-triangle-fill'],
    'invalid'      => ['Cannot import',   'danger',  'bi-x-circle-fill'],
];

if (!function_exists('fi_landing')) {
    /** Where a staged row will land. Never 'approved', never 'confirmed'. */
    function fi_landing(array $row): array {
        if ($row['parsed_status'] === 'invalid') return ['—', 'secondary', 'bi-dash-circle'];
        if (!empty($row['data']['_review']['reviewed'])) {
            return ['Submitted → Pending Minister', 'warning', 'bi-hourglass-split'];
        }
        return ['Submitted', 'info', 'bi-send'];
    }

    function fi_time($t) {
        $t = trim((string)$t);
        return $t === '' ? '—' : date('g:i A', strtotime('2000-01-01 ' . $t));
    }
    function fi_date($d) {
        $d = trim((string)$d);
        return $d === '' ? '—' : date('M j, Y (D)', strtotime($d));
    }
}
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex align-items-center mb-4">
                <a href="index.php?action=facilityCalendar" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left me-1"></i>Back to Facility Booking
                </a>
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-upload me-2 text-primary"></i>Import Facilities Request Forms</h1>
                    <p class="text-muted mb-0">Review every request before anything is added to the calendar.</p>
                </div>
                <a href="index.php?action=facilityImportTemplate" class="btn btn-outline-success ms-auto">
                    <i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>Download template
                </a>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-octagon-fill me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($notice): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($notice); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- The four steps, so it is obvious nothing is saved yet. -->
            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-center gap-2 small">
                        <?php
                        $fiSteps = ['Choose file', 'Check the requests', 'Confirm', 'Requests added'];
                        $fiAt    = $summary ? ($notice ? 4 : 2) : 1;
                        foreach ($fiSteps as $fiI => $fiLabel):
                            $n = $fiI + 1; $on = $n <= $fiAt;
                        ?>
                        <span class="d-flex align-items-center gap-1">
                            <span class="badge rounded-pill bg-<?php echo $on ? 'primary' : 'light text-muted border'; ?>"><?php echo $n; ?></span>
                            <span class="<?php echo $on ? 'fw-semibold' : 'text-muted'; ?>"><?php echo $fiLabel; ?></span>
                        </span>
                        <?php if ($fiI < count($fiSteps) - 1): ?>
                        <i class="bi bi-chevron-right text-muted small"></i>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if (!$summary): ?>
            <!-- ─── Step 1: choose a file ─────────────────────────────────── -->
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-file-earmark-excel me-2"></i>Choose your file</h6></div>
                        <div class="card-body">
                            <form method="POST" action="index.php?action=facilityImportUpload" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold" for="fiFile">Request forms (Excel)</label>
                                    <input type="file" name="import_file" id="fiFile" class="form-control" accept=".xlsx,.xls,.csv" required>
                                    <div class="form-text">
                                        Up to 5 MB. Either the church&rsquo;s workbook with <strong>one request per worksheet</strong>, or the
                                        <a href="index.php?action=facilityImportTemplate">downloadable template</a> with
                                        <strong>one request per row</strong> (.xlsx, or saved as .csv).
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload me-1"></i>Upload and Check
                                </button>
                                <div class="form-text mt-2">
                                    <i class="bi bi-shield-check me-1 text-success"></i>
                                    Uploading only reads the file. Nothing reaches the calendar until you confirm.
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>What gets imported</h6></div>
                        <div class="card-body small">
                            <p class="mb-2">Every worksheet that carries the request labels
                               (<strong>NAME</strong>, <strong>EVENT/ACTIVITY</strong>, <strong>DATE NEEDED</strong>)
                               and has been filled in. Blank templates and unrelated sheets are skipped and listed.</p>
                            <p class="mb-2">
                                <i class="bi bi-table me-1 text-success" aria-hidden="true"></i>
                                <strong>Many requests at once?</strong>
                                <a href="index.php?action=facilityImportTemplate">Download the template</a> — one row per request,
                                with dropdowns for today&rsquo;s rooms and ministries. Its sample row is skipped, and rows with a problem
                                (e.g. an end time before the start time, or a Facility / Room request with no room) are shown with their
                                row number and not imported until fixed.
                            </p>
                            <div class="alert alert-warning border mb-2 py-2">
                                <i class="bi bi-shield-exclamation me-1"></i>
                                <strong>Being in a spreadsheet is not an approval.</strong>
                                Every request imports as <strong>Submitted</strong>. Nothing is imported as
                                Approved or Confirmed — only the Minister can approve, and only Facilities can
                                confirm, both in the portal.
                            </div>
                            <p class="text-muted mb-0">
                                A sheet whose Facilities Personnel section has actually been filled in is
                                imported as Submitted first, then the review is applied — so the request's
                                history shows both steps, in the order they really happened.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- ─── Step 2: preview ───────────────────────────────────────── -->
            <div class="row g-3 mb-3">
                <?php
                $fiCards = [
                    ['Request forms found', $summary['total'],           'secondary', 'bi-file-earmark-text'],
                    ['Will be Submitted',   $summary['as_submitted'],    'info',      'bi-send'],
                    ['With a Facilities review', $summary['with_fac_review'], 'warning', 'bi-clipboard-check'],
                    ['Approved on import',  $summary['approved'],        'success',   'bi-hand-thumbs-up'],
                    ['Confirmed on import', $summary['confirmed'],       'success',   'bi-calendar2-check'],
                    ['Cannot import',       $summary['invalid'],         'danger',    'bi-x-circle'],
                ];
                foreach ($fiCards as [$fiL, $fiV, $fiC, $fiIcon]):
                ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100 border-<?php echo $fiC; ?> border-opacity-50">
                        <div class="card-body text-center py-3">
                            <i class="bi <?php echo $fiIcon; ?> text-<?php echo $fiC; ?>"></i>
                            <div class="h3 fw-bold text-<?php echo $fiC; ?> mb-0"><?php echo (int)$fiV; ?></div>
                            <div class="small text-muted"><?php echo $fiL; ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-info">
                <i class="bi bi-shield-check me-1"></i>
                <strong>Approved on import and Confirmed on import are always zero.</strong>
                The importer can only create a Submitted request and, where the sheet shows one, apply the
                Facilities review. An approval is the Minister's to give in the portal, and only a confirmed
                booking reserves a room.
            </div>

            <?php if ($skippedSheets): ?>
            <div class="card mb-3">
                <div class="card-header py-2">
                    <span class="fw-semibold small"><i class="bi bi-eye-slash me-2"></i>Sheets that were skipped</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($skippedSheets as $fiSheet => $fiWhy): ?>
                    <li class="list-group-item">
                        <strong><?php echo htmlspecialchars((string)$fiSheet); ?></strong>
                        <span class="text-muted">— <?php echo htmlspecialchars((string)$fiWhy); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" action="index.php?action=facilityImportCommit" id="fiCommitForm">
                <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batchId); ?>">

                <div class="alert alert-primary d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <i class="bi bi-clipboard-check me-1"></i>
                        <strong><?php echo (int)$summary['will_create']; ?></strong>
                        request<?php echo $summary['will_create'] === 1 ? '' : 's'; ?> will be created, all as Submitted.
                        <?php if ($summary['already_imported'] > 0): ?>
                        <strong><?php echo (int)$summary['already_imported']; ?></strong> already imported and will be skipped.
                        <?php endif; ?>
                    </div>
                    <!-- No browser confirm(): the modal below is the confirmation. -->
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#fiDiscardModal">
                        <i class="bi bi-x-circle me-1"></i>Discard
                    </button>
                </div>

                <?php foreach ($rows as $r):
                    $d = $r['data'];
                    [$fiStLabel, $fiStColor, $fiStIcon]     = $fiStatus[$r['parsed_status']] ?? ['Unknown', 'secondary', 'bi-question'];
                    [$fiLand, $fiLandColor, $fiLandIcon]    = fi_landing($r);
                    $fiImported = !empty($r['created_request_id']);
                    $fiCanPick  = !$fiImported && $r['parsed_status'] !== 'invalid';
                ?>
                <div class="card mb-3 <?php echo $fiImported ? 'opacity-75' : ''; ?>">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <?php if ($fiImported): ?>
                        <span class="badge bg-success"><i class="bi bi-check me-1"></i>Already imported</span>
                        <?php elseif ($fiCanPick): ?>
                        <div class="form-check mb-0">
                            <input class="form-check-input fi-pick" type="checkbox" checked
                                   name="import[]" value="<?php echo (int)$r['id']; ?>"
                                   id="fiRow<?php echo (int)$r['id']; ?>">
                            <label class="form-check-label small" for="fiRow<?php echo (int)$r['id']; ?>">Import</label>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Not importable</span>
                        <?php endif; ?>

                        <span class="fw-semibold"><?php echo htmlspecialchars($d['event_activity'] ?? '(no event)'); ?></span>
                        <span class="badge bg-<?php echo $fiStColor; ?>">
                            <i class="bi <?php echo $fiStIcon; ?> me-1"></i><?php echo $fiStLabel; ?>
                        </span>
                        <span class="badge bg-<?php echo $fiLandColor; ?>">
                            <i class="bi <?php echo $fiLandIcon; ?> me-1"></i>Will land at: <?php echo htmlspecialchars($fiLand); ?>
                        </span>
                        <span class="ms-auto small text-muted">
                            Sheet: <?php echo htmlspecialchars((string)$r['source_sheet']); ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if ($r['request_missing']): ?>
                        <div class="alert alert-warning py-2 small mb-3">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This sheet was imported, but the request it created no longer exists — it has since been
                            deleted. Untick-and-reimport is not offered here; create it again from the request screen
                            if it is still needed.
                        </div>
                        <?php elseif ($fiImported): ?>
                        <div class="alert alert-light border py-2 small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Imported. Its status now is
                            <strong><?php echo htmlspecialchars(FacilityRequest::statusMeta($r['live_status'])['label']); ?></strong>.
                        </div>
                        <?php endif; ?>

                        <div class="row g-3 small">
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-5 text-muted fw-normal">Requestor</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars($d['requestor_name'] ?? '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Ministry</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars(($d['ministry'] ?? '') ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Purpose</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars(($d['purpose'] ?? '') ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Date needed</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars(fi_date($d['date_needed'] ?? '')); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Time</dt>
                                    <dd class="col-7 mb-0">
                                        <?php echo htmlspecialchars(fi_time($d['start_time'] ?? '')); ?>
                                        &ndash; <?php echo htmlspecialchars(fi_time($d['end_time'] ?? '')); ?>
                                    </dd>
                                </dl>
                            </div>
                            <div class="col-md-6">
                                <dl class="row mb-0">
                                    <dt class="col-5 text-muted fw-normal">Type</dt>
                                    <dd class="col-7"><?php
                                        $fiTypes = [];
                                        foreach ((array)($d['request_type'] ?? []) as $t) {
                                            $fiTypes[] = FacilityRequest::REQUEST_TYPES[$t] ?? $t;
                                        }
                                        echo htmlspecialchars($fiTypes ? implode(', ', $fiTypes) : '—');
                                    ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Equipment</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars(($d['equipment_requested'] ?? '') ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Attendance</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars(($d['expected_attendance'] ?? '') ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Contact</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars(($d['contact_number'] ?? '') ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Email</dt>
                                    <dd class="col-7 mb-0"><?php echo htmlspecialchars(($d['email'] ?? '') ?: '—'); ?></dd>
                                </dl>
                            </div>
                        </div>

                        <?php if (!empty($r['issues_list'])): ?>
                        <ul class="small text-muted mt-3 mb-0 ps-3">
                            <?php foreach ($r['issues_list'] as $fiIssue): ?>
                            <li><?php echo htmlspecialchars($fiIssue); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- ─── Step 3: confirm ───────────────────────────────────── -->
                <div class="card mb-5 border-primary">
                    <div class="card-body text-center py-4">
                        <h5 class="mb-2">Ready to add these requests?</h5>
                        <p class="text-muted mb-3">
                            <strong id="fiWillCreate"><?php echo (int)$summary['will_create']; ?></strong>
                            request<span id="fiPlural"><?php echo $summary['will_create'] === 1 ? '' : 's'; ?></span>
                            will be created as <strong>Submitted</strong>. Sheets you untick are left alone.
                            <br>
                            <span class="small">
                                <i class="bi bi-shield-check text-success me-1"></i>
                                Nothing is approved and nothing is confirmed by this import, so no room is reserved.
                            </span>
                        </p>
                        <button type="button" class="btn btn-primary btn-lg px-5" data-bs-toggle="modal" data-bs-target="#fiCommitModal">
                            <i class="bi bi-check-circle me-1"></i>Confirm Import
                        </button>
                        <div class="form-text mt-2">
                            This is the step that creates them. It can't be undone from here.
                        </div>
                    </div>
                </div>

                <!-- Confirm-import modal (replaces the browser confirm()). Inside
                     the form, so its button submits the ticked rows. -->
                <div class="modal fade" id="fiCommitModal" tabindex="-1" aria-labelledby="fiCommitTitle">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title" id="fiCommitTitle"><i class="bi bi-check-circle me-2" aria-hidden="true"></i>Add these requests now?</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body small">
                                <p class="mb-2"><strong id="fiModalCount"><?php echo (int)$summary['will_create']; ?></strong> request(s) will be created, all as
                                    <strong>Submitted</strong>. None is approved or confirmed and no room is reserved &mdash; each goes through
                                    Facilities Review, the Minister and Confirmation in the portal.</p>
                                <p class="mb-0 text-muted">Imported requests have no owner account, so they are not in anyone&rsquo;s My Requests.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Back to the preview</button>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Confirm Import</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div class="modal fade" id="fiDiscardModal" tabindex="-1" aria-labelledby="fiDiscardTitle">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="fiDiscardTitle"><i class="bi bi-x-circle me-2" aria-hidden="true"></i>Discard this import?</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body small">Nothing has been created from this upload yet. Discarding throws the preview away; already-imported rows stay on record.</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep the preview</button>
                            <a href="index.php?action=facilityImportDiscard&amp;batch=<?php echo urlencode($batchId); ?>" class="btn btn-danger">
                                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Discard
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            // Keep the headline count honest as rows are ticked and unticked.
            (function () {
                var out  = document.getElementById('fiWillCreate');
                var plur = document.getElementById('fiPlural');
                if (!out) return;
                function sync() {
                    var n = document.querySelectorAll('.fi-pick:checked').length;
                    out.textContent  = n;
                    plur.textContent = n === 1 ? '' : 's';
                    var m = document.getElementById('fiModalCount'); if (m) m.textContent = n;
                }
                document.querySelectorAll('.fi-pick').forEach(function (cb) {
                    cb.addEventListener('change', sync);
                });
            })();
            </script>
            <?php endif; ?>

        </div>
    </div>
<?php include 'shared/footer.php'; ?>
