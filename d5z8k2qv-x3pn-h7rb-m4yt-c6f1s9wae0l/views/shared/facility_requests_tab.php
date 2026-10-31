<?php
/**
 * Requests tab — every request with its workflow position and the action that
 * moves it to the next step.
 *
 * The table itself is views/shared/facility_requests_table.php (shared with
 * the "Waiting on me" tab); row actions come from facility_row_actions.php.
 * Conflict / lead-time advisories are precomputed by the controller
 * (FacilityController::advisoryFor) so this table issues no queries in a loop.
 */
$requests      = $requests      ?? [];
$advisories    = $advisories    ?? [];
$facilities    = $facilities    ?? [];
$reqMinistries = $reqMinistries ?? [];
$activeFilters = $activeFilters ?? [];
$activeTab     = $activeTab     ?? 'calendar';
$statusMeta    = $statusMeta    ?? FacilityRequest::STATUSES;
$currentUserType = $_SESSION['user']['accounttype'] ?? null;
?>
<div class="<?php echo $activeTab === 'requests' ? '' : 'd-none'; ?>" id="facilityRequestsTab">

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <span class="text-white fw-semibold"><i class="bi bi-funnel me-2"></i>Search &amp; Filter</span>
        </div>
        <div class="card-body py-2">
            <form method="GET" action="index.php" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="facilityCalendar">
                <input type="hidden" name="tab" value="requests">
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <?php foreach ($statusMeta as $key => $m): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"
                            <?php echo (($activeFilters['status'] ?? '') === $key) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m['label']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1">Facility</label>
                    <select name="f_facility" class="form-select form-select-sm">
                        <option value="">All facilities</option>
                        <?php foreach ($facilities as $f): ?>
                        <option value="<?php echo (int)$f['id']; ?>"
                            <?php echo ((int)($activeFilters['facility_id'] ?? 0) === (int)$f['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($f['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1">Ministry</label>
                    <select name="f_ministry" class="form-select form-select-sm">
                        <option value="">All ministries</option>
                        <?php foreach ($reqMinistries as $m): ?>
                        <option value="<?php echo htmlspecialchars($m); ?>"
                            <?php echo (($activeFilters['ministry'] ?? '') === $m) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($m); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1">Date from</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($activeFilters['date_from'] ?? ''); ?>">
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <label class="form-label small mb-1">Date to</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($activeFilters['date_to'] ?? ''); ?>">
                </div>
                <div class="col-6 col-md-3 col-lg-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                    <a href="index.php?action=facilityCalendar&tab=requests" class="btn btn-sm btn-outline-secondary">
                        Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body">
            <?php
            $tableRows  = $requests;
            $tableId    = 'facilityRequestsTable';
            $tableEmpty = $activeFilters
                ? ['icon' => 'bi-funnel', 'text' => 'No requests match these filters.', 'cta' => false]
                : ['icon' => 'bi-calendar-x', 'text' => 'No facility requests yet.', 'cta' => true];
            include __DIR__ . '/facility_requests_table.php';
            ?>
        </div>
    </div>
</div>
