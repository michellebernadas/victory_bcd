<?php
// Dashboard — Worship Service Attendance.
//
// The old version was four flat number cards plus an unexplained "OTHER /
// LEGACY" row, so you could read each figure but not compare them. Now each
// service gets a share bar against the busiest one, with the count and its
// share of members shown together, and the odd values are labelled for what
// they actually are (member dispositions such as "migrated", not services).
//
// Requires in scope: $serviceStats (name, period, count), $activeStats
$svcAm    = array_values(array_filter($serviceStats, fn($s) => $s['period'] === 'AM'));
$svcPm    = array_values(array_filter($serviceStats, fn($s) => $s['period'] === 'PM'));
$svcOther = array_values(array_filter($serviceStats, fn($s) => $s['period'] === '' && (int)$s['count'] > 0));

$svcCounts   = array_map(fn($s) => (int)$s['count'], array_merge($svcAm, $svcPm));
$svcMax      = $svcCounts ? max($svcCounts) : 0;
$svcTotal    = array_sum($svcCounts);
$svcOtherTot = array_sum(array_map(fn($s) => (int)$s['count'], $svcOther));
$svcActive   = max(1, (int)($activeStats['total'] ?? 0));
$svcAmTotal  = array_sum(array_map(fn($s) => (int)$s['count'], $svcAm));
$svcPmTotal  = array_sum(array_map(fn($s) => (int)$s['count'], $svcPm));
$svcBusiest  = null;
foreach (array_merge($svcAm, $svcPm) as $s) {
    if ($svcBusiest === null || (int)$s['count'] > (int)$svcBusiest['count']) $svcBusiest = $s;
}

/** One service row: name, count, share bar. */
$svcRow = function (array $s) use ($svcMax, $svcActive, $svcBusiest) {
    $n     = (int)$s['count'];
    $bar   = $svcMax > 0 ? (int)round($n / $svcMax * 100) : 0;
    $share = (int)round($n / $svcActive * 100);
    $isTop = $svcBusiest && $s['name'] === $svcBusiest['name'] && $n > 0;
    ob_start(); ?>
    <div class="d-flex align-items-center gap-3 mb-2">
        <div class="text-nowrap" style="min-width:86px;">
            <span class="fw-semibold"><?php echo htmlspecialchars($s['name']); ?></span>
            <?php if ($isTop): ?>
            <i class="bi bi-star-fill text-warning ms-1" style="font-size:9px;" title="Busiest service"></i>
            <?php endif; ?>
        </div>
        <div class="flex-grow-1">
            <div class="progress" style="height:14px;">
                <div class="progress-bar bg-<?php echo $s['period'] === 'AM' ? 'warning' : 'primary'; ?>"
                     style="width:<?php echo $bar; ?>%"
                     title="<?php echo $n; ?> members &middot; <?php echo $share; ?>% of active members"></div>
            </div>
        </div>
        <div class="text-end" style="min-width:74px;">
            <span class="fw-bold"><?php echo $n; ?></span>
            <span class="text-muted" style="font-size:11px;">&nbsp;<?php echo $share; ?>%</span>
        </div>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0 text-white"><i class="bi bi-clock me-2"></i>Worship Service Attendance</h6>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-white text-dark border"><?php echo (int)$svcTotal; ?> service sign-ups</span>
            <a href="index.php?action=settings&tab=services" class="btn btn-sm btn-outline-light" title="Manage service schedules">
                <i class="bi bi-gear"></i>
            </a>
        </div>
    </div>
    <div class="card-body">

        <!-- AM / PM split up front: the single most useful comparison. -->
        <div class="row g-2 mb-3">
            <div class="col-6">
                <div class="border rounded p-2 text-center bg-warning bg-opacity-10">
                    <div class="h4 fw-bold text-warning mb-0"><?php echo (int)$svcAmTotal; ?></div>
                    <div class="text-muted text-uppercase" style="font-size:10px; letter-spacing:.05em;">
                        <i class="bi bi-sunrise me-1"></i>Morning (AM)
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="border rounded p-2 text-center bg-primary bg-opacity-10">
                    <div class="h4 fw-bold text-primary mb-0"><?php echo (int)$svcPmTotal; ?></div>
                    <div class="text-muted text-uppercase" style="font-size:10px; letter-spacing:.05em;">
                        <i class="bi bi-sunset me-1"></i>Afternoon (PM)
                    </div>
                </div>
            </div>
        </div>

        <!-- Per-service share bars, so services are comparable at a glance. -->
        <?php if ($svcAm): ?>
        <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.05em;">
            <i class="bi bi-sunrise me-1 text-warning"></i>Morning
        </div>
        <?php foreach ($svcAm as $s) echo $svcRow($s); ?>
        <?php endif; ?>

        <?php if ($svcPm): ?>
        <div class="small fw-bold text-muted text-uppercase mb-2 mt-3" style="letter-spacing:.05em;">
            <i class="bi bi-sunset me-1 text-primary"></i>Afternoon
        </div>
        <?php foreach ($svcPm as $s) echo $svcRow($s); ?>
        <?php endif; ?>

        <div class="border-top pt-2 mt-3 text-muted" style="font-size:11px;">
            <i class="bi bi-info-circle me-1"></i>
            Percentages are of <strong><?php echo (int)$activeStats['total']; ?></strong> active members.
            The four figures add up to more than that because a member can be recorded against more than
            one service.
            <?php if ($svcBusiest && (int)$svcBusiest['count'] > 0): ?>
            Busiest: <strong><?php echo htmlspecialchars($svcBusiest['name']); ?></strong>.
            <?php endif; ?>
        </div>

        <?php if ($svcOther): ?>
        <!-- These live in the services list but describe a member's situation
             (moved away, transferred) rather than a service time. Labelled as
             such so the card doesn't imply they are services. -->
        <div class="border-top pt-2 mt-2">
            <div class="small fw-bold text-muted text-uppercase mb-1" style="letter-spacing:.05em;">
                <i class="bi bi-box-arrow-right me-1"></i>Not attending locally
                <span class="badge bg-light text-dark border ms-1"><?php echo (int)$svcOtherTot; ?></span>
            </div>
            <div class="d-flex flex-wrap gap-1">
                <?php foreach ($svcOther as $s): ?>
                <span class="badge bg-light text-dark border" style="font-size:10px;"
                      title="Recorded in the service list but describes the member's situation, not a service time">
                    <?php echo htmlspecialchars($s['name']); ?>: <?php echo (int)$s['count']; ?>
                </span>
                <?php endforeach; ?>
            </div>
            <div class="text-muted mt-1" style="font-size:10px;">
                These are member dispositions kept in the service list &mdash; tidy them up under
                <a href="index.php?action=settings&tab=services">Settings &rsaquo; Church Services</a>.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
