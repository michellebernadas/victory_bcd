<?php
/**
 * Requests tab — the request lists, one list tab at a time:
 *
 *   Active · Waiting on me · My Requests · Upcoming confirmed · Past ·
 *   Cancelled & declined · All · Deleted (admin only)
 *
 * Which rows each list holds is decided in FacilityRequest::tabSql() (and the
 * Waiting-on-me desk by config/permissions.php); every list excludes
 * soft-deleted rows except Deleted itself. The search / filter form below is
 * applied inside whichever list is open, and the list tab travels with it in
 * the URL (and in every form's _return), so actions land back here.
 *
 * The table itself is views/shared/facility_requests_table.php; row actions
 * come from facility_row_actions.php. Conflict / lead-time advisories are
 * precomputed by the controller, so this view issues no queries in a loop.
 */
$requests      = $requests      ?? [];
$advisories    = $advisories    ?? [];
$facilities    = $facilities    ?? [];
$reqMinistries = $reqMinistries ?? [];
$activeFilters = $activeFilters ?? [];
$activeTab     = $activeTab     ?? 'calendar';
$statusMeta    = $statusMeta    ?? FacilityRequest::STATUSES;
$listTabs      = $listTabs      ?? FacilityRequest::LIST_TABS;
$activeList    = $activeList    ?? 'active';
$tabCounts     = $tabCounts     ?? [];
$currentUserType = $_SESSION['user']['accounttype'] ?? null;

// Filters carried across list tabs, so switching list keeps the search.
$fbFilterQs = [];
foreach (['status' => 'status', 'f_facility' => 'facility_id', 'f_ministry' => 'ministry',
          'date_from' => 'date_from', 'date_to' => 'date_to', 'q' => 'search'] as $qk => $fk) {
    if (isset($activeFilters[$fk]) && $activeFilters[$fk] !== '') $fbFilterQs[$qk] = $activeFilters[$fk];
}
$fbListUrl = function ($list) use ($fbFilterQs) {
    return 'index.php?' . http_build_query(['action' => 'facilityCalendar', 'tab' => 'requests', 'list' => $list] + $fbFilterQs);
};

$fbEmpty = [
    'active'   => ['bi-calendar-check', 'Nothing in progress. New requests appear here until their date passes.'],
    'waiting'  => ['bi-check2-all',     'Nothing is waiting on you right now.'],
    'mine'     => ['bi-person',         'You have not submitted any requests from this account yet.'],
    'upcoming' => ['bi-calendar2-check', 'No confirmed bookings coming up.'],
    'past'     => ['bi-clock-history',  'No past bookings or expired requests.'],
    'closed'   => ['bi-x-octagon',      'No cancelled or declined requests.'],
    'all'      => ['bi-calendar-x',     'No facility requests yet.'],
    'deleted'  => ['bi-trash3',         'No deleted requests. Deleting is only for test rows and duplicates.'],
];
$fbListHint = [
    'active'   => 'Requests still in the workflow (not yet past their date), plus confirmed bookings from today on.',
    'mine'     => 'Requests you submitted from this account. Imported requests have no owner and are not listed here.',
    'upcoming' => 'Confirmed (official) bookings from today on, soonest first.',
    'past'     => 'Bookings whose date has passed: confirmed ones, and requests that expired while still pending.',
    'closed'   => 'Cancelled and declined requests stay on record here.',
    'all'      => 'Every request, whatever its status.',
    'deleted'  => 'Admin only. Requests deleted as mistakes (test rows, duplicates). They are hidden everywhere else and reserve nothing. Restore puts one back exactly as it was.',
];
?>
<div class="<?php echo $activeTab === 'requests' ? '' : 'd-none'; ?>" id="facilityRequestsTab">

    <!-- List tabs: each with its count, honouring the filters below. -->
    <nav aria-label="Request lists" class="mb-2">
        <ul class="nav nav-pills fb-list-tabs flex-wrap gap-1">
            <?php foreach ($listTabs as $key => $lt):
                $n = (int)($tabCounts[$key] ?? 0);
                $on = ($key === $activeList);
                $hot = ($key === 'waiting' && $n > 0);
            ?>
            <li class="nav-item<?php echo $key === 'deleted' ? ' ms-lg-auto' : ''; ?>">
                <a class="nav-link<?php echo $on ? ' active' : ''; ?><?php echo $key === 'deleted' ? ' fb-list-deleted' : ''; ?>"
                   href="<?php echo htmlspecialchars($fbListUrl($key)); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>>
                    <i class="bi <?php echo $lt['icon']; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($lt['label']); ?>
                    <span class="badge rounded-pill ms-1 <?php echo $hot ? 'bg-danger' : ($on ? 'bg-light text-dark' : 'bg-secondary'); ?>"><?php echo $n; ?><span class="visually-hidden"> requests</span></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <?php if ($activeList === 'waiting'): ?>
        <?php include __DIR__ . '/facility_waiting_tab.php'; ?>
    <?php elseif (isset($fbListHint[$activeList])): ?>
    <p class="small text-muted mb-2<?php echo $activeList === 'deleted' ? ' text-danger-emphasis' : ''; ?>">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i><?php echo htmlspecialchars($fbListHint[$activeList]); ?>
    </p>
    <?php endif; ?>

    <!-- Search & filter, inside the open list -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <span class="text-white fw-semibold"><i class="bi bi-funnel me-2"></i>Search &amp; Filter
                <span class="fw-normal small">— within <?php echo htmlspecialchars($listTabs[$activeList]['label'] ?? 'this list'); ?></span></span>
        </div>
        <div class="card-body py-2">
            <form method="GET" action="index.php" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="facilityCalendar">
                <input type="hidden" name="tab" value="requests">
                <input type="hidden" name="list" value="<?php echo htmlspecialchars($activeList); ?>">
                <div class="col-12 col-md-6 col-lg-4">
                    <label class="form-label small mb-1" for="fbSearch">Search</label>
                    <input type="search" name="q" id="fbSearch" class="form-control form-control-sm" maxlength="100"
                           placeholder="Event, requestor or ministry"
                           value="<?php echo htmlspecialchars($activeFilters['search'] ?? ''); ?>">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1" for="fbStatus">Status</label>
                    <select name="status" id="fbStatus" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <?php foreach ($statusMeta as $key => $m): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"
                            <?php echo (($activeFilters['status'] ?? '') === $key) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m['label']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-3">
                    <label class="form-label small mb-1" for="fbFacility">Facility</label>
                    <select name="f_facility" id="fbFacility" class="form-select form-select-sm">
                        <option value="">All facilities</option>
                        <?php foreach ($facilities as $f): ?>
                        <option value="<?php echo (int)$f['id']; ?>"
                            <?php echo ((int)($activeFilters['facility_id'] ?? 0) === (int)$f['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($f['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-3">
                    <label class="form-label small mb-1" for="fbMinistry">Ministry</label>
                    <select name="f_ministry" id="fbMinistry" class="form-select form-select-sm">
                        <option value="">All ministries</option>
                        <?php foreach ($reqMinistries as $m): ?>
                        <option value="<?php echo htmlspecialchars($m); ?>"
                            <?php echo (($activeFilters['ministry'] ?? '') === $m) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                    <label class="form-label small mb-1" for="fbFrom">Date from</label>
                    <input type="date" name="date_from" id="fbFrom" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($activeFilters['date_from'] ?? ''); ?>">
                </div>
                <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                    <label class="form-label small mb-1" for="fbTo">Date to</label>
                    <input type="date" name="date_to" id="fbTo" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($activeFilters['date_to'] ?? ''); ?>">
                </div>
                <div class="col-12 col-md-auto d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill" title="Apply filters">
                        <i class="bi bi-search me-1" aria-hidden="true"></i>Filter
                    </button>
                    <a href="index.php?action=facilityCalendar&amp;tab=requests&amp;list=<?php echo urlencode($activeList); ?>"
                       class="btn btn-sm btn-outline-secondary" title="Clear filters">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body">
            <?php
            $tableRows    = $requests;
            $tableId      = 'facilityRequestsTable';
            $tableDeleted = ($activeList === 'deleted');
            [$fbEIcon, $fbEText] = $fbEmpty[$activeList] ?? $fbEmpty['all'];
            $tableEmpty = $activeFilters
                ? ['icon' => 'bi-funnel', 'text' => 'No requests in this list match these filters.', 'cta' => false]
                : ['icon' => $fbEIcon, 'text' => $fbEText, 'cta' => in_array($activeList, ['active', 'mine', 'all'], true)];
            include __DIR__ . '/facility_requests_table.php';
            ?>
        </div>
    </div>
</div>

<style>
.fb-list-tabs .nav-link { font-size: .8rem; padding: .2rem .5rem !important; color: var(--bs-body-color); border: 1px solid var(--bs-border-color, #dee2e6); background: #fff; white-space: nowrap; }
.fb-list-tabs .nav-link:hover { background: var(--vb-purple-soft, #f3e8f2); }
.fb-list-tabs .nav-link.active { background: var(--vb-purple, #51154b); border-color: var(--vb-purple, #51154b); color: #fff; }
.fb-list-tabs .fb-list-deleted:not(.active) { border-style: dashed; color: #6c757d; }
</style>
