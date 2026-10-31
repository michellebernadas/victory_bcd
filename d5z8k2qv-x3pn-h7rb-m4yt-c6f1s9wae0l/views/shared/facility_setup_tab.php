<?php
/**
 * Weekly setup sheet — what Facilities has to prepare, week by week.
 *
 * CONFIRMED, non-deleted bookings only (FacilityRequest::getSetupSheet): only
 * a confirmed request is an official reservation, so nothing earlier is set up
 * for. Grouped by day, then by room. The same rows feed the CSV / Excel export
 * (FacilityController::setupSheetLines), so the printout and the download can
 * never disagree.
 *
 * Print: the Print button uses the browser's print dialog; the @media print
 * block below hides the sidebar, header, tabs and buttons and fits the table
 * to A4 / Letter landscape.
 */
$setupRows = $setupRows ?? [];
$setupFrom = $setupFrom ?? date('Y-m-d');
$setupTo   = $setupTo   ?? date('Y-m-d');
$fbPrev = date('Y-m-d', strtotime('-7 days', strtotime($setupFrom)));
$fbNext = date('Y-m-d', strtotime('+7 days', strtotime($setupFrom)));
list($fbThisWeek) = FacilityController::weekBounds(date('Y-m-d'));
$fbIsThisWeek = ($setupFrom === $fbThisWeek);
$fbWeekTitle = date('M j', strtotime($setupFrom)) . ' – ' . date('M j, Y', strtotime($setupTo));

$fbLines = FacilityController::setupSheetLines($setupRows);
// Group: day -> room -> lines (rows arrive in day, room, start order).
$fbByDay = [];
foreach ($setupRows as $i => $r) {
    $fbByDay[$r['date_needed']][$fbLines[$i]['Room']][] = $fbLines[$i] + ['_id' => (int)$r['id']];
}
$fbUrl = function ($week) { return 'index.php?action=facilityCalendar&tab=setup&week=' . urlencode($week); };
?>
<div id="facilitySetupTab">
    <div class="card fb-setup-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 fb-noprint">
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-sm btn-outline-light" href="<?php echo htmlspecialchars($fbUrl($fbPrev)); ?>" aria-label="Previous week">
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </a>
                <a class="btn btn-sm btn-outline-light<?php echo $fbIsThisWeek ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($fbUrl($fbThisWeek)); ?>">This week</a>
                <a class="btn btn-sm btn-outline-light" href="<?php echo htmlspecialchars($fbUrl($fbNext)); ?>" aria-label="Next week">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </a>
                <form method="GET" action="index.php" class="d-flex align-items-center gap-1 ms-1">
                    <input type="hidden" name="action" value="facilityCalendar">
                    <input type="hidden" name="tab" value="setup">
                    <label class="visually-hidden" for="fbWeekPick">Week containing</label>
                    <input type="date" name="week" id="fbWeekPick" class="form-control form-control-sm" style="width:auto"
                           value="<?php echo htmlspecialchars($setupFrom); ?>" onchange="this.form.submit()">
                </form>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-light" onclick="window.print()">
                    <i class="bi bi-printer me-1" aria-hidden="true"></i>Print
                </button>
                <a class="btn btn-sm btn-outline-light" href="index.php?action=facilitySetupSheetExport&amp;format=csv&amp;week=<?php echo urlencode($setupFrom); ?>">
                    <i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV
                </a>
                <a class="btn btn-sm btn-outline-light" href="index.php?action=facilitySetupSheetExport&amp;format=xlsx&amp;week=<?php echo urlencode($setupFrom); ?>">
                    <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Excel
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
                <h2 class="h5 mb-0"><i class="bi bi-clipboard2-data me-2 text-purple fb-noprint" aria-hidden="true"></i>Setup sheet · <?php echo htmlspecialchars($fbWeekTitle); ?></h2>
                <span class="small text-muted"><?php echo count($setupRows); ?> confirmed booking<?php echo count($setupRows) === 1 ? '' : 's'; ?> · printed <?php echo htmlspecialchars(date('M j, Y g:i A')); ?></span>
            </div>
            <p class="small text-muted mb-3">Confirmed (official) bookings only — pending, cancelled and deleted requests are not set up for.
                Setup / technical show the Facilities review answer when there is one, otherwise what the requestor asked for.</p>

            <?php if (!$setupRows): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-cup-hot d-block mb-2" style="font-size:2rem" aria-hidden="true"></i>
                <p class="mb-1">No confirmed bookings for <?php echo htmlspecialchars($fbWeekTitle); ?>.</p>
                <p class="small mb-0">Nothing to set up this week. Use the arrows to look at another week.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-bordered align-top fb-setup-table mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col" style="width:12%">Room</th>
                        <th scope="col" style="width:12%">Time</th>
                        <th scope="col">Event</th>
                        <th scope="col" style="width:11%">Ministry</th>
                        <th scope="col" style="width:7%" class="text-end">Attend.</th>
                        <th scope="col" style="width:14%">Setup required</th>
                        <th scope="col" style="width:8%">Technical</th>
                        <th scope="col" style="width:17%">Facilities notes</th>
                    </tr>
                </thead>
                <?php foreach ($fbByDay as $day => $rooms): ?>
                <tbody class="fb-setup-day">
                    <tr class="fb-setup-dayrow">
                        <th colspan="8" scope="rowgroup"><?php echo htmlspecialchars(date('l, F j, Y', strtotime($day))); ?></th>
                    </tr>
                    <?php foreach ($rooms as $room => $lines): foreach ($lines as $li => $l): ?>
                    <tr>
                        <?php if ($li === 0): ?>
                        <th scope="row" rowspan="<?php echo count($lines); ?>" class="fw-semibold"><?php echo htmlspecialchars($room); ?></th>
                        <?php endif; ?>
                        <td class="text-nowrap"><?php echo htmlspecialchars($l['Time']); ?></td>
                        <td>
                            <a href="#" class="text-decoration-none fb-noprint-link" onclick="openFacilityDetail(<?php echo (int)$l['_id']; ?>); return false;"><?php echo htmlspecialchars($l['Event'] ?: '(untitled)'); ?></a>
                        </td>
                        <td><?php echo htmlspecialchars($l['Ministry']); ?></td>
                        <td class="text-end"><?php echo htmlspecialchars($l['Expected attendance']); ?></td>
                        <td><?php echo htmlspecialchars($l['Setup required']); ?></td>
                        <td><?php echo htmlspecialchars($l['Technical required']); ?></td>
                        <td class="small"><?php echo htmlspecialchars($l['Facilities notes']); ?></td>
                    </tr>
                    <?php endforeach; endforeach; ?>
                </tbody>
                <?php endforeach; ?>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.fb-setup-dayrow th { background: var(--vb-purple-soft, #f3e8f2) !important; color: var(--vb-purple, #51154b); font-size: .9rem; }
.fb-setup-table td, .fb-setup-table th { font-size: .82rem; }
@media print {
    @page { size: landscape; margin: 10mm; }
    body { background: #fff !important; }
    #main-menu, .sidebar, .sidebar-toggle, .sidebar-backdrop, .main-content > .container-fluid > :not(#facilitySetupTab),
    .fb-noprint, .modal, .modal-backdrop, .btn, .alert { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; width: 100% !important; }
    #facilitySetupTab .card { border: 0 !important; box-shadow: none !important; }
    #facilitySetupTab .card-body { padding: 0 !important; }
    .fb-setup-table { width: 100% !important; }
    .fb-setup-table td, .fb-setup-table th { font-size: 9pt; padding: 2px 4px !important; }
    .fb-setup-day { break-inside: avoid; page-break-inside: avoid; }
    .fb-noprint-link { color: #000 !important; text-decoration: none !important; }
    .table-responsive { overflow: visible !important; }
}
</style>
