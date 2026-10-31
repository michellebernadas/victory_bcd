<?php
/**
 * "Waiting on me" tab — the requests sitting at the signed-in account's stage.
 *
 * Which statuses count is decided by facilityWaitingStatuses() in
 * config/permissions.php (Facilities: stage 2 + 4, Minister: stage 3, Admin:
 * all approver desks, Requestor: requests returned for clarification). The
 * same function drives the menu badge, so the two can never disagree.
 */
$waitingRequests = $waitingRequests ?? [];
$waitingStatuses = $waitingStatuses ?? [];
$stats           = $stats           ?? [];
$activeTab       = $activeTab       ?? 'calendar';
$currentUserType = $_SESSION['user']['accounttype'] ?? null;

$stageText = [];
if (canFacilitiesReview($currentUserType))  $stageText[] = 'Facilities Review (stage 2)';
if (canMinisterApprove($currentUserType))   $stageText[] = 'Minister Approval (stage 3)' . (ministerActsAsOverride($currentUserType) ? ' as an Admin override' : '');
if (canFacilitiesConfirm($currentUserType)) $stageText[] = 'Facilities Confirmation (stage 4)';
?>
<div class="<?php echo $activeTab === 'waiting' ? '' : 'd-none'; ?>" id="facilityWaitingTab">
    <div class="alert alert-light border small py-2 d-flex gap-2 align-items-start">
        <i class="bi bi-person-check text-purple mt-1" aria-hidden="true"></i>
        <div>
            <?php if ($stageText): ?>
                Requests waiting for you to act on: <strong><?php echo htmlspecialchars(implode(', ', $stageText)); ?></strong>.
                <?php if (!empty($stats['waiting_requestor'])): ?>
                <br><i class="bi bi-question-circle text-warning me-1" aria-hidden="true"></i>
                <?php echo (int)$stats['waiting_requestor']; ?> request(s) are waiting on the requestor for clarification —
                <a href="index.php?action=facilityCalendar&amp;tab=requests&amp;status=for_clarification">view them</a>.
                <?php endif; ?>
            <?php else: ?>
                Requests that were <strong>returned to you for clarification</strong>. Contact Facilities Personnel
                or the Minister with the details they asked for (see the request's history for their comments).
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <?php
            $tableRows  = $waitingRequests;
            $tableId    = 'facilityWaitingTable';
            $tableEmpty = ['icon' => 'bi-check2-all', 'text' => 'Nothing is waiting on you right now.', 'cta' => false];
            include __DIR__ . '/facility_requests_table.php';
            ?>
        </div>
    </div>
</div>
