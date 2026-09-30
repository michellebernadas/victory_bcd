<?php
require_once 'models/ProgramAttendance.php';
require_once 'models/Member.php';

/**
 * Shared controller for "session classes" — discipleship classes whose
 * attendance is a per-session grid stored in program_attendances.extra_data
 * (Leadership 1-1-3 and Spiritual Foundations today).
 *
 * Subclasses only declare what differs: the program_type, the route action
 * name, the display label and the expected number of sessions. Everything
 * else — filtering, extra_data assembly, member-flag syncing, redirects — is
 * identical and lives here so the two classes can't drift apart.
 */
abstract class SessionClassController {
    protected $db;
    protected $paModel;

    /** program_attendances.program_type handled by this controller. */
    protected string $programType = '';
    /** index.php?action=<route> used for redirects and filter links. */
    protected string $route = '';
    /** Human label used in notifications and program_label. */
    protected string $label = '';
    /** Expected number of class sessions; 0 = "whatever the grid contains". */
    protected int $expectedSessions = 0;
    /** View file rendered by listRecords(). */
    protected string $view = '';

    public function __construct($db) {
        $this->db      = $db;
        $this->paModel = new ProgramAttendance($db);
    }

    /** Hook for subclasses that need extra view data (e.g. the SF curriculum). */
    protected function extraViewData(): array { return []; }

    public function listRecords(): void {
        $filters = [
            'program_type'    => $this->programType,
            'program_year'    => $_GET['year']            ?? '',
            'search'          => $_GET['search']          ?? '',
            'event_date_from' => $_GET['event_date_from'] ?? '',
            'event_date_to'   => $_GET['event_date_to']   ?? '',
        ];
        $records        = $this->paModel->getFiltered($filters);
        $availableYears = $this->paModel->getAvailableYearsByType($this->programType);
        $batchList      = $this->paModel->getDistinctBatchesByType($this->programType);
        $scBatchNames   = $this->paModel->getDistinctBatchNamesByType($this->programType);
        $scStatuses     = $this->paModel->getDistinctSessionStatuses($this->programType);
        $scSessionKeys  = $this->paModel->getDistinctSessionKeys($this->programType);
        $paStats        = $this->paModel->getSummaryStats();

        $availableEventDates = [];
        if (!empty($filters['program_year'])) {
            $availableEventDates = $this->paModel->getDistinctEventDatesByTypeAndYear(
                $this->programType, (int)$filters['program_year']
            );
        }

        $scRoute          = $this->route;
        $scLabel          = $this->label;
        $scProgramType    = $this->programType;
        $scExpectedCount  = $this->expectedSessions;
        $activeYear       = $filters['program_year'];
        $activeBatch      = $_GET['batch'] ?? '';
        $activeSearch     = $filters['search'];
        $activeDateFrom   = $filters['event_date_from'];
        $activeDateTo     = $filters['event_date_to'];
        // Topic / session filter — a session key (date) from the stored grid, or ''.
        $activeSession    = trim($_GET['session'] ?? '');
        // Match-status filter persists across page navigation via the `match` URL parameter.
        $activeMatch      = in_array($_GET['match'] ?? '', ['matched', 'unmatched'], true) ? $_GET['match'] : 'all';

        // Back-compat aliases so the existing Leadership 1-1-3 view keeps working unchanged.
        $l113BatchNames = $scBatchNames;
        $l113Statuses   = $scStatuses;

        extract($this->extraViewData(), EXTR_OVERWRITE);
        include $this->view;
    }

    public function addRecord(array $data): void {
        $data   = $this->buildSessionExtraData($data);
        $result = $this->paModel->add($data);
        if ($result) {
            $this->paModel->syncMemberFlag((int)($data['member_id'] ?? 0), (string)($data['program_type'] ?? $this->programType));
        }
        $this->redirect($result ? 'add' : 'error');
    }

    public function updateRecord(int $id, array $data): void {
        $oldRow = $this->paModel->getById($id);
        $data   = $this->buildSessionExtraData($data, $id);
        $result = $this->paModel->update($id, $data);
        if ($result) {
            $newPt = (string)($data['program_type'] ?? $this->programType);
            $this->paModel->syncMemberFlag((int)($data['member_id'] ?? 0), $newPt);
            // The row may have moved to another member and/or another class — resync the old pair too.
            if ($oldRow) {
                $oldMember = (int)($oldRow['member_id'] ?? 0);
                $oldPt     = (string)($oldRow['program_type'] ?? '');
                if ($oldMember && ($oldMember !== (int)($data['member_id'] ?? 0) || $oldPt !== $newPt)) {
                    $this->paModel->syncMemberFlag($oldMember, $oldPt);
                }
            }
        }
        $this->redirect($result ? 'update' : 'error');
    }

    public function deactivateRecord(int $id): void {
        $result = $this->paModel->updateStatus($id, 'inactive');
        if ($result) $this->paModel->syncMemberFlagFromRecord($id);
        $this->redirect($result ? 'deactivate' : 'error');
    }

    public function activateRecord(int $id): void {
        $result = $this->paModel->updateStatus($id, 'active');
        if ($result) $this->paModel->syncMemberFlagFromRecord($id);
        $this->redirect($result ? 'activate' : 'error');
    }

    public function deleteRecord(int $id): void {
        $row    = $this->paModel->getById($id);
        $result = $this->paModel->hardDelete($id);
        if ($result && $row) {
            $this->paModel->syncMemberFlag((int)($row['member_id'] ?? 0), (string)($row['program_type'] ?? $this->programType));
        }
        $this->redirect($result ? 'delete' : 'error');
    }

    protected function redirect(string $notif): void {
        header('Location: index.php?action=' . $this->route . '&notif=' . $notif);
        exit();
    }

    /**
     * Converts POST session_dates[] + session_statuses[] into the JSON
     * extra_data string ProgramAttendance::add/update expects:
     *   {"sessions":{"<date>":"<status>",...},"attended":N,"total_sessions":N}
     *
     * Attended / no-class classification is shared (ProgramAttendance constants)
     * so the Certificate button and the completion badges always agree.
     */
    protected function buildSessionExtraData(array $data, ?int $existingId = null): array {
        if (empty($data['program_type'])) {
            $data['program_type'] = $this->programType;
        }
        $data['program_label'] = ProgramAttendance::PROGRAM_LABELS[$data['program_type']] ?? $data['program_type'];

        // batch_label has its own column; the form posts it as `sc_batch` (legacy: `l113_batch`).
        foreach (['sc_batch', 'l113_batch'] as $batchKey) {
            if (array_key_exists($batchKey, $data)) {
                $data['batch_label'] = trim($data[$batchKey] ?? '');
                break;
            }
        }

        $dates    = $data['session_dates']    ?? [];
        $statuses = $data['session_statuses'] ?? [];

        if (!empty($dates) && is_array($dates)) {
            $sessions = [];
            foreach ($dates as $i => $d) {
                $d = trim((string)$d);
                if ($d === '') continue;
                $sessions[$d] = $statuses[$i] ?? 'P';
            }
            $stats = ProgramAttendance::sessionStats(['sessions' => $sessions]);
            $data['l113_extra'] = json_encode([
                'sessions'       => $sessions,
                'attended'       => $stats['attended'],
                'total_sessions' => $stats['required'],
            ]);
        } elseif ($existingId) {
            // No sessions submitted — preserve existing extra_data minus legacy keys.
            $existing = $this->paModel->getById($existingId);
            if ($existing && $existing['extra_data']) {
                $old = json_decode($existing['extra_data'], true) ?? [];
                unset($old['batch'], $old['remarks']);
                $data['l113_extra'] = json_encode($old);
            }
        }

        unset($data['session_dates'], $data['session_statuses'], $data['sc_batch'], $data['l113_batch']);
        return $data;
    }
}
?>
