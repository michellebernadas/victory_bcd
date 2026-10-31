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
 * Special rows:
 *   expired  — no workflow step is offered; staff get "Close as expired" (a
 *              cancel with a prefilled note), everyone else sees "Expired".
 *   deleted  — only Restore (admins), plus View details / History.
 *
 * Secondary actions live in a labelled "More" menu: View details, History,
 * Book again, and — per request, via the permission helpers — Edit / Edit &
 * resubmit (owner), Cancel (staff, or the owner before the date), Delete
 * (admin).
 *
 * Expects $r (the request row), $adv, $currentUserType from the caller.
 */
require_once __DIR__ . '/facility_stepper.php';
$status  = $r['status'];
$rid     = (int)$r['id'];
$deleted = !empty($r['is_deleted']);
$expired = !empty($r['is_expired']);
$facilityPayload = htmlspecialchars(json_encode(fb_row_payload($r, count($adv['hard']), count($adv['soft']))), ENT_QUOTES);
$next    = ($deleted || $expired) ? null : facilityNextAction($status, $currentUserType);
$owner   = ($deleted || $expired) ? null : facilityStageOwner($status);
$openers = ['review' => 'openFacilitiesReview', 'decide' => 'openMinisterDecision', 'confirm' => 'openConfirmRequest'];
$btnClass = ['review' => 'btn-primary', 'decide' => 'btn-purple', 'confirm' => 'btn-success'];
$btnIcon  = ['review' => 'bi-clipboard-check', 'decide' => 'bi-person-check', 'confirm' => 'bi-calendar2-check'];
$canCancel    = canCancelThisFacilityRequest($r, $currentUserType);
$canClose     = canCloseExpiredFacilityRequest($r, $currentUserType);
$canEdit      = canEditFacilityRequest($r, $currentUserType);
$canDelete    = !$deleted && canDeleteFacilityRequest($currentUserType);
$canRestore   = $deleted && canRestoreFacilityRequest($currentUserType);
$canBookAgain = !$deleted && canBookFacilityAgain($currentUserType);
$eventJs      = htmlspecialchars(json_encode($r['event_activity']), ENT_QUOTES);
?>
<div class="d-flex justify-content-end align-items-center gap-1 flex-wrap">
    <?php if ($canRestore): ?>
        <button type="button" class="btn btn-sm btn-outline-success text-nowrap" onclick='openRestoreRequest(<?php echo $facilityPayload; ?>)'>
            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore
        </button>
    <?php elseif ($canClose): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" onclick='openCloseExpired(<?php echo $facilityPayload; ?>)'
                title="Cancel it with a note that the date passed before it was confirmed" data-bs-toggle="tooltip">
            <i class="bi bi-archive me-1" aria-hidden="true"></i>Close as expired
        </button>
    <?php elseif ($next): ?>
        <button type="button" class="btn btn-sm text-nowrap <?php echo $btnClass[$next['key']]; ?>"
                onclick='<?php echo $openers[$next['key']]; ?>(<?php echo $facilityPayload; ?>)'
                title="<?php echo htmlspecialchars($next['hint']); ?>" data-bs-toggle="tooltip">
            <i class="bi <?php echo $btnIcon[$next['key']]; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($next['label']); ?>
        </button>
    <?php elseif ($canEdit && $status === 'for_clarification'): ?>
        <button type="button" class="btn btn-sm btn-warning text-nowrap" onclick="openEditRequest(<?php echo $rid; ?>)">
            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Edit &amp; resubmit
        </button>
    <?php elseif ($expired): ?>
        <span class="small text-muted text-nowrap"><i class="bi bi-hourglass-bottom me-1" aria-hidden="true"></i>Date passed</span>
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
                        onclick="openFacilityHistory(<?php echo $rid; ?>, <?php echo $eventJs; ?>)">
                <i class="bi bi-clock-history me-2" aria-hidden="true"></i>History</button></li>
            <?php if ($canEdit): ?>
            <li><button type="button" class="dropdown-item" onclick="openEditRequest(<?php echo $rid; ?>)">
                <i class="bi bi-pencil-square me-2" aria-hidden="true"></i><?php echo $status === 'for_clarification' ? 'Edit &amp; resubmit' : 'Edit request'; ?></button></li>
            <?php endif; ?>
            <?php if ($canBookAgain): ?>
            <li><button type="button" class="dropdown-item" onclick="openBookAgain(<?php echo $rid; ?>)">
                <i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Book again</button></li>
            <?php endif; ?>
            <?php if ($canCancel || $canDelete): ?>
            <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <?php if ($canCancel): ?>
            <li><button type="button" class="dropdown-item text-danger"
                        onclick='openCancelRequest(<?php echo $facilityPayload; ?>)'>
                <i class="bi bi-x-circle me-2" aria-hidden="true"></i>Cancel request</button></li>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <li><button type="button" class="dropdown-item text-danger"
                        onclick='openDeleteRequest(<?php echo $facilityPayload; ?>)'>
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete (mistake / duplicate)</button></li>
            <?php endif; ?>
        </ul>
    </div>
</div>
