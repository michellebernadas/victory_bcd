<?php
/**
 * "Waiting on me" — the explanation shown above the Waiting-on-me list (one of
 * the Requests view's list tabs; the list itself is the shared table).
 *
 * Which statuses count is decided by facilityWaitingStatuses() and whose by
 * facilityWaitingOwnerScope(), both in config/permissions.php (Facilities:
 * stage 2 + 4, Minister: stage 3, Admin: all approver desks, Requestor: their
 * OWN requests returned for clarification). Deleted and expired requests are
 * never on anybody's desk. The same functions drive the menu badge, so the two
 * can never disagree.
 */
$stats           = $stats ?? [];
$currentUserType = $_SESSION['user']['accounttype'] ?? null;

$stageText = [];
if (canFacilitiesReview($currentUserType))  $stageText[] = 'Facilities Review (stage 2)';
if (canMinisterApprove($currentUserType))   $stageText[] = 'Minister Approval (stage 3)' . (ministerActsAsOverride($currentUserType) ? ' as an Admin override' : '');
if (canFacilitiesConfirm($currentUserType)) $stageText[] = 'Facilities Confirmation (stage 4)';
?>
<div class="alert alert-light border small py-2 d-flex gap-2 align-items-start" id="facilityWaitingInfo">
    <i class="bi bi-person-check text-purple mt-1" aria-hidden="true"></i>
    <div>
        <?php if ($stageText): ?>
            Requests waiting for you to act on: <strong><?php echo htmlspecialchars(implode(', ', $stageText)); ?></strong>.
            Expired requests (date already passed) are not listed — find them under Past.
            <?php if (!empty($stats['waiting_requestor'])): ?>
            <br><i class="bi bi-question-circle text-warning me-1" aria-hidden="true"></i>
            <?php echo (int)$stats['waiting_requestor']; ?> request(s) are waiting on the requestor for clarification —
            <a href="index.php?action=facilityCalendar&amp;tab=requests&amp;list=active&amp;status=for_clarification">view them</a>.
            <?php endif; ?>
        <?php else: ?>
            Your requests that were <strong>returned to you for clarification</strong>. Open one and choose
            <strong>Edit &amp; resubmit</strong> to answer what was asked (see its history for the comments) —
            it then goes back to Facilities Personnel for review.
        <?php endif; ?>
    </div>
</div>
