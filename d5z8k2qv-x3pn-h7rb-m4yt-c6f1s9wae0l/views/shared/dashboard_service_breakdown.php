<?php
// Dashboard — Church Health: attendance per service time.
//
// Replaces the old "Worship Service Attendance" card, which counted members'
// PREFERRED service on their profile (0 after the reset). This one reads the
// recorded Sunday head counts (ServiceAttendance::getServiceBreakdown) for the
// same range as the Sunday cards. Only real service times appear; a time's
// average covers only the Sundays it was held (4:30 PM started in June).
//
// Requires in scope: $serviceBreakdown, $sundayScope, $saEmpty
$sbRows   = $serviceBreakdown['rows'];
$sbLatest = $serviceBreakdown['latest_date'];
$sbMaxAvg = max(1, ...array_map(fn($r) => (int)$r['avg_service'], $sbRows ?: [['avg_service' => 0]]));
?>
<div class="border-top pt-3 mb-4" id="serviceBreakdown">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
        <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
            <i class="bi bi-clock me-1"></i>By service time
            <span class="fw-normal text-lowercase">&mdash; <?php echo htmlspecialchars($sundayScope['label']); ?></span>
        </span>
    </div>
    <?php if ($saEmpty || !$sbRows): ?>
    <div class="text-muted small py-2">No Sunday head counts in this range yet, so there is nothing to break down by service time.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-1" id="serviceBreakdownTable">
            <thead class="table-light">
                <tr>
                    <th style="width:14%">Service time</th>
                    <th>Average Sunday Service</th>
                    <th class="text-end">Average Kids Church</th>
                    <th class="text-end">Latest<?php echo $sbLatest ? ' (' . htmlspecialchars(date('M j', strtotime($sbLatest))) . ')' : ''; ?></th>
                    <th class="text-end">Share of that day</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sbRows as $sb): ?>
                <tr>
                    <td class="fw-semibold text-nowrap"><?php echo htmlspecialchars($sb['name']); ?></td>
                    <td>
                        <?php if ($sb['sundays'] > 0): ?>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:8px; max-width:260px;" aria-hidden="true">
                                <div class="progress-bar bg-success" style="width:<?php echo (int)round($sb['avg_service'] / $sbMaxAvg * 100); ?>%"></div>
                            </div>
                            <strong><?php echo number_format($sb['avg_service']); ?></strong>
                        </div>
                        <div class="text-muted" style="font-size:10px;">over <?php echo $sb['sundays']; ?> Sunday<?php echo $sb['sundays'] === 1 ? '' : 's'; ?> held</div>
                        <?php else: ?>
                        <span class="text-muted small">Not held in this range</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($sb['avg_kids'] !== null): ?>
                        <strong><?php echo number_format($sb['avg_kids']); ?></strong>
                        <div class="text-muted" style="font-size:10px;">counted <?php echo $sb['kids_days']; ?> of <?php echo $sb['sundays']; ?></div>
                        <?php elseif ($sb['sundays'] > 0): ?>
                        <span class="text-muted small">Not counted</span>
                        <?php else: ?>&mdash;<?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($sb['latest_total'] !== null): ?>
                        <strong><?php echo number_format($sb['latest_service']); ?></strong>
                        <div class="text-muted" style="font-size:10px;">
                            <?php echo $sb['latest_kids'] !== null ? '+ ' . number_format($sb['latest_kids']) . ' Kids Church' : 'Kids Church not counted'; ?>
                        </div>
                        <?php else: ?><span class="text-muted small">Not held</span><?php endif; ?>
                    </td>
                    <td class="text-end"><?php echo $sb['latest_share'] !== null ? (int)$sb['latest_share'] . '%' : '&mdash;'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="3" class="text-muted small fw-normal">Latest Sunday grand total (Sunday Service + Kids Church counted)</td>
                    <td class="text-end"><?php echo number_format($serviceBreakdown['latest_total']); ?></td>
                    <td class="text-end">100%</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="small text-muted">
        Each service time is averaged over the Sundays it was actually held, so a newer service (like 4:30 PM) is not pulled down by the months before it began.
    </div>
    <?php endif; ?>
</div>
