<?php
require_once 'models/MemberImport.php';

/**
 * Staged member import:
 *   UPLOAD -> PARSE -> VALIDATE -> PREVIEW -> ADMIN CONFIRMATION -> IMPORT
 *
 * Each step is its own route, so the admin can stop at the preview and walk
 * away without having changed anything.
 */
class MemberImportController {
    private $db;
    private $model;

    private const MAX_BYTES = 5 * 1024 * 1024;   // matches .htaccess upload_max_filesize
    private const ALLOWED   = ['xlsx', 'xls', 'csv'];

    public function __construct($db) {
        $this->db    = $db;
        $this->model = new MemberImport($db);
    }

    /** Step 1 — the upload form, plus the preview when a batch is in progress. */
    public function showUpload(): void {
        $batchId = trim($_GET['batch'] ?? '');
        $rows = []; $summary = null;
        if ($batchId !== '') {
            $rows    = $this->model->getBatch($batchId);
            $summary = $this->model->getSummary($batchId);
            if (!$rows) { $batchId = ''; $summary = null; }
        }
        $error  = $_GET['msg']  ?? '';
        $notice = $_GET['done'] ?? '';
        include 'views/member_import.php';
    }

    /** Step 2 — parse + validate + stage. Still writes nothing to `members`. */
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
            $this->fail('Please upload an Excel file (.xlsx or .xls) or a .csv file.');
        }
        // A browser-supplied path must never be trusted as a readable location.
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->fail('The upload could not be verified. Please try again.');
        }

        // The temp file has no extension; the original name carries it.
        $parsed = $this->model->parseFile($file['tmp_name'], (string)$file['name']);
        if ($parsed['error'])      $this->fail($parsed['error']);
        if (empty($parsed['rows'])) $this->fail('No member rows were found in that file.');

        $batchId = $this->model->stage($parsed['rows']);
        header('Location: index.php?action=memberImport&batch=' . urlencode($batchId));
        exit();
    }

    /** Step 3 — the admin confirmed. This is the only step that creates members. */
    public function commit(): void {
        $batchId = trim($_POST['batch_id'] ?? '');
        if ($batchId === '') $this->fail('That import session has expired. Please upload the file again.');

        // Rows the admin individually ticked, on top of the automatically-ready ones.
        $approved = $_POST['approve'] ?? [];
        $approved = is_array($approved) ? array_map('intval', $approved) : [];

        $result = $this->model->commit($batchId, $approved);

        $msg = $result['created'] . ' member' . ($result['created'] === 1 ? '' : 's') . ' added.';
        if ($result['skipped'] > 0) $msg .= ' ' . $result['skipped'] . ' row(s) skipped.';
        if (!empty($result['errors'])) $msg .= ' ' . count($result['errors']) . ' row(s) failed.';

        header('Location: index.php?action=memberImport&batch=' . urlencode($batchId)
             . '&done=' . urlencode($msg));
        exit();
    }

    public function discard(): void {
        $batchId = trim($_GET['batch'] ?? '');
        if ($batchId !== '') $this->model->discardBatch($batchId);
        header('Location: index.php?action=memberImport');
        exit();
    }

    /** Downloadable CSV of everything that needs a human decision. */
    public function downloadReview(): void {
        $batchId = trim($_GET['batch'] ?? '');
        if ($batchId === '') { header('Location: index.php?action=memberImport'); exit(); }
        $csv = $this->model->buildReviewCsv($batchId);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="member-import-review.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit();
    }

    private function fail(string $msg): void {
        header('Location: index.php?action=memberImport&msg=' . urlencode($msg));
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
