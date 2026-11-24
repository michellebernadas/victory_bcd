<?php
/**
 * Public: "I'm interested in joining a Victory Group". The ONLY public page that
 * writes, and it writes exactly one row to join_requests, then tells the group's
 * leader (and staff) by email — after the visitor already has their answer.
 *
 * Built so the landing page can reuse it later: any page on this site can post
 * here with source=<name>. Add format=json (or Accept: application/json) to get
 * {ok, message, errors} instead of a page.
 *
 * Required: full_name, phone (mobile), email, gender, life_stage, consent.
 * Optional: area, message, group (a PUBLISHED group id), source.
 *
 * Abuse protection (no login, so it matters): hidden honeypot field; signed,
 * time-stamped token (rejects instant posts and stale forms); per-IP and global
 * hourly limits counted from join_requests; strict validation; the chosen group
 * must be public.
 */
define('VB_PUBLIC', 1);
require __DIR__ . '/_public/bootstrap.php';
require_once VB_APP_DIR . '/models/JoinNotifier.php';

const JR_MIN_SECONDS = 3;
const JR_MAX_SECONDS = 7200;
const JR_PER_IP_HOUR = 5;
const JR_GLOBAL_HOUR = 200;

function jr_secret() { return hash('sha256', ($_ENV['DB_PASSWORD'] ?? '') . '|' . ($_ENV['DB_DATABASE'] ?? '') . '|vb-public-join'); }
function jr_token() { $t = time(); return $t . '.' . hash_hmac('sha256', (string)$t, jr_secret()); }
function jr_token_age($tok) {
    $p = explode('.', (string)$tok, 2);
    if (count($p) !== 2 || !ctype_digit($p[0])) return null;
    return hash_equals(hash_hmac('sha256', $p[0], jr_secret()), $p[1]) ? time() - (int)$p[0] : null;
}
function jr_clean($v, $max) {
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v);
    return mb_substr(trim(preg_replace('/[ \t]+/', ' ', $v)), 0, $max);
}
/** Send the answer first, then do the slow part (email) without keeping the visitor waiting. */
function jr_respond_then(callable $after, $redirect = null, $json = null) {
    ignore_user_abort(true);
    ob_start();
    if ($json !== null) { header('Content-Type: application/json'); echo json_encode($json); }
    else header('Location: ' . $redirect);
    header('Content-Length: ' . ob_get_length());
    header('Connection: close');
    ob_end_flush(); @flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    $after();
    exit;
}

$wantsJson = (($_REQUEST['format'] ?? '') === 'json') || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
$errors = [];
$old = ['full_name' => '', 'email' => '', 'phone' => '', 'area' => '', 'message' => '', 'gender' => '', 'life_stage' => '', 'group' => (int)($_GET['group'] ?? 0)];
$source = jr_clean($_REQUEST['source'] ?? 'public_site', 40) ?: 'public_site';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['full_name' => 150, 'email' => 190, 'phone' => 60, 'area' => 150, 'message' => 1000, 'gender' => 10, 'life_stage' => 30] as $k => $max) {
        $old[$k] = jr_clean($_POST[$k] ?? '', $max);
    }
    $old['group'] = (int)($_POST['group'] ?? 0);

    $silentReject = trim((string)($_POST['website'] ?? '')) !== '';                         // honeypot
    $age = jr_token_age($_POST['t'] ?? '');
    if ($age === null || $age < JR_MIN_SECONDS) $silentReject = true;
    elseif ($age > JR_MAX_SECONDS) $errors[] = 'This form expired. Please check your details and send it again.';

    if (mb_strlen($old['full_name']) < 2) $errors[] = 'Please tell us your name.';
    $digits = preg_replace('/\D/', '', $old['phone']);
    if ($old['phone'] === '' || !preg_match('/^[0-9+()\-\s.]{7,40}$/', $old['phone']) || strlen($digits) < 7 || strlen($digits) > 15) {
        $errors[] = 'Please give a mobile number we can reach you on.';
    }
    if ($old['email'] === '' || !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please give a valid email address.';
    if (!isset(GroupMatcher::GENDERS[$old['gender']]))       $errors[] = 'Please tell us if you are a man or a woman, so we can suggest the right groups.';
    if (!isset(GroupMatcher::LIFE_STAGES[$old['life_stage']])) $errors[] = 'Please choose your life stage.';
    if (empty($_POST['consent']))                            $errors[] = 'Please tick the box so we may contact you.';

    $groupId = null;
    if ($old['group'] > 0) {                                                                 // only a published group may be chosen
        $g = pub_groups($db, $old['group']);
        if ($g) $groupId = (int)$g[0]['id']; else $old['group'] = 0;
    }

    $ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!$errors && !$silentReject) {
        $q = $db->prepare("SELECT SUM(ip_hash = ?), COUNT(*) FROM join_requests WHERE created_at > (NOW() - INTERVAL 1 HOUR)");
        $q->execute([$ipHash]);
        list($mine, $all) = array_map('intval', $q->fetch(PDO::FETCH_NUM));
        if ($mine >= JR_PER_IP_HOUR || $all >= JR_GLOBAL_HOUR) $errors[] = 'We have received a lot of requests just now. Please try again in a little while.';
    }

    $newId = 0;
    if (!$errors && !$silentReject) {
        $db->prepare("INSERT INTO join_requests (group_id, full_name, email, phone, area, message, life_stage, gender, source, ip_hash)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$groupId, $old['full_name'], $old['email'], $old['phone'], $old['area'] ?: null, $old['message'] ?: null,
                      $old['life_stage'], $old['gender'], $source, $ipHash]);
        $newId = (int)$db->lastInsertId();
    }

    if (!$errors) {                                    // success, or a bot we pretend to accept
        $after = function () use ($db, $newId) { if ($newId) JoinNotifier::run($db, $newId); };
        $okMsg = 'Thank you! A leader from our team will contact you soon.';
        if ($wantsJson) jr_respond_then($after, null, ['ok' => true, 'message' => $okMsg]);
        jr_respond_then($after, 'join.php?sent=1');
    }
    if ($wantsJson) { http_response_code(422); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'errors' => $errors]); exit; }
}

$sent   = !empty($_GET['sent']);
$groups = $sent ? [] : pub_groups($db);

pub_header('Get Connected', 'join.php', 'Tell us a little about you and a leader will reach out to welcome you to a Victory Group.');
?>
<header class="vp-hero">
    <div class="container vp-fade">
        <span class="vp-eyebrow">You belong here</span>
        <h1 class="display-5">Interested in joining<br>a Victory Group?</h1>
        <p class="lead mb-0">We would love to have you. Tell us a little about you and a leader will reach out to welcome you personally.</p>
    </div>
</header>
<main class="container py-5">
<?php if ($sent): ?>
    <div class="vp-card p-5 text-center mx-auto" style="max-width:640px">
        <i class="bi bi-check-circle-fill text-success display-3"></i>
        <h2 class="h3 mt-3">Thank you!</h2>
        <p class="text-muted mb-4">We received your details. A leader from our team will contact you soon &mdash; we&rsquo;re glad you reached out.</p>
        <a class="btn vp-cta" href="groups.php"><i class="bi bi-people-fill me-1" aria-hidden="true"></i>Browse groups</a>
        <a class="btn btn-outline-primary rounded-pill ms-2" href="services.php">Service times</a>
    </div>
<?php else: ?>
<div class="row g-4">
  <div class="col-lg-7">
    <?php if ($errors): ?>
    <div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?php echo pub_e($er); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" action="join.php" id="joinForm" class="vp-card vp-form p-4 p-md-4" novalidate data-pre="<?php echo (int)$old['group']; ?>" data-group="<?php echo (int)$old['group']; ?>">
        <input type="hidden" name="t" value="<?php echo pub_e(jr_token()); ?>">
        <input type="hidden" name="source" value="<?php echo pub_e($source); ?>">
        <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="mb-3"><label class="form-label" for="full_name">Your name <span class="text-danger">*</span></label>
            <input class="form-control" id="full_name" name="full_name" maxlength="150" required autocomplete="name" value="<?php echo pub_e($old['full_name']); ?>">
            <div class="invalid-feedback">Please tell us your name.</div></div>
        <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label" for="phone">Mobile number <span class="text-danger">*</span></label>
                <input class="form-control" id="phone" name="phone" type="tel" inputmode="tel" maxlength="40" required pattern="[0-9+()\-\s.]{7,40}" placeholder="0917 123 4567" autocomplete="tel" value="<?php echo pub_e($old['phone']); ?>">
                <div class="invalid-feedback">Please enter a mobile number.</div></div>
            <div class="col-md-6"><label class="form-label" for="email">Email <span class="text-danger">*</span></label>
                <input class="form-control" id="email" name="email" type="email" maxlength="190" required autocomplete="email" value="<?php echo pub_e($old['email']); ?>">
                <div class="invalid-feedback">Please enter a valid email.</div></div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-5"><label class="form-label" for="gender">I am a <span class="text-danger">*</span></label>
                <select class="form-select" id="gender" name="gender" required><option value="">Select&hellip;</option>
                    <?php foreach (GroupMatcher::GENDERS as $k => $lbl): ?><option value="<?php echo pub_e($k); ?>" <?php echo $old['gender'] === $k ? 'selected' : ''; ?>><?php echo pub_e($lbl); ?></option><?php endforeach; ?></select>
                <div class="invalid-feedback">Please choose one.</div></div>
            <div class="col-md-7"><label class="form-label" for="life_stage">My life stage <span class="text-danger">*</span></label>
                <select class="form-select" id="life_stage" name="life_stage" required><option value="">Select&hellip;</option>
                    <?php foreach (GroupMatcher::LIFE_STAGES as $k => $lbl): ?><option value="<?php echo pub_e($k); ?>" <?php echo $old['life_stage'] === $k ? 'selected' : ''; ?>><?php echo pub_e($lbl); ?></option><?php endforeach; ?></select>
                <div class="invalid-feedback">Please choose your life stage.</div></div>
        </div>
        <div class="mb-3"><label class="form-label" for="area">Where do you live?</label>
            <input class="form-control" id="area" name="area" maxlength="150" placeholder="Barangay or area" value="<?php echo pub_e($old['area']); ?>"></div>

        <div class="mb-2"><label class="form-label" for="group">Group you are interested in</label>
            <select class="form-select" id="group" name="group">
                <option value="0">Not sure yet &mdash; help me find one</option>
                <?php foreach ($groups as $g): $isCampus = $g['group_kind'] === 'campus'; ?>
                <option value="<?php echo (int)$g['id']; ?>" data-title="<?php echo pub_e(pub_group_title($g)); ?>" data-kind="<?php echo $isCampus ? 'Campus' : 'Victory Group'; ?>"
                        data-when="<?php echo pub_e(pub_when($g)); ?>" data-where="<?php echo pub_e($g['location']); ?>" data-leaders="<?php echo pub_e(implode(', ', $g['leaders'])); ?>"
                        data-freq="<?php echo pub_e($g['meeting_frequency']); ?>" data-cat="<?php echo pub_e($g['group_category']); ?>"
                        data-stages="<?php echo pub_e(implode(',', $g['stages'])); ?>" data-gender="<?php echo pub_e($g['gender']); ?>"
                        <?php echo (int)$old['group'] === (int)$g['id'] ? 'selected' : ''; ?>><?php echo pub_e(pub_group_title($g)); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text" id="groupNote">Choose your gender and life stage and we will show the groups that fit you.</div></div>
        <div class="vp-preview mb-3 d-none" id="groupPreview" aria-live="polite"></div>

        <div class="mb-3"><label class="form-label" for="message">Anything else we should know?</label>
            <textarea class="form-control" id="message" name="message" rows="3" maxlength="1000"><?php echo pub_e($old['message']); ?></textarea></div>
        <div class="form-check mb-4"><input class="form-check-input" type="checkbox" id="consent" name="consent" value="1" required>
            <label class="form-check-label small" for="consent">I agree that Victory Bacolod may contact me, and share these details with the group leader, about joining a group. <span class="text-danger">*</span></label>
            <div class="invalid-feedback">Please tick this box so we may contact you.</div></div>
        <button class="btn vp-cta btn-lg w-100" type="submit"><i class="bi bi-heart me-1" aria-hidden="true"></i>Get Connected</button>
    </form>
  </div>
  <aside class="col-lg-5">
    <div class="vp-card p-4 mb-3">
        <h2 class="h5 fw-bold mb-3">What happens next</h2>
        <div class="vp-step"><span class="n">1</span><div><strong>We receive your details</strong><div class="text-muted small">Only our team and the group leader see them.</div></div></div>
        <div class="vp-step"><span class="n">2</span><div><strong>A leader reaches out</strong><div class="text-muted small">Usually within a few days, to say hello and answer your questions.</div></div></div>
        <div class="vp-step mb-0"><span class="n">3</span><div><strong>You visit a group</strong><div class="text-muted small">Come once, no pressure. See if it feels like home.</div></div></div>
    </div>
    <div class="vp-card p-4"><div class="fw-semibold mb-1">Want to look around first?</div>
        <p class="text-muted small mb-3">Browse every group, its day, time and place.</p><a class="btn btn-outline-primary rounded-pill" href="groups.php">Browse groups</a></div>
  </aside>
</div>
<?php endif; ?>
</main>
<?php
$js = $sent ? '' : <<<'JS'
(function () {
  var $form = $('#joinForm'), $g = $('#group'), $stage = $('#life_stage'), $gender = $('#gender'), $note = $('#groupNote'), $prev = $('#groupPreview');
  var pre = String($form.attr('data-pre') || '0'), master = [];
  $g.find('option[data-title]').each(function () {
    var o = $(this), a = function (n) { return o.attr('data-' + n) || ''; };
    master.push({ id: o.val(), title: a('title'), kind: a('kind'), when: a('when'), where: a('where'), leaders: a('leaders'), freq: a('freq'), cat: a('cat'),
                  stages: a('stages').split(','), gender: a('gender') });
  });
  $('#gender, #life_stage').select2({ theme: 'bootstrap-5', width: '100%', minimumResultsForSearch: Infinity, placeholder: 'Select…' });

  function row(icon, text) { return text ? $('<div class="vp-row"></div>').append($('<i class="bi"></i>').addClass(icon), $('<span></span>').text(text)) : null; }
  function tpl(o, short) {
    if (!o.id || o.id === '0') return o.text;
    var g = master.filter(function (m) { return m.id === o.id; })[0]; if (!g) return o.text;
    var $e = $('<div class="s2-opt"></div>');
    $e.append($('<div></div>').append($('<span class="t"></span>').text(g.title), $('<span class="k"></span>').toggleClass('campus', g.kind === 'Campus').text(g.kind)));
    if (short) return $e;
    var m1 = [g.when, g.where].filter(Boolean).join(' · ');
    if (m1) $e.append($('<div class="m"></div>').text(m1));
    if (g.leaders) $e.append($('<div class="m"></div>').text('Led by ' + g.leaders));
    return $e;
  }
  $g.select2({ theme: 'bootstrap-5', width: '100%', templateResult: function (o) { return tpl(o, false); }, templateSelection: function (o) { return tpl(o, true); },
               matcher: function (p, d) { if (!p.term) return d; var g = master.filter(function (m) { return m.id === d.id; })[0];
                 var hay = (g ? [g.title, g.when, g.where, g.leaders, g.cat].join(' ') : d.text).toLowerCase(); return hay.indexOf(p.term.toLowerCase()) > -1 ? d : null; } });

  function fits(g, stage, gender) {
    var gOk = g.gender === 'any' || (gender === 'man' && g.gender === 'M') || (gender === 'woman' && g.gender === 'F');
    return { gender: gOk, both: gOk && (stage === 'other' || g.stages.indexOf(stage) !== -1) };
  }
  function render() {
    var stage = $stage.val(), gender = $gender.val(), list, msg;
    if (!stage || !gender) {
      list = master.filter(function (g) { return g.id === pre; });
      msg = 'Choose your gender and life stage and we will show the groups that fit you.';
    } else {
      list = master.filter(function (g) { return fits(g, stage, gender).both || g.id === pre; });
      var exact = master.filter(function (g) { return fits(g, stage, gender).both; }).length;
      if (!exact) { list = master.filter(function (g) { return fits(g, stage, gender).gender || g.id === pre; });
        msg = 'No group is made for exactly your life stage yet. Here are the groups open to you — or choose “Not sure” and our team will help you find one.'; }
      else msg = exact + (exact === 1 ? ' group fits' : ' groups fit') + ' you. Pick one, or choose “Not sure” and we will help.';
    }
    var cur = $g.val();
    $g.empty().append(new Option('Not sure yet — help me find one', '0'));
    list.forEach(function (g) { $g.append(new Option(g.title, g.id)); });
    var keep = list.some(function (g) { return g.id === cur; }) ? cur : (list.some(function (g) { return g.id === pre; }) ? pre : '0');
    $g.val(keep).trigger('change.select2'); $note.text(msg); preview();
  }
  function preview() {
    var g = master.filter(function (m) { return m.id === $g.val(); })[0];
    if (!g) { $prev.addClass('d-none').empty(); return; }
    $prev.removeClass('d-none').empty().append(
      $('<div class="d-flex align-items-center gap-2 mb-2"></div>').append($('<span class="vp-chip"></span>').toggleClass('campus', g.kind === 'Campus').text(g.kind),
        g.cat ? $('<span class="vp-chip soft"></span>').text(g.cat) : null),
      $('<div class="fw-bold mb-2"></div>').text(g.title),
      row('bi-calendar-event', g.when + (g.freq && g.freq !== 'weekly' ? ' · ' + g.freq : '')), row('bi-geo-alt', g.where), row('bi-person', g.leaders ? 'Led by ' + g.leaders : ''));
  }
  $stage.add($gender).on('change', render); $g.on('change', preview);
  render();

  $form.on('submit', function (e) {
    this.classList.add('was-validated');
    if (!this.checkValidity()) { e.preventDefault(); var bad = this.querySelector(':invalid'); if (bad) { bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); try { bad.focus({ preventScroll: true }); } catch (x) {} } }
  });
})();
JS;
pub_footer($db, $js);
