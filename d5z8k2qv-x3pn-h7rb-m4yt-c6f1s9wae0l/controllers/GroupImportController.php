<?php
require_once 'models/GroupImport.php';

/**
 * Staged Staff VG Schedule import:
 *   UPLOAD -> PARSE -> VALIDATE -> PREVIEW -> ADMIN CONFIRMATION -> IMPORT
 *
 * Each step is its own route, exactly like MemberImportController, so the admin
 * can stop at the preview and walk away without having changed anything.
 */
class GroupImportController {
    private $db;
    private $model;

    private const MAX_BYTES = 5 * 1024 * 1024;   // matches .htaccess upload_max_filesize
    private const ALLOWED   = ['xlsx', 'xls'];   // the schedule is a grid; CSV loses the layout

    public function __construct($db) {
        $this->db    = $db;
        $this->model = new GroupImport($db);
    }

    /** Step 1 — the upload form, plus the preview when a batch is in progress. */
    public function showUpload(): void {
        $batchId = trim($_GET['batch'] ?? '');
        $cards = []; $summary = null;
        if ($batchId !== '') {
            $cards   = $this->model->getBatch($batchId);
            $summary = $cards ? $this->model->getSummary($batchId) : null;
            if (!$cards) $batchId = '';
        }
        $error  = $_GET['msg']  ?? '';
        $notice = $_GET['done'] ?? '';
        include 'views/group_import.php';
    }

    /** Step 2 — parse + validate + stage. Still writes nothing to victory_groups. */
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
            $this->fail('Please upload the schedule as an Excel file (.xlsx or .xls). '
                      . 'A CSV loses the card layout the schedule is laid out in.');
        }
        // A browser-supplied path must never be trusted as a readable location.
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->fail('The upload could not be verified. Please try again.');
        }

        // The temp file has no extension; the original name carries it.
        $parsed = $this->model->parseFile($file['tmp_name'], (string)$file['name']);
        if ($parsed['error'])        $this->fail($parsed['error']);
        if (empty($parsed['cards'])) $this->fail('No group cards were found in that file.');

        $batchId = $this->model->stage($parsed['cards']);
        header('Location: index.php?action=groupImport&batch=' . urlencode($batchId));
        exit();
    }

    /** Step 3 — the admin confirmed. This is the only step that creates groups. */
    public function commit(): void {
        $batchId = trim($_POST['batch_id'] ?? '');
        if ($batchId === '') $this->fail('That import session has expired. Please upload the file again.');

        // Every importable card is ticked by default; this is what the admin UNticked.
        $keep = $_POST['import'] ?? [];
        $keep = is_array($keep) ? array_flip(array_map('intval', $keep)) : [];
        $skip = [];
        foreach ($this->model->getBatch($batchId) as $card) {
            if (!isset($keep[(int)$card['id']])) $skip[] = (int)$card['id'];
        }

        $result = $this->model->commit($batchId, $skip);

        $msg = $result['created'] . ' group' . ($result['created'] === 1 ? '' : 's') . ' created with '
             . $result['people'] . ' ' . ($result['people'] === 1 ? 'person' : 'people') . '.';
        if ($result['needs_link'] > 0) {
            $msg .= ' ' . $result['needs_link'] . ' still need linking to a member — no members were created.';
        }
        if ($result['skipped'] > 0)     $msg .= ' ' . $result['skipped'] . ' card(s) skipped.';
        if (!empty($result['errors']))  $msg .= ' ' . count($result['errors']) . ' card(s) failed.';

        header('Location: index.php?action=groupImport&batch=' . urlencode($batchId)
             . '&done=' . urlencode($msg));
        exit();
    }

    public function discard(): void {
        $batchId = trim($_GET['batch'] ?? '');
        if ($batchId !== '') $this->model->discardBatch($batchId);
        header('Location: index.php?action=groupImport');
        exit();
    }

    /** Downloadable CSV of every name that will land as Needs Link. */
    public function downloadReview(): void {
        $batchId = trim($_GET['batch'] ?? '');
        if ($batchId === '') { header('Location: index.php?action=groupImport'); exit(); }
        $csv = $this->model->buildReviewCsv($batchId);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="vg-schedule-needs-link.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit();
    }

    private function fail(string $msg): void {
        header('Location: index.php?action=groupImport&msg=' . urlencode($msg));
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
