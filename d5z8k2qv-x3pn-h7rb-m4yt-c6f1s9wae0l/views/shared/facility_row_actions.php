<?php
/**
 * Workflow actions for one request row.
 *
 * ONE primary button: the single next step THIS account can take, from
 * facilityNextAction() in config/permissions.php (status picks the step, the
 * role helpers decide whether this person may take it — the same helpers the
 * route and the controller ask). When the viewer cannot act, the row says
 * whose turn it is instead ("Waiting for Minister"). The model's from-status
 * checks still decide server-side; this is the convenience, not the guard.
 *
 * Secondary actions live in a labelled "More" menu: View details and History
 * for everyone who can see the page; Cancel request only for
 * canCancelFacilityRequest().
 *
 * Expects $r (the request row), $adv, $currentUserType from the caller.
 */
require_once __DIR__ . '/facility_stepper.php';
$status = $r['status'];
$rid    = (int)$r['id'];
$facilityPayload = htmlspecialchars(json_encode(fb_row_payload($r, count($adv['hard']), count($adv['soft']))), ENT_QUOTES);
$next   = facilityNextAction($status, $currentUserType);
$owner  = facilityStageOwner($status);
$openers = ['review' => 'openFacilitiesReview', 'decide' => 'openMinisterDecision', 'confirm' => 'openConfirmRequest'];
$btnClass = ['review' => 'btn-primary', 'decide' => 'btn-purple', 'confirm' => 'btn-success'];
$btnIcon  = ['review' => 'bi-clipboard-check', 'decide' => 'bi-person-check', 'confirm' => 'bi-calendar2-check'];
$canCancel = !in_array($status, ['cancelled', 'declined'], true) && canCancelFacilityRequest($currentUserType);
?>
<div class="d-flex justify-content-end align-items-center gap-1 flex-wrap">
    <?php if ($next): ?>
        <button type="button" class="btn btn-sm <?php echo $btnClass[$next['key']]; ?>"
                onclick='<?php echo $openers[$next['key']]; ?>(<?php echo $facilityPayload; ?>)'
                title="<?php echo htmlspecialchars($next['hint']); ?>" data-bs-toggle="tooltip">
            <i class="bi <?php echo $btnIcon[$next['key']]; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($next['label']); ?>
        </button>
    <?php elseif ($owner): ?>
        <span class="small text-muted text-nowrap fb-waiting">
            <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Waiting for <?php echo htmlspecialchars($owner); ?>
        </span>
    <?php endif; ?>

    <div class="dropdown">
        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"
                data-bs-popper-config='{"strategy":"fixed"}'
                aria-expanded="false" aria-label="More actions for <?php echo htmlspecialchars($r['event_activity'] ?: 'this request'); ?>">
            More
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><button type="button" class="dropdown-item" onclick="openFacilityDetail(<?php echo $rid; ?>)">
                <i class="bi bi-eye me-2" aria-hidden="true"></i>View details</button></li>
            <li><button type="button" class="dropdown-item"
                        onclick="openFacilityHistory(<?php echo $rid; ?>, <?php echo htmlspecialchars(json_encode($r['event_activity']), ENT_QUOTES); ?>)">
                <i class="bi bi-clock-history me-2" aria-hidden="true"></i>History</button></li>
            <?php if ($canCancel): ?>
            <li><hr class="dropdown-divider"></li>
            <li><button type="button" class="dropdown-item text-danger"
                        onclick='openCancelRequest(<?php echo $facilityPayload; ?>)'>
                <i class="bi bi-x-circle me-2" aria-hidden="true"></i>Cancel request</button></li>
            <?php endif; ?>
        </ul>
    </div>
</div>
