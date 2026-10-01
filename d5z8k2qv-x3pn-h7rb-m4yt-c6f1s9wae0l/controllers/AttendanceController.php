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

        // Authoritative derived discipleship state — the SAME source the Dashboard
        // and Member Profile read. The Journey Overview used to be computed in JS
        // from visible pivot rows (i.e. "has any attendance record", counting
        // unmatched people too), which is why its numbers disagreed with theirs.
        require_once 'models/DiscipleshipProgressService.php';
        require_once 'models/Member.php';
        $progressService    = new DiscipleshipProgressService($this->db);
        $derivedStepCounts  = $progressService->getStepCounts(false);        // all non-deleted members
        $derivedActiveCounts= $progressService->getStepCounts(true);         // active members only
        $memberTotals       = (new Member($this->db))->getStats();
        $activeMemberTotal  = (int)($memberTotals['active'] ?? 0);
        $allMemberTotal     = (int)($memberTotals['total'] ?? 0);

        include 'views/attendance_records.php';
    }

    /**
     * Classes the generic attendance form may create, and the module that owns
     * each of the rest.
     *
     * Leadership 1-1-3 and Spiritual Foundations completion is decided by a
     * per-session / per-topic grid that this form cannot capture, so a generic
     * row for them would never be valid completion evidence — it would just be
     * a record the dedicated module has to repair. They are rejected here as
     * well as hidden from the dropdown, so a crafted POST can't slip one in.
     */
    private const DEDICATED_MODULES = [
        'leadership_113'        => ['label' => 'Leadership 1-1-3',      'action' => 'leadership113'],
        'spiritual_foundations' => ['label' => 'Spiritual Foundations', 'action' => 'spiritualFoundations'],
    ];

    /**
     * Blocks a generic submission for a dedicated class. Returns only when the
     * program_type is one this form owns.
     */
    private function rejectDedicatedClass(array $data): void {
        $pt = trim((string)($data['program_type'] ?? ''));
        if (!isset(self::DEDICATED_MODULES[$pt])) return;

        $mod = self::DEDICATED_MODULES[$pt];
        $msg = sprintf(
            '%s attendance is managed from the %s module. Nothing was saved — please add or edit the record there.',
            $mod['label'], $mod['label']
        );
        header('Location: index.php?action=' . $mod['action'] . '&error=1&msg=' . urlencode($msg));
        exit();
    }

    public function addAttendance(array $data): void {
        $this->rejectDedicatedClass($data);
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
        // Blocks both directions: converting a generic row INTO an L113/SF row,
        // and editing an existing legacy L113/SF row through this form (which
        // would drop its session grid).
        $this->rejectDedicatedClass($data);
        $existing = $this->paModel->getById($id);
        if ($existing) $this->rejectDedicatedClass(['program_type' => $existing['program_type']]);

        $data = $this->normalizeMdParts($data);
        // Capture the OLD member_id+pt before update so we can also re-sync the previous member if the row moved.
        $oldRow = $existing;
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
     * REMOVED: this used to run `UPDATE members SET victory_weekend = 1` when a
     * Victory Weekend record had water_baptism=1.
     *
     * That was a second source of truth, and a write-only one — it could set the
     * flag but never clear it, so deleting the record left the member showing
     * Victory Weekend complete forever. The flag is now derived from the
     * attendance record itself by DiscipleshipProgressService, which handles the
     * water-baptism case automatically (the VW record IS the evidence) and
     * correctly reverts the step if that record is removed.
     *
     * Kept as a no-op so the call sites stay readable in diffs; safe to delete
     * once the Laravel port lands.
     */
    private function syncMemberDiscipleship(int $memberId, array $data): void {
        // Intentionally empty — see DiscipleshipProgressService::recalculateMember().
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
