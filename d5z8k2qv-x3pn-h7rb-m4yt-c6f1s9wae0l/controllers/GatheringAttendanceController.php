<?php
require_once 'models/GatheringAttendance.php';

/**
 * Prayer Meeting & Students' Hangout attendance — list / add / edit / delete.
 * Same shape as ServiceAttendanceController: every write ends in a redirect
 * carrying &notif= or &error=&msg=, so a refresh never re-submits a form.
 * Gated exactly like Sunday attendance (any logged-in account) in index.php.
 */
class GatheringAttendanceController
{
    private $db;
    private $model;

    public function __construct($db)
    {
        $this->db    = $db;
        $this->model = new GatheringAttendance($db);
    }

    public function listRecords(): void
    {
        $filters = $this->currentFilters();
        $records       = $this->model->getAll($filters);
        $gaStats       = $this->model->getStatsByType($filters);
        $activeFilters = $filters;
        $gaEditId      = (int)($_GET['edit'] ?? 0);
        include 'views/gathering_attendance.php';
    }

    public function addRecord(array $data): void
    {
        $r = $this->model->add($data, (int)($_SESSION['user']['id'] ?? 0) ?: null);
        if (!empty($r['ok'])) $this->redirect('add');
        $this->redirectError($r['error'] ?? 'Failed to add the entry.', (int)($r['existing_id'] ?? 0));
    }

    public function updateRecord(int $id, array $data): void
    {
        $r = $this->model->update($id, $data);
        if (!empty($r['ok'])) $this->redirect('update');
        $this->redirectError($r['error'] ?? 'Failed to update the entry.', (int)($r['existing_id'] ?? 0));
    }

    public function deleteRecord(int $id): void
    {
        $this->redirect($this->model->delete($id) ? 'delete' : 'error');
    }

    private function currentFilters(): array
    {
        $f = [];
        $type = (string)($_GET['gathering_type'] ?? '');
        if (GatheringAttendance::isType($type)) $f['gathering_type'] = $type;
        foreach (['date_from', 'date_to'] as $k) {
            $v = (string)($_GET[$k] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) $f[$k] = $v;
        }
        return $f;
    }

    private function filterQuery(): string
    {
        $f = $this->currentFilters();
        return $f ? '&' . http_build_query($f) : '';
    }

    private function redirect(string $notif): void
    {
        header('Location: index.php?action=gatheringAttendance' . $this->filterQuery() . '&notif=' . $notif);
        exit();
    }

    private function redirectError(string $msg, int $existingId = 0): void
    {
        $url = 'index.php?action=gatheringAttendance' . $this->filterQuery() . '&error=1&msg=' . urlencode($msg);
        if ($existingId > 0) $url .= '&edit=' . $existingId;
        header('Location: ' . $url);
        exit();
    }
}
?>
