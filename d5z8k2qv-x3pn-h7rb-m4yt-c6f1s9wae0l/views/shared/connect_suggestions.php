<?php
/**
 * "Is this the Joshua Demetria in Single Men VG — Saturday? [Connect]"
 *
 * Member-side suggestions for names on the group schedules that are not
 * connected to a member profile yet. Built by
 * VictoryGroup::suggestConnectionsForMembers() (exact / near name matches
 * only). Nothing connects on its own: each Connect is one POST to the same
 * linkVgMember route and VictoryGroup::linkVgMember() the group pages use.
 *
 * Requires in scope:
 *   $connectSuggestions  array from suggestConnectionsForMembers()
 *   $connectReturn       ['return_to' => 'profile'|'members'|'memberImport', 'batch' => '...'] (optional)
 *   $connectMax          int, how many to show before "see all" (optional, default 8)
 */
require_once 'models/LinkSuggester.php';
$connectSuggestions = $connectSuggestions ?? [];
$connectReturn      = $connectReturn ?? ['return_to' => 'members'];
$connectMax         = $connectMax ?? 8;
if (!$connectSuggestions || !function_exists('canAdminister') || !canAdminister()) return;

// One line per (name on a group, member). A couple offers one button per half.
$csLines = [];
foreach ($connectSuggestions as $s) {
    $k = (int)$s['vg_member_id'] . '|' . (int)$s['suggest_id'];
    if (!isset($csLines[$k])) $csLines[$k] = $s + ['slots' => []];
    $csLines[$k]['slots'][] = (int)$s['slot'];
}
$csRoleLabel = ['leader' => 'Leader', 'intern' => 'Intern', 'attendee' => 'Member', 'lg_leader' => 'LG Leader'];
$csShown = array_slice($csLines, 0, $connectMax);
?>
<div class="card mb-4 border-primary border-opacity-50" id="connectSuggestions">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="bi bi-person-check me-2"></i>Is this the same person?</h6>
        <span class="badge bg-light text-dark"><?php echo count($csLines); ?></span>
    </div>
    <div class="card-body py-2">
        <p class="small text-muted mb-2">
            These names on the group schedules aren't connected to a member profile yet.
            Connect them so the group shows on their profile and they're counted.
        </p>
        <ul class="list-unstyled mb-0">
        <?php foreach ($csShown as $s):
            $csCouple = !empty($s['is_couple']);
            $csHalves = $csCouple ? LinkSuggester::coupleHalves((string)$s['written_name']) : [];
            $csGroup  = trim((string)($s['group_name'] ?? '')) ?: 'a group';
        ?>
            <li class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom">
                <span class="me-auto">
                    <?php if ($csCouple): ?>
                    Is <strong><?php echo htmlspecialchars($s['suggest_name']); ?></strong> one of
                    &ldquo;<?php echo htmlspecialchars($s['written_name']); ?>&rdquo; in
                    <em><?php echo htmlspecialchars($csGroup); ?></em>?
                    <?php else: ?>
                    Is this the <strong><?php echo htmlspecialchars($s['written_name']); ?></strong> in
                    <em><?php echo htmlspecialchars($csGroup); ?></em>?
                    <?php if (LinkSuggester::norm((string)$s['written_name']) !== LinkSuggester::norm((string)$s['suggest_name'])): ?>
                    <span class="small text-muted">(profile: <?php echo htmlspecialchars($s['suggest_name']); ?>)</span>
                    <?php endif; ?>
                    <?php endif; ?>
                    <span class="badge bg-light text-secondary border ms-1"><?php echo htmlspecialchars($csRoleLabel[$s['role']] ?? $s['role']); ?></span>
                </span>
                <?php
                // A couple can be either half; the half the name matched comes first.
                $csSlots = $csCouple ? array_values(array_unique(array_merge($s['slots'], [1, 2]))) : [1];
                foreach ($csSlots as $csSlot):
                    $csHalf = $csCouple ? trim((string)($csHalves[$csSlot][0] ?? '')) : '';
                ?>
                <form method="POST" action="index.php?action=linkVgMember" class="d-inline">
                    <input type="hidden" name="vg_member_id" value="<?php echo (int)$s['vg_member_id']; ?>">
                    <input type="hidden" name="slot" value="<?php echo (int)$csSlot; ?>">
                    <input type="hidden" name="member_id" value="<?php echo (int)$s['suggest_id']; ?>">
                    <?php foreach ($connectReturn as $rk => $rv): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($rk); ?>" value="<?php echo htmlspecialchars((string)$rv); ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-sm <?php echo in_array($csSlot, $s['slots'], true) ? 'btn-primary' : 'btn-outline-primary'; ?>">
                        <i class="bi bi-person-check me-1"></i>Connect<?php echo $csCouple ? ' as ' . htmlspecialchars(ucwords($csHalf !== '' ? $csHalf : 'person ' . $csSlot)) : ''; ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if (count($csLines) > $connectMax): ?>
        <div class="small mt-2">
            <?php echo count($csLines) - $connectMax; ?> more.
            <a href="index.php?action=needsLink">See them all on Connect people to profiles</a>
        </div>
        <?php endif; ?>
    </div>
</div>
