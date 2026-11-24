<?php
/** Public: Victory Groups and Campus groups an admin chose to publish. Read-only, no login. */
define('VB_PUBLIC', 1);
require __DIR__ . '/_public/bootstrap.php';

$groups = pub_groups($db);

// Filter choices come from the published data itself.
$days = [];
foreach ($groups as $g) {
    foreach (array_filter(array_map('trim', explode(',', (string)$g['day_of_week']))) as $d) $days[$d] = true;
}
$order = ['Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
uksort($days, function ($a, $b) use ($order) { return ($order[$a] ?? 9) <=> ($order[$b] ?? 9); });
$nVg     = count(array_filter($groups, function ($g) { return $g['group_kind'] === 'victory_group'; }));
$nCampus = count($groups) - $nVg;

pub_header('Find a Victory Group', 'groups.php', 'Find a Victory Group or Campus group near you and tell us you are interested.');
?>
<header class="vp-hero" style="padding-bottom:4.2rem">
    <div class="container vp-fade">
        <span class="vp-eyebrow">Victory Groups</span>
        <h1 class="display-5">Find your people.</h1>
        <p class="lead mb-3">Life is better together. Find a group that fits your life and ask to be part of it.</p>
        <?php if ($groups): ?>
        <span class="vp-herostat"><i class="bi bi-people-fill" aria-hidden="true"></i><?php echo (int)$nVg; ?> Victory Groups</span>
        <?php if ($nCampus): ?><span class="vp-herostat"><i class="bi bi-building" aria-hidden="true"></i><?php echo (int)$nCampus; ?> Campus groups</span><?php endif; ?>
        <span class="vp-herostat"><i class="bi bi-calendar-week" aria-hidden="true"></i>Meeting <?php echo count($days); ?> day<?php echo count($days) === 1 ? '' : 's'; ?> a week</span>
        <?php endif; ?>
    </div>
</header>

<main class="container pb-4">
<?php if ($groups): ?>
    <div class="vp-card vp-filter mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-6 col-lg-2"><label for="fKind">Type</label>
                <select id="fKind" class="form-select"><option value="">All types</option>
                    <option value="victory_group">Victory Groups</option><option value="campus">Campus</option></select></div>
            <div class="col-6 col-lg-2"><label for="fDay">Day</label>
                <select id="fDay" class="form-select"><option value="">Any day</option>
                    <?php foreach (array_keys($days) as $d): ?><option><?php echo pub_e($d); ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-lg-3"><label for="fStage">I am&hellip;</label>
                <select id="fStage" class="form-select"><option value="">Any life stage</option>
                    <?php foreach (GroupMatcher::LIFE_STAGES as $k => $lbl): if ($k === 'other') continue; ?><option value="<?php echo pub_e($k); ?>"><?php echo pub_e($lbl); ?></option><?php endforeach; ?></select></div>
            <div class="col-6 col-lg-3"><label for="fGender">Group for</label>
                <select id="fGender" class="form-select"><option value="">Everyone</option>
                    <option value="woman">Women</option><option value="man">Men</option></select></div>
            <div class="col-12 col-lg-2 d-flex gap-2"><button type="button" id="fReset" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</button></div>
            <div class="col-12"><label for="fText" class="visually-hidden">Search</label>
                <div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-search" aria-hidden="true"></i></span>
                    <input id="fText" class="form-control" placeholder="Search by area, leader or group name, e.g. Mandalagan"></div></div>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3 small text-muted"><span id="fCount" aria-live="polite"></span>
        <a href="join.php" class="fw-semibold text-decoration-none">Not sure which one? Let us help <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>

    <div class="row g-3" id="groupList">
        <?php foreach ($groups as $g):
            $isCampus = $g['group_kind'] === 'campus';
            $title = pub_group_title($g);
            $when  = pub_when($g);
            $hay   = strtolower(implode(' ', [$title, $g['location'], implode(' ', $g['leaders']), $g['group_type'], $g['group_category'], $g['audience']]));
        ?>
        <div class="col-md-6 col-xl-4 vp-group vp-fade" data-kind="<?php echo pub_e($g['group_kind']); ?>"
             data-days="<?php echo pub_e($g['day_of_week']); ?>" data-hay="<?php echo pub_e($hay); ?>"
             data-stages="<?php echo pub_e(implode(',', $g['stages'])); ?>" data-gender="<?php echo pub_e($g['gender']); ?>">
            <article class="vp-card vp-lift vp-group-card <?php echo $isCampus ? 'campus' : ''; ?>">
                <div class="vp-group-body">
                    <div>
                        <span class="vp-chip <?php echo $isCampus ? 'campus' : ''; ?>"><?php echo $isCampus ? 'Campus' : 'Victory Group'; ?></span>
                        <?php if (trim((string)$g['group_category']) !== ''): ?><span class="vp-chip soft"><?php echo pub_e($g['group_category']); ?></span><?php endif; ?>
                    </div>
                    <h2 class="vp-group-title"><?php echo pub_e($title); ?></h2>
                    <?php if ($when !== ''): ?>
                    <div class="vp-row"><i class="bi bi-calendar-event" aria-hidden="true"></i><span><?php echo pub_e($when); ?>
                        <?php if ($g['meeting_frequency'] && $g['meeting_frequency'] !== 'weekly'): ?><span class="text-muted">&middot; <?php echo pub_e($g['meeting_frequency']); ?></span><?php endif; ?></span></div>
                    <?php endif; ?>
                    <?php if (trim((string)$g['location']) !== ''): ?><div class="vp-row"><i class="bi bi-geo-alt" aria-hidden="true"></i><span><?php echo pub_e($g['location']); ?></span></div><?php endif; ?>
                    <?php if ($g['leaders']): ?>
                    <div class="vp-leaders"><span class="vp-avatar" aria-hidden="true"><?php echo pub_e(pub_initials($g['leaders'][0])); ?></span>
                        <div><small>Led by</small><?php echo pub_e(implode(', ', $g['leaders'])); ?></div></div>
                    <?php endif; ?>
                    <a class="btn vp-cta mt-3" href="join.php?group=<?php echo (int)$g['id']; ?>"><i class="bi bi-heart me-1" aria-hidden="true"></i>Get Connected</a>
                </div>
            </article>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="vp-card p-5 text-center text-muted d-none mt-3" id="noMatch"><i class="bi bi-search display-5 d-block mb-2"></i>
        <div class="fw-semibold text-dark mb-1">No groups match those filters.</div>
        Try another day or life stage, or <a href="join.php">tell us about you</a> and we will help you find one.</div>
<?php else: ?>
    <div class="vp-card p-5 text-center text-muted mt-4"><i class="bi bi-people display-5 d-block mb-2"></i>Our groups will be listed here soon.
        <div class="mt-2"><a href="join.php">Tell us you are interested</a> and we will get in touch.</div></div>
<?php endif; ?>
</main>
<?php
pub_footer($db, <<<'JS'
(function () {
  var $k = $('#fKind'), $d = $('#fDay'), $s = $('#fStage'), $g = $('#fGender'), $t = $('#fText');
  if (!$k.length) return;
  $('#fKind, #fDay, #fStage, #fGender').select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: Infinity });
  var $cards = $('.vp-group');
  function apply() {
    var kind = $k.val(), day = $d.val(), stage = $s.val(), gender = $g.val(), txt = $.trim($t.val()).toLowerCase(), shown = 0;
    $cards.each(function () {
      var c = $(this), stages = (c.data('stages') + '').split(','), gg = c.data('gender') + '';
      var ok = (!kind || c.data('kind') === kind)
            && (!day || (c.data('days') + '').indexOf(day) !== -1)
            && (!stage || stages.indexOf(stage) !== -1)
            && (!gender || gg === 'any' || (gender === 'man' && gg === 'M') || (gender === 'woman' && gg === 'F'))
            && (!txt || (c.data('hay') + '').indexOf(txt) !== -1);
      c.toggleClass('d-none', !ok); if (ok) shown++;
    });
    $('#fCount').text(shown === $cards.length ? 'Showing all ' + shown + ' groups' : 'Showing ' + shown + ' of ' + $cards.length + ' groups');
    $('#noMatch').toggleClass('d-none', shown > 0);
  }
  $('#fKind, #fDay, #fStage, #fGender').on('change', apply);
  $t.on('input', apply);
  $('#fReset').on('click', function () { $('#fKind, #fDay, #fStage, #fGender').val('').trigger('change.select2').trigger('change'); $t.val(''); apply(); });
  apply();
})();
JS);
