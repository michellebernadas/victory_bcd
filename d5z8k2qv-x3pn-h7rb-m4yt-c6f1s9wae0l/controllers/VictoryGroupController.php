<?php
require_once 'models/VictoryGroup.php';
require_once 'models/VglStatDefinition.php';
require_once 'models/VgOption.php';

class VictoryGroupController {
    private $db;
    private $groupModel;
    private $statModel;
    private $vgOptionModel;

    public function __construct($db) {
        $this->db         = $db;
        $this->groupModel = new VictoryGroup($db);
        $this->statModel  = new VglStatDefinition($db);
        $this->vgOptionModel = new VgOption($db);
    }

    // Maps the Groups page's top-level nav tab to the group_kind it filters on.
    const TAB_KIND_MAP = ['vg' => 'victory_group', 'lg' => 'leadership_group', 'campus' => 'campus'];
    const KIND_TAB_MAP = ['victory_group' => 'vg', 'leadership_group' => 'lg', 'campus' => 'campus'];

    private function tabForKind($kind) {
        return self::KIND_TAB_MAP[$kind] ?? 'vg';
    }

    public function listGroups() {
        $filters = [];
        $tab = $_GET['tab'] ?? 'vg';
        if (isset(self::TAB_KIND_MAP[$tab])) {
            $filters['group_kind'] = self::TAB_KIND_MAP[$tab];
        } elseif (!empty($_GET['group_kind'])) {
            $filters['group_kind'] = sanitizeInput($_GET['group_kind']);
        }
        if (!empty($_GET['group_category']))   $filters['group_category']   = sanitizeInput($_GET['group_category']);
        if (!empty($_GET['day_of_week']))       $filters['day_of_week']       = sanitizeInput($_GET['day_of_week']);
        if (!empty($_GET['meeting_frequency'])) $filters['meeting_frequency'] = sanitizeInput($_GET['meeting_frequency']);
        if (!empty($_GET['group_status']))      $filters['group_status']      = sanitizeInput($_GET['group_status']);
        if (!empty($_GET['group_type']))        $filters['group_type']        = sanitizeInput($_GET['group_type']);
        // Date range filter — matches on victory_groups.dateadded (when the group was created).
        if (!empty($_GET['date_from']))         $filters['date_from']         = sanitizeInput($_GET['date_from']);
        if (!empty($_GET['date_to']))           $filters['date_to']           = sanitizeInput($_GET['date_to']);

        $groups        = $this->groupModel->getAllGroups($filters);
        $activeFilters = $filters;
        $statDefs      = $this->statModel->getAll();
        $statsGrouped  = $this->statModel->getStatsGrouped();
        $statSections  = VglStatDefinition::SECTIONS;
        $statLabels    = $this->statModel->getDistinctLabels();
        $vgOptions     = $this->vgOptionModel->getAllGrouped(true);
        $leaderNames   = $this->groupModel->getDistinctMemberNames('leader', $filters['group_kind'] ?? null);
        $internNames   = $this->groupModel->getDistinctMemberNames('intern', $filters['group_kind'] ?? null);
        $attendeeNames = $this->groupModel->getDistinctMemberNames('attendee', $filters['group_kind'] ?? null);
        $lgLeaderNames = $this->groupModel->getDistinctMemberNames('lg_leader', $filters['group_kind'] ?? null);
        $distinctTimes = $this->groupModel->getDistinctTimes();
        $allMembers        = $this->groupModel->getAllMembersList();
        $distinctLocations = $this->groupModel->getDistinctLocations();
        $groupStats        = $this->groupModel->getStats();
        $needsLinkCount    = canAdminister() ? $this->groupModel->countUnresolvedSlots() : 0;
        include 'views/victory_groups.php';
    }

    // Parse parallel name[] / gender[] arrays from POST into [{name, gender}, ...]
    private function parseMembersFromPost($nameKey, $genderKey = null) {
        $names   = $_POST[$nameKey]   ?? [];
        $genders = $genderKey ? ($_POST[$genderKey] ?? []) : [];
        $result  = [];
        foreach ($names as $i => $name) {
            $name = trim($name);
            if ($name !== '') {
                $result[] = ['name' => $name, 'gender' => trim($genders[$i] ?? '')];
            }
        }
        return $result;
    }

    public function addGroup($data) {
        $leaders   = $this->parseMembersFromPost('leader_name', 'leader_gender');
        $interns   = $this->parseMembersFromPost('intern_name', 'intern_gender');
        $attendees = $this->parseMembersFromPost('attendee_name');
        $lgLeaders = $this->parseMembersFromPost('lg_leader_name', 'lg_leader_gender');
        $tab = $this->tabForKind($data['group_kind'] ?? '');

        $isLeadership = ($data['group_kind'] ?? '') === 'leadership_group';
        if ($isLeadership ? empty($lgLeaders) : empty($leaders)) {
            header('Location: index.php?action=victoryGroups&tab=' . $tab . '&error=add&msg=' . urlencode('At least one leader is required'));
            exit();
        }

        $result = $this->groupModel->addGroup($data, $leaders, $interns, $attendees, $lgLeaders);
        if ($result) {
            header('Location: index.php?action=victoryGroups&tab=' . $tab . '&notif=add');
        } else {
            header('Location: index.php?action=victoryGroups&tab=' . $tab . '&error=add&msg=' . urlencode('Failed to add group'));
        }
        exit();
    }

    public function editGroup($id) {
        $editGroup = $this->groupModel->getGroupById($id);
        $tab = $this->tabForKind(is_array($editGroup) ? ($editGroup['group_kind'] ?? '') : '');
        $_GET['tab'] = $tab;
        $filters       = ['group_kind' => self::TAB_KIND_MAP[$tab]];
        $groups        = $this->groupModel->getAllGroups($filters);
        $activeFilters = $filters;
        $statDefs      = $this->statModel->getAll();
        $statsGrouped  = $this->statModel->getStatsGrouped();
        $statSections  = VglStatDefinition::SECTIONS;
        $vgOptions     = $this->vgOptionModel->getAllGrouped(true);
        $leaderNames   = $this->groupModel->getDistinctMemberNames('leader', $filters['group_kind'] ?? null);
        $internNames   = $this->groupModel->getDistinctMemberNames('intern', $filters['group_kind'] ?? null);
        $attendeeNames = $this->groupModel->getDistinctMemberNames('attendee', $filters['group_kind'] ?? null);
        $lgLeaderNames = $this->groupModel->getDistinctMemberNames('lg_leader', $filters['group_kind'] ?? null);
        $distinctTimes = $this->groupModel->getDistinctTimes();
        $allMembers        = $this->groupModel->getAllMembersList();
        $distinctLocations = $this->groupModel->getDistinctLocations();
        $groupStats        = $this->groupModel->getStats();
        $needsLinkCount    = canAdminister() ? $this->groupModel->countUnresolvedSlots() : 0;
        include 'views/victory_groups.php';
    }

    public function updateGroup($id, $data) {
        $leaders   = $this->parseMembersFromPost('leader_name', 'leader_gender');
        $interns   = $this->parseMembersFromPost('intern_name', 'intern_gender');
        $attendees = $this->parseMembersFromPost('attendee_name');
        $lgLeaders = $this->parseMembersFromPost('lg_leader_name', 'lg_leader_gender');
        $tab = $this->tabForKind($data['group_kind'] ?? '');

        $result = $this->groupModel->updateGroup($id, $data, $leaders, $interns, $attendees, $lgLeaders);
        if ($result) {
            header('Location: index.php?action=victoryGroups&tab=' . $tab . '&notif=update');
        } else {
            header('Location: index.php?action=victoryGroups&tab=' . $tab . '&error=update&msg=' . urlencode('Failed to update group'));
        }
        exit();
    }

    public function deleteGroup($id) {
        // Soft delete: sets group_status='inactive'. Kept for backwards-compat with the Deactivate button.
        $group = $this->groupModel->getGroupById($id);
        $tab = $this->tabForKind(is_array($group) ? ($group['group_kind'] ?? '') : '');
        $result = $this->groupModel->deleteGroup($id);
        header('Location: index.php?action=victoryGroups&tab=' . $tab . '&notif=' . ($result ? 'deactivate' : 'error'));
        exit();
    }

    public function activateGroup($id) {
        $group = $this->groupModel->getGroupById($id);
        $tab = $this->tabForKind(is_array($group) ? ($group['group_kind'] ?? '') : '');
        $result = $this->groupModel->activateGroup($id);
        header('Location: index.php?action=victoryGroups&tab=' . $tab . '&notif=' . ($result ? 'activate' : 'error'));
        exit();
    }

    // ── Manual person linking ────────────────────────────────────────────────

    /**
     * Link one roster line to one existing member.
     *
     * A couple row has two slots and is linked twice, once per spouse. Nothing is
     * created here and the row's display_name is not touched, so the schedule's
     * own wording survives the link. The model enforces that slot 2 only exists on
     * a couple row and that the same member cannot fill both halves.
     */
    public function linkVgMember() {
        $vgmId    = (int)($_POST['vg_member_id'] ?? 0);
        $slot     = (int)($_POST['slot'] ?? 1);
        $memberId = (int)($_POST['member_id'] ?? 0);
        $tab      = $this->tabForKind($this->groupModel->getGroupKindForVgMember($vgmId));

        if ($vgmId <= 0 || $memberId <= 0) {
            $this->redirectLink($tab, false, 'Choose a member to link this name to.');
        }
        $res = $this->groupModel->linkVgMember($vgmId, $slot, $memberId);
        $this->redirectLink($tab, $res['ok'], $res['msg']);
    }

    /** Clear one slot. The name stays on the group, back on Needs Link. */
    public function unlinkVgMember() {
        $vgmId = (int)($_GET['vgm'] ?? 0);
        $slot  = (int)($_GET['slot'] ?? 1);
        $tab   = $this->tabForKind($this->groupModel->getGroupKindForVgMember($vgmId));

        if ($vgmId <= 0) $this->redirectLink($tab, false, 'That name could not be found.');
        $res = $this->groupModel->unlinkVgMember($vgmId, $slot);
        $this->redirectLink($tab, $res['ok'], $res['msg']);
    }

    // ── Needs Link: one page for every unresolved imported name ──────────────

    public function needsLink() {
        require_once 'models/LinkSuggester.php';
        $nlRows      = $this->groupModel->getUnresolvedRows();
        $nlProgress  = $this->groupModel->getLinkProgress();
        $suggester   = new LinkSuggester($this->groupModel->getMembersForMatching());
        $nlSuggest   = [];
        foreach ($nlRows as $r) {
            $nlSuggest[(int)$r['vg_member_id']] = $suggester->suggestForRow($r);
        }
        include 'views/needs_link.php';
    }

    /**
     * One action on one slot: link | unlink | not_member | restore.
     * AJAX (ajax=1) answers JSON with the redrawn row and fresh progress, so the
     * admin's place in a long list never moves. Without JS the same form posts
     * normally and is redirected back to the same filtered URL and row anchor.
     */
    public function needsLinkSave() {
        $vgmId    = (int)($_POST['vg_member_id'] ?? 0);
        $slot     = (int)($_POST['slot'] ?? 1);
        $op       = (string)($_POST['op'] ?? '');
        $memberId = (int)($_POST['member_id'] ?? 0);

        if ($vgmId <= 0) {
            $res = ['ok' => false, 'msg' => 'That name could not be found.'];
        } elseif ($op === 'link') {
            $res = $memberId > 0
                ? $this->groupModel->linkVgMember($vgmId, $slot, $memberId)
                : ['ok' => false, 'msg' => 'Choose a member to link this name to.'];
        } elseif ($op === 'not_member') {
            $res = $this->groupModel->markVgMemberNotAMember($vgmId, $slot);
        } elseif ($op === 'unlink' || $op === 'restore') {
            $res = $this->groupModel->unlinkVgMember($vgmId, $slot);
            if ($res['ok'] && $op === 'restore') $res['msg'] = 'Back on Needs Link.';
        } else {
            $res = ['ok' => false, 'msg' => 'Unknown action.'];
        }

        if (!empty($_POST['ajax'])) {
            require_once 'models/LinkSuggester.php';
            require_once 'views/shared/needs_link_row.php';
            $row  = $vgmId > 0 ? $this->groupModel->getNeedsLinkRow($vgmId) : false;
            $html = '';
            if ($row) {
                $sugg = (new LinkSuggester($this->groupModel->getMembersForMatching()))->suggestForRow($row);
                $html = nl_render_row($row, $sugg);
            }
            header('Content-Type: application/json');
            echo json_encode([
                'ok'       => (bool)$res['ok'],
                'msg'      => $res['msg'],
                'html'     => $html,
                'progress' => $this->groupModel->getLinkProgress(),
            ]);
            exit();
        }

        // No-JS fallback: back to the same filtered list, at the same row.
        $back = (string)($_POST['return'] ?? '');
        parse_str(ltrim($back, '?'), $q);
        $keep = array_intersect_key($q, array_flip(['kind', 'role', 'couples', 'q']));
        header('Location: index.php?action=needsLink' . ($keep ? '&' . http_build_query($keep) : '')
             . ($res['ok'] ? '&notif=1' : '&error=1') . '&msg=' . urlencode($res['msg'])
             . '#nl-row-' . $vgmId);
        exit();
    }

    private function redirectLink($tab, $ok, $msg) {
        header('Location: index.php?action=victoryGroups&tab=' . $tab
             . ($ok ? '&notif=link&msg=' : '&error=link&msg=') . urlencode($msg));
        exit();
    }

    public function hardDeleteGroup($id) {
        $group = $this->groupModel->getGroupById($id);
        $tab = $this->tabForKind(is_array($group) ? ($group['group_kind'] ?? '') : '');
        $usage = $this->groupModel->getUsageInfo((int)$id);
        if ($usage['member_count'] > 0) {
            $msg = sprintf(
                'Cannot delete this group — %d member%s still listed (leaders, interns, attendees). Remove all members first, or use Deactivate to preserve history.',
                $usage['member_count'],
                $usage['member_count'] === 1 ? ' is' : 's are'
            );
            header('Location: index.php?action=victoryGroups&tab=' . $tab . '&error=1&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->groupModel->hardDelete((int)$id);
        header('Location: index.php?action=victoryGroups&tab=' . $tab . '&notif=' . ($result ? 'delete' : 'error'));
        exit();
    }
}
?>
