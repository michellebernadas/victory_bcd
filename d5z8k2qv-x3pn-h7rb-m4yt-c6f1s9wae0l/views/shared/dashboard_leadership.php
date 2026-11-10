<?php
// Dashboard — Leadership.
//
// Plain ratios of people to leaders, from Victory Groups + Leadership Groups
// (ProgramAttendance::getChurchHealthStats). It used to be titled "Church
// Health — Attendance per Leader" and sat with the Sunday figures, which read
// like a VG / LG / Campus attendance comparison; it is not one. "Class
// attendance records per leader" was dropped: it could not be said in one
// plain sentence. Leaders count only once connected to a member profile, so
// before that the ratios are one friendly line, not a row of zeros.
//
// Requires in scope: $churchHealth, $groupStats
$ldCh      = $churchHealth;
$ldLeaders = (int)$ldCh['leaders'];
$ldCards = [
    ['label' => 'Group members per leader', 'value' => $ldCh['attendees_per_leader'], 'icon' => 'bi-people', 'color' => 'info',
     'why' => 'People attending a Victory Group or Leadership Group, for every leader.'],
    ['label' => 'Groups per leader', 'value' => $ldCh['groups_per_leader'], 'icon' => 'bi-diagram-3', 'color' => 'secondary',
     'why' => 'Active groups for every leader. Above 1 means some leaders lead more than one group.'],
    ['label' => 'Active members per leader', 'value' => $ldCh['members_per_leader'], 'icon' => 'bi-person-check', 'color' => 'warning',
     'why' => 'Active church members for every leader.'],
];
$ldToLink = (int)(($groupStats['vg_leaders_to_link'] ?? 0) + ($groupStats['lg_leaders_to_link'] ?? 0));
?>
<div class="card mb-4" id="leadershipRatios">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-star me-2"></i>Leadership</h6>
        <a href="index.php?action=groupLeaders" class="btn btn-sm btn-outline-light"><i class="bi bi-person-badge me-1"></i>View leaders</a>
    </div>
    <div class="card-body">
        <?php if ($ldLeaders === 0): ?>
        <div class="text-muted small">
            <i class="bi bi-person-plus me-1"></i>
            Connect leaders to their member profiles to see these numbers<?php if ($ldToLink > 0): ?>
            (<?php echo $ldToLink; ?> leader<?php echo $ldToLink === 1 ? ' is' : 's are'; ?> waiting)<?php endif; ?>.
            <?php if ($ldToLink > 0 && function_exists('canAdminister') && canAdminister()): ?>
            <a href="index.php?action=needsLink">Connect people</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <p class="small text-muted mb-3">
            <i class="bi bi-info-circle me-1"></i>
            How many people each leader is looking after, based on the
            <strong><?php echo $ldLeaders; ?></strong> Victory Group and Leadership Group leader<?php echo $ldLeaders === 1 ? '' : 's'; ?>
            connected to a member profile<?php echo (int)$ldCh['interns'] ? ' (plus ' . (int)$ldCh['interns'] . ' intern' . ((int)$ldCh['interns'] === 1 ? '' : 's') . ' in training)' : ''; ?>.
        </p>
        <div class="row g-2">
            <?php foreach ($ldCards as $ldCard): ?>
            <div class="col-md-4">
                <div class="border rounded p-2 h-100 text-center">
                    <i class="bi <?php echo $ldCard['icon']; ?> text-<?php echo $ldCard['color']; ?>"></i>
                    <div class="h5 fw-bold mb-0"><?php echo htmlspecialchars((string)$ldCard['value']); ?></div>
                    <div class="small fw-semibold"><?php echo htmlspecialchars($ldCard['label']); ?></div>
                    <div class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($ldCard['why']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
