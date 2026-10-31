<?php
/**
 * One row of the Needs Link screen.
 *
 * Rendered by views/needs_link.php on page load AND by
 * VictoryGroupController::needsLinkSave() after an AJAX action, so the row the
 * admin sees after clicking Link is byte-for-byte what a reload would show.
 *
 * Every control is a real <form method="POST"> to needsLinkSave: with JS the
 * page intercepts it and swaps the row in place; without JS it posts and the
 * controller redirects back to the same filtered URL and #nl-row anchor.
 */

// Always loaded with require_once (const must be top-level, so no guard block).

const NL_ROLE_LABELS = ['leader' => 'Leader', 'intern' => 'Intern', 'attendee' => 'Member', 'lg_leader' => 'LG Leader'];
const NL_KIND_BADGES = [
    'victory_group'    => ['VG',     'bg-primary'],
    'leadership_group' => ['LG',     'bg-info text-dark'],
    'campus'           => ['Campus', 'bg-danger'],
];

/** 'linked' | 'not_a_member' | 'open' for one slot. */
function nl_slot_state(array $r, int $slot): string {
    $mid  = $slot === 1 ? $r['member_id']   : $r['member_id_2'];
    $stat = $slot === 1 ? $r['link_status'] : $r['link_status_2'];
    if ($stat === 'linked' && !empty($mid)) return 'linked';
    if ($stat === 'not_a_member') return 'not_a_member';
    return 'open';
}

function nl_form_open(int $vgmId, int $slot, string $op, string $extraClass = ''): string {
    return '<form method="POST" action="index.php?action=needsLinkSave" class="nl-form ' . $extraClass . '">'
         . '<input type="hidden" name="vg_member_id" value="' . $vgmId . '">'
         . '<input type="hidden" name="slot" value="' . $slot . '">'
         . '<input type="hidden" name="op" value="' . htmlspecialchars($op) . '">'
         . '<input type="hidden" name="return" value="' . htmlspecialchars('?' . ($_SERVER['QUERY_STRING'] ?? '')) . '">';
}

function nl_render_slot(array $r, int $slot, array $suggestions): string {
    $vgmId    = (int)$r['vg_member_id'];
    $isCouple = !empty($r['is_couple']);
    $state    = nl_slot_state($r, $slot);
    $mid      = (int)($slot === 1 ? $r['member_id'] : $r['member_id_2']);
    $mName    = (string)($slot === 1 ? $r['member_name'] : $r['member2_name']);

    $out = '<div class="nl-slot nl-slot-' . $state . '" data-slot="' . $slot . '">';
    $out .= '<div class="d-flex align-items-center flex-wrap gap-2 mb-1">';
    if ($isCouple) $out .= '<span class="fw-semibold small">Person ' . $slot . '</span>';

    if ($state === 'linked') {
        $out .= '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Linked</span>'
              . '<a href="index.php?action=memberProfile&amp;id=' . $mid . '" class="small text-decoration-none" target="_blank" rel="noopener">'
              . '<i class="bi bi-person-fill text-success me-1"></i>' . htmlspecialchars($mName) . '</a>'
              . nl_form_open($vgmId, $slot, 'unlink', 'd-inline')
              . '<button type="submit" class="btn btn-link btn-sm p-0 text-danger text-decoration-none small" title="Unlink. The name stays exactly as written.">'
              . '<i class="bi bi-x-circle me-1"></i>Unlink</button></form>';
        return $out . '</div></div>';
    }
    if ($state === 'not_a_member') {
        $out .= '<span class="badge bg-secondary"><i class="bi bi-person-dash me-1"></i>Not a church member</span>'
              . nl_form_open($vgmId, $slot, 'restore', 'd-inline')
              . '<button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none small">'
              . '<i class="bi bi-arrow-counterclockwise me-1"></i>Undo</button></form>';
        return $out . '</div></div>';
    }

    $out .= '<span class="badge bg-warning text-dark"><i class="bi bi-link-45deg me-1"></i>Needs Link</span></div>';

    if ($suggestions) {
        $out .= '<div class="d-flex flex-wrap gap-1 mb-1 align-items-center"><span class="text-muted small me-1">Suggested:</span>';
        foreach ($suggestions as $s) {
            $out .= nl_form_open($vgmId, $slot, 'link', 'd-inline')
                  . '<input type="hidden" name="member_id" value="' . (int)$s['id'] . '">'
                  . '<button type="submit" class="btn btn-sm btn-outline-success nl-chip" title="Link this name to ' . htmlspecialchars($s['full_name']) . '">'
                  . 'Link to ' . htmlspecialchars($s['full_name']) . '?</button></form>';
        }
        $out .= '</div>';
    }

    $out .= '<div class="d-flex flex-wrap gap-1 align-items-center">'
          . nl_form_open($vgmId, $slot, 'link', 'd-flex gap-1 align-items-center flex-grow-1 nl-search-form')
          . '<div class="flex-grow-1" style="min-width:180px;max-width:320px">'
          . '<select name="member_id" class="form-select form-select-sm nl-member-search" required'
          . ' aria-label="Search members to link ' . htmlspecialchars($isCouple ? 'Person ' . $slot : $r['written_name']) . '">'
          . '<option value="">Search members…</option></select></div>'
          . '<button type="submit" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-link-45deg me-1"></i>Link</button></form>'
          . nl_form_open($vgmId, $slot, 'not_member', 'd-inline')
          . '<button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap" title="This person will never have a member record (e.g. a guest). You can undo this.">'
          . '<i class="bi bi-person-dash me-1"></i>Not a church member</button></form>'
          . '</div>';
    return $out . '</div>';
}

/** The whole <tr>. $sugg is LinkSuggester::suggestForRow() output, keyed by slot. */
function nl_render_row(array $r, array $sugg): string {
    $vgmId    = (int)$r['vg_member_id'];
    $isCouple = !empty($r['is_couple']);
    $slots    = $isCouple ? [1, 2] : [1];
    $open = 0; $linked = 0;
    foreach ($slots as $s) {
        $st = nl_slot_state($r, $s);
        if ($st === 'open') $open++;
        if ($st === 'linked') $linked++;
    }
    [$kindLabel, $kindClass] = NL_KIND_BADGES[$r['group_kind']] ?? ['VG', 'bg-primary'];
    $roleKey   = $r['role_key'] ?? $r['role'];
    $roleLabel = NL_ROLE_LABELS[$roleKey] ?? ucfirst((string)$roleKey);

    $when = trim(implode(' · ', array_filter([
        (string)$r['day_of_week'],
        $r['meeting_time'] ? date('g:i A', strtotime($r['meeting_time'])) : '',
        (string)$r['location'],
    ])));
    $groupTitle = trim((string)$r['group_name']) !== '' ? $r['group_name'] : ($r['group_type'] ?: 'Group #' . (int)$r['group_id']);
    $search = mb_strtolower($r['written_name'] . ' ' . $groupTitle . ' ' . $when . ' ' . $r['group_leaders'] . ' '
            . $r['member_name'] . ' ' . $r['member2_name'], 'UTF-8');

    $out = '<tr id="nl-row-' . $vgmId . '" class="nl-row' . ($open === 0 ? ' nl-done' : '') . '"'
         . ' data-kind="' . htmlspecialchars($r['group_kind']) . '"'
         . ' data-role="' . htmlspecialchars($roleKey) . '"'
         . ' data-couple="' . ($isCouple ? 1 : 0) . '"'
         . ' data-open="' . $open . '"'
         . ' data-search="' . htmlspecialchars($search) . '">';

    // Name exactly as written.
    $out .= '<td class="nl-name"><div class="fw-semibold">' . htmlspecialchars($r['written_name']) . '</div>';
    if ($isCouple) {
        $out .= '<span class="badge ' . ($linked === 2 ? 'bg-success' : 'bg-light text-dark border') . ' mt-1">'
              . '<i class="bi bi-people-fill me-1"></i>Couple &middot; ' . $linked . ' of 2 linked</span>';
    }
    if ($open === 0) {
        $out .= '<div class="small text-success mt-1"><i class="bi bi-check2-all me-1"></i>All set</div>';
    }
    $out .= '</td>';

    // Group.
    $out .= '<td class="nl-group"><div class="d-flex align-items-center gap-1 flex-wrap">'
          . '<span class="badge ' . $kindClass . '">' . $kindLabel . '</span>'
          . '<a href="index.php?action=victoryGroups&amp;tab=' . ['victory_group' => 'vg', 'leadership_group' => 'lg', 'campus' => 'campus'][$r['group_kind']] . '"'
          . ' class="small fw-semibold text-decoration-none text-reset">' . htmlspecialchars($groupTitle) . '</a>';
    if (($r['group_status'] ?? 'active') !== 'active') $out .= '<span class="badge bg-secondary">Inactive</span>';
    $out .= '</div>';
    if ($when !== '') $out .= '<div class="small text-muted">' . htmlspecialchars($when) . '</div>';
    if ($r['group_leaders'] !== '') {
        $out .= '<div class="small text-muted text-truncate" style="max-width:260px" title="' . htmlspecialchars($r['group_leaders']) . '">'
              . '<i class="bi bi-person-badge me-1"></i>' . htmlspecialchars($r['group_leaders']) . '</div>';
    }
    $out .= '</td>';

    // Role.
    $out .= '<td class="nl-role"><span class="badge bg-light text-dark border">' . htmlspecialchars($roleLabel) . '</span></td>';

    // Slots.
    $out .= '<td class="nl-slots">';
    foreach ($slots as $s) $out .= nl_render_slot($r, $s, $sugg[$s] ?? []);
    $out .= '</td></tr>';
    return $out;
}
