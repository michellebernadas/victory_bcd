<?php
require_once 'models/Member.php';

class MemberController {
    private $db;
    private $memberModel;

    public function __construct($db) {
        $this->db = $db;
        $this->memberModel = new Member($db);
    }

    public function listMembers() {
        // Fetch active discipleship steps for dynamic filters
        $discipleshipSteps = $this->memberModel->getDiscipleshipSteps();

        $filters = [];
        if (!empty($_GET['ministry']))          $filters['ministry']          = sanitizeInput($_GET['ministry']);
        if (!empty($_GET['civil_status']))      $filters['civil_status']      = sanitizeInput($_GET['civil_status']);
        if (!empty($_GET['volunteer_status']))  $filters['volunteer_status']  = sanitizeInput($_GET['volunteer_status']);
        if (!empty($_GET['member_status']))     $filters['member_status']     = sanitizeInput($_GET['member_status']);

        // Dynamic discipleship step filters
        $discipleshipFilters = [];
        foreach ($discipleshipSteps as $step) {
            $key = 'step_' . $step['id'];
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $discipleshipFilters[$step['id']] = $_GET[$key];
            }
        }
        if (!empty($discipleshipFilters)) {
            $filters['discipleship'] = $discipleshipFilters;
        }

        $members    = $this->memberModel->getAllMembers($filters);
        $ministries = $this->memberModel->getAllMinistries();
        $services   = $this->memberModel->getAllServices();
        $activeFilters = $filters;
        include 'views/members.php';
    }

    public function addMember($data) {
        $data = $this->normalizeMemberData($data);
        $err  = $this->validateMember($data);
        if ($err) {
            header('Location: index.php?action=members&error=add&msg=' . urlencode($err));
            exit();
        }
        // Friendly duplicate check before the DB UNIQUE constraint throws.
        $existing = $this->memberModel->findByFullName($data['full_name']);
        if ($existing) {
            $msg = 'A member named "' . $existing['full_name'] . '" already exists — open their record instead of creating a duplicate.';
            header('Location: index.php?action=members&error=add&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->memberModel->addMember($data);
        if ($result) {
            header('Location: index.php?action=members&notif=add');
        } else {
            header('Location: index.php?action=members&error=add&msg=' . urlencode('Failed to add member'));
        }
        exit();
    }

    /** Collapse multi-select ministry[]/service_attending[] arrays into comma-separated strings,
     *  and ensure full_name is always derived from first_name + last_name (single source of truth). */
    private function normalizeMemberData(array $data): array {
        foreach (['ministry', 'service_attending'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $vals = array_filter(array_map('trim', $data[$key]), 'strlen');
                $data[$key] = implode(', ', $vals);
            }
        }
        $first = trim($data['first_name'] ?? '');
        $last  = trim($data['last_name']  ?? '');
        if ($first !== '' || $last !== '') {
            // Canonical "Last, First" — server-side, so the form never has to build the string itself.
            $data['full_name']  = ($last !== '' && $first !== '') ? ($last . ', ' . $first) : ($last ?: $first);
            $data['first_name'] = $first;
            $data['last_name']  = $last;
        }
        return $data;
    }

    /** Returns an error string if required fields are missing; null on success. */
    private function validateMember(array $data): ?string {
        $first = trim($data['first_name'] ?? '');
        $last  = trim($data['last_name']  ?? '');
        if ($last === '')  return 'Last name is required.';
        if ($first === '') return 'First name is required.';
        foreach (['civil_status' => 'Life Stage', 'volunteer_status' => 'Volunteer Status'] as $field => $label) {
            if (empty(trim($data[$field] ?? ''))) return $label . ' is required.';
        }
        if (empty(trim($data['ministry'] ?? '')))          return 'Ministry is required (pick at least one, or "No Ministry").';
        if (empty(trim($data['service_attending'] ?? ''))) return 'Service Attending is required (pick at least one).';
        return null;
    }

    public function editMember($id) {
        $discipleshipSteps = $this->memberModel->getDiscipleshipSteps();
        $filters    = [];
        $members    = $this->memberModel->getAllMembers($filters);
        $ministries = $this->memberModel->getAllMinistries();
        $services   = $this->memberModel->getAllServices();
        $editMember = $this->memberModel->getMemberById($id);
        $activeFilters = [];
        include 'views/members.php';
    }

    public function updateMember($id, $data) {
        $data = $this->normalizeMemberData($data);
        $err  = $this->validateMember($data);
        if ($err) {
            header('Location: index.php?action=members&error=update&msg=' . urlencode($err));
            exit();
        }
        $result = $this->memberModel->updateMember($id, $data);
        if ($result) {
            header('Location: index.php?action=members&notif=update');
        } else {
            header('Location: index.php?action=members&error=update&msg=' . urlencode('Failed to update member'));
        }
        exit();
    }

    public function deleteMember($id) {
        $usage = $this->memberModel->getUsageInfo((int)$id);
        if ($usage['total'] > 0) {
            $parts = [];
            if ($usage['attendance_count'] > 0) {
                $parts[] = $usage['attendance_count'] . ' attendance record' . ($usage['attendance_count'] === 1 ? '' : 's');
            }
            if ($usage['vg_count'] > 0) {
                $parts[] = $usage['vg_count'] . ' victory group membership' . ($usage['vg_count'] === 1 ? '' : 's');
            }
            $msg = sprintf(
                'Cannot delete "%s" — they still have %s linked. Use Deactivate instead to preserve history, or remove the linked records first.',
                $usage['name'], implode(' and ', $parts)
            );
            header('Location: index.php?action=members&error=1&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->memberModel->deleteMember($id);
        header('Location: index.php?action=members&notif=' . ($result ? 'delete' : 'error'));
        exit();
    }

    public function deactivateMember($id) {
        $result = $this->memberModel->deactivateMember((int)$id);
        header('Location: index.php?action=members&notif=' . ($result ? 'deactivate' : 'error'));
        exit();
    }

    public function activateMember($id) {
        $result = $this->memberModel->activateMember((int)$id);
        header('Location: index.php?action=members&notif=' . ($result ? 'activate' : 'error'));
        exit();
    }

    public function memberProfile($id) {
        $member = $this->memberModel->getMemberById($id);
        if (!$member) {
            header('Location: index.php?action=members&error=1&msg=' . urlencode('Member not found'));
            exit();
        }
        $discipleshipSteps      = $this->memberModel->getDiscipleshipSteps();
        $memberCompletedStepIds = $member['completed_step_ids'] ?? [];
        // Active ministry / service name sets so the view can flag deactivated assignments with a warning icon.
        $activeMinistryNames = array_flip(array_column($this->memberModel->getAllMinistries(), 'name'));
        $activeServiceNames  = array_flip(array_column($this->memberModel->getAllServices(),  'name'));

        require_once 'models/ProgramAttendance.php';
        $paModel     = new ProgramAttendance($this->db);
        $attendances = $paModel->getByMemberGrouped((int)$member['id']);

        // Victory Groups / Leadership Groups this member belongs to
        $stmtVg = $this->db->prepare("
            SELECT vm.role, vg.id AS group_id, vg.group_type, vg.group_category,
                   vg.day_of_week, vg.meeting_time, vg.location, vg.group_status,
                   COALESCE(m.full_name, vm.name) AS display_name
            FROM vg_members vm
            JOIN victory_groups vg ON vg.id = vm.group_id
            LEFT JOIN members m ON m.id = vm.member_id
            WHERE vm.member_id = ? AND vg.is_deleted = 0
            ORDER BY FIELD(vg.group_status,'active','inactive'), vm.role
        ");
        $stmtVg->execute([$member['id']]);
        $memberGroups = $stmtVg->fetchAll();

        // Fetch all members of those groups, grouped by group_id then role
        $groupDetails = [];
        if (!empty($memberGroups)) {
            $groupIds = array_unique(array_column($memberGroups, 'group_id'));
            $ph = implode(',', array_fill(0, count($groupIds), '?'));
            $stmtGd = $this->db->prepare("
                SELECT vm.group_id, vm.role, vm.member_id,
                       COALESCE(m.full_name, vm.name) AS display_name,
                       m.id AS linked_member_id
                FROM vg_members vm
                LEFT JOIN members m ON m.id = vm.member_id
                WHERE vm.group_id IN ($ph)
                ORDER BY FIELD(vm.role,'leader','intern','attendee'), display_name
            ");
            $stmtGd->execute($groupIds);
            foreach ($stmtGd->fetchAll() as $row) {
                $gid  = $row['group_id'];
                $role = $row['role'];
                $groupDetails[$gid][$role][] = $row;
            }
        }

        // Unique counselors from all attendance records
        $counselors = [];
        foreach ($attendances as $ptRecs) {
            foreach ($ptRecs as $yrRecs) {
                foreach ($yrRecs as $rec) {
                    if (!empty($rec['counselor_name'])) {
                        $cn = trim($rec['counselor_name']);
                        if ($cn && !in_array($cn, $counselors)) $counselors[] = $cn;
                    }
                }
            }
        }

        include 'views/member_profile.php';
    }
}
?>
