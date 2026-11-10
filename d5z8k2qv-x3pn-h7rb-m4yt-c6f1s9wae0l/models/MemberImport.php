<?php
require_once 'models/Member.php';

/**
 * Safe, staged member import.
 *
 *     UPLOAD -> PARSE -> VALIDATE -> PREVIEW -> ADMIN CONFIRMATION -> IMPORT
 *
 * Nothing is written to `members` until an admin has seen the preview and
 * explicitly confirmed it. Parsed rows live in `member_imports` until then, so
 * a bad spreadsheet costs a review, not a cleanup.
 *
 * The importer this replaces inserted on upload, with no preview and no login
 * required, and cleared whole tables before writing.
 *
 * DUPLICATE DETECTION — deliberately conservative
 * Only three signals, all exact after normalisation:
 *   1. email, lower-cased            (strongest, when present)
 *   2. normalised first + last name  (case/space/punctuation-insensitive)
 *   3. the canonical "Last, First"   (what members.full_name already uniquely holds)
 * No fuzzy or phonetic matching. In a church two real people genuinely are
 * called "Maria Santos", and silently merging them is far worse than asking.
 * Everything found is a flag for a human, never an automatic merge.
 */
class MemberImport {
    private $db;

    /** Header aliases → canonical field. Lower-cased, punctuation-stripped. */
    private const HEADER_MAP = [
        'firstname'        => 'first_name',  'first'          => 'first_name',
        'givenname'        => 'first_name',  'fname'          => 'first_name',
        'lastname'         => 'last_name',   'last'           => 'last_name',
        'surname'          => 'last_name',   'familyname'     => 'last_name',
        'lname'            => 'last_name',
        'fullname'         => 'full_name',   'name'           => 'full_name',
        'email'            => 'email',       'emailaddress'   => 'email',
        'contactnumber'    => 'contact_number', 'contact'     => 'contact_number',
        'mobile'           => 'contact_number', 'phone'       => 'contact_number',
        'mobilenumber'     => 'contact_number', 'cellphone'   => 'contact_number',
        'facebook'         => 'facebook_url', 'facebookurl'   => 'facebook_url',
        'facebookprofile'  => 'facebook_url', 'fb'            => 'facebook_url',
        'ministry'         => 'ministry',    'department'     => 'ministry',
        'service'          => 'service_attending',
        'serviceattending' => 'service_attending',
        'lifestage'        => 'civil_status', 'civilstatus'   => 'civil_status',
        'status'           => 'member_status', 'memberstatus' => 'member_status',
        'notes'            => 'notes',       'remarks'        => 'notes',
    ];

    public function __construct($db) { $this->db = $db; }

    // ── Normalisation ───────────────────────────────────────────────────────

    /** Collapses whitespace; keeps the name otherwise exactly as written. */
    public static function cleanName(?string $v): string {
        $v = preg_replace('/\s+/u', ' ', trim((string)$v));
        return $v === null ? '' : $v;
    }

    /**
     * Comparison key for duplicate detection — NOT for storage.
     * Case-folded, accent-folded, punctuation-stripped, so "O'Brien",
     * "o brien" and "OBRIEN" collide, and "José" matches "Jose".
     */
    public static function nameKey(?string $v): string {
        $v = mb_strtolower(self::cleanName($v), 'UTF-8');
        if (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
            if ($folded !== false) $v = $folded;
        }
        return preg_replace('/[^a-z0-9]/', '', $v) ?? '';
    }

    /**
     * Flags names that look malformed. WARNINGS ONLY — a legitimate Filipino or
     * Spanish name must never be rejected. Apostrophes, hyphens, accents,
     * "Ñ", and compound surnames ("dela Cruz", "San Juan") are all valid and
     * produce no warning.
     */
    public static function nameWarnings(string $first, string $last): array {
        $out = [];
        foreach (['First name' => $first, 'Last name' => $last] as $label => $val) {
            if ($val === '') continue;
            if (mb_strlen($val) === 1) {
                $out[] = "$label is a single character — check it is not an initial.";
            }
            if (preg_match('/\d/', $val)) {
                $out[] = "$label contains a number.";
            }
            // Letters, spaces, apostrophes (straight and curly), hyphens, periods.
            if (preg_match('/[^\p{L}\p{M}\s\'\x{2019}\-\.]/u', $val)) {
                $out[] = "$label contains unusual characters — please check it.";
            }
            if (mb_strtoupper($val, 'UTF-8') === $val && mb_strlen($val) > 3) {
                $out[] = "$label is in ALL CAPS — it will be saved exactly as written.";
            }
        }
        return $out;
    }

    // ── Parse ───────────────────────────────────────────────────────────────

    /**
     * Open an UPLOADED spreadsheet. Shared by all three staged importers.
     *
     * TWO THINGS GO WRONG WITH A RAW UPLOAD TEMP FILE, AND BOTH ARE FIXED HERE.
     *
     * 1. It has NO EXTENSION. PHP stores an upload as "C:\...\Temp\phpA1B2.tmp",
     *    so neither IOFactory::createReaderForFile() nor the CSV reader's own
     *    canRead() can tell what it is from the name.
     * 2. Their fallback is mime_content_type(), which only exists when
     *    ext-fileinfo is enabled. It is OFF in this XAMPP build, and off by
     *    default on plenty of shared hosts, so the call is a fatal "undefined
     *    function". Every importer caught that and told the admin "That file
     *    could not be opened as a spreadsheet. Please save it as .xlsx" —
     *    advice that could never work, because the file was never the problem.
     *
     * The browser sends the original filename and the controller has already
     * whitelisted its extension, so that extension is the honest, available
     * answer. The upload is copied to a temp file CARRYING that extension,
     * which satisfies the reader's own name-based check as well as ours, and
     * is removed again before this returns. Behaviour is unchanged on a host
     * where fileinfo is enabled; it simply no longer depends on it.
     *
     * @param  string $originalName Filename as uploaded, for its extension.
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     */
    public static function openSpreadsheet(string $path, string $originalName) {
        $map = [
            'csv'  => \PhpOffice\PhpSpreadsheet\IOFactory::READER_CSV,
            'xlsx' => \PhpOffice\PhpSpreadsheet\IOFactory::READER_XLSX,
            'xls'  => \PhpOffice\PhpSpreadsheet\IOFactory::READER_XLS,
        ];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // No usable hint: let PhpSpreadsheet decide, exactly as before.
        if ($originalName === '' || !isset($map[$ext])) {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            return $reader->load($path);
        }

        $named = null;
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== $ext) {
            $candidate = tempnam(sys_get_temp_dir(), 'vbimp') ;
            if ($candidate !== false) {
                $withExt = $candidate . '.' . $ext;
                if (@copy($path, $withExt)) {
                    $named = $withExt;
                }
                @unlink($candidate);
            }
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($map[$ext]);
        $reader->setReadDataOnly(true);
        try {
            return $reader->load($named ?? $path);
        } finally {
            // The copy exists only for the duration of the read.
            if ($named !== null) @unlink($named);
        }
    }

    /**
     * Reads the sheet into rows keyed by canonical field name.
     * Returns ['rows' => [...], 'headers' => [...], 'error' => ?string].
     *
     * @param string $originalName Uploaded filename, so the reader is chosen by
     *                             the extension the admin actually sent.
     */
    public function parseFile(string $path, string $originalName = ''): array {
        if (!is_readable($path)) {
            return ['rows' => [], 'headers' => [], 'error' => 'The uploaded file could not be read.'];
        }
        try {
            $sheet = self::openSpreadsheet($path, $originalName)->getActiveSheet();
            $raw   = $sheet->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            error_log('MemberImport::parseFile: ' . $e->getMessage());
            return ['rows' => [], 'headers' => [],
                    'error' => 'That file could not be opened as a spreadsheet. Please save it as .xlsx and try again.'];
        }

        // Find the header row: the first row mapping at least one known column.
        $headerIdx = -1; $colMap = [];
        foreach ($raw as $i => $row) {
            $map = [];
            foreach ($row as $c => $cell) {
                $key = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$cell)));
                if ($key !== '' && isset(self::HEADER_MAP[$key])) $map[$c] = self::HEADER_MAP[$key];
            }
            // Require a name column so a stray "Email" label can't be mistaken for a header.
            if ($map && array_intersect($map, ['first_name', 'last_name', 'full_name'])) {
                $headerIdx = $i; $colMap = $map; break;
            }
            if ($i > 20) break;   // header is near the top or the file is not a roster
        }
        if ($headerIdx < 0) {
            return ['rows' => [], 'headers' => [],
                    'error' => 'No column headers were found. The sheet needs a header row containing at least '
                             . '"First Name" and "Last Name" (or "Full Name").'];
        }

        $rows = [];
        for ($i = $headerIdx + 1; $i < count($raw); $i++) {
            $rec = [];
            foreach ($colMap as $c => $field) {
                $rec[$field] = self::cleanName($raw[$i][$c] ?? '');
            }
            if (implode('', $rec) === '') continue;      // wholly blank row
            $rec['_row'] = $i + 1;                        // 1-based, matches Excel
            $rows[] = $rec;
        }
        return ['rows' => $rows, 'headers' => array_values($colMap), 'error' => null];
    }

    // ── Validate + stage ────────────────────────────────────────────────────

    /**
     * Validates every row, classifies it, and stores it in member_imports.
     * Returns the batch id. NOTHING is written to `members` here.
     */
    public function stage(array $rows): string {
        $batchId = $this->uuid();

        // One query each for the existing-member indexes, rather than per row.
        $byEmail = []; $byNameKey = [];
        foreach ($this->db->query(
            "SELECT id, full_name, first_name, last_name, email FROM members WHERE is_deleted = 0"
        ) as $m) {
            if (!empty($m['email'])) $byEmail[mb_strtolower(trim($m['email']))] = $m;
            $byNameKey[self::nameKey($m['last_name']) . '|' . self::nameKey($m['first_name'])] = $m;
        }

        // Duplicates WITHIN the uploaded file are their own problem: the file
        // may list the same person twice, and importing both would create the
        // duplicate we are trying to prevent.
        $seenInFile = [];

        $ins = $this->db->prepare(
            "INSERT INTO member_imports
                (batch_id, row_number, raw_data, first_name, last_name, full_name, email,
                 row_status, issues, matched_member_id, match_reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );

        foreach ($rows as $r) {
            [$first, $last] = $this->resolveNames($r);
            $email  = Member::normalizeOptional($r['email'] ?? null);
            $issues = [];
            $status = 'ready';
            $matchId = null; $matchReason = null;

            // --- required fields ---
            if ($last === '' && $first === '') {
                $issues[] = 'No name found in this row.';
                $status = 'invalid';
            } elseif ($last === '') {
                $issues[] = 'Last name is missing.';
                $status = 'invalid';
            } elseif ($first === '') {
                $issues[] = 'First name is missing.';
                $status = 'invalid';
            }

            // --- format checks (warnings, never fatal) ---
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $issues[] = 'Email "' . $email . '" is not a valid address — it will be left blank.';
                $email = null;
                if ($status === 'ready') $status = 'needs_review';
            }
            foreach (self::nameWarnings($first, $last) as $w) {
                $issues[] = $w;
                if ($status === 'ready') $status = 'needs_review';
            }

            $fullName = ($last !== '' && $first !== '') ? "$last, $first" : ($last ?: $first);

            // --- duplicate detection ---
            if ($status !== 'invalid') {
                $nameKey = self::nameKey($last) . '|' . self::nameKey($first);

                if ($email !== null && isset($byEmail[mb_strtolower($email)])) {
                    $hit = $byEmail[mb_strtolower($email)];
                    $matchId = (int)$hit['id']; $matchReason = 'email_exact';
                    $issues[] = 'Same email as existing member "' . $hit['full_name'] . '".';
                    $status = 'existing';
                } elseif (isset($byNameKey[$nameKey])) {
                    $hit = $byNameKey[$nameKey];
                    $matchId = (int)$hit['id']; $matchReason = 'name_normalized';
                    $issues[] = 'Same name as existing member "' . $hit['full_name'] . '".';
                    $status = 'existing';
                } elseif (isset($seenInFile[$nameKey])) {
                    $issues[] = 'This name also appears on row ' . $seenInFile[$nameKey] . ' of this file.';
                    $status = 'duplicate';
                }
                if (!isset($seenInFile[$nameKey])) $seenInFile[$nameKey] = $r['_row'] ?? 0;
            }

            $ins->execute([
                $batchId, (int)($r['_row'] ?? 0), json_encode($r, JSON_UNESCAPED_UNICODE),
                $first ?: null, $last ?: null, $fullName ?: null, $email,
                $status, $issues ? json_encode($issues, JSON_UNESCAPED_UNICODE) : null,
                $matchId, $matchReason,
            ]);
        }
        return $batchId;
    }

    /** Uses first/last when present; otherwise splits a canonical "Last, First". */
    private function resolveNames(array $r): array {
        $first = self::cleanName($r['first_name'] ?? '');
        $last  = self::cleanName($r['last_name']  ?? '');
        if ($first === '' && $last === '') {
            $full = self::cleanName($r['full_name'] ?? '');
            if ($full !== '' && strpos($full, ',') !== false) {
                [$l, $f] = array_pad(explode(',', $full, 2), 2, '');
                $last = self::cleanName($l); $first = self::cleanName($f);
            } elseif ($full !== '') {
                // "Juan Dela Cruz" — last token is rarely right for Filipino
                // compound surnames, so take the FIRST token as the given name
                // and keep the remainder together as the surname.
                $parts = explode(' ', $full);
                $first = array_shift($parts);
                $last  = implode(' ', $parts);
            }
        }
        return [$first, $last];
    }

    // ── Preview ─────────────────────────────────────────────────────────────

    public function getBatch(string $batchId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM member_imports WHERE batch_id = ? ORDER BY row_number"
        );
        $stmt->execute([$batchId]);
        return $stmt->fetchAll();
    }

    /** Counts behind "X will be created, Y need review, Z already exist". */
    public function getSummary(string $batchId): array {
        $stmt = $this->db->prepare(
            "SELECT row_status, COUNT(*) n FROM member_imports WHERE batch_id = ? GROUP BY row_status"
        );
        $stmt->execute([$batchId]);
        $by = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $get = fn($k) => (int)($by[$k] ?? 0);
        return [
            'total'        => array_sum($by),
            'ready'        => $get('ready'),
            'needs_review' => $get('needs_review'),
            'duplicate'    => $get('duplicate'),
            'existing'     => $get('existing'),
            'invalid'      => $get('invalid'),
            // Only 'ready' rows import by default. Review rows need a tick each.
            'will_create'  => $get('ready'),
        ];
    }

    // ── Commit ──────────────────────────────────────────────────────────────

    /**
     * Creates members from the confirmed rows, in ONE transaction.
     *
     * @param string $batchId
     * @param array  $approvedRowIds member_imports.id the admin ticked in
     *                               addition to the automatically-ready rows.
     * @return array ['created' => int, 'skipped' => int, 'errors' => string[]]
     */
    public function commit(string $batchId, array $approvedRowIds = []): array {
        $rows = $this->getBatch($batchId);
        $approved = array_flip(array_map('intval', $approvedRowIds));

        $created = 0; $skipped = 0; $errors = [];
        $memberModel = new Member($this->db);

        $this->db->beginTransaction();
        try {
            $mark = $this->db->prepare(
                "UPDATE member_imports SET created_member_id = ?, imported_at = NOW() WHERE id = ?"
            );
            foreach ($rows as $row) {
                $id = (int)$row['id'];
                // Already imported in an earlier confirmation of this batch —
                // re-confirming must never create a second copy.
                if (!empty($row['created_member_id'])) { $skipped++; continue; }
                // 'invalid' can never be force-imported: there is no name to save.
                if ($row['row_status'] === 'invalid')  { $skipped++; continue; }
                $autoOk = $row['row_status'] === 'ready';
                if (!$autoOk && !isset($approved[$id]))  { $skipped++; continue; }

                $raw = json_decode($row['raw_data'] ?? '{}', true) ?: [];
                $newId = $memberModel->addMember([
                    'first_name'        => $row['first_name'],
                    'last_name'         => $row['last_name'],
                    'email'             => $row['email'],
                    'facebook_url'      => $raw['facebook_url']      ?? null,
                    'contact_number'    => $raw['contact_number']    ?? '',
                    'ministry'          => $raw['ministry']          ?? '',
                    'service_attending' => $raw['service_attending'] ?? '',
                    'civil_status'      => $raw['civil_status']      ?? '',
                    'member_status'     => 'active',
                    'notes'             => $raw['notes']             ?? '',
                ]);
                if ($newId) {
                    $mark->execute([(int)$newId, $id]);
                    $created++;
                } else {
                    $skipped++;
                    $errors[] = 'Row ' . $row['row_number'] . ' (' . $row['full_name'] . ') could not be saved.';
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('MemberImport::commit: ' . $e->getMessage());
            return ['created' => 0, 'skipped' => count($rows),
                    'errors'  => ['The import was cancelled and nothing was saved: ' . $e->getMessage()]];
        }
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** members.id of every row this batch created — for the "connect them" suggestions. */
    public function getCreatedMemberIds(string $batchId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT created_member_id FROM member_imports WHERE batch_id = ? AND created_member_id IS NOT NULL"
            );
            $stmt->execute([$batchId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            error_log('MemberImport::getCreatedMemberIds: ' . $e->getMessage());
            return [];
        }
    }

    public function discardBatch(string $batchId): void {
        $this->db->prepare("DELETE FROM member_imports WHERE batch_id = ? AND created_member_id IS NULL")
                 ->execute([$batchId]);
    }

    /** CSV of every row that needs attention, for the admin to fix offline. */
    public function buildReviewCsv(string $batchId): string {
        $out = "Row,Status,Last Name,First Name,Email,Issues\n";
        foreach ($this->getBatch($batchId) as $r) {
            if (in_array($r['row_status'], ['ready'], true)) continue;
            $issues = implode('; ', json_decode($r['issues'] ?? '[]', true) ?: []);
            $cell = fn($v) => '"' . str_replace('"', '""', (string)$v) . '"';
            $out .= implode(',', [
                (int)$r['row_number'], $cell($r['row_status']), $cell($r['last_name']),
                $cell($r['first_name']), $cell($r['email']), $cell($issues),
            ]) . "\n";
        }
        return $out;
    }

    private function uuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    }
}
