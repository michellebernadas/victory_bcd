<?php
// Dashboard — Discipleship Records Analytics.
//
// Rewritten to answer the question a leader actually has ("how many PEOPLE have
// been through each class, and how far along is everyone?") instead of showing
// three near-identical numbers per card.
//
// Previously each card showed Attendees / Matched / Members, where "Attendees"
// counted ROWS (double-counting anyone who attended in two years) and Matched
// and Members were almost always the same figure. Now: one headline count of
// distinct people, with records and unlinked rows as supporting detail.
//
// Requires in scope: $paStats, $participantsByProgram, $activeStats,
//                    $availableYears, $memberStats
$programDefs = [
    'victory_weekend'       => ['label' => 'Victory Weekend',       'color' => 'primary',   'icon' => 'bi-sun',         'short' => 'VW',   'link' => 'index.php?action=attendanceRecords&program_type=victory_weekend'],
    'church_community'      => ['label' => 'Church Community',      'color' => 'secondary', 'icon' => 'bi-building',    'short' => 'CC',   'link' => 'index.php?action=attendanceRecords&program_type=church_community'],
    'making_disciples'      => ['label' => 'Making Disciples',      'color' => 'success',   'icon' => 'bi-person-plus', 'short' => 'MD',   'link' => 'index.php?action=attendanceRecords&program_type=making_disciples'],
    'empowering_leaders'    => ['label' => 'Empowering Leaders',    'color' => 'warning',   'icon' => 'bi-star',        'short' => 'EL',   'link' => 'index.php?action=attendanceRecords&program_type=empowering_leaders'],
    'leadership_113'        => ['label' => 'Leadership 1-1-3',      'color' => 'danger',    'icon' => 'bi-trophy',      'short' => 'L113', 'link' => 'index.php?action=leadership113'],
    'spiritual_foundations' => ['label' => 'Spiritual Foundations', 'color' => 'purple',    'icon' => 'bi-shield',      'short' => 'SF',   'link' => 'index.php?action=spiritualFoundations'],
];
$daActiveTotal = max(1, (int)($activeStats['total'] ?? 0));
?>

<!-- ── Class participation ──────────────────────────────────────────────── -->
<div class="row mb-2">
    <div class="col-12 d-flex justify-content-between align-items-end flex-wrap gap-2">
        <h5 class="text-muted fw-bold mb-0" style="font-size:12px; letter-spacing:1px;">CLASS PARTICIPATION</h5>
        <span class="text-muted" style="font-size:11px;">
            <i class="bi bi-info-circle me-1"></i>People who have attended each class at least once
        </span>
    </div>
</div>

<!-- Year filter for the "By year" rows inside the cards. Sits with the cards it
     controls, rather than floating between two unrelated sections. -->
<?php if (!empty($availableYears)): ?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <span class="text-muted fw-bold" style="font-size:11px; letter-spacing:.05em;">
        <i class="bi bi-calendar3 me-1"></i>SHOW YEARS:
    </span>
    <button type="button" class="btn btn-sm btn-primary dash-yr-btn" data-year="all">All</button>
    <?php foreach ($availableYears as $daFYr): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary dash-yr-btn" data-year="<?php echo (int)$daFYr; ?>">
        <?php echo (int)$daFYr; ?>
    </button>
    <?php endforeach; ?>
    <span class="text-muted" style="font-size:10px;">filters the <em>By year</em> rows below</span>
</div>
<?php endif; ?>
<div class="row mb-4 g-3" id="discAnalyticsCards">
    <?php foreach ($programDefs as $daPt => $daDef):
        $daP        = $participantsByProgram[$daPt] ?? ['people' => 0, 'records' => 0, 'linked' => 0, 'unlinked' => 0];
        $daYearData = $paStats[$daPt] ?? [];
        ksort($daYearData);
        $daMaxYear  = $daYearData ? max($daYearData) : 0;
        // Share of the ACTIVE membership that has been through this class.
        $daPct = (int)round(min($daP['linked'], $daActiveTotal) / $daActiveTotal * 100);
    ?>
    <div class="col-12 col-sm-6 col-lg-4 col-xl-2 program-analytics-card" data-program="<?php echo $daPt; ?>">
        <div class="card h-100 border-<?php echo $daDef['color']; ?> border-opacity-50">
            <!-- text-white (not text-reset) so the gradient header stays legible -->
            <a href="<?php echo $daDef['link']; ?>"
               class="card-header py-2 d-block text-decoration-none text-white"
               title="Open <?php echo htmlspecialchars($daDef['label']); ?> records">
                <div class="d-flex align-items-center justify-content-between">
                    <span class="fw-semibold small text-white">
                        <i class="bi <?php echo $daDef['icon']; ?> me-1"></i><?php echo $daDef['label']; ?>
                    </span>
                    <span class="badge bg-white text-dark"><?php echo $daDef['short']; ?></span>
                </div>
            </a>
            <div class="card-body p-3">
                <!-- One headline number: distinct people. -->
                <div class="text-center mb-2">
                    <div class="display-6 fw-bold text-<?php echo $daDef['color']; ?> mb-0" style="line-height:1;">
                        <?php echo (int)$daP['people']; ?>
                    </div>
                    <div class="text-muted text-uppercase" style="font-size:10px; letter-spacing:.05em;">
                        People attended
                    </div>
                </div>

                <!-- Share of active members, so the count has a denominator. -->
                <div class="mb-2">
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar bg-<?php echo $daDef['color']; ?>" style="width:<?php echo $daPct; ?>%"></div>
                    </div>
                    <div class="text-muted mt-1" style="font-size:10px;">
                        <?php echo $daPct; ?>% of <?php echo (int)$activeStats['total']; ?> active members
                    </div>
                </div>

                <div class="border-top pt-2 d-flex justify-content-between" style="font-size:10px;">
                    <span class="text-muted" title="Attendance rows recorded — more than one per person when they attend in several years">
                        <i class="bi bi-journal-text me-1"></i><?php echo (int)$daP['records']; ?> records
                    </span>
                    <?php if ((int)$daP['unlinked'] > 0): ?>
                    <span class="text-warning" title="Attended but not linked to a member record — link them so their discipleship journey updates">
                        <i class="bi bi-person-exclamation me-1"></i><?php echo (int)$daP['unlinked']; ?> unlinked
                    </span>
                    <?php else: ?>
                    <span class="text-success"><i class="bi bi-check2-circle me-1"></i>all linked</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($daYearData)): ?>
                <div class="border-top pt-2 mt-2">
                    <div style="font-size:10px;" class="text-muted fw-bold text-uppercase mb-1">By year</div>
                    <?php foreach ($daYearData as $daYr => $daCnt): ?>
                    <div class="d-flex align-items-center gap-2 mb-1 dash-yr-row" data-year="<?php echo $daYr; ?>">
                        <span class="badge bg-secondary" style="font-size:10px; min-width:38px;"><?php echo $daYr; ?></span>
                        <div class="flex-grow-1">
                            <div class="progress" style="height:5px;">
                                <div class="progress-bar bg-<?php echo $daDef['color']; ?>"
                                     style="width:<?php echo $daMaxYear > 0 ? round($daCnt / $daMaxYear * 100) : 0; ?>%"></div>
                            </div>
                        </div>
                        <span class="small fw-semibold" style="min-width:22px; text-align:right;"><?php echo $daCnt; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted text-center mb-0 pt-2" style="font-size:11px;">No records yet</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<!-- The "Volunteers vs discipleship journey" panel was removed with the
     volunteer_status column (2026 reset). Serve Team membership is now the
     single source of truth for who serves. -->
<script>
// Year filter for the per-card "By year" rows. Data-attribute driven so the
// buttons carry no inline handlers.
document.addEventListener('DOMContentLoaded', function () {
    var btns = document.querySelectorAll('.dash-yr-btn');
    if (!btns.length) return;
    btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var yr = btn.dataset.year;
            document.querySelectorAll('.dash-yr-row').forEach(function (row) {
                row.style.display = (yr === 'all' || row.dataset.year === yr) ? '' : 'none';
            });
            btns.forEach(function (b) {
                var on = b === btn;
                b.classList.toggle('btn-primary', on);
                b.classList.toggle('btn-outline-secondary', !on);
            });
        });
    });
});
</script>
