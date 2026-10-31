<?php
/**
 * Staff VG Schedule import — the review screen.
 *
 * Every variable is defaulted below, so this page renders identically against
 * an empty database and an empty batch, and never emits a notice.
 */
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';

$batchId = $batchId ?? '';
$cards   = $cards   ?? [];
$summary = $summary ?? null;
$error   = $error   ?? '';
$notice  = $notice  ?? '';

$giKind = [
    'victory_group'    => ['Victory Group',    'primary', 'bi-people-fill'],
    'leadership_group' => ['Leadership Group', 'info',    'bi-person-badge-fill'],
    'campus'           => ['Campus',           'warning', 'bi-mortarboard-fill'],
];
$giStatus = [
    'ready'        => ['Ready to import', 'success', 'bi-check-circle-fill'],
    'needs_review' => ['Needs review',    'warning', 'bi-exclamation-triangle-fill'],
    'invalid'      => ['Cannot import',   'danger',  'bi-x-circle-fill'],
];
$giRole = [
    'leader' => 'VG Leader', 'lg_leader' => 'LG Leader',
    'intern' => 'Intern',    'attendee'  => 'Member',
];
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex align-items-center mb-4">
                <a href="index.php?action=victoryGroups" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left me-1"></i>Back to Groups
                </a>
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-upload me-2 text-primary"></i>Import the Staff VG Schedule</h1>
                    <p class="text-muted mb-0">Review every group and every name before anything is created.</p>
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
                        $giSteps = ['Choose file', 'Check the groups', 'Confirm', 'Groups created'];
                        $giAt    = $summary ? ($notice ? 4 : 2) : 1;
                        foreach ($giSteps as $giI => $giLabel):
                            $n = $giI + 1; $on = $n <= $giAt;
                        ?>
                        <span class="d-flex align-items-center gap-1">
                            <span class="badge rounded-pill bg-<?php echo $on ? 'primary' : 'light text-muted border'; ?>"><?php echo $n; ?></span>
                            <span class="<?php echo $on ? 'fw-semibold' : 'text-muted'; ?>"><?php echo $giLabel; ?></span>
                        </span>
                        <?php if ($giI < count($giSteps) - 1): ?>
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
                            <form method="POST" action="index.php?action=groupImportUpload" enctype="multipart/form-data">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Staff VG Schedule (Excel)</label>
                                    <input type="file" name="import_file" class="form-control" accept=".xlsx,.xls" required>
                                    <div class="form-text">Excel (.xlsx, .xls) — up to 5 MB.</div>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-upload me-1"></i>Upload and Check
                                </button>
                                <div class="form-text mt-2">
                                    <i class="bi bi-shield-check me-1 text-success"></i>
                                    Uploading only reads the file. No group and no member is created until you confirm.
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>What the schedule looks like</h6></div>
                        <div class="card-body small">
                            <p class="mb-2">The importer reads the schedule the way it is laid out — as group cards,
                               each one starting with <strong>VGL:</strong> and the leader's name beside it:</p>
                            <pre class="bg-light border rounded p-2 mb-3 small">VGL:                  Philip and Sab Porras
Intern:
Members:
 1.                   Alejandro Abad
 2.                   Cara Abad
Type of VG:           Couples
Day:                  Thursday
Time:                 7:00 PM
Frequency of meeting: Weekly
Venue:                Quinos Lacson</pre>
                            <div class="alert alert-light border mb-0 py-2">
                                <i class="bi bi-lightbulb me-1 text-warning"></i>
                                Names are kept exactly as written. A couple such as
                                <strong>Amy &amp; Carl</strong> stays one name and is never split into two people.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- ─── Step 2: preview ───────────────────────────────────────── -->
            <div class="row g-3 mb-3">
                <?php
                $giCards = [
                    ['Group cards found', $summary['total'],            'secondary', 'bi-grid-3x3-gap'],
                    ['Victory Groups',    $summary['victory_group'],    'primary',   'bi-people-fill'],
                    ['Leadership Groups', $summary['leadership_group'], 'info',      'bi-person-badge-fill'],
                    ['Campus groups',     $summary['campus'],           'warning',   'bi-mortarboard-fill'],
                    ['Cannot import',     $summary['invalid'],          'danger',    'bi-x-circle'],
                ];
                foreach ($giCards as [$giL, $giV, $giC, $giIcon]):
                ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card h-100 border-<?php echo $giC; ?> border-opacity-50">
                        <div class="card-body text-center py-3">
                            <i class="bi <?php echo $giIcon; ?> text-<?php echo $giC; ?>"></i>
                            <div class="h3 fw-bold text-<?php echo $giC; ?> mb-0"><?php echo (int)$giV; ?></div>
                            <div class="small text-muted"><?php echo $giL; ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="row g-3 mb-4">
                <?php
                $giPeople = [
                    ['Names on the sheet',  $summary['people'],     'secondary', 'bi-person-lines-fill'],
                    ['Matched to a member', $summary['linked'],     'success',   'bi-person-check-fill'],
                    ['Needs Link',          $summary['needs_link'], 'warning',   'bi-link-45deg'],
                    ['Couple names',        $summary['couples'],    'info',      'bi-people'],
                ];
                foreach ($giPeople as [$giL, $giV, $giC, $giIcon]):
                ?>
                <div class="col-6 col-xl-3">
                    <div class="card h-100 border-<?php echo $giC; ?> border-opacity-50">
                        <div class="card-body text-center py-3">
                            <i class="bi <?php echo $giIcon; ?> text-<?php echo $giC; ?>"></i>
                            <div class="h3 fw-bold text-<?php echo $giC; ?> mb-0"><?php echo (int)$giV; ?></div>
                            <div class="small text-muted"><?php echo $giL; ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($summary['needs_link'] > 0): ?>
            <div class="alert alert-warning">
                <i class="bi bi-link-45deg me-1"></i>
                <strong><?php echo (int)$summary['needs_link']; ?></strong> of
                <strong><?php echo (int)$summary['people']; ?></strong> names do not match anyone on your member list.
                They will be saved <strong>exactly as written</strong> and shown as
                <span class="badge bg-warning text-dark">Needs Link</span> on the Victory Groups page.
                <strong>No member record is created for them.</strong> Add those people as members first, then link
                them by hand — which is the expected order for the 2026 start.
            </div>
            <?php endif; ?>

            <form method="POST" action="index.php?action=groupImportCommit" id="giCommitForm">
                <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($batchId); ?>">

                <div class="alert alert-primary d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <i class="bi bi-clipboard-check me-1"></i>
                        <strong><?php echo (int)$summary['will_create']; ?></strong>
                        group<?php echo $summary['will_create'] === 1 ? '' : 's'; ?> will be created
                        (<?php echo (int)$summary['victory_group']; ?> Victory,
                         <?php echo (int)$summary['leadership_group']; ?> Leadership,
                         <?php echo (int)$summary['campus']; ?> Campus).
                        <?php if ($summary['already_imported'] > 0): ?>
                        <strong><?php echo (int)$summary['already_imported']; ?></strong> already imported and will be skipped.
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($summary['needs_link'] > 0): ?>
                        <a href="index.php?action=groupImportReview&batch=<?php echo urlencode($batchId); ?>"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-download me-1"></i>Download Needs-Link List
                        </a>
                        <?php endif; ?>
                        <a href="index.php?action=groupImportDiscard&batch=<?php echo urlencode($batchId); ?>"
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Discard this import? Nothing has been created yet.');">
                            <i class="bi bi-x-circle me-1"></i>Discard
                        </a>
                    </div>
                </div>

                <?php foreach ($cards as $c):
                    [$giKindLabel, $giKindColor, $giKindIcon] = $giKind[$c['group_kind']] ?? ['Group', 'secondary', 'bi-dot'];
                    [$giStLabel, $giStColor, $giStIcon]       = $giStatus[$c['row_status']] ?? ['Unknown', 'secondary', 'bi-question'];
                    $giIssues   = json_decode($c['issues'] ?? '[]', true) ?: [];
                    $giImported = !empty($c['created_group_id']);
                    $giCanPick  = !$giImported && $c['row_status'] !== 'invalid';
                ?>
                <div class="card mb-3 <?php echo $giImported ? 'opacity-75' : ''; ?>">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <?php if ($giImported): ?>
                        <span class="badge bg-success"><i class="bi bi-check me-1"></i>Already created</span>
                        <?php elseif ($giCanPick): ?>
                        <div class="form-check mb-0">
                            <input class="form-check-input gi-pick" type="checkbox" checked
                                   name="import[]" value="<?php echo (int)$c['id']; ?>"
                                   id="giCard<?php echo (int)$c['id']; ?>">
                            <label class="form-check-label small" for="giCard<?php echo (int)$c['id']; ?>">Import</label>
                        </div>
                        <?php else: ?>
                        <span class="text-muted small"><i class="bi bi-dash-circle me-1"></i>Not importable</span>
                        <?php endif; ?>

                        <span class="badge bg-<?php echo $giKindColor; ?>">
                            <i class="bi <?php echo $giKindIcon; ?> me-1"></i><?php echo $giKindLabel; ?>
                        </span>
                        <span class="fw-semibold"><?php echo htmlspecialchars($c['group_name'] ?: '(unnamed group)'); ?></span>
                        <span class="badge bg-<?php echo $giStColor; ?>">
                            <i class="bi <?php echo $giStIcon; ?> me-1"></i><?php echo $giStLabel; ?>
                        </span>
                        <span class="ms-auto small text-muted">
                            <?php echo htmlspecialchars($c['sheet_name'] . ' ' . $c['cell_ref']); ?>
                            &middot; <?php echo (int)$c['people_count']; ?> listed
                            <?php if ((int)$c['needs_link_count'] > 0): ?>
                            &middot; <span class="text-warning fw-semibold"><?php echo (int)$c['needs_link_count']; ?> needs link</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <dl class="row small mb-0">
                                    <dt class="col-5 text-muted fw-normal">On the sheet</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars($c['source_label'] ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Type of VG</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars($c['raw_audience'] ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Day</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars($c['day_of_week'] ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Time</dt>
                                    <dd class="col-7">
                                        <?php if ($c['meeting_time']): ?>
                                            <?php echo htmlspecialchars(date('g:i A', strtotime('2000-01-01 ' . $c['meeting_time']))); ?>
                                        <?php else: ?>
                                            <span class="text-muted">not set</span>
                                        <?php endif; ?>
                                        <?php if ($c['raw_time'] !== '' && $c['raw_time'] !== null): ?>
                                        <span class="text-muted">(sheet: <?php echo htmlspecialchars($c['raw_time']); ?>)</span>
                                        <?php endif; ?>
                                    </dd>
                                    <dt class="col-5 text-muted fw-normal">Frequency</dt>
                                    <dd class="col-7"><?php echo htmlspecialchars($c['meeting_frequency'] ?: '—'); ?></dd>
                                    <dt class="col-5 text-muted fw-normal">Venue</dt>
                                    <dd class="col-7 mb-0"><?php echo htmlspecialchars($c['venue'] ?: '—'); ?></dd>
                                </dl>
                                <?php if ($giIssues): ?>
                                <ul class="small text-muted mt-2 mb-0 ps-3">
                                    <?php foreach ($giIssues as $giIssue): ?>
                                    <li><?php echo htmlspecialchars($giIssue); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php endif; ?>
                            </div>
                            <div class="col-lg-7">
                                <?php if (empty($c['people'])): ?>
                                <p class="text-muted small mb-0">No names listed on this card.</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:110px;">Role</th>
                                                <th>Name as written</th>
                                                <th style="width:40%;">Member link</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($c['people'] as $p):
                                                $giPIssues = json_decode($p['issues'] ?? '[]', true) ?: [];
                                            ?>
                                            <tr>
                                                <td class="small text-muted"><?php echo htmlspecialchars($giRole[$p['person_role']] ?? $p['person_role']); ?></td>
                                                <td class="small">
                                                    <?php echo htmlspecialchars($p['display_name']); ?>
                                                    <?php if (!empty($p['is_couple_name'])): ?>
                                                    <span class="badge bg-info bg-opacity-25 text-dark border" style="font-size:.65rem"
                                                          title="Kept as one name — never split into two people. It imports with TWO link slots, so you can link each spouse to their own member record by hand on the Victory Groups page.">
                                                        <i class="bi bi-people me-1"></i>couple — 2 links
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small">
                                                    <?php if ($p['link_status'] === 'linked'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-person-check-fill me-1"></i>Linked
                                                    </span>
                                                    <span class="text-muted"><?php echo htmlspecialchars($p['matched_full_name'] ?? ''); ?></span>
                                                    <?php else: ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="bi bi-link-45deg me-1"></i>Needs Link
                                                    </span>
                                                    <?php if ($giPIssues): ?>
                                                    <span class="text-muted"><?php echo htmlspecialchars($giPIssues[0]); ?></span>
                                                    <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- ─── Step 3: confirm ───────────────────────────────────── -->
                <div class="card mb-5 border-primary">
                    <div class="card-body text-center py-4">
                        <h5 class="mb-2">Ready to create these groups?</h5>
                        <p class="text-muted mb-3">
                            <strong id="giWillCreate"><?php echo (int)$summary['will_create']; ?></strong>
                            group<span id="giPlural"><?php echo $summary['will_create'] === 1 ? '' : 's'; ?></span>
                            will be created. Cards you untick are left alone.
                            <br>
                            <span class="small">
                                <i class="bi bi-shield-check text-success me-1"></i>
                                No member record is created by this import. Unmatched names are saved as
                                <strong>Needs Link</strong> for you to resolve.
                            </span>
                        </p>
                        <button type="submit" class="btn btn-primary btn-lg px-5"
                                onclick="return confirm('Create these groups now?');">
                            <i class="bi bi-check-circle me-1"></i>Confirm Import
                        </button>
                        <div class="form-text mt-2">
                            This is the step that creates them. It can't be undone from here.
                        </div>
                    </div>
                </div>
            </form>

            <script>
            // Keep the headline count honest as cards are ticked and unticked.
            (function () {
                var out  = document.getElementById('giWillCreate');
                var plur = document.getElementById('giPlural');
                if (!out) return;
                function sync() {
                    var n = document.querySelectorAll('.gi-pick:checked').length;
                    out.textContent  = n;
                    plur.textContent = n === 1 ? '' : 's';
                }
                document.querySelectorAll('.gi-pick').forEach(function (cb) {
                    cb.addEventListener('change', sync);
                });
            })();
            </script>
            <?php endif; ?>

        </div>
    </div>
<?php include 'shared/footer.php'; ?>
