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
//                    $availableYears, $volunteerJourneyData, $memberStats
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

<!-- ── Volunteers vs discipleship journey ───────────────────────────────── -->
<?php
// The raw volunteer_status column is free text ("FOR AUDITION", blanks, …), so
// the old table led with a 272-row "Unspecified" bucket and buried the useful
// rows. Normalise into the four statuses the church actually uses, roll anything
// else into "Other", and order them by the journey rather than by size.
$volBuckets = [
    'ACTIVE'   => ['label' => 'Active volunteers',   'color' => 'success',   'note' => 'Currently serving'],
    'NEW'      => ['label' => 'New volunteers',      'color' => 'info',      'note' => 'Recently joined a team'],
    'INACTIVE' => ['label' => 'Inactive volunteers', 'color' => 'secondary', 'note' => 'Previously served'],
    'OTHER'    => ['label' => 'Other / in process',  'color' => 'warning',   'note' => 'e.g. awaiting audition'],
    'NONE'     => ['label' => 'Not a volunteer yet', 'color' => 'light',     'note' => 'No volunteer status recorded'],
];
$volRows = [];
foreach ($volBuckets as $volKey => $volDef) {
    $volRows[$volKey] = $volDef + ['total' => 0, 'vw' => 0, 'cc' => 0, 'md' => 0, 'el' => 0, 'l113' => 0, 'raw' => []];
}
foreach ($volunteerJourneyData as $vrow) {
    $raw = strtoupper(trim((string)$vrow['vol_status']));
    if ($raw === '' || $raw === 'UNSPECIFIED')          $key = 'NONE';
    elseif (isset($volBuckets[$raw]) && $raw !== 'OTHER') $key = $raw;
    else                                                 $key = 'OTHER';
    $volRows[$key]['total'] += (int)$vrow['total'];
    foreach (['vw', 'cc', 'md', 'el', 'l113'] as $sk) $volRows[$key][$sk] += (int)$vrow[$sk];
    if ($key === 'OTHER' && $raw !== '') $volRows[$key]['raw'][] = ucwords(strtolower($raw));
}
// Volunteers = everyone with a status other than "none".
$volTotalVolunteers = 0;
foreach ($volRows as $volKey => $vr) if ($volKey !== 'NONE') $volTotalVolunteers += $vr['total'];
$volGrandTotal = $volTotalVolunteers + $volRows['NONE']['total'];
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 text-white">
            <i class="bi bi-person-lines-fill me-2"></i>Volunteers &amp; Their Discipleship Journey
        </h6>
        <div class="d-flex align-items-center gap-2">
            <span class="col-toggle" data-table="dashVolunteerTable" data-locked="0"></span>
            <span class="badge bg-white text-dark border"><?php echo (int)$volTotalVolunteers; ?> volunteers</span>
        </div>
    </div>
    <div class="card-body p-0">
        <p class="text-muted small mb-0 px-3 pt-3">
            <i class="bi bi-info-circle me-1"></i>
            How far along the discipleship journey each volunteer group is. Each class column shows how many
            of that group have completed it, and what share of the group that represents &mdash; so a low
            percentage flags a group that needs following up.
        </p>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle" id="dashVolunteerTable">
                <thead class="table-light">
                    <tr>
                        <th>Volunteer Group</th>
                        <th class="text-center">People</th>
                        <th class="text-center" title="Victory Weekend"><i class="bi bi-sun text-primary"></i> VW</th>
                        <th class="text-center" title="Church Community"><i class="bi bi-building text-secondary"></i> CC</th>
                        <th class="text-center" title="Making Disciples"><i class="bi bi-person-plus text-success"></i> MD</th>
                        <th class="text-center" title="Empowering Leaders"><i class="bi bi-star text-warning"></i> EL</th>
                        <th class="text-center" title="Leadership 1-1-3"><i class="bi bi-trophy text-danger"></i> L113</th>
                        <th style="min-width:150px;" title="Average number of the 5 classes completed per person in this group">
                            Avg. classes done
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($volRows as $volKey => $vr):
                        if ($vr['total'] === 0) continue;                 // hide empty buckets
                        $stepsDone = $vr['vw'] + $vr['cc'] + $vr['md'] + $vr['el'] + $vr['l113'];
                        $avgSteps  = $vr['total'] > 0 ? round($stepsDone / $vr['total'], 1) : 0;
                        $avgPct    = (int)round($avgSteps / 5 * 100);
                        $avgColor  = $avgPct >= 60 ? 'success' : ($avgPct >= 30 ? 'warning' : 'danger');
                    ?>
                    <tr>
                        <td>
                            <span class="fw-semibold"><?php echo htmlspecialchars($vr['label']); ?></span>
                            <div class="text-muted" style="font-size:10px;">
                                <?php echo htmlspecialchars($vr['note']); ?>
                                <?php if ($volKey === 'OTHER' && $vr['raw']): ?>
                                &mdash; <?php echo htmlspecialchars(implode(', ', array_unique($vr['raw']))); ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $vr['color'] === 'light' ? 'light text-dark border' : $vr['color']; ?>">
                                <?php echo (int)$vr['total']; ?>
                            </span>
                        </td>
                        <?php foreach ([['vw','primary'],['cc','secondary'],['md','success'],['el','warning'],['l113','danger']] as [$sk, $sc]):
                            $sCount = (int)$vr[$sk];
                            $sPct   = $vr['total'] > 0 ? (int)round($sCount / $vr['total'] * 100) : 0;
                        ?>
                        <td class="text-center">
                            <div class="fw-semibold text-<?php echo $sc; ?>"><?php echo $sCount; ?></div>
                            <div class="text-muted" style="font-size:10px;"><?php echo $sPct; ?>%</div>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:8px;">
                                    <div class="progress-bar bg-<?php echo $avgColor; ?>" style="width:<?php echo $avgPct; ?>%"></div>
                                </div>
                                <small class="fw-semibold text-<?php echo $avgColor; ?>" style="min-width:52px;">
                                    <?php echo $avgSteps; ?> / 5
                                </small>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr class="fw-semibold">
                        <td>All members on record</td>
                        <td class="text-center"><?php echo (int)$volGrandTotal; ?></td>
                        <td colspan="6" class="text-muted small">
                            <?php echo (int)$volTotalVolunteers; ?> have a volunteer status;
                            <?php echo (int)$volRows['NONE']['total']; ?> do not yet.
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

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
