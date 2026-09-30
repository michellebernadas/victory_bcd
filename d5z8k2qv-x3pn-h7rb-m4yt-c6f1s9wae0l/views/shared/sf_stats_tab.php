<?php
// Spiritual Foundations — Statistics tab.
// Requires in scope: $rows, $batchStats, $sfWeeks, $sfRequiredWeeks, $requiredWeekNos,
//                    $sfProgress, $totalParticipants, $totalEligible, $totalNotEligible

// ── A. Overall completion (cumulative, not per-batch) ───────────────────────
$sfPct = $totalParticipants > 0 ? (int)round($totalEligible / $totalParticipants * 100) : 0;

// ── B. Attendance by topic. NC is excluded from the denominator because the
//      topic was not required of that participant in that batch. ────────────
$weekTotals = [];
foreach ($sfRequiredWeeks as $wk => $topic) {
    $weekTotals[$wk] = ['topic' => $topic['topic'], 'present' => 0, 'late' => 0,
                        'absent' => 0, 'nc' => 0, 'recorded' => 0];
}
foreach ($rows as $r) {
    foreach ($r['weeks'] as $wk => $w) {
        if (!isset($weekTotals[$wk])) continue;
        $st = ProgramAttendance::statusStyle($w['status']);
        $weekTotals[$wk]['recorded']++;
        if     ($st['kind'] === 'not_required') $weekTotals[$wk]['nc']++;
        elseif ($st['kind'] === 'late')         $weekTotals[$wk]['late']++;
        elseif ($st['kind'] === 'attended')     $weekTotals[$wk]['present']++;
        elseif ($st['kind'] === 'absent')       $weekTotals[$wk]['absent']++;
    }
}

// ── D. Most missed topics — by absences, and by participants still not done ──
$stillMissing = array_fill_keys(array_keys($sfRequiredWeeks), 0);
foreach ($sfProgress as $p) {
    foreach (($p['missing'] ?? []) as $wk) {
        if (isset($stillMissing[$wk])) $stillMissing[$wk]++;
    }
}
$missedRank = [];
foreach ($weekTotals as $wk => $t) {
    $missedRank[$wk] = [
        'week' => $wk, 'topic' => $t['topic'],
        'absent' => $t['absent'], 'outstanding' => (int)($stillMissing[$wk] ?? 0),
        'required' => $t['present'] + $t['late'] + $t['absent'],
    ];
}
uasort($missedRank, fn($a, $b) => ($b['outstanding'] <=> $a['outstanding']) ?: ($b['absent'] <=> $a['absent']));

// Chart payloads.
$chartWeekLabels = [];
foreach ($weekTotals as $wk => $t) $chartWeekLabels[] = 'W' . $wk . ' · ' . $t['topic'];
$topMissed = array_slice(array_values($missedRank), 0, 8);
?>
<script>
window.SF_STATS = {
    completion:   { complete: <?php echo (int)$totalEligible; ?>, incomplete: <?php echo (int)$totalNotEligible; ?> },
    weekLabels:   <?php echo json_encode($chartWeekLabels); ?>,
    weekPresent:  <?php echo json_encode(array_values(array_column($weekTotals, 'present'))); ?>,
    weekLate:     <?php echo json_encode(array_values(array_column($weekTotals, 'late'))); ?>,
    weekAbsent:   <?php echo json_encode(array_values(array_column($weekTotals, 'absent'))); ?>,
    batchLabels:  <?php echo json_encode(array_values(array_map(fn($b) => $b['batch'] . ' (' . $b['year'] . ')', $batchStats))); ?>,
    batchDone:    <?php echo json_encode(array_values(array_column($batchStats, 'completed'))); ?>,
    batchPending: <?php echo json_encode(array_values(array_column($batchStats, 'notCompleted'))); ?>,
    missedLabels: <?php echo json_encode(array_map(fn($m) => 'W' . $m['week'] . ' · ' . $m['topic'], $topMissed)); ?>,
    missedAbsent: <?php echo json_encode(array_map(fn($m) => (int)$m['absent'], $topMissed)); ?>,
    missedOutstanding: <?php echo json_encode(array_map(fn($m) => (int)$m['outstanding'], $topMissed)); ?>
};
</script>

<!-- Headline cards -->
<div class="row mb-4 g-3">
    <div class="col-6 col-md-3">
        <div class="card h-100 border-info"><div class="card-body text-center py-3">
            <div class="display-6 fw-bold text-info mb-0"><?php echo (int)$totalParticipants; ?></div>
            <div class="small text-muted mt-1">Participants</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body text-center py-3">
            <div class="display-6 fw-bold text-success mb-0"><?php echo (int)$totalEligible; ?></div>
            <div class="small text-muted mt-1">Complete Overall</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body text-center py-3">
            <div class="display-6 fw-bold text-warning mb-0"><?php echo (int)$totalNotEligible; ?></div>
            <div class="small text-muted mt-1">Incomplete Overall</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card h-100"><div class="card-body text-center py-3">
            <div class="display-6 fw-bold text-secondary mb-0"><?php echo count($requiredWeekNos); ?></div>
            <div class="small text-muted mt-1">Required Topics</div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- A. Completion status (overall, cumulative) -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span class="small fw-bold text-white"><i class="bi bi-pie-chart-fill me-2"></i>Completion Status</span>
                <button class="btn btn-sm btn-outline-light" onclick="sfExportChartPng('sfCompletionChart','sf_completion_status')">
                    <i class="bi bi-download"></i>
                </button>
            </div>
            <div class="card-body">
                <!-- Bounded height + maintainAspectRatio:false is what stops the
                     clipping/zoom: the canvas fills this box instead of forcing
                     its own aspect ratio against the card width. -->
                <div class="chart-box chart-box-sm"><canvas id="sfCompletionChart"></canvas></div>
                <div class="text-center small text-muted mt-2">
                    <strong class="text-success"><?php echo $sfPct; ?>%</strong> complete
                    &mdash; cumulative across all batches
                </div>
            </div>
        </div>
    </div>

    <!-- B. Attendance by topic -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span class="small fw-bold text-white"><i class="bi bi-bar-chart-fill me-2"></i>Attendance by Topic</span>
                <button class="btn btn-sm btn-outline-light" onclick="sfExportChartPng('sfWeekChart','sf_attendance_by_topic')">
                    <i class="bi bi-download me-1"></i>PNG
                </button>
            </div>
            <div class="card-body">
                <div class="chart-box chart-box-lg"><canvas id="sfWeekChart"></canvas></div>
                <div class="small text-muted mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Bars show participant counts per topic; the blue line is the attendance rate
                    <em>(Present + Late) ÷ (Present + Late + Absent)</em>. NC is excluded from both —
                    the topic was not required of that participant in that batch. Hover a bar for the full topic name.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- C. Completion rate by batch -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span class="small fw-bold text-white"><i class="bi bi-collection-fill me-2"></i>Completion by Batch</span>
                <button class="btn btn-sm btn-outline-light" onclick="sfExportChartPng('sfBatchChart','sf_completion_by_batch')">
                    <i class="bi bi-download"></i>
                </button>
            </div>
            <div class="card-body">
                <div class="chart-box"><canvas id="sfBatchChart"></canvas></div>
            </div>
        </div>
    </div>

    <!-- D. Most missed topics -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span class="small fw-bold text-white"><i class="bi bi-exclamation-triangle-fill me-2"></i>Most Missed Topics</span>
                <button class="btn btn-sm btn-outline-light" onclick="sfExportChartPng('sfMissedChart','sf_most_missed_topics')">
                    <i class="bi bi-download"></i>
                </button>
            </div>
            <div class="card-body">
                <div class="chart-box"><canvas id="sfMissedChart"></canvas></div>
                <div class="small text-muted mt-2">
                    <i class="bi bi-info-circle me-1"></i><strong>Still outstanding</strong> counts participants who
                    have not completed the topic in <em>any</em> batch — the catch-up backlog.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Per-topic table -->
<div class="card mb-4">
    <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="small fw-bold text-white"><i class="bi bi-journal-text me-2"></i>Per-Topic Breakdown</span>
        <span class="col-toggle" data-table="sfWeekTable" data-locked="0"></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="sfWeekTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:70px">Week</th>
                        <th>Topic</th>
                        <th class="text-center text-success">Present</th>
                        <th class="text-center text-warning">Late</th>
                        <th class="text-center text-danger">Absent</th>
                        <th class="text-center text-muted" title="Not required of that participant in that batch">NC</th>
                        <th style="min-width:120px" title="(Present + Late) ÷ (Present + Late + Absent) — NC excluded">Attendance Rate</th>
                        <th class="text-center" title="Participants who have not completed this topic in any batch">Still Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($weekTotals as $wk => $t):
                        $denom = $t['present'] + $t['late'] + $t['absent'];
                        $rate  = $denom > 0 ? (int)round(($t['present'] + $t['late']) / $denom * 100) : 0;
                        $c     = $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger');
                        $out   = (int)($stillMissing[$wk] ?? 0);
                    ?>
                    <tr>
                        <td><span class="badge bg-info">W<?php echo (int)$wk; ?></span></td>
                        <td class="fw-semibold small"><?php echo htmlspecialchars($t['topic']); ?></td>
                        <td class="text-center text-success fw-semibold"><?php echo $t['present']; ?></td>
                        <td class="text-center text-warning fw-semibold"><?php echo $t['late']; ?></td>
                        <td class="text-center text-danger fw-semibold"><?php echo $t['absent']; ?></td>
                        <td class="text-center text-muted"><?php echo $t['nc']; ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:8px;">
                                    <div class="progress-bar bg-<?php echo $c; ?>" style="width:<?php echo $rate; ?>%"></div>
                                </div>
                                <small class="fw-semibold text-<?php echo $c; ?>" style="min-width:35px;"><?php echo $denom > 0 ? $rate . '%' : '—'; ?></small>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $out > 0 ? 'bg-warning text-dark' : 'bg-light text-muted border'; ?>"><?php echo $out; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Batch summary table -->
<?php if (!empty($batchStats)): ?>
<div class="card">
    <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="small fw-bold text-white"><i class="bi bi-table me-2"></i>Batch Summary</span>
        <span class="col-toggle" data-table="sfBatchTable" data-locked="0"></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="sfBatchTable">
                <thead class="table-light">
                    <tr>
                        <th>Batch</th>
                        <th>Year</th>
                        <th class="text-center">Participants</th>
                        <th class="text-center text-success">Completed in batch</th>
                        <th class="text-center text-warning">Incomplete in batch</th>
                        <th class="text-center">Batch completion %</th>
                        <th class="text-center text-info" title="Of this batch's participants, how many are complete overall (possibly finished in another batch)">Complete overall</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batchStats as $bs):
                        $bsPct = $bs['total'] > 0 ? (int)round($bs['completed'] / $bs['total'] * 100) : 0;
                    ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($bs['batch']); ?></td>
                        <td><span class="badge bg-secondary"><?php echo (int)$bs['year']; ?></span></td>
                        <td class="text-center fw-bold"><?php echo $bs['total']; ?></td>
                        <td class="text-center text-success fw-semibold"><?php echo $bs['completed']; ?></td>
                        <td class="text-center text-warning fw-semibold"><?php echo $bs['notCompleted']; ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $bsPct >= 80 ? 'success' : ($bsPct >= 50 ? 'warning' : 'danger'); ?>"><?php echo $bsPct; ?>%</span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info"><?php echo (int)$bs['overallComplete']; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Completed in batch</strong> means the participant finished every required topic inside that batch.
        <strong>Complete overall</strong> counts the same people once their whole Spiritual Foundations history is
        considered — a catch-up batch can complete someone who was incomplete the first time round.
    </div>
</div>
<?php endif; ?>
