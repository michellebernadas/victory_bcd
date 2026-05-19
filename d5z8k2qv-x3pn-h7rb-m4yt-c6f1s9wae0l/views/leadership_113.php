<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';

$records        = $records        ?? [];
$availableYears = $availableYears ?? [];
$batchList      = $batchList      ?? [];
$activeYear     = $activeYear     ?? '';
$activeBatch    = $activeBatch    ?? '';
$activeTab      = $_GET['tab']    ?? 'records';
$activeSearch   = $activeSearch   ?? '';
$activeDateFrom = $activeDateFrom ?? '';
$activeDateTo   = $activeDateTo   ?? '';

// Filter records by activeBatch if set
if ($activeBatch !== '') {
    $records = array_filter($records, function($rec) use ($activeBatch) {
        $batch = trim($rec['batch_label'] ?? '');
        return $batch === $activeBatch || $rec['program_label'] === $activeBatch;
    });
    $records = array_values($records);
}

// Group records by batch label (then by year within)
$grouped = [];
foreach ($records as $rec) {
    $key = $rec['program_label'] ?: ('Year ' . $rec['program_year']);
    $grouped[$key][] = $rec;
}

// No-class / skip statuses
$noClassStatuses = ['NO CLASS', 'HOLY WEEK', 'DC 2023', 'NC'];

// Stats
$totalRecords  = count($records);
$totalComplete = 0;
$totalNotComplete = 0;
$totalMembers  = 0;
foreach ($records as $rec) {
    if ($rec['member_id']) $totalMembers++;
    $ed = $rec['extra_data'] ? json_decode($rec['extra_data'], true) : null;
    if ($ed && isset($ed['sessions'])) {
        $totalActual = (int)($ed['total_sessions'] ?? 0);
        if ($totalActual === 0) {
            foreach ($ed['sessions'] as $s) {
                if (!in_array(strtoupper(trim($s)), $noClassStatuses)) $totalActual++;
            }
        }
        $absentCnt = 0;
        foreach ($ed['sessions'] as $s) {
            if (strtoupper(trim($s)) === 'A') $absentCnt++;
        }
        $isComplete = ($totalActual > 0 && $absentCnt === 0);
        if ($isComplete) $totalComplete++; else if ($totalActual > 0) $totalNotComplete++;
    }
}

// Batch stats table (group by batch+year)
$batchStats = [];
foreach ($records as $rec) {
    $ed = $rec['extra_data'] ? json_decode($rec['extra_data'], true) : null;
    $batchKey = trim($rec['batch_label'] ?? '') ?: ($rec['program_label'] ?? '');
    $yr = $rec['program_year'];
    $key = $batchKey . '|' . $yr;
    if (!isset($batchStats[$key])) {
        $batchStats[$key] = ['batch'=>$batchKey,'year'=>$yr,'total'=>0,'completed'=>0,'notCompleted'=>0];
    }
    $batchStats[$key]['total']++;
    if ($ed && isset($ed['sessions'])) {
        $totalActual = (int)($ed['total_sessions'] ?? 0);
        if ($totalActual === 0) {
            foreach ($ed['sessions'] as $s) {
                if (!in_array(strtoupper(trim($s)), $noClassStatuses)) $totalActual++;
            }
        }
        $ab = 0;
        foreach ($ed['sessions'] as $s) { if (strtoupper(trim($s)) === 'A') $ab++; }
        if ($totalActual > 0 && $ab === 0) $batchStats[$key]['completed']++;
        elseif ($totalActual > 0) $batchStats[$key]['notCompleted']++;
    }
}

// Batches available for the selected year (column-based now)
$batchesForYear = [];
foreach ($records as $rec) {
    if ($activeYear && (string)$rec['program_year'] !== (string)$activeYear) continue;
    $b = trim($rec['batch_label'] ?? '');
    if ($b && !in_array($b, $batchesForYear)) $batchesForYear[] = $b;
}
?>
<body>
    <?php
    // Shared datalist of session statuses (defaults merged with distinct values from existing records).
    // Referenced by every row's <input list="l113StatusOpts"> in the Add/Edit modals.
    $statusDefaults = ['P', 'A', 'L', 'NO CLASS', 'HOLY WEEK', 'NC'];
    $statusMerged = $statusDefaults;
    foreach (($l113Statuses ?? []) as $s) {
        $s = trim($s);
        if ($s !== '' && !in_array($s, $statusMerged, true)) $statusMerged[] = $s;
    }
    ?>
    <datalist id="l113StatusOpts">
        <?php foreach ($statusMerged as $s): ?>
        <option value="<?php echo htmlspecialchars($s); ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-trophy me-2 text-danger"></i>Leadership 1-1-3</h1>
                    <p class="text-muted mb-0">Session attendance records for the Leadership 1-1-3 class</p>
                </div>
                <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addL113Modal">
                    <i class="bi bi-plus-circle me-1"></i>Add Leadership 113 Record
                </button>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'records' ? 'active' : ''; ?>"
                       href="index.php?action=leadership113&tab=records<?php echo $activeYear ? '&year='.urlencode($activeYear) : ''; ?><?php echo $activeBatch ? '&batch='.urlencode($activeBatch) : ''; ?><?php echo $activeSearch ? '&search='.urlencode($activeSearch) : ''; ?>">
                        <i class="bi bi-table me-1"></i>Records
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'stats' ? 'active' : ''; ?>"
                       href="index.php?action=leadership113&tab=stats<?php echo $activeYear ? '&year='.urlencode($activeYear) : ''; ?>">
                        <i class="bi bi-bar-chart-line me-1"></i>Statistics
                    </a>
                </li>
            </ul>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php $msgs = [
                    'add'        => 'Leadership 1-1-3 record has been added successfully.',
                    'update'     => 'Leadership 1-1-3 record has been updated successfully. Changes are now reflected on the records.',
                    'deactivate' => 'Leadership 1-1-3 record has been deactivated successfully. Press the activate button to enable it again.',
                    'activate'   => 'Leadership 1-1-3 record has been reactivated successfully.',
                    'delete'     => 'Leadership 1-1-3 record has been deleted successfully. The record has been removed from the Leadership 1-1-3 records list.',
                ]; echo $msgs[$_GET['notif']] ?? 'Done.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($activeTab === 'records'): ?>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100 border-danger border-opacity-50">
                        <div class="card-body py-3">
                            <div class="h3 fw-bold text-danger mb-0"><?php echo $totalRecords; ?></div>
                            <div class="text-muted small">Total Participants</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100 border-success border-opacity-50">
                        <div class="card-body py-3">
                            <div class="h3 fw-bold text-success mb-0"><?php echo $totalComplete; ?></div>
                            <div class="text-muted small">Completed (0 absences)</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100 border-warning border-opacity-50">
                        <div class="card-body py-3">
                            <div class="h3 fw-bold text-warning mb-0"><?php echo $totalNotComplete; ?></div>
                            <div class="text-muted small">Not Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100 border-secondary border-opacity-50">
                        <div class="card-body py-3">
                            <div class="h3 fw-bold text-secondary mb-0"><?php echo $totalMembers; ?></div>
                            <div class="text-muted small">Linked to Members</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overall Completion Progress Bar -->
            <?php if ($totalRecords > 0):
                $overallPct = round($totalComplete / $totalRecords * 100);
                $barColor   = $overallPct >= 80 ? 'success' : ($overallPct >= 50 ? 'warning' : 'danger');
            ?>
            <div class="card mb-4">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-semibold">Overall Completion Rate</span>
                        <span class="badge bg-<?php echo $barColor; ?>"><?php echo $overallPct; ?>%</span>
                    </div>
                    <div class="progress" style="height:10px;">
                        <div class="progress-bar bg-<?php echo $barColor; ?>" style="width:<?php echo $overallPct; ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1" style="font-size:11px;">
                        <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><?php echo $totalComplete; ?> completed</span>
                        <span class="text-muted"><?php echo $totalRecords; ?> total participants</span>
                        <span class="text-warning"><i class="bi bi-exclamation-circle-fill me-1"></i><?php echo $totalNotComplete; ?> not completed</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <?php
            $l113FilterCount = count(array_filter([$activeYear, $activeBatch, $activeSearch, $activeDateFrom, $activeDateTo]));
            $l113ClearUrl    = 'index.php?action=leadership113';
            ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="text-white fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-funnel me-2"></i>Search & Filter
                        <span class="badge bg-white text-danger" id="l113ActiveFilterBadge" style="display:none">0 active</span>
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?php echo $l113ClearUrl; ?>" class="btn btn-sm btn-outline-light" id="l113ClearFiltersBtn" style="<?php echo $l113FilterCount > 0 ? '' : 'display:none'; ?>">
                            <i class="bi bi-x-circle me-1"></i>Clear Filters
                        </a>
                        <a href="index.php?action=attendanceRecords" class="btn btn-sm btn-outline-light">
                            <i class="bi bi-layout-three-columns me-1"></i>All Classes
                        </a>
                        <button class="btn btn-sm btn-outline-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#l113FilterBody" aria-expanded="true">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div class="collapse show" id="l113FilterBody">
                <div class="card-body py-3">
                    <!-- Search -->
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="l113Search" class="form-control"
                               value="<?php echo htmlspecialchars($activeSearch); ?>"
                               placeholder="Search name, batch, counselor…" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button"
                                onclick="document.getElementById('l113Search').value='';if(window.l113Table)window.l113Table.search('').draw();">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <!-- Year filter -->
                    <?php if (!empty($availableYears)):
                        $_l113YearCounts = $paStats['leadership_113'] ?? [];
                        $_l113TotalCount = array_sum($_l113YearCounts);
                    ?>
                    <div class="border-top pt-3">
                        <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                            <i class="bi bi-calendar me-1"></i>Filter by Year
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <a href="index.php?action=leadership113<?php echo $activeSearch ? '&search='.urlencode($activeSearch) : ''; ?>"
                               class="btn btn-sm <?php echo !$activeYear ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                All Years <span class="badge bg-white text-danger ms-1"><?php echo $_l113TotalCount; ?></span>
                            </a>
                            <?php foreach ($availableYears as $yr): ?>
                            <a href="index.php?action=leadership113&year=<?php echo $yr; ?><?php echo $activeSearch ? '&search='.urlencode($activeSearch) : ''; ?>"
                               class="btn btn-sm <?php echo $activeYear == $yr ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                <?php echo $yr; ?> <span class="badge bg-white text-danger ms-1"><?php echo (int)($_l113YearCounts[(int)$yr] ?? 0); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php
                        // Event date sub-filter — only shows when a year is selected and there are distinct dates.
                        $availableEventDates = $availableEventDates ?? [];
                        if ($activeYear && count($availableEventDates) > 1):
                        ?>
                        <div class="d-flex align-items-center flex-wrap gap-2 mt-2 pt-2 border-top border-dashed">
                            <span class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em; white-space:nowrap;">
                                <i class="bi bi-calendar3 me-1"></i>Event Date
                            </span>
                            <a href="index.php?action=leadership113&year=<?php echo urlencode($activeYear); ?><?php echo $activeSearch ? '&search='.urlencode($activeSearch) : ''; ?>"
                               class="btn btn-sm <?php echo empty($activeDateFrom) ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
                                All Dates
                            </a>
                            <?php foreach ($availableEventDates as $_evDate):
                                $isEvActive = ($activeDateFrom === $_evDate);
                                $_evLabel = date('M j, Y', strtotime($_evDate));
                            ?>
                            <a href="index.php?action=leadership113&year=<?php echo urlencode($activeYear); ?>&event_date_from=<?php echo $_evDate; ?>&event_date_to=<?php echo $_evDate; ?><?php echo $activeSearch ? '&search='.urlencode($activeSearch) : ''; ?>"
                               class="btn btn-sm <?php echo $isEvActive ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                <i class="bi bi-calendar3 me-1"></i><?php echo $_evLabel; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <!-- Batch filter -->
                    <?php if (!empty($batchesForYear)): ?>
                    <div class="border-top pt-3 mt-2">
                        <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                            <i class="bi bi-collection me-1"></i>Filter by Batch
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <?php
                            $batchClearUrl = 'index.php?action=leadership113' .
                                ($activeYear   ? '&year='.urlencode($activeYear)               : '') .
                                ($activeSearch ? '&search='.urlencode($activeSearch)           : '') .
                                ($activeDateFrom ? '&event_date_from='.urlencode($activeDateFrom) : '') .
                                ($activeDateTo   ? '&event_date_to='.urlencode($activeDateTo)     : '');
                            ?>
                            <a href="<?php echo $batchClearUrl; ?>"
                               class="btn btn-sm <?php echo !$activeBatch ? 'btn-danger' : 'btn-outline-danger'; ?>">All Batches</a>
                            <?php foreach ($batchesForYear as $bl): ?>
                            <?php
                            $batchUrl = 'index.php?action=leadership113&batch=' . urlencode($bl) .
                                ($activeYear   ? '&year='.urlencode($activeYear)               : '') .
                                ($activeSearch ? '&search='.urlencode($activeSearch)           : '') .
                                ($activeDateFrom ? '&event_date_from='.urlencode($activeDateFrom) : '') .
                                ($activeDateTo   ? '&event_date_to='.urlencode($activeDateTo)     : '');
                            ?>
                            <?php
                            // Display label: strip "LEADERSHIP 113" prefix and trailing year
                            $blDisplay = preg_replace('/^\s*LEADERSHIP\s+1-?1-?3\s+/i', '', $bl);
                            if ($activeYear) {
                                $blDisplay = preg_replace('/\s+' . preg_quote((string)$activeYear, '/') . '\s*$/', '', $blDisplay);
                            }
                            $blDisplay = trim($blDisplay) ?: $bl;
                            ?>
                            <a href="<?php echo $batchUrl; ?>"
                               class="btn btn-sm <?php echo $activeBatch === $bl ? 'btn-danger' : 'btn-outline-danger'; ?>">
                                <?php echo htmlspecialchars($blDisplay); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- Date range filter -->
                    <!-- Match status filter -->
                    <div class="border-top pt-3 mt-2">
                        <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                            <i class="bi bi-link-45deg me-1"></i>Filter by Match Status
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-primary l113-match-filter-btn" data-match="all">All Records</button>
                            <button type="button" class="btn btn-sm btn-outline-success l113-match-filter-btn" data-match="matched"><i class="bi bi-person-check me-1"></i>Matched Only</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary l113-match-filter-btn" data-match="unmatched"><i class="bi bi-person-exclamation me-1"></i>Unmatched Only</button>
                        </div>
                    </div>
                    <div class="border-top pt-3 mt-2">
                        <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                            <i class="bi bi-calendar-range me-1"></i>Filter by Date Range
                        </div>
                        <form method="GET" action="index.php" class="d-flex flex-wrap gap-2 align-items-end">
                            <input type="hidden" name="action" value="leadership113">
                            <?php if ($activeYear): ?><input type="hidden" name="year" value="<?php echo htmlspecialchars($activeYear); ?>"><?php endif; ?>
                            <?php if ($activeBatch): ?><input type="hidden" name="batch" value="<?php echo htmlspecialchars($activeBatch); ?>"><?php endif; ?>
                            <?php if ($activeSearch): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($activeSearch); ?>"><?php endif; ?>
                            <div>
                                <label class="form-label small mb-1">From</label>
                                <input type="date" name="event_date_from" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($activeDateFrom); ?>">
                            </div>
                            <div>
                                <label class="form-label small mb-1">To</label>
                                <input type="date" name="event_date_to" class="form-control form-control-sm"
                                       value="<?php echo htmlspecialchars($activeDateTo); ?>">
                            </div>
                            <div class="d-flex gap-1">
                                <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-funnel me-1"></i>Apply</button>
                                <?php if ($activeDateFrom || $activeDateTo): ?>
                                <a href="index.php?action=leadership113<?php echo $activeYear ? '&year='.urlencode($activeYear) : ''; ?><?php echo $activeSearch ? '&search='.urlencode($activeSearch) : ''; ?>"
                                   class="btn btn-sm btn-outline-secondary">Clear Date</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                </div>
            </div>

            <!-- Records Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold d-flex align-items-center flex-wrap gap-2">
                        <i class="bi bi-table me-2"></i>Leadership 1-1-3 Records
                        <span class="badge bg-white text-dark border" id="l113CountBadge"><?php echo $totalRecords; ?></span>
                        <?php if ($activeYear): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-calendar me-1"></i>Year: <?php echo htmlspecialchars($activeYear); ?></span>
                        <?php endif; ?>
                        <?php if ($activeBatch): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-collection me-1"></i>Batch: <?php echo htmlspecialchars(preg_replace('/^\s*LEADERSHIP\s+1-?1-?3\s+/i', '', $activeBatch)); ?></span>
                        <?php endif; ?>
                        <?php if ($activeSearch): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-search me-1"></i>Search: <?php echo htmlspecialchars($activeSearch); ?></span>
                        <?php endif; ?>
                        <?php if ($activeDateFrom || $activeDateTo): ?>
                        <span class="badge bg-light text-dark border"><i class="bi bi-calendar-range me-1"></i>Date: <?php echo htmlspecialchars($activeDateFrom ?: '…'); ?> → <?php echo htmlspecialchars($activeDateTo ?: '…'); ?></span>
                        <?php endif; ?>
                    </span>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 me-3">
                            <label class="text-white small mb-0">Show</label>
                            <select id="l113PerPage" class="form-select form-select-sm" style="width:auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="-1">All</option>
                            </select>
                            <label class="text-white small mb-0">per page</label>
                        </div>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportL113Csv()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportL113Excel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportL113Pdf()" title="Export PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="printL113()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($records)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-trophy display-4 mb-3 d-block"></i>
                        No Leadership 1-1-3 records found.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="l113Table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Participant</th>
                                    <th>Year</th>
                                    <th>Batch</th>
                                    <th>Contact #</th>
                                    <th>Sessions</th>
                                    <th>Completion</th>
                                    <th>Matched Member</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $i => $rec):
                                    $ed         = $rec['extra_data'] ? json_decode($rec['extra_data'], true) : null;
                                    $sessions   = $ed['sessions']       ?? [];
                                    $attended   = $ed['attended']       ?? 0;
                                    $total      = (int)($ed['total_sessions'] ?? 0);
                                    if ($total === 0) {
                                        foreach ($sessions as $s) {
                                            if (!in_array(strtoupper(trim($s)), ['NO CLASS','HOLY WEEK','DC 2023','NC'])) $total++;
                                        }
                                    }
                                    $batch      = trim($rec['batch_label'] ?? '');
                                    $remarks    = $ed['remarks']        ?? '';
                                    $pct        = $total > 0 ? round($attended / $total * 100) : 0;
                                    $absentCnt  = 0;
                                    foreach ($sessions as $s) { if (strtoupper(trim($s)) === 'A') $absentCnt++; }
                                    $isComplete = ($total > 0 && $absentCnt === 0);
                                    $gridId     = 'lg_' . $rec['id'];
                                    $recStatus  = $rec['status'] ?? 'active';
                                ?>
                                <tr data-sessions="<?php echo htmlspecialchars(json_encode($sessions), ENT_QUOTES); ?>" data-matched="<?php echo $rec['member_id'] ? '1' : '0'; ?>">
                                    <td class="text-muted small"><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($rec['full_name_display']); ?></td>
                                    <td class="small text-nowrap">
                                        <?php if (!empty($rec['program_year'])): ?>
                                        <i class="bi bi-calendar3 text-muted me-1"></i><?php echo (int)$rec['program_year']; ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php
                                        $batchDisplay = $batch ?: $rec['program_label'];
                                        // Strip leading "LEADERSHIP 113" / "LEADERSHIP 1-1-3" prefix
                                        $batchDisplay = preg_replace('/^\s*LEADERSHIP\s+1-?1-?3\s+/i', '', $batchDisplay);
                                        // Strip trailing year (year is now shown in the Event Date column)
                                        $batchDisplay = preg_replace('/\s+' . preg_quote((string)$rec['program_year'], '/') . '\s*$/', '', $batchDisplay);
                                        $batchDisplay = trim($batchDisplay);
                                        if ($batchDisplay !== ''): ?>
                                            <i class="bi bi-collection text-muted me-1"></i><?php echo htmlspecialchars($batchDisplay); ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?php if ($rec['contact_number']): ?>
                                            <i class="bi bi-telephone text-muted me-1"></i><?php echo htmlspecialchars($rec['contact_number']); ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($sessions)): ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <div class="progress flex-grow-1" style="height:5px; min-width:60px;">
                                                <div class="progress-bar bg-<?php echo $pct >= 100 ? 'success' : ($pct >= 75 ? 'info' : ($pct >= 50 ? 'warning' : 'danger')); ?>"
                                                     style="width:<?php echo $pct; ?>%"></div>
                                            </div>
                                            <span class="text-muted small"><?php echo $attended; ?>/<?php echo $total; ?></span>
                                        </div>
                                        <button class="btn btn-link btn-sm p-0 mt-1" style="font-size:10px;" type="button"
                                                onclick="toggleGrid('<?php echo $gridId; ?>')">
                                            <i class="bi bi-grid-3x3 me-1"></i>Sessions
                                        </button>
                                        <div id="<?php echo $gridId; ?>" style="display:none; margin-top:4px;">
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($sessions as $sDate => $sStatus):
                                                    $sUp = strtoupper(trim($sStatus));
                                                    if ($sUp === 'P' || in_array($sUp, ['MUSIC SUMMIT','KIDS SUMMIT'])) {
                                                        $sBg = 'bg-success';
                                                    } elseif ($sUp === 'A') {
                                                        $sBg = 'bg-danger';
                                                    } elseif ($sUp === 'L') {
                                                        $sBg = 'bg-warning text-dark';
                                                    } elseif ($sUp === 'NC') {
                                                        $sBg = 'bg-light text-muted border';
                                                    } else {
                                                        // NO CLASS, HOLY WEEK, DC 2023, etc.
                                                        $sBg = 'bg-secondary';
                                                    }
                                                    $sLabel = $sUp === 'NC' ? 'NC' : htmlspecialchars($sStatus);
                                                ?>
                                                <span class="badge <?php echo $sBg; ?>"
                                                      title="<?php echo htmlspecialchars($sDate) . ': ' . htmlspecialchars($sStatus); ?>"
                                                      style="font-size:9px;">
                                                    <?php echo htmlspecialchars($sDate); ?>
                                                    <?php if ($sUp === 'A'): ?><i class="bi bi-x-circle-fill ms-1"></i><?php endif; ?>
                                                </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 mt-1" style="font-size:10px;">
                                                <span class="text-success"><i class="bi bi-circle-fill"></i> Present/Summit</span>
                                                <span class="text-danger"><i class="bi bi-circle-fill"></i> Absent</span>
                                                <span class="text-warning"><i class="bi bi-circle-fill"></i> Late</span>
                                                <span class="text-muted"><i class="bi bi-circle-fill"></i> NC (2nd Sem)</span>
                                                <span class="text-secondary"><i class="bi bi-circle-fill"></i> No Class</span>
                                            </div>
                                            <?php if ($remarks): ?>
                                            <div class="text-muted mt-1" style="font-size:10px;"><i class="bi bi-chat-left-text me-1"></i><?php echo htmlspecialchars($remarks); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isComplete): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Complete</span>
                                        <?php elseif ($total > 0): ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle me-1"></i><?php echo $absentCnt; ?> absent</span>
                                        <?php else: ?>
                                        <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rec['member_id'] && $rec['member_name']): ?>
                                        <a href="index.php?action=memberProfile&id=<?php echo $rec['member_id']; ?>"
                                           class="text-decoration-none small">
                                            <i class="bi bi-person-fill me-1 text-success"></i><?php echo htmlspecialchars($rec['member_name']); ?>
                                            <?php if ($rec['member_ministry']): ?>
                                            <div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars(strtoupper($rec['member_ministry'])); ?></div>
                                            <?php endif; ?>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted small"><i class="bi bi-question-circle me-1"></i>Unmatched</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $recStatus === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($recStatus); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php
                                            $_l113Json = [
                                                'id'             => $rec['id'],
                                                'raw_first_name' => $rec['raw_first_name'],
                                                'raw_last_name'  => $rec['raw_last_name'],
                                                'program_type'   => $rec['program_type'],
                                                'program_year'   => $rec['program_year'],
                                                'contact_number' => $rec['contact_number'],
                                                'member_id'       => $rec['member_id'],
                                                'member_name'     => $rec['member_name'] ?? '',
                                                'member_ministry' => $rec['member_ministry'] ?? '',
                                                'l113_batch'      => $batch,
                                                'sessions'       => $sessions,
                                            ];
                                        ?>
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditL113Modal(<?php echo htmlspecialchars(json_encode($_l113Json)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-info me-1" title="Duplicate (copy year / batch / sessions, then change the name)"
                                            onclick="openDuplicateL113Modal(<?php echo htmlspecialchars(json_encode($_l113Json)); ?>)">
                                            <i class="bi bi-files"></i>
                                        </button>
                                        <?php if ($recStatus === 'active'): ?>
                                        <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                           href="index.php?action=deactivateL113Record&id=<?php echo $rec['id']; ?>"
                                           onclick="return confirm('Deactivate this record?')">
                                            <i class="bi bi-pause-circle"></i>
                                        </a>
                                        <?php else: ?>
                                        <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                           href="index.php?action=activateL113Record&id=<?php echo $rec['id']; ?>">
                                            <i class="bi bi-play-circle"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-danger" title="Delete"
                                           href="index.php?action=deleteL113Record&id=<?php echo $rec['id']; ?>"
                                           onclick="return confirm('Permanently delete this record? This cannot be undone.');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add L113 Modal -->
            <div class="modal fade" id="addL113Modal" tabindex="-1">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Leadership 113 Record</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="index.php?action=addL113Record" id="addL113Form">
                        <div class="modal-body">
                            <?php include 'shared/l113_form_fields.php'; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger"><i class="bi bi-save me-1"></i>Save Record</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Edit L113 Modal -->
            <div class="modal fade" id="editL113Modal" tabindex="-1">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Leadership 113 Record</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" id="editL113Form" action="">
                        <div class="modal-body">
                            <?php include 'shared/l113_form_fields.php'; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
                        </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php elseif ($activeTab === 'stats'): ?>
            <?php
            /* ── Prepare chart data ── */
            $_batchLabels       = [];
            $_batchCompleted    = [];
            $_batchNotCompleted = [];
            foreach ($batchStats as $bs) {
                $_batchLabels[]       = $bs['batch'] . ' (' . $bs['year'] . ')';
                $_batchCompleted[]    = (int)$bs['completed'];
                $_batchNotCompleted[] = (int)$bs['notCompleted'];
            }
            $l113Pct = ($totalRecords > 0) ? round($totalComplete / $totalRecords * 100) : 0;
            ?>
            <script>
            var l113BatchLabels       = <?php echo json_encode($_batchLabels); ?>;
            var l113BatchCompleted    = <?php echo json_encode($_batchCompleted); ?>;
            var l113BatchNotCompleted = <?php echo json_encode($_batchNotCompleted); ?>;
            </script>

            <!-- L113 Summary Cards -->
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-3">
                    <div class="card h-100 border-danger">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-danger mb-0"><?php echo $totalRecords; ?></div>
                            <div class="small text-muted mt-1">Total Participants</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-success mb-0"><?php echo $totalComplete; ?></div>
                            <div class="small text-muted mt-1">Completed (0 absences)</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-warning mb-0"><?php echo $totalNotComplete; ?></div>
                            <div class="small text-muted mt-1">Not Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <div class="display-6 fw-bold text-secondary mb-0"><?php echo $totalMembers; ?></div>
                            <div class="small text-muted mt-1">Linked to Members</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-bar-chart-fill me-2"></i>Completion per Batch</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('l113CompletionChart','l113_completion_per_batch')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="l113CompletionChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <span class="small fw-bold"><i class="bi bi-people-fill me-2"></i>Participants per Batch</span>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('l113ParticipantsChart','l113_participants_per_batch')">
                                <i class="bi bi-download me-1"></i>PNG
                            </button>
                        </div>
                        <div class="card-body">
                            <canvas id="l113ParticipantsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Batch Summary Table -->
            <?php if (!empty($batchStats)): ?>
            <div class="card">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold"><i class="bi bi-table me-2"></i>Batch Summary</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light" onclick="exportL113BatchCsv()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportL113BatchExcel()" title="Export Excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportL113BatchPdf()" title="Export PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</button>
                        <button class="btn btn-sm btn-outline-light" onclick="printL113Batch()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="l113BatchTable">
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
                                <?php foreach ($batchStats as $bs):
                                    $bsPct = $bs['total'] > 0 ? round($bs['completed'] / $bs['total'] * 100) : 0;
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($bs['batch']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo $bs['year']; ?></span></td>
                                    <td class="text-center fw-bold"><?php echo $bs['total']; ?></td>
                                    <td class="text-center text-success fw-semibold"><?php echo $bs['completed']; ?></td>
                                    <td class="text-center text-warning fw-semibold"><?php echo $bs['notCompleted']; ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $bsPct >= 80 ? 'success' : ($bsPct >= 50 ? 'warning' : 'danger'); ?>">
                                            <?php echo $bsPct; ?>%
                                        </span>
                                    </td>
                                    <td style="min-width:120px">
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar bg-<?php echo $bsPct >= 80 ? 'success' : ($bsPct >= 50 ? 'warning' : 'danger'); ?>"
                                                 style="width:<?php echo $bsPct; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="table-secondary fw-bold">
                                    <td>Total</td>
                                    <td></td>
                                    <td class="text-center"><?php echo $totalRecords; ?></td>
                                    <td class="text-center text-success"><?php echo $totalComplete; ?></td>
                                    <td class="text-center text-warning"><?php echo $totalNotComplete; ?></td>
                                    <td class="text-center"><?php echo $l113Pct; ?>%</td>
                                    <td>
                                        <div class="progress" style="height:8px;">
                                            <div class="progress-bar bg-<?php echo $l113Pct >= 80 ? 'success' : ($l113Pct >= 50 ? 'warning' : 'danger'); ?>"
                                                 style="width:<?php echo $l113Pct; ?>%"></div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; /* end records/stats tab */ ?>

        </div>
    </div>

<script>
function toggleGrid(id) {
    var g = document.getElementById(id);
    if (g) g.style.display = g.style.display === 'none' ? 'block' : 'none';
}

function openEditL113Modal(rec) {
    var form = document.getElementById('editL113Form');
    form.action = 'index.php?action=updateL113Record&id=' + rec.id;

    // Plain text inputs
    var plainFields = {
        raw_first_name: rec.raw_first_name,
        raw_last_name:  rec.raw_last_name,
        contact_number: rec.contact_number,
    };
    for (var k in plainFields) {
        var el = form.querySelector('[name="' + k + '"]');
        if (el) el.value = plainFields[k] || '';
    }

    // Helper: set a Select2 field value (add option if not present)
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

    setSelect2('program_year',  String(rec.program_year), String(rec.program_year));
    setSelect2('program_type',  rec.program_type,   rec.program_type);
    setSelect2('l113_batch',    rec.l113_batch,     rec.l113_batch);

    // Linked member Select2 (AJAX)
    var $memberSel = $(form).find('[name="member_id"]');
    if ($memberSel.length) {
        if (rec.member_id) {
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
    }

    // Populate session rows
    var tbody = form.querySelector('.session-tbody');
    if (tbody) {
        tbody.innerHTML = '';
        if (rec.sessions && typeof rec.sessions === 'object') {
            for (var d in rec.sessions) { addSessionRow(tbody, toDateInputVal(d), rec.sessions[d]); }
        }
    }

    var modalEl = document.getElementById('editL113Modal');
    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modal.show();
}

// Duplicate flow — opens the Add L113 modal pre-filled with another row's class / year / batch /
// session-date columns, but with the name and member-link cleared so the next person can be added
// quickly. Keeps the session DATES (P/A/L statuses default to 'P' for the new person).
function openDuplicateL113Modal(rec) {
    var form = document.getElementById('addL113Form');
    if (!form) { console.error('addL113Form not found'); return; }

    // Clear identity fields
    var first   = form.querySelector('[name="raw_first_name"]'); if (first)   first.value   = '';
    var last    = form.querySelector('[name="raw_last_name"]');  if (last)    last.value    = '';
    var contact = form.querySelector('[name="contact_number"]'); if (contact) contact.value = '';

    // Set the underlying Select2 values, injecting options as needed.
    function setSel(name, value, label) {
        var $sel = $(form).find('[name="' + name + '"]');
        if (!$sel.length) return;
        value = value || '';
        label = label || value;
        if (value && !$sel.find('option[value="' + value + '"]').length) {
            $sel.append(new Option(label, value, false, false));
        }
        $sel.val(value || null);
    }
    setSel('program_year', String(rec.program_year || ''), String(rec.program_year || ''));
    setSel('program_type', rec.program_type || 'leadership_113', rec.program_type || 'leadership_113');
    setSel('l113_batch',   rec.l113_batch   || '',                rec.l113_batch   || '');

    // Linked member cleared (new person). Drop leftover options from a previous match.
    var $mem = $(form).find('[name="member_id"]');
    $mem.find('option').not('[value=""]').not('[value="__unmatched__"]').remove();
    $mem.val(null);
    // Hide any stale "Auto-linked" notice.
    $(form).find('.l113-auto-link-notice').hide();

    // Refresh every Select2 chip so the visible UI matches the underlying values.
    ['program_year', 'program_type', 'l113_batch', 'member_id'].forEach(function(name) {
        var $el = $(form).find('[name="' + name + '"]');
        if ($el.hasClass('select2-hidden-accessible')) $el.trigger('change.select2');
    });

    // Copy session DATES (statuses reset to 'P' for the new person).
    var tbody = form.querySelector('.session-tbody');
    if (tbody) {
        tbody.innerHTML = '';
        if (rec.sessions && typeof rec.sessions === 'object') {
            for (var d in rec.sessions) { addSessionRow(tbody, toDateInputVal(d), 'P'); }
        }
    }

    var modalEl = document.getElementById('addL113Modal');
    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modal.show();
    setTimeout(function() {
        var fn = form.querySelector('[name="raw_first_name"]');
        if (fn) fn.focus();
    }, 350);
}

function toDateInputVal(d) {
    if (!d) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(d)) return d;
    // Convert MM/DD/YYYY → YYYY-MM-DD
    var parts = d.split('/');
    if (parts.length === 3 && parts[2].length === 4) {
        return parts[2] + '-' + parts[0].padStart(2,'0') + '-' + parts[1].padStart(2,'0');
    }
    return d;
}

function addSessionRow(tbody, date, status) {
    if (!tbody) return;
    // Defense-in-depth: escape every value going into innerHTML — date keys and status
    // strings ultimately come from DB rows that an admin can edit.
    function escAttr(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    var tr = document.createElement('tr');
    var current = (status || 'P').trim();
    tr.innerHTML =
        '<td><input type="date" name="session_dates[]" class="form-control form-control-sm" value="' + escAttr(date || '') + '" style="min-width:140px"></td>' +
        '<td><input type="text" name="session_statuses[]" list="l113StatusOpts" class="form-control form-control-sm" value="' + escAttr(current) + '" placeholder="Status…" autocomplete="off"></td>' +
        '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove()"><i class="bi bi-trash"></i></button></td>';
    tbody.appendChild(tr);
}

function addNewSessionRow() {
    var modal  = document.querySelector('#addL113Modal:not([style*="display: none"])') || document.getElementById('addL113Modal');
    // Determine which modal is open
    var openModal = document.querySelector('.modal.show');
    var form  = openModal ? openModal.querySelector('form') : null;
    var tbody = form ? form.querySelector('.session-tbody') : null;
    if (tbody) addSessionRow(tbody, '', 'P');
}

// ── Auto-link L113 participant to an existing member by exact name match ──
// Mirrors the attendance-form behavior. Fires on focusout of either name input.
function l113TryAutoLinkMember($form) {
    var first = ($form.find('.l113-first').val() || '').trim();
    var last  = ($form.find('.l113-last').val()  || '').trim();
    if (!first || !last) return;
    var $mem = $form.find('.l113-member-select2');
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
        var $notice = $form.find('.l113-auto-link-notice');
        if (!$notice.length) {
            $notice = $('<div class="l113-auto-link-notice text-success small mt-1"><i class="bi bi-link-45deg me-1"></i>Auto-linked to existing member: <strong></strong>.</div>');
            $mem.closest('.col-12').append($notice);
        }
        $notice.find('strong').text(m.name);
        $notice.show();
    });
}
// `focusout` bubbles (blur doesn't) — needed for document-level delegation to fire reliably,
// especially when the input is inside a modal that was just opened dynamically.
$(document).on('focusout', '.l113-first, .l113-last', function() {
    l113TryAutoLinkMember($(this).closest('form'));
});

function _l113TableData() {
    var headers = ['Participant','Year','Batch','Contact #','Sessions','Completion','Matched Member','Status'];
    var rows = [];
    document.querySelectorAll('#l113Table tbody tr').forEach(function(tr) {
        if (tr.style.display === 'none') return;
        var tds = tr.querySelectorAll('td');
        if (tds.length < 9) return;
        // Build session text: summary (e.g. 15/17) then full date list on next line
        var sessEl = tds[5].querySelector('.text-muted.small');
        var summary = sessEl ? sessEl.textContent.trim() : '';
        var dateParts = '';
        var sessAttr = tr.getAttribute('data-sessions');
        if (sessAttr) {
            try {
                var sess = JSON.parse(sessAttr);
                var parts = [];
                for (var date in sess) { parts.push(date + ': ' + sess[date]); }
                dateParts = parts.join(' | ');
            } catch(e) {}
        }
        var sessText = summary && dateParts ? summary + '\n' + dateParts : (summary || dateParts);
        rows.push([
            tds[1].textContent.trim(),
            tds[2].textContent.trim(),
            tds[3].textContent.trim().replace(/\s+/g,' '),
            tds[4].textContent.trim(),
            sessText,
            tds[6].textContent.trim(),
            tds[7].textContent.trim(),
            tds[8].textContent.trim(),
        ]);
    });
    return {headers: headers, rows: rows};
}

function exportL113Csv() {
    var d = _l113TableData();
    var lines = [d.headers.map(function(h){return '"'+h.replace(/"/g,'""')+'"';}).join(',')];
    d.rows.forEach(function(r){
        lines.push(r.map(function(c){return '"'+(c||'').replace(/"/g,'""')+'"';}).join(','));
    });
    var blob = new Blob([lines.join('\r\n')], {type:'text/csv'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'l113_records.csv'; a.click();
}

function exportL113Excel() {
    var d = _l113TableData();
    if (typeof XLSX !== 'undefined') {
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'L113 Records');
        XLSX.writeFile(wb, 'l113_records.xlsx');
    } else {
        exportL113Csv();
    }
}

function exportL113Pdf() {
    var d = _l113TableData();
    var JsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : (typeof jsPDF !== 'undefined' ? jsPDF : null);
    if (JsPDF) {
        var doc = new JsPDF({orientation:'landscape'});
        doc.autoTable({head:[d.headers], body:d.rows, styles:{fontSize:7}, headStyles:{fillColor:[192,57,43]}});
        doc.save('l113_records.pdf');
    } else {
        alert('PDF export library not loaded.');
    }
}

function printL113() {
    var d = _l113TableData();
    var html = '<html><head><title>Leadership 1-1-3 Records</title>';
    html += '<style>body{font-family:sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;vertical-align:top}th{background:#c0392b;color:#fff}tr:nth-child(even){background:#f9f9f9}.sess-dates{font-size:9px;color:#555;margin-top:2px}</style>';
    html += '</head><body><h2 style="color:#c0392b">Leadership 1-1-3 Records</h2>';
    html += '<table><thead><tr>' + d.headers.map(function(h){return '<th>'+h+'</th>';}).join('') + '</tr></thead><tbody>';
    d.rows.forEach(function(r){
        html += '<tr>' + r.map(function(c, i) {
            if (i === 4 && c && c.indexOf('\n') !== -1) {
                var lines = c.split('\n');
                return '<td><strong>' + lines[0] + '</strong><div class="sess-dates">' + (lines[1] || '').replace(/\s*\|\s*/g, '<br>') + '</div></td>';
            }
            return '<td>' + (c || '') + '</td>';
        }).join('') + '</tr>';
    });
    html += '</tbody></table></body></html>';
    var w = window.open('','_blank'); w.document.write(html); w.document.close(); w.print();
}

// ── Batch Summary table export helpers ────────────────────────────────────────
function _l113BatchTableData() {
    var headers = ['Batch','Year','Total','Completed','Not Completed','Completion %'];
    var rows = [];
    document.querySelectorAll('#l113BatchTable tbody tr').forEach(function(tr) {
        var tds = tr.querySelectorAll('td');
        if (tds.length < 6) return;
        rows.push([
            tds[0].textContent.trim(),
            tds[1].textContent.trim(),
            tds[2].textContent.trim(),
            tds[3].textContent.trim(),
            tds[4].textContent.trim(),
            tds[5].textContent.trim(),
        ]);
    });
    return {headers: headers, rows: rows};
}
function exportL113BatchCsv() {
    var d = _l113BatchTableData();
    var lines = [d.headers.map(function(h){return '"'+h.replace(/"/g,'""')+'"';}).join(',')];
    d.rows.forEach(function(r){lines.push(r.map(function(c){return '"'+(c||'').replace(/"/g,'""')+'"';}).join(','));});
    var blob = new Blob([lines.join('\r\n')], {type:'text/csv'});
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
    a.download = 'l113_batch_summary.csv'; a.click();
}
function exportL113BatchExcel() {
    var d = _l113BatchTableData();
    if (typeof XLSX !== 'undefined') {
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'L113 Batch Summary');
        XLSX.writeFile(wb, 'l113_batch_summary.xlsx');
    } else { exportL113BatchCsv(); }
}
function exportL113BatchPdf() {
    var d = _l113BatchTableData();
    var JsPDF = (window.jspdf && window.jspdf.jsPDF) ? window.jspdf.jsPDF : (typeof jsPDF !== 'undefined' ? jsPDF : null);
    if (JsPDF) {
        var doc = new JsPDF({orientation:'landscape'});
        doc.autoTable({head:[d.headers], body:d.rows, styles:{fontSize:8}, headStyles:{fillColor:[192,57,43]}});
        doc.save('l113_batch_summary.pdf');
    } else { alert('PDF export library not loaded.'); }
}
function printL113Batch() {
    var d = _l113BatchTableData();
    var html = '<html><head><title>L113 Batch Summary</title>';
    html += '<style>body{font-family:sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px}th{background:#c0392b;color:#fff}tr:nth-child(even){background:#f9f9f9}</style>';
    html += '</head><body><h2 style="color:#c0392b">Leadership 1-1-3 — Batch Summary</h2>';
    html += '<table><thead><tr>' + d.headers.map(function(h){return '<th>'+h+'</th>';}).join('') + '</tr></thead><tbody>';
    d.rows.forEach(function(r){ html += '<tr>'+r.map(function(c){return '<td>'+(c||'')+'</td>';}).join('')+'</tr>'; });
    html += '</tbody></table></body></html>';
    var w = window.open('','_blank'); w.document.write(html); w.document.close(); w.print();
}

// ── Chart PNG export helper ────────────────────────────────────────────────────
function exportChartPng(canvasId, filename) {
    var canvas = document.getElementById(canvasId);
    if (!canvas) return;
    var link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = (filename || canvasId) + '_' + new Date().toISOString().slice(0,10) + '.png';
    link.click();
}
</script>
<?php include 'shared/footer.php'; ?>
<script>
$(function() {
    // Match-status filter state + DataTable search hook (L113-only).
    // Persisted across server-side filter navigation (year/batch) via the `match` URL parameter.
    window.l113MatchFilter = <?php echo json_encode($activeMatch ?? 'all'); ?>;
    window.updateL113FilterBadge = function() {
        var serverSide = <?php echo (int)$l113FilterCount; ?>;
        var n = serverSide;
        if (window.l113MatchFilter && window.l113MatchFilter !== 'all') n++;
        // Note: page search input is part of $l113FilterCount only via the URL `search` param,
        // not the client-side DataTable search box (which doesn't trigger a URL refresh).
        var liveSearch = (document.getElementById('l113Search') || {}).value || '';
        var serverSearch = <?php echo json_encode($activeSearch); ?>;
        if (liveSearch.trim() && liveSearch !== serverSearch) n++;
        var badge = document.getElementById('l113ActiveFilterBadge');
        if (badge) {
            badge.textContent = n + ' active';
            badge.style.display = n > 0 ? '' : 'none';
        }
        var clearBtn = document.getElementById('l113ClearFiltersBtn');
        if (clearBtn) clearBtn.style.display = n > 0 ? '' : 'none';
    };

    window.filterL113MatchStatus = function(status) {
        window.l113MatchFilter = status || 'all';
        document.querySelectorAll('.l113-match-filter-btn').forEach(function(btn) {
            var active = btn.dataset.match === window.l113MatchFilter;
            var base = btn.dataset.match === 'matched' ? 'success' : (btn.dataset.match === 'unmatched' ? 'secondary' : 'primary');
            btn.className = btn.className.replace(/\bbtn-(outline-)?[a-z]+\b/g, '').replace(/\s+/g, ' ').trim();
            btn.classList.add('btn', 'btn-sm', 'l113-match-filter-btn', active ? 'btn-' + base : 'btn-outline-' + base);
        });
        // Persist into URL so picking another (server-side) filter afterwards keeps this state.
        try {
            var u = new URL(window.location.href);
            if (window.l113MatchFilter === 'all') u.searchParams.delete('match');
            else u.searchParams.set('match', window.l113MatchFilter);
            history.replaceState(null, '', u);
        } catch(e) {}
        // Defensive: re-fetch the DataTable API by id in case window.l113Table was stale or wrong type.
        try {
            if ($.fn.DataTable && $.fn.DataTable.isDataTable('#l113Table')) {
                $('#l113Table').DataTable().draw();
            }
        } catch(e) {}
        window.updateL113FilterBadge();
    };
    document.querySelectorAll('.l113-match-filter-btn').forEach(function(btn) {
        btn.addEventListener('click', function() { window.filterL113MatchStatus(btn.dataset.match); });
    });

    // Inject the current match filter into every server-side filter link in the L113 Search & Filter card,
    // so clicking a year/batch link doesn't drop the active match state.
    document.querySelectorAll('#l113FilterBody a[href*="action=leadership113"]').forEach(function(a) {
        var orig = a.getAttribute('href');
        a.addEventListener('click', function() {
            if (!window.l113MatchFilter || window.l113MatchFilter === 'all') return;
            var href = orig;
            if (!/[?&]match=/.test(href)) {
                href += (href.indexOf('?') > -1 ? '&' : '?') + 'match=' + encodeURIComponent(window.l113MatchFilter);
                a.setAttribute('href', href);
            }
        });
    });

    // Apply current match-filter button visual state on initial load (reflects the value restored from URL)
    window.filterL113MatchStatus(window.l113MatchFilter);
    // Initial filter badge
    window.updateL113FilterBadge();

    // DataTable
    if (typeof $.fn.DataTable !== 'undefined' && $('#l113Table').length) {
        window.l113Table = $('#l113Table').DataTable({
            responsive: true, pageLength: 25, searching: true,
            dom: 'rt<"d-flex justify-content-between align-items-center mt-2 px-2"ip>',
            order: [[1, 'asc']], // sort by Participant name
            columnDefs: [
                { targets: 0, orderable: false, searchable: false }
            ],
            drawCallback: function() {
                // Re-number the # column to match current sorted/paged order
                this.api().column(0, { page: 'current' }).nodes().each(function(cell, i) {
                    cell.innerHTML = (i + 1);
                });
                // Real-time count badge — reflects the post-filter row count
                var info = this.api().page.info();
                var b1 = document.getElementById('l113CountBadge');
                if (b1) b1.textContent = info.recordsDisplay;
            }
        });

        // Match-status search hook — registered AFTER table init so settings.nTable resolves correctly.
        $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
            if (!settings.nTable || settings.nTable.id !== 'l113Table') return true;
            if (!window.l113MatchFilter || window.l113MatchFilter === 'all') return true;
            var row  = settings.aoData[dataIndex];
            var node = row && row.nTr;
            if (!node) return true;
            var matched = node.getAttribute('data-matched');
            if (window.l113MatchFilter === 'matched'   && matched !== '1') return false;
            if (window.l113MatchFilter === 'unmatched' && matched !== '0') return false;
            return true;
        });
        // Apply initial match filter (might have been restored from URL)
        try { $('#l113Table').DataTable().draw(); } catch(e) {}
        $('#l113PerPage').on('change', function() {
            try { $('#l113Table').DataTable().page.len(parseInt($(this).val())).draw(); } catch(e) {}
        });
        var timer;
        $('#l113Search').on('input', function() {
            var val = $(this).val();
            clearTimeout(timer);
            timer = setTimeout(function() {
                try { $('#l113Table').DataTable().search(val).draw(); } catch(e) {}
                var b = document.getElementById('l113SearchBadge');
                if (b) { b.style.display = val ? '' : 'none'; b.textContent = val ? '\u201c' + val + '\u201d' : ''; }
                if (typeof window.updateL113FilterBadge === 'function') window.updateL113FilterBadge();
            }, 250);
        });
    }

    // L113 Charts
    if (typeof Chart !== 'undefined' && typeof l113BatchLabels !== 'undefined' && l113BatchLabels.length) {
        var ctx1 = document.getElementById('l113CompletionChart');
        if (ctx1) {
            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: l113BatchLabels,
                    datasets: [
                        {label:'Completed',     data: l113BatchCompleted,    backgroundColor:'rgba(40,167,69,0.8)',  borderColor:'rgba(40,167,69,1)',  borderWidth:1},
                        {label:'Not Completed', data: l113BatchNotCompleted, backgroundColor:'rgba(255,193,7,0.8)', borderColor:'rgba(255,193,7,1)', borderWidth:1},
                    ]
                },
                options: {
                    responsive: true,
                    plugins: {legend:{position:'bottom'}},
                    scales: {x:{stacked:true}, y:{stacked:true, beginAtZero:true, ticks:{stepSize:1, precision:0}}},
                }
            });
        }
        var ctx2 = document.getElementById('l113ParticipantsChart');
        if (ctx2) {
            var totals = l113BatchCompleted.map(function(c,i){return c + l113BatchNotCompleted[i];});
            new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: l113BatchLabels,
                    datasets: [{
                        label:'Total Participants',
                        data: totals,
                        backgroundColor:'rgba(220,53,69,0.7)',
                        borderColor:'rgba(220,53,69,1)',
                        borderWidth:1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {legend:{position:'bottom'}},
                    scales: {y:{beginAtZero:true, ticks:{stepSize:1}}},
                }
            });
        }
    }

    // Select2 for all L113 modal fields
    if (typeof $.fn.select2 !== 'undefined') {
        // Class (program_type)
        $('.l113-program-select2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: '\u2014 Select Class \u2014',
                minimumResultsForSearch: Infinity,
            });
        });

        // Year (tags — can type new)
        $('.l113-year-select2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Year\u2026',
                tags: true,
                minimumResultsForSearch: 0,
            });
        });

        // Batch Name (tags — loaded from DB)
        $('.l113-batch-select2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Select or type batch name\u2026',
                allowClear: true,
                tags: true,
            });
        });

        // Linked member (AJAX search)
        $('.l113-member-select2').each(function() {
            $(this).select2({
                dropdownParent: $(this).closest('.modal'),
                placeholder: 'Search or browse members\u2026',
                allowClear: true,
                minimumInputLength: 0,
                language: {
                    noResults:    function() { return 'No members found.'; },
                    searching:    function() { return 'Searching\u2026'; },
                    loadingMore:  function() { return 'Loading more\u2026'; },
                    errorLoading: function() { return 'Could not load results.'; },
                },
                ajax: {
                    url: 'index.php?action=ajaxSearchMembers',
                    dataType: 'json',
                    delay: 200,
                    data: function(p) { return { q: p.term || '' }; },
                    processResults: function(d) {
                        return { results: (d && d.results) ? d.results : [] };
                    },
                    cache: true,
                }
            });
        });

        // "Mark as Unmatched" button — selects a visible "Unmatched" sentinel option
        // in the linked-member Select2. On form submit the sentinel is converted to ""
        // so the controller stores NULL.
        $(document).on('click', '.l113-clear-member', function() {
            var $form = $(this).closest('form');
            var $sel  = $form.find('.l113-member-select2');
            if (!$sel.length) return;
            if (!$sel.find('option[value="__unmatched__"]').length) {
                $sel.append(new Option('Unmatched (not yet a member)', '__unmatched__', false, false));
            }
            $sel.val('__unmatched__').trigger('change');
        });

        // Before submit: replace the sentinel with empty so the server sees member_id=""
        $(document).on('submit', 'form', function() {
            var $sel = $(this).find('.l113-member-select2');
            if ($sel.length && $sel.val() === '__unmatched__') {
                $sel.val('').trigger('change');
            }
        });
    }
});
</script>
