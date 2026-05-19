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
$groupStats = $groupModel->getStats();
$paStats = $paModel->getSummaryStats();
$paTotals = $paModel->getTotalByProgram();
$paUnmatched = $paModel->getUnmatchedCount();

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
                    <div class="card text-center h-100 stat-card">
                        <div class="card-body py-3">
                            <i class="bi bi-people display-5 text-primary mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $memberStats['total']; ?></div>
                            <small class="text-muted">Total Members</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center h-100 stat-card">
                        <div class="card-body py-3">
                            <i class="bi bi-person-check display-5 text-success mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $memberStats['active']; ?></div>
                            <small class="text-muted">Active</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center h-100 stat-card">
                        <div class="card-body py-3">
                            <i class="bi bi-diagram-3 display-5 text-info mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $groupStats['active']; ?></div>
                            <small class="text-muted">Active VG/LG</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center h-100 stat-card">
                        <div class="card-body py-3">
                            <i class="bi bi-people-fill display-5 text-primary mb-2" style="opacity:0.7;"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $groupStats['vg']; ?></div>
                            <small class="text-muted">Victory Groups</small>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card text-center h-100 stat-card">
                        <div class="card-body py-3">
                            <i class="bi bi-heart display-5 text-danger mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $groupStats['lg']; ?></div>
                            <small class="text-muted">Life Groups</small>
                        </div>
                    </div>
                </div>
                <?php if (isset($_SESSION['user']['accounttype']) && $_SESSION['user']['accounttype'] === 'admin'): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <?php
                    try { $userCount = $db->query("SELECT COUNT(*) FROM accounts WHERE accountstatus='active'")->fetchColumn(); }
                    catch(Exception $e) { $userCount = 0; }
                    ?>
                    <div class="card text-center h-100 stat-card">
                        <div class="card-body py-3">
                            <i class="bi bi-person-gear display-5 text-warning mb-2"></i>
                            <div class="h3 mb-0 fw-bold"><?php echo $userCount; ?></div>
                            <small class="text-muted">Admin Users</small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

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
                            Counts members with at least one active attendance record per class. Update by adding records on the relevant class page — flags refresh automatically.
                            </p>
                            <?php
                            $total = max($memberStats['total'], 1);
                            $steps = [
                                ['label' => 'Victory Weekend', 'key' => 'victory_weekend', 'color' => 'primary', 'icon' => 'bi-sun'],
                                ['label' => 'Church Community', 'key' => 'church_community', 'color' => 'secondary', 'icon' => 'bi-building'],
                                ['label' => 'Making Disciples', 'key' => 'making_disciples', 'color' => 'success', 'icon' => 'bi-person-plus'],
                                ['label' => 'Empowering Leaders', 'key' => 'empowering_leaders', 'color' => 'warning', 'icon' => 'bi-star'],
                                ['label' => 'Leadership 113', 'key' => 'leadership_113', 'color' => 'danger', 'icon' => 'bi-trophy'],
                            ];
                            foreach ($steps as $step):
                                $count = $memberStats[$step['key']];
                                $pct = $total > 0 ? round(($count / $total) * 100) : 0;
                            ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-semibold small"><i class="bi <?php echo $step['icon']; ?> me-1 text-<?php echo $step['color']; ?>"></i><?php echo $step['label']; ?></span>
                                    <span class="badge bg-<?php echo $step['color']; ?>"><?php echo $count; ?> / <?php echo $memberStats['total']; ?> (<?php echo $pct; ?>%)</span>
                                </div>
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-<?php echo $step['color']; ?>" role="progressbar"
                                         style="width: <?php echo $pct; ?>%;" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
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
                            $programOrder = ['victory_weekend', 'church_community', 'making_disciples', 'empowering_leaders', 'leadership_113'];
                            if (!empty($allYears)):
                            ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm mb-0">
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
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="index.php?action=attendanceRecords&program_type=<?php echo $pType; ?>" class="text-decoration-none text-dark">
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
                                            <td class="text-center"><?php echo $cnt > 0 ? '<a href="index.php?action=attendanceRecords&program_type='.$pType.'&program_year='.$yr.'" class="badge bg-light text-dark border text-decoration-none">' . $cnt . '</a>' : '<span class="text-muted">—</span>'; ?></td>
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

            <!-- Discipleship Records Analytics -->
            <div class="row mb-2">
                <div class="col-12"><h5 class="text-muted fw-bold" style="font-size:12px; letter-spacing:1px;">DISCIPLESHIP RECORDS ANALYTICS</h5></div>
            </div>

            <!-- Program Attendance Cards by Year -->
            <div class="row mb-4 g-3" id="discAnalyticsCards">
                <?php
                $programDefs = [
                    'victory_weekend'    => ['label' => 'Victory Weekend',    'color' => 'primary',   'icon' => 'bi-sun',         'short' => 'VW'],
                    'church_community'   => ['label' => 'Church Community',   'color' => 'secondary', 'icon' => 'bi-building',    'short' => 'CC'],
                    'making_disciples'   => ['label' => 'Making Disciples',   'color' => 'success',   'icon' => 'bi-person-plus', 'short' => 'MD'],
                    'empowering_leaders' => ['label' => 'Empowering Leaders', 'color' => 'warning',   'icon' => 'bi-star',        'short' => 'EL'],
                    'leadership_113'     => ['label' => 'Leadership 113',     'color' => 'danger',    'icon' => 'bi-trophy',      'short' => 'L113'],
                ];
                foreach ($programDefs as $pType => $pDef):
                    $yearData = $paStats[$pType] ?? [];
                    ksort($yearData);
                    $programTotal = array_sum($yearData);
                    // matched members for this program
                    $matchedCount = 0;
                    foreach ($paTotals as $row) { if ($row['program_type'] === $pType) { $matchedCount = $row['matched_members']; break; } }
                    // member flag count from discipleship journey sheet
                    $flagCount = $memberStats[$pType] ?? 0;
                ?>
                <div class="col-12 col-sm-6 col-lg-4 col-xl-2-4 program-analytics-card" data-program="<?php echo $pType; ?>">
                    <div class="card h-100 border-<?php echo $pDef['color']; ?> border-opacity-50">
                        <div class="card-header py-2 bg-<?php echo $pDef['color']; ?> bg-opacity-10">
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="fw-semibold small"><i class="bi <?php echo $pDef['icon']; ?> me-1 text-<?php echo $pDef['color']; ?>"></i><?php echo $pDef['label']; ?></span>
                                <span class="badge bg-<?php echo $pDef['color']; ?>"><?php echo $pDef['short']; ?></span>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <div class="d-flex gap-3 mb-3 text-center">
                                <div class="flex-fill">
                                    <div class="h4 fw-bold text-<?php echo $pDef['color']; ?> mb-0"><?php echo $programTotal; ?></div>
                                    <div style="font-size:10px;" class="text-muted text-uppercase">Attendees</div>
                                </div>
                                <div class="flex-fill border-start">
                                    <div class="h4 fw-bold text-success mb-0"><?php echo $matchedCount; ?></div>
                                    <div style="font-size:10px;" class="text-muted text-uppercase">Matched</div>
                                </div>
                                <div class="flex-fill border-start">
                                    <div class="h4 fw-bold text-secondary mb-0"><?php echo $flagCount; ?></div>
                                    <div style="font-size:10px;" class="text-muted text-uppercase">Members</div>
                                </div>
                            </div>
                            <?php if (!empty($yearData)): ?>
                            <div class="border-top pt-2">
                                <div style="font-size:10px;" class="text-muted fw-bold text-uppercase mb-2">Year Breakdown</div>
                                <?php foreach ($yearData as $yr => $cnt): ?>
                                <div class="d-flex align-items-center gap-2 mb-1 dash-yr-row" data-year="<?php echo $yr; ?>">
                                    <span class="badge bg-secondary" style="font-size:10px; min-width:38px;"><?php echo $yr; ?></span>
                                    <div class="flex-grow-1">
                                        <div class="progress" style="height:6px;">
                                            <div class="progress-bar bg-<?php echo $pDef['color']; ?>" style="width:<?php echo $programTotal>0?round($cnt/$programTotal*100):0; ?>%"></div>
                                        </div>
                                    </div>
                                    <span class="small fw-semibold" style="min-width:24px; text-align:right;"><?php echo $cnt; ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-muted small text-center mb-0 pt-2">No attendance records</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Year Filter + Volunteer Journey -->
            <div class="row mb-4 g-3">
                <!-- Year Filter for Cards -->
                <div class="col-12">
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                        <span class="text-muted small fw-bold" style="letter-spacing:1px;">FILTER BY YEAR:</span>
                        <button class="btn btn-sm btn-primary" onclick="filterDashYear('all')">All Years</button>
                        <?php foreach ($availableYears as $yr): ?>
                        <button class="btn btn-sm btn-outline-secondary" onclick="filterDashYear('<?php echo $yr; ?>')"><?php echo $yr; ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Volunteer Discipleship Journey Table -->
                <?php if (!empty($volunteerJourneyData)): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h6 class="mb-0"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Volunteer Discipleship Journey Breakdown</h6>
                            <span class="badge bg-light text-dark border"><?php echo count($volunteerJourneyData); ?> groups</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Volunteer Status</th>
                                            <th class="text-center">Members</th>
                                            <th class="text-center"><i class="bi bi-sun text-primary"></i> VW</th>
                                            <th class="text-center"><i class="bi bi-building text-secondary"></i> CC</th>
                                            <th class="text-center"><i class="bi bi-person-plus text-success"></i> MD</th>
                                            <th class="text-center"><i class="bi bi-star text-warning"></i> EL</th>
                                            <th class="text-center"><i class="bi bi-trophy text-danger"></i> L113</th>
                                            <th style="min-width:140px;">Overall Completion</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($volunteerJourneyData as $vr):
                                            $allSteps = (int)$vr['vw'] + (int)$vr['cc'] + (int)$vr['md'] + (int)$vr['el'] + (int)$vr['l113'];
                                            $maxSteps = (int)$vr['total'] * 5;
                                            $completionPct = $maxSteps > 0 ? round(($allSteps / $maxSteps) * 100) : 0;
                                            $pctColor = $completionPct >= 80 ? 'success' : ($completionPct >= 50 ? 'warning' : 'secondary');
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($vr['vol_status']); ?></td>
                                            <td class="text-center"><span class="badge bg-secondary"><?php echo $vr['total']; ?></span></td>
                                            <td class="text-center">
                                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?php echo $vr['vw']; ?></span>
                                                <?php $vpct = $vr['total']>0?round($vr['vw']/$vr['total']*100):0; ?>
                                                <div style="font-size:10px;" class="text-muted"><?php echo $vpct; ?>%</div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25"><?php echo $vr['cc']; ?></span>
                                                <?php $cpct = $vr['total']>0?round($vr['cc']/$vr['total']*100):0; ?>
                                                <div style="font-size:10px;" class="text-muted"><?php echo $cpct; ?>%</div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><?php echo $vr['md']; ?></span>
                                                <?php $mpct = $vr['total']>0?round($vr['md']/$vr['total']*100):0; ?>
                                                <div style="font-size:10px;" class="text-muted"><?php echo $mpct; ?>%</div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25"><?php echo $vr['el']; ?></span>
                                                <?php $epct = $vr['total']>0?round($vr['el']/$vr['total']*100):0; ?>
                                                <div style="font-size:10px;" class="text-muted"><?php echo $epct; ?>%</div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25"><?php echo $vr['l113']; ?></span>
                                                <?php $lpct = $vr['total']>0?round($vr['l113']/$vr['total']*100):0; ?>
                                                <div style="font-size:10px;" class="text-muted"><?php echo $lpct; ?>%</div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height:8px;">
                                                        <div class="progress-bar bg-<?php echo $pctColor; ?>" style="width:<?php echo $completionPct; ?>%"></div>
                                                    </div>
                                                    <small class="fw-semibold text-<?php echo $pctColor; ?>" style="min-width:35px;"><?php echo $completionPct; ?>%</small>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <script>
            function filterDashYear(yr) {
                document.querySelectorAll('.dash-yr-row').forEach(function(el) {
                    if (yr === 'all' || el.dataset.year === yr) {
                        el.style.display = '';
                    } else {
                        el.style.display = 'none';
                    }
                });
                // Update button states
                document.querySelectorAll('[onclick^="filterDashYear"]').forEach(function(btn) {
                    btn.classList.remove('btn-primary','btn-outline-secondary','btn-secondary');
                    if (btn.getAttribute('onclick') === "filterDashYear('" + yr + "')" || (yr==='all' && btn.getAttribute('onclick')==="filterDashYear('all')")) {
                        btn.classList.add('btn-primary');
                    } else {
                        btn.classList.add('btn-outline-secondary');
                    }
                });
            }
            </script>

        </div>
    </div>

<?php include 'shared/footer.php'; ?>
