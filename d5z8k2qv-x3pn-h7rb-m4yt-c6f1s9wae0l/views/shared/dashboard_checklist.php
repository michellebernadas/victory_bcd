<?php
// Dashboard — "Getting started with 2026" (admin only).
//
// Every step is computed live from the database on each load; nothing about
// progress is stored. Each query is isolated in its own try/catch so a table or
// enum value that does not exist yet (e.g. 'minister' on an older database)
// reads as "not done" instead of breaking the dashboard.
//
// Requires in scope: $db (PDO), $groupModel (VictoryGroup).
if (!canAdminister()) return;

$gsCount = function (string $sql, array $params = []) use ($db): int {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
};
$gsMembers   = $gsCount("SELECT COUNT(*) FROM members WHERE is_deleted = 0");
$gsGroups    = $gsCount("SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0");
$gsUnlinked  = $groupModel->countUnresolvedSlots();
$gsFacility  = $gsCount("SELECT COUNT(*) FROM accounts WHERE accounttype = 'facilities' AND accountstatus = 'active'");
$gsMinister  = $gsCount("SELECT COUNT(*) FROM accounts WHERE accounttype = 'minister' AND accountstatus = 'active'");
$gsSundays   = $gsCount("SELECT COUNT(*) FROM service_attendances");
$gsServe     = $gsCount("SELECT COUNT(*) FROM serve_teams WHERE is_deleted = 0");

$gsSteps = [
    ['done' => $gsMembers > 0, 'label' => 'Import 2026 members',
     'detail' => $gsMembers > 0 ? number_format($gsMembers) . ' members on record' : 'Upload the 2026 member list',
     'href' => 'index.php?action=memberImport', 'cta' => 'Import members'],
    ['done' => $gsGroups > 0, 'label' => 'Import the Staff VG Schedule',
     'detail' => $gsGroups > 0 ? number_format($gsGroups) . ' groups on record' : 'Victory Groups, Leadership Groups and Campus',
     'href' => 'index.php?action=groupImport', 'cta' => 'Import schedule'],
    // Linking only means something once there is a schedule to link.
    ['done' => $gsGroups > 0 && $gsUnlinked === 0, 'label' => 'Connect people on the groups to their profiles',
     'detail' => $gsGroups === 0 ? 'After the schedule is imported'
               : ($gsUnlinked > 0 ? number_format($gsUnlinked) . ' not connected yet' : 'Everyone is connected'),
     'href' => 'index.php?action=needsLink', 'cta' => 'Connect people'],
    ['done' => $gsFacility > 0, 'label' => 'Add a Facilities Personnel account',
     'detail' => 'Reviews and confirms facility requests',
     'href' => 'index.php?action=users', 'cta' => 'Open Users'],
    ['done' => $gsMinister > 0, 'label' => 'Add a Minister account',
     'detail' => 'Gives the Minister approval on facility requests',
     'href' => 'index.php?action=users', 'cta' => 'Open Users'],
    ['done' => $gsSundays > 0, 'label' => 'Record the first Sunday attendance',
     'detail' => 'Sunday Service and Kids Church counts per service time feed Church Health',
     'href' => 'index.php?action=serviceAttendance&enterDay=1', 'cta' => 'Enter a Sunday'],
    ['done' => $gsServe > 0, 'label' => 'Set up Serve Teams',
     'detail' => $gsServe > 0 ? number_format($gsServe) . ($gsServe === 1 ? ' team' : ' teams') : 'Who serves where',
     'href' => 'index.php?action=serveTeams', 'cta' => 'Open Serve Teams'],
];
$gsDone  = count(array_filter($gsSteps, fn($s) => $s['done']));
$gsTotal = count($gsSteps);
$gsNext  = null;
foreach ($gsSteps as $i => $s) { if (!$s['done']) { $gsNext = $i; break; } }

// All done: the card disappears on its own, and so does the "show it" link.
$gsShowChecklist = $gsDone < $gsTotal;
if (!$gsShowChecklist) return;
?>
<div class="card mb-4 border-primary border-2" id="gettingStartedCard">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h6 class="mb-0"><i class="bi bi-flag me-2"></i>Getting started with 2026</h6>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-white text-primary"><?php echo $gsDone; ?> of <?php echo $gsTotal; ?> done</span>
            <button type="button" class="btn btn-sm btn-outline-light" id="gsHideBtn" title="Hide on this browser. Bring it back from Quick Actions.">
                <i class="bi bi-eye-slash me-1"></i>Hide checklist
            </button>
        </div>
    </div>
    <div class="card-body py-2">
        <div class="progress mb-2" style="height:6px" role="progressbar" aria-label="Getting started progress"
             aria-valuenow="<?php echo $gsDone; ?>" aria-valuemin="0" aria-valuemax="<?php echo $gsTotal; ?>">
            <div class="progress-bar bg-success" style="width:<?php echo (int)round($gsDone / $gsTotal * 100); ?>%"></div>
        </div>
        <ol class="list-unstyled mb-0 gs-steps">
            <?php foreach ($gsSteps as $i => $s): $isNext = ($i === $gsNext); ?>
            <li class="d-flex align-items-center gap-2 py-1 px-2 rounded<?php echo $isNext ? ' gs-next' : ''; ?><?php echo $s['done'] ? ' text-muted' : ''; ?>">
                <?php if ($s['done']): ?>
                <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
                <?php else: ?>
                <span class="gs-num<?php echo $isNext ? ' bg-primary text-white' : ''; ?>" aria-hidden="true"><?php echo $i + 1; ?></span>
                <?php endif; ?>
                <div class="flex-grow-1">
                    <span class="<?php echo $s['done'] ? 'text-decoration-line-through' : 'fw-semibold'; ?>"><?php echo htmlspecialchars($s['label']); ?></span>
                    <span class="visually-hidden"><?php echo $s['done'] ? '(done)' : '(to do)'; ?></span>
                    <span class="small text-muted ms-1">&middot; <?php echo htmlspecialchars($s['detail']); ?></span>
                </div>
                <?php if (!$s['done']): ?>
                <a href="<?php echo htmlspecialchars($s['href']); ?>" class="btn btn-sm <?php echo $isNext ? 'btn-primary' : 'btn-outline-primary'; ?> text-nowrap">
                    <?php echo htmlspecialchars($s['cta']); ?> <i class="bi bi-arrow-right-short"></i>
                </a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</div>
<style>
    .gs-steps .gs-next { background: rgba(13,110,253,.08); }
    .gs-num { display:inline-flex; align-items:center; justify-content:center; width:1.3rem; height:1.3rem;
              border-radius:50%; font-size:.75rem; font-weight:700; border:1px solid #adb5bd; flex-shrink:0; }
</style>
<script>
// Runs as soon as the card is parsed, so a hidden checklist never flashes.
(function () {
    var KEY = 'vb.gettingStarted.hidden';
    var card = document.getElementById('gettingStartedCard');
    var hidden = false;
    try { hidden = localStorage.getItem(KEY) === '1'; } catch (e) {}
    if (hidden) card.classList.add('d-none');
    document.getElementById('gsHideBtn').addEventListener('click', function () {
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
        card.classList.add('d-none');
        var show = document.getElementById('gsShowLink');
        if (show) show.classList.remove('d-none');
    });
    window.gsShowChecklist = function () {
        try { localStorage.removeItem(KEY); } catch (e) {}
        card.classList.remove('d-none');
        var show = document.getElementById('gsShowLink');
        if (show) show.classList.add('d-none');
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
})();
</script>
