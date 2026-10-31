<?php
/**
 * The request table, shared by the "Requests" and "Waiting on me" tabs.
 *
 * Expects from the caller:
 *   $tableRows   request rows (FacilityRequest::getAllRequests shape)
 *   $tableId     DOM id for the table
 *   $tableEmpty  ['icon' => ..., 'text' => ..., 'cta' => bool]
 * plus $advisories, $stageEvents, $currentUserType from facility_calendar.php.
 *
 * No queries here: advisories and stepper history are precomputed by
 * FacilityController::showCalendar.
 */
require_once __DIR__ . '/facility_stepper.php';
$tableRows   = $tableRows   ?? [];
$tableId     = $tableId     ?? 'facilityRequestsTable';
$tableEmpty  = $tableEmpty  ?? ['icon' => 'bi-calendar-x', 'text' => 'No facility requests yet.', 'cta' => true];
$advisories  = $advisories  ?? [];
$stageEvents = $stageEvents ?? [];
$tableDeleted = $tableDeleted ?? false;   // Deleted list: who / when / why instead of flags
?>
<?php if (empty($tableRows)): ?>
    <div class="text-center text-muted py-5">
        <i class="bi <?php echo htmlspecialchars($tableEmpty['icon']); ?> d-block mb-2" style="font-size:2rem" aria-hidden="true"></i>
        <p class="mb-2"><?php echo htmlspecialchars($tableEmpty['text']); ?></p>
        <?php if (!empty($tableEmpty['cta']) && canSubmitFacilityRequest($currentUserType)): ?>
        <button class="btn btn-sm btn-primary" onclick="openFacilityRequestForm()">
            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Create a request
        </button>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-sm table-hover align-middle data-table fb-table" id="<?php echo htmlspecialchars($tableId); ?>" data-order="[]">
        <thead>
            <tr>
                <th>Status &amp; progress</th>
                <th>Request</th>
                <th>Facility</th>
                <th>When</th>
                <th><?php echo $tableDeleted ? 'Deleted' : 'Flags'; ?></th>
                <th class="text-end">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tableRows as $r):
            $rid = (int)$r['id'];
            $adv = $advisories[$rid] ?? ['hard' => [], 'soft' => [], 'lead_warn' => false, 'lead_days' => null, 'required' => null];
            $steps = FacilityRequest::stepper($r['status'], $stageEvents[$rid] ?? []);
            $when  = fb_when_range($r['date_needed'], $r['start_time'], $r['end_time']);
        ?>
            <tr class="<?php echo !empty($r['is_expired']) ? 'fb-row-expired' : ''; ?>">
                <td data-order="<?php echo htmlspecialchars($r['status']); ?>">
                    <?php echo fb_badge($r['status']); ?>
                    <?php echo fb_expired_badge($r); ?>
                    <div class="mt-1"><?php echo fb_stepper_html($steps, true); ?></div>
                </td>
                <td>
                    <a href="#" class="text-primary text-decoration-none fw-semibold"
                       onclick="openFacilityDetail(<?php echo $rid; ?>); return false;">
                        <?php echo htmlspecialchars($r['event_activity'] ?: '(untitled)'); ?>
                    </a>
                    <div class="small text-body">
                        <?php echo htmlspecialchars($r['requestor_name']); ?>
                        <?php if (!empty($r['ministry'])): ?>
                            <span class="text-muted">· <?php echo htmlspecialchars($r['ministry']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:.7rem"><?php echo htmlspecialchars(fb_types($r['request_type'])); ?></div>
                </td>
                <td class="small">
                    <?php if ($r['facility_name']): ?>
                        <?php echo htmlspecialchars($r['facility_name']); ?>
                    <?php else: ?>
                        <span class="text-muted" title="No room requested — nothing is occupied, so no conflict check runs.">
                            <i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No room
                        </span>
                    <?php endif; ?>
                </td>
                <td class="small text-nowrap" data-order="<?php echo htmlspecialchars(($r['date_needed'] ?? '') . ' ' . ($r['start_time'] ?? '')); ?>">
                    <?php echo $when !== '' ? htmlspecialchars($when) : '<span class="text-muted">No date</span>'; ?>
                </td>
                <td>
                    <?php if ($tableDeleted): ?>
                    <div class="small">
                        <div><i class="bi bi-person me-1" aria-hidden="true"></i><?php echo htmlspecialchars($r['deleted_by_name'] ?: 'Unknown'); ?></div>
                        <div class="text-muted"><?php echo $r['deleted_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($r['deleted_at']))) : ''; ?></div>
                        <div class="fst-italic">&ldquo;<?php echo htmlspecialchars((string)$r['delete_reason']); ?>&rdquo;</div>
                    </div>
                    <?php else: ?>
                    <div class="d-flex flex-wrap gap-1">
                    <?php if (!empty($adv['hard'])): ?>
                        <span class="fb-chip fb-chip-danger" title="Overlaps <?php echo count($adv['hard']); ?> CONFIRMED reservation(s) in the same room.">
                            <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>Conflict
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($adv['soft'])): ?>
                        <span class="fb-chip fb-chip-warning" title="Overlaps <?php echo count($adv['soft']); ?> pending request(s). Nothing is reserved yet.">
                            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Possible clash
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($adv['lead_warn'])): ?>
                        <span class="fb-chip fb-chip-muted"
                              title="Submitted with <?php echo (int)$adv['lead_days']; ?> day(s) lead time; <?php echo (int)$adv['required']; ?> recommended (policy section 10). Advisory only.">
                            <i class="bi bi-stopwatch" aria-hidden="true"></i>Short notice
                        </span>
                    <?php endif; ?>
                    <?php if (empty($adv['hard']) && empty($adv['soft']) && empty($adv['lead_warn']) && empty($r['series_id'])): ?>
                        <span class="text-muted small">None</span>
                    <?php endif; ?>
                    <?php if (!empty($r['series_id'])): ?>
                        <span class="fb-chip fb-chip-muted" title="One of a weekly series of separate requests.">
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Weekly
                        </span>
                    <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php include __DIR__ . '/facility_row_actions.php'; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
