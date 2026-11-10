<?php
// Dashboard — Discipleship classes, average attendance.
//
// CLASS attendance (program_attendances: Victory Weekend, Church Community,
// Making Disciples...), not Sunday attendance. It used to sit at the top of
// Church Health, where a row of zeros after the 2026 reset read as "nobody
// came to church"; it now lives beside the Discipleship Journey Overview
// with its own Class / Year filter.
//
// Requires in scope: $attendanceAverages, $attendanceSeries, $dashClass,
//                    $dashYear, $dashBucket, $dashYearOptions, $dashUrl,
//                    $dashFilterCount, $paModel
$avgDefs = [
    'weekly'    => ['icon' => 'bi-calendar-week',  'color' => 'primary'],
    'monthly'   => ['icon' => 'bi-calendar-month', 'color' => 'success'],
    'quarterly' => ['icon' => 'bi-calendar3',      'color' => 'warning'],
    'annually'  => ['icon' => 'bi-calendar4',      'color' => 'danger'],
];
$bucketLabels = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annually' => 'Annually'];
// Any class record at all under the current filter? The annual bucket's
// total is the plain record count, so it doubles as the emptiness test.
$dcHasRecords = (int)($attendanceAverages['annually']['total'] ?? 0) > 0;
$dcScopeLabel = ($dashClass ? ProgramAttendance::PROGRAM_LABELS[$dashClass] . ' in ' : '') . ($dashYear ?: (int)date('Y'));
?>
<div class="card mb-4 mb-md-0" id="classAttendance">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0">
            <i class="bi bi-mortarboard me-2"></i>Discipleship classes &mdash; average attendance
            <?php if ($dashFilterCount): ?>
            <span class="badge bg-white text-primary ms-1"><?php echo $dashFilterCount; ?> filter<?php echo $dashFilterCount === 1 ? '' : 's'; ?></span>
            <?php endif; ?>
        </h6>
        <div class="d-flex align-items-center flex-wrap gap-2">
            <?php if ($dashFilterCount): ?>
            <a href="index.php#classAttendance" class="btn btn-sm btn-outline-light"><i class="bi bi-x-circle me-1"></i>Clear</a>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-light" type="button"
                    data-bs-toggle="collapse" data-bs-target="#dashFilterBody" aria-expanded="<?php echo $dashFilterCount ? 'true' : 'false'; ?>">
                <i class="bi bi-funnel me-1"></i>Class / Year
            </button>
        </div>
    </div>
    <!-- Class / Year filter -->
    <div class="collapse<?php echo $dashFilterCount ? ' show' : ''; ?>" id="dashFilterBody">
        <div class="card-body border-bottom py-3 bg-light">
            <form method="GET" action="index.php#classAttendance" class="row g-2 align-items-end">
                <input type="hidden" name="sunday_range" value="<?php echo htmlspecialchars($sundayRange); ?>">
                <div class="col-md-4">
                    <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Class</label>
                    <select name="dash_class" class="form-select form-select-sm">
                        <option value="">All classes</option>
                        <?php foreach (ProgramAttendance::PROGRAM_LABELS as $dhPt => $dhLbl): ?>
                        <option value="<?php echo $dhPt; ?>" <?php echo $dashClass === $dhPt ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dhLbl); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Year</label>
                    <select name="dash_year" class="form-select form-select-sm">
                        <option value="">All years</option>
                        <?php foreach ($dashYearOptions as $dhYr): ?>
                        <option value="<?php echo (int)$dhYr; ?>" <?php echo $dashYear === (int)$dhYr ? 'selected' : ''; ?>><?php echo (int)$dhYr; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Trend by</label>
                    <select name="dash_bucket" class="form-select form-select-sm">
                        <?php foreach ($bucketLabels as $dhBk => $dhBl): ?>
                        <option value="<?php echo $dhBk; ?>" <?php echo $dashBucket === $dhBk ? 'selected' : ''; ?>><?php echo $dhBl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card-body">
        <?php if (!$dcHasRecords): ?>
        <div class="text-center text-muted py-4">
            <i class="bi bi-mortarboard display-6 d-block mb-2 opacity-50"></i>
            <div class="fw-semibold">No class attendance recorded yet for <?php echo htmlspecialchars($dcScopeLabel); ?>.</div>
            <div class="small">Averages per week, month, quarter and year appear here once class attendance is added on
                <a href="index.php?action=attendanceRecords">Attendance Records</a>.</div>
        </div>
        <?php else: ?>
        <!-- ── Average attendance ── -->
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
            <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                <i class="bi bi-calendar-check me-1"></i>Average class attendance
            </span>
            <span class="text-muted" style="font-size:11px;">
                <?php echo $dashClass ? htmlspecialchars(ProgramAttendance::PROGRAM_LABELS[$dashClass]) : 'All classes'; ?>
                &middot; <?php echo $dashYear ?: 'All years'; ?>
            </span>
        </div>
        <div class="row g-2 mb-4">
            <?php foreach ($avgDefs as $dhKey => $dhDef):
                $dhAvg = $attendanceAverages[$dhKey] ?? ['avg' => 0, 'periods' => 0, 'total' => 0, 'label' => ucfirst($dhKey), 'unit' => ''];
            ?>
            <div class="col-6 col-lg-3">
                <a href="<?php echo htmlspecialchars($dashUrl(['dash_bucket' => $dhKey])); ?>"
                   class="card h-100 stat-card is-clickable text-decoration-none text-reset border-<?php echo $dhDef['color']; ?><?php echo $dashBucket === $dhKey ? ' border-2' : ' border-opacity-50'; ?>"
                   title="Show the trend by <?php echo strtolower($dhAvg['label']); ?>">
                    <div class="card-body py-2 px-2 text-center">
                        <i class="bi <?php echo $dhDef['icon']; ?> text-<?php echo $dhDef['color']; ?>"></i>
                        <div class="h4 fw-bold text-<?php echo $dhDef['color']; ?> mb-0 mt-1"><?php echo $dhAvg['avg']; ?></div>
                        <div class="small fw-semibold"><?php echo htmlspecialchars($dhAvg['label']); ?></div>
                        <div class="text-muted" style="font-size:10px;">
                            attendees <?php echo htmlspecialchars($dhAvg['unit'] ?? ''); ?><br>
                            <?php echo (int)$dhAvg['total']; ?> records / <?php echo (int)$dhAvg['periods']; ?> periods
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Trend ── -->
        <?php if (!empty($attendanceSeries['labels'])): ?>
        <div class="border-top pt-3 mb-4">
            <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                <i class="bi bi-graph-up me-1"></i><?php echo htmlspecialchars($bucketLabels[$dashBucket]); ?> Attendance Trend
                <span class="fw-normal text-lowercase">(last <?php echo count($attendanceSeries['labels']); ?> periods)</span>
            </div>
            <div class="chart-box chart-box-sm"><canvas id="dashTrendChart"></canvas></div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
