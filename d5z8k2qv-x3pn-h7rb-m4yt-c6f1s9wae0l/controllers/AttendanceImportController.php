<?php
require_once 'models/AttendanceImport.php';

/**
 * Staged import of the Worship Service Annual Attendance workbook:
 *   UPLOAD -> PARSE -> PREVIEW -> ADMIN CONFIRM -> IMPORT
 * Admin-only (gated in index.php, like the other importers). Upload writes
 * only to attendance_import_staging; Confirm is the one step that adds
 * attendance rows, and it never overwrites what is already in the portal.
 */
class AttendanceImportController
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private $db;
    private $model;

    public function __construct($db)
    {
        $this->db    = $db;
        $this->model = new AttendanceImport($db);
    }

    public function showUpload(): void
    {
        $batchId = trim((string)($_GET['batch'] ?? ''));
        $rows = $batchId !== '' ? $this->model->getBatch($batchId) : [];
        if (!$rows) $batchId = '';
        $meta    = $batchId !== '' ? ($_SESSION['att_import'][$batchId] ?? ['blocks' => [], 'notes' => [], 'file' => '']) : null;
        $summary = $rows ? $this->model->getSummary($rows) : null;
        $check   = $rows ? AttendanceImport::crossCheck($meta['blocks'] ?? [], $rows) : [];
        $error   = (string)($_GET['msg'] ?? '');
        $notice  = (string)($_GET['done'] ?? '');
        include 'views/attendance_import.php';
    }

    public function upload(): void
    {
        $f = $_FILES['import_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $this->fail('Please choose the workbook to upload.');
        if ($f['size'] > self::MAX_BYTES) $this->fail('That file is larger than 5 MB.');
        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') $this->fail('Please upload the .xlsx workbook.');
        if (!is_uploaded_file($f['tmp_name'])) $this->fail('The upload could not be verified. Please try again.');

        $year = preg_match('/^\d{4}$/', (string)($_POST['sheet_year'] ?? '')) ? $_POST['sheet_year'] : '2026';
        $p = $this->model->parseFile($f['tmp_name'], (string)$f['name'], $year);
        if ($p['error']) $this->fail($p['error']);
        if (!$p['entries']) $this->fail('No attendance counts were found on the ' . $year . ' sheet.');
        $batch = $this->model->stage($p['entries'], $year);
        if ($batch === '') $this->fail('The file was read but could not be staged. Please try again.');
        $_SESSION['att_import'] = [$batch => ['blocks' => $p['blocks'], 'notes' => $p['notes'],
                                              'file' => (string)$f['name'], 'year' => $year]];
        header('Location: index.php?action=attendanceImport&batch=' . urlencode($batch));
        exit();
    }

    public function commit(): void
    {
        $batch = trim((string)($_POST['batch_id'] ?? ''));
        if ($batch === '') $this->fail('That import session has expired. Please upload the file again.');
        $ids = is_array($_POST['import'] ?? null) ? $_POST['import'] : [];
        $r = $this->model->commit($batch, $ids, (int)($_SESSION['user']['id'] ?? 0) ?: null);
        unset($_SESSION['att_import']);
        if ($r['error']) $this->fail($r['error']);
        $msg = sprintf('Imported %d Sunday service-time entr%s, %d Prayer Meeting%s and %d Students\' Hangout%s.',
            $r['sunday'], $r['sunday'] === 1 ? 'y' : 'ies',
            $r['prayer_meeting'], $r['prayer_meeting'] === 1 ? '' : 's',
            $r['students_hangout'], $r['students_hangout'] === 1 ? '' : 's');
        if ($r['kept'] > 0) $msg .= ' ' . $r['kept'] . ' already in the portal were left unchanged.';
        header('Location: index.php?action=attendanceImport&done=' . urlencode($msg));
        exit();
    }

    public function discard(): void
    {
        $batch = trim((string)($_GET['batch'] ?? ''));
        if ($batch !== '') $this->model->discard($batch);
        unset($_SESSION['att_import']);
        header('Location: index.php?action=attendanceImport');
        exit();
    }

    private function fail(string $msg): void
    {
        header('Location: index.php?action=attendanceImport&msg=' . urlencode($msg));
        exit();
    }
}
?>
