<?php
/**
 * Member profile: manage this person's groups in place.
 * Add to a group / Change role / Remove from group, all posting to
 * memberGroupSave (MemberController) which uses VictoryGroup's
 * addMemberToGroup / changeMemberRole / removeMemberFromGroup.
 *
 * Requires in scope: $member, $memberGroups, $groupOptions.
 */
require_once 'models/VictoryGroup.php';
$pgKinds = ['victory_group' => 'Victory Groups', 'leadership_group' => 'Leadership Groups', 'campus' => 'Campus'];
$pgRoleNames = ['leader' => 'Leader', 'intern' => 'Intern', 'attendee' => 'Member', 'lg_leader' => 'LG Leader'];
$pgMid = (int)$member['id'];
// One <option> per group, grouped by kind; data-kind drives which roles are offered.
$pgByKind = [];
foreach (($groupOptions ?? []) as $g) $pgByKind[$g['group_kind']][] = $g;
$pgRolesJs = [];
foreach (array_keys($pgKinds) as $k) $pgRolesJs[$k] = VictoryGroup::rolesForKind($k);
?>
<div class="border-top px-3 py-2" id="profileGroups">
    <?php foreach ($memberGroups as $grp):
        $pgRoles = VictoryGroup::rolesForKind($grp['group_kind']);
        $pgCouple = !empty($grp['is_couple']);
    ?>
    <div class="d-flex flex-wrap align-items-center gap-1 py-1 small">
        <span class="me-auto text-truncate" style="max-width:55%" title="<?php echo htmlspecialchars($grp['group_name'] ?? ''); ?>">
            <?php echo htmlspecialchars($grp['group_name'] ?: 'Group'); ?>
        </span>
        <form method="POST" action="index.php?action=memberGroupSave" class="d-inline-flex gap-1">
            <input type="hidden" name="op" value="role">
            <input type="hidden" name="member_id" value="<?php echo $pgMid; ?>">
            <input type="hidden" name="vg_member_id" value="<?php echo (int)$grp['vg_member_id']; ?>">
            <select name="role" class="form-select form-select-sm py-0" style="width:auto" aria-label="Role in this group"
                    onchange="this.form.submit()" <?php echo $pgCouple ? 'title="Changes the role for both people on this couple line"' : ''; ?>>
                <?php foreach ($pgRoles as $rk => $rl): ?>
                <option value="<?php echo $rk; ?>" <?php echo $rk === $grp['role'] ? 'selected' : ''; ?>><?php echo $rl; ?></option>
                <?php endforeach; ?>
                <?php if (!isset($pgRoles[$grp['role']])): ?>
                <option value="<?php echo htmlspecialchars($grp['role']); ?>" selected><?php echo htmlspecialchars($pgRoleNames[$grp['role']] ?? $grp['role']); ?></option>
                <?php endif; ?>
            </select>
            <noscript><button class="btn btn-sm btn-outline-primary py-0">Change role</button></noscript>
        </form>
        <?php if (!$pgCouple || canAdminister()): ?>
        <form method="POST" action="index.php?action=memberGroupSave" class="d-inline">
            <input type="hidden" name="op" value="remove">
            <input type="hidden" name="member_id" value="<?php echo $pgMid; ?>">
            <input type="hidden" name="vg_member_id" value="<?php echo (int)$grp['vg_member_id']; ?>">
            <button type="submit" class="btn btn-sm btn-link text-danger p-0 ms-1" title="Remove from group"
                    data-confirm="<?php echo htmlspecialchars($pgCouple
                        ? 'Take ' . $member['full_name'] . ' off the couple line "' . $grp['display_name'] . '"? The other person stays on the group.'
                        : 'Remove ' . $member['full_name'] . ' from ' . ($grp['group_name'] ?: 'this group') . '?'); ?>">
                <i class="bi bi-x-circle"></i><span class="visually-hidden">Remove from group</span>
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($pgByKind)): ?>
    <form method="POST" action="index.php?action=memberGroupSave" class="row g-1 align-items-end pt-2" id="pgAddForm">
        <input type="hidden" name="op" value="add">
        <input type="hidden" name="member_id" value="<?php echo $pgMid; ?>">
        <div class="col-12 small fw-semibold">Add to a group</div>
        <div class="col-12">
            <select name="group_id" id="pgGroup" class="form-select form-select-sm" required aria-label="Group">
                <option value="">Choose a group…</option>
                <?php foreach ($pgKinds as $k => $kl): if (empty($pgByKind[$k])) continue; ?>
                <optgroup label="<?php echo $kl; ?>">
                    <?php foreach ($pgByKind[$k] as $g): ?>
                    <option value="<?php echo (int)$g['id']; ?>" data-kind="<?php echo $k; ?>"><?php echo htmlspecialchars($g['name'] ?: ('Group ' . $g['id'])); ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-8">
            <select name="role" id="pgRole" class="form-select form-select-sm" required aria-label="Role">
                <option value="attendee">Member</option>
            </select>
        </div>
        <div class="col-4"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
    </form>
    <script>
    // The role list follows the chosen group's kind (LG: LG Leader / Member).
    (function () {
        var roles = <?php echo json_encode($pgRolesJs); ?>;
        var g = document.getElementById('pgGroup'), r = document.getElementById('pgRole');
        if (!g || !r) return;
        g.addEventListener('change', function () {
            var opt = g.options[g.selectedIndex], kind = opt ? opt.getAttribute('data-kind') : '';
            var list = roles[kind] || roles.victory_group, keep = r.value;
            r.innerHTML = '';
            Object.keys(list).forEach(function (k) { r.add(new Option(list[k], k)); });
            r.value = list[keep] ? keep : 'attendee';
        });
    })();
    </script>
    <?php endif; ?>
</div>
