<?php
/**
 * Workflow buttons for one request row.
 *
 * Exactly one "next step" is ever offered, chosen by the current status, so the
 * UI cannot let a request skip Facilities review or the Minister. The model
 * enforces the same rules again server-side — this is the convenience, not the
 * guard.
 *
 * TWO INDEPENDENT FILTERS DECIDE WHAT IS SHOWN
 *   1. Status — which step is next. Exactly one is ever offered.
 *   2. Role   — whether THIS person may take that step, via the helpers in
 *      config/permissions.php, the same ones the route and the controller use.
 * Both must pass, so nobody is ever shown a button that would bounce them.
 * Hidden rather than disabled: a greyed-out "Decide" would read as "ask an
 * admin to click this", when the point is that the Minister's decision is not
 * Facilities' to make at all.
 *
 * View and History are deliberately outside both filters — anyone who can see
 * the booking page can read a request and its approval trail.
 *
 * Expects $r (the request row), $rid, $adv, $currentUserType from the caller.
 */
$status = $r['status'];
$rid    = (int)$r['id'];
$facilityPayload = htmlspecialchars(json_encode([
    'id'       => $rid,
    'event'    => $r['event_activity'],
    'ministry' => $r['ministry'],
    'facility' => $r['facility_name'],
    'date'     => $r['date_needed'],
    'start'    => $r['start_time'],
    'end'      => $r['end_time'],
    'hard'     => count($adv['hard']),
    'soft'     => count($adv['soft']),
]), ENT_QUOTES);
?>
<div class="btn-group btn-group-sm">

    <?php if (in_array($status, ['submitted', 'facilities_review', 'for_clarification'], true)
              && canFacilitiesReview($currentUserType)): ?>
        <!-- Step 2 — Facilities Personnel assess logistics (policy section 8). -->
        <button type="button" class="btn btn-outline-primary"
                onclick='openFacilitiesReview(<?php echo $facilityPayload; ?>)'
                title="Step 2 of 4 — Facilities review, then forward to the Minister">
            <i class="bi bi-clipboard-check me-1"></i>Review
        </button>
    <?php endif; ?>

    <?php if (in_array($status, ['pending_minister', 'for_clarification'], true)
              && canMinisterApprove($currentUserType)): ?>
        <!-- Step 3 — Minister gives the final ministry approval (policy section 9). -->
        <button type="button" class="btn btn-outline-purple"
                onclick='openMinisterDecision(<?php echo $facilityPayload; ?>)'
                title="Step 3 of 4 — Minister decision">
            <i class="bi bi-person-check me-1"></i>Decide
        </button>
    <?php endif; ?>

    <?php if ($status === 'approved' && canFacilitiesConfirm($currentUserType)): ?>
        <!-- Step 4 — the ONLY step that creates an official reservation. -->
        <button type="button" class="btn btn-success"
                onclick='openConfirmRequest(<?php echo $facilityPayload; ?>)'
                title="Step 4 of 4 — Facilities confirmation. This makes it an official reservation.">
            <i class="bi bi-calendar2-check me-1"></i>Confirm
        </button>
    <?php endif; ?>

    <button type="button" class="btn btn-outline-secondary"
            onclick="openFacilityDetail(<?php echo $rid; ?>)" title="View full request">
        <i class="bi bi-eye"></i>
    </button>

    <button type="button" class="btn btn-outline-secondary"
            onclick="openFacilityHistory(<?php echo $rid; ?>, <?php echo htmlspecialchars(json_encode($r['event_activity']), ENT_QUOTES); ?>)"
            title="Approval / status history">
        <i class="bi bi-clock-history"></i>
    </button>

    <?php if (!in_array($status, ['cancelled', 'declined'], true) && canCancelFacilityRequest($currentUserType)): ?>
        <button type="button" class="btn btn-outline-danger"
                onclick='openCancelRequest(<?php echo $facilityPayload; ?>)'
                title="Cancel this request">
            <i class="bi bi-x-circle"></i>
        </button>
    <?php endif; ?>
</div>
