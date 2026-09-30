<?php
if (!isset($_SESSION['user'])) {
    header('Location: index.php?action=login');
    exit();
}

require_once 'models/Member.php';
require_once 'models/VictoryGroup.php';
require_once 'models/ProgramAttendance.php';
$memberModel = new Member($db);
$groupModel = new VictoryGroup($db);
$paModel = new ProgramAttendance($db);
$memberStats = $memberModel->getStats();
// Discipleship Journey Overview reports "out of ACTIVE members", so it uses its
// own active-only roll-up rather than the all-members figures above.
$activeStats = $memberModel->getActiveStats();
$groupStats = $groupModel->getStats();
$paStats = $paModel->getSummaryStats();
$paTotals = $paModel->getTotalByProgram();
$paUnmatched = $paModel->getUnmatchedCount();
// Distinct PEOPLE per class (not rows) — the headline figure on the class cards.
$participantsByProgram = $paModel->getParticipantsByProgram();
// ── Dashboard filter (Average Attendance + Church Health) ──────────────────
// Both panels answer "how are we doing", so they share one filter rather than
// each carrying its own controls.
$dashClass  = $_GET['dash_class']  ?? '';
$dashYear   = (int)($_GET['dash_year'] ?? 0);
$dashBucket = in_array($_GET['dash_bucket'] ?? '', ['weekly','monthly','quarterly','annually'], true)
    ? $_GET['dash_bucket'] : 'monthly';
if (!array_key_exists($dashClass, ProgramAttendance::PROGRAM_LABELS)) $dashClass = '';

$attendanceAverages = $paModel->getAttendanceAverages($dashClass, $dashYear);
$churchHealth       = $paModel->getChurchHealthStats($dashClass, $dashYear);
$attendanceSeries   = $paModel->getAttendanceSeries($dashBucket, $dashClass, $dashYear,
                                                    $dashBucket === 'weekly' ? 16 : 12);
$dashYearOptions    = $paModel->getAvailableYears();
// Preserves the current filter when building a link that changes one facet.
$dashUrl = function (array $over = []) use ($dashClass, $dashYear, $dashBucket) {
    $q = array_merge(['dash_class' => $dashClass, 'dash_year' => $dashYear ?: '', 'dash_bucket' => $dashBucket], $over);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
};
$dashFilterCount = count(array_filter([$dashClass, $dashYear]));
$serviceStats       = $memberModel->getServiceAttendanceStats();
// Serve Teams roll-up (module is optional — tolerate a missing table).
$serveTeamStats = ['total' => 0, 'active' => 0, 'leaders' => 0, 'servers' => 0];
try {
    require_once 'models/ServeTeam.php';
    $serveTeamStats = (new ServeTeam($db))->getStats();
} catch (Exception $e) { /* Serve Teams not migrated yet — cards stay at zero. */ }

// Volunteer journey breakdown by discipleship steps
$volunteerJourneyData = [];
try {
    $vStmt = $db->query("
        SELECT COALESCE(NULLIF(TRIM(volunteer_status),''), 'Unspecified') as vol_status,
               COUNT(*) as total,
               SUM(victory_weekend) as vw,
               SUM(church_community) as cc,
               SUM(making_disciples) as md,
               SUM(empowering_leaders) as el,
               SUM(leadership_113) as l113
        FROM members
        WHERE is_deleted = 0
        GROUP BY COALESCE(NULLIF(TRIM(volunteer_status),''), 'Unspecified')
        ORDER BY total DESC
        LIMIT 12
    ");
    $volunteerJourneyData = $vStmt->fetchAll();
} catch(Exception $e) { $volunteerJourneyData = []; }

// Gather available years from paStats
$_allDashYrs = [];
foreach ($paStats as $_pType => $_yrs) { foreach (array_keys($_yrs) as $_yr) { $_allDashYrs[$_yr] = true; } }
ksort($_allDashYrs);
$availableYears = array_keys($_allDashYrs);

include 'shared/header.php';
?>

<body>
    <?php include 'shared/menu.php'; ?>

    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <h1 class="h3 mb-0"><i class="bi bi-house-door me-2 text-primary"></i>Dashboard</h1>
                    <p class="text-muted mb-0">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['user']['username']); ?></strong> &mdash; Victory Bacolod Admin Portal</p>
                </div>
            </div>

            <!-- Notification -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php
                $msgs = ['add' => 'Record added successfully.', 'update' => 'Record updated successfully.', 'delete' => 'Record deleted successfully.'];
                echo $msgs[$_GET['notif']] ?? 'Action completed.';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Stats Cards Row 1: Members -->
            <div class="row mb-2">
                <div class="col-12"><h5 class="text-muted fw-bold" style="font-size:12px; letter-spacing:1px;">MEMBERSHIP</h5></div>
            </div>
            <div class="row mb-4 g-3">
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="index.php?action=members" class="card text-center h-100 stat-card text-decoration-none text-reset" title="Open Members">
                        <div class="card-body py-3">
                            <i class="bi bi-people display-5 text-primary mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $memberStats['total']; ?></div>
                            <small class="text-muted">Total Members</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="index.php?action=members&member_status=active" class="card text-center h-100 stat-card text-decoration-none text-reset" title="Open active members">
                        <div class="card-body py-3">
                            <i class="bi bi-person-check display-5 text-success mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $memberStats['active']; ?></div>
                            <small class="text-muted">Active</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="index.php?action=victoryGroups&group_status=active" class="card text-center h-100 stat-card text-decoration-none text-reset" title="Open Victory Groups / LG">
                        <div class="card-body py-3">
                            <i class="bi bi-diagram-3 display-5 text-info mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $groupStats['active']; ?></div>
                            <small class="text-muted">Active VG/LG</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="index.php?action=victoryGroups&group_type=VG" class="card text-center h-100 stat-card text-decoration-none text-reset" title="Open Victory Groups">
                        <div class="card-body py-3">
                            <i class="bi bi-people-fill display-5 text-primary mb-2" style="opacity:0.7;"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $groupStats['vg']; ?></div>
                            <small class="text-muted">Victory Groups</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="index.php?action=victoryGroups&group_type=LG" class="card text-center h-100 stat-card text-decoration-none text-reset" title="Open Life Groups">
                        <div class="card-body py-3">
                            <i class="bi bi-heart display-5 text-danger mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $groupStats['lg']; ?></div>
                            <small class="text-muted">Life Groups</small>
                        </div>
                    </a>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="index.php?action=serveTeams" class="card text-center h-100 stat-card text-decoration-none text-reset" title="Open Serve Teams">
                        <div class="card-body py-3">
                            <i class="bi bi-hand-thumbs-up display-5 text-info mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo (int)$serveTeamStats['active']; ?></div>
                            <small class="text-muted">Serve Teams</small>
                        </div>
                    </a>
                </div>
                <?php if (isset($_SESSION['user']['accounttype']) && $_SESSION['user']['accounttype'] === 'admin'): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <?php
                    try { $userCount = $db->query("SELECT COUNT(*) FROM accounts WHERE accountstatus='active'")->fetchColumn(); }
                    catch(Exception $e) { $userCount = 0; }
                    ?>
                    <a href="index.php?action=users" class="card text-center h-100 stat-card text-decoration-none text-reset" title="Open Users">
                        <div class="card-body py-3">
                            <i class="bi bi-person-gear display-5 text-warning mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $userCount; ?></div>
                            <small class="text-muted">Admin Users</small>
                        </div>
                    </a>
                </div>
                <?php endif; ?>
            </div>


            <!-- Attendance & Church Health (one section, one shared filter) -->
            <?php include 'shared/dashboard_health.php'; ?>

            <!-- Worship Service Attendance -->
            <?php if (!empty($serviceStats)) include 'shared/dashboard_services.php'; ?>

            <!-- Discipleship Journey Progress -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h6 class="mb-0"><i class="bi bi-bar-chart-steps me-2"></i>Discipleship Journey Overview</h6>
                            <span class="badge bg-light text-muted border small" title="Auto-synced from Attendance Records — adding/removing a record updates the flag automatically.">
                                <i class="bi bi-arrow-repeat me-1"></i>Auto-synced
                            </span>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3"><i class="bi bi-info-circle me-1"></i>
                            Counts <strong>active</strong> members with at least one active attendance record per class. Update by adding records on the relevant class page — flags refresh automatically.
                            </p>
                            <?php
                            // Denominator is ACTIVE members (not all members), so both sides of
                            // every ratio below exclude inactive and soft-deleted people.
                            $activeTotal = (int)($activeStats['total'] ?? 0);
                            $total = max($activeTotal, 1);
                            $steps = [
                                ['label' => 'Victory Weekend',       'key' => 'victory_weekend',       'color' => 'primary',   'icon' => 'bi-sun',         'link' => 'index.php?action=attendanceRecords&program_type=victory_weekend'],
                                ['label' => 'Church Community',      'key' => 'church_community',      'color' => 'secondary', 'icon' => 'bi-building',    'link' => 'index.php?action=attendanceRecords&program_type=church_community'],
                                ['label' => 'Making Disciples',      'key' => 'making_disciples',      'color' => 'success',   'icon' => 'bi-person-plus', 'link' => 'index.php?action=attendanceRecords&program_type=making_disciples'],
                                ['label' => 'Empowering Leaders',    'key' => 'empowering_leaders',    'color' => 'warning',   'icon' => 'bi-star',        'link' => 'index.php?action=attendanceRecords&program_type=empowering_leaders'],
                                ['label' => 'Leadership 1-1-3',      'key' => 'leadership_113',        'color' => 'danger',    'icon' => 'bi-trophy',      'link' => 'index.php?action=leadership113'],
                                ['label' => 'Spiritual Foundations', 'key' => 'spiritual_foundations', 'color' => 'info',      'icon' => 'bi-shield',      'link' => 'index.php?action=spiritualFoundations'],
                            ];
                            foreach ($steps as $step):
                                // A step with no members.<column> yet simply reports 0.
                                if (!array_key_exists($step['key'], $activeStats)) continue;
                                $count = (int)$activeStats[$step['key']];
                                $pct = $activeTotal > 0 ? round(($count / $activeTotal) * 100) : 0;
                            ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <a href="<?php echo $step['link']; ?>" class="fw-semibold small text-decoration-none text-reset" title="Open <?php echo htmlspecialchars($step['label']); ?> records">
                                        <i class="bi <?php echo $step['icon']; ?> me-1 text-<?php echo $step['color']; ?>"></i><?php echo $step['label']; ?>
                                        <i class="bi bi-box-arrow-up-right ms-1 text-muted" style="font-size:9px;"></i>
                                    </a>
                                    <span class="badge bg-<?php echo $step['color']; ?>"><?php echo $count; ?> / <?php echo $activeTotal; ?> (<?php echo $pct; ?>%)</span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-<?php echo $step['color']; ?>" role="progressbar"
                                         style="width: <?php echo $pct; ?>%;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="border-top pt-2 mt-3 small text-muted">
                                <i class="bi bi-people me-1"></i>
                                Out of <strong><?php echo $activeTotal; ?></strong> Active Members
                                <span class="ms-1">(<?php echo (int)$memberStats['total']; ?> total on record)</span>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <a href="index.php?action=members" class="btn btn-sm btn-primary"><i class="bi bi-people me-1"></i>Manage Members</a>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="col-md-4">
                    <div class="card mb-3">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-lightning-charge me-2"></i>Quick Actions</h6>
                        </div>
                        <div class="card-body">
                            <h6 class="text-muted small fw-bold mb-2">MEMBERS</h6>
                            <div class="d-grid gap-2 mb-3">
                                <a href="index.php?action=members" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-person-plus me-1"></i> Add Member
                                </a>
                                <a href="index.php?action=members" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-people me-1"></i> View All Members
                                </a>
                            </div>
                            <h6 class="text-muted small fw-bold mb-2 mt-3">GROUPS</h6>
                            <div class="d-grid gap-2 mb-3">
                                <a href="index.php?action=victoryGroups" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-diagram-3 me-1"></i> Add VG / Life Group
                                </a>
                                <a href="index.php?action=victoryGroups" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-list-ul me-1"></i> View All Groups
                                </a>
                                <a href="index.php?action=serveTeams" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-hand-thumbs-up me-1"></i> Serve Teams
                                </a>
                            </div>
                            <h6 class="text-muted small fw-bold mb-2 mt-3">CLASSES</h6>
                            <div class="d-grid gap-2 mb-3">
                                <a href="index.php?action=attendanceRecords" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-calendar-check me-1"></i> Attendance Records
                                </a>
                                <a href="index.php?action=leadership113" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trophy me-1"></i> Leadership 1-1-3
                                </a>
                                <a href="index.php?action=spiritualFoundations" class="btn btn-sm btn-outline-info">
                                    <i class="bi bi-shield me-1"></i> Spiritual Foundations
                                </a>
                            </div>
                            <?php if (isset($_SESSION['user']['accounttype']) && $_SESSION['user']['accounttype'] === 'admin'): ?>
                            <h6 class="text-muted small fw-bold mb-2 mt-3">ADMINISTRATION</h6>
                            <div class="d-grid gap-2">
                                <a href="index.php?action=users" class="btn btn-sm btn-outline-warning">
                                    <i class="bi bi-person-gear me-1"></i> Manage Users
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- System Info -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>System Info</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-2 small"><strong>Role:</strong> <?php echo ucfirst($_SESSION['user']['accounttype'] ?? 'Editor'); ?></p>
                            <p class="mb-2 small"><strong>Username:</strong> <?php echo htmlspecialchars($_SESSION['user']['username']); ?></p>
                            <p class="mb-0 small"><strong>Last Login:</strong>
                                <?php
                                try {
                                    $stmt = $db->prepare("SELECT last_login FROM accounts WHERE id = ?");
                                    $stmt->execute([$_SESSION['user']['id']]);
                                    $r = $stmt->fetch();
                                    echo ($r && $r['last_login'] && $r['last_login'] != '0000-00-00 00:00:00')
                                        ? date('M d, Y g:i A', strtotime($r['last_login']))
                                        : 'First login';
                                } catch (Exception $e) { echo 'N/A'; }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Program Attendance Summary -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Program Attendance Summary</h6>
                            <span class="col-toggle" data-table="dashProgramSummaryTable" data-locked="0"></span>
                            <?php if ($paUnmatched > 0): ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i><?php echo $paUnmatched; ?> unmatched records</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php
                            $allYears = [];
                            foreach ($paStats as $pType => $years) {
                                foreach (array_keys($years) as $yr) { $allYears[$yr] = true; }
                            }
                            ksort($allYears);
                            $allYears = array_keys($allYears);
                            $programOrder = ['victory_weekend', 'church_community', 'making_disciples', 'empowering_leaders', 'leadership_113', 'spiritual_foundations'];
                            if (!empty($allYears)):
                            ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm mb-0" id="dashProgramSummaryTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Program</th>
                                            <?php foreach ($allYears as $yr): ?>
                                            <th class="text-center"><?php echo $yr; ?></th>
                                            <?php endforeach; ?>
                                            <th class="text-center fw-bold">Total</th>
                                            <th class="text-center">Matched Members</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $totalsRow = array_fill_keys($allYears, 0);
                                        $grandTotal = 0;
                                        $grandMatched = 0;
                                        // Build a lookup for totals by program type
                                        $paTotalsByType = [];
                                        foreach ($paTotals as $row) {
                                            $paTotalsByType[$row['program_type']] = $row;
                                        }
                                        foreach ($programOrder as $pType):
                                            if (!isset($paStats[$pType]) && !isset($paTotalsByType[$pType])) continue;
                                            $label = ProgramAttendance::PROGRAM_LABELS[$pType] ?? $pType;
                                            $color = ProgramAttendance::PROGRAM_COLORS[$pType] ?? 'secondary';
                                            $icon = ProgramAttendance::PROGRAM_ICONS[$pType] ?? 'bi-circle';
                                            $rowTotal = 0;
                                            $matched = $paTotalsByType[$pType]['matched_members'] ?? 0;
                                            $grandMatched += $matched;
                                            // Session-based classes have their own pages (the generic
                                            // attendance table can't render a per-session grid).
                                            $pLink = [
                                                'leadership_113'        => 'index.php?action=leadership113',
                                                'spiritual_foundations' => 'index.php?action=spiritualFoundations',
                                            ][$pType] ?? ('index.php?action=attendanceRecords&program_type=' . $pType);
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo $pLink; ?>" class="text-decoration-none text-dark">
                                                <span class="badge bg-<?php echo $color; ?> me-1"><i class="bi <?php echo $icon; ?>"></i></span>
                                                <span class="fw-semibold"><?php echo $label; ?></span>
                                                </a>
                                            </td>
                                            <?php foreach ($allYears as $yr):
                                                $cnt = $paStats[$pType][$yr] ?? 0;
                                                $rowTotal += $cnt;
                                                $totalsRow[$yr] += $cnt;
                                                $grandTotal += $cnt;
                                            ?>
                                            <?php $pYearLink = $pLink . (strpos($pLink, 'leadership113') !== false || strpos($pLink, 'spiritualFoundations') !== false ? '&year=' : '&program_year=') . $yr; ?>
                                            <td class="text-center"><?php echo $cnt > 0 ? '<a href="'.$pYearLink.'" class="badge bg-light text-dark border text-decoration-none">' . $cnt . '</a>' : '<span class="text-muted">—</span>'; ?></td>
                                            <?php endforeach; ?>
                                            <td class="text-center fw-bold text-<?php echo $color; ?>"><?php echo $rowTotal; ?></td>
                                            <td class="text-center">
                                                <?php if ($matched > 0): ?>
                                                <span class="badge bg-success"><?php echo $matched; ?></span>
                                                <?php else: ?>
                                                <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td>Total</td>
                                            <?php foreach ($allYears as $yr): ?>
                                            <td class="text-center"><?php echo $totalsRow[$yr] ?: '—'; ?></td>
                                            <?php endforeach; ?>
                                            <td class="text-center text-primary"><?php echo $grandTotal; ?></td>
                                            <td class="text-center text-success"><?php echo $grandMatched; ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <?php else: ?>
                            <p class="text-muted mb-0 text-center py-3"><i class="bi bi-info-circle me-1"></i>No attendance data yet. <a href="import.php">Run the import</a> to load program data.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ministry Breakdown -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Members by Ministry</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <?php
                                try {
                                    $stmt = $db->query("SELECT ministry, COUNT(*) as cnt FROM members WHERE member_status='active' AND ministry != '' AND is_deleted = 0 GROUP BY ministry ORDER BY cnt DESC LIMIT 12");
                                    $ministryData = $stmt->fetchAll();
                                    $colors = ['primary','success','info','warning','danger','secondary'];
                                    if (count($ministryData) > 0):
                                        foreach ($ministryData as $i => $row):
                                            $color = $colors[$i % count($colors)];
                                ?>
                                <div class="col-6 col-sm-4 col-md-3 col-lg-2">
                                    <div class="card border-<?php echo $color; ?> text-center p-2 h-100">
                                        <div class="fw-bold h5 mb-0 text-<?php echo $color; ?>"><?php echo $row['cnt']; ?></div>
                                        <small class="text-muted text-truncate d-block" title="<?php echo htmlspecialchars($row['ministry']); ?>"><?php echo htmlspecialchars($row['ministry']); ?></small>
                                    </div>
                                </div>
                                <?php endforeach;
                                    else: ?>
                                <div class="col-12"><p class="text-muted mb-0 text-center">No ministry data yet. <a href="index.php?action=members">Add members</a> to see breakdown.</p></div>
                                <?php endif;
                                } catch (Exception $e) { echo '<div class="col-12"><p class="text-muted text-center">No data available</p></div>'; }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class participation + volunteer journey (rewritten for clarity) -->
            <?php include 'shared/dashboard_analytics.php'; ?>

        </div>
    </div>

<?php include 'shared/footer.php'; ?>
<script>
// Attendance trend. Uses the same bounded .chart-box container as the other
// charts (fixed height + maintainAspectRatio:false), so it fills its box and
// can't feed its own size back into the layout.
(function () {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('dashTrendChart');
    if (!el) return;
    var labels = <?php echo json_encode($attendanceSeries['labels'] ?? []); ?>;
    var values = <?php echo json_encode($attendanceSeries['values'] ?? []); ?>;
    if (!labels.length) return;
    new Chart(el, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Attendance records',
                data: values,
                borderColor: 'rgba(23,66,245,.9)',
                backgroundColor: 'rgba(23,66,245,.12)',
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                fill: true,
                tension: .3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            resizeDelay: 120,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 0, autoSkipPadding: 8 } },
                y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } } }
            }
        }
    });
})();
</script>
