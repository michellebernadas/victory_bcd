<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';
$editMember        = $editMember ?? null;
$activeFilters     = $activeFilters ?? [];
$discipleshipSteps = $discipleshipSteps ?? [];
$ministries        = $ministries ?? [];
$services          = $services ?? [];
$members           = $members ?? [];
$activeTab         = $_GET['tab'] ?? 'list';

$CIVIL_STATUSES      = ['Single', 'Student', 'Married', 'Widowed', 'Separated'];
$VOLUNTEER_STATUSES  = ['ACTIVE', 'NEW', 'INACTIVE'];

// Active reference sets used to flag rows whose value points to a deactivated Settings option.
$activeMinistryNames = array_flip(array_column($ministries, 'name'));
$activeServiceNames  = array_flip(array_column($services,   'name'));
?>

<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-people me-2 text-primary"></i>Members</h1>
                    <p class="text-muted mb-0">Discipleship journey tracking for all members</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                    <i class="bi bi-person-plus me-1"></i> Add Member
                </button>
            </div>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php
                $msgs = [
                    'add'        => 'Member has been added successfully.',
                    'update'     => 'Member has been updated successfully. Changes are now reflected on the records.',
                    'deactivate' => 'Member has been deactivated successfully. Press the activate button to enable it again.',
                    'activate'   => 'Member has been reactivated successfully.',
                    'delete'     => 'Member has been deleted successfully. The record has been removed from the members list.',
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

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'list' ? 'active' : ''; ?>"
                       href="index.php?action=members&tab=list">
                        <i class="bi bi-table me-1"></i>Members List
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'stats' ? 'active' : ''; ?>"
                       href="index.php?action=members&tab=stats">
                        <i class="bi bi-bar-chart-line me-1"></i>Statistics
                    </a>
                </li>
            </ul>

            <?php if ($activeTab === 'list'): ?>

            <!-- Stat cards above the filter — action-oriented "what needs attention" metrics.
                 Statistics tab covers org-wide totals (active/inactive/in VG/etc); here we surface
                 GAPS and data quality flags so users can spot members to follow up on while browsing. -->
            <?php
                $listTotal       = count($members);
                $listInMinistry  = 0;   // serving in at least one real ministry
                $listNoVg        = 0;   // gap: not in any VG/LG → invitation to assign
                $listInProgress  = 0;   // discipleship 1-4 steps → currently being discipled
                $listFullyDisc   = 0;   // discipleship all 5 steps → completed journey
                $listMissingInfo = 0;   // missing contact number → data quality gap
                foreach ($members as $_m) {
                    if (empty($_m['vg_memberships'])) $listNoVg++;

                    // Count "in a ministry" — has at least one ministry token that isn't blank
                    // or the canonical "No Ministry" placeholder value.
                    $minTokens = array_filter(array_map('trim', explode(',', $_m['ministry'] ?? '')));
                    $minTokens = array_filter($minTokens, function($t){
                        return strcasecmp($t, 'No Ministry') !== 0;
                    });
                    if (!empty($minTokens)) $listInMinistry++;

                    $stepCount = is_array($_m['completed_step_ids'] ?? null)
                                 ? count($_m['completed_step_ids']) : 0;
                    if ($stepCount >= 1 && $stepCount < 5) $listInProgress++;
                    if ($stepCount >= 5)                   $listFullyDisc++;
                    if (trim($_m['contact_number'] ?? '') === '') $listMissingInfo++;
                }
            ?>
            <div class="row mb-3 g-2">
                <div class="col-6 col-md-4 col-xl">
                    <div class="card text-center h-100 border-primary border-2">
                        <div class="card-body py-2 px-1">
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-people-fill text-primary"></i>
                                <div class="h4 fw-bold text-primary mb-0"><?php echo $listTotal; ?></div>
                            </div>
                            <div style="font-size:11px;" class="text-muted">Members in List</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-briefcase-fill text-info"></i>
                                <div class="h4 fw-bold text-info mb-0"><?php echo $listInMinistry; ?></div>
                            </div>
                            <div style="font-size:11px;" class="text-muted">In a Ministry</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-person-dash text-danger"></i>
                                <div class="h4 fw-bold text-danger mb-0"><?php echo $listNoVg; ?></div>
                            </div>
                            <div style="font-size:11px;" class="text-muted">Not in a VG / LG</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-hourglass-split text-warning"></i>
                                <div class="h4 fw-bold text-warning mb-0"><?php echo $listInProgress; ?></div>
                            </div>
                            <div style="font-size:11px;" class="text-muted">Discipleship In-Progress</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-patch-check-fill text-success"></i>
                                <div class="h4 fw-bold text-success mb-0"><?php echo $listFullyDisc; ?></div>
                            </div>
                            <div style="font-size:11px;" class="text-muted">Fully Discipled</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card text-center h-100">
                        <div class="card-body py-2 px-1">
                            <div class="d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-telephone-x text-secondary"></i>
                                <div class="h4 fw-bold text-secondary mb-0"><?php echo $listMissingInfo; ?></div>
                            </div>
                            <div style="font-size:11px;" class="text-muted">Missing Contact</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filter Panel -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-white fw-semibold"><i class="bi bi-funnel me-2"></i>Search & Filter</span>
                        <span id="memberFilterBadge" class="badge bg-white text-primary d-none"></span>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <button id="memberClearFilters" class="btn btn-sm btn-outline-light d-none">
                            <i class="bi bi-x-circle me-1"></i>Clear Filters
                        </button>
                        <button class="btn btn-sm btn-outline-light" type="button"
                                data-bs-toggle="collapse" data-bs-target="#memberFilterBody"
                                aria-expanded="true">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                </div>
                <div class="collapse show" id="memberFilterBody">
                    <div class="card-body py-3">
                        <!-- Search -->
                        <div class="input-group mb-3">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="memberSearch" class="form-control"
                                   placeholder="Search name, ministry, service, contact…" autocomplete="off">
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="document.getElementById('memberSearch').value='';$('#membersTable').DataTable().search('').draw();">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                        <!-- Row 1: Core filters -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Ministry</label>
                                <select id="mfMinistry" class="filter-select2" multiple="multiple" data-placeholder="All ministries..." style="display:none">
                                    <?php foreach ($ministries as $min): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($min['name'])); ?>"><?php echo htmlspecialchars($min['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Life Stage</label>
                                <select id="mfLifeStage" class="filter-select2" multiple="multiple" data-placeholder="All life stages..." style="display:none">
                                    <?php foreach ($CIVIL_STATUSES as $cs): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($cs)); ?>"><?php echo htmlspecialchars($cs); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Volunteer Status</label>
                                <select id="mfVolunteerStatus" class="filter-select2" multiple="multiple" data-placeholder="All statuses..." style="display:none">
                                    <?php foreach ($VOLUNTEER_STATUSES as $vs): ?>
                                    <option value="<?php echo htmlspecialchars(strtoupper($vs)); ?>"><?php echo htmlspecialchars($vs); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Member Status</label>
                                <select id="mfMemberStatus" class="filter-select2" multiple="multiple" data-placeholder="All statuses..." style="display:none">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <!-- Row 1b: Service Attending -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Service Attending</label>
                                <select id="mfService" class="filter-select2" multiple="multiple" data-placeholder="All services..." style="display:none">
                                    <?php foreach ($services as $svc): ?>
                                    <option value="<?php echo htmlspecialchars(strtolower($svc['name'])); ?>"><?php echo htmlspecialchars($svc['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <!-- Row 2: Discipleship journey -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Has Completed</label>
                                <select id="mfHasCompleted" class="filter-select2" multiple="multiple" data-placeholder="Select completed steps..." style="display:none">
                                    <?php foreach ($discipleshipSteps as $step): ?>
                                    <option value="<?php echo (int)$step['id']; ?>"><?php echo htmlspecialchars($step['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Has Not Completed</label>
                                <select id="mfNotCompleted" class="filter-select2" multiple="multiple" data-placeholder="Select pending steps..." style="display:none">
                                    <?php foreach ($discipleshipSteps as $step): ?>
                                    <option value="<?php echo (int)$step['id']; ?>"><?php echo htmlspecialchars($step['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Members Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold d-flex align-items-center flex-wrap gap-2">
                        <i class="bi bi-table me-2"></i>Members List
                        <span class="badge bg-white text-dark border" id="membersCountBadge"><?php echo count($members); ?></span>
                        <?php
                        // Show active server-side filter badges (URL-driven only — client-side filters are reflected in the count)
                        $filterBadges = [];
                        if (!empty($activeFilters['ministry']))         $filterBadges[] = ['icon' => 'bi-people',         'label' => 'Ministry', 'value' => $activeFilters['ministry']];
                        if (!empty($activeFilters['civil_status']))     $filterBadges[] = ['icon' => 'bi-heart',          'label' => 'Life Stage', 'value' => $activeFilters['civil_status']];
                        if (!empty($activeFilters['volunteer_status'])) $filterBadges[] = ['icon' => 'bi-hand-thumbs-up', 'label' => 'Volunteer', 'value' => $activeFilters['volunteer_status']];
                        if (!empty($activeFilters['member_status']))    $filterBadges[] = ['icon' => 'bi-circle-fill',    'label' => 'Status', 'value' => ucfirst($activeFilters['member_status'])];
                        foreach ($filterBadges as $b):
                        ?>
                        <span class="badge bg-light text-dark border"><i class="bi <?php echo $b['icon']; ?> me-1"></i><?php echo $b['label']; ?>: <?php echo htmlspecialchars($b['value']); ?></span>
                        <?php endforeach; ?>
                        <span id="membersClientFilterBadge" class="d-inline-flex flex-wrap gap-1" style="display:none"></span>
                    </span>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 me-3">
                            <label class="text-white small mb-0">Show</label>
                            <select id="membersPerPage" class="form-select form-select-sm" style="width:auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="-1">All</option>
                            </select>
                            <label class="text-white small mb-0">per page</label>
                        </div>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportMembersCsv()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportMembersExcel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportMembersPdf()" title="Export PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="printMembers()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="membersTable" class="table table-hover mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Full Name</th>
                                    <th>Life Stage</th>
                                    <th>Ministry</th>
                                    <th>Volunteer Status</th>
                                    <th>Service</th>
                                    <th>Contact #</th>
                                    <th>VG / LG</th>
                                    <?php foreach ($discipleshipSteps as $step): ?>
                                    <th class="text-center"><?php echo htmlspecialchars($step['abbreviation']); ?></th>
                                    <?php endforeach; ?>
                                    <th class="text-center">Member Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($members as $i => $m):
                                    $vsNorm = strtoupper(trim($m['volunteer_status'] ?? ''));
                                ?>
                                <tr data-ministry="<?php echo htmlspecialchars(strtolower($m['ministry'])); ?>"
                                    data-life-stage="<?php echo htmlspecialchars(strtolower($m['civil_status'])); ?>"
                                    data-volunteer-status="<?php echo htmlspecialchars($vsNorm); ?>"
                                    data-member-status="<?php echo htmlspecialchars($m['member_status']); ?>"
                                    data-service="<?php echo htmlspecialchars(strtolower($m['service_attending'] ?? '')); ?>"
                                    data-completed-steps="<?php echo htmlspecialchars(implode(',', $m['completed_step_ids'])); ?>">
                                    <td><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold">
                                        <a href="index.php?action=memberProfile&id=<?php echo $m['id']; ?>"
                                           class="text-decoration-none text-primary">
                                            <?php echo htmlspecialchars($m['full_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($m['civil_status']); ?></td>
                                    <td>
                                        <?php
                                        $minName    = trim($m['ministry'] ?? '');
                                        $minTokens  = array_filter(array_map('trim', explode(',', $minName)));
                                        // Render each ministry. "No Ministry" gets a muted pill (matches the L113 "Unmatched" look).
                                        $minParts = [];
                                        foreach ($minTokens as $t) {
                                            if (strcasecmp($t, 'No Ministry') === 0) {
                                                $minParts[] = '<span class="badge bg-light text-muted border fst-italic"><i class="bi bi-question-circle me-1"></i>No Ministry</span>';
                                            } else {
                                                $minParts[] = htmlspecialchars($t);
                                            }
                                        }
                                        echo implode(', ', $minParts);
                                        $inactiveMin = array_filter($minTokens, fn($t) => !isset($activeMinistryNames[$t]) && strcasecmp($t, 'No Ministry') !== 0);
                                        if (!empty($inactiveMin)):
                                        ?>
                                        <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                           title="Inactive ministry: &quot;<?php echo htmlspecialchars(implode(', ', $inactiveMin)); ?>&quot;. Reassign this member."
                                           data-bs-toggle="tooltip"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $vs = strtoupper(trim($m['volunteer_status'] ?? ''));
                                        if ($vs === 'ACTIVE'):
                                        ?><span class="badge bg-success">ACTIVE</span><?php
                                        elseif ($vs === 'NEW'):
                                        ?><span class="badge bg-info text-white">NEW</span><?php
                                        elseif ($vs === 'INACTIVE'):
                                        ?><span class="badge bg-secondary">INACTIVE</span><?php
                                        elseif ($vs !== ''):
                                        ?><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($vs); ?></span><?php
                                        endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $svcName = trim($m['service_attending'] ?? '');
                                        echo htmlspecialchars($svcName);
                                        $svcTokens   = array_filter(array_map('trim', explode(',', $svcName)));
                                        $inactiveSvc = array_filter($svcTokens, fn($t) => !isset($activeServiceNames[$t]));
                                        if (!empty($inactiveSvc)):
                                        ?>
                                        <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                           title="Inactive service: &quot;<?php echo htmlspecialchars(implode(', ', $inactiveSvc)); ?>&quot;. Reassign this member."
                                           data-bs-toggle="tooltip"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($m['contact_number'] ?? ''); ?></td>
                                    <td class="small">
                                        <?php
                                        $vgs = $m['vg_memberships'] ?? [];
                                        if (empty($vgs)):
                                            echo '<span class="text-muted">—</span>';
                                        else:
                                            // Compact summary: role-count chips ("Leader · 2, Attendee · 1") + a button to view details.
                                            $roleCounts = array_count_values(array_column($vgs, 'role'));
                                            $roleColors = ['leader' => 'primary', 'intern' => 'info', 'attendee' => 'secondary'];
                                        ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary mb-1"
                                                onclick="openMemberVgModal(<?php echo htmlspecialchars(json_encode([
                                                    'member_name' => $m['full_name'],
                                                    'vgs'         => $vgs,
                                                ])); ?>)">
                                            <i class="bi bi-people-fill me-1"></i><?php echo count($vgs); ?> group<?php echo count($vgs) === 1 ? '' : 's'; ?>
                                        </button>
                                        <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($roleCounts as $role => $cnt):
                                            $c = $roleColors[$role] ?? 'secondary';
                                        ?>
                                            <span class="badge bg-<?php echo $c; ?>-subtle text-<?php echo $c; ?> border border-<?php echo $c; ?>" style="font-size:10px;">
                                                <?php echo ucfirst($role); ?>&nbsp;·&nbsp;<?php echo $cnt; ?>
                                            </span>
                                        <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($discipleshipSteps as $step): ?>
                                    <td class="text-center">
                                        <?php echo in_array((int)$step['id'], $m['completed_step_ids'])
                                            ? '<i class="bi bi-check-circle-fill text-success"></i>'
                                            : '<i class="bi bi-x-circle text-muted"></i>'; ?>
                                    </td>
                                    <?php endforeach; ?>
                                    <td class="text-center">
                                        <span class="badge <?php echo $m['member_status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($m['member_status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode($m)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($m['member_status'] === 'active'): ?>
                                        <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                           href="index.php?action=deactivateMember&id=<?php echo $m['id']; ?>"
                                           onclick="return confirm('Deactivate <?php echo htmlspecialchars(addslashes($m['full_name'])); ?>? They will be hidden from active lists but their record is preserved.');">
                                            <i class="bi bi-pause-circle"></i>
                                        </a>
                                        <?php else: ?>
                                        <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                           href="index.php?action=activateMember&id=<?php echo $m['id']; ?>">
                                            <i class="bi bi-play-circle"></i>
                                        </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"
                                            onclick="confirmDelete(<?php echo $m['id']; ?>, '<?php echo htmlspecialchars(addslashes($m['full_name'])); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($members)): ?>
                                <tr><td colspan="<?php echo 10 + count($discipleshipSteps); ?>" class="text-center text-muted py-4">No members found. <a href="#" data-bs-toggle="modal" data-bs-target="#addMemberModal">Add the first member</a>.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Legend -->
    <div class="main-content pt-0">
        <div class="container-fluid">
            <small class="text-muted">
                <strong>Legend:</strong>
                <?php
                $legendParts = [];
                foreach ($discipleshipSteps as $step) {
                    $legendParts[] = htmlspecialchars($step['abbreviation']) . ' = ' . htmlspecialchars($step['name']);
                }
                echo implode(' &bull; ', $legendParts);
                ?>
            </small>
        </div>
    </div>

    <?php elseif ($activeTab === 'stats'): ?>

    <!-- ═══════════════ STATISTICS TAB ═══════════════ -->
    <?php
        // Compute aggregate stats from the fetched members list.
        $stTotal       = count($members);
        $stActive      = 0;
        $stInactive    = 0;
        $stWithVg      = 0;
        $stVolNew      = 0;
        $stVolActive   = 0;
        $stVolInactive = 0;
        $stFullyDisc   = 0;            // completed all 5 discipleship steps
        $stInMinistry  = 0;            // serving in at least one real ministry
        $stVgLeaders   = 0;            // serving as leader or intern in any VG/LG
        $byMinistry    = [];
        $byCivil       = [];
        $byVolunteer   = [];          // volunteer_status => count
        $byService     = [];          // service token => count
        $vgByCivil     = [];          // For members in a VG: civil_status => count
        $byStep        = [];           // step_id => completed_count
        $byCompletion  = [0=>0, 1=>0, 2=>0, 3=>0, 4=>0, 5=>0]; // bucket by # steps completed
        foreach ($members as $m) {
            if ($m['member_status'] === 'active') $stActive++; else $stInactive++;
            $hasVg = !empty($m['vg_memberships']);
            if ($hasVg) $stWithVg++;

            // VG / LG Leaders — any membership where this member serves as leader or intern.
            if ($hasVg) {
                foreach ($m['vg_memberships'] as $vm) {
                    if (in_array($vm['role'] ?? '', ['leader', 'intern'], true)) {
                        $stVgLeaders++;
                        break; // count member once even if leader in multiple groups
                    }
                }
            }

            // In a Ministry — has at least one ministry token that isn't "No Ministry".
            $minTokensTmp = array_filter(array_map('trim', explode(',', $m['ministry'] ?? '')));
            $minTokensTmp = array_filter($minTokensTmp, function($t){
                return strcasecmp($t, 'No Ministry') !== 0;
            });
            if (!empty($minTokensTmp)) $stInMinistry++;

            $vol = strtoupper(trim($m['volunteer_status'] ?? ''));
            if ($vol !== '') $byVolunteer[$vol] = ($byVolunteer[$vol] ?? 0) + 1;
            if ($vol === 'NEW')      $stVolNew++;
            if ($vol === 'ACTIVE')   $stVolActive++;
            if ($vol === 'INACTIVE') $stVolInactive++;

            // Service Attending — comma-separated tokens like ministry
            foreach (array_filter(array_map('trim', explode(',', $m['service_attending'] ?? ''))) as $svc) {
                $byService[$svc] = ($byService[$svc] ?? 0) + 1;
            }

            // VG members by life stage (for the "Who's in a group?" chart)
            if ($hasVg && !empty($m['civil_status'])) {
                $vgByCivil[$m['civil_status']] = ($vgByCivil[$m['civil_status']] ?? 0) + 1;
            }

            foreach (array_filter(array_map('trim', explode(',', $m['ministry'] ?? ''))) as $min) {
                $byMinistry[$min] = ($byMinistry[$min] ?? 0) + 1;
            }
            if (!empty($m['civil_status'])) {
                $byCivil[$m['civil_status']] = ($byCivil[$m['civil_status']] ?? 0) + 1;
            }
            $stepIds = $m['completed_step_ids'] ?? [];
            foreach ($stepIds as $sid) {
                $byStep[(int)$sid] = ($byStep[(int)$sid] ?? 0) + 1;
            }
            $n = count($stepIds);
            if ($n >= 5) $stFullyDisc++;
            if ($n > 5) $n = 5;
            $byCompletion[$n] = ($byCompletion[$n] ?? 0) + 1;
        }
        arsort($byMinistry);
        arsort($byCivil);
        arsort($byVolunteer);
        arsort($byService);
        arsort($vgByCivil);

        // Helper to render "N (P%)" — keeps card markup tidy.
        $pct = function($n) use ($stTotal) {
            if ($stTotal <= 0) return '0%';
            return round(($n / $stTotal) * 100) . '%';
        };
    ?>

    <!-- Stat Cards — Statistics tab uses the larger display-6 style (matches Attendance Records statistics).
         8 cards laid out as 4-per-row on xl, 3-per-row on md, 2-per-row on phones.
         Count + percentage of total so each metric carries its own context. -->
    <div class="row mb-4 g-3">
        <!-- Row 1: headline membership counts -->
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100 border-primary">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-primary mb-0"><?php echo $stTotal; ?></div>
                    <div class="small text-muted mt-1">Total Members</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-success mb-0"><?php echo $stActive; ?></div>
                    <div class="small text-muted mt-1">Active <span class="text-success-emphasis fw-semibold">(<?php echo $pct($stActive); ?>)</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-secondary mb-0"><?php echo $stInactive; ?></div>
                    <div class="small text-muted mt-1">Inactive <span class="text-secondary-emphasis fw-semibold">(<?php echo $pct($stInactive); ?>)</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-info mb-0"><?php echo $stWithVg; ?></div>
                    <div class="small text-muted mt-1">In a VG / LG <span class="text-info-emphasis fw-semibold">(<?php echo $pct($stWithVg); ?>)</span></div>
                </div>
            </div>
        </div>
        <!-- Row 2: engagement & leadership counts -->
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-warning mb-0"><?php echo $stVolActive; ?></div>
                    <div class="small text-muted mt-1">Active Volunteers <span class="text-warning-emphasis fw-semibold">(<?php echo $pct($stVolActive); ?>)</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-primary mb-0"><?php echo $stVgLeaders; ?></div>
                    <div class="small text-muted mt-1">VG / LG Leaders <span class="text-primary-emphasis fw-semibold">(<?php echo $pct($stVgLeaders); ?>)</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-success mb-0"><?php echo $stFullyDisc; ?></div>
                    <div class="small text-muted mt-1">Fully Discipled <span class="text-success-emphasis fw-semibold">(<?php echo $pct($stFullyDisc); ?>)</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-xl-3">
            <div class="card h-100">
                <div class="card-body text-center py-3">
                    <div class="display-6 fw-bold text-info mb-0"><?php echo $stInMinistry; ?></div>
                    <div class="small text-muted mt-1">In a Ministry <span class="text-info-emphasis fw-semibold">(<?php echo $pct($stInMinistry); ?>)</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts row -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-white"><i class="bi bi-bar-chart-steps me-2"></i>Discipleship Completion Distribution
                        <span class="text-white-50 fw-normal small">(unique members)</span>
                    </span>
                    <button class="btn btn-sm btn-outline-light" onclick="exportMemberChartPng('memberCompletionChart','members_completion_distribution')"><i class="bi bi-download me-1"></i>PNG</button>
                </div>
                <div class="card-body" style="max-height:280px;">
                    <canvas id="memberCompletionChart" style="max-height:240px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-white"><i class="bi bi-bar-chart me-2"></i>Completion per Step</span>
                    <button class="btn btn-sm btn-outline-light" onclick="exportMemberChartPng('memberStepChart','members_by_step')"><i class="bi bi-download me-1"></i>PNG</button>
                </div>
                <div class="card-body" style="max-height:280px;">
                    <canvas id="memberStepChart" style="max-height:240px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Ministry + Service row — bars (long category lists handle bars better than pies) -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-white"><i class="bi bi-bar-chart me-2"></i>Members by Ministry</span>
                    <button class="btn btn-sm btn-outline-light" onclick="exportMemberChartPng('memberMinistryChart','members_by_ministry')"><i class="bi bi-download me-1"></i>PNG</button>
                </div>
                <div class="card-body" style="max-height:320px;">
                    <canvas id="memberMinistryChart" style="max-height:280px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-white"><i class="bi bi-bar-chart me-2"></i>Members by Service Attending</span>
                    <button class="btn btn-sm btn-outline-light" onclick="exportMemberChartPng('memberServiceChart','members_by_service')"><i class="bi bi-download me-1"></i>PNG</button>
                </div>
                <div class="card-body" style="max-height:320px;">
                    <canvas id="memberServiceChart" style="max-height:280px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Life Stage / Volunteer Status / VG-by-Life-Stage — small data sets, pies work nicely -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-white"><i class="bi bi-pie-chart me-2"></i>Life Stage</span>
                    <button class="btn btn-sm btn-outline-light" onclick="exportMemberChartPng('memberCivilChart','members_by_life_stage')"><i class="bi bi-download me-1"></i>PNG</button>
                </div>
                <div class="card-body" style="max-height:280px;">
                    <canvas id="memberCivilChart" style="max-height:240px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-white"><i class="bi bi-pie-chart me-2"></i>Volunteer Status</span>
                    <button class="btn btn-sm btn-outline-light" onclick="exportMemberChartPng('memberVolunteerChart','members_by_volunteer_status')"><i class="bi bi-download me-1"></i>PNG</button>
                </div>
                <div class="card-body" style="max-height:280px;">
                    <canvas id="memberVolunteerChart" style="max-height:240px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header py-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-white"><i class="bi bi-people-fill me-2"></i>VG Members by Life Stage</span>
                    <button class="btn btn-sm btn-outline-light" onclick="exportMemberChartPng('memberVgCivilChart','vg_members_by_life_stage')"><i class="bi bi-download me-1"></i>PNG</button>
                </div>
                <div class="card-body" style="max-height:280px;">
                    <canvas id="memberVgCivilChart" style="max-height:240px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary table for export -->
    <?php
        $summaryRows = [];
        $summaryRows[] = ['Total Members',     $stTotal];
        $summaryRows[] = ['Active Members',    $stActive];
        $summaryRows[] = ['Inactive Members',  $stInactive];
        $summaryRows[] = ['Members in a VG / LG', $stWithVg];
        foreach ($discipleshipSteps as $s) {
            $summaryRows[] = ['Completed ' . $s['name'], (int)($byStep[(int)$s['id']] ?? 0)];
        }
        foreach ($byCompletion as $n => $cnt) {
            $summaryRows[] = ['Members with ' . $n . ($n === 1 ? ' step' : ' steps') . ' completed', $cnt];
        }
        foreach ($byCivil as $cs => $cnt) {
            $summaryRows[] = ['Life Stage: ' . $cs, $cnt];
        }
        foreach ($byVolunteer as $v => $cnt) {
            $summaryRows[] = ['Volunteer Status: ' . $v, $cnt];
        }
        foreach ($byMinistry as $min => $cnt) {
            $summaryRows[] = ['Ministry: ' . $min, $cnt];
        }
        foreach ($byService as $svc => $cnt) {
            $summaryRows[] = ['Service Attending: ' . $svc, $cnt];
        }
    ?>
    <div class="card mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="text-white fw-semibold"><i class="bi bi-table me-2"></i>Members Summary
                <span class="badge bg-white text-dark border ms-1"><?php echo count($summaryRows); ?></span>
            </span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-sm btn-outline-light" onclick="exportMemberSummaryCsv()"  title="CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                <button class="btn btn-sm btn-outline-light" onclick="exportMemberSummaryXlsx()" title="Excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel</button>
                <button class="btn btn-sm btn-outline-light" onclick="exportMemberSummaryPdf()"  title="PDF"><i class="bi bi-filetype-pdf me-1"></i>PDF</button>
                <button class="btn btn-sm btn-outline-light" onclick="printMemberSummary()"      title="Print"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="memberSummaryTable">
                    <thead class="table-light">
                        <tr><th>Metric</th><th class="text-center" style="width:120px">Count</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaryRows as $r): ?>
                        <tr><td><?php echo htmlspecialchars($r[0]); ?></td><td class="text-center fw-bold"><?php echo (int)$r[1]; ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        window.MEMBER_STATS = {
            completion:  <?php echo json_encode($byCompletion); ?>,
            ministry:    <?php echo json_encode($byMinistry); ?>,
            service:     <?php echo json_encode($byService); ?>,
            steps:       <?php echo json_encode(array_map(fn($s) => [
                            'id'    => (int)$s['id'],
                            'name'  => $s['name'],
                            'color' => $s['color'],
                            'count' => $byStep[(int)$s['id']] ?? 0,
                        ], $discipleshipSteps)); ?>,
            civil:       <?php echo json_encode($byCivil); ?>,
            volunteer:   <?php echo json_encode($byVolunteer); ?>,
            vgByCivil:   <?php echo json_encode($vgByCivil); ?>,
            totalActive: <?php echo (int)$stActive; ?>,
        };
    </script>

    <?php endif; // tab ?>

    <!-- Add Member Modal -->
    <div class="modal fade" id="addMemberModal" tabindex="-1" aria-labelledby="addMemberModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addMember" class="member-form">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="add_last_name" name="last_name" required placeholder="e.g. Dela Cruz">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="add_first_name" name="first_name" required placeholder="e.g. Juan B.">
                        </div>
                        <div class="col-12 mt-1">
                            <div id="add_full_name_dup_warn" class="alert alert-danger py-2 mb-0 small" style="display:none">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                <span></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Life Stage <span class="text-danger">*</span></label>
                            <select name="civil_status" class="form-select member-select2" required>
                                <option value="">— Select —</option>
                                <?php foreach ($CIVIL_STATUSES as $cs): ?>
                                <option value="<?php echo $cs; ?>"><?php echo $cs; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Member Status <span class="text-danger">*</span></label>
                            <select name="member_status" class="form-select member-select2" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Volunteer Status <span class="text-danger">*</span></label>
                            <select name="volunteer_status" class="form-select member-select2" required>
                                <option value="">— Select —</option>
                                <?php foreach ($VOLUNTEER_STATUSES as $vs): ?>
                                <option value="<?php echo $vs; ?>"><?php echo $vs; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ministry <span class="text-danger">*</span> <span class="text-muted small">(select one or more)</span></label>
                            <select name="ministry[]" class="form-select member-select2-multi" multiple required>
                                <option value="No Ministry">No Ministry</option>
                                <?php foreach ($ministries as $min): if (strcasecmp($min['name'], 'No Ministry') === 0) continue; ?>
                                <option value="<?php echo htmlspecialchars($min['name']); ?>"><?php echo htmlspecialchars($min['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Service Attending <span class="text-danger">*</span> <span class="text-muted small">(select one or more)</span></label>
                            <select name="service_attending[]" class="form-select member-select2-multi" multiple required>
                                <?php foreach ($services as $svc): ?>
                                <option value="<?php echo htmlspecialchars($svc['name']); ?>"><?php echo htmlspecialchars($svc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold d-block">Discipleship Steps Completed</label>
                            <div class="alert alert-info py-2 mb-0 small">
                                <i class="bi bi-info-circle me-1"></i>
                                Discipleship steps are derived automatically from <strong>Attendance Records</strong>.
                                After saving this member, head to
                                <a href="index.php?action=attendanceRecords" target="_blank" class="alert-link">Attendance Records</a>
                                to add VW / CC / MD / EL records, or
                                <a href="index.php?action=leadership113" target="_blank" class="alert-link">Leadership&nbsp;1-1-3</a>
                                for L113 — those records drive the steps shown on this member's profile.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Member</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Member Modal -->
    <div class="modal fade" id="editMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editMemberForm" action="" class="member-form">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Life Stage <span class="text-danger">*</span></label>
                            <select name="civil_status" id="edit_civil_status" class="form-select member-select2" required>
                                <option value="">— Select —</option>
                                <?php foreach ($CIVIL_STATUSES as $cs): ?>
                                <option value="<?php echo $cs; ?>"><?php echo $cs; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Member Status <span class="text-danger">*</span></label>
                            <select name="member_status" id="edit_member_status" class="form-select member-select2" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Volunteer Status <span class="text-danger">*</span></label>
                            <select name="volunteer_status" id="edit_volunteer_status" class="form-select member-select2" required>
                                <option value="">— Select —</option>
                                <?php foreach ($VOLUNTEER_STATUSES as $vs): ?>
                                <option value="<?php echo $vs; ?>"><?php echo $vs; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ministry <span class="text-danger">*</span> <span class="text-muted small">(select one or more)</span></label>
                            <select name="ministry[]" id="edit_ministry" class="form-select member-select2-multi" multiple required>
                                <option value="No Ministry">No Ministry</option>
                                <?php foreach ($ministries as $min): if (strcasecmp($min['name'], 'No Ministry') === 0) continue; ?>
                                <option value="<?php echo htmlspecialchars($min['name']); ?>"><?php echo htmlspecialchars($min['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Service Attending <span class="text-danger">*</span> <span class="text-muted small">(select one or more)</span></label>
                            <select name="service_attending[]" id="edit_service_attending" class="form-select member-select2-multi" multiple required>
                                <?php foreach ($services as $svc): ?>
                                <option value="<?php echo htmlspecialchars($svc['name']); ?>"><?php echo htmlspecialchars($svc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" id="edit_contact_number" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold d-block">Discipleship Steps Completed</label>
                            <?php
                                // Attendance-tracked steps (the 5 classes) sync from Attendance Records / L113 — read-only here.
                                // Other steps (PBC, SF) have no attendance flow, so they stay editable.
                                $_attendanceKeys = ['victory_weekend','church_community','making_disciples','empowering_leaders','leadership_113'];
                                $_autoSteps   = array_filter($discipleshipSteps, fn($s) => in_array($s['column_key'] ?? '', $_attendanceKeys, true));
                                $_manualSteps = array_filter($discipleshipSteps, fn($s) => !in_array($s['column_key'] ?? '', $_attendanceKeys, true));
                            ?>
                            <?php if (!empty($_autoSteps)): ?>
                            <div class="small text-muted mt-1 mb-1">
                                <i class="bi bi-link-45deg me-1"></i>Auto-derived from attendance:
                            </div>
                            <div class="d-flex flex-wrap gap-2 mb-2" id="edit_discipleship_container">
                                <?php foreach ($_autoSteps as $step): ?>
                                <span class="badge edit-step-badge bg-light text-muted border"
                                      data-step-id="<?php echo $step['id']; ?>"
                                      data-step-color="<?php echo htmlspecialchars($step['color']); ?>"
                                      style="font-size:12px;padding:.45em .7em;">
                                    <i class="bi <?php echo htmlspecialchars($step['icon']); ?> me-1"></i><?php echo htmlspecialchars($step['name']); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($_manualSteps)): ?>
                            <div class="small text-muted mt-2 mb-1">
                                <i class="bi bi-pencil-square me-1"></i>Manual (no attendance flow — tick to mark complete):
                            </div>
                            <div class="d-flex flex-wrap gap-3 mt-1">
                                <?php foreach ($_manualSteps as $step): ?>
                                <div class="form-check">
                                    <input class="form-check-input edit-step-cb" type="checkbox" name="discipleship_steps[]"
                                           id="edit_step_<?php echo $step['id']; ?>" value="<?php echo $step['id']; ?>">
                                    <label class="form-check-label" for="edit_step_<?php echo $step['id']; ?>">
                                        <i class="bi <?php echo htmlspecialchars($step['icon']); ?> text-<?php echo htmlspecialchars($step['color']); ?> me-1"></i><?php echo htmlspecialchars($step['name']); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <div class="form-text small mt-2">
                                <i class="bi bi-info-circle me-1"></i>
                                The first 5 steps auto-update when you add records on
                                <a href="index.php?action=attendanceRecords" target="_blank">Attendance Records</a>
                                or
                                <a href="index.php?action=leadership113" target="_blank">Leadership&nbsp;1-1-3</a>.
                                PBC / SF have no attendance grid so you set them here.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update Member</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Member VG/LG Details Modal -->
    <div class="modal fade" id="memberVgModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0" style="box-shadow: 0 2px 7px 0 rgba(0, 0, 0, 0.3) !important; border-radius:.75rem;">
                <div class="modal-header bg-primary">
                    <h5 class="modal-title text-white">
                        <i class="bi bi-people-fill me-2"></i>VG / LG Memberships
                        <span class="ms-2 small fw-normal text-white" id="memberVgModalSubtitle"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="memberVgModalBody">
                    <!-- populated by openMemberVgModal() -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteMemberName"></strong>?</p>
                    <p class="text-muted small">This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <a id="deleteConfirmBtn" href="#" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    var discipleshipSteps = <?php echo json_encode(array_map(function($s) {
        return ['id' => (int)$s['id'], 'abbreviation' => $s['abbreviation']];
    }, $discipleshipSteps)); ?>;

    // ── Top-level HTML escape helper. Every place that concatenates a string into innerHTML
    //    (filter chips, modal body, badges) must run user-controllable values through this. ──
    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Initialize Bootstrap tooltips for the inactive-value warning icons in the table.
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                new bootstrap.Tooltip(el);
            });
        }
    });

    function openEditModal(member) {
        document.getElementById('edit_last_name').value          = member.last_name  || '';
        document.getElementById('edit_first_name').value         = member.first_name || '';
        document.getElementById('edit_civil_status').value       = member.civil_status || '';
        document.getElementById('edit_member_status').value      = member.member_status;
        document.getElementById('edit_volunteer_status').value   = (member.volunteer_status || '').toUpperCase();
        document.getElementById('edit_contact_number').value     = member.contact_number || '';
        document.getElementById('edit_notes').value              = member.notes || '';

        // Ministry & service are stored as comma-separated strings (or legacy single value).
        // Split and set as multi-select values; trigger Select2 update.
        function setMulti($sel, csv) {
            var vals = (csv || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
            vals.forEach(function(v) {
                var exists = false;
                $sel.find('option').each(function() { if (this.value === v) exists = true; });
                if (!exists) $sel.append(new Option(v + '  —  (inactive)', v));
            });
            $sel.val(vals).trigger('change');
        }
        setMulti($('#edit_ministry'),          member.ministry);
        setMulti($('#edit_service_attending'), member.service_attending);

        // Single-select dropdowns now wrapped in Select2 — also need change.select2 trigger
        $('#edit_civil_status, #edit_member_status, #edit_volunteer_status').trigger('change.select2');

        // Read-only badges: color them with the step's color when completed; muted-grey otherwise.
        var completedIds = member.completed_step_ids || [];
        document.querySelectorAll('.edit-step-badge').forEach(function(badge) {
            var stepId = parseInt(badge.dataset.stepId, 10);
            var color  = badge.dataset.stepColor || 'success';
            badge.className = 'badge edit-step-badge';
            badge.style.fontSize = '12px';
            badge.style.padding = '.45em .7em';
            if (completedIds.indexOf(stepId) !== -1) {
                badge.classList.add('bg-' + color, color === 'warning' ? 'text-dark' : 'text-white');
            } else {
                badge.classList.add('bg-light', 'text-muted', 'border');
            }
        });

        document.getElementById('editMemberForm').action = 'index.php?action=updateMember&id=' + member.id;
        var modal = new bootstrap.Modal(document.getElementById('editMemberModal'));
        modal.show();
    }

    function confirmDelete(id, name) {
        document.getElementById('deleteMemberName').textContent = name;
        document.getElementById('deleteConfirmBtn').href = 'index.php?action=deleteMember&id=' + id;
        var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
        modal.show();
    }

    // Opens the "VG / LG Memberships" modal — renders one card per group the member belongs to,
    // showing the leader(s), this member's role, day/time/location, and group status.
    function openMemberVgModal(data) {
        var subtitle = document.getElementById('memberVgModalSubtitle');
        var body     = document.getElementById('memberVgModalBody');
        if (subtitle) subtitle.textContent = '— ' + data.member_name;
        if (!body) return;

        // Local alias to the top-level escape helper — every VG/LG field below
        // concatenated into innerHTML must run through it to prevent stored-XSS.
        var esc = escHtml;

        var roleColors = { leader: 'primary', intern: 'info', attendee: 'secondary' };
        var typeColors = function(t){ return /\bLG\b/.test(t || '') ? 'info' : 'primary'; };
        var fmtTime = function(t) {
            if (!t) return '';
            var p = t.split(':'); if (p.length < 2) return t;
            var h = parseInt(p[0], 10), m = p[1];
            var suf = h >= 12 ? 'PM' : 'AM';
            h = (h % 12) || 12;
            return h + ':' + m + ' ' + suf;
        };

        // Parses the '||'-joined GROUP_CONCAT lists into clean arrays.
        function splitList(s) {
            if (!s) return [];
            return s.split('||').map(function(n){ return n.trim(); }).filter(Boolean);
        }
        function renderPeopleList(arr, color, emptyText) {
            if (!arr.length) return '<span class="text-muted small">' + esc(emptyText) + '</span>';
            return arr.map(function(n) {
                return '<span class="badge bg-' + color + '-subtle text-' + color + ' border border-' + color + ' me-1 mb-1" style="font-size:11px;font-weight:500;">' +
                       '<i class="bi bi-person me-1"></i>' + esc(n) + '</span>';
            }).join('');
        }

        var vgs = data.vgs || [];
        if (!vgs.length) {
            body.innerHTML = '<p class="text-muted mb-0">No VG / LG memberships.</p>';
        } else {
            var html = '';
            vgs.forEach(function(vg) {
                var roleColor = roleColors[vg.role] || 'secondary';
                var typeColor = typeColors(vg.group_type);
                var inactive  = vg.group_status && vg.group_status !== 'active';

                var leaders   = splitList(vg.leader_list);
                var interns   = splitList(vg.intern_list);
                var attendees = splitList(vg.attendee_list);

                // Pick a header tint that matches the role so the card-header is visible
                // against the white modal body but still distinct from the blue modal header.
                var headerTints = {
                    primary:   { bg:'#e7f1ff', border:'#0d6efd', text:'#084298' },
                    info:      { bg:'#cff4fc', border:'#0dcaf0', text:'#055160' },
                    secondary: { bg:'#e2e3e5', border:'#6c757d', text:'#41464b' }
                };
                var tint = headerTints[roleColor] || headerTints.secondary;

                html += '<div class="card mb-3 ' + (inactive ? 'border-warning' : 'border-0') + '"' +
                        '     style="box-shadow: 0 2px 7px 0 rgba(0, 0, 0, 0.3) !important;">' +
                        // Header strip with type + role + status — tinted to the role color, with a
                        // strong left accent and bottom border so it's clearly visible.
                        '  <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2"' +
                        '       style="background:' + tint.bg + ';color:' + tint.text + ';' +
                        '              border-left:4px solid ' + tint.border + ';' +
                        '              border-bottom:2px solid ' + tint.border + ';font-weight:600;' +
                        '              box-shadow: 0 2px 7px 0 rgba(0, 0, 0, 0.3) !important;">' +
                        '    <div class="d-flex flex-wrap gap-1 align-items-center">' +
                        '      <span class="badge bg-' + typeColor + '">' + esc(vg.group_type || '—') + '</span>' +
                        (vg.group_category ? '<span class="badge bg-white text-dark border">' + esc(vg.group_category) + '</span>' : '') +
                        (inactive ? '<span class="badge bg-warning text-dark"><i class="bi bi-pause-circle me-1"></i>' + esc(vg.group_status) + '</span>' : '') +
                        '    </div>' +
                        '    <span class="badge bg-' + roleColor + '"><i class="bi bi-person-fill me-1"></i>' + esc((vg.role || '').toUpperCase()) + ' (this member)</span>' +
                        '  </div>' +
                        '  <div class="card-body py-2 px-3">' +
                        // Meta line
                        '    <div class="small text-muted mb-2">' +
                        '      <i class="bi bi-calendar3 me-1"></i>' + esc(vg.day_of_week || '—') +
                        (vg.meeting_time ? ' &middot; <i class="bi bi-clock me-1"></i>' + esc(fmtTime(vg.meeting_time)) : '') +
                        (vg.meeting_frequency ? ' &middot; ' + esc(vg.meeting_frequency) : '') +
                        (vg.location ? ' &middot; <i class="bi bi-geo-alt me-1"></i>' + esc(vg.location) : '') +
                        '    </div>' +
                        // Leaders / Interns / Attendees rows
                        '    <div class="row g-2 small">' +
                        '      <div class="col-12">' +
                        '        <div class="text-muted fw-semibold mb-1"><i class="bi bi-star-fill text-primary me-1"></i>Leader(s) <span class="badge bg-light text-dark border ms-1">' + leaders.length + '</span></div>' +
                        '        ' + renderPeopleList(leaders, 'primary', '(no leader assigned)') +
                        '      </div>' +
                        (interns.length ? (
                        '      <div class="col-12 mt-2">' +
                        '        <div class="text-muted fw-semibold mb-1"><i class="bi bi-mortarboard-fill text-info me-1"></i>Intern(s) <span class="badge bg-light text-dark border ms-1">' + interns.length + '</span></div>' +
                        '        ' + renderPeopleList(interns, 'info', '—') +
                        '      </div>'
                        ) : '') +
                        (attendees.length ? (
                        '      <div class="col-12 mt-2">' +
                        '        <div class="text-muted fw-semibold mb-1"><i class="bi bi-people me-1"></i>Attendee(s) <span class="badge bg-light text-dark border ms-1">' + attendees.length + '</span></div>' +
                        '        ' + renderPeopleList(attendees, 'secondary', '—') +
                        '      </div>'
                        ) : '') +
                        '    </div>' +
                        '    <div class="mt-3">' +
                        '      <a href="index.php?action=victoryGroups&tab=groups#group-' + (parseInt(vg.group_id, 10) || 0) + '" target="_blank" class="small">' +
                        '        Open group in Victory Groups <i class="bi bi-box-arrow-up-right ms-1"></i>' +
                        '      </a>' +
                        '    </div>' +
                        '  </div>' +
                        '</div>';
            });
            body.innerHTML = html;
        }
        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('memberVgModal'));
        modal.show();
    }

    <?php if ($editMember): ?>
    window.addEventListener('DOMContentLoaded', function() {
        openEditModal(<?php echo json_encode($editMember); ?>);
    });
    <?php endif; ?>

    <?php if (!empty($_GET['openAdd'])): ?>
    window.addEventListener('DOMContentLoaded', function() {
        new bootstrap.Modal(document.getElementById('addMemberModal')).show();
    });
    <?php endif; ?>

    // ── Live duplicate-name check on the Add Member modal ──
    // Fires on blur of either name input. Combines into "Last, First", hits the AJAX exact-match
    // endpoint, and shows an inline red banner if the name already exists.
    window.addEventListener('DOMContentLoaded', function() {
        var lastEl  = document.getElementById('add_last_name');
        var firstEl = document.getElementById('add_first_name');
        var warn    = document.getElementById('add_full_name_dup_warn');
        if (!lastEl || !firstEl || !warn) return;
        var warnText = warn.querySelector('span');
        var lastQueried = '';
        function check() {
            var last  = (lastEl.value  || '').trim();
            var first = (firstEl.value || '').trim();
            if (!last || !first) { warn.style.display = 'none'; lastEl.classList.remove('is-invalid'); firstEl.classList.remove('is-invalid'); return; }
            var canonical = last + ', ' + first;
            if (canonical === lastQueried) return;
            lastQueried = canonical;
            fetch('index.php?action=ajaxFindMemberByName&name=' + encodeURIComponent(canonical))
                .then(function(r){ return r.json(); })
                .then(function(data) {
                    if (data && data.match) {
                        warnText.textContent = 'A member named "' + data.match.name + '" already exists. Open their profile instead of creating a duplicate.';
                        warn.style.display = '';
                        lastEl.classList.add('is-invalid');
                        firstEl.classList.add('is-invalid');
                    } else {
                        warn.style.display = 'none';
                        lastEl.classList.remove('is-invalid');
                        firstEl.classList.remove('is-invalid');
                    }
                })
                .catch(function(){ /* swallow — non-blocking check */ });
        }
        [lastEl, firstEl].forEach(function(el) {
            el.addEventListener('blur', check);
            el.addEventListener('input', function() {
                warn.style.display = 'none';
                lastEl.classList.remove('is-invalid');
                firstEl.classList.remove('is-invalid');
            });
        });
    });

    // ── Statistics tab chart helpers ──
    function exportMemberChartPng(canvasId, filename) {
        var c = document.getElementById(canvasId);
        if (!c) return;
        var a = document.createElement('a');
        a.href = c.toDataURL('image/png');
        a.download = (filename || canvasId) + '_' + new Date().toISOString().slice(0,10) + '.png';
        a.click();
    }

    window.addEventListener('DOMContentLoaded', function() {
        if (typeof Chart === 'undefined' || !window.MEMBER_STATS) return;
        var S = window.MEMBER_STATS;

        var palette = [
            'rgba(13,110,253,.8)','rgba(25,135,84,.8)','rgba(255,193,7,.8)','rgba(220,53,69,.8)',
            'rgba(13,202,240,.8)','rgba(102,16,242,.8)','rgba(253,126,20,.8)','rgba(108,117,125,.6)',
            'rgba(32,201,151,.8)','rgba(232,62,140,.8)','rgba(255,138,101,.8)','rgba(149,117,205,.8)'
        ];

        // 1) Completion Distribution — horizontal bar, gradient red→green
        var compEl = document.getElementById('memberCompletionChart');
        if (compEl) {
            var bucketColors = ['rgba(108,117,125,.6)','rgba(220,53,69,.75)','rgba(255,193,7,.75)','rgba(13,202,240,.75)','rgba(13,110,253,.75)','rgba(25,135,84,.85)'];
            new Chart(compEl, {
                type: 'bar',
                data: {
                    labels: ['0 steps','1 step','2 steps','3 steps','4 steps','All 5 steps'],
                    datasets: [{
                        label: 'Members',
                        data: [0,1,2,3,4,5].map(function(n){ return S.completion[n] || 0; }),
                        backgroundColor: bucketColors,
                        borderWidth: 0,
                    }]
                },
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x:{ beginAtZero: true, ticks:{ precision:0 } }, y:{ grid:{ display:false } } }
                }
            });
        }

        // 2) Completion per Step — vertical bars, each colored by step
        var stepEl = document.getElementById('memberStepChart');
        if (stepEl) {
            var bsColor = { primary:'#0d6efd', secondary:'#6c757d', success:'#198754', danger:'#dc3545',
                            warning:'#ffc107', info:'#0dcaf0', dark:'#212529', light:'#dee2e6' };
            new Chart(stepEl, {
                type: 'bar',
                data: {
                    labels: S.steps.map(function(s){ return s.name; }),
                    datasets: [{
                        label: 'Members completed',
                        data: S.steps.map(function(s){ return s.count; }),
                        backgroundColor: S.steps.map(function(s){ return bsColor[s.color] || '#0d6efd'; }),
                        borderWidth: 0,
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend:{ display:false } },
                    scales: { y:{ beginAtZero:true, ticks:{ precision:0 } } }
                }
            });
        }

        // 3) Members by Ministry — vertical bar chart
        var minEl = document.getElementById('memberMinistryChart');
        if (minEl) {
            var minNames  = Object.keys(S.ministry);
            var minCounts = minNames.map(function(k){ return S.ministry[k]; });
            new Chart(minEl, {
                type: 'bar',
                data: { labels: minNames, datasets: [{
                    label: 'Members',
                    data: minCounts,
                    backgroundColor: palette,
                    borderWidth: 0,
                }]},
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend:{ display:false } },
                    scales: { y:{ beginAtZero:true, ticks:{ precision:0 } }, x:{ grid:{ display:false } } }
                }
            });
        }

        // 4) Members by Service Attending — horizontal bar
        var svcEl = document.getElementById('memberServiceChart');
        if (svcEl) {
            var svcNames  = Object.keys(S.service);
            var svcCounts = svcNames.map(function(k){ return S.service[k]; });
            new Chart(svcEl, {
                type: 'bar',
                data: { labels: svcNames, datasets: [{
                    label: 'Members',
                    data: svcCounts,
                    backgroundColor: palette,
                    borderWidth: 0,
                }]},
                options: {
                    indexAxis: 'y',
                    maintainAspectRatio: false,
                    plugins: { legend:{ display:false } },
                    scales: { x:{ beginAtZero:true, ticks:{ precision:0 } }, y:{ grid:{ display:false } } }
                }
            });
        }

        // 5) Life Stage — pie (small category set)
        var civEl = document.getElementById('memberCivilChart');
        if (civEl) {
            var civNames = Object.keys(S.civil);
            new Chart(civEl, {
                type: 'pie',
                data: { labels: civNames, datasets: [{
                    data: civNames.map(function(k){ return S.civil[k]; }),
                    backgroundColor: palette,
                    borderWidth: 1
                }]},
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend:{ position:'bottom', labels:{ boxWidth:12, padding:8 } } }
                }
            });
        }

        // 6) Volunteer Status — pie
        var volEl = document.getElementById('memberVolunteerChart');
        if (volEl) {
            var volNames = Object.keys(S.volunteer);
            var volColorMap = { ACTIVE:'rgba(25,135,84,.8)', NEW:'rgba(13,202,240,.8)', INACTIVE:'rgba(108,117,125,.7)' };
            new Chart(volEl, {
                type: 'pie',
                data: { labels: volNames, datasets: [{
                    data: volNames.map(function(k){ return S.volunteer[k]; }),
                    backgroundColor: volNames.map(function(k){ return volColorMap[k] || palette[0]; }),
                    borderWidth: 1
                }]},
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend:{ position:'bottom', labels:{ boxWidth:12, padding:8 } } }
                }
            });
        }

        // 7) VG Members by Life Stage — pie
        var vgCivEl = document.getElementById('memberVgCivilChart');
        if (vgCivEl) {
            var vgCivNames = Object.keys(S.vgByCivil);
            new Chart(vgCivEl, {
                type: 'pie',
                data: { labels: vgCivNames, datasets: [{
                    data: vgCivNames.map(function(k){ return S.vgByCivil[k]; }),
                    backgroundColor: palette,
                    borderWidth: 1
                }]},
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend:{ position:'bottom', labels:{ boxWidth:12, padding:8 } } }
                }
            });
        }
    });

    // ── Member Summary exports — same Session-Summary pattern used elsewhere
    function _memberSummaryData() {
        var tbl = document.getElementById('memberSummaryTable');
        if (!tbl) return { headers: ['Metric','Count'], rows: [] };
        var headers = ['Metric','Count'];
        var rows = Array.from(tbl.querySelectorAll('tbody tr')).map(function(tr){
            return Array.from(tr.querySelectorAll('td')).map(function(td){ return td.textContent.trim(); });
        });
        return { headers: headers, rows: rows };
    }
    function exportMemberSummaryCsv() {
        var d = _memberSummaryData();
        var csv = [d.headers.map(function(h){ return '"'+h+'"'; }).join(',')];
        d.rows.forEach(function(r){ csv.push(r.map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(',')); });
        var blob = new Blob(['﻿'+csv.join('\r\n')], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = 'members_summary_'+new Date().toISOString().slice(0,10)+'.csv'; a.click();
    }
    function exportMemberSummaryXlsx() {
        if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
        var d = _memberSummaryData();
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        ws['!cols'] = [{ wch: 48 }, { wch: 10 }];
        var wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, 'Members Summary');
        XLSX.writeFile(wb, 'members_summary_'+new Date().toISOString().slice(0,10)+'.xlsx');
    }
    function exportMemberSummaryPdf() {
        if (typeof window.jspdf === 'undefined') { alert('PDF library not loaded.'); return; }
        var d = _memberSummaryData();
        var doc = new window.jspdf.jsPDF({ orientation:'portrait', unit:'pt', format:'a4' });
        doc.setFontSize(13); doc.text('Members — Statistics Summary', 40, 36);
        doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: '+new Date().toLocaleDateString(), 40, 52);
        doc.autoTable({
            head: [d.headers], body: d.rows, startY: 62,
            styles: { fontSize: 9, cellPadding: 4 },
            headStyles: { fillColor: [13,110,253], textColor: 255, fontStyle: 'bold' },
            alternateRowStyles: { fillColor: [245,247,250] },
            columnStyles: { 1: { halign:'center', cellWidth: 60 } },
            margin: { left:40, right:40 }
        });
        doc.save('members_summary_'+new Date().toISOString().slice(0,10)+'.pdf');
    }
    function printMemberSummary() {
        var d = _memberSummaryData();
        var thead = '<tr>'+d.headers.map(function(h){ return '<th>'+h+'</th>'; }).join('')+'</tr>';
        var tbody = d.rows.map(function(r){ return '<tr>'+r.map(function(v){ return '<td>'+(v||'')+'</td>'; }).join('')+'</tr>'; }).join('');
        var html = '<!DOCTYPE html><html><head><title>Members Summary</title>'
            + '<style>body{font-family:sans-serif;font-size:12px;padding:20px}h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
            + 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:5px 8px;text-align:left}'
            + 'th{background:#0d6efd;color:#fff}td:last-child,th:last-child{text-align:center}tr:nth-child(even){background:#f5f7fa}</style>'
            + '</head><body><h2>Members — Statistics Summary</h2><p>Printed: '+new Date().toLocaleDateString()+'</p>'
            + '<table><thead>'+thead+'</thead><tbody>'+tbody+'</tbody></table></body></html>';
        var w = window.open('','_blank'); w.document.write(html); w.document.close(); w.focus();
        setTimeout(function(){ w.print(); }, 500);
    }
    </script>

<?php include 'shared/footer.php'; ?>

<script>
// Runs after jQuery, Select2, and DataTables are loaded
$(document).ready(function() {

    // ── Member form Select2 init (Add + Edit modals) ──────────────────────
    $(document).on('shown.bs.modal', '#addMemberModal, #editMemberModal', function() {
        var $modal = $(this);
        $modal.find('.member-select2:not(.select2-hidden-accessible)').each(function() {
            $(this).select2({ dropdownParent: $modal, width: '100%' });
        });
        $modal.find('.member-select2-multi:not(.select2-hidden-accessible)').each(function() {
            $(this).select2({
                dropdownParent: $modal,
                width: '100%',
                closeOnSelect: false,
                placeholder: '— Select one or more —'
            });
        });
    });

    // ── Full Name validation on blur ──────────────────────────────────────
    // Show validation error the moment focus leaves the field — don't wait for submit.
    // Pattern: at least one non-comma character (the lastname), a comma, then at least one more non-space (the firstname).
    var FULLNAME_RE = /^[^,]+,\s*\S.*$/;
    $(document).on('blur', '.member-fullname', function() {
        var val = ($(this).val() || '').trim();
        var $input = $(this);
        $input.removeClass('is-invalid is-valid');
        $input.next('.invalid-feedback').remove();
        if (val === '') return;
        if (FULLNAME_RE.test(val)) {
            $input.addClass('is-valid');
        } else {
            $input.addClass('is-invalid');
            $input.after('<div class="invalid-feedback d-block">Invalid format. Use <code>Lastname, Firstname</code> &mdash; e.g. <em>Dela Cruz, Juan</em></div>');
        }
    });

    // Initialize members DataTable WITHOUT the built-in search + length menu (we have custom ones).
    if (!$.fn.DataTable.isDataTable('#membersTable') && $('#membersTable').length) {
        window.membersTable = $('#membersTable').DataTable({
            responsive: true,
            pageLength: 25,
            searching: true,
            dom: 'rt<"d-flex justify-content-between align-items-center mt-2 px-2"ip>',
            order: [[1, 'asc']],
            columnDefs: [{ targets: 0, orderable: false, searchable: false }],
            drawCallback: function() {
                // Renumber the # column to match current sorted/paged order
                this.api().column(0, { page: 'current' }).nodes().each(function(cell, i) {
                    cell.innerHTML = (i + 1);
                });
                // Real-time count badge in the table header
                var info = this.api().page.info();
                var b = document.getElementById('membersCountBadge');
                if (b) b.textContent = info.recordsDisplay;
            }
        });
    }

    // Register custom search for membersTable
    $.fn.dataTable.ext.search.push(function(settings, searchData, dataIndex) {
        if ($(settings.nTable).attr('id') !== 'membersTable') return true;

        var dt   = $('#membersTable').DataTable();
        var node = dt.row(dataIndex).node();
        if (!node) return true;
        var $row = $(node);

        var ministryVals  = $('#mfMinistry').val()        || [];
        var lifeStageVals = $('#mfLifeStage').val()       || [];
        var volunteerVals = $('#mfVolunteerStatus').val()  || [];
        var memberStVals  = $('#mfMemberStatus').val()     || [];
        var serviceVals   = $('#mfService').val()          || [];
        var hasCompVals   = $('#mfHasCompleted').val()     || [];
        var notCompVals   = $('#mfNotCompleted').val()     || [];

        var rowMinistry  = ($row.data('ministry')         || '').toLowerCase();
        var rowLifeStage = ($row.data('life-stage')       || '').toLowerCase();
        var rowVolunteer = ($row.data('volunteer-status') || '').toUpperCase();
        var rowMemberSt  = ($row.data('member-status')    || '');
        var rowService   = ($row.data('service')          || '').toLowerCase();
        var rowCompleted = String($row.data('completed-steps') || '').split(',').filter(Boolean);

        // Ministry: row may have CSV like "music, ushering" — match if ANY token equals a selected value.
        if (ministryVals.length) {
            var rowMinTokens = rowMinistry.split(',').map(function(s){return s.trim();}).filter(Boolean);
            if (!ministryVals.some(function(v){ return rowMinTokens.indexOf(v) !== -1; })) return false;
        }
        if (lifeStageVals.length && lifeStageVals.indexOf(rowLifeStage) === -1) return false;
        if (volunteerVals.length && volunteerVals.indexOf(rowVolunteer)  === -1) return false;
        if (memberStVals.length  && memberStVals.indexOf(rowMemberSt)   === -1) return false;
        // Service: row may have CSV like "9:00 am, 11:00 am" — match if ANY token equals a selected value.
        if (serviceVals.length) {
            var rowSvcTokens = rowService.split(',').map(function(s){return s.trim();}).filter(Boolean);
            if (!serviceVals.some(function(v){ return rowSvcTokens.indexOf(v) !== -1; })) return false;
        }

        for (var i = 0; i < hasCompVals.length; i++) {
            if (rowCompleted.indexOf(String(hasCompVals[i])) === -1) return false;
        }
        for (var j = 0; j < notCompVals.length; j++) {
            if (rowCompleted.indexOf(String(notCompVals[j])) !== -1) return false;
        }

        return true;
    });

    // applyFilters is called by footer's filter-select2 event handler
    window.applyFilters = function() {
        var total =
            ($('#mfMinistry').val()        || []).length +
            ($('#mfLifeStage').val()       || []).length +
            ($('#mfVolunteerStatus').val() || []).length +
            ($('#mfMemberStatus').val()    || []).length +
            ($('#mfService').val()         || []).length +
            ($('#mfHasCompleted').val()    || []).length +
            ($('#mfNotCompleted').val()    || []).length;

        // Count the search box too (so the badge reflects ALL active filters)
        var searchVal = ($('#memberSearch').val() || '').trim();
        if (searchVal) total += 1;

        var $badge = $('#memberFilterBadge');
        var $clearBtn = $('#memberClearFilters');
        $badge.text(total + ' active').toggleClass('d-none', total === 0);
        $clearBtn.toggleClass('d-none', total === 0);

        // Build labeled filter chips for the table header — one per filter type that's active.
        // Format: "Ministry: MUSIC, USHERING".
        // All user-controllable strings (option values from admin-editable enums + free-text search)
        // are run through escHtml() before going into innerHTML.
        var chips = [];
        function chip(label, values, color) {
            if (!values || !values.length) return;
            var disp = values.length > 2 ? (values.slice(0, 2).join(', ') + ' +' + (values.length - 2) + ' more') : values.join(', ');
            chips.push('<span class="badge bg-' + (color || 'light text-dark border') + '">' + label + ': ' + escHtml(disp.toUpperCase()) + '</span>');
        }
        function chipSteps(label, ids, color) {
            if (!ids || !ids.length) return;
            var names = ids.map(function(id) {
                var opt = $('#mfHasCompleted option[value="' + id + '"], #mfNotCompleted option[value="' + id + '"]').first();
                return opt.length ? opt.text() : id;
            });
            chip(label, names, color);
        }
        chip('Ministry',  $('#mfMinistry').val());
        chip('Life Stage',$('#mfLifeStage').val());
        chip('Volunteer', $('#mfVolunteerStatus').val());
        chip('Status',    $('#mfMemberStatus').val());
        chip('Service',   $('#mfService').val());
        chipSteps('Completed',     $('#mfHasCompleted').val());
        chipSteps('Not Completed', $('#mfNotCompleted').val());
        if (searchVal) chips.push('<span class="badge bg-light text-dark border">Search: "' + escHtml(searchVal) + '"</span>');
        var tblBadge = document.getElementById('membersClientFilterBadge');
        if (tblBadge) {
            if (chips.length) {
                // Cap at 4 chips; overflow → "+N more" pill
                var shown = chips.slice(0, 4).join(' ');
                var overflow = chips.length - 4;
                if (overflow > 0) shown += ' <span class="badge bg-secondary">+' + overflow + ' more</span>';
                tblBadge.innerHTML = shown;
                tblBadge.style.display = 'inline-flex';
                tblBadge.classList.add('flex-wrap', 'gap-1');
            } else {
                tblBadge.innerHTML = '';
                tblBadge.style.display = 'none';
            }
        }

        if ($.fn.DataTable.isDataTable('#membersTable')) {
            $('#membersTable').DataTable().draw();
        }
    };

    // Clear button
    $('#memberClearFilters').on('click', function() {
        $('#mfMinistry, #mfLifeStage, #mfVolunteerStatus, #mfMemberStatus, #mfService, #mfHasCompleted, #mfNotCompleted')
            .val(null).trigger('change.select2');
        $('#memberSearch').val('');
        if ($.fn.DataTable.isDataTable('#membersTable')) {
            $('#membersTable').DataTable().search('').draw();
        }
        window.applyFilters();
    });

    // Search input → DataTable
    var memberSearchTimer;
    $('#memberSearch').on('input', function() {
        var val = $(this).val();
        clearTimeout(memberSearchTimer);
        memberSearchTimer = setTimeout(function() {
            if ($.fn.DataTable.isDataTable('#membersTable')) {
                $('#membersTable').DataTable().search(val).draw();
            }
            if (typeof window.applyFilters === 'function') window.applyFilters();
        }, 250);
    });

    // Per-page selector
    $('#membersPerPage').on('change', function() {
        if ($.fn.DataTable.isDataTable('#membersTable')) {
            $('#membersTable').DataTable().page.len(parseInt($(this).val())).draw();
        }
    });

    // (Real-time count badge update is wired in DataTable drawCallback above.)

    // ── Export helpers (filtered rows only) ────────────────────────────
    function _membersExportData() {
        var headers = [];
        $('#membersTable thead tr th').each(function(i) {
            // skip Actions column (last)
            if (i < $('#membersTable thead tr th').length - 1) headers.push($(this).text().trim());
        });
        var rows = [];
        if ($.fn.DataTable.isDataTable('#membersTable')) {
            $('#membersTable').DataTable().rows({ search: 'applied' }).every(function() {
                var $cells = $(this.node()).children();
                var row = [];
                $cells.each(function(i) {
                    if (i < $cells.length - 1) row.push($(this).text().replace(/\s+/g, ' ').trim());
                });
                rows.push(row);
            });
        }
        return { headers: headers, rows: rows };
    }
    function _csvEscape(v) { return '"' + String(v).replace(/"/g, '""') + '"'; }
    window.exportMembersCsv = function() {
        var d = _membersExportData();
        var csv = [d.headers.map(_csvEscape).join(',')];
        d.rows.forEach(function(r) { csv.push(r.map(_csvEscape).join(',')); });
        var blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'members_' + new Date().toISOString().slice(0,10) + '.csv';
        a.click();
    };
    window.exportMembersExcel = function() {
        if (typeof XLSX === 'undefined') { exportMembersCsv(); return; }
        var d = _membersExportData();
        var ws = XLSX.utils.aoa_to_sheet([d.headers].concat(d.rows));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Members');
        XLSX.writeFile(wb, 'members_' + new Date().toISOString().slice(0,10) + '.xlsx');
    };
    window.exportMembersPdf = function() {
        if (typeof jspdf === 'undefined' || !jspdf.jsPDF) { exportMembersCsv(); return; }
        var d = _membersExportData();
        var doc = new jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
        doc.setFontSize(14); doc.text('Members List', 40, 30);
        doc.setFontSize(9); doc.setTextColor(120); doc.text('Exported: ' + new Date().toLocaleDateString(), 40, 46);
        doc.autoTable({ head: [d.headers], body: d.rows, startY: 60, styles: { fontSize: 7 } });
        doc.save('members_' + new Date().toISOString().slice(0,10) + '.pdf');
    };
    window.printMembers = function() {
        var d = _membersExportData();
        var html = '<html><head><title>Members List</title>'
                 + '<style>body{font-family:sans-serif;font-size:11px}h2{color:#1742f5}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}th{background:#1742f5;color:#fff}</style>'
                 + '</head><body><h2>Members List</h2>'
                 + '<p>Printed: ' + new Date().toLocaleDateString() + ' &nbsp;|&nbsp; ' + d.rows.length + ' members</p>'
                 + '<table><thead><tr>' + d.headers.map(function(h){return '<th>'+h+'</th>';}).join('') + '</tr></thead>'
                 + '<tbody>' + d.rows.map(function(r){return '<tr>' + r.map(function(c){return '<td>'+c+'</td>';}).join('') + '</tr>';}).join('') + '</tbody></table>'
                 + '</body></html>';
        var w = window.open('', '_blank');
        w.document.write(html); w.document.close(); w.focus(); setTimeout(function(){ w.print(); }, 300);
    };

});
</script>
