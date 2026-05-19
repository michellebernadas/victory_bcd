<?php
require_once 'models/ProgramAttendance.php';
require_once 'models/Member.php';

class AttendanceController {
    private $db;
    private $paModel;

    public function __construct($db) {
        $this->db = $db;
        $this->paModel = new ProgramAttendance($db);
    }

    public function listAttendances() {
        $filters = [
            'program_type'    => $_GET['program_type']    ?? '',
            'program_year'    => $_GET['program_year']    ?? '',
            'search'          => $_GET['search']          ?? '',
            'event_date_from' => $_GET['event_date_from'] ?? '',
            'event_date_to'   => $_GET['event_date_to']   ?? '',
        ];
        $records              = $this->paModel->getFiltered($filters);
        $availableYears       = $this->paModel->getAvailableYears();
        $paStats              = $this->paModel->getSummaryStats();
        $memberStats          = $this->paModel->getMemberStats();
        $matchStats           = $this->paModel->getMatchStats();
        $activeFilters        = array_filter($filters);
        $allEventLabels       = $this->paModel->getAllDistinctLabels();
        $allBatchLabels       = $this->paModel->getAllDistinctBatchLabels();
        $allCounselors        = $this->paModel->getAllDistinctCounselors();
        $availableEventDates  = [];
        if (!empty($filters['program_type']) && !empty($filters['program_year'])) {
            $availableEventDates = $this->paModel->getDistinctEventDatesByTypeAndYear(
                $filters['program_type'],
                (int)$filters['program_year']
            );
        }
        // Match-status filter persists across server-side navigation via the `match` URL parameter.
        $activeMatch = in_array($_GET['match'] ?? '', ['matched', 'unmatched'], true) ? $_GET['match'] : 'all';
        include 'views/attendance_records.php';
    }

    public function addAttendance(array $data): void {
        $data = $this->normalizeMdParts($data);
        $result = $this->paModel->add($data);
        if ($result) {
            // Keep the member's discipleship flag in sync with the new record.
            $this->paModel->syncMemberFlag((int)($data['member_id'] ?? 0), (string)($data['program_type'] ?? ''));
            // VW water_baptism still flips the legacy members.victory_weekend flag (already covered by syncMemberFlag,
            // but the baptism-specific message lives in syncMemberDiscipleship). Keep both for now.
            $this->syncMemberDiscipleship((int)($data['member_id'] ?? 0), $data);
        }
        $pt = $data['program_type'] ?? '';
        $redir = 'index.php?action=attendanceRecords' . ($pt ? '&program_type=' . urlencode($pt) : '');
        header('Location: ' . $redir . '&notif=' . ($result ? 'add' : 'error'));
        exit();
    }

    public function updateAttendance(int $id, array $data): void {
        $data = $this->normalizeMdParts($data);
        // Capture the OLD member_id+pt before update so we can also re-sync the previous member if the row moved.
        $oldRow = $this->paModel->getById($id);
        $result = $this->paModel->update($id, $data);
        if ($result) {
            $this->paModel->syncMemberFlag((int)($data['member_id'] ?? 0), (string)($data['program_type'] ?? ''));
            // If the record's linked member or program_type changed, re-sync the OLD member/class too.
            if ($oldRow) {
                $oldMember = (int)($oldRow['member_id'] ?? 0);
                $oldPt     = (string)($oldRow['program_type'] ?? '');
                if ($oldMember && ($oldMember !== (int)($data['member_id'] ?? 0) || $oldPt !== ($data['program_type'] ?? ''))) {
                    $this->paModel->syncMemberFlag($oldMember, $oldPt);
                }
            }
            $this->syncMemberDiscipleship((int)($data['member_id'] ?? 0), $data);
        }
        $pt = $data['program_type'] ?? '';
        $redir = 'index.php?action=attendanceRecords' . ($pt ? '&program_type=' . urlencode($pt) : '');
        header('Location: ' . $redir . '&notif=' . ($result ? 'update' : 'error'));
        exit();
    }

    /**
     * MD records may have separate dates for Part 1 and Part 2 (2025+).
     * Part 1 date becomes the main event_date (existing column).
     * Part 2 date is stashed in extra_data so the table can show it next to the P2 badge.
     */
    private function normalizeMdParts(array $data): array {
        if (($data['program_type'] ?? '') !== 'making_disciples') return $data;
        // Part 1 date → main event_date (only if user picked one)
        if (!empty($data['md_part1_date'])) {
            $data['event_date'] = $data['md_part1_date'];
        }
        $p2date = trim($data['md_part2_date'] ?? '');
        if ($p2date !== '') {
            // Merge into extra_data
            $extra = !empty($data['l113_extra']) ? json_decode($data['l113_extra'], true) : [];
            if (!is_array($extra)) $extra = [];
            $extra['part2_date'] = $p2date;
            $data['l113_extra'] = json_encode($extra);
        }
        // These per-part-date fields aren't columns on program_attendances — strip before the model writes.
        unset($data['md_part1_date'], $data['md_part2_date']);
        return $data;
    }

    /**
     * When saving a Victory Weekend record with water_baptism=1, mark the linked
     * member's victory_weekend flag. (CC/MD/EL follow-up sync was removed — those
     * classes have their own records, no need to mirror via VW.)
     */
    private function syncMemberDiscipleship(int $memberId, array $data): void {
        if (!$memberId || ($data['program_type'] ?? '') !== 'victory_weekend') return;
        if (empty($data['water_baptism'])) return;
        try {
            $this->db->prepare("UPDATE members SET victory_weekend = 1 WHERE id = ?")->execute([$memberId]);
        } catch (Exception $e) {
            error_log("syncMemberDiscipleship error: " . $e->getMessage());
        }
    }

    public function deactivateAttendance(int $id): void {
        $result = $this->paModel->updateStatus($id, 'inactive');
        if ($result) $this->paModel->syncMemberFlagFromRecord($id);
        $pt = $_GET['program_type'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        // Try to extract program_type from referrer for redirect
        if (preg_match('/program_type=([a-z_]+)/', $pt, $m)) $pt = $m[1]; else $pt = '';
        $redir = 'index.php?action=attendanceRecords' . ($pt ? '&program_type=' . urlencode($pt) : '');
        header('Location: ' . $redir . '&notif=' . ($result ? 'deactivate' : 'error'));
        exit();
    }

    public function activateAttendance(int $id): void {
        $result = $this->paModel->updateStatus($id, 'active');
        if ($result) $this->paModel->syncMemberFlagFromRecord($id);
        $pt = $_GET['program_type'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        if (preg_match('/program_type=([a-z_]+)/', $pt, $m)) $pt = $m[1]; else $pt = '';
        $redir = 'index.php?action=attendanceRecords' . ($pt ? '&program_type=' . urlencode($pt) : '');
        header('Location: ' . $redir . '&notif=' . ($result ? 'activate' : 'error'));
        exit();
    }

    public function deleteAttendance(int $id): void {
        // Capture member_id + pt BEFORE soft-delete so we can resync after.
        $row = $this->paModel->getById($id);
        $result = $this->paModel->hardDelete($id);
        if ($result && $row) {
            $this->paModel->syncMemberFlag((int)($row['member_id'] ?? 0), (string)($row['program_type'] ?? ''));
        }
        $pt = $_GET['program_type'] ?? '';
        $redir = 'index.php?action=attendanceRecords' . ($pt ? '&program_type=' . urlencode($pt) : '');
        header('Location: ' . $redir . '&notif=' . ($result ? 'delete' : 'error'));
        exit();
    }
}
?>
