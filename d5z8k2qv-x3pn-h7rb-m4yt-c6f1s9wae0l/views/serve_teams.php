<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
require_once 'models/ServeTeam.php';
include 'shared/header.php';

require_once 'models/ServeOption.php';
$teams           = $teams           ?? [];
$teamStats       = $teamStats       ?? ['total' => 0, 'active' => 0, 'leaders' => 0, 'servers' => 0];
$ministries      = $ministries      ?? [];
$serveOptions    = $serveOptions    ?? ['service' => [], 'place' => []];
$serveOptionsAll = $serveOptionsAll ?? ['service' => [], 'place' => []];
$serviceDefaults = $serviceDefaults ?? [];
$optionUsage     = $optionUsage     ?? [];
$activeFilters   = $activeFilters   ?? [];
$isAdmin         = $isAdmin         ?? (($_SESSION['user']['accounttype'] ?? '') === 'admin');
$activeTab       = $activeTab       ?? 'teams';
$DAYS            = $DAYS            ?? ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

/** Renders a team's people list, linking registered members to their profile. */
if (!function_exists('renderServeList')):
function renderServeList(array $people): string {
    if (empty($people)) return '<span class="text-muted">—</span>';
    $out = '';
    foreach ($people as $p) {
        $out .= '<div>';
        if (!empty($p['member_id'])) {
            $out .= '<a href="index.php?action=memberProfile&id=' . (int)$p['member_id'] . '"'
                  . ' class="text-primary text-decoration-none" title="View member profile">'
                  . '<i class="bi bi-person-fill me-1 text-success" style="font-size:.75rem"></i>'
                  . htmlspecialchars($p['name']) . '</a>';
        } else {
            $out .= htmlspecialchars($p['name'])
                  . ' <span class="badge bg-warning text-dark" style="font-size:.65rem" title="Not in the Members list">'
                  . '<i class="bi bi-exclamation-triangle-fill me-1"></i>Unregistered</span>';
        }
        $out .= '</div>';
    }
    return $out;
}
endif;
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-hand-thumbs-up me-2 text-primary"></i>Serve Teams</h1>
                    <p class="text-muted mb-0">Volunteer teams, their leaders and the service they serve</p>
                </div>
                <?php if ($activeTab === 'teams'): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeamModal">
                    <i class="bi bi-plus-circle me-1"></i>Add Serve Team
                </button>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'teams' ? 'active' : ''; ?>"
                       href="index.php?action=serveTeams&tab=teams">
                        <i class="bi bi-table me-1"></i>Teams
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'options' ? 'active' : ''; ?>"
                       href="index.php?action=serveTeams&tab=options">
                        <i class="bi bi-sliders me-1"></i>Service &amp; Place Options
                    </a>
                </li>
            </ul>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php $msgs = [
                    'add'        => 'Serve team has been added successfully.',
                    'update'     => 'Serve team has been updated successfully.',
                    'activate'   => 'Serve team has been reactivated successfully.',
                    'deactivate' => 'Serve team has been deactivated successfully.',
                    'delete'     => 'Serve team has been deleted successfully.',
                    'opt_add'        => 'Value has been added to the list.',
                    'opt_update'     => 'Value has been updated. Teams using it were updated too.',
                    'opt_activate'   => 'Value has been reactivated.',
                    'opt_deactivate' => 'Value has been deactivated. Existing teams keep it.',
                    'opt_delete'     => 'Value was unused, so it has been deleted permanently.',
                    'opt_archived'   => 'Value is used by existing teams, so it was archived instead of deleted.',
                ]; echo $msgs[$_GET['notif']] ?? 'Done.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_GET['msg'] ?? 'An error occurred.'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($activeTab === 'options'): ?>
            <?php include __DIR__ . '/shared/serve_options_tab.php'; ?>
            <?php else: ?>

            <!-- Stat cards -->
            <div class="row mb-3 g-2">
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100 border-primary border-2"><div class="card-body py-2">
                        <div class="h4 fw-bold text-primary mb-0"><?php echo (int)$teamStats['total']; ?></div>
                        <div style="font-size:11px;" class="text-muted">Total Teams</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100"><div class="card-body py-2">
                        <div class="h4 fw-bold text-success mb-0"><?php echo (int)$teamStats['active']; ?></div>
                        <div style="font-size:11px;" class="text-muted">Active Teams</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100"><div class="card-body py-2">
                        <div class="h4 fw-bold text-info mb-0"><?php echo (int)$teamStats['leaders']; ?></div>
                        <div style="font-size:11px;" class="text-muted">Team Leaders</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100"><div class="card-body py-2">
                        <div class="h4 fw-bold text-warning mb-0"><?php echo (int)$teamStats['servers']; ?></div>
                        <div style="font-size:11px;" class="text-muted">People Serving</div>
                    </div></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="text-white fw-semibold"><i class="bi bi-funnel me-2"></i>Search & Filter</span>
                    <a href="index.php?action=serveTeams" class="btn btn-sm btn-outline-light"><i class="bi bi-x-circle me-1"></i>Clear</a>
                </div>
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="serveTeams">
                        <div class="col-md-4">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Ministry</label>
                            <select name="ministry" class="form-select form-select-sm">
                                <option value="">All ministries</option>
                                <?php foreach ($ministries as $min): ?>
                                <option value="<?php echo htmlspecialchars($min['name']); ?>" <?php echo ($activeFilters['ministry'] ?? '') === $min['name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($min['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Service</label>
                            <select name="service_name" class="form-select form-select-sm">
                                <option value="">All services</option>
                                <?php foreach (($serveOptions['service'] ?? []) as $svc): ?>
                                <option value="<?php echo htmlspecialchars($svc['name']); ?>" <?php echo ($activeFilters['service_name'] ?? '') === $svc['name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($svc['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Status</label>
                            <select name="team_status" class="form-select form-select-sm">
                                <option value="">All statuses</option>
                                <option value="active"   <?php echo ($activeFilters['team_status'] ?? '') === 'active'   ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($activeFilters['team_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel me-1"></i>Apply</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Teams table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-table me-2"></i>Serve Teams
                        <span class="badge bg-white text-dark border"><?php echo count($teams); ?></span>
                    </span>
                    <div class="d-flex align-items-center gap-2">
                        <?php // 11 columns: # (0) … Actions (10) — both stay locked. ?>
                        <span class="col-toggle" data-table="serveTeamsTable" data-locked="0,10"></span>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportServeTeamsCsv()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportServeTeamsExcel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="printServeTeams()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($teams)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-hand-thumbs-up display-4 mb-3 d-block"></i>
                        No serve teams yet. Click <strong>Add Serve Team</strong> to create the first one.
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="serveTeamsTable" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Team</th>
                                    <th>Ministry</th>
                                    <th>Service</th>
                                    <th>Day</th>
                                    <th>Call Time</th>
                                    <th>Place</th>
                                    <th>Leader(s)</th>
                                    <th>Members</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teams as $i => $t):
                                    $editJson = [
                                        'id'            => (int)$t['id'],
                                        'name'          => $t['name'],
                                        'ministry'      => $t['ministry'],
                                        'service_name'  => $t['service_name'] ?? '',
                                        'service_time'  => $t['service_time'],
                                        'day_of_week'   => $t['day_of_week'],
                                        'meetup_time'   => $t['meetup_time'],
                                        'meeting_place' => $t['meeting_place'],
                                        'team_status'   => $t['team_status'],
                                        'notes'         => $t['notes'],
                                        'leader_ids'    => array_map(fn($p) => (string)($p['member_id'] ?: $p['name']), $t['leaders']),
                                        'leader_labels' => array_map(fn($p) => $p['name'], $t['leaders']),
                                        'member_ids'    => array_map(fn($p) => (string)($p['member_id'] ?: $p['name']), $t['members']),
                                        'member_labels' => array_map(fn($p) => $p['name'], $t['members']),
                                    ];
                                    // Roster payload for the modal, and plain name lists for the
                                    // exports (the cells themselves only show a compact summary).
                                    $rosterJson = [
                                        'team'    => $t['name'],
                                        'service' => trim(($t['service_name'] ?? '') . ' ' . ($t['service_time'] ? '· ' . $t['service_time'] : '')),
                                        'leaders' => array_map(fn($p) => ['name' => $p['name'], 'member_id' => $p['member_id'] ? (int)$p['member_id'] : null], $t['leaders']),
                                        'members' => array_map(fn($p) => ['name' => $p['name'], 'member_id' => $p['member_id'] ? (int)$p['member_id'] : null], $t['members']),
                                    ];
                                    $leaderNamesCsv = implode('; ', array_column($t['leaders'], 'name'));
                                    $memberNamesCsv = implode('; ', array_column($t['members'], 'name'));
                                ?>
                                <tr>
                                    <td class="text-muted small"><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($t['name']); ?></td>
                                    <td class="small"><?php echo $t['ministry'] ? htmlspecialchars($t['ministry']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="small">
                                        <?php if (!empty($t['service_name'])): ?>
                                            <span class="badge bg-info text-dark"><i class="bi bi-calendar-event me-1"></i><?php echo htmlspecialchars($t['service_name']); ?></span>
                                            <?php if (!empty($t['service_time'])): ?>
                                            <div class="text-muted" style="font-size:10px;"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($t['service_time']); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo $t['day_of_week'] ? htmlspecialchars($t['day_of_week']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="small"><?php echo $t['meetup_time'] ? date('g:i A', strtotime($t['meetup_time'])) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="small"><?php echo $t['meeting_place'] ? htmlspecialchars($t['meeting_place']) : '<span class="text-muted">—</span>'; ?></td>
                                    <?php
                                    // Compact people cells. Listing 20 names vertically made a single
                                    // row taller than the whole table, so the cell shows a count plus
                                    // the first two names and the full roster opens in a modal.
                                    // data-export carries every name so CSV / Excel / Print stay complete.
                                    $renderPeopleCell = function (array $people, string $noun) use ($rosterJson) {
                                        if (empty($people)) return '<span class="text-muted">—</span>';
                                        $total   = count($people);
                                        $preview = array_slice($people, 0, 2);
                                        $out  = '<button type="button" class="btn btn-sm btn-outline-primary st-roster-btn mb-1"'
                                              . ' onclick="openTeamRosterModal(' . htmlspecialchars(json_encode($rosterJson), ENT_QUOTES) . ')">'
                                              . '<i class="bi bi-people-fill me-1"></i>' . $total . ' ' . $noun . ($total === 1 ? '' : 's')
                                              . '</button>';
                                        $names = [];
                                        foreach ($preview as $p) {
                                            $n = htmlspecialchars($p['name']);
                                            $names[] = !empty($p['member_id'])
                                                ? '<a href="index.php?action=memberProfile&id=' . (int)$p['member_id'] . '" class="text-decoration-none">' . $n . '</a>'
                                                : $n . ' <i class="bi bi-exclamation-triangle-fill text-warning" title="Not in the Members list"></i>';
                                        }
                                        $out .= '<div class="text-muted" style="font-size:10px; line-height:1.4;">' . implode(', ', $names);
                                        if ($total > count($preview)) {
                                            $out .= ' <span class="fw-semibold">+' . ($total - count($preview)) . ' more</span>';
                                        }
                                        $out .= '</div>';
                                        return $out;
                                    };
                                    ?>
                                    <td class="small" style="min-width:150px;" data-export="<?php echo htmlspecialchars($leaderNamesCsv); ?>">
                                        <?php echo $renderPeopleCell($t['leaders'], 'leader'); ?>
                                    </td>
                                    <td class="small" style="min-width:150px;" data-export="<?php echo htmlspecialchars($memberNamesCsv); ?>">
                                        <?php echo $renderPeopleCell($t['members'], 'member'); ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $t['team_status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($t['team_status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditTeamModal(<?php echo htmlspecialchars(json_encode($editJson)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($t['team_status'] === 'active'): ?>
                                        <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                           href="index.php?action=deactivateServeTeam&id=<?php echo (int)$t['id']; ?>"
                                           onclick="return confirm('Deactivate this team?')"><i class="bi bi-pause-circle"></i></a>
                                        <?php else: ?>
                                        <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                           href="index.php?action=activateServeTeam&id=<?php echo (int)$t['id']; ?>"><i class="bi bi-play-circle"></i></a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-danger" title="Delete"
                                           href="index.php?action=deleteServeTeam&id=<?php echo (int)$t['id']; ?>"
                                           onclick="return confirm('Delete this serve team? Membership history is kept but the team is hidden.');"><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Full team roster (keeps the table row one line tall) -->
            <div class="modal fade" id="teamRosterModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title">
                                <i class="bi bi-people-fill me-2"></i>Team Roster
                                <span class="small fw-normal ms-1" id="rosterSub"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.05em;">
                                        <i class="bi bi-star-fill text-primary me-1"></i>Leaders
                                        <span class="badge bg-primary ms-1" id="rosterLeaderCount">0</span>
                                    </div>
                                    <div id="rosterLeaders" class="d-flex flex-column gap-1"></div>
                                </div>
                                <div class="col-md-7 border-start">
                                    <div class="small fw-bold text-muted text-uppercase mb-2" style="letter-spacing:.05em;">
                                        <i class="bi bi-people me-1"></i>Members
                                        <span class="badge bg-secondary ms-1" id="rosterMemberCount">0</span>
                                    </div>
                                    <div id="rosterMembers" class="d-flex flex-column gap-1"></div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer justify-content-between py-2">
                            <span class="small text-muted">
                                <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>marks someone not yet in the Members list.
                            </span>
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <?php endif; /* end teams / options tab */ ?>

            <?php
            // Add + Edit modals share one field partial.
            $stModalId = 'addTeamModal'; $stFormId = 'addTeamForm';
            $stAction  = 'index.php?action=addServeTeam';
            $stTitle   = 'Add Serve Team'; $stHeaderClass = 'bg-primary';
            include 'shared/serve_team_modal.php';

            $stModalId = 'editTeamModal'; $stFormId = 'editTeamForm';
            $stAction  = '';
            $stTitle   = 'Edit Serve Team'; $stHeaderClass = 'bg-primary';
            include 'shared/serve_team_modal.php';
            ?>

        </div>
    </div>

<?php include 'shared/footer.php'; ?>
<script src="<?php echo asset('js/serve-teams.js'); ?>"></script>
<script>
// Service defaults drive the Day / Time auto-fill on the team form.
window.SERVE_CONFIG = {
    serviceDefaults: <?php echo json_encode($serviceDefaults); ?>,
    isAdmin:         <?php echo $isAdmin ? 'true' : 'false'; ?>
};
if (window.initServeTeamsPage) window.initServeTeamsPage();
</script>
