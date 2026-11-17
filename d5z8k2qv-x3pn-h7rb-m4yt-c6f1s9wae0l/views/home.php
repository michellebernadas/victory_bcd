<?php
/**
 * Landing page for a role WITHOUT the Dashboard permission.
 *
 * Shows a greeting, then one card per section this account may open, each with
 * a live figure. A figure is only ever computed for a section the role can
 * open, so this page never exposes a number the role could not otherwise see —
 * the church-wide totals stay on the Dashboard.
 *
 * Reached from views/dashboard.php, which includes this file before it loads
 * its own models, so everything needed is required here.
 */
require_once 'models/Member.php';
require_once 'models/VictoryGroup.php';
require_once 'models/ServeTeam.php';
require_once 'models/ServiceAttendance.php';
require_once 'models/GatheringAttendance.php';
require_once 'models/FacilityRequest.php';

include 'shared/header.php';


/** Run one stat query; a failure must blank one card, never the page. */
$hmTry = function (callable $fn, $fallback = null) {
    try { return $fn(); } catch (Throwable $e) { error_log('home stat: ' . $e->getMessage()); return $fallback; }
};
$hmNum  = function ($n) { return $n === null ? '—' : number_format((int)$n); };
$hmDate = function ($d) { return $d ? date('M j', strtotime($d)) : null; };

// ── Figures: only for sections this role can open ───────────────────────────
$hmMember = hasPermission('members') ? $hmTry(function () use ($db) { return (new Member($db))->getStats(); }, []) : null;
$hmGroups = anyGroupPermission()     ? $hmTry(function () use ($db) { return (new VictoryGroup($db))->getStats(); }, []) : null;
$hmServe  = hasPermission('serve_teams') ? $hmTry(function () use ($db) { return (new ServeTeam($db))->getStats(); }, []) : null;
$hmSunday = hasPermission('service_attendance') ? $hmTry(function () use ($db) { return (new ServiceAttendance($db))->getLatestSunday([]); }, []) : null;
$hmGather = anyGatheringPermission() ? $hmTry(function () use ($db) { return (new GatheringAttendance($db))->getStatsByType([]); }, []) : null;
$hmWaiting = null;
if (canViewFacilityBooking()) {
    $hmWaiting = $hmTry(function () use ($db) {
        return (new FacilityRequest($db))->countByStatuses(facilityWaitingStatuses(), facilityWaitingOwnerScope());
    }, null);
}

// Card: [permission, url, icon, title, blurb, accent, figure|null, figure label]
$hmCards = [];
$add = function ($perm, $url, $icon, $title, $blurb, $accent, $figure = null, $label = '') use (&$hmCards) {
    if (hasPermission($perm)) $hmCards[] = compact('url', 'icon', 'title', 'blurb', 'accent', 'figure', 'label');
};
$g = $hmGroups ?: [];
$add('members', 'index.php?action=members', 'bi-people', 'Members', 'Profiles, contact details and discipleship progress.', 'blue',
     $hmMember ? $hmNum($hmMember['active'] ?? null) : null, 'active members');
$add('group_vg', 'index.php?action=victoryGroups&tab=vg', 'bi-people-fill', 'Victory Groups', 'Groups, their leaders, interns and attendees.', 'purple',
     $hmGroups ? $hmNum($g['vg_active'] ?? null) : null, 'active groups');
$add('group_lg', 'index.php?action=victoryGroups&tab=lg', 'bi-diagram-3', 'Leadership Groups', 'Groups for leaders of Victory Groups.', 'teal',
     $hmGroups ? $hmNum($g['lg_active'] ?? null) : null, 'active groups');
$add('group_campus', 'index.php?action=victoryGroups&tab=campus', 'bi-building', 'Campus', 'Campus groups and their leaders.', 'orange',
     $hmGroups ? $hmNum($g['campus_active'] ?? null) : null, 'active groups');
$add('group_leaders', 'index.php?action=groupLeaders', 'bi-star', 'Leaders', 'Everyone leading a group, in one list.', 'gold',
     $hmGroups ? $hmNum((int)($g['vg_leaders'] ?? 0) + (int)($g['lg_leaders'] ?? 0) + (int)($g['campus_leaders'] ?? 0)) : null, 'leaders');
$add('serve_teams', 'index.php?action=serveTeams', 'bi-hand-thumbs-up', 'Serve Teams', 'Teams, who leads them and who serves.', 'green',
     $hmServe ? $hmNum($hmServe['active'] ?? null) : null, 'active teams');
$sunLatest = $hmSunday['date'] ?? null;
$add('service_attendance', 'index.php?action=serviceAttendance', 'bi-heart-pulse', 'Sunday Attendance', 'Head counts for every Sunday service.', 'red',
     $sunLatest ? $hmNum($hmSunday['total'] ?? null) : null, $sunLatest ? 'on ' . $hmDate($sunLatest) : 'no Sunday recorded yet');
foreach (GATHERING_PERMISSIONS as $gType => $gPerm) {
    $gs  = $hmGather[$gType] ?? null;
    $gd  = GatheringAttendance::TYPES[$gType];
    $add($gPerm, 'index.php?action=gatheringAttendance&gathering_type=' . $gType, $gd['icon'], $gd['label'], $gd['day'] . ' gathering head counts.', $gType === 'prayer_meeting' ? 'red' : 'green',
         ($gs && $gs['latest'] !== null) ? $hmNum($gs['latest']) : null,
         ($gs && $gs['latest_date']) ? 'on ' . $hmDate($gs['latest_date']) : 'nothing recorded yet');
}
$classCards = [
    ['class_victory_weekend', 'index.php?action=attendanceRecords&program_type=victory_weekend', 'bi-sun', 'Victory Day', 'Attendance records.'],
    ['class_water_baptism', 'index.php?action=attendanceRecords&program_type=water_baptism', 'bi-droplet', 'Water Baptism', 'Baptism records.'],
    ['class_church_community', 'index.php?action=attendanceRecords&program_type=church_community', 'bi-building', 'Church Community', 'Attendance records.'],
    ['class_making_disciples', 'index.php?action=attendanceRecords&program_type=making_disciples', 'bi-person-plus', 'Making Disciples', 'Attendance records.'],
    ['class_empowering_leaders', 'index.php?action=attendanceRecords&program_type=empowering_leaders', 'bi-star', 'Empowering Leaders', 'Attendance records.'],
    ['class_leadership_113', 'index.php?action=leadership113', 'bi-trophy', 'Leadership 113', 'Leadership records.'],
    ['class_spiritual_foundations', 'index.php?action=spiritualFoundations', 'bi-shield', 'Spiritual Foundations', 'Weekly lessons and progress.'],
];
foreach ($classCards as [$perm, $url, $icon, $title, $blurb]) $add($perm, $url, $icon, $title, $blurb, 'indigo');
$add('facility_view', 'index.php?action=facilityCalendar' . ($hmWaiting ? '&tab=requests&list=waiting' : ''), 'bi-calendar3', 'Facility Booking',
     'The room calendar and your requests.', 'purple',
     $hmWaiting ? $hmNum($hmWaiting) : null, $hmWaiting ? 'waiting on you' : '');

// Quick actions: things people do daily, each only if the role may do it.
$hmActions = [];
if (hasPermission('members'))            $hmActions[] = ['index.php?action=members&openAdd=1', 'bi-person-plus', 'Add member'];
if (hasPermission('group_vg'))           $hmActions[] = ['index.php?action=victoryGroups&tab=vg&openAdd=1', 'bi-plus-circle', 'Add Victory Group'];
if (hasPermission('service_attendance')) $hmActions[] = ['index.php?action=serviceAttendance&enterDay=1', 'bi-heart-pulse', 'Enter a Sunday'];
if (canSubmitFacilityRequest())          $hmActions[] = ['index.php?action=facilityCalendar', 'bi-calendar-plus', 'Request a room'];
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

            <!-- Greeting (shared with the Dashboard: views/shared/greeting_banner.php) -->
            <?php include 'shared/greeting_banner.php'; ?>

            <?php if ($hmWaiting): ?>
            <a href="index.php?action=facilityCalendar&amp;tab=requests&amp;list=waiting" class="hm-alert text-decoration-none mb-4">
                <i class="bi bi-bell-fill" aria-hidden="true"></i>
                <span><strong><?php echo (int)$hmWaiting; ?></strong> facility request<?php echo $hmWaiting == 1 ? '' : 's'; ?> waiting on you</span>
                <span class="ms-auto small">Review <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
            </a>
            <?php endif; ?>

            <?php if ($hmActions): ?>
            <div class="hm-actions mb-4" role="group" aria-label="Quick actions">
                <span class="hm-actions-label">Quick actions</span>
                <?php foreach ($hmActions as [$url, $icon, $label]): ?>
                <a href="<?php echo htmlspecialchars($url); ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi <?php echo $icon; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($label); ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($hmCards): ?>
            <h2 class="hm-section">Your sections</h2>
            <div class="row g-3">
                <?php foreach ($hmCards as $c): ?>
                <div class="col-12 col-sm-6 col-xl-4">
                    <a href="<?php echo htmlspecialchars($c['url']); ?>" class="hm-card hm-<?php echo $c['accent']; ?> stat-card text-decoration-none">
                        <span class="hm-icon" aria-hidden="true"><i class="bi <?php echo $c['icon']; ?>"></i></span>
                        <span class="hm-body">
                            <span class="hm-title"><?php echo htmlspecialchars($c['title']); ?></span>
                            <span class="hm-blurb"><?php echo htmlspecialchars($c['blurb']); ?></span>
                        </span>
                        <?php if ($c['figure'] !== null): ?>
                        <span class="hm-figure">
                            <span class="hm-num"><?php echo htmlspecialchars((string)$c['figure']); ?></span>
                            <span class="hm-lbl"><?php echo htmlspecialchars($c['label']); ?></span>
                        </span>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="hm-empty">
                <i class="bi bi-shield-lock" aria-hidden="true"></i>
                <h2 class="h5 mb-1">No sections assigned yet</h2>
                <p class="text-muted mb-0">Your role (<?php echo htmlspecialchars($hmRole); ?>) hasn't been given access to anything yet. Please ask an administrator to update it.</p>
            </div>
            <?php endif; ?>

        </div>
    </div>

<style>
.hm-alert { display:flex; align-items:center; gap:.7rem; padding:.8rem 1.1rem; border-radius:.7rem; color:#7a4b00;
            background:#fff4d6; border:1px solid #ffe39a; }
.hm-alert:hover { background:#ffeebb; color:#7a4b00; }
.hm-actions { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
.hm-actions-label { font-size:.72rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#6c757d; margin-right:.25rem; }
.hm-section { font-size:.75rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#6c757d; margin:0 0 .75rem; }

.hm-card { --hm: #1742f5; --hm-soft: #e8edff;
           display:flex; align-items:center; gap:.9rem; height:100%; padding:1rem 1.1rem; color:inherit;
           background:#fff; border:1px solid #e6e8ee; border-left:4px solid var(--hm); border-radius:.75rem; }
.hm-card:hover { color:inherit; border-color:var(--hm); box-shadow:0 6px 16px rgba(16,24,40,.08); }
.hm-icon { flex:0 0 auto; width:46px; height:46px; display:grid; place-items:center; font-size:1.4rem;
           color:var(--hm); background:var(--hm-soft); border-radius:.65rem; }
.hm-body { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; }
.hm-title { font-weight:650; font-size:1rem; color:#1d2433; }
.hm-blurb { font-size:.8rem; color:#6c757d; line-height:1.3; }
.hm-figure { flex:0 0 auto; text-align:right; line-height:1.1; }
.hm-num { display:block; font-size:1.55rem; font-weight:700; color:var(--hm); }
.hm-lbl { display:block; font-size:.68rem; color:#6c757d; max-width:7.5rem; }
.hm-blue   { --hm:#1742f5; --hm-soft:#e8edff; }
.hm-purple { --hm:#51154b; --hm-soft:#f3e8f2; }
.hm-teal   { --hm:#0f766e; --hm-soft:#dcf5f2; }
.hm-orange { --hm:#c2570c; --hm-soft:#fdebd9; }
.hm-gold   { --hm:#9a6b00; --hm-soft:#fbf0cf; }
.hm-green  { --hm:#1d7a46; --hm-soft:#dff3e7; }
.hm-red    { --hm:#b42335; --hm-soft:#fbe3e6; }
.hm-indigo { --hm:#4338ca; --hm-soft:#e8e6fb; }
.hm-empty { text-align:center; padding:3rem 1rem; border:1px dashed #cfd4de; border-radius:.9rem; background:#fff; }
.hm-empty i { font-size:2.5rem; color:#9aa3b5; display:block; margin-bottom:.5rem; }
@media (prefers-reduced-motion: no-preference) { .hm-card { transition: box-shadow .15s, border-color .15s, transform .15s; } .hm-card:hover { transform: translateY(-1px); } }
</style>
<?php include 'shared/footer.php'; ?>
