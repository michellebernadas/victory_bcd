<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';

$discipleshipSteps      = $discipleshipSteps      ?? [];
$memberCompletedStepIds = $memberCompletedStepIds  ?? [];
$memberGroups           = $memberGroups            ?? [];
$counselors             = $counselors              ?? [];

$completedCount = count($memberCompletedStepIds);
$totalSteps     = count($discipleshipSteps);
$groupDetails   = $groupDetails ?? [];

// VG role helpers
$roleColors = ['leader' => 'danger', 'intern' => 'warning', 'attendee' => 'secondary'];
$roleIcons  = ['leader' => 'bi-star-fill', 'intern' => 'bi-person-check', 'attendee' => 'bi-person'];

// Day-of-week sort order for display
$dayOrder = ['Sunday'=>0,'Monday'=>1,'Tuesday'=>2,'Wednesday'=>3,'Thursday'=>4,'Friday'=>5,'Saturday'=>6];
?>

<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Breadcrumb / Back -->
            <div class="d-flex align-items-center mb-4">
                <a href="index.php?action=members" class="btn btn-sm btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left me-1"></i>Back to Members
                </a>
                <nav aria-label="breadcrumb" class="mb-0">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="index.php?action=members">Members</a></li>
                        <li class="breadcrumb-item active"><?php echo htmlspecialchars($member['full_name']); ?></li>
                    </ol>
                </nav>
            </div>

            <!-- Profile Header Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row align-items-center g-3">
                        <div class="col-auto">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                 style="width:80px;height:80px;font-size:28px;background:linear-gradient(135deg,#1742f5,#070d63);">
                                <?php echo strtoupper(mb_substr(trim($member['full_name']), 0, 1)); ?>
                            </div>
                        </div>
                        <div class="col">
                            <h2 class="mb-1"><?php echo htmlspecialchars($member['full_name']); ?></h2>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <span class="badge <?php echo $member['member_status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                    <i class="bi bi-circle-fill me-1" style="font-size:7px;vertical-align:middle;"></i><?php echo ucfirst($member['member_status']); ?>
                                </span>
                                <?php if (!empty($member['ministry'])):
                                    $isMinInactive = !isset($activeMinistryNames[$member['ministry']]);
                                ?>
                                <span class="badge bg-primary"><i class="bi bi-people me-1"></i><?php echo htmlspecialchars($member['ministry']); ?>
                                    <?php if ($isMinInactive): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                       title="Ministry &quot;<?php echo htmlspecialchars($member['ministry']); ?>&quot; is inactive. Please reassign this member."
                                       data-bs-toggle="tooltip"></i>
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <?php
                                $vs = strtoupper(trim($member['volunteer_status'] ?? ''));
                                $vsColors = ['ACTIVE'=>'success','NEW'=>'info','INACTIVE'=>'secondary'];
                                $vsColor  = $vsColors[$vs] ?? 'secondary';
                                if ($vs): ?>
                                <span class="badge bg-<?php echo $vsColor; ?>"><i class="bi bi-hand-thumbs-up me-1"></i><?php echo $vs; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($member['civil_status'])): ?>
                                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($member['civil_status']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($member['service_attending'])):
                                    $isSvcInactive = !isset($activeServiceNames[$member['service_attending']]);
                                ?>
                                <span class="badge bg-info text-dark"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($member['service_attending']); ?>
                                    <?php if ($isSvcInactive): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                       title="Service &quot;<?php echo htmlspecialchars($member['service_attending']); ?>&quot; is inactive. Please reassign this member."
                                       data-bs-toggle="tooltip"></i>
                                    <?php endif; ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($member['contact_number'])): ?>
                            <p class="mb-0 text-muted small"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($member['contact_number']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto text-end">
                            <div class="text-center px-3">
                                <div class="display-6 fw-bold text-primary"><?php echo $completedCount; ?>/<?php echo $totalSteps; ?></div>
                                <small class="text-muted">Discipleship Steps</small>
                            </div>
                        </div>
                        <div class="col-12 border-top pt-3">
                            <a href="index.php?action=editMember&id=<?php echo $member['id']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil me-1"></i>Edit Member
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row align-items-stretch">
                <!-- Left Column -->
                <div class="col-lg-4 d-flex flex-column">

                    <!-- Personal Details -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-person-vcard me-2"></i>Personal Details</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <tbody>
                                <?php
                                $details = [
                                    ['bi-telephone',      'Contact #',         $member['contact_number'] ?? '',   null],
                                    ['bi-heart',          'Life Stage',        $member['civil_status'] ?? '',     null],
                                    ['bi-hand-thumbs-up', 'Volunteer Status',  $member['volunteer_status'] ?? '', null],
                                    ['bi-people',         'Ministry',          $member['ministry'] ?? '',         'ministry'],
                                    ['bi-clock',          'Service Attending', $member['service_attending'] ?? '','service'],
                                ];
                                foreach ($details as [$icon, $label, $value, $kind]):
                                    if (!$value) continue;
                                    $inactive = ($kind === 'ministry' && !isset($activeMinistryNames[$value]))
                                             || ($kind === 'service'  && !isset($activeServiceNames[$value]));
                                ?>
                                <tr>
                                    <td class="text-muted small ps-3" style="width:40%"><i class="bi <?php echo $icon; ?> me-1"></i><?php echo $label; ?></td>
                                    <td class="small fw-semibold">
                                        <?php echo htmlspecialchars($value); ?>
                                        <?php if ($inactive): ?>
                                        <i class="bi bi-exclamation-triangle-fill text-warning ms-1"
                                           title="<?php echo $kind === 'ministry' ? 'Ministry' : 'Service'; ?> &quot;<?php echo htmlspecialchars($value); ?>&quot; is inactive. Please reassign this member."
                                           data-bs-toggle="tooltip"></i>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Victory Groups / Leadership Groups -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Victory / Leadership Groups</h6>
                            <span class="badge bg-light text-dark border"><?php echo count($memberGroups); ?></span>
                        </div>
                        <?php if (empty($memberGroups)): ?>
                        <div class="card-body text-muted small text-center py-3">
                            <i class="bi bi-people display-6 d-block mb-2 opacity-25"></i>Not assigned to any group
                        </div>
                        <?php else: ?>
                        <div class="accordion accordion-flush" id="vgAccordion">
                            <?php foreach ($memberGroups as $idx => $grp):
                                $gid      = $grp['group_id'];
                                $role     = $grp['role'];
                                $rColor   = $roleColors[$role] ?? 'secondary';
                                $rIcon    = $roleIcons[$role]  ?? 'bi-person';
                                $timeStr  = $grp['meeting_time'] ? date('g:i A', strtotime($grp['meeting_time'])) : '';
                                $inactive = $grp['group_status'] === 'inactive';
                                $gMembers = $groupDetails[$gid] ?? [];
                                $leaders  = $gMembers['leader']   ?? [];
                                $interns  = $gMembers['intern']   ?? [];
                                $attendees= $gMembers['attendee'] ?? [];
                                $accId    = 'vgGroup'.$gid.'_'.$idx;
                                // CSS var name for role color accent
                                $roleAccents = ['leader'=>'#dc3545','intern'=>'#ffc107','attendee'=>'#6c757d'];
                                $roleAccent  = $roleAccents[$role] ?? '#6c757d';
                            ?>
                            <div class="accordion-item border-0<?php echo ($idx < count($memberGroups)-1) ? ' border-bottom' : ''; ?><?php echo $inactive ? ' opacity-60' : ''; ?>">
                                <h2 class="accordion-header">
                                    <button class="vg-acc-btn accordion-button py-2 px-3 small" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#<?php echo $accId; ?>"
                                            style="--vg-accent:<?php echo $roleAccent; ?>;">
                                        <span class="badge bg-<?php echo $rColor; ?> me-2 flex-shrink-0" style="min-width:68px;text-align:center;">
                                            <i class="bi <?php echo $rIcon; ?> me-1"></i><?php echo ucfirst($role); ?>
                                        </span>
                                        <span class="flex-grow-1 text-truncate">
                                            <span class="fw-semibold"><?php echo htmlspecialchars(strtoupper($grp['group_type'])); ?></span>
                                            <?php if ($grp['group_category']): ?>
                                            <span class="text-muted fw-normal ms-1">— <?php echo htmlspecialchars($grp['group_category']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($inactive): ?>
                                            <span class="badge bg-secondary ms-1" style="font-size:9px;">Inactive</span>
                                            <?php endif; ?>
                                        </span>
                                    </button>
                                </h2>
                                <div id="<?php echo $accId; ?>" class="accordion-collapse collapse show">
                                    <div class="accordion-body pt-2 pb-3 px-3" style="border-left:3px solid <?php echo $roleAccent; ?>;">

                                        <!-- Schedule / Location -->
                                        <?php if ($grp['day_of_week'] || $timeStr || !empty($grp['location'])): ?>
                                        <div class="d-flex flex-wrap gap-2 mb-3" style="font-size:11px;">
                                            <?php if ($grp['day_of_week']): ?>
                                            <span class="badge bg-light text-dark border"><i class="bi bi-calendar-week me-1"></i><?php echo htmlspecialchars($grp['day_of_week']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($timeStr): ?>
                                            <span class="badge bg-light text-dark border"><i class="bi bi-clock me-1"></i><?php echo $timeStr; ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($grp['location'])): ?>
                                            <span class="badge bg-light text-dark border"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($grp['location']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Leaders -->
                                        <?php if (!empty($leaders)): ?>
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center gap-1 mb-2">
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size:10px;">
                                                    <i class="bi bi-star-fill me-1"></i>LEADER<?php echo count($leaders) > 1 ? 'S' : ''; ?>
                                                </span>
                                            </div>
                                            <div class="d-flex flex-column gap-1">
                                            <?php foreach ($leaders as $ldr):
                                                $isMe = ((int)$ldr['linked_member_id'] === (int)$member['id']); ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0"
                                                     style="width:24px;height:24px;font-size:11px;background:<?php echo $isMe ? '#dc3545' : '#dee2e6'; ?>;color:<?php echo $isMe ? '#fff' : '#666'; ?>!important;">
                                                    <?php echo strtoupper(mb_substr(trim($ldr['display_name']),0,1)); ?>
                                                </div>
                                                <?php if ($isMe): ?>
                                                <span class="small fw-semibold text-danger"><?php echo htmlspecialchars($ldr['display_name']); ?> <span class="badge bg-danger ms-1" style="font-size:9px;">You</span></span>
                                                <?php elseif ($ldr['linked_member_id']): ?>
                                                <a href="index.php?action=memberProfile&id=<?php echo $ldr['linked_member_id']; ?>" class="small text-decoration-none fw-medium"><?php echo htmlspecialchars($ldr['display_name']); ?></a>
                                                <?php else: ?>
                                                <span class="small text-muted"><?php echo htmlspecialchars($ldr['display_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Interns -->
                                        <?php if (!empty($interns)): ?>
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center gap-1 mb-2">
                                                <span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25" style="font-size:10px;color:#856404!important;">
                                                    <i class="bi bi-person-check me-1"></i>INTERN<?php echo count($interns) > 1 ? 'S' : ''; ?>
                                                </span>
                                            </div>
                                            <div class="d-flex flex-column gap-1">
                                            <?php foreach ($interns as $intern):
                                                $isMe = ((int)$intern['linked_member_id'] === (int)$member['id']); ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                                     style="width:24px;height:24px;font-size:11px;background:<?php echo $isMe ? '#ffc107' : '#dee2e6'; ?>;color:<?php echo $isMe ? '#000' : '#666'; ?>!important;">
                                                    <?php echo strtoupper(mb_substr(trim($intern['display_name']),0,1)); ?>
                                                </div>
                                                <?php if ($isMe): ?>
                                                <span class="small fw-semibold" style="color:#856404;"><?php echo htmlspecialchars($intern['display_name']); ?> <span class="badge bg-warning text-dark ms-1" style="font-size:9px;">You</span></span>
                                                <?php elseif ($intern['linked_member_id']): ?>
                                                <a href="index.php?action=memberProfile&id=<?php echo $intern['linked_member_id']; ?>" class="small text-decoration-none fw-medium"><?php echo htmlspecialchars($intern['display_name']); ?></a>
                                                <?php else: ?>
                                                <span class="small text-muted"><?php echo htmlspecialchars($intern['display_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Attendees / Members -->
                                        <?php if (!empty($attendees)): ?>
                                        <div>
                                            <div class="d-flex align-items-center gap-1 mb-2">
                                                <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25" style="font-size:10px;">
                                                    <i class="bi bi-people me-1"></i>MEMBERS (<?php echo count($attendees); ?>)
                                                </span>
                                            </div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($attendees as $att):
                                                    $isMe = ((int)$att['linked_member_id'] === (int)$member['id']);
                                                ?>
                                                <?php if ($isMe): ?>
                                                <span class="badge border fw-semibold" style="font-size:10px;background:#f0f4ff;color:#1742f5;border-color:#1742f5!important;">You</span>
                                                <?php elseif ($att['linked_member_id']): ?>
                                                <a href="index.php?action=memberProfile&id=<?php echo $att['linked_member_id']; ?>" class="badge bg-light text-dark border text-decoration-none" style="font-size:10px;"><?php echo htmlspecialchars($att['display_name']); ?></a>
                                                <?php else: ?>
                                                <span class="badge bg-light text-dark border" style="font-size:10px;"><?php echo htmlspecialchars($att['display_name']); ?></span>
                                                <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Counselors -->
                    <?php if (!empty($counselors)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-person-hearts me-2"></i>Counselors</h6>
                        </div>
                        <div class="card-body py-2">
                            <?php foreach ($counselors as $cn): ?>
                            <span class="badge bg-light text-dark border me-1 mb-1 px-2 py-1">
                                <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($cn); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Discipleship Journey -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-bar-chart-steps me-2"></i>Discipleship Journey</h6>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($discipleshipSteps as $step):
                                    $done     = in_array((int)$step['id'], $memberCompletedStepIds);
                                    $pType    = $step['column_key'];
                                    // Get years from attendance records (only if column_key is set)
                                    $yearsAttended = ($pType && isset($attendances[$pType])) ? array_keys($attendances[$pType]) : [];
                                    sort($yearsAttended);
                                ?>
                                <li class="list-group-item d-flex align-items-center gap-3 py-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center <?php echo $done ? 'text-white bg-' . htmlspecialchars($step['color']) : 'text-muted'; ?>"
                                         style="width:38px; height:38px; min-width:38px; <?php echo !$done ? 'background:#dee2e6;' : ''; ?>">
                                        <i class="bi <?php echo htmlspecialchars($step['icon']); ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold"><?php echo htmlspecialchars($step['name']); ?></div>
                                        <?php if (!empty($yearsAttended)): ?>
                                        <div class="mt-1">
                                            <?php foreach ($yearsAttended as $yr): ?>
                                            <span class="badge bg-<?php echo htmlspecialchars($step['color']); ?> bg-opacity-10 text-<?php echo htmlspecialchars($step['color']); ?> border border-<?php echo htmlspecialchars($step['color']); ?> me-1" style="font-size:10px;"><?php echo $yr; ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($done): ?>
                                    <i class="bi bi-check-circle-fill text-<?php echo htmlspecialchars($step['color']); ?> fs-5"></i>
                                    <?php else: ?>
                                    <i class="bi bi-circle text-muted fs-5"></i>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>

                    <!-- Notes -->
                    <?php if (!empty($member['notes'])): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-sticky me-2"></i>Notes</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-0 text-muted small"><?php echo nl2br(htmlspecialchars($member['notes'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Record Info -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Record Info</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-1 small"><strong>Member ID:</strong> #<?php echo $member['id']; ?></p>
                            <p class="mb-1 small"><strong>Added:</strong> <?php echo $member['dateadded'] ? date('M d, Y', strtotime($member['dateadded'])) : 'N/A'; ?></p>
                            <p class="mb-0 small"><strong>Last Updated:</strong> <?php echo ($member['dateupdated'] ?? null) ? date('M d, Y', strtotime($member['dateupdated'])) : 'Never'; ?></p>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Attendance History -->
                <div class="col-lg-8 d-flex flex-column">

                    <!-- Filter Bar -->
                    <?php
                    $allProfileYrs = [];
                    foreach ($attendances as $_pt => $_yrs) { foreach (array_keys($_yrs) as $_yr) { $allProfileYrs[$_yr] = true; } }
                    ksort($allProfileYrs);
                    $allProfileYrs = array_keys($allProfileYrs);
                    ?>
                    <?php if (!empty($attendances)): ?>
                    <div class="card mb-3">
                        <div class="card-header py-2 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>Filter Attendance</h6>
                            <div class="d-flex align-items-center gap-2">
                                <span id="profileFilterCount" class="small text-muted"></span>
                                <button onclick="clearProfileFilters()" class="btn btn-sm btn-outline-light">
                                    <i class="bi bi-x-circle me-1"></i>Clear
                                </button>
                            </div>
                        </div>
                        <div class="card-body py-2 px-3">
                            <div class="row g-2">
                                <div class="col-md-7">
                                    <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Class</label>
                                    <select id="profileFilterProgram" class="filter-select2" multiple="multiple" data-placeholder="All classes..." style="display:none">
                                        <?php foreach ($discipleshipSteps as $step):
                                            if (!$step['column_key']) continue; ?>
                                        <option value="<?php echo htmlspecialchars($step['column_key']); ?>"><?php echo htmlspecialchars($step['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Year</label>
                                    <select id="profileFilterYear" class="filter-select2" multiple="multiple" data-placeholder="All years..." style="display:none">
                                        <?php foreach ($allProfileYrs as $yr): ?>
                                        <option value="<?php echo $yr; ?>"><?php echo $yr; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Discipleship Overview Summary -->
                    <?php if (!empty($attendances)): ?>
                    <div class="card mb-4">
                        <div class="card-header py-2">
                            <h6 class="mb-0"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>Discipleship Overview</h6>
                        </div>
                        <div class="card-body p-3">
                            <div class="row g-2">
                                <?php foreach ($discipleshipSteps as $step):
                                    $pType      = $step['column_key'];
                                    $hasRecords = $pType && isset($attendances[$pType]);
                                    $done       = in_array((int)$step['id'], $memberCompletedStepIds);
                                    $yearKeys   = $hasRecords ? array_keys($attendances[$pType]) : [];
                                    sort($yearKeys);
                                    $recCount   = 0;
                                    if ($hasRecords) { foreach ($attendances[$pType] as $yr => $recs) { $recCount += count($recs); } }
                                ?>
                                <div class="col-6 col-md-4">
                                    <div class="card h-100 border-<?php echo $done ? htmlspecialchars($step['color']) : 'light'; ?> <?php echo !$done && !$hasRecords ? 'opacity-50' : ''; ?>"
                                         style="<?php echo $done ? '' : 'border-style:dashed!important;'; ?>">
                                        <div class="card-body p-2 text-center">
                                            <div class="mb-1">
                                                <span class="badge bg-<?php echo $done ? htmlspecialchars($step['color']) : 'light text-dark border'; ?> px-2 py-1" style="font-size:11px;">
                                                    <i class="bi <?php echo htmlspecialchars($step['icon']); ?> me-1"></i><?php echo htmlspecialchars($step['abbreviation']); ?>
                                                </span>
                                            </div>
                                            <div class="small fw-semibold text-truncate" title="<?php echo htmlspecialchars($step['name']); ?>"><?php echo htmlspecialchars($step['name']); ?></div>
                                            <?php if ($done): ?>
                                            <div class="mt-1"><i class="bi bi-check-circle-fill text-<?php echo htmlspecialchars($step['color']); ?>"></i> <span class="text-success small">Completed</span></div>
                                            <?php else: ?>
                                            <div class="mt-1"><i class="bi bi-circle text-muted"></i> <span class="text-muted small">Pending</span></div>
                                            <?php endif; ?>
                                            <?php if (!empty($yearKeys)): ?>
                                            <div class="mt-1 d-flex flex-wrap justify-content-center gap-1">
                                                <?php foreach ($yearKeys as $yr): ?>
                                                <span class="badge bg-<?php echo htmlspecialchars($step['color']); ?> bg-opacity-10 text-<?php echo htmlspecialchars($step['color']); ?> border border-<?php echo htmlspecialchars($step['color']); ?> border-opacity-25" style="font-size:10px;"><?php echo $yr; ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php elseif (!$done): ?>
                                            <div class="mt-1"><span class="text-muted" style="font-size:10px;">No records</span></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($attendances)): ?>
                    <!-- No attendance records — show grayed-out "step placeholder" cards so the page still
                         feels like a discipleship overview, with explicit calls to add records. -->
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        No attendance records found yet for this member. Add a record on the
                        <a href="index.php?action=attendanceRecords" target="_blank" class="alert-link">Attendance Records</a>
                        page (or
                        <a href="index.php?action=leadership113" target="_blank" class="alert-link">Leadership 1-1-3</a>
                        for L113) — that will automatically light up the matching step below.
                    </div>
                    <div class="row g-3 mb-4">
                        <?php foreach ($discipleshipSteps as $step):
                            $pType = $step['column_key'];
                            if (!$pType) continue;
                        ?>
                        <div class="col-md-6">
                            <div class="card h-100 border-light" style="opacity:.85">
                                <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center text-muted"
                                         style="width:42px; height:42px; min-width:42px; background:#e9ecef;">
                                        <i class="bi <?php echo htmlspecialchars($step['icon']); ?> fs-5"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold text-muted"><?php echo htmlspecialchars($step['name']); ?></div>
                                        <div class="small text-muted">No record yet.</div>
                                    </div>
                                    <a class="btn btn-sm btn-outline-<?php echo htmlspecialchars($step['color']); ?>"
                                       href="index.php?action=<?php echo $pType === 'leadership_113' ? 'leadership113' : 'attendanceRecords&program_type='.urlencode($pType); ?>"
                                       target="_blank">
                                        <i class="bi bi-plus-circle me-1"></i>Add
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($discipleshipSteps as $step):
                        $pType = $step['column_key'];
                        // Only render program cards for steps that have a column_key and attendance records
                        if (!$pType || !isset($attendances[$pType])) continue;
                        $typeAttendances = $attendances[$pType];
                        ksort($typeAttendances);
                        $totalRecords = 0;
                        foreach ($typeAttendances as $recs) { $totalRecords += count($recs); }
                    ?>
                    <div class="card mb-4 profile-program-card" data-program="<?php echo htmlspecialchars($pType); ?>">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h6 class="mb-0">
                                <span class="badge bg-<?php echo htmlspecialchars($step['color']); ?> me-2"><i class="bi <?php echo htmlspecialchars($step['icon']); ?>"></i></span>
                                <?php echo htmlspecialchars($step['name']); ?>
                            </h6>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-light text-dark border"><?php echo count($typeAttendances); ?> year<?php echo count($typeAttendances) !== 1 ? 's' : ''; ?></span>
                                <span class="badge bg-<?php echo htmlspecialchars($step['color']); ?> bg-opacity-10 text-<?php echo htmlspecialchars($step['color']); ?> border"><?php echo $totalRecords; ?> record<?php echo $totalRecords !== 1 ? 's' : ''; ?></span>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php foreach ($typeAttendances as $year => $records):
                                foreach ($records as $rec):
                                    $extraData = $rec['extra_data'] ? json_decode($rec['extra_data'], true) : null;
                            ?>
                            <div class="border-bottom p-3 profile-year-block" data-year="<?php echo $year; ?>" data-program="<?php echo htmlspecialchars($pType); ?>">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="text-center" style="min-width:60px;">
                                        <div class="badge bg-<?php echo htmlspecialchars($step['color']); ?> px-3 py-2" style="font-size:14px;"><?php echo $year; ?></div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <?php if ($rec['program_label']): ?>
                                        <div class="fw-semibold mb-1"><?php echo htmlspecialchars($rec['program_label']); ?></div>
                                        <?php endif; ?>

                                        <div class="d-flex flex-wrap gap-3 small text-muted">
                                            <?php if ($rec['full_name_display']): ?>
                                            <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($rec['full_name_display']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($rec['counselor_name']): ?>
                                            <span><i class="bi bi-person-hearts me-1"></i>Counselor: <?php echo htmlspecialchars($rec['counselor_name']); ?>
                                                <?php if ($pType === 'victory_weekend' && $extraData && !empty($extraData['counselor_contact'])): ?>
                                                <span class="ms-1 text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($extraData['counselor_contact']); ?></span>
                                                <?php endif; ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($rec['contact_number']): ?>
                                            <span><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($rec['contact_number']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($rec['water_baptism']): ?>
                                            <span class="text-primary fw-semibold"><i class="bi bi-droplet-fill me-1"></i>Water Baptism</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php
                                        // MD multi-part data (2025: part1_april12, part2_april26, part2_1_july12)
                                        $mdPartLabels = [
                                            'part1_april12'  => 'Part 1 (Apr 12)',
                                            'part2_april26'  => 'Part 2 (Apr 26)',
                                        ];
                                        $hasMdParts = $pType === 'making_disciples' && $extraData && array_intersect_key($mdPartLabels, $extraData);
                                        if ($hasMdParts):
                                        ?>
                                        <div class="mt-2 d-flex flex-wrap gap-2">
                                            <?php foreach ($mdPartLabels as $partKey => $partLabel):
                                                if (!isset($extraData[$partKey])) continue;
                                                $partDone = (bool)$extraData[$partKey];
                                            ?>
                                            <span class="badge <?php echo $partDone ? 'bg-success' : 'bg-light text-muted border'; ?>">
                                                <i class="bi bi-<?php echo $partDone ? 'check-circle-fill' : 'circle'; ?> me-1"></i><?php echo htmlspecialchars($partLabel); ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>

                                        <?php
                                        // L113 session attendance grid
                                        if ($pType === 'leadership_113' && $extraData && isset($extraData['sessions']) && !empty($extraData['sessions'])):
                                            $attendCount = 0; $absentCount = 0; $lateCount = 0;
                                            foreach ($extraData['sessions'] as $_sd => $_sv) {
                                                $u = strtoupper(trim($_sv));
                                                if ($u === 'P') $attendCount++;
                                                elseif ($u === 'A') $absentCount++;
                                                elseif ($u === 'L') $lateCount++;
                                            }
                                            $l113Total    = count($extraData['sessions']);
                                            $l113Complete = ($l113Total > 0 && $absentCount === 0);
                                            $l113Remarks  = $extraData['remarks'] ?? '';
                                            $l113Batch    = $extraData['batch'] ?? '';
                                        ?>
                                        <div class="mt-2">
                                            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                                <small class="text-muted fw-semibold">Session Attendance:</small>
                                                <?php if ($l113Complete): ?>
                                                <span class="badge bg-success" style="font-size:11px;"><i class="bi bi-check-circle-fill me-1"></i>Complete</span>
                                                <?php else: ?>
                                                <span class="badge bg-warning text-dark" style="font-size:11px;"><i class="bi bi-exclamation-circle me-1"></i>Incomplete — <?php echo $absentCount; ?> absent</span>
                                                <?php endif; ?>
                                                <?php if ($l113Batch): ?>
                                                <span class="badge bg-secondary bg-opacity-50 text-dark" style="font-size:10px;"><?php echo htmlspecialchars($l113Batch); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($l113Remarks): ?>
                                            <div class="text-muted small mb-1"><i class="bi bi-chat-left-text me-1"></i><?php echo htmlspecialchars($l113Remarks); ?></div>
                                            <?php endif; ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($extraData['sessions'] as $sessionDate => $status):
                                                    $statusUpper = strtoupper(trim($status));
                                                    if ($statusUpper === 'P') $bgClass = 'bg-success';
                                                    elseif ($statusUpper === 'A') $bgClass = 'bg-danger';
                                                    elseif ($statusUpper === 'L') $bgClass = 'bg-warning';
                                                    else $bgClass = 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $bgClass; ?>" title="<?php echo htmlspecialchars($sessionDate); ?>: <?php echo htmlspecialchars($status ?: '?'); ?>" style="font-size:10px;">
                                                    <?php echo htmlspecialchars($sessionDate); ?>
                                                </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="mt-1 d-flex gap-2" style="font-size:11px;">
                                                <span class="text-success"><i class="bi bi-circle-fill"></i> P: <?php echo $attendCount; ?></span>
                                                <span class="text-danger"><i class="bi bi-circle-fill"></i> A: <?php echo $absentCount; ?></span>
                                                <span class="text-warning"><i class="bi bi-circle-fill"></i> L: <?php echo $lateCount; ?></span>
                                                <span class="text-muted">Total: <?php echo $l113Total; ?> sessions</span>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                    </div>
                                </div>
                            </div>
                            <?php endforeach; endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>

        </div>
    </div>

<style>
/* Override Bootstrap accordion open-state blue background */
#vgAccordion .accordion-button:not(.collapsed) {
    background-color: #f8f9ff;
    color: inherit;
    box-shadow: none;
    border-left: 3px solid var(--vg-accent, #1742f5);
}
#vgAccordion .accordion-button.collapsed {
    border-left: 3px solid transparent;
}
#vgAccordion .accordion-button:focus {
    box-shadow: none;
}
#vgAccordion .accordion-button::after {
    margin-left: auto;
    flex-shrink: 0;
}
</style>

<script>
// Initialize Bootstrap tooltips for the inactive-value warning icons.
document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el);
        });
    }
});

// No jQuery used here — safe to define before footer loads jQuery
function applyFilters() {
    var progEl = document.getElementById('profileFilterProgram');
    var yearEl = document.getElementById('profileFilterYear');

    // Collect selected values (works for both single and multi-select)
    var progVals = progEl ? Array.from(progEl.selectedOptions).map(function(o) { return o.value; }).filter(Boolean) : [];
    var yearVals = yearEl ? Array.from(yearEl.selectedOptions).map(function(o) { return o.value; }).filter(Boolean) : [];

    var visibleRecords = 0;

    document.querySelectorAll('.profile-program-card').forEach(function(card) {
        var cardProg  = card.dataset.program;
        var progMatch = progVals.length === 0 || progVals.indexOf(cardProg) !== -1;

        if (!progMatch) {
            card.style.display = 'none';
            return;
        }

        var blocks = card.querySelectorAll('.profile-year-block');
        var visibleInCard = 0;
        blocks.forEach(function(block) {
            var yearMatch = yearVals.length === 0 || yearVals.indexOf(String(block.dataset.year)) !== -1;
            block.style.display = yearMatch ? '' : 'none';
            if (yearMatch) { visibleInCard++; visibleRecords++; }
        });

        var show = visibleInCard > 0 || yearVals.length === 0;
        card.style.display = show ? '' : 'none';
    });

    var countEl = document.getElementById('profileFilterCount');
    if (countEl) {
        countEl.textContent = (progVals.length || yearVals.length) ? visibleRecords + ' record(s) shown' : '';
    }
}

function clearProfileFilters() {
    if (window.jQuery) {
        jQuery('#profileFilterProgram, #profileFilterYear').val(null).trigger('change.select2');
    }
    applyFilters();
}
</script>

<?php include 'shared/footer.php'; ?>
