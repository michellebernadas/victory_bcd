<?php
/**
 * Greeting banner — shared by the Dashboard and the welcome page (views/home.php),
 * so the two never drift apart. Styles: .hm-* in css/style.css.
 *
 * Optional inputs (set before including):
 *   $gbSubtitle  string   line under the greeting
 *   $gbStats     array    [['n'=>'1,204','l'=>'label','d'=>'detail','url'=>…], …]
 *                          "at a glance" tiles; each is only shown when passed,
 *                          so the caller decides what this role may see
 *   $gbActions   array    extra pills: [['url'=>…,'icon'=>'bi-…','label'=>…], …]
 *   $gbRefresh   bool     show a Refresh pill (the dashboard does)
 */
$gbUser  = $_SESSION['user']['username'] ?? 'there';
$gbRole  = accountTypeLabel($_SESSION['user']['accounttype'] ?? '');
$gbHour  = (int)date('G');
$gbHello = $gbHour < 12 ? 'Good morning' : ($gbHour < 18 ? 'Good afternoon' : 'Good evening');
$gbSubtitle = $gbSubtitle ?? 'Welcome to the Victory Bacolod admin portal.';
$gbStats    = $gbStats    ?? [];
$gbActions  = $gbActions  ?? [];
$gbRefresh  = !empty($gbRefresh);
?>
<div class="hm-hero mb-4">
    <div>
        <div class="hm-hello"><?php echo $gbHello; ?>, <?php echo htmlspecialchars($gbUser); ?></div>
        <div class="hm-sub"><?php echo htmlspecialchars($gbSubtitle); ?></div>
    </div>
    <div class="hm-meta db-noprint">
        <span class="hm-pill"><i class="bi bi-person-badge me-1" aria-hidden="true"></i><?php echo htmlspecialchars($gbRole); ?></span>
        <span class="hm-pill"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i><?php echo date('l, F j'); ?></span>
        <?php foreach ($gbActions as $a): ?>
        <a href="<?php echo htmlspecialchars($a['url']); ?>" class="hm-pill hm-pill-btn">
            <i class="bi <?php echo htmlspecialchars($a['icon']); ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($a['label']); ?>
        </a>
        <?php endforeach; ?>
        <?php if ($gbRefresh): ?>
        <a href="" class="hm-pill hm-pill-btn" onclick="location.reload(); return false;" title="Reload the latest figures">
            <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Refresh
        </a>
        <?php endif; ?>
    </div>
    <?php if ($gbStats): ?>
    <div class="hm-hero-stats" aria-label="At a glance">
        <?php foreach ($gbStats as $s): $tag = !empty($s['url']) ? 'a' : 'div'; ?>
        <<?php echo $tag; ?> class="hm-hero-stat"<?php if (!empty($s['url'])): ?> href="<?php echo htmlspecialchars($s['url']); ?>"<?php endif; ?>>
            <span class="n"><?php echo htmlspecialchars((string)$s['n']); ?></span>
            <span class="l"><?php echo htmlspecialchars($s['l']); ?></span>
            <?php if (!empty($s['d'])): ?><span class="d"><?php echo htmlspecialchars($s['d']); ?></span><?php endif; ?>
        </<?php echo $tag; ?>>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
