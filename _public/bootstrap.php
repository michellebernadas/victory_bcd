<?php
/**
 * Public site bootstrap — shared by groups.php, services.php and join.php in
 * the site root. NOT an entry point: opening this file directly does nothing.
 *
 * WHAT THE PUBLIC SITE MAY DO
 *   - READ a whitelisted set of columns (see pub_groups() / pub_services()).
 *   - WRITE one thing: a row in join_requests (join.php).
 * It never starts the admin session, never reads accounts, and never exposes
 * anything the query does not name explicitly — a column added to a table later
 * stays hidden by default (notify_email, notes, interns and attendees included).
 *
 * It reuses the admin app's config/config.php (database credentials, time zone)
 * so there is one .env. If the admin folder is ever renamed, change
 * VB_APP_DIR below.
 */
if (!defined('VB_PUBLIC')) { http_response_code(404); exit; }

const VB_APP_DIR = __DIR__ . '/../d5z8k2qv-x3pn-h7rb-m4yt-c6f1s9wae0l';

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', VB_APP_DIR . '/logs/error.log');

set_exception_handler(function (Throwable $e) {
    error_log('[PUBLIC UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo 'Sorry, something went wrong on our side. Please try again later.';
});

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
     . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
     . "font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; "
     . "form-action 'self'; base-uri 'self'; frame-ancestors 'self'");

require_once VB_APP_DIR . '/config/config.php';
require_once VB_APP_DIR . '/models/GroupMatcher.php';
$db = getDB();

// ── Output helpers ───────────────────────────────────────────────────────────

function pub_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function pub_time($t) {
    $t = trim((string)$t);
    return $t === '' ? '' : date('g:i A', strtotime('2000-01-01 ' . $t));
}

function pub_initials($name) {
    $parts = preg_split('/[\s&]+/', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);
    $i = '';
    foreach (array_slice($parts, 0, 2) as $p) $i .= mb_strtoupper(mb_substr($p, 0, 1));
    return $i ?: '?';
}

/** "Saturday at 3:00 PM" */
function pub_when(array $g) {
    $day = trim((string)$g['day_of_week']);
    $t   = pub_time($g['meeting_time']);
    return trim($day . ($t !== '' ? ($day !== '' ? ' at ' : '') . $t : ''));
}

function pub_group_title(array $g) {
    if (trim((string)$g['name']) !== '') return $g['name'];
    $isCampus = $g['group_kind'] === 'campus';
    $type = trim((string)$g['group_type']);
    return ($type !== '' && !$isCampus) ? $type . ' Victory Group' : ($isCampus ? 'Campus group' : 'Victory Group');
}

// ── Data: only these columns ever leave the database ─────────────────────────

/**
 * Sunday service times: active service-time rows only. The services table also
 * holds non-service labels ("migrated", "transferred to ...") flagged
 * is_service_time = 0, which must never reach the public.
 */
function pub_services($db) {
    try {
        return $db->query(
            "SELECT name, service_period FROM services
              WHERE is_service_time = 1 AND is_active = 1 AND is_deleted = 0
              ORDER BY sort_order ASC, id ASC"
        )->fetchAll() ?: [];
    } catch (PDOException $e) {
        error_log('pub_services: ' . $e->getMessage());
        return [];
    }
}

/**
 * The next Sunday service from now: today if one is still ahead, otherwise the
 * first service next Sunday. Times come from the service names ("8:30 AM").
 * @return array|null ['when' => DateTime, 'name' => string, 'is_today' => bool]
 */
function pub_next_service(array $services) {
    $slots = [];
    foreach ($services as $s) {
        $ts = strtotime('2000-01-01 ' . $s['name']);
        if ($ts !== false) $slots[] = ['name' => $s['name'], 'sec' => (int)date('G', $ts) * 3600 + (int)date('i', $ts) * 60];
    }
    if (!$slots) return null;
    usort($slots, function ($a, $b) { return $a['sec'] <=> $b['sec']; });
    $now = new DateTime('now');
    if ((int)$now->format('w') === 0) {
        $secNow = (int)$now->format('G') * 3600 + (int)$now->format('i') * 60;
        foreach ($slots as $sl) {
            if ($sl['sec'] > $secNow) {
                $w = (clone $now)->setTime(intdiv($sl['sec'], 3600), intdiv($sl['sec'] % 3600, 60));
                return ['when' => $w, 'name' => $sl['name'], 'is_today' => true];
            }
        }
    }
    $w = (new DateTime('next sunday'))->setTime(intdiv($slots[0]['sec'], 3600), intdiv($slots[0]['sec'] % 3600, 60));
    return ['when' => $w, 'name' => $slots[0]['name'], 'is_today' => false];
}

/**
 * Victory Groups and Campus groups an admin ticked "Show on the public page"
 * for. Leadership Groups are excluded in SQL, not just in the admin form.
 * Leaders' names are the verbatim names on file; interns, attendees, notes,
 * gender and notify_email are never selected.
 * Each group also gets 'stages' and 'gender' tags (GroupMatcher) for filtering.
 */
function pub_groups($db, $onlyId = null) {
    try {
        $sql = "SELECT id, group_kind, group_type, group_category, audience, name,
                       day_of_week, meeting_time, meeting_frequency, location
                  FROM victory_groups
                 WHERE is_deleted = 0 AND group_status = 'active' AND show_public = 1
                   AND group_kind IN ('victory_group', 'campus')";
        $params = [];
        if ($onlyId !== null) { $sql .= " AND id = ?"; $params[] = (int)$onlyId; }
        $sql .= " ORDER BY FIELD(group_kind, 'victory_group', 'campus'), id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $groups = $stmt->fetchAll() ?: [];
        if (!$groups) return [];

        $ids = array_map(function ($g) { return (int)$g['id']; }, $groups);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $ls  = $db->prepare(
            "SELECT group_id, COALESCE(NULLIF(display_name, ''), name) AS nm
               FROM vg_members WHERE role = 'leader' AND group_id IN ($ph)
              ORDER BY sort_order, id"
        );
        $ls->execute($ids);
        $leaders = [];
        foreach ($ls->fetchAll() as $r) { if (trim($r['nm']) !== '') $leaders[(int)$r['group_id']][] = trim($r['nm']); }
        foreach ($groups as &$g) {
            $g['leaders'] = $leaders[(int)$g['id']] ?? [];
            $g['stages']  = GroupMatcher::stages($g);
            $g['gender']  = GroupMatcher::gender($g);
        }
        unset($g);
        return $groups;
    } catch (PDOException $e) {
        error_log('pub_groups: ' . $e->getMessage());
        return [];
    }
}

// ── Layout ───────────────────────────────────────────────────────────────────

function pub_header($title, $active = '', $meta = '') {
    $nav = ['services.php' => ['Service times', 'bi-clock'], 'groups.php' => ['Find a Victory Group', 'bi-people-fill']];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . pub_e($title) . ' &middot; Victory Bacolod</title>'
       . ($meta !== '' ? '<meta name="description" content="' . pub_e($meta) . '">' : '')
       . '<link rel="icon" type="image/png" href="assets/favicon.png">'
       . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
       . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
       . '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">'
       . '<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">'
       . '<link href="assets/public.css?v=' . (int)@filemtime(__DIR__ . '/../assets/public.css') . '" rel="stylesheet">'
       . '</head><body>'
       . '<nav class="navbar navbar-expand-md sticky-top vp-nav"><div class="container">'
       . '<a class="navbar-brand" href="services.php" aria-label="Victory Bacolod home"><img src="assets/victory-logo.png" alt="Victory Bacolod"></a>'
       . '<button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#vpNav" aria-controls="vpNav" aria-expanded="false" aria-label="Toggle menu"><span class="navbar-toggler-icon"></span></button>'
       . '<div class="collapse navbar-collapse" id="vpNav"><ul class="navbar-nav ms-auto align-items-md-center gap-md-1 pt-2 pt-md-0">';
    foreach ($nav as $href => $n) {
        echo '<li class="nav-item"><a class="nav-link' . ($active === $href ? ' active" aria-current="page' : '') . '" href="' . $href . '"><i class="bi ' . $n[1] . ' me-1" aria-hidden="true"></i>' . pub_e($n[0]) . '</a></li>';
    }
    echo '<li class="nav-item ms-md-2 py-2 py-md-0"><a class="btn vp-cta" href="join.php"><i class="bi bi-heart me-1" aria-hidden="true"></i>Get Connected</a></li>'
       . '</ul></div></div></nav>';
}

/** @param string $js page script, run after jQuery / Bootstrap / Select2 are loaded */
function pub_footer($db, $js = '') {
    $services = pub_services($db);
    echo '<footer class="vp-footer"><div class="container py-5"><div class="row g-4">'
       . '<div class="col-md-5"><div class="brand mb-3"><img src="assets/victory-logo.png" alt="Victory Bacolod"></div>'
       . '<p class="mb-2">Honor God. Make Disciples.</p>'
       . '<p class="small mb-0">We are a church family in Bacolod. Wherever you are in life, there is a place for you to belong.</p></div>'
       . '<div class="col-6 col-md-3"><h2>Explore</h2><ul>'
       . '<li><a href="services.php">Service times</a></li><li><a href="groups.php">Find a Victory Group</a></li><li><a href="join.php">Get Connected</a></li></ul></div>'
       . '<div class="col-6 col-md-4"><h2>Sunday services</h2><ul>';
    if ($services) { foreach ($services as $s) echo '<li><i class="bi bi-clock me-2" aria-hidden="true"></i>' . pub_e($s['name']) . '</li>'; }
    else { echo '<li>Times coming soon</li>'; }
    echo '</ul></div></div></div>'
       . '<div class="bottom"><div class="container py-3 d-flex flex-wrap justify-content-between gap-2">'
       . '<span>&copy; ' . date('Y') . ' Victory Bacolod. All rights reserved.</span><span>Honor God. Make Disciples.</span></div></div></footer>'
       . '<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>'
       . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>'
       . '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>'
       . ($js !== '' ? '<script>' . $js . '</script>' : '')
       . '</body></html>';
}
