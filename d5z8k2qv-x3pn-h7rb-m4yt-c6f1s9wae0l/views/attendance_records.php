<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';

$PROGRAM_DEFS = [
    'victory_weekend'    => ['label' => 'Victory Weekend',    'color' => 'primary',   'icon' => 'bi-sun',         'short' => 'VW'],
    'church_community'   => ['label' => 'Church Community',   'color' => 'secondary', 'icon' => 'bi-building',    'short' => 'CC'],
    'making_disciples'   => ['label' => 'Making Disciples',   'color' => 'success',   'icon' => 'bi-person-plus', 'short' => 'MD'],
    'empowering_leaders' => ['label' => 'Empowering Leaders', 'color' => 'warning',   'icon' => 'bi-star',        'short' => 'EL'],
    'leadership_113'     => ['label' => 'Leadership 113',     'color' => 'danger',    'icon' => 'bi-trophy',      'short' => 'L113'],
];
$activeFilters       = $activeFilters       ?? [];
$records             = $records             ?? [];
$availableYears      = $availableYears      ?? [];
$paStats             = $paStats             ?? [];
$memberStats         = $memberStats         ?? [];
$matchStats          = $matchStats          ?? [];
$allEventLabels      = $allEventLabels      ?? [];
$allCounselors       = $allCounselors       ?? [];
$availableEventDates = $availableEventDates ?? [];
$activeTab      = $_GET['tab']    ?? 'records';
// Active class helpers
$activePt       = $activeFilters['program_type'] ?? '';
$activePtDef    = $activePt ? ($PROGRAM_DEFS[$activePt] ?? null) : null;
$isAllView      = !$activePt;
$isVWView       = $activePt === 'victory_weekend';
$isCCView       = $activePt === 'church_community';
$isMDView       = $activePt === 'making_disciples';
$isELView       = $activePt === 'empowering_leaders';
$isL113View     = $activePt === 'leadership_113';
// Column visibility
$colProgram     = $isAllView;
$colWaterBap    = $isAllView || $isVWView;
$colDoneVW      = $isAllView || $isVWView;
$colMdCols      = $isMDView;
$colExtraInfo   = $isAllView || $isL113View;
$colCounselor   = !$isCCView && !$isMDView && !$isELView;
// Show Batch column only if any record in the active view has a batch_label.
// (Right now only L113 has values — the column quietly stays hidden for VW/CC/MD/EL
// until somebody manually fills in those classes' batches.)
$showBatchCol = false;
foreach ($records as $_rec) {
    if (!empty(trim($_rec['batch_label'] ?? ''))) { $showBatchCol = true; break; }
}
// Show Notes column only when at least one record in the active view has a non-empty note.
// L113 view never shows it (per spec — L113 can't be re-taken so the original use case doesn't apply).
$showNotesCol = false;
if (!$isL113View) {
    foreach ($records as $_rec) {
        if (trim((string)($_rec['notes'] ?? '')) !== '') { $showNotesCol = true; break; }
    }
}
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <?php if ($activePtDef): ?>
                    <h1 class="h3 mb-0">
                        <i class="bi <?php echo $activePtDef['icon']; ?> me-2 text-<?php echo $activePtDef['color']; ?>"></i>
                        <?php echo htmlspecialchars($activePtDef['label']); ?>
                    </h1>
                    <p class="text-muted mb-0">
                        <?php echo htmlspecialchars($activePtDef['label']); ?> attendance records
                        <?php if (!empty($activeFilters['program_year'])): ?> &mdash; <?php echo $activeFilters['program_year']; ?><?php endif; ?>
                    </p>
                    <?php else: ?>
                    <h1 class="h3 mb-0"><i class="bi bi-calendar-check me-2 text-success"></i>Attendance Records</h1>
                    <p class="text-muted mb-0">All program attendance data — all classes, all years</p>
                    <?php endif; ?>
                </div>
                <button class="btn btn-<?php echo $activePtDef ? $activePtDef['color'] : 'primary'; ?>"
                        data-bs-toggle="modal" data-bs-target="#addAttendanceModal">
                    <i class="bi bi-plus-circle me-1"></i>Add <?php echo $activePtDef ? htmlspecialchars($activePtDef['label']).' ' : ''; ?>Record
                </button>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'records' ? 'active' : ''; ?>"
                       href="index.php?action=attendanceRecords&tab=records<?php echo !empty($activeFilters['program_type']) ? '&program_type='.$activeFilters['program_type'] : ''; ?><?php echo !empty($activeFilters['program_year']) ? '&program_year='.$activeFilters['program_year'] : ''; ?>">
                        <i class="bi bi-table me-1"></i>Records
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'stats' ? 'active' : ''; ?>"
                       href="index.php?action=attendanceRecords&tab=stats<?php echo $activePt ? '&program_type='.urlencode($activePt) : ''; ?>">
                        <i class="bi bi-bar-chart-line me-1"></i>Statistics
                    </a>
                </li>
            </ul>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php $msgs = [
                    'add'        => 'Attendance record has been added successfully.',
                    'update'     => 'Attendance record has been updated successfully. Changes are now reflected on the records.',
                    'deactivate' => 'Attendance record has been deactivated successfully. Press the activate button to enable it again.',
                    'activate'   => 'Attendance record has been reactivated successfully.',
                    'delete'     => 'Attendance record has been deleted successfully. The record has been removed from the attendance records list.',
                ]; echo $msgs[$_GET['notif']] ?? 'Done.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>An error occurred.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($activeTab === 'records'): ?>

            <!-- Stats Cards -->
            <?php if ($activePtDef):
                // Compute class-specific stats from already-filtered $records
                $statTotal     = count($records);
                $statMatched   = 0; $statWBap    = 0;
                $statMdPart1   = 0; $statMdPart2 = 0;
                $statL113Done  = 0;
                foreach ($records as $_r) {
                    if ($_r['member_id']) $statMatched++;
                    if ($_r['water_baptism']) $statWBap++;
                    if ($_r['md_part1'])      $statMdPart1++;
                    if ($_r['md_part2'])      $statMdPart2++;
                    if ($isL113View && $_r['extra_data']) {
                        $_ed = json_decode($_r['extra_data'], true);
                        if ($_ed && !empty($_ed['sessions'])) {
                            $_absent = 0;
                            foreach ($_ed['sessions'] as $_s) { if (strtoupper(trim($_s)) === 'A') $_absent++; }
                            if ($_absent === 0) $statL113Done++;
                        }
                    }
                }
                $c = $activePtDef['color'];
            ?>
            <div class="row mb-4 g-2">
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100 border-<?php echo $c; ?> border-2">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-<?php echo $c; ?> mb-0"><?php echo $statTotal; ?></div>
                            <div style="font-size:11px;" class="text-muted">Total Records</div>
                        </div>
                    </div>
                </div>
                <?php if ($isVWView): ?>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-primary mb-0"><?php echo $statWBap; ?></div>
                            <div style="font-size:11px;" class="text-muted"><i class="bi bi-droplet-fill me-1"></i>Water Baptism</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-success mb-0"><?php echo $statMatched; ?></div>
                            <div style="font-size:11px;" class="text-muted">Matched Members</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-secondary mb-0"><?php echo $statTotal - $statMatched; ?></div>
                            <div style="font-size:11px;" class="text-muted">Unmatched</div>
                        </div>
                    </div>
                </div>
                <?php if ($isMDView): ?>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-success mb-0"><?php echo $statMdPart1; ?></div>
                            <div style="font-size:11px;" class="text-muted">Part 1 Attended</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-success mb-0"><?php echo $statMdPart2; ?></div>
                            <div style="font-size:11px;" class="text-muted">Part 2 Attended</div>
                        </div>
                    </div>
                </div>
                <?php elseif ($isL113View): ?>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-success mb-0"><?php echo $statL113Done; ?></div>
                            <div style="font-size:11px;" class="text-muted">Complete</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-warning mb-0"><?php echo $statTotal - $statL113Done; ?></div>
                            <div style="font-size:11px;" class="text-muted">Incomplete</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- All-classes program cards -->
            <div class="row mb-4 g-2">
                <?php foreach ($PROGRAM_DEFS as $pType => $pDef):
                    $totalForProgram = 0;
                    foreach ($paStats[$pType] ?? [] as $yr => $cnt) $totalForProgram += $cnt;
                ?>
                <div class="col-6 col-md-4 col-xl-2-4">
                    <a href="index.php?action=attendanceRecords&program_type=<?php echo $pType; ?>"
                       class="text-decoration-none">
                        <div class="card text-center h-100" style="cursor:pointer;">
                            <div class="card-body py-2 px-1">
                                <div class="h4 fw-bold text-<?php echo $pDef['color']; ?> mb-0"><?php echo $totalForProgram; ?></div>
                                <div style="font-size:11px;" class="text-muted"><?php echo $pDef['label']; ?></div>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Search & Year Breakdown (merged) -->
            <?php
            // Count active removable filters (program_type only counts in all-view)
            $atFilterCount = count(array_filter([
                $activePt ? '' : ($activeFilters['program_type'] ?? ''),
                $activeFilters['program_year']    ?? '',
                $activeFilters['event_date_from'] ?? '',
                $activeFilters['event_date_to']   ?? '',
            ]));
            $clearUrl = 'index.php?action=attendanceRecords' . ($activePt ? '&program_type='.urlencode($activePt) : '');
            ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="text-white fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-funnel me-2"></i>Search & Filter
                        <span class="badge bg-white text-primary" id="atActiveFilterBadge" style="display:none">0 active</span>
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?php echo $clearUrl; ?>" class="btn btn-sm btn-outline-light" id="atClearFilterBtn" style="display:none"
                           onclick="filterAtClassYear('', 0); filterAtMatchStatus('all'); var s=document.getElementById('atFilterSearch'); if(s)s.value=''; <?php echo $atFilterCount > 0 ? 'return true' : 'if(window.atTable){window.atTable.search(\'\').draw();} return false'; ?>;">
                            <i class="bi bi-x-circle me-1"></i>Clear Filters
                        </a>
                        <?php if ($activePt): ?>
                        <a href="index.php?action=attendanceRecords" class="btn btn-sm btn-outline-light">
                            <i class="bi bi-layout-three-columns me-1"></i>All Classes
                        </a>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#atFilterBody" aria-expanded="true">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div class="collapse show" id="atFilterBody">
                <div class="card-body py-3">
                    <!-- Search -->
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="atFilterSearch" class="form-control"
                               placeholder="Search name<?php echo $activePtDef ? '' : ', program'; ?>, year, event, counselor…" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('atFilterSearch').value='';if(window.atTable)window.atTable.search('').draw();">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <?php if ($activePtDef): ?>
                    <!-- Class-specific year filter -->
                    <?php $classYears = $paStats[$activePt] ?? []; krsort($classYears); ?>
                    <?php if (!empty($classYears)): ?>
                    <div class="border-top pt-3">
                        <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                            <i class="bi bi-calendar me-1"></i>Filter by Year
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <a href="index.php?action=attendanceRecords&program_type=<?php echo $activePt; ?>"
                               class="btn btn-sm <?php echo empty($activeFilters['program_year']) ? 'btn-'.$activePtDef['color'] : 'btn-outline-'.$activePtDef['color']; ?>">
                                All Years
                            </a>
                            <?php foreach ($classYears as $yr => $cnt):
                                $isYrActive = (($activeFilters['program_year'] ?? '') == $yr);
                            ?>
                            <a href="index.php?action=attendanceRecords&program_type=<?php echo $activePt; ?>&program_year=<?php echo $yr; ?>"
                               class="btn btn-sm <?php echo $isYrActive ? 'btn-'.$activePtDef['color'] : 'btn-outline-'.$activePtDef['color']; ?>">
                                <?php echo $yr; ?> <span class="badge bg-white text-<?php echo $activePtDef['color']; ?> ms-1"><?php echo $cnt; ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php
                        // Show event-date buttons when a year is selected and distinct dates exist
                        $availableEventDates = $availableEventDates ?? [];
                        $activeDateFrom      = $activeFilters['event_date_from'] ?? '';
                        if (!empty($activeFilters['program_year']) && count($availableEventDates) > 1):
                        ?>
                        <div class="d-flex align-items-center flex-wrap gap-2 mt-2 pt-2 border-top border-dashed">
                            <span class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em; white-space:nowrap;">
                                <i class="bi bi-calendar3 me-1"></i>Event Date
                            </span>
                            <a href="index.php?action=attendanceRecords&program_type=<?php echo $activePt; ?>&program_year=<?php echo $activeFilters['program_year']; ?>"
                               class="btn btn-sm <?php echo empty($activeDateFrom) ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                                All Dates
                            </a>
                            <?php foreach ($availableEventDates as $_evDate):
                                $isEvActive = ($activeDateFrom === $_evDate);
                                $dt1 = new DateTime($_evDate);
                                // For 2-day events (Victory Weekend), show both days
                                if ($activePt === 'victory_weekend') {
                                    $dt2 = (clone $dt1)->modify('+1 day');
                                    if ($dt1->format('M') === $dt2->format('M')) {
                                        $_evLabel = $dt1->format('M j') . '-' . $dt2->format('j, Y');
                                    } else {
                                        $_evLabel = $dt1->format('M j') . ' & ' . $dt2->format('M j, Y');
                                    }
                                } else {
                                    $_evLabel = $dt1->format('M j, Y');
                                }
                            ?>
                            <a href="index.php?action=attendanceRecords&program_type=<?php echo $activePt; ?>&program_year=<?php echo $activeFilters['program_year']; ?>&event_date_from=<?php echo $_evDate; ?>&event_date_to=<?php echo $_evDate; ?>"
                               class="btn btn-sm <?php echo $isEvActive ? 'btn-'.$activePtDef['color'] : 'btn-outline-'.$activePtDef['color']; ?>">
                                <i class="bi bi-calendar3 me-1"></i><?php echo $_evLabel; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <!-- All-classes year breakdown (client-side DataTables filter — no redirect) -->
                    <?php if (!empty($paStats)): ?>
                    <div class="border-top pt-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em">
                                <i class="bi bi-bar-chart me-1"></i>Filter by Class &amp; Year
                            </div>
                        </div>
                        <?php foreach ($PROGRAM_DEFS as $pType => $pDef):
                            // Use unique-member counts here so the badge totals match the pivot rows
                            // shown when this class/year is clicked. The pivot displays one row per
                            // member — so a member with two records in the same year counts once.
                            $yearData = $memberStats[$pType] ?? ($paStats[$pType] ?? []);
                            if (empty($yearData)) continue;
                            krsort($yearData);   // newest year first, matches the per-class view
                            $pColor   = $pDef['color'];
                        ?>
                        <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                            <span class="badge bg-<?php echo $pColor; ?>" style="min-width:130px;text-align:left;padding:.4em .6em">
                                <i class="bi <?php echo $pDef['icon']; ?> me-1"></i><?php echo $pDef['label']; ?>
                            </span>
                            <?php foreach ($yearData as $yr => $cnt): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-<?php echo $pColor; ?> at-class-filter-btn"
                                    data-pt="<?php echo $pType; ?>"
                                    data-yr="<?php echo $yr; ?>"
                                    data-color="<?php echo $pColor; ?>"
                                    onclick="filterAtClassYear('<?php echo $pType; ?>', <?php echo $yr; ?>)">
                                <?php echo $yr; ?> <span class="badge bg-white text-<?php echo $pColor; ?> ms-1"><?php echo $cnt; ?></span>
                            </button>
                            <?php endforeach; ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary at-class-filter-btn"
                                    data-pt="<?php echo $pType; ?>"
                                    data-yr="0"
                                    data-color="secondary"
                                    onclick="filterAtClassYear('<?php echo $pType; ?>', 0)">
                                All
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <!-- Match status filter -->
                    <div class="border-top pt-3 mt-2">
                        <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                            <i class="bi bi-link-45deg me-1"></i>Filter by Match Status
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-primary at-match-filter-btn" data-match="all">All Records</button>
                            <button type="button" class="btn btn-sm btn-outline-success at-match-filter-btn" data-match="matched"><i class="bi bi-person-check me-1"></i>Matched Only</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary at-match-filter-btn" data-match="unmatched"><i class="bi bi-person-exclamation me-1"></i>Unmatched Only</button>
                        </div>
                    </div>
                    <!-- Date range filter -->
                    <?php
                    $_applyColor = $activePtDef['color'] ?? 'primary';
                    // EL uses btn-warning which has light text by default — keep its text black so the label is readable.
                    $_applyExtra = ($_applyColor === 'warning') ? ' text-dark' : '';
                    ?>
                    <div class="border-top pt-3 mt-2">
                        <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                            <i class="bi bi-calendar-range me-1"></i>Filter by Date Range
                        </div>
                        <form method="GET" action="index.php" class="d-flex flex-wrap gap-2 align-items-end">
                            <input type="hidden" name="action" value="attendanceRecords">
                            <?php if ($activePt): ?><input type="hidden" name="program_type" value="<?php echo htmlspecialchars($activePt); ?>"><?php endif; ?>
                            <?php if (!empty($activeFilters['program_year'])): ?><input type="hidden" name="program_year" value="<?php echo htmlspecialchars($activeFilters['program_year']); ?>"><?php endif; ?>
                            <div>
                                <label class="form-label small mb-1">From</label>
                                <input type="date" name="event_date_from" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($activeFilters['event_date_from'] ?? ''); ?>">
                            </div>
                            <div>
                                <label class="form-label small mb-1">To</label>
                                <input type="date" name="event_date_to" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($activeFilters['event_date_to'] ?? ''); ?>">
                            </div>
                            <div class="d-flex gap-1">
                                <button type="submit" class="btn btn-sm btn-<?php echo $_applyColor . $_applyExtra; ?>"><i class="bi bi-funnel me-1"></i>Apply</button>
                                <?php if (!empty($activeFilters['event_date_from']) || !empty($activeFilters['event_date_to'])): ?>
                                <a href="<?php echo $clearUrl; ?><?php echo !empty($activeFilters['program_year']) ? '&program_year='.urlencode($activeFilters['program_year']) : ''; ?>"
                                   class="btn btn-sm btn-outline-secondary">Clear Date</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                </div>
            </div>

            <?php if ($isAllView): ?>
            <!-- All Classes completion overview — counts members who have any record per class. Updates with filters. -->
            <div class="card mb-3">
                <div class="card-header py-2 px-3">
                    <span class="text-white fw-semibold"><i class="bi bi-bar-chart-line-fill me-1"></i>Discipleship Journey Overview</span>
                    <span class="badge bg-white text-primary ms-2" id="atOverviewTotal">0 members</span>
                </div>
                <div class="card-body py-3 px-3" id="atOverviewBody">
                    <div class="row g-2">
                        <?php
                        $overviewCards = [
                            ['key' => 'vw',   'label' => 'Victory Weekend',     'color' => 'primary',   'icon' => 'bi-sun'],
                            ['key' => 'cc',   'label' => 'Church Community',    'color' => 'secondary', 'icon' => 'bi-building'],
                            ['key' => 'md',   'label' => 'Making Disciples',    'color' => 'success',   'icon' => 'bi-person-plus'],
                            ['key' => 'el',   'label' => 'Empowering Leaders',  'color' => 'warning',   'icon' => 'bi-star'],
                            ['key' => 'l113', 'label' => 'Leadership 1-1-3',    'color' => 'danger',    'icon' => 'bi-trophy'],
                        ];
                        foreach ($overviewCards as $c): ?>
                        <div class="col-6 col-md">
                            <div class="border rounded p-2 h-100">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-semibold"><i class="bi <?php echo $c['icon']; ?> text-<?php echo $c['color']; ?> me-1"></i><?php echo $c['label']; ?></span>
                                    <span class="badge bg-<?php echo $c['color']; ?>" id="atOv_<?php echo $c['key']; ?>">0/0</span>
                                </div>
                                <div class="progress" style="height:6px;">
                                    <div class="progress-bar bg-<?php echo $c['color']; ?>" id="atOvBar_<?php echo $c['key']; ?>" style="width:0%"></div>
                                </div>
                                <div class="text-end small text-muted mt-1"><span id="atOvPct_<?php echo $c['key']; ?>">0%</span></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isVWView || $isMDView): ?>
            <!-- Completion summary line (updates with filters via atSummaryUpdate JS) -->
            <div class="card mb-3 border-0" style="background:#f6f8fc;">
                <div class="card-body py-2 px-3" id="atCompletionSummary">
                    <span class="text-muted small me-2"><i class="bi bi-bar-chart-fill me-1"></i>Showing:</span>
                    <?php if ($isVWView): ?>
                    <span class="me-3 small"><i class="bi bi-droplet-fill text-primary me-1"></i>Water Baptism: <strong id="atSumWaterBap">0/0</strong> <span class="text-muted">(<span id="atSumWaterBapPct">0%</span>)</span></span>
                    <?php elseif ($isMDView): ?>
                    <span class="me-3 small"><i class="bi bi-1-circle text-success me-1"></i>Part 1: <strong id="atSumMdP1">0/0</strong> <span class="text-muted">(<span id="atSumMdP1Pct">0%</span>)</span></span>
                    <span class="me-3 small"><i class="bi bi-2-circle text-success me-1"></i>Part 2: <strong id="atSumMdP2">0/0</strong> <span class="text-muted">(<span id="atSumMdP2Pct">0%</span>)</span></span>
                    <span class="me-3 small"><i class="bi bi-check-circle-fill text-success me-1"></i>Both parts: <strong id="atSumMdBoth">0/0</strong> <span class="text-muted">(<span id="atSumMdBothPct">0%</span>)</span></span>
                    <span class="me-3 small"><i class="bi bi-circle text-muted me-1"></i>Neither: <strong id="atSumMdNeither">0</strong></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Records Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold d-flex align-items-center flex-wrap gap-2">
                        <?php
                        $tableTitleMap = [
                            'victory_weekend'    => 'Victory Weekend Records',
                            'church_community'   => 'Church Community Records',
                            'making_disciples'   => 'Making Disciples Records',
                            'empowering_leaders' => 'Empowering Leaders Records',
                        ];
                        $tableTitle = $tableTitleMap[$activePt ?? ''] ?? 'Attendance Records';
                        ?>
                        <i class="bi bi-table me-2"></i><?php echo htmlspecialchars($tableTitle); ?>
                        <span class="badge bg-white text-dark border" id="atRecordCount"><?php echo count($records); ?></span>
                        <?php if (!empty($activeFilters['program_year'])): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-calendar me-1"></i>Year: <?php echo htmlspecialchars($activeFilters['program_year']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($activeFilters['program_label'])): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-collection me-1"></i>Event: <?php echo htmlspecialchars($activeFilters['program_label']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($activeFilters['event_date_from']) || !empty($activeFilters['event_date_to'])): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-calendar-range me-1"></i>Date: <?php echo htmlspecialchars($activeFilters['event_date_from'] ?? '…'); ?> → <?php echo htmlspecialchars($activeFilters['event_date_to'] ?? '…'); ?></span>
                        <?php endif; ?>
                        <span class="badge bg-light text-dark border" id="atSearchBadge" style="display:none"></span>
                        <span class="badge border" id="atClientFilterBadge" style="display:none"></span>
                    </span>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 me-3">
                            <label class="text-white small mb-0">Show</label>
                            <select id="atPerPage" class="form-select form-select-sm" style="width:auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="-1">All</option>
                            </select>
                            <label class="text-white small mb-0">per page</label>
                        </div>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportAtCSV()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportAtExcel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportAtPDF()" title="Export PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="printAt()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($records)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-calendar-x display-4 mb-3 d-block"></i>
                        No records found.<br>
                        <small>
                            <?php if (empty($activeFilters)): ?>
                            Run the <a href="import.php">data import</a> first.
                            <?php else: ?>
                            Try adjusting your filters.
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="width:100%" id="attendanceTable">
                            <thead>
                                <tr>
                                <?php if ($isAllView): /* All Classes — pivot view: one row per member, all class columns */ ?>
                                    <th>#</th>
                                    <th>Participant Name</th>
                                    <th class="text-center"><i class="bi bi-sun me-1 text-primary"></i>Victory Weekend</th>
                                    <th class="text-center"><i class="bi bi-droplet-fill me-1 text-primary"></i>Baptized</th>
                                    <th class="text-center"><i class="bi bi-building me-1 text-secondary"></i>Church Community</th>
                                    <th class="text-center"><i class="bi bi-person-plus me-1 text-success"></i>Making Disciples</th>
                                    <th class="text-center"><i class="bi bi-star me-1 text-warning"></i>Empowering Leaders</th>
                                    <th class="text-center"><i class="bi bi-trophy me-1 text-danger"></i>Leadership 1-1-3</th>
                                    <th>Contact #</th>
                                    <th>Matched Member</th>
                                    <th class="text-center" title="Number of classes this participant has any record for, out of 5 total (VW, CC, MD, EL, L113).">Progress</th>
                                    <th class="text-center">Actions</th>
                                <?php elseif ($isVWView): ?>
                                    <th>#</th>
                                    <th>Participant Name</th>
                                    <th class="text-center"><i class="bi bi-droplet-fill me-1 text-primary"></i>Baptized</th>
                                    <th>Event Date</th>
                                    <?php if ($showBatchCol): ?><th>Batch</th><?php endif; ?>
                                    <th>Counselor</th>
                                    <th style="width:130px;">Contact #</th>
                                    <th style="width:240px;">Matched Member</th>
                                    <?php if ($showNotesCol): ?><th>Notes</th><?php endif; ?>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                <?php elseif ($isCCView): ?>
                                    <th>#</th>
                                    <th>Participant Name</th>
                                    <th>Event Date</th>
                                    <?php if ($showBatchCol): ?><th>Batch</th><?php endif; ?>
                                    <th>Contact #</th>
                                    <th>Matched Member</th>
                                    <?php if ($showNotesCol): ?><th>Notes</th><?php endif; ?>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                <?php elseif ($isMDView): ?>
                                    <th>#</th>
                                    <th>Participant Name</th>
                                    <th class="text-center">Part 1 Event Date</th>
                                    <th class="text-center">Part 2 Event Date</th>
                                    <?php if ($showBatchCol): ?><th>Batch</th><?php endif; ?>
                                    <th>Contact #</th>
                                    <th>Matched Member</th>
                                    <?php if ($showNotesCol): ?><th>Notes</th><?php endif; ?>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                <?php elseif ($isELView): ?>
                                    <th>#</th>
                                    <th>Participant Name</th>
                                    <th>Event Date</th>
                                    <?php if ($showBatchCol): ?><th>Batch</th><?php endif; ?>
                                    <th>Contact #</th>
                                    <th>Matched Member</th>
                                    <?php if ($showNotesCol): ?><th>Notes</th><?php endif; ?>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // ────────────────────────────────────────────────────────────────────────────────
                                // PIVOT view for "All Classes" — group records by participant so one row shows
                                // every class that person has any record for. This matches the admin's mental model
                                // ("Aguirre, Joemarie completed VW + CC + MD + EL" → one row, four checks).
                                // Specific class views keep the record-by-record layout (one row per attendance).
                                // ────────────────────────────────────────────────────────────────────────────────
                                // Helper for the small "event date" line under each ✓.
                                // Defined OUTSIDE the foreach so PHP doesn't re-declare it on every iteration.
                                if (!function_exists('_atDateLine')) {
                                    function _atDateLine($rec) {
                                        if (!$rec) return '';
                                        $lbl = !empty($rec['event_date']) ? date('M j, Y', strtotime($rec['event_date'])) : ($rec['program_year'] ?: '');
                                        if ($lbl === '') return '';
                                        return '<div class="text-muted" style="font-size:10px;"><i class="bi bi-calendar3 me-1"></i>' . htmlspecialchars((string)$lbl) . '</div>';
                                    }
                                }
                                if ($isAllView):
                                    $pivot = [];
                                    foreach ($records as $rec) {
                                        $key = $rec['member_id'] ? ('m_' . (int)$rec['member_id']) : ('u_' . strtolower(trim($rec['full_name_display'])));
                                        if (!isset($pivot[$key])) {
                                            $pivot[$key] = [
                                                'name'            => $rec['full_name_display'],
                                                'member_id'       => $rec['member_id'],
                                                'member_name'     => $rec['member_name'] ?? '',
                                                'member_ministry' => $rec['member_ministry'] ?? '',
                                                'contact'         => $rec['contact_number'],
                                                'classes'         => ['victory_weekend'=>null,'church_community'=>null,'making_disciples'=>null,'empowering_leaders'=>null,'leadership_113'=>null],
                                                // Year(s) the member has records for, per class — drives the per-class year filter.
                                                'class_years'     => ['victory_weekend'=>[],'church_community'=>[],'making_disciples'=>[],'empowering_leaders'=>[],'leadership_113'=>[]],
                                                'rec_ids'         => [],
                                            ];
                                        }
                                        $pivot[$key]['rec_ids'][] = $rec['id'];
                                        if (isset($pivot[$key]['class_years'][$rec['program_type']]) && !empty($rec['program_year'])) {
                                            $pivot[$key]['class_years'][$rec['program_type']][(int)$rec['program_year']] = true;
                                        }
                                        // array_key_exists (NOT isset) — isset returns false for null values,
                                        // which would skip every record since classes start as null.
                                        if (array_key_exists($rec['program_type'], $pivot[$key]['classes'])) {
                                            $cur = $pivot[$key]['classes'][$rec['program_type']];
                                            $thisStamp = ($rec['event_date'] ?: '') . '|' . str_pad((string)($rec['program_year'] ?: 0), 4, '0', STR_PAD_LEFT);
                                            $curStamp  = $cur ? (($cur['event_date'] ?: '') . '|' . str_pad((string)($cur['program_year'] ?: 0), 4, '0', STR_PAD_LEFT)) : '';
                                            // Keep the most recent record per class.
                                            if (!$cur || $thisStamp > $curStamp) {
                                                $pivot[$key]['classes'][$rec['program_type']] = $rec;
                                            }
                                        }
                                    }
                                    // Sort pivot rows by participant name.
                                    uasort($pivot, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
                                ?>
                                <?php $rowNum = 0; foreach ($pivot as $p):
                                    // For each class, decode extra_data once so the per-class cell can render
                                    // P1/P2 (for MD) and session info (for L113).
                                    $cls = $p['classes'];
                                    $vwRec   = $cls['victory_weekend'];   $vwExtra   = ($vwRec   && $vwRec['extra_data'])   ? json_decode($vwRec['extra_data'],   true) : null;
                                    $ccRec   = $cls['church_community'];
                                    $mdRec   = $cls['making_disciples'];  $mdExtra   = ($mdRec   && $mdRec['extra_data'])   ? json_decode($mdRec['extra_data'],   true) : null;
                                    $elRec   = $cls['empowering_leaders'];
                                    $l113Rec = $cls['leadership_113'];    $l113Extra = ($l113Rec && $l113Rec['extra_data']) ? json_decode($l113Rec['extra_data'], true) : null;
                                    $mdP1  = $mdRec ? (int)($mdRec['md_part1'] ?? ($mdExtra['part1_april12'] ?? 0)) : 0;
                                    $mdP2  = $mdRec ? (int)($mdRec['md_part2'] ?? ($mdExtra['part2_april26'] ?? 0)) : 0;
                                    // Pre-2025 MD was single-part — record's existence implies Part 1 attended.
                                    $mdYearPv = $mdRec ? (int)($mdRec['program_year'] ?? 0) : 0;
                                    if ($mdRec && $mdYearPv > 0 && $mdYearPv < 2025 && !$mdP1) $mdP1 = 1;
                                    $l113Sessions = ($l113Extra && isset($l113Extra['sessions'])) ? $l113Extra['sessions'] : [];
                                    $l113Attended = $l113Extra['attended'] ?? 0;
                                    $l113Total    = $l113Extra['total_sessions'] ?? count($l113Sessions);
                                    $l113Absent   = 0; foreach ($l113Sessions as $_s) if (strtoupper(trim($_s)) === 'A') $l113Absent++;
                                    $l113Pct      = $l113Total > 0 ? round($l113Attended / $l113Total * 100) : 0;
                                    $l113Complete = ($l113Total > 0 && $l113Absent === 0);
                                    $l113Batch    = trim($l113Rec['batch_label'] ?? '');
                                ?>
                                <?php
                                    // Build CSVs of classes / years this member has data for, so the
                                    // existing class/year filter hooks (which read data-pt / data-yr)
                                    // also work in pivot mode.
                                    $ptList = []; $yrList = [];
                                    foreach ($p['classes'] as $clsKey => $rec) {
                                        if (!$rec) continue;
                                        $ptList[$clsKey] = true;
                                        if (!empty($rec['program_year'])) $yrList[(int)$rec['program_year']] = true;
                                    }
                                ?>
                                <tr data-matched="<?php echo $p['member_id'] ? '1' : '0'; ?>"
                                    data-pt="<?php echo htmlspecialchars(implode(',', array_keys($ptList))); ?>"
                                    data-yr="<?php echo htmlspecialchars(implode(',', array_keys($yrList))); ?>"
                                    <?php foreach ($p['class_years'] as $_clsKey => $_yrs): ?>
                                    data-yr-<?php echo $_clsKey; ?>="<?php echo htmlspecialchars(implode(',', array_keys($_yrs))); ?>"
                                    <?php endforeach; ?>
                                    data-water-bap="<?php echo $vwRec ? (int)$vwRec['water_baptism'] : 0; ?>"
                                    data-md-p1="<?php echo $mdP1; ?>" data-md-p2="<?php echo $mdP2; ?>">
                                    <td class="text-muted small at-row-num"><?php echo ++$rowNum; ?></td>
                                    <td class="fw-semibold">
                                        <?php if ($p['member_id']): ?>
                                            <a href="index.php?action=memberProfile&id=<?php echo (int)$p['member_id']; ?>" class="text-primary text-decoration-none"><?php echo htmlspecialchars($p['name']); ?></a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($p['name']); ?>
                                            <span class="badge bg-light text-muted border ms-1" style="font-size:9px;">Unmatched</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- VW: ✓ + event date -->
                                    <td class="text-center small">
                                        <?php if ($vwRec): ?>
                                            <span class="badge bg-primary"><i class="bi bi-check-lg"></i></span>
                                            <?php echo _atDateLine($vwRec); ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <!-- Baptized: water droplet icon if baptized -->
                                    <td class="text-center small">
                                        <?php if ($vwRec && (int)$vwRec['water_baptism']): ?>
                                            <i class="bi bi-droplet-fill text-primary" style="font-size:1.2rem;" title="Underwent water baptism"></i>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <!-- CC -->
                                    <td class="text-center small">
                                        <?php if ($ccRec): ?>
                                            <span class="badge bg-secondary"><i class="bi bi-check-lg"></i></span>
                                            <?php echo _atDateLine($ccRec); ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <!-- MD: per-part date/status, same rules as the MD view (legacy single-part / Absent / N/A) -->
                                    <td class="text-center small">
                                        <?php if ($mdRec):
                                            // Part 1 cell content
                                            if ($mdP1 && !empty($mdRec['event_date'])) {
                                                $p1Disp = '<i class="bi bi-calendar3 text-muted me-1"></i>' . htmlspecialchars(date('M j, Y', strtotime($mdRec['event_date'])));
                                            } elseif ($mdYearPv >= 2025) {
                                                $p1Disp = '<span class="badge bg-light text-muted border" title="Did not attend Part 1."><i class="bi bi-x-circle me-1"></i>Absent</span>';
                                            } else {
                                                $p1Disp = '<span class="text-muted">—</span>';
                                            }
                                            // Part 2 cell content
                                            if ($mdP2 && !empty($mdExtra['part2_date'])) {
                                                $p2Disp = '<i class="bi bi-calendar3 text-muted me-1"></i>' . htmlspecialchars(date('M j, Y', strtotime($mdExtra['part2_date'])));
                                            } elseif ($mdYearPv > 0 && $mdYearPv < 2025) {
                                                $p2Disp = '<span class="badge bg-light text-muted border" title="Single-part year — no Part 2.">N/A</span>';
                                            } elseif ($mdYearPv >= 2025) {
                                                $p2Disp = '<span class="badge bg-light text-muted border" title="Did not attend Part 2."><i class="bi bi-x-circle me-1"></i>Absent</span>';
                                            } else {
                                                $p2Disp = '<span class="text-muted">—</span>';
                                            }
                                        ?>
                                            <div style="font-size:10px; line-height:1.4;">
                                                <div><strong class="text-muted">P1:</strong> <?php echo $p1Disp; ?></div>
                                                <div><strong class="text-muted">P2:</strong> <?php echo $p2Disp; ?></div>
                                            </div>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <!-- EL -->
                                    <td class="text-center small">
                                        <?php if ($elRec): ?>
                                            <span class="badge bg-warning text-dark"><i class="bi bi-check-lg"></i></span>
                                            <?php echo _atDateLine($elRec); ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <!-- L113 -->
                                    <td class="text-center small">
                                        <?php if ($l113Rec): ?>
                                            <?php if ($l113Complete): ?>
                                                <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                            <?php elseif ($l113Total > 0): ?>
                                                <span class="badge bg-warning text-dark" title="<?php echo $l113Absent; ?> absent"><?php echo $l113Attended; ?>/<?php echo $l113Total; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="bi bi-check-lg"></i></span>
                                            <?php endif; ?>
                                            <?php if ($l113Batch): ?><div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars($l113Batch); ?></div><?php endif; ?>
                                            <?php if ($l113Total > 0): ?>
                                            <div class="progress mt-1 mx-auto" style="height:4px; max-width:80px;">
                                                <div class="progress-bar bg-<?php echo $l113Pct >= 100 ? 'success' : ($l113Pct >= 75 ? 'info' : ($l113Pct >= 50 ? 'warning' : 'danger')); ?>" style="width:<?php echo $l113Pct; ?>%"></div>
                                            </div>
                                            <?php endif; ?>
                                            <?php echo _atDateLine($l113Rec); ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <!-- Contact -->
                                    <td class="small"><?php echo $p['contact'] ? htmlspecialchars($p['contact']) : '<span class="text-muted">—</span>'; ?></td>
                                    <!-- Matched Member -->
                                    <td>
                                        <?php if ($p['member_id'] && $p['member_name']): ?>
                                            <a href="index.php?action=memberProfile&id=<?php echo (int)$p['member_id']; ?>" class="text-primary text-decoration-none small">
                                                <i class="bi bi-person-fill me-1 text-success"></i><?php echo htmlspecialchars($p['member_name']); ?>
                                                <?php if ($p['member_ministry']): ?><div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars(strtoupper($p['member_ministry'])); ?></div><?php endif; ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="bi bi-question-circle me-1"></i>Unmatched</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- Progress: X/5 — how many of the 5 classes this participant has any record for. -->
                                    <?php $clsCount = count(array_filter($p['classes'])); ?>
                                    <td class="text-center small">
                                        <span class="badge bg-light text-dark border" style="font-size:11px;">
                                            <?php echo $clsCount; ?>/5
                                        </span>
                                    </td>
                                    <!-- Actions: pivot row points to profile (since one row = many records, can't edit one) -->
                                    <td class="text-center">
                                        <?php if ($p['member_id']): ?>
                                            <a href="index.php?action=memberProfile&id=<?php echo (int)$p['member_id']; ?>" class="btn btn-sm btn-outline-primary" title="View member profile">
                                                <i class="bi bi-person-lines-fill"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>

                                <?php else: // record view (specific class) ?>
                                <?php $rowNum = 0; foreach ($records as $rec):
                                    $pDef      = $PROGRAM_DEFS[$rec['program_type']] ?? ['label' => $rec['program_type'], 'color' => 'secondary', 'icon' => 'bi-circle', 'short' => '?'];
                                    $extraData = $rec['extra_data'] ? json_decode($rec['extra_data'], true) : null;
                                    $isVW      = $rec['program_type'] === 'victory_weekend';
                                    $recCc     = $rec['counselor_contact'] ?? null;
                                    if (!$recCc && $isVW && $extraData && !empty($extraData['counselor_contact'])) $recCc = $extraData['counselor_contact'];
                                ?>
                                <tr data-pt="<?php echo htmlspecialchars($rec['program_type']); ?>" data-yr="<?php echo (int)$rec['program_year']; ?>" data-matched="<?php echo $rec['member_id'] ? '1' : '0'; ?>" data-water-bap="<?php echo (int)$rec['water_baptism']; ?>" data-md-p1="<?php echo (int)($rec['md_part1'] ?? 0); ?>" data-md-p2="<?php echo (int)($rec['md_part2'] ?? 0); ?>">
                                    <td class="text-muted small at-row-num"><?php echo ++$rowNum; ?></td>
                                    <td class="fw-semibold">
                                        <?php echo htmlspecialchars($rec['full_name_display']); ?>
                                    </td>
                                    <?php
                                    // Pre-compute per-row class data
                                    $pt = $rec['program_type'];
                                    $isVwRec = ($pt === 'victory_weekend');
                                    $isCcRec = ($pt === 'church_community');
                                    $isMdRec = ($pt === 'making_disciples');
                                    $isElRec = ($pt === 'empowering_leaders');
                                    $isL113Rec = ($pt === 'leadership_113');
                                    $eventLabel = '';
                                    if (!empty($rec['event_date'])) $eventLabel = date('M j, Y', strtotime($rec['event_date']));
                                    elseif (!empty($rec['program_year'])) $eventLabel = $rec['program_year'];
                                    $eventName = $rec['program_label'] ?: '';
                                    // L113 sessions summary (used inside L113 column below)
                                    $l113Attended = $l113Total = $l113Absent = 0; $l113Batch = $l113Pct = 0; $l113Complete = false; $l113Sessions = [];
                                    if ($isL113Rec && $extraData && isset($extraData['sessions'])) {
                                        $l113Sessions = $extraData['sessions'];
                                        $l113Attended = $extraData['attended'] ?? 0;
                                        $l113Total    = $extraData['total_sessions'] ?? count($l113Sessions);
                                        $l113Batch    = trim($rec['batch_label'] ?? '');
                                        foreach ($l113Sessions as $_s) if (strtoupper(trim($_s)) === 'A') $l113Absent++;
                                        $l113Pct      = $l113Total > 0 ? round($l113Attended / $l113Total * 100) : 0;
                                        $l113Complete = ($l113Total > 0 && $l113Absent === 0);
                                    }
                                    $mdP1 = (int)($rec['md_part1'] ?? ($extraData['part1_april12'] ?? 0));
                                    $mdP2 = (int)($rec['md_part2'] ?? ($extraData['part2_april26'] ?? 0));
                                    // Pre-2025 MD was a single-part class — older records don't have md_part1 set
                                    // because the column didn't exist back then. Having an MD record IS Part 1
                                    // attendance for those years; Part 2 stays empty (no second session existed).
                                    $mdYear = (int)($rec['program_year'] ?? 0);
                                    if ($isMdRec && $mdYear > 0 && $mdYear < 2025 && !$mdP1) {
                                        $mdP1 = 1;
                                    }
                                    ?>

                                    <?php
                                    $eventDateDisplay = !empty($rec['event_date']) ? date('M j, Y', strtotime($rec['event_date'])) : '';
                                    $batchDisplay = trim($rec['batch_label'] ?? '');
                                    // MD Part 2 has its own date in extra_data.part2_date (when present, e.g. 2025 had two parts on different dates)
                                    $mdP2Date = '';
                                    if ($isMDView && $extraData) {
                                        if (!empty($extraData['part2_date']))    $mdP2Date = $extraData['part2_date'];
                                        elseif (!empty($extraData['part2_april26'])) { /* legacy flag, no date */ }
                                    }
                                    // Renderer helpers
                                    $renderDate = function($d) {
                                        if (!$d) return '<span class="text-muted">—</span>';
                                        return '<i class="bi bi-calendar3 text-muted me-1"></i>' . htmlspecialchars($d);
                                    };
                                    $renderBatch = function($b) {
                                        if (!$b) return '<span class="text-muted">—</span>';
                                        return '<i class="bi bi-collection text-muted me-1"></i>' . htmlspecialchars($b);
                                    };
                                    $renderPhone = function($p) {
                                        if (!$p) return '<span class="text-muted">—</span>';
                                        return '<i class="bi bi-telephone text-muted me-1"></i>' . htmlspecialchars($p);
                                    };
                                    ?>

                                    <?php if ($isVWView): /* Victory Weekend record view */ ?>
                                    <!-- Baptized -->
                                    <td class="text-center small">
                                        <?php if ((int)$rec['water_baptism']): ?>
                                            <i class="bi bi-droplet-fill text-primary" style="font-size:1.2rem;" title="Underwent water baptism"></i>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo $renderDate($eventDateDisplay); ?></td>
                                    <?php if ($showBatchCol): ?><td class="small"><?php echo $renderBatch($batchDisplay); ?></td><?php endif; ?>

                                    <?php elseif ($isCCView): /* Church Community record view */ ?>
                                    <td class="small"><?php echo $renderDate($eventDateDisplay); ?></td>
                                    <?php if ($showBatchCol): ?><td class="small"><?php echo $renderBatch($batchDisplay); ?></td><?php endif; ?>

                                    <?php elseif ($isMDView): /* Making Disciples record view */ ?>
                                    <!-- Part 1 Event Date. Attended → date; 2025+ but missed Part 1 → "Absent" badge. -->
                                    <td class="text-center small">
                                        <?php if ($mdP1 && $eventDateDisplay): ?>
                                            <i class="bi bi-calendar3 text-muted me-1"></i><?php echo htmlspecialchars($eventDateDisplay); ?>
                                        <?php elseif ($mdYear >= 2025): ?>
                                            <span class="badge bg-light text-muted border" title="Did not attend Part 1."><i class="bi bi-x-circle me-1"></i>Absent</span>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <!-- Part 2 Event Date. N/A for pre-2025 (single-part years); Absent for 2025+ no-show. -->
                                    <td class="text-center small">
                                        <?php if ($mdP2 && $mdP2Date): ?>
                                            <i class="bi bi-calendar3 text-muted me-1"></i><?php echo htmlspecialchars(date('M j, Y', strtotime($mdP2Date))); ?>
                                        <?php elseif ($mdYear > 0 && $mdYear < 2025): ?>
                                            <span class="badge bg-light text-muted border" title="Making Disciples in <?php echo $mdYear; ?> was a single-part class — no Part 2.">N/A</span>
                                        <?php elseif ($mdYear >= 2025): ?>
                                            <span class="badge bg-light text-muted border" title="Did not attend Part 2."><i class="bi bi-x-circle me-1"></i>Absent</span>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <?php if ($showBatchCol): ?><td class="small"><?php echo $renderBatch($batchDisplay); ?></td><?php endif; ?>

                                    <?php elseif ($isELView): /* Empowering Leaders record view */ ?>
                                    <td class="small"><?php echo $renderDate($eventDateDisplay); ?></td>
                                    <?php if ($showBatchCol): ?><td class="small"><?php echo $renderBatch($batchDisplay); ?></td><?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($isVWView): /* Counselor only relevant for VW */ ?>
                                    <td class="small">
                                        <?php if ($rec['counselor_name']): ?>
                                            <div><i class="bi bi-person-badge text-muted me-1"></i><?php echo htmlspecialchars($rec['counselor_name']); ?></div>
                                            <?php if ($recCc): ?><div class="text-muted" style="font-size:10px;"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($recCc); ?></div><?php endif; ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <!-- Contact -->
                                    <td class="small"><?php echo $renderPhone($rec['contact_number']); ?></td>
                                    <td>
                                        <?php if ($rec['member_id'] && $rec['member_name']): ?>
                                        <a href="index.php?action=memberProfile&id=<?php echo $rec['member_id']; ?>"
                                           class="text-decoration-none small">
                                            <i class="bi bi-person-fill me-1 text-success"></i><?php echo htmlspecialchars($rec['member_name']); ?>
                                            <?php if ($rec['member_ministry']): ?>
                                            <div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars($rec['member_ministry']); ?></div>
                                            <?php endif; ?>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted small"><i class="bi bi-question-circle me-1"></i>Unmatched</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($showNotesCol): ?>
                                    <td class="small" style="max-width:260px;">
                                        <?php
                                        $noteText = trim((string)($rec['notes'] ?? ''));
                                        if ($noteText !== ''):
                                            $noteLimit = 60;
                                            $noteIsLong = mb_strlen($noteText) > $noteLimit;
                                            $noteShort = $noteIsLong ? mb_substr($noteText, 0, $noteLimit) . '…' : $noteText;
                                        ?>
                                            <i class="bi bi-sticky text-primary me-1"></i>
                                            <?php if ($noteIsLong): ?>
                                                <span class="at-note-short"><?php echo htmlspecialchars($noteShort); ?></span>
                                                <span class="at-note-full" style="display:none; white-space:pre-wrap;"><?php echo htmlspecialchars($noteText); ?></span>
                                                <button type="button" class="btn btn-link btn-sm p-0 at-note-toggle" style="font-size:11px;">See more</button>
                                            <?php else: ?>
                                                <?php echo nl2br(htmlspecialchars($noteText)); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="text-center">
                                        <?php $recStatus = $rec['status'] ?? 'active'; ?>
                                        <span class="badge <?php echo $recStatus === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($recStatus); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php
                                            // Pre-2025 MD was a single-part class — record's existence implies Part 1 attended.
                                            $_isMdRow   = ($rec['program_type'] === 'making_disciples');
                                            $_mdYearRow = (int)($rec['program_year'] ?? 0);
                                            $_legacyMd  = $_isMdRow && $_mdYearRow > 0 && $_mdYearRow < 2025;
                                            $_p1Flag    = ((int)($rec['md_part1'] ?? 0)) || $_legacyMd;
                                            $_p2Flag    = (int)($rec['md_part2'] ?? 0);
                                        ?>
                                        <?php
                                            $_recJson = [
                                                'id'               => $rec['id'],
                                                'raw_first_name'   => $rec['raw_first_name'],
                                                'raw_last_name'    => $rec['raw_last_name'],
                                                'program_type'     => $rec['program_type'],
                                                'program_year'     => $rec['program_year'],
                                                'program_label'    => $rec['program_label'],
                                                'event_date'       => $rec['event_date'] ?? '',
                                                'counselor_name'   => $rec['counselor_name'],
                                                'counselor_contact'=> $rec['counselor_contact'] ?? '',
                                                'contact_number'   => $rec['contact_number'],
                                                'water_baptism'    => (int)$rec['water_baptism'],
                                                'member_id'        => $rec['member_id'],
                                                'member_name'      => $rec['member_name'] ?? '',
                                                'member_ministry'  => $rec['member_ministry'] ?? '',
                                                'batch_label'      => $rec['batch_label'] ?? '',
                                                'md_part1'         => $_p1Flag ? 1 : 0,
                                                'md_part2'         => $_p2Flag ? 1 : 0,
                                                'md_part1_date'    => $_p1Flag ? ($rec['event_date'] ?? '') : '',
                                                'md_part2_date'    => ($_isMdRow && $_p2Flag && $extraData && !empty($extraData['part2_date'])) ? $extraData['part2_date'] : '',
                                                'notes'            => $rec['notes'] ?? '',
                                            ];
                                        ?>
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditAtModal(<?php echo htmlspecialchars(json_encode($_recJson)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-info me-1" title="Duplicate (copy this record's class / year / batch / event, then change the name)"
                                            onclick="openDuplicateAtModal(<?php echo htmlspecialchars(json_encode($_recJson)); ?>)">
                                            <i class="bi bi-files"></i>
                                        </button>
                                        <?php if (($rec['status'] ?? 'active') === 'active'): ?>
                                        <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                           href="index.php?action=deactivateAttendance&id=<?php echo $rec['id']; ?><?php echo $activePt ? '&program_type='.$activePt : ''; ?>"
                                           onclick="return confirm('Deactivate this record?')">
                                            <i class="bi bi-pause-circle"></i>
                                        </a>
                                        <?php else: ?>
                                        <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                           href="index.php?action=activateAttendance&id=<?php echo $rec['id']; ?><?php echo $activePt ? '&program_type='.$activePt : ''; ?>">
                                            <i class="bi bi-play-circle"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-danger" title="Delete"
                                           href="index.php?action=deleteAttendance&id=<?php echo $rec['id']; ?><?php echo $activePt ? '&program_type='.$activePt : ''; ?>"
                                           onclick="return confirm('Permanently delete this record? This cannot be undone.');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; // end isAllView / record view branching ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($activeTab === 'stats'): ?>

            <!-- ── Statistics Tab ─────────────────────────────────────────── -->
            <?php if ($isVWView): ?>
            <?php
            /* ── Victory Weekend statistics computation ── */
            $vwTotal    = 0; $vwBaptized = 0; $vwMatched = 0;
            $vwByBatch  = []; // [event_date => [year, total, baptized, matched]]
            $vwByYear   = []; // [year        => [total, baptized, matched]]
            $vwCounselors = [];
            foreach ($records as $r) {
                $bk = $r['event_date'] ?: 'Unknown';
                $yr = (int)($r['program_year'] ?? 0);
                if (!isset($vwByBatch[$bk])) $vwByBatch[$bk] = ['year'=>$yr,'total'=>0,'baptized'=>0,'matched'=>0];
                if (!isset($vwByYear[$yr]))  $vwByYear[$yr]  = ['total'=>0,'baptized'=>0,'matched'=>0];
                $bap  = (int)($r['water_baptism']  ?? 0);
                $mtch = $r['member_id'] ? 1 : 0;
                $vwByBatch[$bk]['total']++;    $vwByYear[$yr]['total']++;
                $vwByBatch[$bk]['baptized']+=$bap; $vwByYear[$yr]['baptized']+=$bap;
                $vwByBatch[$bk]['matched']+=$mtch; $vwByYear[$yr]['matched']+=$mtch;
                $vwTotal++; $vwBaptized+=$bap; $vwMatched+=$mtch;
                if (!empty($r['counselor_name'])) {
                    $vwCounselors[$r['counselor_name']] = ($vwCounselors[$r['counselor_name']] ?? 0) + 1;
                }
            }
            ksort($vwByBatch); ksort($vwByYear);
            arsort($vwCounselors);
            $topCounselors = array_slice($vwCounselors, 0, 10, true);
            /* Build human-readable batch labels (VW spans 2 days) */
            $vwBatchLabels = [];
            foreach (array_keys($vwByBatch) as $bk) {
                if ($bk === 'Unknown') { $vwBatchLabels[$bk] = 'Unknown'; continue; }
                $dt1 = new DateTime($bk);
                $dt2 = (clone $dt1)->modify('+1 day');
                $vwBatchLabels[$bk] = ($dt1->format('M') === $dt2->format('M'))
                    ? $dt1->format('M j').'-'.$dt2->format('j, Y')
                    : $dt1->format('M j').' & '.$dt2->format('M j, Y');
            }
            $vwYears = array_keys($vwByYear);
            ?>

            <!-- VW Summary Cards -->
            <?php $vwSessions = count($vwByBatch); ?>
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-primary">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-primary mb-0"><?php echo $vwTotal; ?></div>
                            <div class="small text-muted mt-1">Total Attendees</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-success mb-0"><?php echo $vwSessions; ?></div>
                            <div class="small text-muted mt-1">Sessions</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-info mb-0"><?php echo $vwBaptized; ?></div>
                            <div class="small text-muted mt-1">Water Baptism</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-secondary mb-0"><?php echo $vwMatched; ?></div>
                            <div class="small text-muted mt-1">Linked to Members</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 1: Attendance per Batch -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart me-2"></i>Attendance per Batch</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('vwBatchChart','vw_attendance_by_batch')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body" style="height:320px;">
                            <canvas id="vwBatchChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 2: Year-over-Year Trend + Top Counselors -->
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-graph-up me-2"></i>Year-over-Year Attendance</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('vwYearChart','vw_year_over_year')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="vwYearChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-people me-2"></i>Top Counselors</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('vwCounselorChart','vw_top_counselors')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="vwCounselorChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Session Summary Table -->
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold"><i class="bi bi-table me-2"></i>Session Summary</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light" onclick="exportVwBatchCsv()" title="Export CSV">
                            <i class="bi bi-filetype-csv me-1"></i>CSV
                        </button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportVwBatchExcel()" title="Export Excel">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
                        </button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportVwBatchPdf()" title="Export PDF">
                            <i class="bi bi-filetype-pdf me-1"></i>PDF
                        </button>
                        <button class="btn btn-sm btn-outline-light" onclick="printVwBatch()" title="Print">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="vwBatchTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Session</th>
                                    <th>Year</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Baptized</th>
                                    <th class="text-center">Linked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vwByBatch as $bk => $bv): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($vwBatchLabels[$bk]); ?></td>
                                    <td><?php echo $bv['year'] ?: '—'; ?></td>
                                    <td class="text-center fw-bold"><?php echo $bv['total']; ?></td>
                                    <td class="text-center"><?php echo $bv['baptized']; ?></td>
                                    <td class="text-center"><?php echo $bv['matched']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>Total</td>
                                    <td></td>
                                    <td class="text-center"><?php echo $vwTotal; ?></td>
                                    <td class="text-center"><?php echo $vwBaptized; ?></td>
                                    <td class="text-center"><?php echo $vwMatched; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php elseif ($isCCView): /* ── Church Community statistics ── */ ?>
            <?php
            $ccTotal = 0; $ccMatched = 0;
            $ccByBatch = []; // [event_date => [year, total, matched]]
            $ccByYear  = []; // [year        => [total, matched]]
            foreach ($records as $r) {
                $bk = $r['event_date'] ?: 'Unknown';
                $yr = (int)($r['program_year'] ?? 0);
                if (!isset($ccByBatch[$bk])) $ccByBatch[$bk] = ['year'=>$yr,'total'=>0,'matched'=>0];
                if (!isset($ccByYear[$yr]))  $ccByYear[$yr]  = ['total'=>0,'matched'=>0];
                $mtch = $r['member_id'] ? 1 : 0;
                $ccByBatch[$bk]['total']++;   $ccByYear[$yr]['total']++;
                $ccByBatch[$bk]['matched']+=$mtch; $ccByYear[$yr]['matched']+=$mtch;
                $ccTotal++; $ccMatched+=$mtch;
            }
            ksort($ccByBatch); ksort($ccByYear);
            $ccBatchLabels = [];
            foreach (array_keys($ccByBatch) as $bk) {
                $ccBatchLabels[$bk] = ($bk === 'Unknown') ? 'Unknown' : (new DateTime($bk))->format('M j, Y');
            }
            $ccYears    = array_keys($ccByYear);
            $ccSessions = count($ccByBatch);
            ?>

            <!-- CC Summary Cards -->
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-4">
                    <div class="card h-100 border-success">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-success mb-0"><?php echo $ccTotal; ?></div>
                            <div class="small text-muted mt-1">Total Attendees</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-primary mb-0"><?php echo $ccSessions; ?></div>
                            <div class="small text-muted mt-1">Sessions</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-secondary mb-0"><?php echo $ccMatched; ?></div>
                            <div class="small text-muted mt-1">Linked to Members</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 1: Attendance per Session + Year-over-Year -->
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart me-2"></i>Attendance per Session</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('ccSessionChart','cc_attendance_by_session')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="ccSessionChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-graph-up me-2"></i>Year-over-Year Attendance</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('ccYearChart','cc_year_over_year')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="ccYearChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CC Session Summary Table -->
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold"><i class="bi bi-table me-2"></i>Session Summary</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light" onclick="exportCcSessionCsv()" title="CSV">
                            <i class="bi bi-filetype-csv me-1"></i>CSV
                        </button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportCcSessionExcel()" title="Excel">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
                        </button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportCcSessionPdf()" title="PDF">
                            <i class="bi bi-filetype-pdf me-1"></i>PDF
                        </button>
                        <button class="btn btn-sm btn-outline-light" onclick="printCcSession()" title="Print">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="ccSessionTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Session Date</th>
                                    <th>Year</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Linked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ccByBatch as $bk => $bv): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($ccBatchLabels[$bk]); ?></td>
                                    <td><?php echo $bv['year'] ?: '—'; ?></td>
                                    <td class="text-center fw-bold"><?php echo $bv['total']; ?></td>
                                    <td class="text-center"><?php echo $bv['matched']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>Total</td>
                                    <td></td>
                                    <td class="text-center"><?php echo $ccTotal; ?></td>
                                    <td class="text-center"><?php echo $ccMatched; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php elseif ($isMDView): /* ── Making Disciples statistics ── */ ?>
            <?php
            $mdTotal  = 0; $mdMatched = 0; $mdPart1 = 0; $mdPart2 = 0; $mdBoth = 0;
            $mdByBatch = []; // [event_date => [year, total, part1, part2, both, matched]]
            $mdByYear  = []; // [year        => [total, part1, part2, both, matched]]
            foreach ($records as $r) {
                $bk   = $r['event_date'] ?: 'Unknown';
                $yr   = (int)($r['program_year'] ?? 0);
                $p1   = (int)($r['md_part1']  ?? 0);
                $p2   = (int)($r['md_part2']  ?? 0);
                $both = ($p1 && $p2) ? 1 : 0;
                $mtch = $r['member_id'] ? 1 : 0;
                if (!isset($mdByBatch[$bk])) $mdByBatch[$bk] = ['year'=>$yr,'total'=>0,'part1'=>0,'part2'=>0,'both'=>0,'matched'=>0];
                if (!isset($mdByYear[$yr]))  $mdByYear[$yr]  = ['total'=>0,'part1'=>0,'part2'=>0,'both'=>0,'matched'=>0];
                foreach (['total'=>1,'part1'=>$p1,'part2'=>$p2,'both'=>$both,'matched'=>$mtch] as $_k => $_v) {
                    $mdByBatch[$bk][$_k] += (int)$_v;
                    $mdByYear[$yr][$_k]  += (int)$_v;
                }
                $mdTotal++; $mdMatched+=$mtch; $mdPart1+=$p1; $mdPart2+=$p2; $mdBoth+=$both;
            }
            ksort($mdByBatch); ksort($mdByYear);
            $mdBatchLabels = [];
            foreach (array_keys($mdByBatch) as $bk) {
                $mdBatchLabels[$bk] = ($bk === 'Unknown') ? 'Unknown' : (new DateTime($bk))->format('M j, Y');
            }
            $mdYears    = array_keys($mdByYear);
            $mdSessions = count($mdByBatch);
            $hasMdParts = ($mdPart1 + $mdPart2) > 0; // any part1/part2 data (2025 style)
            ?>

            <!-- MD Summary Cards -->
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100 border-info">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-info mb-0"><?php echo $mdTotal; ?></div>
                            <div class="small text-muted mt-1">Total Attendees</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-primary mb-0"><?php echo $mdSessions; ?></div>
                            <div class="small text-muted mt-1">Sessions</div>
                        </div>
                    </div>
                </div>
                <?php if ($hasMdParts): ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-info mb-0"><?php echo $mdPart1; ?></div>
                            <div class="small text-muted mt-1">Attended Part 1</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-info mb-0"><?php echo $mdPart2; ?></div>
                            <div class="small text-muted mt-1">Attended Part 2</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-success mb-0"><?php echo $mdBoth; ?></div>
                            <div class="small text-muted mt-1">Completed Both</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-secondary mb-0"><?php echo $mdMatched; ?></div>
                            <div class="small text-muted mt-1">Linked to Members</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 1: Attendance per Session + Year-over-Year -->
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart me-2"></i>Attendance per Session</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('mdSessionChart','md_attendance_by_session')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="mdSessionChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-graph-up me-2"></i>Year-over-Year Attendance</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('mdYearChart','md_year_over_year')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="mdYearChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MD Session Summary Table -->
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold"><i class="bi bi-table me-2"></i>Session Summary</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light" onclick="exportMdSessionCsv()" title="CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportMdSessionExcel()" title="Excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportMdSessionPdf()" title="PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</button>
                        <button class="btn btn-sm btn-outline-light" onclick="printMdSession()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="mdSessionTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Session Date</th>
                                    <th>Year</th>
                                    <th class="text-center">Total</th>
                                    <?php if ($hasMdParts): ?>
                                    <th class="text-center text-info">Part 1</th>
                                    <th class="text-center text-info">Part 2</th>
                                    <th class="text-center text-success">Both</th>
                                    <?php endif; ?>
                                    <th class="text-center text-secondary">Linked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mdByBatch as $bk => $bv): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($mdBatchLabels[$bk]); ?></td>
                                    <td><?php echo $bv['year'] ?: '—'; ?></td>
                                    <td class="text-center fw-bold"><?php echo $bv['total']; ?></td>
                                    <?php if ($hasMdParts): ?>
                                    <td class="text-center"><?php echo $bv['part1']; ?></td>
                                    <td class="text-center"><?php echo $bv['part2']; ?></td>
                                    <td class="text-center"><?php echo $bv['both']; ?></td>
                                    <?php endif; ?>
                                    <td class="text-center"><?php echo $bv['matched']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>Total</td>
                                    <td></td>
                                    <td class="text-center"><?php echo $mdTotal; ?></td>
                                    <?php if ($hasMdParts): ?>
                                    <td class="text-center"><?php echo $mdPart1; ?></td>
                                    <td class="text-center"><?php echo $mdPart2; ?></td>
                                    <td class="text-center"><?php echo $mdBoth; ?></td>
                                    <?php endif; ?>
                                    <td class="text-center"><?php echo $mdMatched; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php elseif ($isELView): /* ── Empowering Leaders statistics ── */ ?>
            <?php
            $elTotal  = 0; $elMatched = 0;
            $elByBatch = []; // [event_date => [year, total, matched]]
            $elByYear  = []; // [year        => [total, matched]]
            foreach ($records as $r) {
                $bk   = $r['event_date'] ?: 'Unknown';
                $yr   = (int)($r['program_year'] ?? 0);
                $mtch = $r['member_id'] ? 1 : 0;
                if (!isset($elByBatch[$bk])) $elByBatch[$bk] = ['year'=>$yr,'total'=>0,'matched'=>0];
                if (!isset($elByYear[$yr]))  $elByYear[$yr]  = ['total'=>0,'matched'=>0];
                $elByBatch[$bk]['total']++;   $elByBatch[$bk]['matched'] += $mtch;
                $elByYear[$yr]['total']++;    $elByYear[$yr]['matched']  += $mtch;
                $elTotal++; $elMatched += $mtch;
            }
            ksort($elByBatch); ksort($elByYear);
            $elBatchLabels = [];
            foreach (array_keys($elByBatch) as $bk) {
                $elBatchLabels[$bk] = ($bk === 'Unknown') ? 'Unknown' : (new DateTime($bk))->format('M j, Y');
            }
            $elYears    = array_keys($elByYear);
            $elSessions = count($elByBatch);
            ?>

            <!-- EL Summary Cards -->
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-4">
                    <div class="card h-100 border-warning">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-warning mb-0"><?php echo $elTotal; ?></div>
                            <div class="small text-muted mt-1">Total Attendees</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-primary mb-0"><?php echo $elSessions; ?></div>
                            <div class="small text-muted mt-1">Sessions</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-secondary mb-0"><?php echo $elMatched; ?></div>
                            <div class="small text-muted mt-1">Linked to Members</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 1: Attendance per Session + Year-over-Year -->
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart me-2"></i>Attendance per Session</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('elSessionChart','el_attendance_by_session')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="elSessionChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-graph-up me-2"></i>Year-over-Year Attendance</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('elYearChart','el_year_over_year')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="elYearChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- EL Session Summary Table -->
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold"><i class="bi bi-table me-2"></i>Session Summary</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light" onclick="exportElSessionCsv()" title="CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportElSessionExcel()" title="Excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportElSessionPdf()" title="PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</button>
                        <button class="btn btn-sm btn-outline-light" onclick="printElSession()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="elSessionTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Session Date</th>
                                    <th>Year</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center">Linked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($elByBatch as $bk => $bv): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($elBatchLabels[$bk]); ?></td>
                                    <td><?php echo $bv['year'] ?: '—'; ?></td>
                                    <td class="text-center fw-bold"><?php echo $bv['total']; ?></td>
                                    <td class="text-center"><?php echo $bv['matched']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>Total</td>
                                    <td></td>
                                    <td class="text-center"><?php echo $elTotal; ?></td>
                                    <td class="text-center"><?php echo $elMatched; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php elseif ($isL113View): /* ── Leadership 1-1-3 statistics ── */ ?>
            <?php
            $l113Total = 0; $l113Complete = 0; $l113Matched = 0;
            $l113ByBatch = []; // [batch_key => [batch, year, total, completed, notCompleted]]
            $noClassSt   = ['NO CLASS','HOLY WEEK','DC 2023','NC'];
            foreach ($records as $r) {
                $ed  = $r['extra_data'] ? json_decode($r['extra_data'], true) : null;
                $bk  = trim($r['batch_label'] ?? '') ?: ($r['program_label'] ?? 'Unknown');
                $yr  = (int)($r['program_year'] ?? 0);
                $key = $bk . '|' . $yr;
                if (!isset($l113ByBatch[$key])) $l113ByBatch[$key] = ['batch'=>$bk,'year'=>$yr,'total'=>0,'completed'=>0,'notCompleted'=>0];
                $l113ByBatch[$key]['total']++;
                $l113Total++;
                if ($r['member_id']) $l113Matched++;
                if ($ed && isset($ed['sessions'])) {
                    $totalActual = (int)($ed['total_sessions'] ?? 0);
                    if ($totalActual === 0) { foreach ($ed['sessions'] as $_s) { if (!in_array(strtoupper(trim($_s)), $noClassSt)) $totalActual++; } }
                    $ab = 0; foreach ($ed['sessions'] as $_s) { if (strtoupper(trim($_s)) === 'A') $ab++; }
                    if ($totalActual > 0 && $ab === 0) { $l113ByBatch[$key]['completed']++; $l113Complete++; }
                    elseif ($totalActual > 0) $l113ByBatch[$key]['notCompleted']++;
                }
            }
            $l113NotComplete = $l113Total - $l113Complete;
            /* chart data */
            $_l113BLabels = []; $_l113BCompleted = []; $_l113BNotCompleted = [];
            foreach ($l113ByBatch as $bv) {
                $_l113BLabels[]       = $bv['batch'] . ' (' . $bv['year'] . ')';
                $_l113BCompleted[]    = (int)$bv['completed'];
                $_l113BNotCompleted[] = (int)$bv['notCompleted'];
            }
            ?>

            <!-- L113 Summary Cards -->
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-danger">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-danger mb-0"><?php echo $l113Total; ?></div>
                            <div class="small text-muted mt-1">Total Participants</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-success mb-0"><?php echo $l113Complete; ?></div>
                            <div class="small text-muted mt-1">Completed (0 absences)</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-warning mb-0"><?php echo $l113NotComplete; ?></div>
                            <div class="small text-muted mt-1">Not Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-secondary mb-0"><?php echo $l113Matched; ?></div>
                            <div class="small text-muted mt-1">Linked to Members</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 1: Completion per Batch + Participants per Batch -->
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart-fill me-2"></i>Completion per Batch</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('arL113CompletionChart','l113_completion_per_batch')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="arL113CompletionChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-people-fill me-2"></i>Participants per Batch</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('arL113ParticipantsChart','l113_participants_per_batch')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="arL113ParticipantsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- L113 Batch Summary Table -->
            <?php if (!empty($l113ByBatch)):
                $l113TotalPct = $l113Total > 0 ? round($l113Complete / $l113Total * 100) : 0;
            ?>
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold"><i class="bi bi-table me-2"></i>Batch Summary</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light" onclick="exportArL113BatchCsv()" title="CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportArL113BatchExcel()" title="Excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportArL113BatchPdf()" title="PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</button>
                        <button class="btn btn-sm btn-outline-light" onclick="printArL113Batch()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="arL113BatchTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Batch</th>
                                    <th>Year</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center text-success">Completed</th>
                                    <th class="text-center text-warning">Not Completed</th>
                                    <th class="text-center">Completion %</th>
                                    <th>Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($l113ByBatch as $bv):
                                    $bvPct = $bv['total'] > 0 ? round($bv['completed'] / $bv['total'] * 100) : 0;
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($bv['batch']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $bv['year']; ?></span></td>
                                    <td class="text-center fw-bold"><?php echo $bv['total']; ?></td>
                                    <td class="text-center text-success fw-semibold"><?php echo $bv['completed']; ?></td>
                                    <td class="text-center text-warning fw-semibold"><?php echo $bv['notCompleted']; ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $bvPct >= 80 ? 'success' : ($bvPct >= 50 ? 'warning' : 'danger'); ?>"><?php echo $bvPct; ?>%</span>
                                    </td>
                                    <td style="min-width:120px">
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar bg-<?php echo $bvPct >= 80 ? 'success' : ($bvPct >= 50 ? 'warning' : 'danger'); ?>"
                                                 style="width:<?php echo $bvPct; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>Total</td><td></td>
                                    <td class="text-center"><?php echo $l113Total; ?></td>
                                    <td class="text-center text-success"><?php echo $l113Complete; ?></td>
                                    <td class="text-center text-warning"><?php echo $l113NotComplete; ?></td>
                                    <td class="text-center"><?php echo $l113TotalPct; ?>%</td>
                                    <td>
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar bg-<?php echo $l113TotalPct >= 80 ? 'success' : ($l113TotalPct >= 50 ? 'warning' : 'danger'); ?>"
                                                 style="width:<?php echo $l113TotalPct; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php else: /* ── All-classes statistics ── */ ?>
            <?php
            $allYears = [];
            foreach ($paStats as $pt => $yrs) foreach ($yrs as $yr => $c) $allYears[$yr] = true;
            ksort($allYears);
            $allYears = array_keys($allYears);

            // Monthly breakdown: [year][month 1-12] = total across all programs
            $monthlyByYear = [];
            foreach ($records as $_r) {
                if (empty($_r['event_date'])) continue;
                $_yr = (int)$_r['program_year'];
                $_mo = (int)substr($_r['event_date'], 5, 2);
                if ($_mo < 1 || $_mo > 12) continue;
                $monthlyByYear[$_yr][$_mo] = ($monthlyByYear[$_yr][$_mo] ?? 0) + 1;
            }
            ksort($monthlyByYear);

            // Discipleship pipeline totals
            $pipelineTotals = [];
            foreach ($PROGRAM_DEFS as $_pt => $_pd) {
                $pipelineTotals[$_pt] = array_sum($paStats[$_pt] ?? []);
            }
            $pipelineMax = max(array_values($pipelineTotals) ?: [1]);
            $pipelineVW  = $pipelineTotals['victory_weekend'] ?? 1;
            ?>

            <!-- Match Stats Cards -->
            <div class="row mb-4 g-3">
                <?php foreach ($PROGRAM_DEFS as $pType => $pDef):
                    $ms = $matchStats[$pType] ?? ['total'=>0,'matched'=>0,'unmatched'=>0];
                    $pct = $ms['total'] > 0 ? round($ms['matched'] / $ms['total'] * 100) : 0;
                ?>
                <div class="col-md-4 col-xl">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-<?php echo $pDef['color']; ?> me-2">
                                    <i class="bi <?php echo $pDef['icon']; ?>"></i>
                                </span>
                                <span class="fw-semibold small"><?php echo $pDef['label']; ?></span>
                            </div>
                            <div class="h4 fw-bold mb-0"><?php echo $ms['total']; ?></div>
                            <div class="small text-muted mb-2">total records</div>
                            <div class="progress mb-1" style="height:6px">
                                <div class="progress-bar bg-<?php echo $pDef['color']; ?>" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between" style="font-size:11px">
                                <span class="text-success"><i class="bi bi-person-check-fill me-1"></i><?php echo $ms['matched']; ?> matched</span>
                                <span class="text-muted"><?php echo $ms['unmatched']; ?> unmatched</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php
            // Tally how many of the 5 classes (VW/CC/MD/EL/L113) each unique member has records for.
            // Bucket members by that count so the chart shows depth of discipleship engagement.
            $classKeysAll = array_keys($PROGRAM_DEFS);
            $memberClassSet = [];   // key => [classes_attended]
            foreach ($records as $_r) {
                $k = $_r['member_id'] ? ('m_' . (int)$_r['member_id']) : ('u_' . strtolower(trim($_r['full_name_display'])));
                $memberClassSet[$k][$_r['program_type']] = true;
            }
            $completionBuckets = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
            foreach ($memberClassSet as $_set) {
                $n = count($_set);
                if (isset($completionBuckets[$n])) $completionBuckets[$n]++;
            }
            $completionTotalMembers = array_sum($completionBuckets);
            ?>

            <!-- Top row: Class Distribution (small) + Member Completion Distribution (wider) -->
            <div class="row g-3 mb-4">
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-pie-chart me-2"></i>Class Distribution</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('atProgramChart','attendance_by_program')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body d-flex align-items-center justify-content-center" style="max-height:260px;">
                            <canvas id="atProgramChart" style="max-height:220px;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart-steps me-2"></i>Member Completion Distribution
                                <span class="text-white-50 fw-normal small">(out of <?php echo $completionTotalMembers; ?> unique members)</span>
                            </span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('atCompletionDistChart','member_completion_distribution')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body" style="max-height:260px;">
                            <canvas id="atCompletionDistChart" style="max-height:220px;"></canvas>
                        </div>
                        <div class="card-footer text-muted small">
                            <i class="bi bi-info-circle me-1"></i>
                            Each bar counts unique members who have records for that many classes (VW, CC, MD, EL, L113). Higher numbers on the right = deeper discipleship engagement.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance by Class per Year — full-width for easy year-over-year comparison -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart me-2"></i>Attendance by Class per Year</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('atClassYearChart','attendance_by_class_per_year')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body" style="height:320px;">
                            <canvas id="atClassYearChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                window.AT_COMPLETION_BUCKETS = <?php echo json_encode($completionBuckets); ?>;
            </script>

            <!-- Discipleship Pipeline -->
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="card" id="discipleshipPipelineCard">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-arrow-right-circle me-2"></i>Discipleship Pipeline</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportDiscipleshipPipelinePng()" title="Download PNG"><i class="bi bi-download me-1"></i>PNG</button>
                        </div>
                        <div class="card-body pb-2">
                            <p class="text-muted small mb-3">Total attendees per class — bars scaled to the largest class. Percentages are relative to Victory Weekend (entry point).</p>
                            <div class="row g-3">
                            <?php foreach ($PROGRAM_DEFS as $_pt => $_pd):
                                $_cnt    = $pipelineTotals[$_pt];
                                $_barPct = $pipelineMax > 0 ? round($_cnt / $pipelineMax * 100) : 0;
                                $_vwPct  = $pipelineVW  > 0 ? round($_cnt / $pipelineVW  * 100) : ($cnt > 0 ? 100 : 0);
                            ?>
                            <div class="col-12">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="d-flex align-items-center gap-2">
                                        <span class="badge bg-<?php echo $_pd['color']; ?>" style="width:28px;text-align:center">
                                            <i class="bi <?php echo $_pd['icon']; ?>"></i>
                                        </span>
                                        <span class="fw-semibold small"><?php echo $_pd['label']; ?></span>
                                    </span>
                                    <span class="d-flex align-items-center gap-2">
                                        <?php if ($_pt !== 'victory_weekend'): ?>
                                        <span class="text-muted small"><?php echo $_vwPct; ?>% of VW</span>
                                        <?php else: ?>
                                        <span class="text-muted small">entry point</span>
                                        <?php endif; ?>
                                        <span class="badge bg-<?php echo $_pd['color']; ?>"><?php echo number_format($_cnt); ?></span>
                                    </span>
                                </div>
                                <div class="progress" style="height:16px;border-radius:6px;">
                                    <div class="progress-bar bg-<?php echo $_pd['color']; ?>"
                                         style="width:<?php echo $_barPct; ?>%;border-radius:6px;transition:width .6s ease;"
                                         title="<?php echo number_format($_cnt); ?> attendees">
                                        <?php if ($_barPct > 12): ?>
                                        <span class="small fw-semibold px-1"><?php echo number_format($_cnt); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-table me-2"></i>Yearly Summary Table</span>
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-light" onclick="exportYearlySummaryCsv()"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                                <button class="btn btn-sm btn-outline-light" onclick="exportYearlySummaryExcel()"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                                <button class="btn btn-sm btn-outline-light" onclick="exportYearlySummaryPdf()"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                                <button class="btn btn-sm btn-outline-light" onclick="printYearlySummary()"><i class="bi bi-printer me-1"></i>Print</button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0" id="atYearlySummaryTable">
                                    <thead>
                                        <tr>
                                            <th>Year</th>
                                            <?php foreach ($PROGRAM_DEFS as $pt => $pd): ?>
                                            <th class="text-<?php echo $pd['color']; ?>"><?php echo $pd['short']; ?></th>
                                            <?php endforeach; ?>
                                            <th class="fw-bold">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                            // Column totals across all years — built as we render the per-year rows
                                            // and emitted as a final "Total" row so it shows up in the exports.
                                            $colTotals  = array_fill_keys(array_keys($PROGRAM_DEFS), 0);
                                            $grandTotal = 0;
                                        ?>
                                        <?php foreach ($allYears as $yr):
                                            $rowTotal = 0;
                                        ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?php echo $yr; ?></span></td>
                                            <?php foreach ($PROGRAM_DEFS as $pt => $pd):
                                                $cnt = $paStats[$pt][$yr] ?? 0;
                                                $rowTotal           += $cnt;
                                                $colTotals[$pt]     += $cnt;
                                            ?>
                                            <td><?php echo $cnt ?: '<span class="text-muted">—</span>'; ?></td>
                                            <?php endforeach; ?>
                                            <td class="fw-bold"><?php echo $rowTotal; ?></td>
                                        </tr>
                                        <?php $grandTotal += $rowTotal; endforeach; ?>
                                        <tr class="table-secondary fw-bold">
                                            <td>TOTAL</td>
                                            <?php foreach ($PROGRAM_DEFS as $pt => $pd): ?>
                                            <td class="text-<?php echo $pd['color']; ?>"><?php echo $colTotals[$pt] ?: 0; ?></td>
                                            <?php endforeach; ?>
                                            <td><?php echo $grandTotal; ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; /* end VW / all-classes stats */ ?>

            <?php endif; /* end tabs */ ?>

            <!-- Add Attendance Modal -->
            <div class="modal fade" id="addAttendanceModal" tabindex="-1">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header bg-<?php echo $activePtDef ? $activePtDef['color'] : 'primary'; ?> text-white" id="addAtModalHeader">
                            <h5 class="modal-title">
                                <i class="bi bi-plus-circle me-2"></i>Add <?php echo $activePtDef ? htmlspecialchars($activePtDef['label']).' ' : ''; ?>Record
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="index.php?action=addAttendance" id="addAtForm">
                        <div class="modal-body">
                            <?php include 'shared/attendance_form_fields.php'; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Record</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Edit Attendance Modal -->
            <div class="modal fade" id="editAttendanceModal" tabindex="-1">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header bg-<?php echo $activePtDef ? $activePtDef['color'] : 'primary'; ?> text-white" id="editAtModalHeader">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Attendance Record</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" id="editAtForm" action="">
                        <div class="modal-body">
                            <?php include 'shared/attendance_form_fields.php'; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

<script>
// ── Batch labels & counselors from PHP ───────────────────────────────────
// AT_BATCH_LABELS is keyed by program_type, each value is an array of distinct batch_label strings.
var AT_BATCH_LABELS = <?php echo json_encode($allBatchLabels ?? []); ?>;
var AT_COUNSELORS   = <?php echo json_encode($allCounselors); ?>;

// ── Show/hide extra fields based on program_type ─────────────────────────
function updateAtExtraFields(form) {
    var pt = $(form).find('.at-program').val();
    $(form).find('.at-extra-vw').toggle(pt === 'victory_weekend');
    $(form).find('.at-extra-md').toggle(pt === 'making_disciples');
    $(form).find('.at-extra-l113').toggle(pt === 'leadership_113');
    // Hide water baptism & counselor fields for Church Community, Making Disciples, and Empowering Leaders
    var isCC = (pt === 'church_community');
    var isMD = (pt === 'making_disciples');
    var isEL = (pt === 'empowering_leaders');
    $(form).find('.at-field-wbap').toggle(!isCC && !isMD && !isEL);
    $(form).find('.at-field-counselor').toggle(!isCC && !isMD && !isEL);
    $(form).find('.at-field-couns-contact').toggle(!isCC && !isMD && !isEL);
    // Update modal header color + title based on selected class
    var progDefs  = <?php echo json_encode(array_map(fn($p) => $p['label'], $PROGRAM_DEFS)); ?>;
    var progColors = <?php echo json_encode(array_map(fn($p) => $p['color'], $PROGRAM_DEFS)); ?>;
    var ptLabel = progDefs[pt] || '';
    var ptColor = progColors[pt] || 'primary';
    var $modal = $(form).closest('.modal');
    var $header = $modal.find('.modal-header');
    $header.removeClass('bg-primary bg-success bg-info bg-warning bg-danger bg-secondary').addClass('bg-' + ptColor);
    if ($modal.attr('id') === 'addAttendanceModal') {
        $modal.find('.modal-title').html('<i class="bi bi-plus-circle me-2"></i>Add ' + (ptLabel ? ptLabel + ' ' : '') + 'Record');
    } else if ($modal.attr('id') === 'editAttendanceModal') {
        $modal.find('.modal-title').html('<i class="bi bi-pencil me-2"></i>Edit ' + (ptLabel ? ptLabel + ' ' : '') + 'Record');
    }
}

// Repopulate the Batch Label Select2 with the distinct batch_label values for the chosen class.
function refreshAtBatchOptions(form, programType) {
    var $sel = $(form).find('.at-batch-select2');
    if (!$sel.length) return;
    var currentVal = $sel.val();
    $sel.empty().append('<option value=""></option>');
    var labels = AT_BATCH_LABELS[programType] || [];
    labels.forEach(function(label) {
        $sel.append(new Option(label, label, false, label === currentVal));
    });
    if (currentVal && !$sel.find('option[value="' + currentVal + '"]').length) {
        $sel.append(new Option(currentVal, currentVal, false, true));
    }
    if ($sel.hasClass('select2-hidden-accessible')) $sel.trigger('change.select2');
}

// ── Edit modal ──────────────────────────────────────────────────────────────
function openEditAtModal(rec) {
    var form = document.getElementById('editAtForm');
    form.action = 'index.php?action=updateAttendance&id=' + rec.id;

    var setVal = function(sel, val) {
        var el = form.querySelector(sel);
        if (el) el.value = val || '';
    };
    setVal('.at-first',      rec.raw_first_name);
    setVal('.at-last',       rec.raw_last_name);
    setVal('.at-event-date', rec.event_date || '');

    // Year: ensure option exists (Select2 tags), then set
    var $yearSel = $(form).find('.at-year');
    if ($yearSel.length && rec.program_year) {
        if (!$yearSel.find('option[value="' + rec.program_year + '"]').length) {
            $yearSel.append(new Option(rec.program_year, rec.program_year));
        }
        $yearSel.val(rec.program_year);
    }
    setVal('.at-contact',           rec.contact_number);
    setVal('.at-counselor-contact', rec.counselor_contact);
    setVal('.at-wbap',              rec.water_baptism ? '1' : '0');

    // Program type (plain select — not Select2)
    var progEl = form.querySelector('.at-program');
    if (progEl) progEl.value = rec.program_type || '';

    // MD part fields (+ per-part dates: Part 1 → main event_date; Part 2 → extra_data.part2_date)
    setVal('.at-md-p1',  rec.md_part1  ? '1' : '0');
    setVal('.at-md-p2',  rec.md_part2  ? '1' : '0');
    setVal('.at-md-p1-date', rec.md_part1_date || '');
    setVal('.at-md-p2-date', rec.md_part2_date || '');
    setVal('.at-notes', rec.notes || '');

    // Batch Label Select2 — reload options for this program type, then set value
    var $batchSel = $(form).find('.at-batch-select2');
    refreshAtBatchOptions(form, rec.program_type);
    var batchVal = rec.batch_label || '';
    if (batchVal) {
        if (!$batchSel.find('option[value="' + batchVal + '"]').length) {
            $batchSel.append(new Option(batchVal, batchVal));
        }
        $batchSel.val(batchVal);
    } else {
        $batchSel.val('');
    }
    if ($batchSel.hasClass('select2-hidden-accessible')) $batchSel.trigger('change.select2');

    // Counselor Select2
    var $couns = $(form).find('.at-counselor-select2');
    if (rec.counselor_name) {
        if (!$couns.find('option[value="' + rec.counselor_name + '"]').length) {
            $couns.append(new Option(rec.counselor_name, rec.counselor_name));
        }
        $couns.val(rec.counselor_name);
    } else {
        $couns.val('');
    }
    if ($couns.hasClass('select2-hidden-accessible')) $couns.trigger('change.select2');

    // Linked member Select2
    var $memberSel = $(form).find('.at-member-select2');
    if (rec.member_id && rec.member_name) {
        var memberLabel = rec.member_name + (rec.member_ministry ? ' — ' + String(rec.member_ministry).toUpperCase() : '');
        if (!$memberSel.find('option[value="' + rec.member_id + '"]').length) {
            $memberSel.append(new Option(memberLabel, rec.member_id, true, true));
        } else {
            $memberSel.val(rec.member_id);
        }
    } else {
        // Existing record with no linked member — show the "Unmatched" sentinel
        if (!$memberSel.find('option[value="__unmatched__"]').length) {
            $memberSel.append(new Option('Unmatched (not yet a member)', '__unmatched__', false, false));
        }
        $memberSel.val('__unmatched__');
    }
    if ($memberSel.hasClass('select2-hidden-accessible')) $memberSel.trigger('change.select2');

    // Trigger change.select2 on all plain selects now wrapped with Select2
    ['.at-program', '.at-year', '.at-wbap', '.at-md-p1', '.at-md-p2'].forEach(function(sel) {
        var $el = $(form).find(sel);
        if ($el.hasClass('select2-hidden-accessible')) $el.trigger('change.select2');
    });

    updateAtExtraFields(form);
    var modalEl = document.getElementById('editAttendanceModal');
    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modal.show();
}

// Open the Add modal pre-filled with another record's class / year / event / batch / counselor /
// VW flags — but with the name + linked-member fields cleared so the next person can be added
// quickly with the same context. Saves retyping the common fields when batching entries.
function openDuplicateAtModal(rec) {
    var form = document.getElementById('addAtForm');
    if (!form) return;

    // Helper that writes to the NATIVE form control (Select2's underlying <select>).
    var setVal = function(sel, val) {
        var el = form.querySelector(sel);
        if (el) el.value = val || '';
    };

    // ── Identity is intentionally cleared ──
    setVal('.at-first',   '');
    setVal('.at-last',    '');
    setVal('.at-contact', '');
    setVal('.at-notes',   '');

    // ── Class context (the fields we DO want to carry over) ──
    setVal('.at-program',           rec.program_type || '');
    setVal('.at-event-date',        rec.event_date   || '');
    setVal('.at-wbap',              rec.water_baptism ? '1' : '0');
    setVal('.at-counselor-contact', rec.counselor_contact || '');
    setVal('.at-md-p1',             rec.md_part1 ? '1' : '0');
    setVal('.at-md-p2',             rec.md_part2 ? '1' : '0');
    setVal('.at-md-p1-date',        rec.md_part1_date || '');
    setVal('.at-md-p2-date',        rec.md_part2_date || '');

    // Year — Select2 with `tags:true`, so we may need to inject the option first.
    var $year = $(form).find('.at-year');
    if ($year.length && rec.program_year) {
        if (!$year.find('option[value="' + rec.program_year + '"]').length) {
            $year.append(new Option(rec.program_year, rec.program_year));
        }
        $year.val(rec.program_year);
    }

    // Batch Label — repopulate options for the picked class, then select the source row's value.
    var $batch = $(form).find('.at-batch-select2');
    if (typeof refreshAtBatchOptions === 'function') refreshAtBatchOptions(form, rec.program_type);
    if (rec.batch_label) {
        if (!$batch.find('option[value="' + rec.batch_label + '"]').length) {
            $batch.append(new Option(rec.batch_label, rec.batch_label));
        }
        $batch.val(rec.batch_label);
    } else {
        $batch.val(null);
    }

    // Counselor (Select2 tags).
    var $couns = $(form).find('.at-counselor-select2');
    if (rec.counselor_name) {
        if (!$couns.find('option[value="' + rec.counselor_name + '"]').length) {
            $couns.append(new Option(rec.counselor_name, rec.counselor_name));
        }
        $couns.val(rec.counselor_name);
    } else {
        $couns.val(null);
    }

    // Linked member — cleared (this is a NEW person). Drop any leftover ad-hoc option from
    // a previous Add (the AJAX-loaded list re-populates on the next search anyway).
    var $memDup = $(form).find('.at-member-select2');
    $memDup.find('option').not('[value=""]').not('[value="__unmatched__"]').remove();
    $memDup.val(null);
    // Hide any stale "Auto-linked" notice from a previous use.
    $(form).find('.at-auto-link-notice').hide();

    // ── CRITICAL: refresh every Select2 dropdown so its visible chip matches the underlying
    // <select> value. Without this, the Class / Year / Water Baptism / batch / counselor would
    // all look empty even though the data was set.
    ['.at-program', '.at-year', '.at-wbap', '.at-md-p1', '.at-md-p2',
     '.at-batch-select2', '.at-counselor-select2', '.at-member-select2'].forEach(function(sel) {
        var $el = $(form).find(sel);
        if ($el.hasClass('select2-hidden-accessible')) $el.trigger('change.select2');
    });

    // Trigger the show/hide logic so MD/VW/L113 panels appear for the chosen class.
    if (typeof updateAtExtraFields === 'function') updateAtExtraFields(form);

    var modalEl = document.getElementById('addAttendanceModal');
    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modal.show();
    setTimeout(function() {
        var first = form.querySelector('.at-first');
        if (first) first.focus();
    }, 350);
}

// ── Chart PNG export helper ───────────────────────────────────────────────────
function exportChartPng(canvasId, filename) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = (filename || canvasId) + '_' + new Date().toISOString().slice(0,10) + '.png';
    link.click();
}

// ── Discipleship Pipeline PNG export (HTML card → image via html2canvas) ──────
function exportDiscipleshipPipelinePng() {
    var card = document.getElementById('discipleshipPipelineCard');
    if (!card) return;
    if (typeof html2canvas === 'undefined') {
        alert('html2canvas not loaded — please check your connection and try again.');
        return;
    }
    html2canvas(card, { backgroundColor: '#ffffff', scale: 2 }).then(function(canvas) {
        var link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = 'discipleship_pipeline_' + new Date().toISOString().slice(0,10) + '.png';
        link.click();
    }).catch(function(err) {
        console.error('Pipeline PNG export failed:', err);
        alert('Could not generate the PNG. Check the console for details.');
    });
}

// ── Statistics charts ─────────────────────────────────────────────────────────
<?php if ($activeTab === 'stats'): ?>
<?php if ($isVWView): ?>
// VW-specific chart data from PHP
var VW_BATCH_LABELS = <?php echo json_encode(array_values($vwBatchLabels)); ?>;
var VW_BATCH_KEYS   = <?php echo json_encode(array_keys($vwByBatch)); ?>;
var VW_BATCH_DATA   = <?php echo json_encode(array_values(array_map(fn($b) => [
    'total'    => $b['total'],
    'baptized' => $b['baptized'],
    'matched'  => $b['matched'],
], $vwByBatch))); ?>;
var VW_YEAR_LABELS  = <?php echo json_encode(array_map('strval', $vwYears)); ?>;
var VW_YEAR_DATA    = <?php echo json_encode(array_values(array_map(fn($y) => [
    'total'    => $y['total'],
    'baptized' => $y['baptized'],
], $vwByYear))); ?>;
var VW_COUNSELOR_LABELS = <?php echo json_encode(array_keys($topCounselors)); ?>;
var VW_COUNSELOR_COUNTS = <?php echo json_encode(array_values($topCounselors)); ?>;

document.addEventListener('DOMContentLoaded', function() {
    var batchTotals   = VW_BATCH_DATA.map(function(b){ return b.total; });
    var batchBaptized = VW_BATCH_DATA.map(function(b){ return b.baptized; });

    // Chart 1: Attendance per Batch — vertical bar
    new Chart(document.getElementById('vwBatchChart'), {
        type: 'bar',
        data: {
            labels: VW_BATCH_LABELS,
            datasets: [
                { label:'Total',         data: batchTotals,   backgroundColor:'rgba(13,110,253,.8)',  borderColor:'rgb(13,110,253)',  borderWidth:1 },
                { label:'Water Baptized',data: batchBaptized, backgroundColor:'rgba(13,202,240,.7)',  borderColor:'rgb(13,202,240)',  borderWidth:1 },
            ]
        },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
            maintainAspectRatio: false,
        }
    });

    // Chart 2: Year-over-Year — Total + Water Baptism only
    var yearTotals   = VW_YEAR_DATA.map(function(y){ return y.total; });
    var yearBaptized = VW_YEAR_DATA.map(function(y){ return y.baptized; });
    var hasAny = function(arr){ return arr.some(function(v){ return v > 0; }); };
    var yearDatasets = [
        { label:'Total',         data: yearTotals,   backgroundColor:'rgba(13,110,253,.8)', borderWidth:1 },
        { label:'Water Baptism', data: yearBaptized, backgroundColor:'rgba(13,202,240,.7)', borderWidth:1 },
    ].filter(function(ds){ return hasAny(ds.data); });
    new Chart(document.getElementById('vwYearChart'), {
        type: 'bar',
        data: { labels: VW_YEAR_LABELS, datasets: yearDatasets },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });

    // Chart 3: Top Counselors — horizontal bar
    new Chart(document.getElementById('vwCounselorChart'), {
        type: 'bar',
        data: {
            labels: VW_COUNSELOR_LABELS,
            datasets: [{
                label: 'Participants',
                data:  VW_COUNSELOR_COUNTS,
                backgroundColor: 'rgba(13,110,253,.75)',
                borderColor: 'rgb(13,110,253)',
                borderWidth: 1,
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend:{ display:false } },
            scales: { x:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });
});

// ── VW Session Summary table export helpers ────────────────────────────────────────────
function _vwBatchTableData() {
    // Linked column stays visible in the UI but is excluded from CSV / Excel / PDF / Print exports.
    var headers = ['Session','Year','Total','Baptized'];
    var rows = [];
    document.querySelectorAll('#vwBatchTable tbody tr').forEach(function(tr) {
        var tds = tr.querySelectorAll('td');
        var row = [];
        for (var i = 0; i < tds.length - 1; i++) { // skip last (Linked) column
            row.push(tds[i].textContent.trim());
        }
        rows.push(row);
    });
    return { headers: headers, rows: rows };
}

function exportVwBatchCsv() {
    var d = _vwBatchTableData();
    var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
    d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
    var blob = new Blob(['\uFEFF'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'vw_session_summary_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
}

function exportVwBatchExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var d = _vwBatchTableData();
    var wsData = [d.headers].concat(d.rows);
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet(wsData);
    ws['!cols'] = d.headers.map(function(h, i) {
        var max = h.length;
        d.rows.forEach(function(r){ if (r[i] && r[i].length > max) max = r[i].length; });
        return { wch: Math.min(max + 2, 40) };
    });
    XLSX.utils.book_append_sheet(wb, ws, 'VW Session Summary');
    XLSX.writeFile(wb, 'vw_session_summary_'+new Date().toISOString().slice(0,10)+'.xlsx');
}

function exportVwBatchPdf() {
    if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
    var d = _vwBatchTableData();
    var doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFontSize(13);
    doc.text('Victory Weekend — Session Summary', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Exported: ' + new Date().toLocaleDateString(), 40, 52);
    doc.autoTable({
        head: [d.headers],
        body: d.rows,
        startY: 62,
        styles: { fontSize: 8, cellPadding: 4 },
        headStyles: { fillColor: [13, 110, 253], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 40, right: 40 },
    });
    doc.save('vw_session_summary_'+new Date().toISOString().slice(0,10)+'.pdf');
}

function printVwBatch() {
    var d = _vwBatchTableData();
    var thead = '<tr>'+d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('')+'</tr>';
    var tbody = d.rows.map(function(r){
        return '<tr>'+r.map(function(v){ return '<td>'+(v||'—')+'</td>'; }).join('')+'</tr>';
    }).join('');
    var html = '<!DOCTYPE html><html><head><title>VW Session Summary</title>'
        + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
        + 'h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        + 'table{border-collapse:collapse;width:100%}'
        + 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        + 'th{background:#0d6efd;color:#fff}'
        + 'tr:nth-child(even){background:#f5f7fa}'
        + 'tr:last-child{font-weight:bold;background:#e9ecef}'
        + '@media print{@page{size:landscape}}'
        + '</style></head><body>'
        + '<h2>Victory Weekend — Session Summary</h2>'
        + '<p>Printed: '+new Date().toLocaleDateString()+' &nbsp;|&nbsp; '+(d.rows.length - 1)+' session(s)</p>'
        + '<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table>'
        + '</body></html>';
    var w = window.open('', '_blank');
    w.document.write(html); w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 500);
}

<?php elseif ($isCCView): /* CC stats JS */ ?>
var CC_SESSION_LABELS = <?php echo json_encode(array_values($ccBatchLabels)); ?>;
var CC_SESSION_DATA   = <?php echo json_encode(array_values(array_map(fn($b) => ['total'=>$b['total'],'matched'=>$b['matched']], $ccByBatch))); ?>;
var CC_YEAR_LABELS    = <?php echo json_encode(array_map('strval', $ccYears)); ?>;
var CC_YEAR_DATA      = <?php echo json_encode(array_values(array_map(fn($y) => ['total'=>$y['total'],'matched'=>$y['matched']], $ccByYear))); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // Chart 1: Attendance per Session — vertical bar
    new Chart(document.getElementById('ccSessionChart'), {
        type: 'bar',
        data: {
            labels: CC_SESSION_LABELS,
            datasets: [
                { label:'Total', data: CC_SESSION_DATA.map(function(s){ return s.total; }),   backgroundColor:'rgba(25,135,84,.8)',  borderColor:'rgb(25,135,84)',  borderWidth:1 },
                { label:'Linked',data: CC_SESSION_DATA.map(function(s){ return s.matched; }), backgroundColor:'rgba(13,110,253,.6)', borderColor:'rgb(13,110,253)', borderWidth:1 },
            ]
        },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });

    // Chart 2: Year-over-Year — vertical bar
    new Chart(document.getElementById('ccYearChart'), {
        type: 'bar',
        data: {
            labels: CC_YEAR_LABELS,
            datasets: [
                { label:'Total Attendees', data: CC_YEAR_DATA.map(function(y){ return y.total; }),   backgroundColor:'rgba(25,135,84,.8)', borderColor:'rgb(25,135,84)', borderWidth:1 },
                { label:'Linked Members',  data: CC_YEAR_DATA.map(function(y){ return y.matched; }), backgroundColor:'rgba(13,110,253,.6)',borderColor:'rgb(13,110,253)',borderWidth:1 },
            ]
        },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });
});

// ── CC Session Table export helpers ──────────────────────────────────────────
function _ccSessionTableData() {
    var headers = ['Session Date','Year','Total'];
    var rows = [];
    document.querySelectorAll('#ccSessionTable tbody tr').forEach(function(tr) {
        var tds = tr.querySelectorAll('td');
        var row = [];
        for (var i = 0; i < tds.length - 1; i++) { // skip last (Linked) column
            row.push(tds[i].textContent.trim());
        }
        rows.push(row);
    });
    return { headers: headers, rows: rows };
}
function exportCcSessionCsv() {
    var d = _ccSessionTableData();
    var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
    d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
    var blob = new Blob(['\uFEFF'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'cc_session_summary_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
}
function exportCcSessionExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var d = _ccSessionTableData();
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
    ws['!cols'] = d.headers.map(function(h,i){ var max=h.length; d.rows.forEach(function(r){ if(r[i]&&r[i].length>max)max=r[i].length; }); return {wch:Math.min(max+2,40)}; });
    XLSX.utils.book_append_sheet(wb, ws, 'CC Session Summary');
    XLSX.writeFile(wb, 'cc_session_summary_'+new Date().toISOString().slice(0,10)+'.xlsx');
}
function exportCcSessionPdf() {
    if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
    var d = _ccSessionTableData();
    var doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    doc.setFontSize(13); doc.text('Church Community — Session Summary', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: '+new Date().toLocaleDateString(), 40, 52);
    doc.autoTable({
        head: [d.headers], body: d.rows, startY: 62,
        styles: { fontSize: 9, cellPadding: 4 },
        headStyles: { fillColor: [25, 135, 84], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 40, right: 40 },
    });
    doc.save('cc_session_summary_'+new Date().toISOString().slice(0,10)+'.pdf');
}
function printCcSession() {
    var d = _ccSessionTableData();
    var thead = '<tr>'+d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('')+'</tr>';
    var tbody = d.rows.map(function(r){ return '<tr>'+r.map(function(v){ return '<td>'+(v||'—')+'</td>'; }).join('')+'</tr>'; }).join('');
    var html = '<!DOCTYPE html><html><head><title>CC Session Summary</title>'
        +'<style>body{font-family:sans-serif;font-size:11px;padding:20px}h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        +'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        +'th{background:#198754;color:#fff}tr:nth-child(even){background:#f5f7fa}tr:last-child{font-weight:bold;background:#e9ecef}'
        +'</style></head><body>'
        +'<h2>Church Community — Session Summary</h2>'
        +'<p>Printed: '+new Date().toLocaleDateString()+' &nbsp;|&nbsp; '+(d.rows.length-1)+' session(s)</p>'
        +'<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table>'
        +'</body></html>';
    var w = window.open('','_blank'); w.document.write(html); w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 500);
}

<?php elseif ($isMDView): /* MD stats JS */ ?>
var MD_SESSION_LABELS = <?php echo json_encode(array_values($mdBatchLabels)); ?>;
var MD_SESSION_DATA   = <?php echo json_encode(array_values(array_map(fn($b) => [
    'total'  => $b['total'],
    'part1'  => $b['part1'],
    'part2'  => $b['part2'],
    'both'   => $b['both'],
    'matched'=> $b['matched'],
], $mdByBatch))); ?>;
var MD_YEAR_LABELS = <?php echo json_encode(array_map('strval', $mdYears)); ?>;
var MD_YEAR_DATA   = <?php echo json_encode(array_values(array_map(fn($y) => [
    'total'  => $y['total'],
    'part1'  => $y['part1'],
    'part2'  => $y['part2'],
    'both'   => $y['both'],
], $mdByYear))); ?>;
var MD_HAS_PARTS = <?php echo $hasMdParts ? 'true' : 'false'; ?>;

document.addEventListener('DOMContentLoaded', function() {
    // Chart 1: Attendance per Session — vertical bar
    var sessionDatasets = [
        { label:'Total', data: MD_SESSION_DATA.map(function(s){ return s.total; }), backgroundColor:'rgba(13,202,240,.8)', borderColor:'rgb(13,202,240)', borderWidth:1 },
    ];
    if (MD_HAS_PARTS) {
        sessionDatasets.push(
            { label:'Part 1', data: MD_SESSION_DATA.map(function(s){ return s.part1; }), backgroundColor:'rgba(13,110,253,.7)', borderColor:'rgb(13,110,253)', borderWidth:1 },
            { label:'Part 2', data: MD_SESSION_DATA.map(function(s){ return s.part2; }), backgroundColor:'rgba(32,201,151,.7)', borderColor:'rgb(32,201,151)', borderWidth:1 },
            { label:'Both',   data: MD_SESSION_DATA.map(function(s){ return s.both;  }), backgroundColor:'rgba(25,135,84,.7)',  borderColor:'rgb(25,135,84)',  borderWidth:1 }
        );
    }
    new Chart(document.getElementById('mdSessionChart'), {
        type: 'bar',
        data: { labels: MD_SESSION_LABELS, datasets: sessionDatasets },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });

    // Chart 2: Year-over-Year — vertical bar
    var yearDatasets = [
        { label:'Total Attendees', data: MD_YEAR_DATA.map(function(y){ return y.total; }), backgroundColor:'rgba(13,202,240,.8)', borderColor:'rgb(13,202,240)', borderWidth:1 },
    ];
    if (MD_HAS_PARTS) {
        yearDatasets.push(
            { label:'Part 1', data: MD_YEAR_DATA.map(function(y){ return y.part1; }), backgroundColor:'rgba(13,110,253,.7)', borderColor:'rgb(13,110,253)', borderWidth:1 },
            { label:'Part 2', data: MD_YEAR_DATA.map(function(y){ return y.part2; }), backgroundColor:'rgba(32,201,151,.7)', borderColor:'rgb(32,201,151)', borderWidth:1 }
        );
    }
    new Chart(document.getElementById('mdYearChart'), {
        type: 'bar',
        data: { labels: MD_YEAR_LABELS, datasets: yearDatasets },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });
});

// ── MD Session Table export helpers ──────────────────────────────────────────
function _mdSessionTableData() {
    var hasParts = MD_HAS_PARTS;
    var headers = hasParts ? ['Session Date','Year','Total','Part 1','Part 2','Both'] : ['Session Date','Year','Total'];
    var colCount = hasParts ? 6 : 3; // skip Linked (last col)
    var rows = [];
    document.querySelectorAll('#mdSessionTable tbody tr').forEach(function(tr) {
        var tds = tr.querySelectorAll('td');
        var row = [];
        for (var i = 0; i < colCount; i++) {
            row.push(tds[i] ? tds[i].textContent.trim() : '');
        }
        rows.push(row);
    });
    return { headers: headers, rows: rows };
}
function exportMdSessionCsv() {
    var d = _mdSessionTableData();
    var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
    d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
    var blob = new Blob(['\uFEFF'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'md_session_summary_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
}
function exportMdSessionExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var d = _mdSessionTableData();
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
    ws['!cols'] = d.headers.map(function(h,i){ var max=h.length; d.rows.forEach(function(r){ if(r[i]&&r[i].length>max)max=r[i].length; }); return {wch:Math.min(max+2,40)}; });
    XLSX.utils.book_append_sheet(wb, ws, 'MD Session Summary');
    XLSX.writeFile(wb, 'md_session_summary_'+new Date().toISOString().slice(0,10)+'.xlsx');
}
function exportMdSessionPdf() {
    if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
    var d = _mdSessionTableData();
    var doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    doc.setFontSize(13); doc.text('Making Disciples — Session Summary', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: '+new Date().toLocaleDateString(), 40, 52);
    doc.autoTable({
        head: [d.headers], body: d.rows, startY: 62,
        styles: { fontSize: 9, cellPadding: 4 },
        headStyles: { fillColor: [13, 202, 240], textColor: 0, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 40, right: 40 },
    });
    doc.save('md_session_summary_'+new Date().toISOString().slice(0,10)+'.pdf');
}
function printMdSession() {
    var d = _mdSessionTableData();
    var thead = '<tr>'+d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('')+'</tr>';
    var tbody = d.rows.map(function(r){ return '<tr>'+r.map(function(v){ return '<td>'+(v||'—')+'</td>'; }).join('')+'</tr>'; }).join('');
    var html = '<!DOCTYPE html><html><head><title>MD Session Summary</title>'
        +'<style>body{font-family:sans-serif;font-size:11px;padding:20px}h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        +'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        +'th{background:#0dcaf0;color:#000}tr:nth-child(even){background:#f5f7fa}tr:last-child{font-weight:bold;background:#e9ecef}'
        +'</style></head><body>'
        +'<h2>Making Disciples — Session Summary</h2>'
        +'<p>Printed: '+new Date().toLocaleDateString()+' &nbsp;|&nbsp; '+(d.rows.length-1)+' session(s)</p>'
        +'<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table>'
        +'</body></html>';
    var w = window.open('','_blank'); w.document.write(html); w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 500);
}

<?php elseif ($isELView): /* EL stats JS */ ?>
var EL_SESSION_LABELS = <?php echo json_encode(array_values($elBatchLabels)); ?>;
var EL_SESSION_DATA   = <?php echo json_encode(array_values(array_map(fn($b) => [
    'total'  => $b['total'],
    'matched'=> $b['matched'],
], $elByBatch))); ?>;
var EL_YEAR_LABELS = <?php echo json_encode(array_map('strval', $elYears)); ?>;
var EL_YEAR_DATA   = <?php echo json_encode(array_values(array_map(fn($y) => [
    'total'  => $y['total'],
], $elByYear))); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // Chart 1: Attendance per Session — vertical bar
    new Chart(document.getElementById('elSessionChart'), {
        type: 'bar',
        data: {
            labels: EL_SESSION_LABELS,
            datasets: [
                { label:'Total', data: EL_SESSION_DATA.map(function(s){ return s.total; }), backgroundColor:'rgba(255,193,7,.8)', borderColor:'rgb(255,193,7)', borderWidth:1 },
            ]
        },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });

    // Chart 2: Year-over-Year — vertical bar
    new Chart(document.getElementById('elYearChart'), {
        type: 'bar',
        data: {
            labels: EL_YEAR_LABELS,
            datasets: [
                { label:'Total Attendees', data: EL_YEAR_DATA.map(function(y){ return y.total; }), backgroundColor:'rgba(255,193,7,.8)', borderColor:'rgb(255,193,7)', borderWidth:1 },
            ]
        },
        options: {
            plugins: { legend:{ position:'bottom', labels:{ boxWidth:12 } } },
            scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } },
            responsive: true,
        }
    });
});

// ── EL Session Table export helpers ──────────────────────────────────────────
function _elSessionTableData() {
    var headers = ['Session Date','Year','Total'];
    var rows = [];
    document.querySelectorAll('#elSessionTable tbody tr').forEach(function(tr) {
        var tds = tr.querySelectorAll('td');
        var row = [];
        for (var i = 0; i < tds.length - 1; i++) { // skip last (Linked) column
            row.push(tds[i].textContent.trim());
        }
        rows.push(row);
    });
    return { headers: headers, rows: rows };
}
function exportElSessionCsv() {
    var d = _elSessionTableData();
    var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
    d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
    var blob = new Blob(['\uFEFF'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'el_session_summary_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
}
function exportElSessionExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var d = _elSessionTableData();
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
    ws['!cols'] = d.headers.map(function(h,i){ var max=h.length; d.rows.forEach(function(r){ if(r[i]&&r[i].length>max)max=r[i].length; }); return {wch:Math.min(max+2,40)}; });
    XLSX.utils.book_append_sheet(wb, ws, 'EL Session Summary');
    XLSX.writeFile(wb, 'el_session_summary_'+new Date().toISOString().slice(0,10)+'.xlsx');
}
function exportElSessionPdf() {
    if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
    var d = _elSessionTableData();
    var doc = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    doc.setFontSize(13); doc.text('Empowering Leaders — Session Summary', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: '+new Date().toLocaleDateString(), 40, 52);
    doc.autoTable({
        head: [d.headers], body: d.rows, startY: 62,
        styles: { fontSize: 9, cellPadding: 4 },
        headStyles: { fillColor: [255, 193, 7], textColor: 0, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 40, right: 40 },
    });
    doc.save('el_session_summary_'+new Date().toISOString().slice(0,10)+'.pdf');
}
function printElSession() {
    var d = _elSessionTableData();
    var thead = '<tr>'+d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('')+'</tr>';
    var tbody = d.rows.map(function(r){ return '<tr>'+r.map(function(v){ return '<td>'+(v||'—')+'</td>'; }).join('')+'</tr>'; }).join('');
    var html = '<!DOCTYPE html><html><head><title>EL Session Summary</title>'
        +'<style>body{font-family:sans-serif;font-size:11px;padding:20px}h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        +'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        +'th{background:#ffc107;color:#000}tr:nth-child(even){background:#f5f7fa}tr:last-child{font-weight:bold;background:#e9ecef}'
        +'</style></head><body>'
        +'<h2>Empowering Leaders — Session Summary</h2>'
        +'<p>Printed: '+new Date().toLocaleDateString()+' &nbsp;|&nbsp; '+(d.rows.length-1)+' session(s)</p>'
        +'<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table>'
        +'</body></html>';
    var w = window.open('','_blank'); w.document.write(html); w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 500);
}

<?php elseif ($isL113View): /* L113 stats JS */ ?>
var AR_L113_BATCH_LABELS       = <?php echo json_encode($_l113BLabels); ?>;
var AR_L113_BATCH_COMPLETED    = <?php echo json_encode($_l113BCompleted); ?>;
var AR_L113_BATCH_NOT_COMPLETED = <?php echo json_encode($_l113BNotCompleted); ?>;

document.addEventListener('DOMContentLoaded', function() {
    var ctx1 = document.getElementById('arL113CompletionChart');
    if (ctx1) {
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: AR_L113_BATCH_LABELS,
                datasets: [
                    {label:'Completed',     data: AR_L113_BATCH_COMPLETED,     backgroundColor:'rgba(40,167,69,0.8)',  borderColor:'rgba(40,167,69,1)',  borderWidth:1},
                    {label:'Not Completed', data: AR_L113_BATCH_NOT_COMPLETED,  backgroundColor:'rgba(255,193,7,0.8)', borderColor:'rgba(255,193,7,1)', borderWidth:1},
                ]
            },
            options: {
                indexAxis: 'y', responsive: true,
                plugins: {legend:{position:'bottom',labels:{boxWidth:12}}},
                scales: {x:{stacked:true,beginAtZero:true,ticks:{precision:0}}, y:{stacked:true}},
            }
        });
    }
    var ctx2 = document.getElementById('arL113ParticipantsChart');
    if (ctx2) {
        var totals = AR_L113_BATCH_COMPLETED.map(function(c,i){return c + AR_L113_BATCH_NOT_COMPLETED[i];});
        new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: AR_L113_BATCH_LABELS,
                datasets: [{label:'Total Participants', data: totals, backgroundColor:'rgba(220,53,69,0.7)', borderColor:'rgba(220,53,69,1)', borderWidth:1}]
            },
            options: {
                responsive: true,
                plugins: {legend:{position:'bottom',labels:{boxWidth:12}}},
                scales: {y:{beginAtZero:true,ticks:{precision:0}}},
            }
        });
    }
});

// ── L113 Batch Table export helpers ───────────────────────────────────────────
function _arL113BatchTableData() {
    var headers = ['Batch','Year','Total','Completed','Not Completed','Completion %'];
    var rows = [];
    document.querySelectorAll('#arL113BatchTable tbody tr').forEach(function(tr) {
        var tds = tr.querySelectorAll('td');
        if (tds.length < 6) return;
        rows.push([tds[0].textContent.trim(),tds[1].textContent.trim(),tds[2].textContent.trim(),
                   tds[3].textContent.trim(),tds[4].textContent.trim(),tds[5].textContent.trim()]);
    });
    return {headers:headers, rows:rows};
}
function exportArL113BatchCsv() {
    var d = _arL113BatchTableData();
    var csv = [d.headers.map(function(h){return '"'+h+'"';}).join(',')];
    d.rows.forEach(function(r){csv.push(r.map(function(v){return '"'+String(v).replace(/"/g,'""')+'"';}).join(','));});
    var blob = new Blob(['\uFEFF'+csv.join('\r\n')],{type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a'); a.href=URL.createObjectURL(blob);
    a.download='l113_batch_summary_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
}
function exportArL113BatchExcel() {
    if (typeof XLSX==='undefined'){alert('Excel library not loaded.');return;}
    var d = _arL113BatchTableData();
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
    XLSX.utils.book_append_sheet(wb, ws, 'L113 Batch Summary');
    XLSX.writeFile(wb,'l113_batch_summary_'+new Date().toISOString().slice(0,10)+'.xlsx');
}
function exportArL113BatchPdf() {
    if (typeof window.jspdf==='undefined'){alert('PDF library not loaded.');return;}
    var d = _arL113BatchTableData();
    var doc = new window.jspdf.jsPDF({orientation:'landscape',unit:'pt',format:'a4'});
    doc.setFontSize(13); doc.text('Leadership 1-1-3 — Batch Summary',40,36);
    doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: '+new Date().toLocaleDateString(),40,52);
    doc.autoTable({
        head:[d.headers],body:d.rows,startY:62,
        styles:{fontSize:9,cellPadding:4},
        headStyles:{fillColor:[192,57,43],textColor:255,fontStyle:'bold'},
        alternateRowStyles:{fillColor:[245,247,250]},
        margin:{left:40,right:40},
    });
    doc.save('l113_batch_summary_'+new Date().toISOString().slice(0,10)+'.pdf');
}
function printArL113Batch() {
    var d = _arL113BatchTableData();
    var thead='<tr>'+d.headers.map(function(h){return '<th>'+h+'</th>';}).join('')+'</tr>';
    var tbody=d.rows.map(function(r){return '<tr>'+r.map(function(v){return '<td>'+(v||'—')+'</td>';}).join('')+'</tr>';}).join('');
    var html='<!DOCTYPE html><html><head><title>L113 Batch Summary</title>'
        +'<style>body{font-family:sans-serif;font-size:11px;padding:20px}h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        +'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        +'th{background:#c0392b;color:#fff}tr:nth-child(even){background:#f5f7fa}tr:last-child{font-weight:bold;background:#e9ecef}'
        +'</style></head><body>'
        +'<h2>Leadership 1-1-3 — Batch Summary</h2>'
        +'<p>Printed: '+new Date().toLocaleDateString()+'</p>'
        +'<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table>'
        +'</body></html>';
    var w=window.open('','_blank'); w.document.write(html); w.document.close(); w.focus();
    setTimeout(function(){w.print();},500);
}

<?php else: /* all-classes stats JS */ ?>
var PA_STATS        = <?php echo json_encode($paStats); ?>;
var MATCH_STATS     = <?php echo json_encode($matchStats); ?>;
var ALL_YEARS       = <?php echo json_encode($allYears ?? []); ?>;
var MONTHLY_BY_YEAR = <?php echo json_encode($monthlyByYear ?? []); ?>;
var PROG_DEFS   = <?php echo json_encode(array_map(fn($p) => ['label'=>$p['label'],'color'=>$p['color'],'short'=>$p['short']], $PROGRAM_DEFS)); ?>;
var CHART_COLORS = {
    victory_weekend:    {fill:'rgba(13,110,253,.8)',   border:'rgb(13,110,253)',   area:'rgba(13,110,253,.12)'},
    church_community:   {fill:'rgba(25,135,84,.8)',    border:'rgb(25,135,84)',    area:'rgba(25,135,84,.12)'},
    making_disciples:   {fill:'rgba(13,202,240,.8)',   border:'rgb(13,202,240)',   area:'rgba(13,202,240,.12)'},
    empowering_leaders: {fill:'rgba(255,193,7,.8)',    border:'rgb(255,193,7)',    area:'rgba(255,193,7,.12)'},
    leadership_113:     {fill:'rgba(220,53,69,.8)',    border:'rgb(220,53,69)',    area:'rgba(220,53,69,.12)'},
};
// Year-line colors (cycle through for the monthly chart)
var YEAR_LINE_COLORS = [
    {border:'rgb(13,110,253)',  area:'rgba(13,110,253,.1)'},
    {border:'rgb(220,53,69)',   area:'rgba(220,53,69,.1)'},
    {border:'rgb(25,135,84)',   area:'rgba(25,135,84,.1)'},
    {border:'rgb(255,193,7)',   area:'rgba(255,193,7,.1)'},
    {border:'rgb(13,202,240)',  area:'rgba(13,202,240,.1)'},
    {border:'rgb(108,117,125)', area:'rgba(108,117,125,.1)'},
];

document.addEventListener('DOMContentLoaded', function() {
    var progKeys   = Object.keys(PROG_DEFS);
    var progLabels = progKeys.map(function(k){ return PROG_DEFS[k].label; });
    var progTotals = progKeys.map(function(k){
        var yrs = PA_STATS[k] || {};
        return Object.values(yrs).reduce(function(a,b){ return a+b; }, 0);
    });

    // ── Pie: Class Distribution ──────────────────────────────────────────
    new Chart(document.getElementById('atProgramChart'), {
        type: 'pie',
        data: {
            labels: progLabels,
            datasets: [{
                data: progTotals,
                backgroundColor: progKeys.map(function(k){ return CHART_COLORS[k] ? CHART_COLORS[k].fill : 'rgba(100,100,100,.7)'; }),
                borderColor:     progKeys.map(function(k){ return CHART_COLORS[k] ? CHART_COLORS[k].border : 'rgb(100,100,100)'; }),
                borderWidth: 2,
                hoverOffset: 8,
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a,b){ return a+b; }, 0);
                            var pct   = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                            return ' ' + ctx.label + ': ' + ctx.raw.toLocaleString() + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });

    // ── Grouped bars: Attendance by Class per Year ───────────────────────
    // For each year, one bar per class — quick visual answer to
    // "how many people attended each of the 5 classes in 2023 / 2024 / 2025".
    var yearsSet = {};
    progKeys.forEach(function(k){
        Object.keys(PA_STATS[k] || {}).forEach(function(y){ yearsSet[y] = true; });
    });
    var allYears = Object.keys(yearsSet).sort();
    var classDatasets = progKeys.map(function(k){
        var c = CHART_COLORS[k] || {fill:'rgba(100,100,100,.7)', border:'rgb(100,100,100)'};
        return {
            label: PROG_DEFS[k].label,
            data: allYears.map(function(y){ return (PA_STATS[k] && PA_STATS[k][y]) ? PA_STATS[k][y] : 0; }),
            backgroundColor: c.fill,
            borderColor: c.border,
            borderWidth: 1,
        };
    });
    new Chart(document.getElementById('atClassYearChart'), {
        type: 'bar',
        data: { labels: allYears, datasets: classDatasets },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } }
            }
        }
    });

    // ── Horizontal bars: Member Completion Distribution ──────────────────
    // Color gradient from danger (1 class only) → success (all 5) so the engagement depth
    // reads visually: red = stuck at entry, green = full journey.
    var completionBuckets = window.AT_COMPLETION_BUCKETS || {};
    var bucketColors = {
        '1': 'rgba(220,53,69,.75)',   // danger
        '2': 'rgba(255,193,7,.75)',   // warning
        '3': 'rgba(13,202,240,.75)',  // info
        '4': 'rgba(13,110,253,.75)',  // primary
        '5': 'rgba(25,135,84,.85)',   // success
    };
    var compEl = document.getElementById('atCompletionDistChart');
    if (compEl) {
        new Chart(compEl, {
            type: 'bar',
            data: {
                labels: ['1 class', '2 classes', '3 classes', '4 classes', 'All 5 classes'],
                datasets: [{
                    label: 'Members',
                    data: [1,2,3,4,5].map(function(n){ return completionBuckets[n] || 0; }),
                    backgroundColor: [1,2,3,4,5].map(function(n){ return bucketColors[n]; }),
                    borderWidth: 0,
                }]
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a,b){return a+b;}, 0);
                                var pct   = total > 0 ? Math.round(ctx.raw / total * 100) : 0;
                                return ' ' + ctx.raw.toLocaleString() + ' members (' + pct + '%)';
                            }
                        }
                    }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } },
                    y: { grid: { display: false } }
                }
            }
        });
    }

});
<?php endif; /* end VW / all-classes stats JS */ ?>
<?php endif; /* end stats tab JS */ ?>

// ── Yearly Summary Table export helpers ──────────────────────────────────────
function _yearlySummaryData() {
    var tbl = document.getElementById('atYearlySummaryTable');
    if (!tbl) return { headers: [], rows: [] };
    var headers = Array.from(tbl.querySelectorAll('thead th')).map(function(th){ return th.textContent.trim(); });
    var rows = Array.from(tbl.querySelectorAll('tbody tr')).map(function(tr){
        return Array.from(tr.querySelectorAll('td')).map(function(td){ return td.textContent.trim(); });
    });
    return { headers: headers, rows: rows };
}
function exportYearlySummaryCsv() {
    var d = _yearlySummaryData();
    var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
    d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
    var blob = new Blob(['\uFEFF'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'yearly_summary_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
}
function exportYearlySummaryExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var d = _yearlySummaryData();
    var wsData = [d.headers].concat(d.rows);
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet(wsData);
    ws['!cols'] = d.headers.map(function(h, i) {
        var max = h.length;
        d.rows.forEach(function(r){ if (r[i] && r[i].length > max) max = r[i].length; });
        return { wch: Math.min(max + 2, 30) };
    });
    XLSX.utils.book_append_sheet(wb, ws, 'Yearly Summary');
    XLSX.writeFile(wb, 'yearly_summary_'+new Date().toISOString().slice(0,10)+'.xlsx');
}
function exportYearlySummaryPdf() {
    if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
    var d = _yearlySummaryData();
    var doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFontSize(13); doc.text('Attendance — Yearly Summary', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: '+new Date().toLocaleDateString(), 40, 52);
    doc.autoTable({
        head: [d.headers], body: d.rows, startY: 62,
        styles: { fontSize: 9, cellPadding: 4 },
        headStyles: { fillColor: [13,110,253], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245,247,250] },
        margin: { left: 40, right: 40 },
    });
    doc.save('yearly_summary_'+new Date().toISOString().slice(0,10)+'.pdf');
}
function printYearlySummary() {
    var d = _yearlySummaryData();
    var thead = '<tr>'+d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('')+'</tr>';
    var tbody = d.rows.map(function(r){
        return '<tr>'+r.map(function(v){ return '<td>'+(v||'—')+'</td>'; }).join('')+'</tr>';
    }).join('');
    var html = '<!DOCTYPE html><html><head><title>Yearly Summary</title>'
        +'<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
        +'h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        +'table{border-collapse:collapse;width:100%}'
        +'th,td{border:1px solid #ccc;padding:4px 8px;text-align:left}'
        +'th{background:#0d6efd;color:#fff}'
        +'tr:nth-child(even){background:#f5f7fa}'
        +'tr:last-child td:last-child{font-weight:bold}'
        +'@media print{@page{size:landscape}}'
        +'</style></head><body>'
        +'<h2>Attendance — Yearly Summary</h2>'
        +'<p>Printed: '+new Date().toLocaleDateString()+'</p>'
        +'<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table>'
        +'</body></html>';
    var w = window.open('','_blank'); w.document.write(html); w.document.close(); w.print();
}

// Export headers built from visible <th> elements (skip last Actions column)
function getAtExportHeaders() {
    var ths = document.querySelectorAll('#attendanceTable thead th');
    var headers = [];
    for (var i = 0; i < ths.length - 1; i++) {
        headers.push(ths[i].textContent.trim());
    }
    return headers;
}

function getAtExportRows() {
    var rows = [];
    if (!window.atTable) return rows;
    var totalCols = document.querySelectorAll('#attendanceTable thead th').length;
    window.atTable.rows({ search: 'applied' }).every(function() {
        var tds = this.node().querySelectorAll('td');
        var row = [];
        for (var i = 0; i < totalCols - 1; i++) { // skip last Actions column
            if (!tds[i]) { row.push(''); continue; }
            var c = tds[i].cloneNode(true);
            c.querySelectorAll('.d-none, style, button, .progress').forEach(function(el){el.remove();});
            row.push(c.textContent.replace(/\s+/g,' ').trim());
        }
        rows.push(row);
    });
    return rows;
}

function exportAtCSV() {
    var headers = getAtExportHeaders();
    var rows    = getAtExportRows();
    var csv = [headers.map(function(h){ return '"' + String(h).replace(/"/g,'""') + '"'; }).join(',')];
    rows.forEach(function(r) {
        csv.push(r.map(function(v){ return '"' + String(v).replace(/"/g,'""') + '"'; }).join(','));
    });
    var blob = new Blob(['\uFEFF' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'attendance_records_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}

function exportAtExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var headers = getAtExportHeaders();
    var rows    = getAtExportRows();
    var wsData  = [headers].concat(rows);
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet(wsData);
    ws['!cols'] = headers.map(function(h, i) {
        var max = h.length;
        rows.forEach(function(r){ if (r[i] && r[i].length > max) max = r[i].length; });
        return { wch: Math.min(max + 2, 50) };
    });
    XLSX.utils.book_append_sheet(wb, ws, 'Attendance');
    XLSX.writeFile(wb, 'attendance_records_' + new Date().toISOString().slice(0,10) + '.xlsx');
}

function exportAtPDF() {
    var headers = getAtExportHeaders();
    var rows    = getAtExportRows();
    var doc     = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFontSize(13);
    doc.text(document.querySelector('h1.h3') ? document.querySelector('h1.h3').textContent.trim() : 'Attendance Records', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Exported: ' + new Date().toLocaleDateString(), 40, 52);
    doc.autoTable({
        head: [headers],
        body: rows,
        startY: 62,
        styles: { fontSize: 7, cellPadding: 3 },
        headStyles: { fillColor: [25, 135, 84], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 40, right: 40 },
    });
    doc.save('attendance_records_' + new Date().toISOString().slice(0,10) + '.pdf');
}

function printAt() {
    var headers = getAtExportHeaders();
    var rows    = getAtExportRows();
    var title   = document.querySelector('h1.h3') ? document.querySelector('h1.h3').textContent.trim() : 'Attendance Records';
    var thead   = '<tr>' + headers.map(function(h){ return '<th>' + h + '</th>'; }).join('') + '</tr>';
    var tbody   = rows.map(function(r){
        return '<tr>' + r.map(function(v){ return '<td>' + (v || '—') + '</td>'; }).join('') + '</tr>';
    }).join('');
    var html = '<!DOCTYPE html><html><head><title>' + title + '</title>'
        + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
        + 'h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        + 'table{border-collapse:collapse;width:100%}'
        + 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        + 'th{background:#198754;color:#fff}'
        + 'tr:nth-child(even){background:#f5f7fa}'
        + '@media print{@page{size:landscape}}'
        + '</style></head><body>'
        + '<h2>' + title + '</h2>'
        + '<p>Printed: ' + new Date().toLocaleDateString() + ' &nbsp;|&nbsp; ' + rows.length + ' record(s)</p>'
        + '<table><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table>'
        + '</body></html>';
    var w = window.open('', '_blank');
    w.document.write(html); w.document.close(); w.focus();
    setTimeout(function(){ w.print(); }, 500);
}

// ── Filter-state + active-count badge ───────────────────────────────────────
window.atPtFilter    = '';
window.atYrFilter    = 0;
window.atMatchFilter = <?php echo json_encode($activeMatch ?? 'all'); ?>; // 'all' | 'matched' | 'unmatched' — restored from URL

window.updateAtFilterBadge = function() {
    var serverSide = <?php echo (int)($atFilterCount ?? 0); ?>;
    var n = serverSide;
    if (window.atPtFilter)    n++;
    if (window.atYrFilter)    n++;
    if (window.atMatchFilter && window.atMatchFilter !== 'all') n++;
    var searchVal = (document.getElementById('atFilterSearch') || {}).value || '';
    if (searchVal.trim()) n++;
    var badge = document.getElementById('atActiveFilterBadge');
    if (badge) {
        badge.textContent = n + ' active';
        badge.style.display = n > 0 ? '' : 'none';
    }
    var clearBtn = document.getElementById('atClearFilterBtn');
    if (clearBtn) clearBtn.style.display = n > 0 ? '' : 'none';
};

function filterAtMatchStatus(status) {
    window.atMatchFilter = status || 'all';
    document.querySelectorAll('.at-match-filter-btn').forEach(function(btn) {
        var active = btn.dataset.match === window.atMatchFilter;
        var base = btn.dataset.match === 'matched' ? 'success' : (btn.dataset.match === 'unmatched' ? 'secondary' : 'primary');
        btn.className = btn.className.replace(/\bbtn-(outline-)?[a-z]+\b/g, '').replace(/\s+/g, ' ').trim();
        btn.classList.add('btn', 'btn-sm', 'at-match-filter-btn', active ? 'btn-' + base : 'btn-outline-' + base);
    });
    // Persist into URL so subsequent server-side navigation keeps this state.
    try {
        var u = new URL(window.location.href);
        if (window.atMatchFilter === 'all') u.searchParams.delete('match');
        else u.searchParams.set('match', window.atMatchFilter);
        history.replaceState(null, '', u);
    } catch(e) {}
    if (window.atTable) window.atTable.draw();
    if (typeof window.updateAtFilterBadge === 'function') window.updateAtFilterBadge();
}

function filterAtClassYear(pt, yr) {
    window.atPtFilter = pt || '';
    window.atYrFilter = yr ? parseInt(yr) : 0;
    // Update button active states
    document.querySelectorAll('.at-class-filter-btn').forEach(function(btn) {
        var btnPt    = btn.dataset.pt || '';
        var btnYr    = parseInt(btn.dataset.yr) || 0;
        var color    = btn.dataset.color || 'secondary';
        var isActive = (btnPt === window.atPtFilter && btnYr === window.atYrFilter);
        btn.className = btn.className
            .replace(/\bbtn-(outline-)?[a-z]+\b/g, '')
            .replace(/\s+/g, ' ').trim();
        btn.classList.add('btn', 'btn-sm', isActive ? 'btn-' + color : 'btn-outline-' + color);
    });
    if (window.atTable) window.atTable.draw();
    if (typeof window.updateAtFilterBadge === 'function') window.updateAtFilterBadge();
    // Update client-side filter badge
    var progLabels = {
        victory_weekend: 'Victory Weekend', church_community: 'Church Community',
        making_disciples: 'Making Disciples', empowering_leaders: 'Empowering Leaders',
        leadership_113: 'Leadership 113'
    };
    var progColors = {
        victory_weekend: 'primary', church_community: 'secondary',
        making_disciples: 'success', empowering_leaders: 'warning',
        leadership_113: 'danger'
    };
    var clientBadge = document.getElementById('atClientFilterBadge');
    if (clientBadge) {
        var parts = [];
        if (window.atPtFilter) parts.push(progLabels[window.atPtFilter] || window.atPtFilter);
        if (window.atYrFilter) parts.push(window.atYrFilter);
        if (parts.length) {
            var bgColor = progColors[window.atPtFilter] || 'secondary';
            var textColor = (bgColor === 'warning') ? 'text-dark' : 'text-white';
            clientBadge.className = 'badge border ms-1 bg-' + bgColor + ' ' + textColor;
            clientBadge.textContent = parts.join(' \u00b7 ');
            clientBadge.style.display = '';
        } else {
            clientBadge.style.display = 'none';
        }
    }
}

function initAttendanceFilters() {
    if (typeof $.fn.DataTable === 'undefined' || !$('#attendanceTable').length) return;

    // Custom DataTables ext.search for class/year buttons + match-status toggle.
    // data-pt / data-yr may be a single value (record view) OR a CSV (pivot view).
    // When BOTH a class and a year are selected, we check per-class years (data-yr-<class>)
    // so "VW 2024" matches only members whose VW record(s) include 2024 — not members
    // with VW (any year) plus some other class in 2024.
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (!settings.nTable || settings.nTable.id !== 'attendanceTable') return true;
        var row = settings.aoData[dataIndex];
        var node = row && row.nTr;
        if (!node) return true;
        if (window.atPtFilter && window.atYrFilter) {
            // Combined class+year filter: must have a record in that exact class+year.
            var classYrs = (node.getAttribute('data-yr-' + window.atPtFilter) || '')
                .split(',').map(function(s){return parseInt(s,10);}).filter(function(n){return !!n;});
            if (classYrs.indexOf(window.atYrFilter) === -1) return false;
        } else {
            if (window.atPtFilter) {
                var pts = (node.getAttribute('data-pt') || '').split(',').filter(Boolean);
                if (pts.indexOf(window.atPtFilter) === -1) return false;
            }
            if (window.atYrFilter) {
                var yrs = (node.getAttribute('data-yr') || '').split(',').map(function(s){return parseInt(s,10);}).filter(function(n){return !!n;});
                if (yrs.indexOf(window.atYrFilter) === -1) return false;
            }
        }
        if (window.atMatchFilter === 'matched'   && node.getAttribute('data-matched') !== '1') return false;
        if (window.atMatchFilter === 'unmatched' && node.getAttribute('data-matched') !== '0') return false;
        return true;
    });

    // Wire match-status buttons
    document.querySelectorAll('.at-match-filter-btn').forEach(function(btn) {
        btn.addEventListener('click', function() { filterAtMatchStatus(btn.dataset.match); });
    });

    // Init DataTable — no built-in search/length controls (we provide our own)
    window.atTable = $('#attendanceTable').DataTable({
        responsive: true,
        pageLength: 25,
        searching: true,
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
        },
        dom: 'rt<"d-flex justify-content-between align-items-center mt-2 px-2"ip>'
    });

    // Recompute the All Classes overview (per-class member counts) from currently-visible (post-filter) pivot rows.
    function atUpdateAllClassesOverview() {
        var box = document.getElementById('atOverviewBody');
        if (!box || !window.atTable) return;
        var classes = ['vw','cc','md','el','l113'];
        // Map a data-pt token to our short key
        var keyMap = {
            'victory_weekend': 'vw',
            'church_community': 'cc',
            'making_disciples': 'md',
            'empowering_leaders': 'el',
            'leadership_113': 'l113'
        };
        var counts = { vw:0, cc:0, md:0, el:0, l113:0 };
        var total = 0;
        window.atTable.rows({ search: 'applied' }).every(function() {
            var n = this.node(); if (!n) return;
            total++;
            var pts = (n.getAttribute('data-pt') || '').split(',').filter(Boolean);
            pts.forEach(function(pt) {
                var k = keyMap[pt];
                if (k) counts[k]++;
            });
        });
        var totalEl = document.getElementById('atOverviewTotal');
        if (totalEl) totalEl.textContent = total + (total === 1 ? ' member' : ' members');
        classes.forEach(function(k) {
            var c = counts[k];
            var pct = total > 0 ? Math.round(c / total * 100) : 0;
            var cellNum = document.getElementById('atOv_'    + k);
            var cellPct = document.getElementById('atOvPct_' + k);
            var bar     = document.getElementById('atOvBar_' + k);
            if (cellNum) cellNum.textContent = c + '/' + total;
            if (cellPct) cellPct.textContent = pct + '%';
            if (bar)     bar.style.width = pct + '%';
        });
    }

    // Recompute the post-filter summary line.
    // VW view: Water Baptism count. MD view: P1/P2/both/neither counts.
    function atUpdateCompletionSummary() {
        var box = document.getElementById('atCompletionSummary');
        if (!box || !window.atTable) return;
        var total = 0, wb = 0, p1 = 0, p2 = 0, both = 0, neither = 0;
        window.atTable.rows({ search: 'applied' }).every(function() {
            var n = this.node(); if (!n) return;
            total++;
            wb  += parseInt(n.getAttribute('data-water-bap') || 0);
            var mp1 = parseInt(n.getAttribute('data-md-p1') || 0);
            var mp2 = parseInt(n.getAttribute('data-md-p2') || 0);
            p1 += mp1; p2 += mp2;
            if (mp1 && mp2) both++;
            else if (!mp1 && !mp2) neither++;
        });
        function pct(n, d) { return d > 0 ? Math.round(n / d * 100) + '%' : '0%'; }
        function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
        setText('atSumWaterBap', wb + '/' + total);  setText('atSumWaterBapPct', pct(wb, total));
        setText('atSumMdP1', p1 + '/' + total);      setText('atSumMdP1Pct',     pct(p1, total));
        setText('atSumMdP2', p2 + '/' + total);      setText('atSumMdP2Pct',     pct(p2, total));
        setText('atSumMdBoth', both + '/' + total);  setText('atSumMdBothPct',   pct(both, total));
        setText('atSumMdNeither', neither);
    }

    // Update count badge, renumber # column, summary line, and overview on every draw
    window.atTable.on('draw', function() {
        var info = window.atTable.page.info();
        var b1 = document.getElementById('atRecordCount');
        if (b1) b1.textContent = info.recordsDisplay;
        atUpdateCompletionSummary();
        atUpdateAllClassesOverview();
        // Renumber visible rows on current page sequentially
        var start = info.start;
        window.atTable.rows({ page: 'current' }).nodes().each(function(row, i) {
            var cell = row.querySelector('td.at-row-num');
            if (cell) cell.textContent = start + i + 1;
        });
    });

    // Fire once on init — the initial DataTable draw happened BEFORE the handler above was attached.
    atUpdateCompletionSummary();
    atUpdateAllClassesOverview();
    var initInfo = window.atTable.page.info();
    var initCountBadge = document.getElementById('atRecordCount');
    if (initCountBadge) initCountBadge.textContent = initInfo.recordsDisplay;

    // Custom per-page select in card header
    $('#atPerPage').on('change', function() {
        window.atTable.page.len(parseInt($(this).val())).draw();
    });

    // Realtime search across all columns — debounced
    var searchTimer;
    $('#atFilterSearch').on('input', function() {
        var val = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            window.atTable.search(val).draw();
            var badge = document.getElementById('atSearchBadge');
            if (badge) {
                if (val) { badge.textContent = '\u201c' + val + '\u201d'; badge.style.display = ''; }
                else     { badge.style.display = 'none'; }
            }
            if (typeof window.updateAtFilterBadge === 'function') window.updateAtFilterBadge();
        }, 250);
    });

    // Initialize the active-filter badge on page load
    if (typeof window.updateAtFilterBadge === 'function') window.updateAtFilterBadge();

    // Apply the current Match Status to button visuals + run the filter so the table reflects it immediately.
    if (typeof filterAtMatchStatus === 'function') filterAtMatchStatus(window.atMatchFilter || 'all');

    // Inject the current match filter into every server-side filter link in the AT Search & Filter card,
    // so clicking a year/date link doesn't drop the active match state.
    document.querySelectorAll('#atFilterBody a[href*="action=attendanceRecords"]').forEach(function(a) {
        var orig = a.getAttribute('href');
        a.addEventListener('click', function() {
            if (!window.atMatchFilter || window.atMatchFilter === 'all') return;
            var href = orig;
            if (!/[?&]match=/.test(href)) {
                href += (href.indexOf('?') > -1 ? '&' : '?') + 'match=' + encodeURIComponent(window.atMatchFilter);
                a.setAttribute('href', href);
            }
        });
    });

    if (typeof $.fn.select2 !== 'undefined') {
        // ── Program type ───────────────────────────────────────────────
        $('.at-program').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: '\u2014 Select Class \u2014',
                minimumResultsForSearch: Infinity,
            });
        });

        // ── Year (tags — pre-loaded from DB, can type new) ────────────
        $('.at-year').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Year\u2026',
                tags: true,
                minimumResultsForSearch: 0,
            });
        });

        // ── Water Baptism & Yes/No fields ─────────────────────────────
        $('.at-wbap, .at-md-p1, .at-md-p2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                minimumResultsForSearch: Infinity,
            });
        });

        // ── Member search (AJAX) ───────────────────────────────────────
        $('.at-member-select2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Search or browse members\u2026',
                allowClear: true,
                minimumInputLength: 0,
                language: {
                    noResults: function() { return 'No members found.'; },
                    searching: function() { return 'Searching\u2026'; },
                    loadingMore: function() { return 'Loading more\u2026'; },
                },
                ajax: {
                    url: 'index.php?action=ajaxSearchMembers',
                    dataType: 'json',
                    delay: 200,
                    data: function(p) { return { q: p.term || '' }; },
                    processResults: function(d) { return d; },
                    cache: true,
                }
            });
        });

        // ── Batch Label (tags — options refreshed per program type from batch_label column) ───
        $('.at-batch-select2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Select or type batch label\u2026',
                allowClear: true,
                tags: true,
                tokenSeparators: [],
            });
        });

        // ── Counselor (tags — pre-loaded from DB) ──────────────────
        $('.at-counselor-select2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Select or type counselor name\u2026',
                allowClear: true,
                tags: true,
            });
        });


        // "Mark as Unmatched" button — selects a visible "Unmatched" sentinel option.
        // On form submit the sentinel is converted to "" so the controller stores NULL.
        $(document).on('click', '.at-clear-member', function() {
            var $form = $(this).closest('form');
            var $sel  = $form.find('.at-member-select2');
            if (!$sel.length) return;
            if (!$sel.find('option[value="__unmatched__"]').length) {
                $sel.append(new Option('Unmatched (not yet a member)', '__unmatched__', false, false));
            }
            $sel.val('__unmatched__').trigger('change');
        });

        // ── Auto-link member by exact name match ──
        // When BOTH First and Last names are filled, query the server for "Lastname, Firstname"
        // and auto-select the matching member (if any). User can still override or clear.
        function atTryAutoLinkMember($form) {
            var first = ($form.find('.at-first').val() || '').trim();
            var last  = ($form.find('.at-last').val() || '').trim();
            if (!first || !last) return;
            var $mem = $form.find('.at-member-select2');
            // Only auto-link when the user hasn't already picked a real member.
            var currentVal = $mem.val();
            if (currentVal && currentVal !== '' && currentVal !== '__unmatched__') return;
            var canonical = last + ', ' + first;
            $.getJSON('index.php?action=ajaxFindMemberByName', { name: canonical }, function(resp) {
                if (!resp || !resp.match) return;
                var m = resp.match;
                var label = m.name + (m.ministry ? ' — ' + String(m.ministry).toUpperCase() : '');
                if (!$mem.find('option[value="' + m.id + '"]').length) {
                    $mem.append(new Option(label, m.id, true, true));
                }
                $mem.val(String(m.id)).trigger('change');
                // Show a small inline note that auto-linking happened.
                var $notice = $form.find('.at-auto-link-notice');
                if (!$notice.length) {
                    $notice = $('<div class="at-auto-link-notice text-success small mt-1"><i class="bi bi-link-45deg me-1"></i>Auto-linked to existing member: <strong></strong>.</div>');
                    $mem.closest('.col-12').append($notice);
                }
                $notice.find('strong').text(m.name);
                $notice.show();
            });
        }
        // Use `focusout` (bubbles) instead of `blur` (doesn't) — needed for delegation through
        // the document. Without this, the handler silently fails when the field loses focus
        // inside a modal that was opened dynamically (e.g. via the Duplicate flow).
        $(document).on('focusout', '.at-first, .at-last', function() {
            atTryAutoLinkMember($(this).closest('form'));
        });

        // ── Duplicate-attendance warning ──
        // Whenever member / class / year change in the form, check if that combination already
        // has an active record. If so, show a yellow banner above the Save button (non-blocking).
        function atCheckDuplicate($form) {
            var memberId = $form.find('.at-member-select2').val();
            var pt = $form.find('.at-program').val();
            var yr = $form.find('.at-year').val();
            // Editing? skip the current row's id.
            var excludeId = 0;
            var action = $form.attr('action') || '';
            var m = action.match(/[?&]id=(\d+)/);
            if (m) excludeId = parseInt(m[1], 10);
            var $banner = $form.find('.at-dup-banner');
            if (!memberId || memberId === '__unmatched__' || !pt || !yr) {
                if ($banner.length) $banner.hide();
                return;
            }
            $.getJSON('index.php?action=ajaxCheckAttendanceDuplicate', {
                member_id: memberId, program_type: pt, program_year: yr, exclude_id: excludeId
            }, function(resp) {
                if (!resp || !resp.exists) {
                    if ($banner.length) $banner.hide();
                    return;
                }
                if (!$banner.length) {
                    $banner = $('<div class="at-dup-banner alert alert-warning py-2 mt-2 mb-0 small"></div>');
                    $form.find('.modal-body').append($banner);
                }
                $banner.html(
                    '<i class="bi bi-exclamation-triangle-fill me-1"></i>' +
                    'This member already has <strong>' + (parseInt(resp.count, 10) || 0) + '</strong> active record(s) for the selected class + year. ' +
                    'You can still save — multi-attendance is allowed — but double-check it isn’t a duplicate entry.'
                );
                $banner.show();
            });
        }
        $(document).on('change', '.at-member-select2, .at-program, .at-year', function() {
            atCheckDuplicate($(this).closest('form'));
        });

        // Before submit: replace the sentinel with empty so the server sees member_id=""
        $(document).on('submit', 'form', function() {
            var $sel = $(this).find('.at-member-select2');
            if ($sel.length && $sel.val() === '__unmatched__') {
                $sel.val('').trigger('change');
            }
        });
    }

    // ── Notes "See more / See less" inline toggle ──
    $(document).on('click', '.at-note-toggle', function() {
        var $td = $(this).closest('td');
        var $short = $td.find('.at-note-short');
        var $full  = $td.find('.at-note-full');
        var expanded = $full.is(':visible');
        $short.toggle(expanded);
        $full.toggle(!expanded);
        $(this).text(expanded ? 'See more' : 'See less');
    });

    // ── Program type → show/hide extra fields + refresh label options ──
    $(document).on('change', '.at-program', function() {
        var form = $(this).closest('form')[0];
        updateAtExtraFields(form);
        refreshAtBatchOptions(form, $(this).val());
    });
    // Initial state for add modal
    var _addForm = document.getElementById('addAtForm');
    if (_addForm) {
        updateAtExtraFields(_addForm);
        refreshAtBatchOptions(_addForm, $(_addForm).find('.at-program').val());
    }
}
</script>
<?php include 'shared/footer.php'; ?>
<script>
$(function() {
    initAttendanceFilters();
    <?php if ($activePt): ?>
    // Pre-fill program type in Add modal for this class view
    var addForm = document.getElementById('addAtForm');
    if (addForm) {
        var $prog = $(addForm).find('.at-program');
        $prog.val('<?php echo $activePt; ?>');
        if ($prog.hasClass('select2-hidden-accessible')) $prog.trigger('change.select2');
        updateAtExtraFields(addForm);
        refreshAtBatchOptions(addForm, '<?php echo $activePt; ?>');
    }
    <?php endif; ?>
});
</script>
