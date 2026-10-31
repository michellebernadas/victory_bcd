<?php
/**
 * Approvers panel — who holds each workflow stage.
 *
 * Facilities Personnel: stages 2 + 4. Minister: stage 3. Admins may override
 * any stage. A stage with nobody assigned is called out, with a link to Users
 * for admins only (canAdminister()).
 */
$approvers = $approvers ?? [];
$facAccts  = $approvers[ROLE_FACILITIES] ?? [];
$minAccts  = $approvers[ROLE_MINISTER]   ?? [];
$admAccts  = $approvers[ROLE_ADMIN]      ?? [];
$canUsers  = canAdminister();
$names = function (array $rows) {
    return implode(', ', array_map(function ($a) { return htmlspecialchars($a['username']); }, $rows));
};
$cols = [
    ['title' => 'Facilities Personnel', 'stages' => 'Stage 2 Review · Stage 4 Confirmation', 'icon' => 'bi-tools',
     'rows' => $facAccts, 'none' => 'No Facilities Personnel account — only Admins can review and confirm.'],
    ['title' => 'Minister', 'stages' => 'Stage 3 Approval', 'icon' => 'bi-person-check',
     'rows' => $minAccts, 'none' => 'No Minister account — only Admins can approve.'],
];
?>
<div class="card mb-3" id="fbApproversPanel">
    <div class="card-header py-2">
        <span class="text-white fw-semibold"><i class="bi bi-people me-2" aria-hidden="true"></i>Approvers</span>
    </div>
    <div class="card-body py-2">
        <div class="row g-2 align-items-start">
            <?php foreach ($cols as $c): ?>
            <div class="col-12 col-md-4">
                <div class="small fw-semibold"><i class="bi <?php echo $c['icon']; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($c['title']); ?></div>
                <div class="text-muted" style="font-size:.7rem"><?php echo htmlspecialchars($c['stages']); ?></div>
                <?php if ($c['rows']): ?>
                    <div class="small"><?php echo $names($c['rows']); ?></div>
                <?php else: ?>
                    <div class="small text-danger fw-semibold">
                        <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><?php echo htmlspecialchars($c['none']); ?>
                        <?php if ($canUsers): ?><a href="index.php?action=users" class="ms-1">Add one in Users</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <div class="col-12 col-md-4">
                <div class="small fw-semibold"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Admin override</div>
                <div class="text-muted" style="font-size:.7rem">Any stage, recorded as an override in history</div>
                <div class="small"><?php echo $admAccts ? $names($admAccts) : '<span class="text-muted">None</span>'; ?></div>
            </div>
        </div>
    </div>
</div>
