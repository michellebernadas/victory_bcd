<?php
/**
 * Landing page for a role that does not have the Dashboard permission.
 * Shows only the sections this account may open — nothing is queried.
 */
include 'shared/header.php';
$homeLinks = [
    ['members',              'index.php?action=members',              'bi-people',        'Members'],
    ['attendance_records',   'index.php?action=attendanceRecords',    'bi-calendar-check','Attendance Records'],
    ['victory_groups',       'index.php?action=victoryGroups',        'bi-people-fill',   'Victory Groups'],
    ['serve_teams',          'index.php?action=serveTeams',           'bi-hand-thumbs-up','Serve Teams'],
    ['service_attendance',   'index.php?action=serviceAttendance',    'bi-heart-pulse',   'Sunday Attendance'],
    ['gathering_attendance', 'index.php?action=gatheringAttendance',  'bi-fire',          'Prayer & Students'],
    ['classes',              'index.php?action=leadership113',        'bi-trophy',        'Classes'],
    ['facility_view',        'index.php?action=facilityCalendar',     'bi-calendar3',     'Facility Booking'],
];
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">
            <?php if (!empty($_SESSION['access_denied'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-shield-exclamation me-2"></i><?php echo htmlspecialchars($_SESSION['access_denied']); unset($_SESSION['access_denied']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <h1 class="h3 mb-1"><i class="bi bi-house-door me-2 text-primary"></i>Welcome, <?php echo htmlspecialchars($_SESSION['user']['username']); ?></h1>
            <p class="text-muted">Signed in as <strong><?php echo htmlspecialchars(accountTypeLabel($_SESSION['user']['accounttype'] ?? '')); ?></strong>. Choose a section to get started.</p>
            <div class="row g-3">
            <?php $any = false; foreach ($homeLinks as [$perm, $url, $icon, $label]):
                if (!hasPermission($perm)) continue; $any = true; ?>
                <div class="col-6 col-md-4 col-xl-3">
                    <a href="<?php echo $url; ?>" class="card text-center h-100 stat-card text-decoration-none text-reset">
                        <div class="card-body py-4"><i class="bi <?php echo $icon; ?> display-6 mb-2"></i><div class="fw-semibold"><?php echo htmlspecialchars($label); ?></div></div>
                    </a>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if (!$any): ?>
            <div class="alert alert-info mt-3">This role has no sections assigned yet. Ask an administrator to update it under Roles.</div>
            <?php endif; ?>
        </div>
    </div>
<?php include 'shared/footer.php'; ?>
