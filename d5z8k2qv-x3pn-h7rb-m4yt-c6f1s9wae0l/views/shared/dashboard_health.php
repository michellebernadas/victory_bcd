<?php
// Dashboard — Church Health: the pastors' view of REAL attendance.
//
// Order: Sunday Services (range filter, cards, trend) -> per-service
// breakdown -> Prayer Meeting & Students' Hangout -> month by month.
// Class averages moved to shared/dashboard_classes.php and the leader ratios
// to shared/dashboard_leadership.php; neither is attendance at church.
//
// Requires in scope: $sundayRange, $sundayScope, $sundayStats, $sundayLatest,
//                    $sundayTrend, $sundayUrl, $serviceBreakdown, $gaSeries,
//                    $gaRangeStats, $sundayMonthly, $gaMonthly, $healthYear,
//                    $healthYears, $healthYearUrl
?>
<div class="card mb-4" id="churchHealth">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-heart-pulse me-2"></i>Church Health &mdash; attendance</h6>
        <div class="dropdown">
            <button class="btn btn-sm btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-file-earmark-excel me-1 text-success"></i>Export to Excel
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach (ServiceAttendance::RANGES as $exKey => $exLabel): ?>
                <li><a class="dropdown-item small<?php echo $exKey === 'year' ? ' fw-semibold' : ''; ?>" href="index.php?action=churchHealthExport&amp;range=<?php echo $exKey; ?>">
                    <?php echo htmlspecialchars($exLabel); ?><?php echo $exKey === $sundayRange ? ' (shown)' : ''; ?>
                </a></li>
                <?php endforeach; ?>
                <?php foreach ($healthYears as $exY): if ((int)$exY === (int)date('Y')) continue; ?>
                <li><a class="dropdown-item small" href="index.php?action=churchHealthExport&amp;year=<?php echo (int)$exY; ?>">Year <?php echo (int)$exY; ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="card-body">
        <!-- ── Sunday Service Attendance ── -->
        <?php
        // Recorded head counts from service_attendances. Its own range filter,
        // because it scopes a different table from the class filter above —
        // and ONE scope drives the four cards, the average and the chart.
        $saEmpty = ((int)($sundayStats['records'] ?? 0)) === 0;
        ?>
        <div class="mb-4" id="sundayAttendance">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                    <i class="bi bi-people-fill me-1"></i>Sunday Services
                    <span class="fw-normal text-lowercase">&mdash; <?php echo htmlspecialchars($sundayScope['label']); ?></span>
                </span>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Attendance range">
                        <?php foreach (ServiceAttendance::RANGES as $srKey => $srLabel): ?>
                        <a href="<?php echo htmlspecialchars($sundayUrl($srKey)); ?>"
                           class="btn btn-sm <?php echo $sundayRange === $srKey ? 'btn-primary' : 'btn-outline-primary'; ?>">
                            <?php echo htmlspecialchars($srLabel); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <a href="index.php?action=serviceAttendance" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-plus-circle me-1"></i>Record
                    </a>
                </div>
            </div>

            <div class="row g-2">
                <?php
                // Latest Sunday is the whole most recent service DAY (all its
                // services summed), not whichever row was entered last.
                // adults = Sunday Service, kids = Kids Church (models/ServiceAttendance.php).
                $saHasL  = !empty($sundayLatest['date']);
                $saLDate = $saHasL ? date('M j, Y', strtotime($sundayLatest['date'])) : 'no entries yet';
                $saLSvc  = (int)$sundayLatest['services'];
                $saLMiss = (int)$sundayLatest['kids_missing'];
                $saLKidsNone = $saHasL && $saLMiss >= $saLSvc;
                $saLink  = function (?string $from, ?string $to = null) {
                    $q = array_filter(['action' => 'serviceAttendance', 'date_from' => $from, 'date_to' => $to]);
                    return 'index.php?' . http_build_query($q);
                };
                $saDayLink   = $saHasL ? $saLink($sundayLatest['date'], $sundayLatest['date']) : 'index.php?action=serviceAttendance';
                $saRangeLink = $saLink($sundayStats['first_date'] ?? null, $sundayStats['last_date'] ?? null);
                $saSundays   = (int)$sundayStats['sundays'];
                $saKidsDays  = (int)$sundayStats['kids_days'];
                $saCards = [
                    ['label' => 'Sunday Service', 'value' => $saHasL ? number_format($sundayLatest['adults']) : null,
                     'sub'   => 'latest Sunday · ' . $saLDate, 'color' => 'success', 'icon' => 'bi-people', 'link' => $saDayLink],
                    ['label' => 'Kids Church', 'value' => $saHasL && !$saLKidsNone ? number_format($sundayLatest['kids']) : null,
                     'empty' => $saHasL ? 'Not counted' : null,
                     'sub'   => !$saHasL ? 'no entries yet'
                              : ($saLKidsNone ? 'Kids Church was not counted on ' . $saLDate
                              : ($saLMiss > 0 ? 'counted at ' . ($saLSvc - $saLMiss) . ' of ' . $saLSvc . ' service times · ' . $saLDate
                              : 'latest Sunday · ' . $saLDate)),
                     'color' => 'info', 'icon' => 'bi-balloon', 'link' => $saDayLink],
                    ['label' => 'Grand Total', 'value' => $saHasL ? number_format($sundayLatest['total']) : null,
                     'sub'   => $saHasL ? $saLSvc . ' service time' . ($saLSvc === 1 ? '' : 's') . ' · ' . $saLDate : 'no entries yet',
                     'color' => 'primary', 'icon' => 'bi-calendar-event', 'link' => $saDayLink],
                ];
                foreach ($saCards as $saCard): ?>
                <div class="col-6 col-lg-3">
                    <a href="<?php echo htmlspecialchars($saCard['link']); ?>" class="card h-100 stat-card is-clickable text-decoration-none text-reset border-<?php echo $saCard['color']; ?> border-opacity-25"
                       title="Open the Sunday records">
                        <div class="card-body py-2 px-2 text-center">
                            <i class="bi <?php echo $saCard['icon']; ?> text-<?php echo $saCard['color']; ?>"></i>
                            <div class="h4 fw-bold text-<?php echo $saCard['color']; ?> mb-0 mt-1">
                                <?php echo $saCard['value'] !== null ? $saCard['value']
                                    : (!empty($saCard['empty']) ? '<span class="h6 text-muted">' . htmlspecialchars($saCard['empty']) . '</span>' : '&mdash;'); ?>
                            </div>
                            <div class="small fw-semibold" style="font-size:11px;"><?php echo htmlspecialchars($saCard['label']); ?></div>
                            <div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars($saCard['sub']); ?></div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
                <!-- Average per Sunday: Sunday Service and Kids Church shown separately. -->
                <div class="col-6 col-lg-3">
                    <a href="<?php echo htmlspecialchars($saRangeLink); ?>" class="card h-100 stat-card is-clickable text-decoration-none text-reset border-warning border-opacity-25"
                       title="Open the Sunday records for this range">
                        <div class="card-body py-2 px-2 text-center">
                            <i class="bi bi-calculator text-warning"></i>
                            <div class="small fw-semibold" style="font-size:11px;">Average per Sunday</div>
                            <?php if ($saEmpty): ?>
                            <div class="h4 fw-bold text-warning mb-0">&mdash;</div>
                            <div class="text-muted" style="font-size:10px;">no entries yet</div>
                            <?php else: ?>
                            <div class="d-flex justify-content-center gap-3 mt-1">
                                <div>
                                    <div class="h4 fw-bold text-success mb-0"><?php echo number_format($sundayStats['avg_service_day']); ?></div>
                                    <div style="font-size:10px;">Sunday Service</div>
                                </div>
                                <div>
                                    <?php if ($saKidsDays): ?>
                                    <div class="h4 fw-bold text-info mb-0"><?php echo number_format($sundayStats['avg_kids_day']); ?></div>
                                    <div style="font-size:10px;">Kids Church</div>
                                    <?php else: ?>
                                    <div class="h6 text-muted mb-0 mt-1">Not counted</div>
                                    <div style="font-size:10px;">Kids Church</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-muted" style="font-size:10px;">
                                over <?php echo $saSundays; ?> Sunday<?php echo $saSundays === 1 ? '' : 's'; ?>
                                <?php if (!$saKidsDays): ?>&middot; Kids Church not counted on these Sundays
                                <?php elseif ($saKidsDays < $saSundays): ?>&middot; Kids Church counted on <?php echo $saKidsDays; ?> of them
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
            </div>

            <?php if (!empty($sundayTrend['labels'])): ?>
            <div class="mt-3">
                <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                    <i class="bi bi-graph-up me-1"></i>Sunday trend
                    <span class="fw-normal text-lowercase">(<?php echo count($sundayTrend['labels']); ?> Sunday<?php echo count($sundayTrend['labels']) === 1 ? '' : 's'; ?>)</span>
                </div>
                <div class="chart-box chart-box-sm"><canvas id="sundayTrendChart"></canvas></div>
                <div class="mt-2 small text-muted">
                    <i class="bi bi-calculator me-1"></i>
                    <strong>Average per Sunday</strong>: each Sunday's service times are added together, then those Sunday totals are averaged.
                    The Kids Church average uses only the Sundays it was counted<?php if ($sundayStats['kids_missing']): ?>; where it was not counted the chart shows a gap, not a zero<?php endif; ?>.
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-light border py-2 small mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>
                No Sunday head counts recorded for <?php echo htmlspecialchars(strtolower($sundayScope['label'])); ?>.
                Add them on the <a href="index.php?action=serviceAttendance" class="alert-link">Sunday Service Attendance</a> page
                and the totals, average and trend will appear here.
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Per service time (same range as above) ── -->
        <?php include __DIR__ . '/dashboard_service_breakdown.php'; ?>

        <!-- ── Prayer Meeting & Students' Hangout (same range as Sunday) ── -->
        <div class="border-top pt-3 mb-4" id="gatheringHealth">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                    <i class="bi bi-fire me-1"></i>Prayer Meeting &amp; Students&rsquo; Hangout
                    <span class="fw-normal text-lowercase">&mdash; <?php echo htmlspecialchars($sundayRange === 'last4' ? 'last 4 of each' : strtolower($sundayScope['label'])); ?></span>
                </span>
                <a href="index.php?action=gatheringAttendance" class="btn btn-sm btn-outline-secondary"><i class="bi bi-plus-circle me-1"></i>Record</a>
            </div>
            <div class="row g-3">
                <?php foreach (GatheringAttendance::TYPES as $gdT => $gdDef):
                    $gdS = $gaRangeStats[$gdT]; $gdSer = $gaSeries[$gdT]; ?>
                <div class="col-lg-6">
                    <div class="border rounded p-2 h-100">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                            <span class="fw-semibold small"><i class="bi <?php echo $gdDef['icon']; ?> text-<?php echo $gdDef['color']; ?> me-1"></i><?php echo htmlspecialchars($gdDef['label']); ?></span>
                            <span class="small text-muted text-end">
                                Latest <strong class="text-<?php echo $gdDef['color']; ?>"><?php echo $gdS['latest'] === null ? '&mdash;' : (int)$gdS['latest']; ?></strong><?php echo $gdS['latest_date'] ? ' (' . date('M j', strtotime($gdS['latest_date'])) . ')' : ''; ?>
                                &middot; Avg <strong><?php echo $gdS['events'] ? (int)$gdS['average'] : '&mdash;'; ?></strong>
                                <span style="font-size:10px">(<?php echo (int)$gdS['events']; ?> held)</span>
                            </span>
                        </div>
                        <?php if ($gdSer['labels']): ?>
                        <div class="chart-box chart-box-xs" style="height:150px"><canvas id="gaChart_<?php echo $gdT; ?>" aria-label="<?php echo htmlspecialchars($gdDef['label']); ?> trend"></canvas></div>
                        <?php else: ?>
                        <div class="text-muted small py-3 text-center">No <?php echo htmlspecialchars($gdDef['label']); ?> counts in this range yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Month by month (one year) ── -->
        <?php
        $mhAny = array_filter($sundayMonthly['sundays']) || array_filter(array_map('array_filter', $gaMonthly['events']));
        ?>
        <div class="border-top pt-3 mb-4" id="monthlyHealth">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                    <i class="bi bi-bar-chart me-1"></i>Month by Month &mdash; Average per Event
                    <span class="fw-normal text-lowercase">&mdash; <?php echo (int)$healthYear; ?></span>
                </span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Year">
                    <?php foreach ($healthYears as $hy): ?>
                    <a href="<?php echo htmlspecialchars($healthYearUrl((int)$hy)); ?>" class="btn btn-sm <?php echo (int)$hy === $healthYear ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo (int)$hy; ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if ($mhAny): ?>
            <div class="row g-3">
                <div class="col-lg-6"><div class="small fw-semibold mb-1">Sunday (average per Sunday)</div>
                    <div class="chart-box" style="height:200px"><canvas id="monthlySundayChart" aria-label="Sunday monthly averages"></canvas></div></div>
                <div class="col-lg-6"><div class="small fw-semibold mb-1">Prayer Meeting &amp; Students&rsquo; Hangout (average per gathering)</div>
                    <div class="chart-box" style="height:200px"><canvas id="monthlyGatheringChart" aria-label="Gathering monthly averages"></canvas></div></div>
            </div>
            <div class="small text-muted mt-1">Months with nothing recorded are left empty rather than drawn as zero.</div>
            <?php else: ?>
            <div class="alert alert-light border py-2 small mb-0"><i class="bi bi-info-circle me-1"></i>Nothing recorded for <?php echo (int)$healthYear; ?> yet.</div>
            <?php endif; ?>
        </div>

    </div>
</div>
