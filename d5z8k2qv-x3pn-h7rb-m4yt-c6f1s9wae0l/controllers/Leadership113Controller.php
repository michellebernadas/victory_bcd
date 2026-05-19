<?php
require_once 'models/ProgramAttendance.php';
require_once 'models/Member.php';

class Leadership113Controller {
    private $db;
    private $paModel;

    public function __construct($db) {
        $this->db    = $db;
        $this->paModel = new ProgramAttendance($db);
    }

    public function listRecords(): void {
        $filters = [
            'program_type'    => 'leadership_113',
            'program_year'    => $_GET['year']            ?? '',
            'search'          => $_GET['search']          ?? '',
            'event_date_from' => $_GET['event_date_from'] ?? '',
            'event_date_to'   => $_GET['event_date_to']   ?? '',
        ];
        $records          = $this->paModel->getFiltered($filters);
        $availableYears   = $this->paModel->getAvailableYearsByType('leadership_113');
        $batchList        = $this->paModel->getDistinctBatchesByType('leadership_113');
        $l113BatchNames   = $this->paModel->getDistinctL113BatchNames();
        $l113Statuses     = $this->paModel->getDistinctL113SessionStatuses();
        $paStats          = $this->paModel->getSummaryStats();
        $availableEventDates = [];
        if (!empty($filters['program_year'])) {
            $availableEventDates = $this->paModel->getDistinctEventDatesByTypeAndYear('leadership_113', (int)$filters['program_year']);
        }
        $activeYear      = $filters['program_year'];
        $activeBatch     = $_GET['batch'] ?? '';
        $activeSearch    = $filters['search'];
        $activeDateFrom  = $filters['event_date_from'];
        $activeDateTo    = $filters['event_date_to'];
        // Match-status filter persists across page navigation via the `match` URL parameter (matched | unmatched | all).
        $activeMatch     = in_array($_GET['match'] ?? '', ['matched', 'unmatched'], true) ? $_GET['match'] : 'all';
        include 'views/leadership_113.php';
    }

    public function addRecord(array $data): void {
        // Build L113 extra_data from session fields if provided
        $data = $this->buildL113ExtraData($data);
        $result = $this->paModel->add($data);
        if ($result) $this->paModel->syncMemberFlag((int)($data['member_id'] ?? 0), 'leadership_113');
        header('Location: index.php?action=leadership113&notif=' . ($result ? 'add' : 'error'));
        exit();
    }

    public function updateRecord(int $id, array $data): void {
        $oldRow = $this->paModel->getById($id);
        $data = $this->buildL113ExtraData($data, $id);
        $result = $this->paModel->update($id, $data);
        if ($result) {
            $this->paModel->syncMemberFlag((int)($data['member_id'] ?? 0), 'leadership_113');
            if ($oldRow && (int)($oldRow['member_id'] ?? 0) && (int)$oldRow['member_id'] !== (int)($data['member_id'] ?? 0)) {
                $this->paModel->syncMemberFlag((int)$oldRow['member_id'], 'leadership_113');
            }
        }
        header('Location: index.php?action=leadership113&notif=' . ($result ? 'update' : 'error'));
        exit();
    }

    public function deactivateRecord(int $id): void {
        $result = $this->paModel->updateStatus($id, 'inactive');
        if ($result) $this->paModel->syncMemberFlagFromRecord($id);
        header('Location: index.php?action=leadership113&notif=' . ($result ? 'deactivate' : 'error'));
        exit();
    }

    public function activateRecord(int $id): void {
        $result = $this->paModel->updateStatus($id, 'active');
        if ($result) $this->paModel->syncMemberFlagFromRecord($id);
        header('Location: index.php?action=leadership113&notif=' . ($result ? 'activate' : 'error'));
        exit();
    }

    public function deleteRecord(int $id): void {
        $row = $this->paModel->getById($id);
        $result = $this->paModel->hardDelete($id);
        if ($result && $row) $this->paModel->syncMemberFlag((int)($row['member_id'] ?? 0), 'leadership_113');
        header('Location: index.php?action=leadership113&notif=' . ($result ? 'delete' : 'error'));
        exit();
    }

    /**
     * Converts POST session_dates[] + session_statuses[] arrays into
     * a JSON extra_data string expected by ProgramAttendance::add/update.
     */
    private static array $PROGRAM_LABELS = [
        'victory_weekend'    => 'Victory Weekend',
        'church_community'   => 'Church Community',
        'making_disciples'   => 'Making Disciples',
        'empowering_leaders' => 'Empowering Leaders',
        'leadership_113'     => 'Leadership 113',
    ];

    private function buildL113ExtraData(array $data, ?int $existingId = null): array {
        // Use submitted program_type; default to leadership_113 if missing
        if (empty($data['program_type'])) {
            $data['program_type'] = 'leadership_113';
        }
        // Keep program_label in sync with the class type
        $data['program_label'] = self::$PROGRAM_LABELS[$data['program_type']] ?? $data['program_type'];

        $dates    = $data['session_dates']    ?? [];
        $statuses = $data['session_statuses'] ?? [];

        // batch_label is now its own column. Pass it through if the form sent one.
        if (array_key_exists('l113_batch', $data)) {
            $data['batch_label'] = trim($data['l113_batch'] ?? '');
        }

        if (!empty($dates) && is_array($dates)) {
            $sessions = [];
            foreach ($dates as $i => $d) {
                $d = trim($d);
                if ($d === '') continue;
                $sessions[$d] = $statuses[$i] ?? 'P';
            }
            $attended = 0;
            $totalSessions = 0;
            $attendedVals = ['P', 'L', 'MUSIC SUMMIT', 'KIDS SUMMIT'];
            $noClassVals  = ['NO CLASS', 'HOLY WEEK', 'DC 2023', 'NC'];
            foreach ($sessions as $s) {
                $sUp = strtoupper(trim($s));
                if (in_array($sUp, $noClassVals)) continue;
                $totalSessions++;
                if (in_array($sUp, array_map('strtoupper', $attendedVals))) $attended++;
            }
            $data['l113_extra'] = json_encode([
                'sessions'       => $sessions,
                'attended'       => $attended,
                'total_sessions' => $totalSessions,
            ]);
        } elseif ($existingId) {
            // No sessions submitted — preserve existing extra_data minus legacy batch key.
            $existing = $this->paModel->getById($existingId);
            if ($existing && $existing['extra_data']) {
                $old = json_decode($existing['extra_data'], true) ?? [];
                unset($old['batch'], $old['remarks']);
                $data['l113_extra'] = json_encode($old);
            }
        }

        unset($data['session_dates'], $data['session_statuses'], $data['l113_batch']);
        return $data;
    }
}
?>
