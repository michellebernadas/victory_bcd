<?php
/**
 * Requests tab — every request with its workflow position and the action that
 * moves it to the next step.
 *
 * The action column is driven by status alone, so a screen can never offer two
 * steps at once or let a request skip Facilities review or the Minister.
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
            <?php if (empty($requests)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-calendar-x d-block mb-2" style="font-size:2rem"></i>
                    <p class="mb-2">No facility requests yet.</p>
                    <button class="btn btn-sm btn-primary" onclick="openFacilityRequestForm()">
                        <i class="bi bi-plus-circle me-1"></i>Create the first request
                    </button>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle data-table" id="facilityRequestsTable">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Event / Activity</th>
                            <th>Requestor</th>
                            <th>Ministry</th>
                            <th>Facility</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Flags</th>
                            <th class="text-end">Workflow</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $r):
                        $rid = (int)$r['id'];
                        $adv = $advisories[$rid] ?? ['hard' => [], 'soft' => [], 'lead_warn' => false, 'lead_days' => null, 'required' => null];
                        $status = $r['status'];
                    ?>
                        <tr>
                            <td><?php echo fb_badge($status); ?></td>
                            <td>
                                <a href="#" class="text-primary text-decoration-none fw-semibold"
                                   onclick="openFacilityDetail(<?php echo $rid; ?>); return false;">
                                    <?php echo htmlspecialchars($r['event_activity'] ?: '(untitled)'); ?>
                                </a>
                                <div class="text-muted" style="font-size:.7rem">
                                    <?php echo htmlspecialchars(fb_types($r['request_type'])); ?>
                                </div>
                            </td>
                            <td class="small"><?php echo htmlspecialchars($r['requestor_name']); ?></td>
                            <td class="small"><?php echo htmlspecialchars($r['ministry'] ?: '—'); ?></td>
                            <td class="small">
                                <?php if ($r['facility_name']): ?>
                                    <?php echo htmlspecialchars($r['facility_name']); ?>
                                <?php else: ?>
                                    <span class="text-muted" title="No room requested — nothing is occupied, so no conflict check runs.">
                                        <i class="bi bi-dash-circle me-1"></i>No room
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-nowrap"><?php echo htmlspecialchars(fb_date($r['date_needed'])); ?></td>
                            <td class="small text-nowrap">
                                <?php
                                $t = trim(fb_time($r['start_time']) . ($r['end_time'] ? ' – ' . fb_time($r['end_time']) : ''));
                                echo $t !== '' ? htmlspecialchars($t) : '<span class="text-muted">—</span>';
                                ?>
                            </td>
                            <td class="text-nowrap">
                                <?php if (!empty($adv['hard'])): ?>
                                <span class="badge bg-danger" title="Overlaps <?php echo count($adv['hard']); ?> CONFIRMED reservation(s) in the same room.">
                                    <i class="bi bi-exclamation-octagon-fill me-1"></i>Conflict
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($adv['soft'])): ?>
                                <span class="badge bg-warning text-dark" title="Overlaps <?php echo count($adv['soft']); ?> pending request(s). Nothing is reserved yet, but watch for a clash.">
                                    <i class="bi bi-exclamation-triangle me-1"></i>Possible clash
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($adv['lead_warn'])): ?>
                                <span class="badge bg-secondary"
                                      title="Submitted with <?php echo (int)$adv['lead_days']; ?> day(s) lead time; <?php echo (int)$adv['required']; ?> recommended (policy section 10). Advisory only.">
                                    <i class="bi bi-stopwatch me-1"></i>Short notice
                                </span>
                                <?php endif; ?>
                                <?php if (empty($adv['hard']) && empty($adv['soft']) && empty($adv['lead_warn'])): ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php include 'facility_row_actions.php'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
