<?php
/**
 * Facility Booking — official facility calendar (policy section 20), the
 * request form (section 7 A–F) and the four-step approval workflow.
 *
 * Every variable this view reads is defaulted below, so the page renders
 * identically against empty facility/request tables and never emits a notice.
 */
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
include 'shared/header.php';

$facilities       = $facilities       ?? [];
$bookable         = $bookable         ?? [];
$calendarRequests = $calendarRequests ?? [];
$requests         = $requests         ?? [];
$advisories       = $advisories       ?? [];
$stats            = $stats            ?? [];
$ministries       = $ministries       ?? [];
$reqMinistries    = $reqMinistries    ?? [];
$allMembers       = $allMembers       ?? [];
$statusMeta       = $statusMeta       ?? FacilityRequest::STATUSES;
$statusColors     = $statusColors     ?? FacilityRequest::STATUS_COLORS;
$requestTypes     = $requestTypes     ?? FacilityRequest::REQUEST_TYPES;
$activeFilters    = $activeFilters    ?? [];
$activeTab        = $activeTab        ?? 'calendar';
$focusDate        = $focusDate        ?? date('Y-m-d');
$currentUserType  = $_SESSION['user']['accounttype'] ?? null;

/** Status badge — one consistent rendering everywhere on the page. */
function fb_badge($status) {
    $m = FacilityRequest::statusMeta($status);
    $official = ($status === FacilityRequest::BLOCKING_STATUS);
    return '<span class="badge bg-' . $m['badge'] . ($official ? ' fb-official' : '') . '">'
         . '<i class="bi ' . $m['icon'] . ' me-1"></i>' . htmlspecialchars($m['label']) . '</span>';
}

function fb_time($t) {
    $t = trim((string)$t);
    return $t === '' ? '' : date('g:i A', strtotime('2000-01-01 ' . $t));
}

function fb_date($d) {
    $d = trim((string)$d);
    return $d === '' ? '—' : date('M j, Y', strtotime($d));
}

/** Pretty-print the request_type SET column. */
function fb_types($set) {
    $labels = FacilityRequest::REQUEST_TYPES;
    $out = [];
    foreach (array_filter(explode(',', (string)$set)) as $t) {
        $out[] = $labels[$t] ?? $t;
    }
    return $out ? implode(', ', $out) : '—';
}

// Calendar payload. json_encode of an empty array yields [], which the JS
// handles as "no bookings" rather than as an error.
$calendarPayload = [];
foreach ($calendarRequests as $r) {
    $calendarPayload[] = [
        'id'          => (int)$r['id'],
        'date'        => $r['date_needed'],
        'start'       => $r['start_time'],
        'end'         => $r['end_time'],
        'event'       => $r['event_activity'],
        'ministry'    => $r['ministry'],
        'facility'    => $r['facility_name'],
        'facility_id' => $r['facility_id'] !== null ? (int)$r['facility_id'] : null,
        'status'      => $r['status'],
    ];
}
?>

<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-calendar3 me-2 text-purple"></i>Facility Booking</h1>
                    <p class="text-muted mb-0">Official facility calendar, requests, and the approval workflow</p>
                </div>
                <?php if (canSubmitFacilityRequest($currentUserType)): ?>
                <div class="d-flex gap-2">
                    <?php if ($currentUserType === 'admin'): ?>
                    <a href="index.php?action=facilityImport" class="btn btn-outline-primary">
                        <i class="bi bi-upload me-1"></i> Import Request Forms
                    </a>
                    <?php endif; ?>
                    <button class="btn btn-primary" onclick="openFacilityRequestForm()">
                        <i class="bi bi-plus-circle me-1"></i> New Request
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- THE rule, stated on the page itself (policy 3.6 / 20). -->
            <div class="alert alert-light border d-flex align-items-start gap-2 py-2 mb-3">
                <i class="bi bi-info-circle-fill text-purple mt-1"></i>
                <div class="small mb-0">
                    Submitting a request does not mean it has been approved. A reservation becomes
                    <strong>official</strong> only after
                    <span class="text-nowrap">Minister Approval &rarr; Facilities Confirmation &rarr; Calendar Entry</span>.
                    Only <?php echo fb_badge('confirmed'); ?> entries reserve a room — everything earlier is shown
                    on the calendar for visibility but blocks nothing.
                </div>
            </div>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <?php
                $msgs = [
                    'submitted'              => 'Request submitted. It is now with Facilities Personnel for review — this is not yet an approved or reserved booking.',
                    'submitted_short_notice' => 'Request submitted below the recommended lead time. Last-minute requests may still be considered (policy section 11), but approval is not guaranteed.',
                    'updated'                => 'Request updated successfully.',
                    'reviewed'               => 'Facilities review saved. The request has been forwarded to the Minister for final approval.',
                    'approved'               => 'Minister APPROVED this request. It is still not an official reservation — Facilities must confirm it before it occupies the calendar.',
                    'declined'               => 'Minister DECLINED this request.',
                    'clarification'          => 'Returned for clarification. The requestor and Facilities can discuss before a decision is made.',
                    'confirmed'              => 'Request CONFIRMED. This is now an official reservation and it blocks the room on the calendar.',
                    'cancelled'              => 'Request cancelled. The slot is free for other ministries.',
                ];
                $notifKey   = $_GET['notif'];
                $notifClass = in_array($notifKey, ['submitted_short_notice', 'clarification'], true) ? 'warning'
                            : ($notifKey === 'declined' ? 'danger' : 'success');
            ?>
            <div class="alert alert-<?php echo $notifClass; ?> alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($msgs[$notifKey] ?? 'Done.'); ?>
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

            <!-- Stat cards -->
            <?php
            $statCards = [
                ['label' => 'Total Requests',     'value' => $stats['total']        ?? 0, 'color' => 'primary'],
                ['label' => 'Confirmed',          'value' => $stats['confirmed']    ?? 0, 'color' => 'success'],
                ['label' => 'Upcoming Confirmed', 'value' => $stats['upcoming']     ?? 0, 'color' => 'info'],
                ['label' => 'Awaiting Action',    'value' => $stats['needs_action'] ?? 0, 'color' => 'warning'],
                ['label' => 'Declined',           'value' => $stats['declined']     ?? 0, 'color' => 'danger'],
            ];
            ?>
            <div class="row mb-3 g-2">
                <?php foreach ($statCards as $i => $sc): ?>
                <div class="col-6 col-md-4 col-xl">
                    <div class="card text-center h-100<?php echo $i === 0 ? ' border-primary border-2' : ''; ?>">
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-<?php echo $sc['color']; ?> mb-0"><?php echo (int)$sc['value']; ?></div>
                            <div style="font-size:11px;" class="text-muted"><?php echo htmlspecialchars($sc['label']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'calendar' ? 'active' : ''; ?>"
                       href="index.php?action=facilityCalendar&tab=calendar">
                        <i class="bi bi-calendar3 me-1"></i>Calendar
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'requests' ? 'active' : ''; ?>"
                       href="index.php?action=facilityCalendar&tab=requests">
                        <i class="bi bi-list-check me-1"></i>Requests
                        <?php if (!empty($stats['total'])): ?>
                        <span class="badge bg-secondary ms-1"><?php echo (int)$stats['total']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>

<?php include 'shared/facility_calendar_tab.php'; ?>
<?php include 'shared/facility_requests_tab.php'; ?>

        </div>
    </div>

<?php include 'shared/facility_modals.php'; ?>

<script>
window.FACILITY_CALENDAR_DATA = {
    events: <?php echo json_encode($calendarPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    colors: <?php echo json_encode($statusColors); ?>,
    labels: <?php echo json_encode(array_map(function ($m) { return $m['label']; }, $statusMeta)); ?>,
    confirmedStatus: <?php echo json_encode(FacilityRequest::BLOCKING_STATUS); ?>,
    focusDate: <?php echo json_encode($focusDate); ?>,
    initialView: 'month'
};
</script>
<script src="<?php echo asset('js/facility-calendar.js'); ?>"></script>

<?php include 'shared/footer.php'; ?>
