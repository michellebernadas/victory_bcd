<?php
require_once 'models/FacilityRequestImport.php';

/**
 * Staged Facilities Request Form import:
 *   UPLOAD -> PARSE -> VALIDATE -> PREVIEW -> ADMIN CONFIRMATION -> IMPORT
 *
 * Each step is its own route, exactly like MemberImportController, so the admin
 * can stop at the preview and walk away without having changed anything.
 */
class FacilityImportController {
    private $db;
    private $model;

    private const MAX_BYTES = 5 * 1024 * 1024;   // matches .htaccess upload_max_filesize
    // xlsx / xls: either format. csv: only the one-row-per-request template
    // (a CSV has one sheet, so it cannot hold the one-sheet-per-request form).
    private const ALLOWED   = ['xlsx', 'xls', 'csv'];

    public function __construct($db) {
        $this->db    = $db;
        $this->model = new FacilityRequestImport($db);
    }

    /** Step 1 — the upload form, plus the preview when a batch is in progress. */
    public function showUpload(): void {
        $batchId = trim($_GET['batch'] ?? '');
        $rows = []; $summary = null;
        if ($batchId !== '') {
            $rows    = $this->model->getBatch($batchId);
            $summary = $rows ? $this->model->getSummary($batchId) : null;
            if (!$rows) $batchId = '';
        }
        $skippedSheets = isset($_SESSION['fri_skipped'][$batchId])
            ? $_SESSION['fri_skipped'][$batchId] : [];
        $error  = $_GET['msg']  ?? '';
        $notice = $_GET['done'] ?? '';
        include 'views/facility_import.php';
    }

    /** Step 2 — parse + validate + stage. Still writes nothing to facility_requests. */
    public function upload(): void {
        $file = $_FILES['import_file'] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail($this->uploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE));
        }
        if ($file['size'] > self::MAX_BYTES) {
            $this->fail('That file is larger than 5 MB. Please split it into smaller files.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED, true)) {
            $this->fail('Please upload an Excel file (.xlsx or .xls) — either the church\'s request-form workbook '
                      . 'or the downloadable one-row-per-request template (which may also be saved as .csv).');
        }
        // A browser-supplied path must never be trusted as a readable location.
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->fail('The upload could not be verified. Please try again.');
        }

        // The temp file has no extension; the original name carries it.
        $parsed = $this->model->parseFile($file['tmp_name'], (string)$file['name']);
        if ($parsed['error'])        $this->fail($parsed['error']);
        if (empty($parsed['forms'])) $this->fail('No filled-in request forms were found in that file.');

        $batchId = $this->model->stage($parsed['forms']);

        // Which sheets were ignored and why — shown once on the preview so the
        // admin can see nothing was quietly dropped.
        $_SESSION['fri_skipped'] = [$batchId => $parsed['skipped']];

        header('Location: index.php?action=facilityImport&batch=' . urlencode($batchId));
        exit();
    }

    /** Step 3 — the admin confirmed. This is the only step that creates requests. */
    public function commit(): void {
        $batchId = trim($_POST['batch_id'] ?? '');
        if ($batchId === '') $this->fail('That import session has expired. Please upload the file again.');

        // Every importable row is ticked by default; this is what the admin UNticked.
        $keep = $_POST['import'] ?? [];
        $keep = is_array($keep) ? array_flip(array_map('intval', $keep)) : [];
        $skip = [];
        foreach ($this->model->getBatch($batchId) as $row) {
            if (!isset($keep[(int)$row['id']])) $skip[] = (int)$row['id'];
        }

        $performedBy = $_SESSION['user']['id'] ?? null;
        $result = $this->model->commit($batchId, $skip, $performedBy);

        $msg = $result['created'] . ' request' . ($result['created'] === 1 ? '' : 's')
             . ' imported as Submitted.';
        if ($result['reviewed'] > 0) {
            $msg .= ' ' . $result['reviewed'] . ' then moved on by the Facilities review recorded on the sheet.';
        }
        $msg .= ' None were approved or confirmed.';
        if ($result['skipped'] > 0)    $msg .= ' ' . $result['skipped'] . ' sheet(s) or row(s) skipped.';
        if (!empty($result['errors'])) $msg .= ' ' . count($result['errors']) . ' sheet(s) or row(s) had a problem.';

        header('Location: index.php?action=facilityImport&batch=' . urlencode($batchId)
             . '&done=' . urlencode($msg));
        exit();
    }

    /**
     * The one-row-per-request template, generated now so its dropdowns carry
     * the live room and ministry lists. Writes nothing.
     */
    public function downloadTemplate(): void {
        require_once 'models/FacilityImportTemplate.php';
        try {
            $book = (new FacilityImportTemplate($this->db))->build();
        } catch (\Throwable $e) {
            error_log('FacilityImportController::downloadTemplate: ' . $e->getMessage());
            $this->fail('The template could not be generated. Please try again.');
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="facility-requests-import-template.xlsx"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
        exit();
    }

    public function discard(): void {
        $batchId = trim($_GET['batch'] ?? '');
        if ($batchId !== '') $this->model->discardBatch($batchId);
        unset($_SESSION['fri_skipped']);
        header('Location: index.php?action=facilityImport');
        exit();
    }

    private function fail(string $msg): void {
        header('Location: index.php?action=facilityImport&msg=' . urlencode($msg));
        exit();
    }

    /** PHP's upload error codes, in words an admin can act on. */
    private function uploadErrorMessage(int $code): string {
        switch ($code) {
            case UPLOAD_ERR_NO_FILE:   return 'Please choose a file to upload.';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: return 'That file is too large. The limit is 5 MB.';
            case UPLOAD_ERR_PARTIAL:   return 'The file was only partly uploaded. Please try again.';
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:return 'The server could not save the file. Please contact support.';
            default:                   return 'The file could not be uploaded. Please try again.';
        }
    }
}
