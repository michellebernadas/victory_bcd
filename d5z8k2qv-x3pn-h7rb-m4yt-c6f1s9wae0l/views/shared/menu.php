<!-- Mobile/tablet hamburger toggle — hidden on desktop via CSS -->
<button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Open menu" aria-controls="main-menu" aria-expanded="false">
    <i class="bi bi-list"></i>
</button>
<div id="sidebarBackdrop" class="sidebar-backdrop" aria-hidden="true"></div>

<div id="main-menu" class="d-flex flex-column flex-shrink-0 p-3 sidebar position-fixed" style="width: 260px; overflow-y: auto; height: 100vh;">
    <a href="index.php" class="d-flex align-items-center mx-auto link-body-emphasis text-decoration-none mb-2">
        <img src="images/victory-logo.png" class="img-fluid my-2 px-3" style="max-height: 60px;" alt="Victory Bacolod">
    </a>
    <div class="text-center mb-2">
        <small class="text-muted fw-semibold" style="font-size: 11px; letter-spacing: 1px;">ADMIN PORTAL</small>
    </div>
    <hr class="mt-0">
    <ul class="nav nav-pills flex-column mb-auto">
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo (!isset($_GET['action']) || $_GET['action'] == '') ? 'active' : ''; ?>">
                <i class="bi bi-house-door me-2"></i>Dashboard
            </a>
        </li>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">DISCIPLESHIP</small>
        </li>
        <?php if (hasPermission('members')): ?>
        <li class="nav-item">
            <a href="index.php?action=members" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'members') ? 'active' : ''; ?>">
                <i class="bi bi-people me-2"></i>Members
            </a>
        </li>
        <?php endif; ?>
        <?php
        // Every item is its own permission (PERMISSION_GROUPS in config/permissions.php):
        // Victory Groups / Leadership Groups / Campus / Leaders, Prayer Meeting and
        // Students' Hangout, and each class are all separate. editGroup sets
        // $_GET['tab'] from the group's kind, so its item stays lit too.
        $mnAction  = $_GET['action']         ?? '';
        $mnTab     = $_GET['tab']            ?? 'vg';
        $mnProgram = $_GET['program_type']   ?? '';
        $mnGType   = $_GET['gathering_type'] ?? '';
        $onGroups  = in_array($mnAction, ['victoryGroups', 'editGroup', 'addGroup'], true);
        if (!in_array($mnTab, ['vg', 'lg', 'campus'], true)) $mnTab = 'vg';
        $mnLink = function ($perm, $url, $icon, $label, $active) {
            if (!hasPermission($perm)) return;
            echo '<li class="nav-item"><a href="' . $url . '" class="nav-link ' . ($active ? 'active' : '') . '">'
               . '<i class="bi ' . $icon . ' me-2"></i>' . $label . '</a></li>' . "\n";
        };
        $mnLink('group_vg',      'index.php?action=victoryGroups&amp;tab=vg',     'bi-people-fill',    'Victory Groups',    $onGroups && $mnTab === 'vg');
        $mnLink('group_lg',      'index.php?action=victoryGroups&amp;tab=lg',     'bi-diagram-3',      'Leadership Groups', $onGroups && $mnTab === 'lg');
        $mnLink('group_campus',  'index.php?action=victoryGroups&amp;tab=campus', 'bi-building',       'Campus',            $onGroups && $mnTab === 'campus');
        $mnLink('group_leaders', 'index.php?action=groupLeaders',                 'bi-star',           'Leaders',           $mnAction === 'groupLeaders');
        $mnLink('serve_teams',   'index.php?action=serveTeams',                   'bi-hand-thumbs-up', 'Serve Teams',       $mnAction === 'serveTeams');
        $mnLink('service_attendance', 'index.php?action=serviceAttendance',       'bi-heart-pulse',    'Sunday Attendance', $mnAction === 'serviceAttendance');
        $mnLink('prayer_meeting',   'index.php?action=gatheringAttendance&amp;gathering_type=prayer_meeting',   'bi-fire',        'Prayer Meeting',        $mnAction === 'gatheringAttendance' && $mnGType === 'prayer_meeting');
        $mnLink('students_hangout', 'index.php?action=gatheringAttendance&amp;gathering_type=students_hangout', 'bi-mortarboard', 'Students&rsquo; Hangout', $mnAction === 'gatheringAttendance' && $mnGType === 'students_hangout');
        if (hasPermission('join_requests')) {
            $mnJr = 0;
            if (class_exists('JoinRequest') || is_file(__DIR__ . '/../../models/JoinRequest.php')) {
                require_once __DIR__ . '/../../models/JoinRequest.php';
                if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) $mnJr = (new JoinRequest($GLOBALS['db']))->countNew();
            }
            echo '<li class="nav-item"><a href="index.php?action=joinRequests" class="nav-link d-flex align-items-center ' . ($mnAction === 'joinRequests' ? 'active' : '') . '">'
               . '<i class="bi bi-inbox me-2"></i>Interested People'
               . ($mnJr > 0 ? '<span class="badge rounded-pill bg-danger ms-auto" title="' . (int)$mnJr . ' new">' . (int)$mnJr . '</span>' : '')
               . '</a></li>' . "\n";
        }
        ?>
        <?php if (anyClassPermission()): ?>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">CLASSES</small>
        </li>
        <?php
        $isAtRecs = $mnAction === 'attendanceRecords';
        $mnLink('class_victory_weekend',    'index.php?action=attendanceRecords&amp;program_type=victory_weekend',    'bi-sun',         'Victory Day',       $isAtRecs && $mnProgram === 'victory_weekend');
        $mnLink('class_water_baptism',      'index.php?action=attendanceRecords&amp;program_type=water_baptism',      'bi-droplet',     'Water Baptism',         $isAtRecs && $mnProgram === 'water_baptism');
        $mnLink('class_church_community',   'index.php?action=attendanceRecords&amp;program_type=church_community',   'bi-building',    'Church Community',      $isAtRecs && $mnProgram === 'church_community');
        $mnLink('class_making_disciples',   'index.php?action=attendanceRecords&amp;program_type=making_disciples',   'bi-person-plus', 'Making Disciples',      $isAtRecs && $mnProgram === 'making_disciples');
        $mnLink('class_empowering_leaders', 'index.php?action=attendanceRecords&amp;program_type=empowering_leaders', 'bi-star',        'Empowering Leaders',    $isAtRecs && $mnProgram === 'empowering_leaders');
        $mnLink('class_leadership_113',     'index.php?action=leadership113',                                         'bi-trophy',      'Leadership 113',      $mnAction === 'leadership113' || ($isAtRecs && $mnProgram === 'leadership_113'));
        $mnLink('class_spiritual_foundations', 'index.php?action=spiritualFoundations',                               'bi-shield',      'Spiritual Foundations', $mnAction === 'spiritualFoundations' || ($isAtRecs && $mnProgram === 'spiritual_foundations'));
        ?>
        <?php endif; ?>

        <?php /* Facility Booking is visible to every logged-in account,
                 including Facilities Personnel — canViewFacilityBooking().
                 What each role may DO once inside is decided per button by
                 facility_row_actions.php, not by hiding the whole section. */ ?>
        <?php if (canViewFacilityBooking()) {
            // "Waiting on me" badge: one COUNT over the statuses at this
            // account's stage (facilityWaitingStatuses() in config/permissions.php,
            // the same list the "Waiting on me" tab uses). 0 hides the badge.
            $fbWaiting = 0;
            if (class_exists('FacilityRequest') && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
                $fbWaiting = (new FacilityRequest($GLOBALS['db']))->countByStatuses(facilityWaitingStatuses(), facilityWaitingOwnerScope());
            }
        ?>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">FACILITIES</small>
        </li>
        <li class="nav-item">
            <a href="index.php?action=facilityCalendar<?php echo $fbWaiting ? '&amp;tab=requests&amp;list=waiting' : ''; ?>" class="nav-link d-flex align-items-center <?php echo (isset($_GET['action']) && $_GET['action'] == 'facilityCalendar') ? 'active' : ''; ?>">
                <i class="bi bi-calendar3 me-2"></i>Facility Booking
                <?php if ($fbWaiting > 0): ?>
                <span class="badge rounded-pill bg-danger ms-auto" title="<?php echo (int)$fbWaiting; ?> request(s) waiting on you">
                    <?php echo (int)$fbWaiting; ?><span class="visually-hidden"> request(s) waiting on you</span>
                </span>
                <?php endif; ?>
            </a>
        </li>
        <?php } ?>
        <?php /* Public site: the pages visitors see, opened in a NEW tab so the admin stays put.
                 Relative to this app's folder; the public pages live one level up (site root). */ ?>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">PUBLIC SITE</small>
        </li>
        <?php foreach ([['../services.php', 'bi-clock', 'Service times'], ['../groups.php', 'bi-people-fill', 'Find a group'], ['../join.php', 'bi-heart', 'Interested form']] as [$psUrl, $psIcon, $psLabel]): ?>
        <li class="nav-item">
            <a href="<?php echo $psUrl; ?>" target="_blank" rel="noopener" class="nav-link d-flex align-items-center">
                <i class="bi <?php echo $psIcon; ?> me-2"></i><?php echo $psLabel; ?>
                <i class="bi bi-box-arrow-up-right ms-auto small text-muted" aria-hidden="true"></i><span class="visually-hidden">(opens in a new tab)</span>
            </a>
        </li>
        <?php endforeach; ?>
        <?php /* Administration stays admin-only: Facilities Personnel get the
                 booking workflow and nothing else. */ ?>
        <?php if (canAdminister()) { ?>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">ADMINISTRATION</small>
        </li>
        <li class="nav-item">
            <a href="index.php?action=users" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'users') ? 'active' : ''; ?>">
                <i class="bi bi-person-gear me-2"></i>Users
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=roles" class="nav-link <?php echo (($_GET['action'] ?? '') === 'roles') ? 'active' : ''; ?>">
                <i class="bi bi-shield-lock me-2"></i>Roles
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=settings" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'settings') ? 'active' : ''; ?>">
                <i class="bi bi-gear me-2"></i>Settings
            </a>
        </li>
        <?php } ?>
    </ul>

    <hr>

    <div class="dropdown">
        <a href="#" class="d-flex align-items-center link-body-emphasis text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <?php $u = $_SESSION['user']['username'] ?? 'User'; ?>
            <span class="rounded-circle avatar-initial me-2" style="width:32px;height:32px;font-size:14px;" aria-hidden="true">
                <?php echo htmlspecialchars(strtoupper(mb_substr($u, 0, 1))); ?>
            </span>
            <strong class="text-truncate" style="max-width: 150px;">Hi, <?php echo htmlspecialchars($u); ?></strong>
        </a>
        <ul class="dropdown-menu text-small shadow">
            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="index.php?action=logout"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
        </ul>
    </div>

    <!-- Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel"><i class="bi bi-person-circle me-2"></i>My Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (isset($_SESSION['user'])): ?>
                        <?php $pu = $_SESSION['user']['username'] ?? 'User'; ?>
                        <div class="text-center mb-3">
                            <div class="rounded-circle avatar-initial mb-2 mx-auto" style="width:80px;height:80px;font-size:32px;" aria-hidden="true">
                                <?php echo htmlspecialchars(strtoupper(mb_substr($pu, 0, 1))); ?>
                            </div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($pu); ?></h5>
                            <span class="badge bg-primary"><?php echo htmlspecialchars(accountTypeLabel($_SESSION['user']['accounttype'] ?? 'editor')); ?></span>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user']['email'] ?? ''); ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Last Login</label>
                            <input type="text" class="form-control" value="<?php echo isset($_SESSION['user']['last_login']) ? date('M d, Y g:i A', strtotime($_SESSION['user']['last_login'])) : 'N/A'; ?>" readonly>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <?php if (canAdminister()): ?>
                    <a href="index.php?action=editUser&id=<?php echo $_SESSION['user']['id'] ?? ''; ?>" class="btn btn-primary">Edit Profile</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div id="profileModalBackdrop" class="modal-backdrop fade" style="display: none; z-index: 1040;"></div>
</div>

<script>
// ── Mobile sidebar drawer ──────────────────────────────────────────────────
// Toggles the off-canvas sidebar on small screens. Closes when the user taps
// the backdrop, picks a nav link, or resizes back to desktop width.
(function () {
    var toggleBtn = document.getElementById('sidebarToggle');
    var sidebar   = document.getElementById('main-menu');
    var backdrop  = document.getElementById('sidebarBackdrop');
    if (!toggleBtn || !sidebar || !backdrop) return;

    function openDrawer() {
        sidebar.classList.add('show');
        backdrop.classList.add('show');
        document.body.classList.add('sidebar-open');
        toggleBtn.setAttribute('aria-expanded', 'true');
    }
    function closeDrawer() {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
        document.body.classList.remove('sidebar-open');
        toggleBtn.setAttribute('aria-expanded', 'false');
    }
    toggleBtn.addEventListener('click', function () {
        sidebar.classList.contains('show') ? closeDrawer() : openDrawer();
    });
    backdrop.addEventListener('click', closeDrawer);
    // Close after navigating from a nav link (so the next page renders without a stuck drawer state).
    sidebar.querySelectorAll('a.nav-link').forEach(function (a) {
        a.addEventListener('click', closeDrawer);
    });
    // Drop the open state if the viewport grows past the breakpoint (e.g. rotation, devtools).
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992) closeDrawer();
    });
})();
</script>
