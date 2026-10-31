<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';

$batchId = $batchId ?? '';
$rows    = $rows    ?? [];
$summary = $summary ?? null;
$error   = $error   ?? '';
$notice  = $notice  ?? '';

// Plain-language label + colour per row outcome. Church admins, not developers.
$miStatus = [
    'ready'        => ['Ready to import', 'success',   'bi-check-circle-fill'],
    'needs_review' => ['Needs review',    'warning',   'bi-exclamation-triangle-fill'],
    'duplicate'    => ['Repeated in file','warning',   'bi-files'],
    'existing'     => ['Already a member','info',      'bi-person-check-fill'],
    'invalid'      => ['Cannot import',   'danger',    'bi-x-circle-fill'],
];
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex align-items-center mb-4">
                <a href="index.php?action=members" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left me-1"></i>Back to Members
                </a>
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-upload me-2 text-primary"></i>Import Members from Excel</h1>
                    <p class="text-muted mb-0">Review everything before anything is added.</p>
                </div>
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
                        $miSteps = ['Choose file', 'Check the rows', 'Confirm', 'Members added'];
                        $miAt    = $summary ? ($notice ? 4 : 2) : 1;
                        foreach ($miSteps as $miI => $miLabel):
                            $n = $miI + 1;
                            $on = $n <= $miAt;
                        ?>
                        <span class="d-flex align-items-center gap-1">
                            <span class="badge rounded-pill bg-<?php echo $on ? 'primary' : 'light text-muted border'; ?>"><?php echo $n; ?></span>
                            <span class="<?php echo $on ? 'fw-semibold' : 'text-muted'; ?>"><?php echo $miLabel; ?></span>
                        </span>
                        <?php if ($miI < count($miSteps) - 1): ?>
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
                            <form method="POST" action="index.php?action=memberImportUpload" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Excel or CSV file</label>
                                    <input type="file" name="import_file" class="form-control"
                                           accept=".xlsx,.xls,.csv" required>
                                    <div class="form-text">Excel (.xlsx, .xls) or .csv — up to 5 MB.</div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload me-1"></i>Upload and Check
                                </button>
                                <div class="form-text mt-2">
                                    <i class="bi bi-shield-check me-1 text-success"></i>
                                    Uploading only checks the file. Nothing is added until you confirm.
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>What your file needs</h6></div>
                        <div class="card-body small">
                            <p class="mb-2">A header row with at least:</p>
                            <ul class="mb-3">
                                <li><strong>First Name</strong> and <strong>Last Name</strong><br>
                                    <span class="text-muted">(or a single <strong>Full Name</strong> column)</span></li>
                            </ul>
                            <p class="mb-2">These are picked up automatically if present:</p>
                            <p class="text-muted mb-3">
                                Email &middot; Contact Number &middot; Facebook &middot; Ministry &middot;
                                Service &middot; Life Stage &middot; Notes
                            </p>
                            <div class="alert alert-light border mb-0 py-2">
                                <i class="bi bi-lightbulb me-1 text-warning"></i>
                                Names are saved exactly as written. Apostrophes, hyphens, accents and
                                compound surnames are all fine.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- ─── Step 2: preview ───────────────────────────────────────── -->
            <div class="row g-3 mb-4">
                <?php
                $miCards = [
                    ['Total rows',       $summary['total'],        'secondary', 'bi-list-ol'],
                    ['Ready to import',  $summary['ready'],        'success',   'bi-check-circle'],
                    ['Need review',      $summary['needs_review'] + $summary['duplicate'], 'warning', 'bi-exclamation-triangle'],
                    ['Already members',  $summary['existing'],     'info',      'bi-person-check'],
                    ['Cannot import',    $summary['invalid'],      'danger',    'bi-x-circle'],
                ];
                foreach ($miCards as [$miL, $miV, $miC, $miIcon]):
                ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card h-100 border-<?php echo $miC; ?> border-opacity-50">
                        <div class="card-body text-center py-3">
                            <i class="bi <?php echo $miIcon; ?> text-<?php echo $miC; ?>"></i>
                            <div class="h3 fw-bold text-<?php echo $miC; ?> mb-0"><?php echo (int)$miV; ?></div>
                            <div class="small text-muted"><?php echo $miL; ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <form method="POST" action="index.php?action=memberImportCommit" id="miCommitForm">
                <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batchId); ?>">

                <div class="alert alert-primary d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <i class="bi bi-clipboard-check me-1"></i>
                        <strong><?php echo (int)$summary['will_create']; ?></strong> member<?php echo $summary['will_create'] === 1 ? '' : 's'; ?> will be created.
                        <strong><?php echo (int)($summary['needs_review'] + $summary['duplicate']); ?></strong> row(s) require review.
                        <strong><?php echo (int)$summary['existing']; ?></strong> row(s) appear to already exist.
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($summary['total'] - $summary['ready'] > 0): ?>
                        <a href="index.php?action=memberImportReview&batch=<?php echo urlencode($batchId); ?>"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-download me-1"></i>Download Review List
                        </a>
                        <?php endif; ?>
                        <a href="index.php?action=memberImportDiscard&batch=<?php echo urlencode($batchId); ?>"
                           class="btn btn-sm btn-outline-danger"
                           data-confirm="Discard this import? Nothing has been added yet.">
                            <i class="bi bi-x-circle me-1"></i>Discard
                        </a>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-table me-2"></i>Every row in your file</h6>
                        <span class="badge bg-light text-dark border"><?php echo count($rows); ?> rows</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:70px;">Import?</th>
                                    <th style="width:60px;">Row</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>What we found</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r):
                                    [$miLabel, $miColor, $miIcon] = $miStatus[$r['row_status']] ?? ['Unknown','secondary','bi-question'];
                                    $miIssues   = json_decode($r['issues'] ?? '[]', true) ?: [];
                                    $miImported = !empty($r['created_member_id']);
                                    $miAuto     = $r['row_status'] === 'ready';
                                    $miCanPick  = !$miImported && !$miAuto && $r['row_status'] !== 'invalid';
                                ?>
                                <tr class="<?php echo $miImported ? 'table-light text-muted' : ''; ?>">
                                    <td>
                                        <?php if ($miImported): ?>
                                        <span class="badge bg-success" title="Already added">
                                            <i class="bi bi-check"></i>
                                        </span>
                                        <?php elseif ($miAuto): ?>
                                        <input class="form-check-input" type="checkbox" checked disabled
                                               title="Ready — will be imported">
                                        <?php elseif ($miCanPick): ?>
                                        <input class="form-check-input mi-approve" type="checkbox"
                                               name="approve[]" value="<?php echo (int)$r['id']; ?>"
                                               title="Tick to import this row anyway">
                                        <?php else: ?>
                                        <span class="text-muted" title="Cannot be imported">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?php echo (int)$r['row_number']; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($r['full_name'] ?: '(no name)'); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($r['email'] ?: '—'); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $miColor; ?>">
                                            <i class="bi <?php echo $miIcon; ?> me-1"></i><?php echo $miLabel; ?>
                                        </span>
                                    </td>
                                    <td class="small">
                                        <?php if (!$miIssues): ?>
                                        <span class="text-muted">—</span>
                                        <?php else: ?>
                                        <ul class="mb-0 ps-3">
                                            <?php foreach ($miIssues as $miIssue): ?>
                                            <li><?php echo htmlspecialchars($miIssue); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ─── Step 3: confirm ───────────────────────────────────── -->
                <div class="card mb-5 border-primary">
                    <div class="card-body text-center py-4">
                        <h5 class="mb-2">Ready to add these members?</h5>
                        <p class="text-muted mb-3">
                            <strong id="miWillCreate"><?php echo (int)$summary['will_create']; ?></strong>
                            member<span id="miPlural"><?php echo $summary['will_create'] === 1 ? '' : 's'; ?></span>
                            will be created. Rows you have not ticked are left alone.
                        </p>
                        <button type="submit" class="btn btn-primary btn-lg px-5"
                                <?php echo $summary['will_create'] === 0 ? 'id="miConfirmBtn"' : ''; ?>
                                data-confirm="Add these members now?" data-confirm-ok="Add members" data-confirm-variant="success">
                            <i class="bi bi-check-circle me-1"></i>Confirm Import
                        </button>
                        <div class="form-text mt-2">
                            This is the step that adds them. It can't be undone from here.
                        </div>
                    </div>
                </div>
            </form>

            <script>
            // Keep the headline count honest as rows are ticked.
            (function () {
                var base = <?php echo (int)$summary['will_create']; ?>;
                var out  = document.getElementById('miWillCreate');
                var plur = document.getElementById('miPlural');
                document.querySelectorAll('.mi-approve').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        var extra = document.querySelectorAll('.mi-approve:checked').length;
                        out.textContent  = base + extra;
                        plur.textContent = (base + extra) === 1 ? '' : 's';
                    });
                });
            })();
            </script>
            <?php endif; ?>

        </div>
    </div>
<?php include 'shared/footer.php'; ?>
