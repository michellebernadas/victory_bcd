<?php
/**
 * Member profile: this person's Serve Teams, managed in place.
 * Change role / Remove / Add to a team post to memberTeamSave, which uses
 * ServeTeam::setMemberOnTeam / removeMemberFromTeam (serve_team_members is
 * still the single source of truth; the Serve Teams page shows the same rows).
 *
 * Requires in scope: $member, $serveTeams, $teamOptions.
 */
require_once 'models/ServeTeam.php';
$ptMid    = (int)$member['id'];
$ptOnIds  = array_flip(array_map('intval', array_column($serveTeams, 'id')));
$ptCanAdd = array_filter($teamOptions ?? [], fn($t) => !isset($ptOnIds[(int)$t['id']]));
?>
<div class="card-body py-2" id="profileTeams">
    <?php if (empty($serveTeams)): ?>
    <div class="text-muted small text-center py-2">
        <i class="bi bi-hand-thumbs-up fs-3 d-block mb-1 opacity-25"></i>Not on any serve team yet.
    </div>
    <?php endif; ?>
    <?php foreach ($serveTeams as $st): ?>
    <div class="d-flex align-items-center gap-1 border-bottom py-2">
        <div class="me-auto">
            <div class="small fw-semibold"><?php echo htmlspecialchars($st['name']); ?></div>
            <div class="text-muted" style="font-size:10px;">
                <?php echo $st['ministry'] ? htmlspecialchars($st['ministry']) : 'No ministry'; ?>
                <?php if ($st['service_time']): ?> · <?php echo htmlspecialchars($st['service_time']); ?><?php endif; ?>
            </div>
        </div>
        <form method="POST" action="index.php?action=memberTeamSave" class="d-inline">
            <input type="hidden" name="op" value="role">
            <input type="hidden" name="member_id" value="<?php echo $ptMid; ?>">
            <input type="hidden" name="team_id" value="<?php echo (int)$st['id']; ?>">
            <select name="role" class="form-select form-select-sm py-0" style="width:auto" aria-label="Role on this team" onchange="this.form.submit()">
                <?php foreach (ServeTeam::ROLES as $rk => $rl): ?>
                <option value="<?php echo $rk; ?>" <?php echo $rk === $st['role'] ? 'selected' : ''; ?>><?php echo $rl; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <form method="POST" action="index.php?action=memberTeamSave" class="d-inline">
            <input type="hidden" name="op" value="remove">
            <input type="hidden" name="member_id" value="<?php echo $ptMid; ?>">
            <input type="hidden" name="team_id" value="<?php echo (int)$st['id']; ?>">
            <button type="submit" class="btn btn-sm btn-link text-danger p-0 ms-1" title="Remove from team"
                    data-confirm="<?php echo htmlspecialchars('Remove ' . $member['full_name'] . ' from ' . $st['name'] . '?'); ?>">
                <i class="bi bi-x-circle"></i><span class="visually-hidden">Remove from team</span>
            </button>
        </form>
    </div>
    <?php endforeach; ?>

    <?php if ($ptCanAdd): ?>
    <form method="POST" action="index.php?action=memberTeamSave" class="row g-1 align-items-end pt-2">
        <input type="hidden" name="op" value="add">
        <input type="hidden" name="member_id" value="<?php echo $ptMid; ?>">
        <div class="col-12 small fw-semibold">Add to a team</div>
        <div class="col-12">
            <select name="team_id" class="form-select form-select-sm" required aria-label="Team">
                <option value="">Choose a team…</option>
                <?php foreach ($ptCanAdd as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-8">
            <select name="role" class="form-select form-select-sm" aria-label="Role">
                <option value="member">Member</option>
                <option value="leader">Leader</option>
            </select>
        </div>
        <div class="col-4"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>Add</button></div>
    </form>
    <?php elseif (empty($teamOptions)): ?>
    <div class="small text-muted pt-2">No active serve teams yet. <a href="index.php?action=serveTeams">Set up Serve Teams</a></div>
    <?php endif; ?>
</div>
