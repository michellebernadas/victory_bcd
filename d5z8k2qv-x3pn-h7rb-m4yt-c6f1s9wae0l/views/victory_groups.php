<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';
$editGroup    = $editGroup    ?? null;
$vgOptions    = $vgOptions    ?? [];
$statDefs     = $statDefs     ?? [];
$statsGrouped = $statsGrouped ?? [];
$statSections = $statSections ?? [];
$activeTab    = $_GET['tab']  ?? 'groups';

$optTypes      = $vgOptions['group_type']        ?? [];
$optCats       = $vgOptions['group_category']    ?? [];
$optDays       = $vgOptions['day_of_week']       ?? [];
$optFreqs      = $vgOptions['meeting_frequency'] ?? [];

// Active reference sets used to flag cells whose value points to a deactivated VG option.
$activeTypeValues = array_flip(array_column($optTypes, 'value'));
$activeCatValues  = array_flip(array_column($optCats,  'value'));
$activeDayValues  = array_flip(array_column($optDays,  'value'));
$activeFreqValues = array_flip(array_column($optFreqs, 'value'));

function vg_inactive_icon(string $kind, string $value): string {
    $msg = htmlspecialchars(ucfirst($kind) . ' "' . $value . '" is inactive. Update this group to use an active ' . $kind . '.');
    return '<i class="bi bi-exclamation-triangle-fill text-warning ms-1" title="' . $msg . '" data-bs-toggle="tooltip"></i>';
}
$leaderNames   = $leaderNames   ?? [];
$internNames   = $internNames   ?? [];
$attendeeNames = $attendeeNames ?? [];
$distinctTimes = $distinctTimes ?? [];
$allMembers        = $allMembers        ?? [];
$distinctLocations = $distinctLocations ?? [];


function renderNameList($members, $showGender = true) {
    if (empty($members)) return '<span class="text-muted">—</span>';
    $out = '';
    foreach ($members as $m) {
        $out .= '<div>';
        if (!empty($m['member_id'])) {
            // Blue link to indicate a clickable hyperlink (consistent with rest of admin panel)
            $out .= '<a href="index.php?action=memberProfile&id=' . (int)$m['member_id'] . '"'
                  . ' class="text-primary text-decoration-none" title="View member profile">'
                  . '<i class="bi bi-person-fill me-1 text-success" style="font-size:.75rem"></i>'
                  . htmlspecialchars($m['name']) . '</a>';
        } else {
            $out .= htmlspecialchars($m['name']);
        }
        if ($showGender && !empty($m['gender'])) {
            $out .= ' <span class="badge bg-secondary bg-opacity-50 text-dark" style="font-size:.7rem">'
                  . htmlspecialchars($m['gender']) . '</span>';
        }
        if (empty($m['member_id'])) {
            $out .= ' <a href="index.php?action=members&openAdd=1" class="badge bg-warning text-dark text-decoration-none"'
                  . ' style="font-size:.65rem;cursor:pointer"'
                  . ' title="Not in Members list — click to add this person as a member">'
                  . '<i class="bi bi-exclamation-triangle-fill me-1"></i>Unregistered</a>';
        }
        $out .= '</div>';
    }
    return $out;
}
?>

<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-diagram-3 me-2 text-info"></i>Victory Groups / Leadership Groups</h1>
                    <p class="text-muted mb-0">Manage VG and LG schedules, leaders, and meeting details</p>
                </div>
                <?php if ($activeTab === 'groups'): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal" onclick="initAddModal()">
                    <i class="bi bi-plus-circle me-1"></i> Add Group
                </button>
                <?php elseif ($activeTab === 'statDefs'): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStatModal">
                    <i class="bi bi-plus-circle me-1"></i> Add Stat Definition
                </button>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'groups' ? 'active' : ''; ?>" href="index.php?action=victoryGroups&tab=groups">
                        <i class="bi bi-table me-1"></i>Groups List
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'stats' ? 'active' : ''; ?>" href="index.php?action=victoryGroups&tab=stats">
                        <i class="bi bi-bar-chart-line me-1"></i>VG Statistics
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'statDefs' ? 'active' : ''; ?>" href="index.php?action=victoryGroups&tab=statDefs">
                        <i class="bi bi-sliders me-1"></i>Stat Definitions
                    </a>
                </li>
            </ul>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php
                $msgs = [
                    'add'         => 'Victory Group has been added successfully.',
                    'update'      => 'Victory Group has been updated successfully. Changes are now reflected on the records.',
                    'deactivate'  => 'Victory Group has been deactivated successfully. Press the activate button to enable it again.',
                    'activate'    => 'Victory Group has been reactivated successfully.',
                    'delete'      => 'Victory Group has been deleted successfully. The record has been removed from the Victory Groups list.',
                    'stat_add'        => 'Stat definition has been added successfully.',
                    'stat_update'     => 'Stat definition has been updated successfully. Changes are now reflected on the records.',
                    'stat_delete'     => 'Stat definition has been deleted successfully. The record has been removed from the stats list.',
                    'stat_deactivate' => 'Stat definition has been deactivated successfully. It will no longer appear on the Statistics tab.',
                    'stat_activate'   => 'Stat definition has been reactivated successfully.',
                ];
                echo $msgs[$_GET['notif']] ?? 'Done.';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($_GET['msg'] ?? 'An error occurred.'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($activeTab === 'groups'): ?>

            <!-- Stat Cards (Groups List) -->
            <?php
                $vgTotal       = count($groups);
                $vgActive      = 0;
                $vgInactive    = 0;
                $vgLeaders     = 0;
                $vgInterns     = 0;
                foreach ($groups as $_g) {
                    if (strtolower($_g['group_status'] ?? '') === 'active') $vgActive++;
                    else $vgInactive++;
                    $vgLeaders += count($_g['leaders']   ?? []);
                    $vgInterns += count($_g['interns']   ?? []);
                }
            ?>
            <div class="row mb-3 g-2">
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100 border-primary border-2">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-primary mb-0"><?php echo $vgTotal; ?></div>
                            <div style="font-size:11px;" class="text-muted">Total Groups</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-success mb-0"><?php echo $vgActive; ?></div>
                            <div style="font-size:11px;" class="text-muted">Active Groups</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-secondary mb-0"><?php echo $vgInactive; ?></div>
                            <div style="font-size:11px;" class="text-muted">Inactive Groups</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-info mb-0"><?php echo $vgLeaders; ?></div>
                            <div style="font-size:11px;" class="text-muted">Total Leaders</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-warning mb-0"><?php echo $vgInterns; ?></div>
                            <div style="font-size:11px;" class="text-muted">Total Interns</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filter Panel -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="text-white fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-funnel me-2"></i>Search & Filter
                        <span id="activeFilterBadge" class="badge bg-white text-primary d-none">0 active</span>
                    </span>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="button" id="groupsClearFiltersBtn" class="btn btn-sm btn-outline-light d-none" onclick="clearAllFilters()">
                            <i class="bi bi-x-circle me-1"></i>Clear Filters
                        </button>
                        <button class="btn btn-sm btn-outline-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#filterBody" aria-expanded="true">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div class="collapse show" id="filterBody">
                    <div class="card-body py-3">

                        <!-- Row 1: Search -->
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" id="filterSearch" class="form-control"
                                           placeholder="Search by leader, intern, attendee, location, type...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('filterSearch').value=''; applyFilters();">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Row 2: Dropdowns row 1 -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-2">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">VG / LG Type</label>
                                <select id="filterType" class="filter-select2" multiple="multiple" data-placeholder="All types..." style="display:none">
                                    <?php foreach ($optTypes as $opt): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($opt['value'])); ?>">
                                        <?php echo htmlspecialchars($opt['label']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Day</label>
                                <select id="filterDay" class="filter-select2" multiple="multiple" data-placeholder="All days..." style="display:none">
                                    <?php foreach ($optDays as $opt): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($opt['value'])); ?>">
                                        <?php echo htmlspecialchars($opt['label']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Category</label>
                                <select id="filterCategory" class="filter-select2" multiple="multiple" data-placeholder="All categories..." style="display:none">
                                    <?php foreach ($optCats as $opt): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($opt['value'])); ?>">
                                        <?php echo htmlspecialchars($opt['label']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Frequency</label>
                                <select id="filterFreq" class="filter-select2" multiple="multiple" data-placeholder="All frequencies..." style="display:none">
                                    <?php foreach ($optFreqs as $opt): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($opt['value'])); ?>">
                                        <?php echo htmlspecialchars($opt['label']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Status</label>
                                <select id="filterStatus" class="filter-select2" multiple="multiple" data-placeholder="All statuses..." style="display:none">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <!-- Row 3: Name, location & time filters (from DB) -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-2">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">VG Leader</label>
                                <select id="filterLeader" class="filter-select2" multiple="multiple" data-placeholder="All leaders..." style="display:none">
                                    <?php foreach ($leaderNames as $n): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($n)); ?>"><?php echo htmlspecialchars($n); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Intern</label>
                                <select id="filterIntern" class="filter-select2" multiple="multiple" data-placeholder="All interns..." style="display:none">
                                    <?php foreach ($internNames as $n): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($n)); ?>"><?php echo htmlspecialchars($n); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Attendees</label>
                                <select id="filterAttendees" class="filter-select2" multiple="multiple" data-placeholder="All attendees..." style="display:none">
                                    <?php foreach ($attendeeNames as $n): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($n)); ?>"><?php echo htmlspecialchars($n); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Location</label>
                                <select id="filterLocation" class="filter-select2" multiple="multiple" data-placeholder="All locations..." style="display:none">
                                    <?php foreach ($distinctLocations as $loc): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($loc)); ?>"><?php echo htmlspecialchars($loc); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Time</label>
                                <select id="filterTime" class="filter-select2" multiple="multiple" data-placeholder="All times..." style="display:none">
                                    <?php foreach ($distinctTimes as $t): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower(date('g:i a', strtotime($t)))); ?>"><?php echo htmlspecialchars(date('g:i A', strtotime($t))); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Row 4: Quick flags -->
                        <div class="row g-3 mt-0">
                            <div class="col-auto d-flex align-items-end">
                                <button type="button" id="filterUnregistered"
                                        class="btn btn-sm btn-outline-warning"
                                        onclick="toggleUnregisteredFilter()"
                                        title="Show only groups that have at least one unregistered member">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Unregistered Members
                                </button>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Groups Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold d-flex align-items-center flex-wrap gap-2">
                        <i class="bi bi-table me-2"></i>Groups List
                        <span class="badge bg-white text-dark border" id="visibleCount"><?php echo count($groups); ?></span>
                        <span id="groupsHeaderBadges" class="d-flex flex-wrap gap-1"></span>
                    </span>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 me-3">
                            <label class="text-white small mb-0">Show</label>
                            <select id="groupsPerPage" class="form-select form-select-sm" style="width:auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="0">All</option>
                            </select>
                            <label class="text-white small mb-0">per page</label>
                        </div>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportCSV()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportExcel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportPDF()" title="Export PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="printGroups()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover dataTable mb-0" id="groupsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="type"      style="cursor:pointer">VG/LG <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="day"       style="cursor:pointer">DAY <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="time"      style="cursor:pointer">TIME <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="location"  style="cursor:pointer">LOCATION <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="leaders"   style="cursor:pointer">VICTORY GROUP LEADER(S) <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="interns"   style="cursor:pointer">INTERN(S) <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="attendees" style="cursor:pointer">ATTENDEES <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="category"  style="cursor:pointer">CATEGORY <span class="dt-column-order"></span></th>
                                    <th class="groups-sort dt-orderable-asc dt-orderable-desc" data-sort="freq"      style="cursor:pointer">FREQUENCY <span class="dt-column-order"></span></th>
                                    <th>NOTES</th>
                                    <th class="text-center groups-sort dt-orderable-asc dt-orderable-desc" data-sort="status" style="cursor:pointer">Status <span class="dt-column-order"></span></th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($groups as $i => $g): ?>
                                <?php
                                    $leaderStr   = strtolower(implode('|', array_column($g['leaders'],   'name')));
                                    $internStr   = strtolower(implode('|', array_column($g['interns'],   'name')));
                                    $attendeeStr = strtolower(implode('|', array_column($g['attendees'], 'name')));
                                    $timeStr     = $g['meeting_time'] ? date('g:i A', strtotime($g['meeting_time'])) : '';
                                    $searchStr   = strtolower($g['group_type'] . ' ' . $g['day_of_week'] . ' ' . $timeStr . ' ' . $g['location'] . ' ' . $leaderStr . ' ' . $internStr . ' ' . $attendeeStr . ' ' . $g['group_category'] . ' ' . $g['meeting_frequency'] . ' ' . $g['group_status']);
                                    $hasUnreg = false;
                                    foreach (array_merge($g['leaders'], $g['interns'], $g['attendees']) as $_m) {
                                        if (empty($_m['member_id'])) { $hasUnreg = true; break; }
                                    }
                                ?>
                                <tr class="group-row" id="group-<?php echo (int)$g['id']; ?>"
                                    data-type="<?php echo htmlspecialchars(strtolower($g['group_type'])); ?>"
                                    data-category="<?php echo htmlspecialchars(strtolower($g['group_category'])); ?>"
                                    data-day="<?php echo htmlspecialchars(strtolower($g['day_of_week'])); ?>"
                                    data-freq="<?php echo htmlspecialchars(strtolower($g['meeting_frequency'])); ?>"
                                    data-status="<?php echo htmlspecialchars(strtolower($g['group_status'])); ?>"
                                    data-time="<?php echo htmlspecialchars(strtolower($timeStr)); ?>"
                                    data-location="<?php echo htmlspecialchars(strtolower($g['location'])); ?>"
                                    data-leaders="<?php echo htmlspecialchars($leaderStr); ?>"
                                    data-interns="<?php echo htmlspecialchars($internStr); ?>"
                                    data-attendees="<?php echo htmlspecialchars($attendeeStr); ?>"
                                    data-search="<?php echo htmlspecialchars($searchStr); ?>"
                                    data-unregistered="<?php echo $hasUnreg ? '1' : '0'; ?>"
                                    data-notes="<?php echo htmlspecialchars($g['notes'] ?? ''); ?>">
                                    <td class="row-num"><?php echo $i + 1; ?></td>
                                    <td>
                                        <span class="badge <?php echo strpos($g['group_type'], 'VG') !== false ? 'bg-primary' : 'bg-info'; ?>"><?php echo htmlspecialchars($g['group_type']); ?></span>
                                        <?php if ($g['group_type'] !== '' && !isset($activeTypeValues[$g['group_type']])) echo vg_inactive_icon('group type', $g['group_type']); ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo htmlspecialchars($g['day_of_week']);
                                        // day_of_week may be a CSV like "Tue, Thu". Flag the whole cell if any token is inactive.
                                        $dayTokens = array_filter(array_map('trim', explode(',', $g['day_of_week'] ?? '')));
                                        $inactiveDays = array_filter($dayTokens, fn($d) => !isset($activeDayValues[$d]));
                                        if (!empty($inactiveDays)) echo vg_inactive_icon('day', implode(', ', $inactiveDays));
                                        ?>
                                    </td>
                                    <td><?php echo $g['meeting_time'] ? date('g:i A', strtotime($g['meeting_time'])) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($g['location']); ?></td>
                                    <td class="fw-semibold"><?php echo renderNameList($g['leaders']); ?></td>
                                    <td><?php echo renderNameList($g['interns']); ?></td>
                                    <td>
                                        <?php
                                        $att = $g['attendees'];
                                        if (empty($att)) {
                                            echo '<span class="text-muted">—</span>';
                                        } elseif (count($att) <= 4) {
                                            echo renderNameList($att, false);
                                        } else {
                                            // Show first 3 inline + a "Show all" toggle that reveals the rest below — no modal jump.
                                            $rest = count($att) - 3;
                                            $expandId = 'vgatt_' . $g['id'];
                                            echo renderNameList(array_slice($att, 0, 3), false);
                                            echo '<div class="mt-1"><button type="button" class="btn btn-link btn-sm p-0" style="font-size:11px"'
                                               . ' onclick="var el=document.getElementById(\'' . $expandId . '\'); var b=this; if(el.style.display===\'none\'){el.style.display=\'\'; b.innerHTML=\'<i class=&quot;bi bi-chevron-up me-1&quot;></i>Show less\';} else {el.style.display=\'none\'; b.innerHTML=\'<i class=&quot;bi bi-chevron-down me-1&quot;></i>+' . $rest . ' more\';}">'
                                               . '<i class="bi bi-chevron-down me-1"></i>+' . $rest . ' more</button></div>';
                                            echo '<div id="' . $expandId . '" style="display:none">' . renderNameList(array_slice($att, 3), false) . '</div>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo ucfirst(htmlspecialchars($g['group_category'])); ?>
                                        <?php if ($g['group_category'] !== '' && !isset($activeCatValues[$g['group_category']])) echo vg_inactive_icon('category', $g['group_category']); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo ucfirst(htmlspecialchars($g['meeting_frequency'])); ?></span>
                                        <?php if ($g['meeting_frequency'] !== '' && !isset($activeFreqValues[$g['meeting_frequency']])) echo vg_inactive_icon('frequency', $g['meeting_frequency']); ?>
                                    </td>
                                    <td class="text-muted small" style="max-width:180px"><?php echo !empty($g['notes']) ? htmlspecialchars($g['notes']) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $g['group_status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($g['group_status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditGroupModal(<?php echo htmlspecialchars(json_encode($g)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php
                                            $firstLeader = !empty($g['leaders']) ? $g['leaders'][0]['name'] : 'this group';
                                            $leaderAttr  = htmlspecialchars(addslashes($firstLeader));
                                        ?>
                                        <?php if ($g['group_status'] === 'active'): ?>
                                        <button class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                            onclick="confirmDeactivateGroup(<?php echo $g['id']; ?>, '<?php echo $leaderAttr; ?>')">
                                            <i class="bi bi-pause-circle"></i>
                                        </button>
                                        <?php else: ?>
                                        <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                            href="index.php?action=activateGroup&id=<?php echo $g['id']; ?>">
                                            <i class="bi bi-play-circle"></i>
                                        </a>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-danger" title="Delete"
                                           href="index.php?action=hardDeleteGroup&id=<?php echo $g['id']; ?>"
                                           onclick="return confirm('Permanently delete the group led by <?php echo $leaderAttr; ?>? This cannot be undone. Members must be removed first.');">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($groups)): ?>
                                <tr id="emptyRow"><td colspan="13" class="text-center text-muted py-4">No groups found. <a href="#" data-bs-toggle="modal" data-bs-target="#addGroupModal" onclick="initAddModal()">Add the first group</a>.</td></tr>
                                <?php endif; ?>
                                <tr id="noResultsRow" class="d-none">
                                    <td colspan="13" class="text-center text-muted py-4">
                                        <i class="bi bi-search me-2"></i>No groups match the selected filters.
                                        <a href="#" onclick="clearAllFilters(); return false;">Clear filters</a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination controls -->
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top" id="groupsPaginationBar">
                        <span class="text-muted small" id="groupsPaginationInfo"></span>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="groupsPaginationNav"></ul>
                        </nav>
                    </div>
                </div>
            </div>

            <?php elseif ($activeTab === 'stats'): ?>

            <?php
            $sectionColors = [
                'overview'       => 'secondary',
                'campus_vg'      => 'primary',
                'campus_vgl'     => 'info',
                'campus_intern'  => 'secondary',
                'men_vg'         => 'success',
                'men_vgl'        => 'success',
                'men_intern'     => 'success',
                'women_vg'       => 'danger',
                'women_vgl'      => 'danger',
                'women_intern'   => 'danger',
                'couples_vg'           => 'warning',
                'couples_vgl'          => 'warning',
                'couples_vgl_men'      => 'warning',
                'couples_vgl_women'    => 'warning',
                'couples_intern'       => 'warning',
                'couples_intern_men'   => 'warning',
                'couples_intern_women' => 'warning',
                'general_vg'     => 'dark',
                'general_vgl'    => 'dark',
                'general_intern' => 'dark',
            ];
            $sectionHex = [
                'overview'       => '#343a40',
                'campus_vg'      => '#0d6efd',
                'campus_vgl'     => '#0dcaf0',
                'campus_intern'  => '#6c757d',
                'men_vg'         => '#198754',
                'men_vgl'        => '#20c997',
                'men_intern'     => '#6dbe8e',
                'women_vg'       => '#dc3545',
                'women_vgl'      => '#e06b7a',
                'women_intern'   => '#e8a0a8',
                'couples_vg'           => '#ffc107',
                'couples_vgl'          => '#fd7e14',
                'couples_vgl_men'      => '#fd7e14',
                'couples_vgl_women'    => '#e8a020',
                'couples_intern'       => '#ffd966',
                'couples_intern_men'   => '#ffd966',
                'couples_intern_women' => '#ffe599',
                'general_vg'     => '#343a40',
                'general_vgl'    => '#495057',
                'general_intern' => '#6c757d',
            ];
            // Sections whose items are shown individually as separate cards/bars
            $expandSections = ['couples_vgl', 'couples_intern'];
            // Per-item hex overrides for expanded sections [sectionKey => [hex0, hex1, ...]]
            $expandHex = [
                'couples_vgl'    => ['#fd7e14', '#e8a020'],
                'couples_intern' => ['#ffd966', '#ffe599'],
            ];

            $statsForChart = [];
            foreach ($statsGrouped as $sectionKey => $rows) {
                if ($sectionKey === 'overview') continue;
                $color = $sectionColors[$sectionKey] ?? 'warning';
                $baseHex = $sectionHex[$sectionKey] ?? '#333333';
                $items = [];
                $total = 0;
                foreach ($rows as $row) {
                    if (!empty($row['is_total_row'])) { $total = (int)$row['count']; continue; }
                    $items[] = ['label' => $row['label'], 'count' => (int)$row['count']];
                }

                if (in_array($sectionKey, $expandSections) && count($items) > 1) {
                    // Expand each item as its own virtual entry
                    $hexList = $expandHex[$sectionKey] ?? [];
                    foreach ($items as $i => $item) {
                        $statsForChart[] = [
                            'key'   => $sectionKey . '_' . $i,
                            'label' => $item['label'],
                            'total' => $item['count'],
                            'items' => [$item],
                            'color' => $color,
                            'hex'   => $hexList[$i] ?? $baseHex,
                        ];
                    }
                } else {
                    $sectionLabel = $statSections[$sectionKey] ?? ucfirst($sectionKey);
                    $statsForChart[] = [
                        'key'   => $sectionKey,
                        'label' => $sectionLabel,
                        'total' => $total,
                        'items' => $items,
                        'color' => $color,
                        'hex'   => $baseHex,
                    ];
                }
            }
            ?>

            <!-- Stats Header -->
            <p class="text-muted mb-3 small"><i class="bi bi-info-circle me-1"></i>Live counts from active groups and their leaders/interns.</p>

            <?php if (empty($statsGrouped)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-bar-chart-line fs-1 d-block mb-2"></i>
                No stat definitions yet. <a href="#" data-bs-toggle="modal" data-bs-target="#addStatModal">Add the first one</a>.
            </div>
            <?php else: ?>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <?php foreach ($statsForChart as $s): ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <div class="card border-<?php echo $s['color']; ?> h-100">
                        <div class="card-body text-center p-3">
                            <div class="display-6 fw-bold text-<?php echo $s['color']; ?>"><?php echo $s['total']; ?></div>
                            <div class="small text-muted mt-1 lh-sm"><?php echo htmlspecialchars($s['label']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- CHARTS VIEW -->
            <div id="statsChartsView">

                <!-- Overview chart with type toggle -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <strong><i class="bi bi-bar-chart me-2"></i>Section Overview — Compare All</strong>
                        <div class="d-flex align-items-center gap-2">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-light active" id="ctBar"  onclick="switchOverviewChart('bar')">Bar</button>
                                <button class="btn btn-outline-light"        id="ctHBar" onclick="switchOverviewChart('hbar')">Horizontal</button>
                            </div>
                            <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('overviewChart','vg_section_overview')" title="Download PNG"><i class="bi bi-download me-1"></i>PNG</button>
                        </div>
                    </div>
                    <div class="card-body" style="position:relative;height:320px">
                        <canvas id="overviewChart"></canvas>
                    </div>
                </div>

                <!-- Category Totals — Campus vs Men vs Women vs Couples vs LG (rolled up) -->
                <?php
                // Define $pieGroups here so both Category Totals (this card) and the Pie View below can use it.
                $pieGroups = [
                    ['key'=>'campus',  'label'=>'Campus',  'color'=>'primary', 'prefix'=>'campus_'],
                    ['key'=>'men',     'label'=>'Men',     'color'=>'success', 'prefix'=>'men_'],
                    ['key'=>'women',   'label'=>'Women',   'color'=>'danger',  'prefix'=>'women_'],
                    ['key'=>'couples', 'label'=>'Couples', 'color'=>'warning', 'prefix'=>'couples_'],
                    ['key'=>'lg',      'label'=>'LG',      'color'=>'info',    'prefix'=>'lg'],
                ];
                $categoryTotals = [];
                foreach ($pieGroups as $pg) {
                    $sum = 0;
                    foreach ($statsForChart as $s) {
                        if (str_starts_with($s['key'], $pg['prefix'])) {
                            $sum += (int)$s['total'];
                        }
                    }
                    if ($sum > 0) {
                        $categoryTotals[] = [
                            'label' => $pg['label'],
                            'count' => $sum,
                            'hex'   => $sectionHex[$pg['key'].'_vg'] ?? ($sectionHex[$pg['key']] ?? '#0d6efd'),
                        ];
                    }
                }
                ?>
                <?php if (!empty($categoryTotals)): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <strong><i class="bi bi-bar-chart-steps me-2"></i>Category Totals</strong>
                            <span class="text-white-50 small">(Campus vs Men vs Women vs Couples vs LG)</span>
                        </div>
                        <button class="btn btn-sm btn-outline-light" onclick="exportChartPng('categoryTotalsChart','vg_category_totals')" title="Download PNG"><i class="bi bi-download me-1"></i>PNG</button>
                    </div>
                    <div class="card-body" style="position:relative;height:280px">
                        <canvas id="categoryTotalsChart"></canvas>
                    </div>
                </div>
                <script>
                    window.VG_CATEGORY_TOTALS = <?php echo json_encode($categoryTotals); ?>;
                </script>
                <?php endif; ?>

                <!-- Category Breakdown — Pie View -->
                <?php
                // $pieGroups was defined above (with the Category Totals card).
                $hasPieData = false;
                foreach ($pieGroups as $pg) {
                    foreach ($statsForChart as $s) {
                        if (str_starts_with($s['key'], $pg['prefix']) && $s['total'] > 0) { $hasPieData = true; break 2; }
                    }
                }
                ?>
                <?php if ($hasPieData): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        <strong><i class="bi bi-pie-chart me-2"></i>Category Breakdown</strong>
                        <span class="text-white-50 small">(VGs + Leaders + Interns combined per category)</span>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                        <?php foreach ($pieGroups as $pg):
                            $pieItems = [];
                            $pieTotal = 0;
                            foreach ($statsForChart as $s) {
                                if (str_starts_with($s['key'], $pg['prefix']) && $s['total'] > 0) {
                                    $pieItems[] = ['label' => $s['label'], 'count' => $s['total'], 'hex' => $s['hex']];
                                    $pieTotal += $s['total'];
                                }
                            }
                            // Skip pies with 0 or 1 slice (a 1-slice pie is just a filled circle — same info as the badge total).
                            if (count($pieItems) < 2) continue;
                        ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="card h-100">
                                <div class="card-header bg-<?php echo $pg['color']; ?> <?php echo $pg['color']==='text-white'; ?> py-2 d-flex justify-content-between align-items-center">
                                    <strong class="small"><?php echo htmlspecialchars($pg['label']); ?></strong>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-white text-dark"><?php echo $pieTotal; ?> total</span>
                                        <button class="btn btn-sm btn-outline-light py-0 px-2" onclick="exportChartPng('pie_<?php echo $pg['key']; ?>','vg_pie_<?php echo $pg['key']; ?>')" title="Download PNG"><i class="bi bi-download"></i></button>
                                    </div>
                                </div>
                                <div class="card-body" style="position:relative;height:240px">
                                    <canvas id="pie_<?php echo $pg['key']; ?>"></canvas>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /statsChartsView -->

            <!-- Summary (matches the 15 stats in Section Overview — Compare All; exportable) -->
            <?php
            $summaryRows = [];
            foreach ($statsForChart as $cs) {
                $summaryRows[] = ['label' => $cs['label'], 'count' => (int)$cs['total']];
            }
            $summaryGrandTotal = array_sum(array_column($summaryRows, 'count'));
            ?>
            <script>
                // Source-of-truth for the Summary export — built server-side so it doesn't depend on the DOM.
                window.VG_SUMMARY_ROWS  = <?php echo json_encode($summaryRows); ?>;
                window.VG_SUMMARY_TOTAL = <?php echo (int)$summaryGrandTotal; ?>;
            </script>
            <div class="card mt-4">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <strong><i class="bi bi-table me-2"></i>Summary</strong>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-sm btn-outline-light" onclick="exportVgSummaryCsv()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportVgSummaryExcel()" title="Export Excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
                        <button class="btn btn-sm btn-outline-light" onclick="exportVgSummaryPdf()" title="Export PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</button>
                        <button class="btn btn-sm btn-outline-light" onclick="printVgSummary()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0" id="vgSummaryTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:40px">#</th>
                                    <th>Label</th>
                                    <th class="text-center" style="width:120px">Count</th>
                                    <th class="text-center" style="width:110px">% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($summaryRows as $i => $row):
                                $pct = $summaryGrandTotal > 0 ? round($row['count'] / $summaryGrandTotal * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td class="text-muted"><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($row['label']); ?></td>
                                    <td class="text-center fw-bold"><?php echo $row['count']; ?></td>
                                    <td class="text-center text-muted"><?php echo $pct; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($summaryRows)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No stats configured yet.</td></tr>
                            <?php else: ?>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="2">Total</td>
                                    <td class="text-center"><?php echo $summaryGrandTotal; ?></td>
                                    <td class="text-center">100%</td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php endif; // empty statsGrouped ?>

            <?php elseif ($activeTab === 'statDefs'): ?>

            <!-- ═══════════════ STAT DEFINITIONS TAB ═══════════════ -->
            <p class="text-muted mb-3 small">
                <i class="bi bi-info-circle me-1"></i>
                Configure what gets counted on the Statistics tab. Each definition becomes a card and bar there.
                Drag rows to reorder the display.
            </p>

            <?php
            $sdLabels   = array_unique(array_column($statDefs, 'label'));
            sort($sdLabels);
            $sdSections = array_unique(array_column($statDefs, 'section'));
            sort($sdSections);
            $sdSources  = array_unique(array_filter(array_column($statDefs, 'count_source')));
            sort($sdSources);

            // Build id → count lookup from $statsGrouped (skips section-total rows that have no id).
            $sdCountById = [];
            foreach ($statsGrouped as $_secKey => $_rows) {
                foreach ($_rows as $_r) {
                    if (!empty($_r['is_total_row'])) continue;
                    if (!empty($_r['id'])) $sdCountById[(int)$_r['id']] = (int)($_r['count'] ?? 0);
                }
            }
            ?>
            <!-- Stat Definitions Filter -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="text-white fw-semibold d-flex align-items-center gap-2">
                        <i class="bi bi-funnel me-2"></i>Search &amp; Filter
                        <span id="statFilterBadge" class="badge bg-white text-primary" style="display:none">0 active</span>
                    </span>
                    <div class="d-flex gap-2 align-items-center">
                        <button type="button" id="statClearFilterBtn" class="btn btn-sm btn-outline-light" onclick="clearStatFilters()" style="display:none">
                            <i class="bi bi-x-circle me-1"></i>Clear Filters
                        </button>
                        <button class="btn btn-sm btn-outline-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#statFilterBody" aria-expanded="true">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div class="collapse show" id="statFilterBody">
                    <div class="card-body py-3">
                        <div class="row g-3 mb-3">
                            <div class="col-12">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" id="sdFilterSearch" class="form-control" placeholder="Search label or filter value...">
                                    <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('sdFilterSearch').value=''; applyStatFilters();">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Label</label>
                                <select id="sdFilterLabel" class="sd-filter-select2" multiple="multiple" data-placeholder="All labels..." style="display:none">
                                    <?php foreach ($sdLabels as $lbl): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($lbl)); ?>"><?php echo htmlspecialchars($lbl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Section</label>
                                <select id="sdFilterSection" class="sd-filter-select2" multiple="multiple" data-placeholder="All sections..." style="display:none">
                                    <?php foreach ($sdSections as $sec): ?>
                                    <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($statSections[$sec] ?? $sec); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Source</label>
                                <select id="sdFilterSource" class="sd-filter-select2" multiple="multiple" data-placeholder="All sources..." style="display:none">
                                    <?php foreach ($sdSources as $src): ?>
                                    <option value="<?php echo htmlspecialchars($src); ?>"><?php echo htmlspecialchars($src); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Status</label>
                                <select id="sdFilterActive" class="sd-filter-select2" data-placeholder="All..." style="display:none">
                                    <option value="">All</option>
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Manage Stat Definitions -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold d-flex align-items-center flex-wrap gap-2" id="statDefsHeaderBadges">
                        <i class="bi bi-sliders me-2"></i>Manage All Stat Definitions
                        <span class="badge bg-white text-dark border" id="statDefsCountBadge"><?php echo count($statDefs); ?></span>
                        <!-- One badge per active filter — populated by applyStatFilters() — mirrors the class pattern. -->
                    </span>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 me-3">
                            <label class="text-white small mb-0">Show</label>
                            <select id="statDefsPerPage" class="form-select form-select-sm" style="width:auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="-1">All</option>
                            </select>
                            <label class="text-white small mb-0">per page</label>
                        </div>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSdCsv()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSdExcel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSdPdf()" title="Export PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="printSd()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($statDefs)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-sliders display-4 mb-3 d-block"></i>
                        No stat definitions yet.<br>
                        <small><a href="#" data-bs-toggle="modal" data-bs-target="#addStatModal">Add the first one</a>.</small>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="width:100%" id="statDefsTable">
                            <thead>
                                <tr>
                                    <th style="width:40px">#</th>
                                    <th>Label</th>
                                    <th>Section</th>
                                    <th style="width:110px">Source</th>
                                    <th style="width:220px">Filter (Type / Category)</th>
                                    <th class="text-center" style="width:80px">Count</th>
                                    <th class="text-center" style="width:90px">Status</th>
                                    <th class="text-center" style="width:130px">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="statDefsBody">
                            <?php foreach ($statDefs as $i => $sd):
                                $sdCategory = $statSections[$sd['section']] ?? $sd['section'];
                                $sdCount    = $sdCountById[(int)$sd['id']] ?? 0;
                            ?>
                                <tr data-stat-id="<?php echo $sd['id']; ?>"
                                    data-label="<?php echo htmlspecialchars(strtolower($sd['label'])); ?>"
                                    data-filter="<?php echo htmlspecialchars(strtolower($sd['filter_value'])); ?>"
                                    data-section="<?php echo htmlspecialchars($sd['section']); ?>"
                                    data-source="<?php echo htmlspecialchars($sd['count_source']); ?>"
                                    data-active="<?php echo $sd['is_active'] ? '1' : '0'; ?>">
                                    <td class="text-muted small"><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($sd['label']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($sdCategory); ?></span></td>
                                    <td><?php echo htmlspecialchars($sd['count_source']); ?></td>
                                    <td style="max-width:220px;">
                                        <?php
                                        $fv     = (string)($sd['filter_value'] ?? '');
                                        $fvLim  = 32;
                                        $isLong = mb_strlen($fv) > $fvLim;
                                        $fvShort = $isLong ? mb_substr($fv, 0, $fvLim) . '…' : $fv;
                                        ?>
                                        <?php if ($isLong): ?>
                                            <code class="sd-fv-short" style="word-break:break-all;"><?php echo htmlspecialchars($fvShort); ?></code>
                                            <code class="sd-fv-full" style="display:none; word-break:break-all; white-space:normal;"><?php echo htmlspecialchars($fv); ?></code>
                                            <button type="button" class="btn btn-link btn-sm p-0 ms-1 sd-fv-toggle" style="font-size:11px;">See more</button>
                                        <?php else: ?>
                                            <code style="word-break:break-all;"><?php echo htmlspecialchars($fv); ?></code>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold"><?php echo $sdCount; ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $sd['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $sd['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                                onclick="openEditStatModal(<?php echo htmlspecialchars(json_encode($sd)); ?>)"><i class="bi bi-pencil"></i></button>
                                        <?php if ($sd['is_active']): ?>
                                        <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                           href="index.php?action=deactivateVglStat&id=<?php echo $sd['id']; ?>"
                                           onclick="return confirm('Deactivate this stat definition? It will be hidden from the Statistics tab.');">
                                            <i class="bi bi-pause-circle"></i>
                                        </a>
                                        <?php else: ?>
                                        <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                           href="index.php?action=activateVglStat&id=<?php echo $sd['id']; ?>">
                                            <i class="bi bi-play-circle"></i>
                                        </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"
                                                onclick="confirmDeleteStat(<?php echo $sd['id']; ?>, '<?php echo htmlspecialchars(addslashes($sd['label'])); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; // activeTab === 'groups' / 'stats' / 'statDefs' ?>

        </div>
    </div>

    <!-- ADD GROUP MODAL -->
    <div class="modal fade" id="addGroupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Victory Group / Leadership Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addGroup" id="addGroupForm" novalidate>
                <div class="modal-body">
                    <div id="addGroupErrors" class="alert alert-danger d-none py-2 mb-3">
                        <strong>Please fill in all required fields:</strong>
                        <ul class="mb-0 mt-1 ps-3" id="addGroupErrorList"></ul>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Group Type <span class="text-danger">*</span></label>
                            <select name="group_type" class="modal-select2" id="ag_type">
                                <option value="">— Select —</option>
                                <?php foreach ($optTypes as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select name="group_category" class="modal-select2" id="ag_category" data-tags="true">
                                <option value="">— Select or type —</option>
                                <?php foreach ($optCats as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Day of Week <span class="text-danger">*</span></label>
                            <select name="day_of_week[]" class="modal-select2" id="ag_day" multiple="multiple">
                                <?php foreach ($optDays as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Meeting Time <span class="text-danger">*</span></label>
                            <input type="time" name="meeting_time" id="ag_time" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Location <span class="text-danger">*</span></label>
                            <select name="location" class="modal-select2" id="ag_location" data-tags="true">
                                <option value="">— Select or type —</option>
                                <?php foreach ($distinctLocations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Meeting Frequency <span class="text-danger">*</span></label>
                            <select name="meeting_frequency" class="modal-select2" id="ag_freq" data-tags="true">
                                <option value="">— Select or type —</option>
                                <?php foreach ($optFreqs as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="group_status" class="modal-select2" id="ag_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Victory Group Leader(s) <span class="text-danger">*</span></label>
                            <div id="am-leaders"></div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addMemberRow('am-leaders','leader')"><i class="bi bi-plus me-1"></i>Add Leader</button>
                            <div id="am_leaders_error" class="text-danger small mt-1 d-none"><i class="bi bi-exclamation-circle me-1"></i>At least one leader is required.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Intern(s)</label>
                            <div id="am-interns"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addMemberRow('am-interns','intern')"><i class="bi bi-plus me-1"></i>Add Intern</button>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Attendees <span class="text-danger">*</span></label>
                            <div id="am-attendees"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addMemberRow('am-attendees','attendee')"><i class="bi bi-plus me-1"></i>Add Attendee</button>
                            <div id="am_attendees_error" class="text-danger small mt-1 d-none"><i class="bi bi-exclamation-circle me-1"></i>At least one attendee is required.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Group</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT GROUP MODAL -->
    <div class="modal fade" id="editGroupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editGroupForm" action="">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Group Type</label>
                            <select name="group_type" id="eg_type" class="modal-select2">
                                <option value="">— Select —</option>
                                <?php foreach ($optTypes as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="group_category" id="eg_category" class="modal-select2" data-tags="true">
                                <option value="">— Select or type —</option>
                                <?php foreach ($optCats as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Day of Week</label>
                            <select name="day_of_week[]" id="eg_day" class="modal-select2" multiple="multiple">
                                <?php foreach ($optDays as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Meeting Time</label>
                            <input type="time" name="meeting_time" id="eg_time" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Location</label>
                            <select name="location" id="eg_location" class="modal-select2" data-tags="true">
                                <option value="">— Select or type —</option>
                                <?php foreach ($distinctLocations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Frequency</label>
                            <select name="meeting_frequency" id="eg_freq" class="modal-select2" data-tags="true">
                                <option value="">— Select or type —</option>
                                <?php foreach ($optFreqs as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt['value']); ?>"><?php echo htmlspecialchars($opt['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="group_status" id="eg_status" class="modal-select2">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Victory Group Leader(s) <span class="text-danger">*</span></label>
                            <div id="em-leaders"></div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addMemberRow('em-leaders','leader')"><i class="bi bi-plus me-1"></i>Add Leader</button>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Intern(s)</label>
                            <div id="em-interns"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addMemberRow('em-interns','intern')"><i class="bi bi-plus me-1"></i>Add Intern</button>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Attendees</label>
                            <div id="em-attendees"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addMemberRow('em-attendees','attendee')"><i class="bi bi-plus me-1"></i>Add Attendee</button>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" name="notes" id="eg_notes" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update Group</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DEACTIVATE GROUP MODAL -->
    <div class="modal fade" id="deleteGroupModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-slash-circle me-2"></i>Deactivate Group</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Deactivate the group led by <strong id="deleteGroupLeader"></strong>?</p>
                    <p class="text-muted small mb-0">The group will be set to <strong>inactive</strong> and can be reactivated at any time.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <a id="deleteGroupBtn" href="#" class="btn btn-danger btn-sm"><i class="bi bi-slash-circle me-1"></i>Deactivate</a>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD STAT MODAL -->
    <div class="modal fade" id="addStatModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Stat Definition</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addVglStat" id="addStatForm" novalidate>
                <div class="modal-body">
                    <div id="addStatErrors" class="alert alert-danger d-none py-2 mb-3">
                        <strong>Please fill in all required fields:</strong>
                        <ul class="mb-0 mt-1 ps-3" id="addStatErrorList"></ul>
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Label <span class="text-danger">*</span></label>
                            <input type="text" name="label" id="as_label" class="form-control" placeholder="e.g. VGL: Men" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Section <span class="text-danger">*</span></label>
                            <select name="section" id="as_section" class="modal-select2">
                                <option value="">— Select —</option>
                                <?php foreach ($statSections as $k => $v): if ($k === 'overview') continue; ?>
                                <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Count Source <span class="text-danger">*</span></label>
                            <select name="count_source" id="as_source" class="modal-select2">
                                <option value="">— Select —</option>
                                <option value="victory_groups">victory_groups</option>
                                <option value="leaders">leaders</option>
                                <option value="interns">interns</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Filter Value(s) <span class="text-danger">*</span></label>
                            <input type="text" name="filter_value" id="as_filter" class="form-control" placeholder="e.g. VGcampusM  or  F,LGF">
                            <div class="form-text">For <em>victory_groups</em>: group_type value. For <em>leaders/interns</em>: gender code. Separate multiple with commas.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="is_active" id="as_active" class="modal-select2">
                                <option value="1" selected>Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT STAT MODAL -->
    <div class="modal fade" id="editStatModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Stat Definition</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editStatForm" action="">
                <div class="modal-body" id="editStatBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DELETE STAT MODAL -->
    <div class="modal fade" id="deleteStatModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Stat</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Delete <strong id="deleteStatLabel"></strong>?</p>
                    <p class="text-muted small mb-0">This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <a id="deleteStatBtn" href="#" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    var totalRows   = <?php echo count($groups); ?>;
    var ALL_MEMBERS = <?php echo json_encode(array_values($allMembers)); ?>;
    var gCurrentPage = 1;
    var gSortKey   = null;   // current sort column data-sort key
    var gSortDir   = 'asc';  // 'asc' | 'desc'

    // ── Column sort ────────────────────────────────────────────────────────
    // Click a sortable <th> → sort .group-row rows by that column's data-* attribute.
    // Visual style uses DataTables' dt-ordering-asc/desc classes so the arrow indicators
    // look identical to every other admin panel that uses DataTables.
    function sortGroupRows(key, dir) {
        var tbody = document.querySelector('#groupsTable tbody');
        if (!tbody) return;
        var rows = Array.from(tbody.querySelectorAll('tr.group-row'));
        rows.sort(function(a, b) {
            var va = (a.dataset[key] || '').toLowerCase();
            var vb = (b.dataset[key] || '').toLowerCase();
            if (va < vb) return dir === 'asc' ? -1 : 1;
            if (va > vb) return dir === 'asc' ? 1 : -1;
            return 0;
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
        // Toggle the dt-ordering-* class to match DataTables' default visual.
        document.querySelectorAll('.groups-sort').forEach(function(th) {
            th.classList.remove('dt-ordering-asc', 'dt-ordering-desc');
            if (th.dataset.sort === key) {
                th.classList.add(dir === 'asc' ? 'dt-ordering-asc' : 'dt-ordering-desc');
            }
        });
    }
    document.addEventListener('click', function(e) {
        var th = e.target.closest('.groups-sort');
        if (!th) return;
        var key = th.dataset.sort;
        if (gSortKey === key) gSortDir = (gSortDir === 'asc' ? 'desc' : 'asc');
        else { gSortKey = key; gSortDir = 'asc'; }
        sortGroupRows(gSortKey, gSortDir);
        if (typeof applyFilters === 'function') applyFilters();
    });

    function applyFilters() {
        var selType      = $('#filterType').val()      || [];
        var selDay       = $('#filterDay').val()       || [];
        var selCategory  = $('#filterCategory').val()  || [];
        var selFreq      = $('#filterFreq').val()      || [];
        var selStatus    = $('#filterStatus').val()    || [];
        var selLeaders   = $('#filterLeader').val()    || [];
        var selInterns   = $('#filterIntern').val()    || [];
        var selAttendees = $('#filterAttendees').val() || [];
        var selTimes     = $('#filterTime').val()      || [];
        var selLocations = $('#filterLocation').val()  || [];
        var txtSearch    = (document.getElementById('filterSearch') ? document.getElementById('filterSearch').value.trim().toLowerCase() : '');
        var filterUnreg  = document.getElementById('filterUnregistered');
        var showOnlyUnreg = filterUnreg && filterUnreg.classList.contains('active');

        var visible = 0;
        document.querySelectorAll('.group-row').forEach(function(row) {
            var d = row.dataset;
            var show = true;

            if (selType.length    && selType.indexOf(d.type)         === -1) show = false;
            if (selCategory.length && selCategory.indexOf(d.category) === -1) show = false;
            if (selFreq.length    && selFreq.indexOf(d.freq)          === -1) show = false;
            if (selStatus.length  && selStatus.indexOf(d.status)      === -1) show = false;
            if (txtSearch         && (d.search || '').indexOf(txtSearch) === -1) show = false;

            // Day: row may have multiple days ("Monday, Wednesday")
            if (selDay.length) {
                var rowDays = (d.day || '').split(',').map(function(x) { return x.trim().toLowerCase(); });
                if (!selDay.some(function(s) { return rowDays.indexOf(s) !== -1; })) show = false;
            }

            // Name filters: pipe-separated lowercase in data attr
            if (selLeaders.length) {
                var names = (d.leaders || '').split('|').filter(Boolean);
                if (!selLeaders.some(function(s) { return names.indexOf(s) !== -1; })) show = false;
            }
            if (selInterns.length) {
                var names = (d.interns || '').split('|').filter(Boolean);
                if (!selInterns.some(function(s) { return names.indexOf(s) !== -1; })) show = false;
            }
            if (selAttendees.length) {
                var names = (d.attendees || '').split('|').filter(Boolean);
                if (!selAttendees.some(function(s) { return names.indexOf(s) !== -1; })) show = false;
            }
            if (selTimes.length     && selTimes.indexOf(d.time)         === -1) show = false;
            if (selLocations.length && selLocations.indexOf(d.location) === -1) show = false;
            if (showOnlyUnreg && d.unregistered !== '1') show = false;

            row._passesFilter = show;
            row.style.display = 'none'; // hide all; pagination will show the right ones
            if (show) visible++;
        });

        // Pagination
        var perPage = parseInt(document.getElementById('groupsPerPage').value) || 0;
        var allRows = Array.from(document.querySelectorAll('.group-row'));
        var filteredRows = allRows.filter(function(r) { return r._passesFilter; });
        var totalPages = perPage > 0 ? Math.max(1, Math.ceil(filteredRows.length / perPage)) : 1;
        if (gCurrentPage > totalPages) gCurrentPage = totalPages;
        if (gCurrentPage < 1) gCurrentPage = 1;

        var start = perPage > 0 ? (gCurrentPage - 1) * perPage : 0;
        var end   = perPage > 0 ? start + perPage : filteredRows.length;
        var n = 1;
        filteredRows.forEach(function(row, idx) {
            if (idx >= start && idx < end) {
                row.style.display = '';
                var c = row.querySelector('.row-num');
                if (c) c.textContent = start + n++;
            }
        });

        var vc   = document.getElementById('visibleCount');
        if (vc)   vc.textContent = visible;
        var nr   = document.getElementById('noResultsRow');
        if (nr)   nr.classList.toggle('d-none', visible > 0 || totalRows === 0);

        // Pagination bar
        renderGroupsPagination(filteredRows.length, totalPages, perPage, start, end);

        // Active filter badge (filter panel header)
        var total = selType.length + selDay.length + selCategory.length + selFreq.length + selStatus.length
                  + selLeaders.length + selInterns.length + selAttendees.length + selTimes.length
                  + selLocations.length + (txtSearch ? 1 : 0) + (showOnlyUnreg ? 1 : 0);
        var badge = document.getElementById('activeFilterBadge');
        if (badge) { badge.textContent = total + ' active'; badge.classList.toggle('d-none', total === 0); }
        var clearBtn = document.getElementById('groupsClearFiltersBtn');
        if (clearBtn) clearBtn.classList.toggle('d-none', total === 0);

        // Inline filter chips in Groups List card header \u2014 show each active filter as a labeled chip.
        // Format: "Type: VG, LG \u00b7 Day: Sunday \u00b7 Leader: Aiber Arela" etc.
        var headerBadges = document.getElementById('groupsHeaderBadges');
        if (headerBadges) {
            var chips = [];
            function makeChip(label, values) {
                if (!values || !values.length) return;
                var arr = Array.isArray(values) ? values : [values];
                var disp = arr.length > 2 ? (arr.slice(0, 2).join(', ') + ' +' + (arr.length - 2) + ' more') : arr.join(', ');
                chips.push('<span class="badge bg-light text-dark border">' + label + ': ' + esc(disp) + '</span>');
            }
            makeChip('Type',      selType);
            makeChip('Category',  selCategory);
            makeChip('Day',       selDay);
            makeChip('Frequency', selFreq);
            makeChip('Status',    selStatus);
            makeChip('Leader',    selLeaders);
            makeChip('Intern',    selInterns);
            makeChip('Attendee',  selAttendees);
            makeChip('Time',      selTimes);
            makeChip('Location',  selLocations);
            if (txtSearch) {
                chips.push('<span class="badge bg-light text-dark border">Search: "' + esc(txtSearch) + '"</span>');
            }
            if (showOnlyUnreg) {
                chips.push('<span class="badge bg-warning text-dark"><i class="bi bi-person-exclamation me-1"></i>Unregistered only</span>');
            }
            // Cap at 4 chips for header; overflow \u2192 "+N more" pill
            var shown    = chips.slice(0, 4).join(' ');
            var overflow = chips.length - 4;
            if (overflow > 0) shown += ' <span class="badge bg-secondary">+' + overflow + ' more</span>';
            headerBadges.innerHTML = shown;
        }
    }

    function renderGroupsPagination(total, totalPages, perPage, start, end) {
        var info = document.getElementById('groupsPaginationInfo');
        var nav  = document.getElementById('groupsPaginationNav');
        var bar  = document.getElementById('groupsPaginationBar');
        if (!nav) return;

        if (perPage === 0 || totalPages <= 1) {
            if (bar) bar.style.display = total > 0 ? 'flex' : 'none';
            if (info) info.textContent = total > 0 ? 'Showing all ' + total + ' entries' : '';
            if (nav)  nav.innerHTML = '';
            return;
        }
        if (bar) bar.style.display = 'flex';
        var shown = Math.min(end, total) - start;
        if (info) info.textContent = 'Showing ' + (start + 1) + ' to ' + Math.min(end, total) + ' of ' + total + ' entries';

        var html = '';
        html += '<li class="page-item' + (gCurrentPage === 1 ? ' disabled' : '') + '">'
              + '<a class="page-link" href="#" onclick="gotoGroupsPage(' + (gCurrentPage - 1) + ');return false;">Previous</a></li>';
        var maxPages = 7;
        var half = Math.floor(maxPages / 2);
        var pStart = Math.max(1, gCurrentPage - half);
        var pEnd   = Math.min(totalPages, pStart + maxPages - 1);
        if (pEnd - pStart < maxPages - 1) pStart = Math.max(1, pEnd - maxPages + 1);
        if (pStart > 1)  html += '<li class="page-item disabled"><a class="page-link">…</a></li>';
        for (var p = pStart; p <= pEnd; p++) {
            html += '<li class="page-item' + (p === gCurrentPage ? ' active' : '') + '">'
                  + '<a class="page-link" href="#" onclick="gotoGroupsPage(' + p + ');return false;">' + p + '</a></li>';
        }
        if (pEnd < totalPages) html += '<li class="page-item disabled"><a class="page-link">…</a></li>';
        html += '<li class="page-item' + (gCurrentPage === totalPages ? ' disabled' : '') + '">'
              + '<a class="page-link" href="#" onclick="gotoGroupsPage(' + (gCurrentPage + 1) + ');return false;">Next</a></li>';
        nav.innerHTML = html;
    }

    function gotoGroupsPage(page) {
        gCurrentPage = page;
        applyFilters();
    }

    function clearAllFilters() {
        $('.filter-select2').val(null).trigger('change.select2');
        var s = document.getElementById('filterSearch');
        if (s) s.value = '';
        var fu = document.getElementById('filterUnregistered');
        if (fu) { fu.classList.remove('active', 'btn-warning'); fu.classList.add('btn-outline-warning'); }
        applyFilters();
    }

    function toggleUnregisteredFilter() {
        var btn = document.getElementById('filterUnregistered');
        var active = btn.classList.toggle('active');
        btn.classList.toggle('btn-warning', active);
        btn.classList.toggle('btn-outline-warning', !active);
        applyFilters();
    }

    var fsEl = document.getElementById('filterSearch');
    if (fsEl) fsEl.addEventListener('input', applyFilters);

    var ppEl = document.getElementById('groupsPerPage');
    if (ppEl) ppEl.addEventListener('change', function() { gCurrentPage = 1; applyFilters(); });


    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Build a member row with a Select2-enabled name picker
    function createMemberRow(type, name, gender, memberId) {
        var div = document.createElement('div');
        div.className = 'd-flex gap-2 mb-2 align-items-center member-row';
        var nameInput = '<select name="' + (type === 'attendee' ? 'attendee' : type) + '_name[]"'
            + ' class="form-select form-select-sm member-name-select flex-grow-1" style="min-width:0"></select>';
        var removeBtn = '<button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0"'
            + ' onclick="this.closest(\'.member-row\').remove()"><i class="bi bi-x"></i></button>';
        var unreg = (name && !memberId)
            ? '<span class="badge bg-warning text-dark flex-shrink-0 unregistered-badge" style="font-size:.65rem;cursor:pointer"'
              + ' title="Not in Members list — please register this person">'
              + '<i class="bi bi-exclamation-triangle-fill me-1"></i>Unregistered</span>'
            : '<span class="badge bg-warning text-dark flex-shrink-0 unregistered-badge d-none" style="font-size:.65rem;cursor:pointer"'
              + ' title="Not in Members list — please register this person">'
              + '<i class="bi bi-exclamation-triangle-fill me-1"></i>Unregistered</span>';
        if (type === 'attendee') {
            div.innerHTML = nameInput + unreg + removeBtn;
        } else {
            var genderInput = '<input type="text" name="' + type + '_gender[]" class="form-control form-control-sm flex-shrink-0"'
                + ' style="max-width:110px" value="' + esc(gender) + '" placeholder="Gender/Role" list="genderList">';
            div.innerHTML = nameInput + genderInput + unreg + removeBtn;
        }
        return div;
    }

    function initMemberRowSelect2(div, name, memberId) {
        var $sel   = $(div).find('.member-name-select');
        var $modal = $sel.closest('.modal');
        // Populate all registered members
        ALL_MEMBERS.forEach(function(m) {
            $sel.append(new Option(m.full_name, m.full_name));
        });
        // If current name not in list (unregistered / custom), add it
        if (name && !$sel.find('option[value="' + name + '"]').length) {
            $sel.prepend(new Option(name, name));
        }
        $sel.val(name || '');
        $sel.select2({
            dropdownParent: $modal.length ? $modal : $('body'),
            width: '100%',
            tags: true,
            placeholder: '— Select or type name —',
            allowClear: true
        });
        // Toggle unregistered badge when selection changes
        $sel.on('select2:select select2:clear', function() {
            var val = $(this).val() || '';
            var isReg = val && ALL_MEMBERS.some(function(m) { return m.full_name === val; });
            $(this).closest('.member-row').find('.unregistered-badge')
                .toggleClass('d-none', !val || isReg);
        });
    }

    function addMemberRow(cid, type) {
        var div = createMemberRow(type, '', '', null);
        document.getElementById(cid).appendChild(div);
        initMemberRowSelect2(div, '', null);
    }

    function populateMembers(cid, type, members) {
        var c = document.getElementById(cid);
        c.innerHTML = '';
        if (members && members.length > 0) {
            members.forEach(function(m) {
                var div = createMemberRow(type, m.name || '', m.gender || '', m.member_id || null);
                c.appendChild(div);
                initMemberRowSelect2(div, m.name || '', m.member_id || null);
            });
        } else {
            var div = createMemberRow(type, '', '', null);
            c.appendChild(div);
            initMemberRowSelect2(div, '', null);
        }
    }

    function initAddModal() {
        populateMembers('am-leaders','leader',[]);
        populateMembers('am-interns','intern',[]);
        populateMembers('am-attendees','attendee',[]);
    }

    var pendingEditGroup = null;

    function openEditGroupModal(g) {
        document.getElementById('eg_time').value  = g.meeting_time || '';
        document.getElementById('eg_notes').value = g.notes        || '';
        document.getElementById('editGroupForm').action = 'index.php?action=updateGroup&id=' + g.id;
        populateMembers('em-leaders',   'leader',   g.leaders   || []);
        populateMembers('em-interns',   'intern',   g.interns   || []);
        populateMembers('em-attendees', 'attendee', g.attendees || []);
        window.pendingEditGroup = g;
        new bootstrap.Modal(document.getElementById('editGroupModal')).show();
    }

    function confirmDeactivateGroup(id, leader) {
        document.getElementById('deleteGroupLeader').textContent = leader;
        document.getElementById('deleteGroupBtn').href = 'index.php?action=deleteGroup&id=' + id;
        new bootstrap.Modal(document.getElementById('deleteGroupModal')).show();
    }

    // ── Add Group form validation ─────────────────────────────────────────────
    (function() {
        var REQUIRED = [
            { id: 'ag_type',     label: 'Group Type' },
            { id: 'ag_category', label: 'Category' },
            { id: 'ag_day',      label: 'Day of Week' },
            { id: 'ag_time',     label: 'Meeting Time' },
            { id: 'ag_location', label: 'Location' },
            { id: 'ag_freq',     label: 'Meeting Frequency' },
        ];

        function markSelect2Invalid(id, invalid) {
            var $sel = $('#' + id).next('.select2-container').find('.select2-selection');
            $sel.toggleClass('border border-danger', invalid);
        }
        function markInputInvalid(id, invalid) {
            document.getElementById(id).classList.toggle('is-invalid', invalid);
        }

        document.getElementById('addGroupForm').addEventListener('submit', function(e) {
            var errors = [];

            // Clear previous error states
            REQUIRED.forEach(function(f) {
                if (f.id === 'ag_time') {
                    markInputInvalid(f.id, false);
                } else {
                    markSelect2Invalid(f.id, false);
                }
            });
            $('#am_leaders_error').addClass('d-none');
            $('#am_attendees_error').addClass('d-none');

            // Validate each required field
            REQUIRED.forEach(function(f) {
                var val = (f.id === 'ag_time')
                    ? document.getElementById(f.id).value
                    : ($('#' + f.id).val() || '');
                var empty = !val || (Array.isArray(val) && val.length === 0) || val === '';
                if (empty) {
                    errors.push(f.label);
                    if (f.id === 'ag_time') {
                        markInputInvalid(f.id, true);
                    } else {
                        markSelect2Invalid(f.id, true);
                    }
                }
            });

            // At least one leader
            var hasLeader = false;
            $('#am-leaders .member-name-select').each(function() {
                if ($(this).val()) { hasLeader = true; return false; }
            });
            if (!hasLeader) {
                errors.push('Victory Group Leader (at least one required)');
                $('#am_leaders_error').removeClass('d-none');
            }

            // At least one attendee
            var hasAttendee = false;
            $('#am-attendees .member-name-select').each(function() {
                if ($(this).val()) { hasAttendee = true; return false; }
            });
            if (!hasAttendee) {
                errors.push('Attendees (at least one required)');
                $('#am_attendees_error').removeClass('d-none');
            }

            if (errors.length > 0) {
                e.preventDefault();
                var $errBox  = $('#addGroupErrors');
                var $errList = $('#addGroupErrorList');
                $errList.empty();
                errors.forEach(function(msg) { $errList.append('<li>' + msg + '</li>'); });
                $errBox.removeClass('d-none');
                // Scroll to top of modal body
                document.querySelector('#addGroupModal .modal-body').scrollTo({ top: 0, behavior: 'smooth' });
            }
        });

        // Clear error state when modal is closed/reset
        document.getElementById('addGroupModal').addEventListener('hidden.bs.modal', function() {
            $('#addGroupErrors').addClass('d-none');
            REQUIRED.forEach(function(f) {
                if (f.id === 'ag_time') markInputInvalid(f.id, false);
                else markSelect2Invalid(f.id, false);
            });
            $('#am_leaders_error').addClass('d-none');
            $('#am_attendees_error').addClass('d-none');
        });
    })();

    // ── VG Statistics Charts ──────────────────────────────────────────────────
    var STATS_DATA = <?php echo json_encode(array_values($statsForChart ?? [])); ?>;
    var STAT_LABELS = <?php echo json_encode(array_values($statLabels ?? [])); ?>;

    var PIE_GROUPS = [
        { key: 'campus',  label: 'Campus',  prefix: 'campus_' },
        { key: 'men',     label: 'Men',     prefix: 'men_' },
        { key: 'women',   label: 'Women',   prefix: 'women_' },
        { key: 'couples', label: 'Couples', prefix: 'couples_' },
        { key: 'lg',      label: 'LG',      prefix: 'lg' },
    ];

    var overviewChartInst = null;
    var pieChartInsts = {};

    function shadeColor(hex, count) {
        var r = parseInt(hex.slice(1,3),16), g = parseInt(hex.slice(3,5),16), b = parseInt(hex.slice(5,7),16);
        var shades = [];
        for (var i = 0; i < count; i++) {
            var t = count === 1 ? 0.7 : 0.35 + (i / (count - 1)) * 0.65;
            shades.push('rgba(' + Math.round(r*t+255*(1-t)) + ',' + Math.round(g*t+255*(1-t)) + ',' + Math.round(b*t+255*(1-t)) + ',0.9)');
        }
        return shades;
    }

    function buildOverviewChart(type) {
        var el = document.getElementById('overviewChart');
        if (!el) return;
        if (overviewChartInst) { overviewChartInst.destroy(); overviewChartInst = null; }
        var labels  = STATS_DATA.map(function(s){ return s.label; });
        var data    = STATS_DATA.map(function(s){ return s.total; });
        var colors  = STATS_DATA.map(function(s){ return s.hex; });
        var bgColors= colors.map(function(c){ return c + 'cc'; });

        var isRadar    = type === 'radar';
        var isPolar    = type === 'polarArea';
        var isHBar     = type === 'hbar';
        var chartType  = isHBar ? 'bar' : type;

        var dataset = { label: 'Total', data: data, backgroundColor: (isRadar ? 'rgba(13,110,253,0.25)' : bgColors), borderColor: (isRadar ? '#0d6efd' : colors), borderWidth: 2 };
        if (isRadar) { dataset.pointBackgroundColor = '#0d6efd'; dataset.pointRadius = 4; }

        overviewChartInst = new Chart(el, {
            type: chartType,
            data: { labels: labels, datasets: [dataset] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: isHBar ? 'y' : 'x',
                plugins: {
                    legend: { display: isPolar || isRadar },
                    tooltip: { callbacks: { label: function(c){ return '  ' + c.formattedValue; } } }
                },
                scales: (isRadar || isPolar) ? (isRadar ? { r: { beginAtZero: true, ticks: { stepSize: 1 } } } : {}) : {
                    [isHBar ? 'x' : 'y']: { beginAtZero: true, ticks: { stepSize: 1 } }
                }
            }
        });
    }

    function buildPieCharts() {
        PIE_GROUPS.forEach(function(pg) {
            var el = document.getElementById('pie_' + pg.key);
            if (!el) return;
            if (pieChartInsts[pg.key]) { pieChartInsts[pg.key].destroy(); }
            var items = [];
            STATS_DATA.forEach(function(s) {
                if (s.key.indexOf(pg.prefix) === 0 && s.total > 0) {
                    items.push({ label: s.label, count: s.total, hex: s.hex });
                }
            });
            if (!items.length) return;
            var labels  = items.map(function(i){ return i.label; });
            var data    = items.map(function(i){ return i.count; });
            var bgColors= items.map(function(i){ return i.hex + 'cc'; });
            var borders = items.map(function(i){ return i.hex; });
            pieChartInsts[pg.key] = new Chart(el, {
                type: 'pie',
                data: { labels: labels, datasets: [{ data: data, backgroundColor: bgColors, borderColor: borders, borderWidth: 1 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12, padding: 8 } },
                        tooltip: { callbacks: { label: function(c){
                            var total = c.dataset.data.reduce(function(a,b){return a+b;},0);
                            var pct = total > 0 ? Math.round(c.raw/total*100) : 0;
                            return '  ' + c.label + ': ' + c.raw + ' (' + pct + '%)';
                        }}}
                    }
                }
            });
        });
    }

    function switchOverviewChart(type) {
        ['Bar','HBar'].forEach(function(t){
            var btn = document.getElementById('ct'+t);
            if (btn) btn.classList.toggle('active', t.toLowerCase() === type || (t==='HBar' && type==='hbar'));
        });
        buildOverviewChart(type);
    }

    // ── VG Summary table export helpers (mirrors the Session Summary pattern on the classes) ──
    // Data is sourced from window.VG_SUMMARY_ROWS / VG_SUMMARY_TOTAL (rendered by PHP),
    // so exports never depend on whether the table is visible or any DOM-scraping detail.
    function _vgSummaryTableData() {
        var headers = ['#', 'Label', 'Count', '% of Total'];
        var rows = [];
        var src   = window.VG_SUMMARY_ROWS || [];
        var total = window.VG_SUMMARY_TOTAL || 0;
        src.forEach(function(r, i) {
            var pct = total > 0 ? (Math.round((r.count / total) * 1000) / 10) : 0;
            rows.push([String(i + 1), r.label, String(r.count), pct + '%']);
        });
        if (rows.length) rows.push(['TOTAL', '', String(total), '100%']);
        return { headers: headers, rows: rows };
    }
    function exportVgSummaryCsv() {
        var d = _vgSummaryTableData();
        var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
        d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
        var blob = new Blob(['﻿'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = 'vg_summary_' + new Date().toISOString().slice(0,10) + '.csv'; a.click();
    }
    function exportVgSummaryExcel() {
        if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
        var d = _vgSummaryTableData();
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        ws['!cols'] = d.headers.map(function(h, i) {
            var max = h.length;
            d.rows.forEach(function(r){ if (r[i] && r[i].length > max) max = r[i].length; });
            return { wch: Math.min(max + 2, 40) };
        });
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'VG Summary');
        XLSX.writeFile(wb, 'vg_summary_' + new Date().toISOString().slice(0,10) + '.xlsx');
    }
    function exportVgSummaryPdf() {
        if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
        var d = _vgSummaryTableData();
        var doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        doc.setFontSize(13);
        doc.text('Victory Groups — Summary', 40, 36);
        doc.setFontSize(9); doc.setTextColor(120);
        doc.text('Exported: ' + new Date().toLocaleDateString(), 40, 52);
        doc.autoTable({
            head: [d.headers],
            body: d.rows,
            startY: 62,
            styles: { fontSize: 8, cellPadding: 4 },
            headStyles: { fillColor: [13, 110, 253], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [245, 247, 250] },
            margin: { left: 40, right: 40 },
        });
        doc.save('vg_summary_' + new Date().toISOString().slice(0,10) + '.pdf');
    }
    function printVgSummary() {
        var d = _vgSummaryTableData();
        var thead = '<tr>' + d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('') + '</tr>';
        var tbody = d.rows.map(function(r){
            return '<tr>' + r.map(function(v){ return '<td>'+(v||'—')+'</td>'; }).join('') + '</tr>';
        }).join('');
        var html = '<!DOCTYPE html><html><head><title>VG Summary</title>'
            + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
            + 'h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
            + 'table{border-collapse:collapse;width:100%}'
            + 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
            + 'th{background:#0d6efd;color:#fff}'
            + 'tr:nth-child(even){background:#f5f7fa}'
            + '@media print{@page{size:landscape}}'
            + '</style></head><body>'
            + '<h2>Victory Groups — Summary</h2>'
            + '<p>Printed: ' + new Date().toLocaleDateString() + ' &nbsp;|&nbsp; ' + d.rows.length + ' stat(s)</p>'
            + '<table><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table>'
            + '</body></html>';
        var w = window.open('', '_blank');
        w.document.write(html); w.document.close(); w.focus();
        setTimeout(function(){ w.print(); }, 500);
    }

    // Reusable single-chart PNG download (mirrors the classes' helper).
    function exportChartPng(canvasId, filename) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        var link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = (filename || canvasId) + '_' + new Date().toISOString().slice(0,10) + '.png';
        link.click();
    }

    function buildCategoryTotalsChart() {
        var el = document.getElementById('categoryTotalsChart');
        if (!el || !window.VG_CATEGORY_TOTALS || !window.VG_CATEGORY_TOTALS.length) return;
        new Chart(el, {
            type: 'bar',
            data: {
                labels: window.VG_CATEGORY_TOTALS.map(function(c){ return c.label; }),
                datasets: [{
                    label: 'Total',
                    data: window.VG_CATEGORY_TOTALS.map(function(c){ return c.count; }),
                    backgroundColor: window.VG_CATEGORY_TOTALS.map(function(c){ return c.hex; }),
                    borderWidth: 0,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
            }
        });
    }

    // Auto-init charts if stats tab is active on page load
    window.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('overviewChart')) {
            buildOverviewChart('bar');
            buildPieCharts();
            buildCategoryTotalsChart();
        }
        // Initialize Bootstrap tooltips for the inactive-value warning icons.
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });
        }
    });

    var STAT_SECTIONS = <?php echo json_encode(array_keys($statSections)); ?>;
    var STAT_SECTION_LABELS = <?php echo json_encode($statSections); ?>;
    var STAT_SOURCES = ['victory_groups','leaders','interns'];

    function buildStatFormHTML(d) {
        d = d || {};
        // Build Category options — exclude 'overview' (Grand Totals are managed separately).
        // If we're editing an existing 'overview' row, keep it in the dropdown so it stays selected.
        var so = STAT_SECTIONS.filter(function(s){ return s !== 'overview' || d.section === 'overview'; })
            .map(function(s){ return '<option value="'+s+'"'+(d.section===s?' selected':'')+'>'+(STAT_SECTION_LABELS[s]||s)+'</option>'; }).join('');
        var src = STAT_SOURCES.map(function(s){ return '<option value="'+s+'"'+(d.count_source===s?' selected':'')+'>'+s+'</option>'; }).join('');
        var isActive = (!d.id || d.is_active == 1);
        var statusOpts = '<option value="1"' + (isActive ? ' selected' : '') + '>Active</option>'
            + '<option value="0"' + (!isActive ? ' selected' : '') + '>Inactive</option>';
        return '<div class="row g-3">'
            +'<div class="col-12"><label class="form-label fw-semibold">Label <span class="text-danger">*</span></label>'
            +'<input type="text" name="label" id="es_label" class="form-control" value="'+esc(d.label||'')+'" placeholder="e.g. VGL: Men" autocomplete="off"></div>'
            +'<div class="col-md-6"><label class="form-label fw-semibold">Section <span class="text-danger">*</span></label><select name="section" id="es_section" class="modal-select2"><option value="">— Select —</option>'+so+'</select></div>'
            +'<div class="col-md-6"><label class="form-label fw-semibold">Count Source <span class="text-danger">*</span></label><select name="count_source" id="es_source" class="modal-select2"><option value="">— Select —</option>'+src+'</select></div>'
            +'<div class="col-12"><label class="form-label fw-semibold">Filter Value(s) <span class="text-danger">*</span></label><input type="text" name="filter_value" id="es_filter" class="form-control" value="'+esc(d.filter_value||'')+'"><div class="form-text">Comma-separated for multiple values.</div></div>'
            +'<div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="is_active" id="es_status" class="modal-select2">'+statusOpts+'</select></div>'
            +'</div>';
    }

    function openEditStatModal(d) {
        document.getElementById('editStatBody').innerHTML = buildStatFormHTML(d);
        document.getElementById('editStatForm').action = 'index.php?action=updateVglStat&id=' + d.id;
        new bootstrap.Modal(document.getElementById('editStatModal')).show();
    }

    // ── Stat Definitions Filter ───────────────────────────────────────────────
    function initStatFilters() {
        $('.sd-filter-select2').select2({
            width: '100%',
            allowClear: true,
            closeOnSelect: false
        });
        $('.sd-filter-select2').on('select2:select select2:unselect select2:clear', function() {
            applyStatFilters();
        });
        var sdSearch = document.getElementById('sdFilterSearch');
        if (sdSearch) sdSearch.addEventListener('input', applyStatFilters);
    }

    function applyStatFilters() {
        // Filtering is enforced by the DataTables search hook above; redraw triggers it.
        if (window.sdTable) window.sdTable.draw();

        var search       = (document.getElementById('sdFilterSearch').value || '').toLowerCase().trim();
        var labels       = $('#sdFilterLabel').val()   || [];
        var sections     = $('#sdFilterSection').val() || [];
        var sources      = $('#sdFilterSource').val()  || [];
        var activeVal    = $('#sdFilterActive').val()  || '';
        var count = (search ? 1 : 0) + labels.length + sections.length + sources.length + (activeVal !== '' ? 1 : 0);

        // ── Filter card header badge: just the count (compact)
        var badge = document.getElementById('statFilterBadge');
        if (badge) {
            badge.textContent = count + ' active';
            badge.style.display = count === 0 ? 'none' : '';
        }

        // ── Table card header: one badge per active filter, showing what it is (mirrors class pattern)
        var holder = document.getElementById('statDefsHeaderBadges');
        if (holder) {
            // Remove any previously-rendered filter badges (they all carry .at-filter-badge)
            holder.querySelectorAll('.sd-filter-badge').forEach(function(el){ el.remove(); });
            // Render one badge per active filter
            // `text` is the human-readable filter value (search string, label, section, source) —
            // some are admin-typed free-text, so escape before inserting into innerHTML.
            function badgeHtml(icon, text) {
                var span = document.createElement('span');
                span.className = 'badge bg-light text-dark border sd-filter-badge';
                span.innerHTML = '<i class="bi ' + icon + ' me-1"></i>' + esc(text);
                holder.appendChild(span);
            }
            // Look up readable section labels from the section dropdown options.
            var sectionLabelByKey = {};
            $('#sdFilterSection option').each(function(){ sectionLabelByKey[this.value] = this.textContent; });
            // Look up readable label-filter values from the label dropdown options.
            var labelByValue = {};
            $('#sdFilterLabel option').each(function(){ labelByValue[this.value] = this.textContent; });

            if (search)             badgeHtml('bi-search',     'Search: ' + search);
            labels.forEach(function(v){   badgeHtml('bi-tag',        'Label: '   + (labelByValue[v] || v)); });
            sections.forEach(function(v){ badgeHtml('bi-collection', 'Section: ' + (sectionLabelByKey[v] || v)); });
            sources.forEach(function(v){  badgeHtml('bi-database',   'Source: '  + v); });
            if (activeVal !== '')   badgeHtml('bi-toggle-on', 'Status: ' + (activeVal === '1' ? 'Active' : 'Inactive'));
        }

        var clearBtn = document.getElementById('statClearFilterBtn');
        if (clearBtn) clearBtn.style.display = count === 0 ? 'none' : '';
    }

    function clearStatFilters() {
        document.getElementById('sdFilterSearch').value = '';
        $('#sdFilterLabel, #sdFilterSection, #sdFilterSource').val(null).trigger('change.select2');
        $('#sdFilterActive').val('').trigger('change.select2');
        applyStatFilters();
    }

    function confirmDeleteStat(id, label) {
        document.getElementById('deleteStatLabel').textContent = label;
        document.getElementById('deleteStatBtn').href = 'index.php?action=deleteVglStat&id=' + id;
        new bootstrap.Modal(document.getElementById('deleteStatModal')).show();
    }

    // ── Add Stat form validation ──────────────────────────────────────────────
    (function() {
        var STAT_REQ = [
            { id: 'as_label',   label: 'Label',          type: 'input' },
            { id: 'as_section', label: 'Section',         type: 'select2' },
            { id: 'as_source',  label: 'Count Source',    type: 'select2' },
            { id: 'as_filter',  label: 'Filter Value(s)', type: 'input' },
        ];
        function markS2Invalid(id, invalid) {
            $('#' + id).next('.select2-container').find('.select2-selection').toggleClass('border border-danger', invalid);
        }
        var form = document.getElementById('addStatForm');
        if (!form) return;
        form.addEventListener('submit', function(e) {
            var errors = [];
            STAT_REQ.forEach(function(f) {
                if (f.type === 'select2') markS2Invalid(f.id, false);
                else document.getElementById(f.id).classList.remove('is-invalid');
            });
            STAT_REQ.forEach(function(f) {
                var val = f.type === 'select2' ? ($('#' + f.id).val() || '') : document.getElementById(f.id).value.trim();
                if (!val) {
                    errors.push(f.label);
                    if (f.type === 'select2') markS2Invalid(f.id, true);
                    else document.getElementById(f.id).classList.add('is-invalid');
                }
            });
            if (errors.length) {
                e.preventDefault();
                var $list = $('#addStatErrorList');
                $list.empty();
                errors.forEach(function(m) { $list.append('<li>' + m + '</li>'); });
                $('#addStatErrors').removeClass('d-none');
                document.querySelector('#addStatModal .modal-body').scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
        document.getElementById('addStatModal').addEventListener('hidden.bs.modal', function() {
            $('#addStatErrors').addClass('d-none');
            form.reset();
            $('#as_section, #as_source').val('').trigger('change.select2');
            $('#as_active').val('1').trigger('change.select2');
            STAT_REQ.forEach(function(f) {
                if (f.type === 'select2') markS2Invalid(f.id, false);
                else document.getElementById(f.id).classList.remove('is-invalid');
            });
        });
    })();

    // ── Stat Definitions table: DataTables for sorting / paging / live count + custom filter hook ──
    // Deferred to DOMContentLoaded because jQuery is loaded in the footer (this <script> runs first).
    window.addEventListener('DOMContentLoaded', function() {
        var tableEl = document.getElementById('statDefsTable');
        if (!tableEl || typeof $ === 'undefined' || typeof $.fn.DataTable === 'undefined') return;

        // Register custom search hook that honors our sd-filter-* widgets.
        $.fn.dataTable.ext.search.push(function(settings, _data, dataIndex) {
            if (settings.nTable.id !== 'statDefsTable') return true;
            var row = settings.aoData[dataIndex].nTr;
            if (!row) return true;
            var search   = ((document.getElementById('sdFilterSearch') || {}).value || '').toLowerCase().trim();
            var labels   = $('#sdFilterLabel').val()   || [];
            var sections = $('#sdFilterSection').val() || [];
            var sources  = $('#sdFilterSource').val()  || [];
            var active   = $('#sdFilterActive').val()  || '';
            var lbl = (row.dataset.label  || '');
            var flt = (row.dataset.filter || '');
            if (search   && !(lbl.indexOf(search) !== -1 || flt.indexOf(search) !== -1)) return false;
            if (labels.length   && labels.indexOf(lbl) === -1)                            return false;
            if (sections.length && sections.indexOf(row.dataset.section) === -1)          return false;
            if (sources.length  && sources.indexOf(row.dataset.source) === -1)            return false;
            if (active !== ''   && row.dataset.active !== active)                          return false;
            return true;
        });

        window.sdTable = $(tableEl).DataTable({
            pageLength: 25,
            order: [[1, 'asc']],   // sort by Label A→Z by default
            searching: true,       // needed for our custom hook to run
            dom: 't<"d-flex justify-content-between align-items-center px-3 py-2"ip>',
            language: {
                info: 'Showing _START_ to _END_ of _TOTAL_ stat definition(s)',
                infoEmpty: 'No definitions to display',
                infoFiltered: '(filtered from _MAX_)',
                paginate: { first: 'First', last: 'Last', next: 'Next', previous: 'Previous' },
            },
            columnDefs: [
                { targets: 0, orderable: false },                // # column — sequential, visual only
                { targets: -1, orderable: false, searchable: false }, // Actions
            ],
            // drawCallback fires on EVERY draw including the initial one — guarantees the # column
            // shows 1, 2, 3… in current display order regardless of sort/filter/paging.
            drawCallback: function() {
                var info = this.api().page.info();
                var badge = document.getElementById('statDefsCountBadge');
                if (badge) badge.textContent = info.recordsDisplay;
                var start = info.start;
                this.api().rows({ page: 'current' }).nodes().each(function(row, i) {
                    var cell = row.querySelector('td');
                    if (cell) cell.textContent = start + i + 1;
                });
            },
        });

        // Per-page selector wiring
        var $perPage = $('#statDefsPerPage');
        $perPage.on('change', function() { window.sdTable.page.len(parseInt(this.value, 10)).draw(); });
    });

    // ── Filter-value "See more / See less" inline toggle (long filter values get trimmed) ──
    // Deferred to DOMContentLoaded because jQuery loads from the footer (this <script> runs first).
    window.addEventListener('DOMContentLoaded', function() {
        if (typeof $ === 'undefined') return;
        $(document).on('click', '.sd-fv-toggle', function() {
            var $td = $(this).closest('td');
            var $short = $td.find('.sd-fv-short');
            var $full  = $td.find('.sd-fv-full');
            var expanded = $full.is(':visible');
            $short.toggle(expanded);
            $full.toggle(!expanded);
            $(this).text(expanded ? 'See more' : 'See less');
        });
    });

    // ── Stat Definitions export helpers (Session-Summary-style: pulls from filtered DataTable rows) ──
    function _sdTableData() {
        var headers = ['#', 'Label', 'Section', 'Source', 'Filter (Type / Category)', 'Count', 'Status'];
        var rows = [];
        if (!window.sdTable) return { headers: headers, rows: rows };
        window.sdTable.rows({ search: 'applied' }).every(function(_idx, _orig) {
            var node = this.node();
            if (!node) return;
            var tds = node.querySelectorAll('td');
            if (tds.length < 8) return;
            // Filter cell may be trimmed (".sd-fv-full" carries the complete value). Prefer the full text.
            var fvCell = tds[4];
            var full   = fvCell.querySelector('.sd-fv-full');
            var fvText = full
                ? full.textContent.trim()
                : (fvCell.querySelector('code') ? fvCell.querySelector('code').textContent.trim()
                    : fvCell.textContent.trim());
            rows.push([
                tds[0].textContent.trim(),
                tds[1].textContent.trim(),
                tds[2].textContent.trim(),
                tds[3].textContent.trim(),
                fvText,
                tds[5].textContent.trim(),
                tds[6].textContent.trim(),
            ]);
        });
        return { headers: headers, rows: rows };
    }
    function exportSdCsv() {
        var d = _sdTableData();
        var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
        d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
        var blob = new Blob(['﻿'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = 'vg_stat_definitions_' + new Date().toISOString().slice(0,10) + '.csv'; a.click();
    }
    function exportSdExcel() {
        if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
        var d = _sdTableData();
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        ws['!cols'] = d.headers.map(function(h, i) {
            var max = h.length;
            d.rows.forEach(function(r){ if (r[i] && r[i].length > max) max = r[i].length; });
            return { wch: Math.min(max + 2, 40) };
        });
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Stat Definitions');
        XLSX.writeFile(wb, 'vg_stat_definitions_' + new Date().toISOString().slice(0,10) + '.xlsx');
    }
    function exportSdPdf() {
        if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
        var d = _sdTableData();
        var doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        doc.setFontSize(13); doc.text('Victory Groups — Stat Definitions', 40, 36);
        doc.setFontSize(9); doc.setTextColor(120);
        doc.text('Exported: ' + new Date().toLocaleDateString(), 40, 52);
        doc.autoTable({
            head: [d.headers],
            body: d.rows,
            startY: 62,
            styles: { fontSize: 8, cellPadding: 4 },
            headStyles: { fillColor: [13, 110, 253], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [245, 247, 250] },
            margin: { left: 40, right: 40 },
        });
        doc.save('vg_stat_definitions_' + new Date().toISOString().slice(0,10) + '.pdf');
    }
    function printSd() {
        var d = _sdTableData();
        var thead = '<tr>' + d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('') + '</tr>';
        var tbody = d.rows.map(function(r){
            return '<tr>' + r.map(function(v){ return '<td>'+(v||'—')+'</td>'; }).join('') + '</tr>';
        }).join('');
        var html = '<!DOCTYPE html><html><head><title>VG Stat Definitions</title>'
            + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
            + 'h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
            + 'table{border-collapse:collapse;width:100%}'
            + 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
            + 'th{background:#0d6efd;color:#fff}'
            + 'tr:nth-child(even){background:#f5f7fa}'
            + '@media print{@page{size:landscape}}'
            + '</style></head><body>'
            + '<h2>Victory Groups — Stat Definitions</h2>'
            + '<p>Printed: ' + new Date().toLocaleDateString() + ' &nbsp;|&nbsp; ' + d.rows.length + ' definition(s)</p>'
            + '<table><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table>'
            + '</body></html>';
        var w = window.open('', '_blank');
        w.document.write(html); w.document.close(); w.focus();
        setTimeout(function(){ w.print(); }, 500);
    }

    // ── Export helpers ────────────────────────────────────────────────────────

    var EXPORT_HEADERS = ['VG/LG Type','Day','Time','Location','Leader(s)','Intern(s)','Attendees','Category','Frequency','Notes','Status'];

    function getExportRows() {
        var rows = [];
        document.querySelectorAll('.group-row').forEach(function(row) {
            if (row.style.display === 'none') return;
            var d = row.dataset;
            // Read names from rendered cells to preserve original casing
            var cells = row.querySelectorAll('td');
            function cellNames(td) {
                if (!td) return '';
                var names = [];
                td.querySelectorAll('div').forEach(function(div) {
                    var clone = div.cloneNode(true);
                    clone.querySelectorAll('.badge, a.unregistered-badge').forEach(function(el) { el.remove(); });
                    var text = clone.textContent.replace(/\s+/g, ' ').trim();
                    if (text) names.push(text);
                });
                // fallback: no divs (single plain text cell)
                if (!names.length) {
                    var clone = td.cloneNode(true);
                    clone.querySelectorAll('.badge, a.unregistered-badge').forEach(function(el) { el.remove(); });
                    var text = clone.textContent.replace(/\s+/g, ' ').trim();
                    if (text) names.push(text);
                }
                return names.join('; ');
            }
            rows.push([
                cells[1] ? cells[1].textContent.trim() : d.type,   // Type
                d.day        || '',                                   // Day
                d.time       || '',                                   // Time
                cells[4] ? cells[4].textContent.trim() : d.location, // Location
                cellNames(cells[5]),                                  // Leaders
                cellNames(cells[6]),                                  // Interns
                cellNames(cells[7]),                                  // Attendees
                cells[8] ? cells[8].textContent.trim() : d.category, // Category
                cells[9] ? cells[9].textContent.trim() : d.freq,     // Frequency
                d.notes      || '',                                   // Notes
                d.status     || '',                                   // Status
            ]);
        });
        return rows;
    }

    function exportCSV() {
        var rows = getExportRows();
        var csv  = [EXPORT_HEADERS.join(',')];
        rows.forEach(function(r) {
            csv.push(r.map(function(v) {
                return '"' + String(v).replace(/"/g, '""') + '"';
            }).join(','));
        });
        var blob = new Blob(['\uFEFF' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'victory_groups_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
    }

    function exportExcel() {
        if (typeof XLSX === 'undefined') {
            alert('Excel library failed to load. Please check your internet connection and try again.');
            return;
        }
        var rows   = getExportRows();
        var wsData = [EXPORT_HEADERS].concat(rows);
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.aoa_to_sheet(wsData);
        // Auto column widths (approximate)
        ws['!cols'] = EXPORT_HEADERS.map(function(h, i) {
            var max = h.length;
            rows.forEach(function(r) { if (r[i] && r[i].length > max) max = r[i].length; });
            return { wch: Math.min(max + 2, 50) };
        });
        XLSX.utils.book_append_sheet(wb, ws, 'Victory Groups');
        XLSX.writeFile(wb, 'victory_groups_' + new Date().toISOString().slice(0,10) + '.xlsx');
    }

    function exportPDF() {
        var rows = getExportRows();
        var doc  = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        doc.setFontSize(13);
        doc.text('Victory Groups / Leadership Groups', 40, 36);
        doc.setFontSize(9);
        doc.setTextColor(120);
        doc.text('Exported: ' + new Date().toLocaleDateString(), 40, 52);
        doc.autoTable({
            head: [EXPORT_HEADERS],
            body: rows,
            startY: 62,
            styles: { fontSize: 7, cellPadding: 3 },
            headStyles: { fillColor: [13, 110, 253], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [245, 247, 250] },
            columnStyles: {
                0: { cellWidth: 45 },  // Type
                1: { cellWidth: 55 },  // Day
                2: { cellWidth: 40 },  // Time
                3: { cellWidth: 80 },  // Location
                4: { cellWidth: 90 },  // Leaders
                5: { cellWidth: 70 },  // Interns
                6: { cellWidth: 80 },  // Attendees
                7: { cellWidth: 55 },  // Category
                8: { cellWidth: 50 },  // Frequency
                9: { cellWidth: 60 },  // Notes
                10:{ cellWidth: 40 },  // Status
            },
            margin: { left: 40, right: 40 },
        });
        doc.save('victory_groups_' + new Date().toISOString().slice(0,10) + '.pdf');
    }

    function printGroups() {
        var rows   = getExportRows();
        var thead  = '<tr>' + EXPORT_HEADERS.map(function(h) { return '<th>' + h + '</th>'; }).join('') + '</tr>';
        var tbody  = rows.map(function(r) {
            return '<tr>' + r.map(function(v) { return '<td>' + (v || '—') + '</td>'; }).join('') + '</tr>';
        }).join('');
        var html = '<!DOCTYPE html><html><head><title>Victory Groups</title>'
            + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
            + 'h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
            + 'table{border-collapse:collapse;width:100%}'
            + 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
            + 'th{background:#0d6efd;color:#fff}'
            + 'tr:nth-child(even){background:#f5f7fa}'
            + '@media print{@page{size:landscape}}'
            + '</style></head><body>'
            + '<h2>Victory Groups / Leadership Groups</h2>'
            + '<p>Printed: ' + new Date().toLocaleDateString() + ' &nbsp;|&nbsp; ' + rows.length + ' group(s)</p>'
            + '<table><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table>'
            + '</body></html>';
        var w = window.open('', '_blank');
        w.document.write(html);
        w.document.close();
        w.focus();
        w.print();
    }

    </script>

    <datalist id="genderList">
        <?php foreach (['M','F','MSL','FSL','MSI','FSI','Mcampus','Fcampus','McampusStaff','FcampusStaff','Mcouples','Fcouples','LGF','M(couples)','F(couples)'] as $g): ?>
        <option value="<?php echo $g; ?>">
        <?php endforeach; ?>
    </datalist>

    <style>.btn-xs { padding:.15rem .35rem; font-size:.75rem; }</style>

<?php include 'shared/footer.php'; ?>
<script>
$(function() {
    initStatFilters();
});
</script>
