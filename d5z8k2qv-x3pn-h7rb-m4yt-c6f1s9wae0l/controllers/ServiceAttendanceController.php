<?php
require_once 'models/ServiceAttendance.php';

/**
 * Sunday Service Attendance (Church Health) — list / add / edit / delete.
 *
 * Follows the VictoryGroupController shape: the controller gathers, the model
 * decides, and every write ends in a redirect carrying ?notif= or ?error=&msg=
 * so a refresh can never re-submit the form.
 */
class ServiceAttendanceController
{
    private $db;
    private $model;

    public function __construct($db)
    {
        $this->db    = $db;
        $this->model = new ServiceAttendance($db);
    }

    public function listRecords(): void
    {
        $filters = [];
        if (!empty($_GET['date_from']))    $filters['date_from']    = sanitizeInput($_GET['date_from']);
        if (!empty($_GET['date_to']))      $filters['date_to']      = sanitizeInput($_GET['date_to']);
        if (!empty($_GET['service_name'])) $filters['service_name'] = sanitizeInput($_GET['service_name']);

        $records       = $this->model->getAll($filters);
        $saStats       = $this->model->getStats($filters);
        $saLatest      = $this->model->getLatestSunday($filters);
        $saByService   = $this->model->getByServiceName($filters);
        $serviceTimes  = $this->model->getServiceTimeOptions();
        $recordedTimes = $this->model->getDistinctServiceNames();
        $activeFilters = $filters;
        // Set when a duplicate save bounced back — the view pre-opens the Edit
        // modal on the row that already owns the slot.
        $saEditId      = (int)($_GET['edit'] ?? 0);
        include 'views/service_attendance.php';
    }

    public function addRecord(array $data): void
    {
        $result = $this->model->add($data, (int)($_SESSION['user']['id'] ?? 0) ?: null);
        if (!empty($result['ok'])) {
            $this->redirect('add');
        }
        $this->redirectError($result['error'] ?? 'Failed to add the entry.', $result['existing_id'] ?? 0);
    }

    public function updateRecord(int $id, array $data): void
    {
        $result = $this->model->update($id, $data);
        if (!empty($result['ok'])) {
            $this->redirect('update');
        }
        $this->redirectError($result['error'] ?? 'Failed to update the entry.', $result['existing_id'] ?? 0);
    }

    public function deleteRecord(int $id): void
    {
        $this->redirect($this->model->delete($id) ? 'delete' : 'error');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Keeps the current filter on the URL so a save doesn't reset the view. */
    private function filterQuery(): string
    {
        $keep = [];
        foreach (['date_from', 'date_to', 'service_name'] as $k) {
            if (!empty($_GET[$k])) $keep[$k] = $_GET[$k];
            elseif (!empty($_POST[$k . '_filter'])) $keep[$k] = $_POST[$k . '_filter'];
        }
        return $keep ? '&' . http_build_query($keep) : '';
    }

    private function redirect(string $notif): void
    {
        header('Location: index.php?action=serviceAttendance' . $this->filterQuery() . '&notif=' . $notif);
        exit();
    }

    /**
     * On a duplicate, $existingId is the row already holding that Date +
     * Service Time; passing it as &edit= makes the records page open that
     * entry's Edit modal, which is the "edit the existing one instead" offer.
     */
    private function redirectError(string $msg, int $existingId = 0): void
    {
        $url = 'index.php?action=serviceAttendance' . $this->filterQuery()
             . '&error=1&msg=' . urlencode($msg);
        if ($existingId > 0) $url .= '&edit=' . $existingId;
        header('Location: ' . $url);
        exit();
    }
}
?>
