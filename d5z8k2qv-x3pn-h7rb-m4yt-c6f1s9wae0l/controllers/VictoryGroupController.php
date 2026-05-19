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

    public function listGroups() {
        $filters = [];
        if (!empty($_GET['group_category']))   $filters['group_category']   = sanitizeInput($_GET['group_category']);
        if (!empty($_GET['day_of_week']))       $filters['day_of_week']       = sanitizeInput($_GET['day_of_week']);
        if (!empty($_GET['meeting_frequency'])) $filters['meeting_frequency'] = sanitizeInput($_GET['meeting_frequency']);
        if (!empty($_GET['group_status']))      $filters['group_status']      = sanitizeInput($_GET['group_status']);
        if (!empty($_GET['group_type']))        $filters['group_type']        = sanitizeInput($_GET['group_type']);

        $groups        = $this->groupModel->getAllGroups($filters);
        $activeFilters = $filters;
        $statDefs      = $this->statModel->getAll();
        $statsGrouped  = $this->statModel->getStatsGrouped();
        $statSections  = VglStatDefinition::SECTIONS;
        $statLabels    = $this->statModel->getDistinctLabels();
        $vgOptions     = $this->vgOptionModel->getAllGrouped(true);
        $leaderNames   = $this->groupModel->getDistinctMemberNames('leader');
        $internNames   = $this->groupModel->getDistinctMemberNames('intern');
        $attendeeNames = $this->groupModel->getDistinctMemberNames('attendee');
        $distinctTimes = $this->groupModel->getDistinctTimes();
        $allMembers        = $this->groupModel->getAllMembersList();
        $distinctLocations = $this->groupModel->getDistinctLocations();
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

        if (empty($leaders)) {
            header('Location: index.php?action=victoryGroups&error=add&msg=' . urlencode('At least one leader is required'));
            exit();
        }

        $result = $this->groupModel->addGroup($data, $leaders, $interns, $attendees);
        if ($result) {
            header('Location: index.php?action=victoryGroups&notif=add');
        } else {
            header('Location: index.php?action=victoryGroups&error=add&msg=' . urlencode('Failed to add group'));
        }
        exit();
    }

    public function editGroup($id) {
        $filters       = [];
        $groups        = $this->groupModel->getAllGroups($filters);
        $editGroup     = $this->groupModel->getGroupById($id);
        $activeFilters = [];
        $statDefs      = $this->statModel->getAll();
        $statsGrouped  = $this->statModel->getStatsGrouped();
        $statSections  = VglStatDefinition::SECTIONS;
        $vgOptions     = $this->vgOptionModel->getAllGrouped(true);
        $leaderNames   = $this->groupModel->getDistinctMemberNames('leader');
        $internNames   = $this->groupModel->getDistinctMemberNames('intern');
        $attendeeNames = $this->groupModel->getDistinctMemberNames('attendee');
        $distinctTimes = $this->groupModel->getDistinctTimes();
        $allMembers        = $this->groupModel->getAllMembersList();
        $distinctLocations = $this->groupModel->getDistinctLocations();
        include 'views/victory_groups.php';
    }

    public function updateGroup($id, $data) {
        $leaders   = $this->parseMembersFromPost('leader_name', 'leader_gender');
        $interns   = $this->parseMembersFromPost('intern_name', 'intern_gender');
        $attendees = $this->parseMembersFromPost('attendee_name');

        $result = $this->groupModel->updateGroup($id, $data, $leaders, $interns, $attendees);
        if ($result) {
            header('Location: index.php?action=victoryGroups&notif=update');
        } else {
            header('Location: index.php?action=victoryGroups&error=update&msg=' . urlencode('Failed to update group'));
        }
        exit();
    }

    public function deleteGroup($id) {
        // Soft delete: sets group_status='inactive'. Kept for backwards-compat with the Deactivate button.
        $result = $this->groupModel->deleteGroup($id);
        header('Location: index.php?action=victoryGroups&notif=' . ($result ? 'deactivate' : 'error'));
        exit();
    }

    public function activateGroup($id) {
        $result = $this->groupModel->activateGroup($id);
        header('Location: index.php?action=victoryGroups&notif=' . ($result ? 'activate' : 'error'));
        exit();
    }

    public function hardDeleteGroup($id) {
        $usage = $this->groupModel->getUsageInfo((int)$id);
        if ($usage['member_count'] > 0) {
            $msg = sprintf(
                'Cannot delete this group — %d member%s still listed (leaders, interns, attendees). Remove all members first, or use Deactivate to preserve history.',
                $usage['member_count'],
                $usage['member_count'] === 1 ? ' is' : 's are'
            );
            header('Location: index.php?action=victoryGroups&error=1&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->groupModel->hardDelete((int)$id);
        header('Location: index.php?action=victoryGroups&notif=' . ($result ? 'delete' : 'error'));
        exit();
    }
}
?>
