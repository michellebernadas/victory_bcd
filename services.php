<?php
/** Public: Sunday service schedule. Read-only, no login. */
define('VB_PUBLIC', 1);
require __DIR__ . '/_public/bootstrap.php';

$services = pub_services($db);
$next     = pub_next_service($services);

// Morning / afternoon / evening, from the time itself.
$part = function ($name) {
    $h = (int)date('G', strtotime('2000-01-01 ' . $name));
    return $h < 12 ? ['Morning service', 'bi-sunrise', 'am'] : ($h < 17 ? ['Afternoon service', 'bi-sun', 'am'] : ['Evening service', 'bi-moon-stars', 'pm']);
};

pub_header('Sunday service times', 'services.php', 'Sunday service times at Victory Bacolod. Everyone is welcome.');
?>
<header class="vp-hero">
    <div class="container vp-fade">
        <span class="vp-eyebrow">Sundays at Victory</span>
        <h1 class="display-5">Come as you are.<br>There&rsquo;s a seat for you.</h1>
        <p class="lead mb-0">Worship with us this Sunday. Pick the service time that fits your week.</p>
        <?php if ($next): ?>
        <div class="vp-next">
            <div class="l"><?php echo $next['is_today'] ? 'Later today' : 'Next service'; ?></div>
            <div class="v"><i class="bi bi-calendar-event me-2" aria-hidden="true"></i><?php echo pub_e($next['when']->format($next['is_today'] ? '\T\o\d\a\y' : 'l, F j')); ?> &middot; <?php echo pub_e($next['name']); ?></div>
        </div>
        <?php endif; ?>
    </div>
</header>

<main class="container py-5">
    <?php if (!$services): ?>
        <div class="vp-card p-5 text-center text-muted"><i class="bi bi-clock-history display-5 d-block mb-2"></i>Service times will be posted here soon.</div>
    <?php else: ?>
    <h2 class="h4 fw-bold mb-3">Sunday service times</h2>
    <div class="row g-3 justify-content-center">
        <?php foreach ($services as $s): $p = $part($s['name']); $isNext = $next && $next['name'] === $s['name']; ?>
        <div class="col-6 col-lg-3">
            <div class="vp-card vp-time vp-lift <?php echo $isNext ? 'next' : ''; ?>">
                <?php if ($isNext): ?><span class="tag"><?php echo $next['is_today'] ? 'Today' : 'Next'; ?></span><?php endif; ?>
                <div class="icon <?php echo $p[2]; ?>"><i class="bi <?php echo $p[1]; ?>" aria-hidden="true"></i></div>
                <div class="big"><?php echo pub_e($s['name']); ?></div>
                <div class="text-muted small"><?php echo $p[0]; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2 class="h4 fw-bold mt-5 mb-3">New to Victory?</h2>
    <div class="row g-3">
        <div class="col-md-4"><div class="vp-card vp-feature p-4 h-100"><div class="ico"><i class="bi bi-emoji-smile" aria-hidden="true"></i></div>
            <h3 class="h6 fw-bold">Come as you are</h3><p class="text-muted small mb-0">No need to prepare anything. Everyone is welcome, wherever you are in your walk with God.</p></div></div>
        <div class="col-md-4"><div class="vp-card vp-feature p-4 h-100"><div class="ico"><i class="bi bi-people" aria-hidden="true"></i></div>
            <h3 class="h6 fw-bold">Belong in a Victory Group</h3><p class="text-muted small mb-0">Sunday is just the start. Small groups meet through the week so you can grow with others.</p></div></div>
        <div class="col-md-4"><div class="vp-card vp-feature p-4 h-100"><div class="ico"><i class="bi bi-chat-heart" aria-hidden="true"></i></div>
            <h3 class="h6 fw-bold">We&rsquo;d love to hear from you</h3><p class="text-muted small mb-0">Tell us a little about you and someone from our team will reach out personally.</p></div></div>
    </div>

    <div class="vp-card p-4 p-md-5 mt-5 text-center" style="background:linear-gradient(135deg,#f7f9ff,#eef2ff)">
        <h2 class="h4 fw-bold">Ready to take the next step?</h2>
        <p class="text-muted mb-4">Find a Victory Group near you, or let us help you find the right one.</p>
        <a class="btn vp-cta btn-lg" href="join.php"><i class="bi bi-heart me-1" aria-hidden="true"></i>I&rsquo;m interested in a Victory Group</a>
        <a class="btn btn-outline-primary btn-lg ms-md-2 mt-2 mt-md-0 rounded-pill" href="groups.php">Browse groups</a>
    </div>
</main>
<?php pub_footer($db); ?>
