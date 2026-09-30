<?php
// Spiritual Foundations — Search & Filter panel.
// Requires in scope: $availableYears, $paStats, $batchesForYear, $sfWeeks,
//                    $activeYear, $activeBatch, $activeSearch, $activeSession,
//                    $activeDateFrom, $activeDateTo, $sfFilterCount, $sfClearUrl
$_keep = function (array $skip = []) use ($activeYear, $activeBatch, $activeSearch, $activeDateFrom, $activeDateTo, $activeSession) {
    $q = [
        'year'            => $activeYear,
        'batch'           => $activeBatch,
        'search'          => $activeSearch,
        'event_date_from' => $activeDateFrom,
        'event_date_to'   => $activeDateTo,
        'session'         => $activeSession,
    ];
    $out = '';
    foreach ($q as $k => $v) {
        if ($v === '' || in_array($k, $skip, true)) continue;
        $out .= '&' . $k . '=' . urlencode((string)$v);
    }
    return $out;
};
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="text-white fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-funnel me-2"></i>Search & Filter
            <span class="badge bg-white text-info" id="sfActiveFilterBadge" style="display:none">0 active</span>
        </span>
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo $sfClearUrl; ?>" class="btn btn-sm btn-outline-light" id="sfClearFiltersBtn"
               style="<?php echo $sfFilterCount > 0 ? '' : 'display:none'; ?>">
                <i class="bi bi-x-circle me-1"></i>Clear Filters
            </a>
            <a href="index.php?action=attendanceRecords" class="btn btn-sm btn-outline-light">
                <i class="bi bi-layout-three-columns me-1"></i>All Classes
            </a>
            <button class="btn btn-sm btn-outline-light" type="button"
                    data-bs-toggle="collapse" data-bs-target="#sfFilterBody" aria-expanded="true">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
    </div>
    <div class="collapse show" id="sfFilterBody">
    <div class="card-body py-3">

        <!-- Search -->
        <div class="input-group mb-3">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="sfSearch" class="form-control"
                   value="<?php echo htmlspecialchars($activeSearch); ?>"
                   placeholder="Search name, batch…" autocomplete="off">
            <button class="btn btn-outline-secondary" type="button" id="sfSearchClear"><i class="bi bi-x"></i></button>
        </div>

        <!-- Topic / Session filter -->
        <div class="border-top pt-3">
            <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                <i class="bi bi-journal-text me-1"></i>Filter by Topic / Session
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-md-7">
                    <select id="sfTopicFilter" class="form-select form-select-sm">
                        <option value="">— All topics / sessions —</option>
                        <?php foreach ($sfWeeks as $wk => $row): ?>
                        <option value="<?php echo (int)$wk; ?>" <?php echo ((string)$activeSession === (string)$wk) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(SfTopic::weekLabel($row)); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <select id="sfTopicStatusFilter" class="form-select form-select-sm">
                        <option value="">Any attendance status</option>
                        <option value="present">Present</option>
                        <option value="late">Late</option>
                        <option value="attended">Completed (Present or Late)</option>
                        <option value="absent">Absent</option>
                        <option value="nc">NC — Not required of this participant</option>
                    </select>
                </div>
            </div>
            <div class="form-text">Shows only participants whose record covers the selected week, with the status you pick.</div>
        </div>

        <!-- Year filter -->
        <?php if (!empty($availableYears)):
            $_sfYearCounts = $paStats['spiritual_foundations'] ?? [];
            $_sfTotalCount = array_sum($_sfYearCounts);
        ?>
        <div class="border-top pt-3 mt-2">
            <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                <i class="bi bi-calendar me-1"></i>Filter by Year
            </div>
            <div class="d-flex align-items-center flex-wrap gap-2">
                <a href="index.php?action=spiritualFoundations<?php echo $_keep(['year', 'batch']); ?>"
                   class="btn btn-sm <?php echo !$activeYear ? 'btn-info text-white' : 'btn-outline-info'; ?>">
                    All Years <span class="badge bg-white text-info ms-1"><?php echo (int)$_sfTotalCount; ?></span>
                </a>
                <?php foreach ($availableYears as $yr): ?>
                <a href="index.php?action=spiritualFoundations&year=<?php echo (int)$yr; ?><?php echo $_keep(['year', 'batch']); ?>"
                   class="btn btn-sm <?php echo (string)$activeYear === (string)$yr ? 'btn-info text-white' : 'btn-outline-info'; ?>">
                    <?php echo (int)$yr; ?> <span class="badge bg-white text-info ms-1"><?php echo (int)($_sfYearCounts[(int)$yr] ?? 0); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Batch filter -->
        <?php if (!empty($batchesForYear)): ?>
        <div class="border-top pt-3 mt-2">
            <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                <i class="bi bi-collection me-1"></i>Filter by Batch
            </div>
            <div class="d-flex align-items-center flex-wrap gap-2">
                <a href="index.php?action=spiritualFoundations<?php echo $_keep(['batch']); ?>"
                   class="btn btn-sm <?php echo !$activeBatch ? 'btn-info text-white' : 'btn-outline-info'; ?>">All Batches</a>
                <?php foreach ($batchesForYear as $bl): ?>
                <a href="index.php?action=spiritualFoundations&batch=<?php echo urlencode($bl); ?><?php echo $_keep(['batch']); ?>"
                   class="btn btn-sm <?php echo $activeBatch === $bl ? 'btn-info text-white' : 'btn-outline-info'; ?>">
                    <?php echo htmlspecialchars($bl); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Certificate + match filters -->
        <div class="border-top pt-3 mt-2">
            <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                <i class="bi bi-award me-1"></i>Filter by Certificate Eligibility
                <span class="text-lowercase fw-normal">(overall, across all batches)</span>
            </div>
            <div class="d-flex align-items-center flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-info text-white sf-cert-filter-btn" data-cert="all">All</button>
                <button type="button" class="btn btn-sm btn-outline-success sf-cert-filter-btn" data-cert="eligible"><i class="bi bi-award me-1"></i>Eligible</button>
                <button type="button" class="btn btn-sm btn-outline-warning sf-cert-filter-btn" data-cert="not"><i class="bi bi-hourglass-split me-1"></i>Not Yet Eligible</button>
            </div>
        </div>
        <div class="border-top pt-3 mt-2">
            <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                <i class="bi bi-link-45deg me-1"></i>Filter by Match Status
            </div>
            <div class="d-flex align-items-center flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-primary sf-match-filter-btn" data-match="all">All Records</button>
                <button type="button" class="btn btn-sm btn-outline-success sf-match-filter-btn" data-match="matched"><i class="bi bi-person-check me-1"></i>Matched Only</button>
                <button type="button" class="btn btn-sm btn-outline-secondary sf-match-filter-btn" data-match="unmatched"><i class="bi bi-person-exclamation me-1"></i>Unmatched Only</button>
            </div>
        </div>

        <!-- Date range filter -->
        <div class="border-top pt-3 mt-2">
            <div class="small fw-semibold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                <i class="bi bi-calendar-range me-1"></i>Filter by Date Range
            </div>
            <form method="GET" action="index.php" class="d-flex flex-wrap gap-2 align-items-end">
                <input type="hidden" name="action" value="spiritualFoundations">
                <?php if ($activeYear): ?><input type="hidden" name="year" value="<?php echo htmlspecialchars($activeYear); ?>"><?php endif; ?>
                <?php if ($activeBatch): ?><input type="hidden" name="batch" value="<?php echo htmlspecialchars($activeBatch); ?>"><?php endif; ?>
                <?php if ($activeSearch): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($activeSearch); ?>"><?php endif; ?>
                <?php if ($activeSession !== ''): ?><input type="hidden" name="session" value="<?php echo htmlspecialchars($activeSession); ?>"><?php endif; ?>
                <div>
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="event_date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($activeDateFrom); ?>">
                </div>
                <div>
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="event_date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($activeDateTo); ?>">
                </div>
                <div class="d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-info text-white"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <?php if ($activeDateFrom || $activeDateTo): ?>
                    <a href="index.php?action=spiritualFoundations<?php echo $_keep(['event_date_from', 'event_date_to']); ?>"
                       class="btn btn-sm btn-outline-secondary">Clear Date</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    </div>
    </div>
</div>
