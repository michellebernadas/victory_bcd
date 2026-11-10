<?php
// Dashboard — Attendance & Church Health.
//
// One section with ONE shared filter: both panels answer the same question
// ("how are we doing"), so splitting them into two blocks with separate controls
// just duplicated chrome. Filter facets: class, year, and the trend bucket.
//
// Requires in scope: $attendanceAverages, $churchHealth, $attendanceSeries,
//                    $dashClass, $dashYear, $dashBucket, $dashYearOptions,
//                    $dashUrl, $dashFilterCount
$avgDefs = [
    'weekly'    => ['icon' => 'bi-calendar-week',  'color' => 'primary'],
    'monthly'   => ['icon' => 'bi-calendar-month', 'color' => 'success'],
    'quarterly' => ['icon' => 'bi-calendar3',      'color' => 'warning'],
    'annually'  => ['icon' => 'bi-calendar4',      'color' => 'danger'],
];
$dhCh = $churchHealth;
$healthCards = [
    ['label' => 'VG/LG Leaders',         'value' => (int)$dhCh['leaders'],          'sub' => 'active group leaders',     'color' => 'primary',   'icon' => 'bi-star-fill',      'link' => 'index.php?action=victoryGroups'],
    ['label' => 'Attendance / Leader',   'value' => $dhCh['attendance_per_leader'],  'sub' => 'records per leader',       'color' => 'success',   'icon' => 'bi-calendar-check', 'link' => 'index.php?action=attendanceRecords'],
    ['label' => 'VG Attendees / Leader', 'value' => $dhCh['attendees_per_leader'],   'sub' => 'group attendees / leader', 'color' => 'info',      'icon' => 'bi-people',         'link' => 'index.php?action=victoryGroups'],
    ['label' => 'Members / Leader',      'value' => $dhCh['members_per_leader'],     'sub' => 'active members / leader',  'color' => 'warning',   'icon' => 'bi-person-check',   'link' => 'index.php?action=members&member_status=active'],
    ['label' => 'Groups / Leader',       'value' => $dhCh['groups_per_leader'],      'sub' => 'active groups / leader',   'color' => 'secondary', 'icon' => 'bi-diagram-3',      'link' => 'index.php?action=victoryGroups'],
];
$bucketLabels = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annually' => 'Annually'];
?>
<div class="card mb-4" id="churchHealth">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0">
            <i class="bi bi-activity me-2"></i>Attendance &amp; Church Health
            <?php if ($dashFilterCount): ?>
            <span class="badge bg-white text-primary ms-1"><?php echo $dashFilterCount; ?> filter<?php echo $dashFilterCount === 1 ? '' : 's'; ?></span>
            <?php endif; ?>
        </h6>
        <div class="d-flex align-items-center flex-wrap gap-2">
            <?php if ($dashFilterCount): ?>
            <a href="index.php" class="btn btn-sm btn-outline-light"><i class="bi bi-x-circle me-1"></i>Clear</a>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-light" type="button"
                    data-bs-toggle="collapse" data-bs-target="#dashFilterBody" aria-expanded="<?php echo $dashFilterCount ? 'true' : 'false'; ?>">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
        </div>
    </div>

    <!-- Shared filter -->
    <div class="collapse<?php echo $dashFilterCount ? ' show' : ''; ?>" id="dashFilterBody">
        <div class="card-body border-bottom py-3 bg-light">
            <form method="GET" action="index.php" class="row g-2 align-items-end">
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
        <!-- ── Average attendance ── -->
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
            <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                <i class="bi bi-calendar-check me-1"></i>Average Attendance
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
                   class="card h-100 stat-card text-decoration-none text-reset border-<?php echo $dhDef['color']; ?><?php echo $dashBucket === $dhKey ? ' border-2' : ' border-opacity-50'; ?>"
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

        <!-- ── Sunday Service Attendance ── -->
        <?php
        // Recorded head counts from service_attendances. Its own range filter,
        // because it scopes a different table from the class filter above —
        // and ONE scope drives the four cards, the average and the chart.
        $saEmpty = ((int)($sundayStats['records'] ?? 0)) === 0;
        ?>
        <div class="border-top pt-3 mb-4" id="sundayAttendance">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                    <i class="bi bi-people-fill me-1"></i>Sunday Service Attendance
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
                $saCards = [
                    ['label' => 'Total Sunday Service', 'value' => $saHasL ? (int)$sundayLatest['adults'] : null,
                     'sub'   => 'latest Sunday · ' . $saLDate, 'color' => 'success', 'icon' => 'bi-people'],
                    ['label' => 'Total Kids Church', 'value' => $saHasL && (int)$sundayLatest['kids_missing'] < (int)$sundayLatest['services'] ? (int)$sundayLatest['kids'] : null,
                     'sub'   => $saHasL ? ((int)$sundayLatest['kids_missing'] > 0 ? ((int)$sundayLatest['kids_missing'] >= (int)$sundayLatest['services'] ? 'not recorded · ' . $saLDate : 'not recorded at ' . (int)$sundayLatest['kids_missing'] . ' of ' . (int)$sundayLatest['services'] . ' times') : 'latest Sunday · ' . $saLDate) : 'no entries yet',
                     'color' => 'info', 'icon' => 'bi-balloon'],
                    ['label' => 'Grand Total', 'value' => $saHasL ? (int)$sundayLatest['total'] : null,
                     'sub'   => $saHasL ? (int)$sundayLatest['services'] . ' service time' . ((int)$sundayLatest['services'] === 1 ? '' : 's') . ' · ' . $saLDate : 'no entries yet',
                     'color' => 'primary', 'icon' => 'bi-calendar-event'],
                    ['label' => 'Average per Sunday', 'value' => $saEmpty ? null : (int)$sundayStats['avg_total_day'],
                     'sub'   => $saEmpty ? 'no entries yet'
                              : number_format($sundayStats['avg_service_day']) . ' Sunday Service · '
                                . ($sundayStats['kids_days'] ? number_format($sundayStats['avg_kids_day']) : '—') . ' Kids Church (where recorded) · '
                                . (int)$sundayStats['sundays'] . ' Sunday' . ((int)$sundayStats['sundays'] === 1 ? '' : 's'),
                     'color' => 'warning', 'icon' => 'bi-calculator'],
                ];
                foreach ($saCards as $saCard): ?>
                <div class="col-6 col-lg-3">
                    <a href="index.php?action=serviceAttendance" class="card h-100 stat-card text-decoration-none text-reset border-<?php echo $saCard['color']; ?> border-opacity-25">
                        <div class="card-body py-2 px-2 text-center">
                            <i class="bi <?php echo $saCard['icon']; ?> text-<?php echo $saCard['color']; ?>"></i>
                            <div class="h4 fw-bold text-<?php echo $saCard['color']; ?> mb-0 mt-1">
                                <?php echo $saCard['value'] === null ? '&mdash;' : $saCard['value']; ?>
                            </div>
                            <div class="small fw-semibold" style="font-size:11px;"><?php echo htmlspecialchars($saCard['label']); ?></div>
                            <div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars($saCard['sub']); ?></div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($sundayTrend['labels'])): ?>
            <div class="mt-3">
                <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.05em">
                    <i class="bi bi-graph-up me-1"></i>Attendance Trend
                    <span class="fw-normal text-lowercase">(<?php echo count($sundayTrend['labels']); ?> service day<?php echo count($sundayTrend['labels']) === 1 ? '' : 's'; ?>)</span>
                </div>
                <div class="chart-box chart-box-sm"><canvas id="sundayTrendChart"></canvas></div>
                <div class="mt-2 small text-muted">
                    <i class="bi bi-calculator me-1"></i>
                    Per Sunday, all service times added together. Kids Church averages use only the Sundays where it was recorded
                    <?php if ($sundayStats['kids_missing']): ?>(not recorded at <?php echo (int)$sundayStats['kids_missing']; ?> service time<?php echo (int)$sundayStats['kids_missing'] === 1 ? '' : 's'; ?> in this range; the chart shows a gap there, not a zero)<?php endif; ?>.
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
                                <span style="font-size:10px">(<?php echo (int)$gdS['events']; ?>)</span>
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

        <!-- ── Church health ── -->
        <div class="border-top pt-3">
            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                    <i class="bi bi-heart-pulse me-1"></i>Church Health &mdash; Attendance per Leader
                </span>
                <a href="index.php?action=victoryGroups" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-diagram-3 me-1"></i>View Leaders
                </a>
            </div>
            <div class="row g-2">
                <?php foreach ($healthCards as $dhCard): ?>
                <div class="col-6 col-md-4 col-xl">
                    <a href="<?php echo $dhCard['link']; ?>" class="card h-100 stat-card text-decoration-none text-reset border-<?php echo $dhCard['color']; ?> border-opacity-25">
                        <div class="card-body py-2 px-2 text-center">
                            <i class="bi <?php echo $dhCard['icon']; ?> text-<?php echo $dhCard['color']; ?>"></i>
                            <div class="h5 fw-bold text-<?php echo $dhCard['color']; ?> mb-0"><?php echo $dhCard['value']; ?></div>
                            <div class="small fw-semibold" style="font-size:11px;"><?php echo $dhCard['label']; ?></div>
                            <div class="text-muted" style="font-size:10px;"><?php echo $dhCard['sub']; ?></div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ((int)$dhCh['leaders'] === 0):
                // Leaders on the schedule but not yet linked to a member record
                // (e.g. straight after the VG Schedule import) vs. none at all.
                $dhLeadersToLink = (int)(($groupStats['vg_leaders_to_link'] ?? 0) + ($groupStats['lg_leaders_to_link'] ?? 0));
            ?>
            <div class="alert alert-warning py-2 small mt-3 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?php if ($dhLeadersToLink > 0): ?>
                <?php echo $dhLeadersToLink; ?> Victory Group / Leadership Group leader<?php echo $dhLeadersToLink === 1 ? ' is' : 's are'; ?>
                on the schedule but not connected to a member profile yet, so the ratios can't be worked out.
                <?php if (function_exists('canAdminister') && canAdminister()): ?>
                <a href="index.php?action=needsLink" class="alert-link">Connect them to their profiles</a>.
                <?php endif; ?>
                <?php else: ?>
                No leaders found on active Victory Groups, so the ratios cannot be computed.
                Assign leaders on the <a href="index.php?action=victoryGroups" class="alert-link">Victory Groups</a> page.
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="mt-2 small text-muted">
                <i class="bi bi-calculator me-1"></i>
                <?php echo (int)$dhCh['attendance']; ?> attendance records &divide; <?php echo (int)$dhCh['leaders']; ?> leaders
                = <strong class="text-success"><?php echo $dhCh['attendance_per_leader']; ?></strong> per leader
                &nbsp;&middot;&nbsp; <?php echo (int)$dhCh['groups']; ?> active groups
                &nbsp;&middot;&nbsp; <?php echo (int)$dhCh['interns']; ?> interns in the pipeline.
                <br><em>Leader counts are current; the class/year filter applies to the attendance side of each ratio.</em>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
