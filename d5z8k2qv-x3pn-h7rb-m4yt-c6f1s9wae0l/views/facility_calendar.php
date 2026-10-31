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
$waitingRequests  = $waitingRequests  ?? [];
$waitingStatuses  = $waitingStatuses  ?? [];
$stageEvents      = $stageEvents      ?? [];
$approvers        = $approvers        ?? [];
$currentAccount   = $currentAccount   ?? ['username' => '', 'email' => ''];
$currentUserType  = $_SESSION['user']['accounttype'] ?? null;
$listTabs         = $listTabs         ?? FacilityRequest::LIST_TABS;
$activeList       = $activeList       ?? 'active';
$tabCounts        = $tabCounts        ?? [];
$setupRows        = $setupRows        ?? [];
$setupFrom        = $setupFrom        ?? date('Y-m-d');
$setupTo          = $setupTo          ?? date('Y-m-d');
$fbReturn         = $fbReturn         ?? 'tab=requests';
require_once __DIR__ . '/shared/facility_stepper.php';

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
        // Past and still pending: drawn faded and labelled Expired.
        'expired'     => !empty($r['is_expired']),
        // Shown on hover / in the chip's accessible name: whose turn it is.
        'waiting_for' => !empty($r['is_expired']) ? null : facilityStageOwner($r['status']),
        'when'        => fb_when_range($r['date_needed'], $r['start_time'], $r['end_time']),
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
                    <?php if (canImportFacilityRequests($currentUserType)): ?>
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
                    'resubmitted'            => 'Request resubmitted. It is back with Facilities Personnel for review.',
                    'deleted'                => 'Request deleted. It is hidden everywhere and no longer reserves anything; admins can restore it from the Deleted list.',
                    'restored'               => 'Request restored, exactly as it was before it was deleted.',
                    'series_submitted'       => (int)($_GET['n'] ?? 0) . ' weekly requests submitted. Each one goes through Facilities review, the Minister and confirmation on its own — none is reserved yet.',
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
                ['label' => 'Awaiting Action',    'value' => $stats['needs_action'] ?? 0, 'color' => 'warning',
                 'hint'  => 'Waiting on Facilities review, the Minister, or Facilities confirmation'],
                ['label' => 'Waiting on Requestor', 'value' => $stats['waiting_requestor'] ?? 0, 'color' => 'purple',
                 'hint'  => 'Returned for clarification', 'href' => 'index.php?action=facilityCalendar&tab=requests&list=active&status=for_clarification'],
                ['label' => 'Expired',            'value' => $stats['expired']      ?? 0, 'color' => 'secondary',
                 'hint'  => 'Still pending after the date passed. Shown under Past; nothing was changed automatically.',
                 'href'  => 'index.php?action=facilityCalendar&tab=requests&list=past'],
                ['label' => 'Declined',           'value' => $stats['declined']     ?? 0, 'color' => 'danger'],
            ];
            ?>
            <div class="row mb-3 g-2">
                <?php foreach ($statCards as $i => $sc): ?>
                <div class="col-6 col-md-3 col-xl">
                    <div class="card text-center h-100<?php echo $i === 0 ? ' border-primary border-2' : ''; ?>"
                         <?php if (!empty($sc['hint'])): ?>title="<?php echo htmlspecialchars($sc['hint']); ?>"<?php endif; ?>>
                        <div class="card-body py-2 px-1">
                            <div class="h4 fw-bold text-<?php echo $sc['color']; ?> mb-0"><?php echo (int)$sc['value']; ?></div>
                            <div style="font-size:11px;" class="text-muted">
                                <?php if (!empty($sc['href'])): ?>
                                <a href="<?php echo htmlspecialchars($sc['href']); ?>" class="text-muted"><?php echo htmlspecialchars($sc['label']); ?></a>
                                <?php else: ?>
                                <?php echo htmlspecialchars($sc['label']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php include 'shared/facility_approvers_panel.php'; ?>

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
                        <?php if (!empty($waitingRequests)): ?>
                        <span class="badge bg-danger ms-1" title="<?php echo count($waitingRequests); ?> waiting on you"><?php echo count($waitingRequests); ?><span class="visually-hidden"> waiting on you</span></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php if (canViewFacilitySetupSheet($currentUserType)): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'setup' ? 'active' : ''; ?>"
                       href="index.php?action=facilityCalendar&tab=setup">
                        <i class="bi bi-clipboard2-data me-1" aria-hidden="true"></i>Setup sheet
                    </a>
                </li>
                <?php endif; ?>
            </ul>

<?php include 'shared/facility_calendar_tab.php'; ?>
<?php include 'shared/facility_requests_tab.php'; ?>
<?php if ($activeTab === 'setup' && canViewFacilitySetupSheet($currentUserType)) include 'shared/facility_setup_tab.php'; ?>

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
<script>
// Tooltips on the labelled workflow buttons and the compact stepper dots.
document.addEventListener('DOMContentLoaded', function () {
    if (!window.bootstrap || !bootstrap.Tooltip) return;
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (n) {
        bootstrap.Tooltip.getOrCreateInstance(n);
    });
});
</script>
<style>
/* ── Stepper ─────────────────────────────────────────────────────────────── */
.fb-stepper-compact { gap: 0; }
.fb-stepper-compact .fb-step { font-size: .85rem; line-height: 1; }
.fb-stepper-compact .fb-step-line { width: 10px; height: 2px; background: #dee2e6; }
.fb-step-card { border: 1px solid #dee2e6; border-radius: .375rem; padding: .4rem .5rem; background: #fff; }
.fb-step-card.fb-step-current  { border-color: var(--vb-purple, #51154b); box-shadow: 0 0 0 1px var(--vb-purple, #51154b) inset; }
.fb-step-card.fb-step-done     { background: #f3faf6; }
.fb-step-card.fb-step-declined { background: #fdf2f3; border-color: #dc3545; }
.fb-step-card.fb-step-clarify  { background: #fff9e6; border-color: #ffc107; }
.fb-step-card.fb-step-cancelled{ background: #f1f3f5; border-color: #495057; }
.fb-step-card.fb-step-skipped  { opacity: .6; }
.fb-step-state { font-size: .72rem; font-weight: 600; }
.fb-step-who   { font-size: .68rem; margin-top: 2px; }
/* ── Flag chips ──────────────────────────────────────────────────────────── */
.fb-chip { display: inline-flex; align-items: center; gap: 3px; font-size: .68rem; font-weight: 600;
           border-radius: 999px; padding: 1px 7px; border: 1px solid; white-space: nowrap; }
.fb-chip-danger  { color: #b02a37; border-color: #f1aeb5; background: #fdf2f3; }
.fb-chip-warning { color: #8a6100; border-color: #ffe69c; background: #fff9e6; }
.fb-chip-muted   { color: #495057; border-color: #ced4da; background: #f8f9fa; }
.fb-field-msg { font-size: .75rem; }
</style>

<?php include 'shared/footer.php'; ?>
