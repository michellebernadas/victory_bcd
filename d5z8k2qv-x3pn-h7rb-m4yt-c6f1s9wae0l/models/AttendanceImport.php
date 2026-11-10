<?php
require_once 'models/MemberImport.php';          // openSpreadsheet()
require_once 'models/ServiceAttendance.php';
require_once 'models/GatheringAttendance.php';

/**
 * Staged import of "Worship Service Annual Attendance.xlsx".
 *
 *     UPLOAD -> PARSE -> PREVIEW (per-cell outcome) -> ADMIN CONFIRM -> IMPORT
 *
 * Reads ONLY the worksheet named for the import year (default "2026"); every
 * other sheet (the hidden 2025 one included) is ignored. Parsed values land in
 * attendance_import_staging; only commit() writes to service_attendances /
 * gathering_attendances, and it never overwrites a row already in the portal,
 * so re-running the same file creates nothing new (the UNIQUE keys back that up).
 *
 * SHEET LAYOUT (one block per month)
 *   header row : A="MONTH" ... H="DATE" I="SUNDAY SERVICES" .. "TOTAL" "KIDS CHURCH" .. "TOTAL"
 *   times row  : service times as Excel day fractions over each section
 *   data rows  : B/C prayer date+count, E/F hangout date+count, H Sunday date,
 *                Sunday Service counts per time, Kids Church counts per time
 *   A="MONTHLY TOTAL" row ends the block; its TOTAL cells are kept for the
 *   cross-check shown on the preview.
 *
 * RULES
 *   - Blank count = no data: skipped. Text such as "NO PM", "NO SH", "PAUSE",
 *     "NA" = the gathering did not happen: skipped, never stored as 0.
 *   - A typed 0 for a prayer meeting / hangout is treated as "did not happen"
 *     (skipped, shown on the preview). The sheet uses 0 and "NO SH" alike.
 *   - Sunday time with a Sunday Service count but blank Kids Church: imported
 *     with Kids Church NOT RECORDED (kids_recorded = 0), never as 0 kids.
 *   - Formula cells (e.g. =+8+16) use their calculated value.
 *   - Text dates like "1/5-9/2026": first date used, original text kept in notes.
 *   - Same gathering + date twice in the file: the second is not imported.
 *   - Odd weekdays are imported but flagged for review on the preview.
 */
class AttendanceImport
{
    const STATUS = [
        'ready'    => ['Will import',            'success', 'bi-check-circle-fill'],
        'review'   => ['Will import — check',    'warning', 'bi-exclamation-triangle-fill'],
        'existing' => ['Already in the portal',  'secondary', 'bi-check2-all'],
        'skipped'  => ['Skipped (no event)',     'light',   'bi-dash-circle'],
        'invalid'  => ['Cannot import',          'danger',  'bi-x-circle-fill'],
    ];
    const KINDS = [
        'sunday'           => 'Sunday',
        'prayer_meeting'   => 'Prayer Meeting',
        'students_hangout' => "Students' Hangout",
    ];

    private $db;

    public function __construct($db) { $this->db = $db; }

    // ── Cell helpers ────────────────────────────────────────────────────────

    /** Value of a cell: calculated value for a formula, trimmed string otherwise. */
    private static function val($ws, string $col, int $row)
    {
        $c = $ws->getCell($col . $row);
        $v = $c->getValue();
        if (is_string($v) && strlen($v) > 0 && $v[0] === '=') {
            $old = $c->getOldCalculatedValue();
            if ($old !== null) return $old;
            try { return $c->getCalculatedValue(); } catch (\Throwable $e) { return null; }
        }
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) $v = $v->getPlainText();
        return is_string($v) ? trim($v) : $v;
    }

    /**
     * A count cell -> ['n' => int] | ['blank' => true] | ['text' => '...'] | ['bad' => '...'].
     */
    private static function count($v): array
    {
        if ($v === null || $v === '') return ['blank' => true];
        if (is_string($v) && !is_numeric($v)) {
            if (strpos($v, '#') === 0) return ['bad' => $v];          // #DIV/0! etc.
            return ['text' => $v];
        }
        $f = (float)$v;
        if ($f < 0 || $f != floor($f)) return ['bad' => (string)$v];
        return ['n' => (int)$f];
    }

    /**
     * A date cell -> ['date' => 'Y-m-d', 'note' => ?string] | ['blank' => true] | ['bad' => '...'].
     */
    private static function date($v): array
    {
        if ($v === null || $v === '') return ['blank' => true];
        if (is_numeric($v)) {
            $n = (float)$v;
            if ($n < 30000 || $n > 60000) return ['bad' => (string)$v];
            $d = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);
            return ['date' => $d->format('Y-m-d'), 'note' => null];
        }
        $s = trim((string)$v);
        // "1/5-9/2026" (a week-long event) or "1/5/2026": month/day[-day]/year.
        if (preg_match('#^(\d{1,2})/(\d{1,2})(?:\s*-\s*\d{1,2})?/(\d{4})$#', $s, $m)
            && checkdate((int)$m[1], (int)$m[2], (int)$m[3])) {
            return ['date' => sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]),
                    'note' => $s === "{$m[1]}/{$m[2]}/{$m[3]}" ? null : 'Sheet date: ' . $s];
        }
        return ['bad' => $s];
    }

    /** 0.3541666 -> "8:30 AM" (services.name format). */
    private static function timeName($v): ?string
    {
        if (!is_numeric($v)) return null;
        $f = (float)$v;
        if ($f <= 0 || $f >= 1) return null;
        $mins = (int)round($f * 1440);
        return date('g:i A', mktime(intdiv($mins, 60), $mins % 60, 0, 1, 1, 2000));
    }

    private static function colIdx(string $col): int
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($col);
    }

    private static function colStr(int $i): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ── Parse ───────────────────────────────────────────────────────────────

    /**
     * @return array{error:?string,entries:array,blocks:array,notes:array}
     *   entries: [kind, date, service, a, b, note, cell, row, issues[], skip?]
     *   blocks : [month, from, to, sheet_service, sheet_kids, sheet_prayer, sheet_hangout]
     */
    public function parseFile(string $path, string $originalName, string $year = '2026'): array
    {
        $out = ['error' => null, 'entries' => [], 'blocks' => [], 'notes' => []];
        try {
            $book = MemberImport::openSpreadsheet($path, $originalName);
        } catch (\Throwable $e) {
            error_log('AttendanceImport::parseFile: ' . $e->getMessage());
            $out['error'] = 'That file could not be opened as a spreadsheet. Please upload the .xlsx workbook.';
            return $out;
        }
        $ws = $book->getSheetByName($year);
        if (!$ws) {
            $out['error'] = 'No worksheet named "' . $year . '" was found. Sheets in this file: '
                          . implode(', ', $book->getSheetNames()) . '.';
            return $out;
        }
        foreach ($book->getSheetNames() as $n) {
            if ($n !== $year) $out['notes'][] = 'Worksheet "' . $n . '" was ignored — only the ' . $year . ' sheet is imported.';
        }

        $last = min(2000, $ws->getHighestDataRow());
        for ($r = 1; $r <= $last; $r++) {
            if (strtoupper((string)self::val($ws, 'A', $r)) !== 'MONTH') continue;
            $block = $this->parseBlock($ws, $r, $last, $out);
            if ($block) { $out['blocks'][] = $block; $r = $block['to']; }
        }
        if (!$out['blocks']) $out['error'] = 'No month blocks (a row starting with MONTH) were found on the ' . $year . ' sheet.';
        return $out;
    }

    /** One month block starting at its MONTH header row. */
    private function parseBlock($ws, int $hdr, int $last, array &$out): ?array
    {
        // Locate the columns from the header text, not from fixed letters.
        $dateCols = []; $svcStart = $kidsStart = $svcEnd = $kidsEnd = $kidsTotal = 0;
        for ($c = 1; $c <= 30; $c++) {
            $h = strtoupper((string)self::val($ws, self::colStr($c), $hdr));
            if ($h === '') continue;
            if (strpos($h, 'AVERAGE') !== false) break;              // the side summary
            if ($h === 'DATE') $dateCols[] = $c;
            elseif (strpos($h, 'SUNDAY SERVICE') !== false) $svcStart = $c;
            elseif (strpos($h, 'KIDS CHURCH') !== false)    $kidsStart = $c;
            elseif ($h === 'TOTAL') {
                if ($svcStart && !$svcEnd && !$kidsStart) $svcEnd = $c - 1;
                elseif ($kidsStart && !$kidsEnd) { $kidsEnd = $c - 1; $kidsTotal = $c; }
            }
        }
        if (count($dateCols) < 3 || !$svcStart || !$svcEnd || !$kidsStart || !$kidsEnd) {
            $out['notes'][] = 'Row ' . $hdr . ': month header not in the expected layout — block skipped.';
            return null;
        }
        $timesRow = $hdr + 1;
        $month = strtoupper((string)self::val($ws, 'A', $hdr + 2)) ?: ('Row ' . $hdr);

        // Service time per section column; a missing header borrows the time
        // over the same position in the other section (and is reported).
        $svcTimes = []; $kidsCol = [];
        for ($i = 0; $svcStart + $i <= $svcEnd; $i++) {
            $sc = $svcStart + $i; $kc = $kidsStart + $i;
            $t = self::timeName(self::val($ws, self::colStr($sc), $timesRow));
            $kt = $kc <= $kidsEnd ? self::timeName(self::val($ws, self::colStr($kc), $timesRow)) : null;
            if ($t === null && $kt !== null) {
                $t = $kt;
                if (self::count(self::val($ws, self::colStr($sc), $hdr + 2))['n'] ?? null) {
                    $out['notes'][] = $month . ': no service-time header over Sunday Service column ' . self::colStr($sc)
                                    . ' (row ' . $timesRow . '); used ' . $t . ' from the Kids Church header in the same position.';
                }
            }
            $svcTimes[$sc] = $t;
            $kidsCol[$sc]  = $kc <= $kidsEnd ? $kc : null;
        }

        $pDate = $dateCols[0]; $pCnt = $pDate + 1; $hDate = $dateCols[1]; $hCnt = $hDate + 1; $sDate = $dateCols[2];
        $block = ['month' => $month, 'from' => $hdr + 2, 'to' => $hdr + 2];
        for ($r = $hdr + 2; $r <= $last; $r++) {
            if (strtoupper((string)self::val($ws, 'A', $r)) === 'MONTHLY TOTAL') {
                $num = fn($v) => is_numeric($v) ? (int)round((float)$v) : null;
                $block += [
                    'to' => $r,
                    'sheet_service' => $num(self::val($ws, self::colStr($svcEnd + 1), $r)),
                    'sheet_kids'    => $num(self::val($ws, self::colStr($kidsTotal), $r)),
                    'sheet_prayer'  => $num(self::val($ws, self::colStr($pCnt + 1), $r)),
                    'sheet_hangout' => $num(self::val($ws, self::colStr($hCnt + 1), $r)),
                ];
                return $block;
            }
            if (strtoupper((string)self::val($ws, 'A', $r)) === 'MONTH') break;   // next block, no total row
            $this->gathering($ws, 'prayer_meeting',   $pDate, $pCnt, $r, $out);
            $this->gathering($ws, 'students_hangout', $hDate, $hCnt, $r, $out);
            $this->sunday($ws, $sDate, $svcTimes, $kidsCol, $r, $out);
            $block['to'] = $r;
        }
        $out['notes'][] = $month . ': no MONTHLY TOTAL row found, so this month cannot be cross-checked.';
        return $block + ['sheet_service' => null, 'sheet_kids' => null, 'sheet_prayer' => null, 'sheet_hangout' => null];
    }

    /** Prayer Meeting / Students' Hangout cell pair on one row. */
    private function gathering($ws, string $kind, int $dCol, int $nCol, int $r, array &$out): void
    {
        $dc = self::colStr($dCol); $nc = self::colStr($nCol);
        $d = self::date(self::val($ws, $dc, $r));
        $n = self::count(self::val($ws, $nc, $r));
        if (isset($d['blank']) && isset($n['blank'])) return;
        $e = ['kind' => $kind, 'date' => $d['date'] ?? null, 'service' => null, 'a' => null, 'b' => null,
              'note' => $d['note'] ?? null, 'cell' => $dc . $r . '/' . $nc . $r, 'row' => $r, 'issues' => [], 'status' => 'ready'];
        if (isset($d['blank'])) { $e['status'] = 'invalid'; $e['issues'][] = 'A count with no date.'; }
        elseif (isset($d['bad'])) { $e['status'] = 'invalid'; $e['issues'][] = 'Date "' . $d['bad'] . '" could not be read.'; }
        elseif (isset($n['blank'])) { $e['status'] = 'skipped'; $e['issues'][] = 'No count entered for this date.'; }
        elseif (isset($n['text'])) { $e['status'] = 'skipped'; $e['issues'][] = '"' . $n['text'] . '" — no event that week.'; }
        elseif (isset($n['bad'])) { $e['status'] = 'invalid'; $e['issues'][] = 'Count "' . $n['bad'] . '" is not a whole number.'; }
        elseif ($n['n'] === 0) { $e['status'] = 'skipped'; $e['a'] = 0; $e['issues'][] = '0 typed — treated as "did not happen", like NO PM / NO SH.'; }
        else {
            $e['a'] = $n['n'];
            $dow = (int)date('w', strtotime($e['date']));
            $usual = $kind === 'prayer_meeting' ? [2, 3] : [5];
            if ($dow === 0) {
                // Gatherings never fall on a Sunday: almost certainly the
                // Sunday date copied across. Imported only if the admin ticks it.
                $e['status'] = 'review'; $e['default_off'] = true;
                $e['issues'][] = 'Dated on a SUNDAY (' . date('M j', strtotime($e['date'])) . ') — likely the Sunday date copied into this column. Not ticked; tick it to import anyway.';
            } elseif (!in_array($dow, $usual, true)) {
                $e['status'] = 'review';
                $e['issues'][] = 'Falls on a ' . date('l', strtotime($e['date'])) . ', not the usual ' . GatheringAttendance::TYPES[$kind]['day'] . '.';
            }
            if ($e['note']) $e['issues'][] = 'Text date — first day used; "' . substr($e['note'], 12) . '" kept in notes.';
        }
        $out['entries'][] = $e;
    }

    /** One Sunday row: one entry per service time that has a count. */
    private function sunday($ws, int $dCol, array $svcTimes, array $kidsCol, int $r, array &$out): void
    {
        $dc = self::colStr($dCol);
        $d = self::date(self::val($ws, $dc, $r));
        foreach ($svcTimes as $sc => $time) {
            $scs = self::colStr($sc);
            $a = self::count(self::val($ws, $scs, $r));
            $kc = $kidsCol[$sc] ? self::colStr($kidsCol[$sc]) : null;
            $b = $kc ? self::count(self::val($ws, $kc, $r)) : ['blank' => true];
            if (isset($a['blank']) && isset($b['blank'])) continue;
            $e = ['kind' => 'sunday', 'date' => $d['date'] ?? null, 'service' => $time, 'a' => null, 'b' => null,
                  'note' => null, 'cell' => $dc . $r . ' ' . $scs . $r . ($kc ? '/' . $kc . $r : ''), 'row' => $r,
                  'issues' => [], 'status' => 'ready'];
            if (!isset($d['date']))  { $e['status'] = 'invalid'; $e['issues'][] = 'Counts with no readable Sunday date.'; }
            elseif ($time === null)  { $e['status'] = 'invalid'; $e['issues'][] = 'Column ' . $scs . ' has no service time.'; }
            elseif (isset($a['blank']) || isset($a['text']) || isset($a['bad']) || ($a['n'] ?? 0) === 0) {
                $e['status'] = 'invalid';
                $e['issues'][] = 'Kids Church count without a Sunday Service count — not imported.';
            } else {
                $e['a'] = $a['n'];
                if (isset($b['n'])) $e['b'] = $b['n'];
                else $e['issues'][] = 'Kids Church not recorded' . (isset($b['text']) || isset($b['bad']) ? ' ("' . ($b['text'] ?? $b['bad']) . '")' : '') . ' — imported as "not recorded", not 0.';
                if ((int)date('w', strtotime($e['date'])) !== 0) {
                    $e['status'] = 'review';
                    $e['issues'][] = 'Date is a ' . date('l', strtotime($e['date'])) . ', not a Sunday.';
                }
            }
            $out['entries'][] = $e;
        }
    }

    // ── Stage / preview ─────────────────────────────────────────────────────

    /** Marks in-file duplicates and rows already in the portal, then stages. */
    public function stage(array $entries, string $sheet = '2026'): string
    {
        $batch = $this->uuid();
        $allowed = array_column((new ServiceAttendance($this->db))->getServiceTimeOptions(), 'name');
        $exSun = $this->db->prepare("SELECT 1 FROM service_attendances WHERE service_date = ? AND service_name = ?");
        $exGa  = $this->db->prepare("SELECT 1 FROM gathering_attendances WHERE gathering_type = ? AND event_date = ?");
        $ins = $this->db->prepare(
            "INSERT INTO attendance_import_staging
                 (batch_id, source_cell, kind, event_date, service_name, count_a, count_b, notes, row_status, issues)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $seen = [];
        $this->db->beginTransaction();
        try {
            foreach ($entries as $e) {
                $live = in_array($e['status'], ['ready', 'review'], true);
                if ($live && $e['kind'] === 'sunday' && $allowed && !in_array($e['service'], $allowed, true)) {
                    $e['status'] = 'invalid';
                    $e['issues'][] = 'Service time "' . $e['service'] . '" is not configured in Settings > Church Services.';
                    $live = false;
                }
                if ($live) {
                    $key = $e['kind'] . '|' . $e['date'] . '|' . $e['service'];
                    if (isset($seen[$key])) {
                        $e['status'] = 'invalid';
                        $e['issues'][] = 'Same ' . self::KINDS[$e['kind']] . ' date' . ($e['service'] ? ' and time' : '')
                                       . ' as cell ' . $seen[$key] . ' — duplicate in the sheet, not imported.';
                    } else {
                        $seen[$key] = $e['cell'];
                        $st = $e['kind'] === 'sunday' ? $exSun : $exGa;
                        $st->execute($e['kind'] === 'sunday' ? [$e['date'], $e['service']] : [$e['kind'], $e['date']]);
                        if ($st->fetchColumn()) {
                            $e['status'] = 'existing';
                            $e['issues'][] = 'Already recorded in the portal — left exactly as it is.';
                        }
                    }
                }
                $ins->execute([
                    $batch, $sheet . '!' . $e['cell'], $e['kind'], $e['date'], $e['service'],
                    $e['a'], $e['b'], $e['note'], $e['status'],
                    json_encode(['msgs' => $e['issues'], 'off' => !empty($e['default_off'])]),
                ]);
            }
            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('AttendanceImport::stage: ' . $ex->getMessage());
            return '';
        }
        return $batch;
    }

    public function getBatch(string $batch): array
    {
        try {
            $st = $this->db->prepare("SELECT * FROM attendance_import_staging WHERE batch_id = ? ORDER BY id");
            $st->execute([$batch]);
            $rows = $st->fetchAll() ?: [];
            foreach ($rows as &$r) {
                $j = json_decode((string)$r['issues'], true) ?: [];
                $r['msgs'] = $j['msgs'] ?? []; $r['default_off'] = !empty($j['off']);
            }
            return $rows;
        } catch (PDOException $e) {
            error_log('AttendanceImport::getBatch: ' . $e->getMessage());
            return [];
        }
    }

    /** Counts per kind x status, plus the importable totals. */
    public function getSummary(array $rows): array
    {
        $s = ['total' => count($rows), 'by' => [], 'import' => 0, 'sundays' => []];
        foreach (self::KINDS as $k => $l) foreach (self::STATUS as $st => $x) $s['by'][$k][$st] = 0;
        foreach ($rows as $r) {
            $s['by'][$r['kind']][$r['row_status']] = ($s['by'][$r['kind']][$r['row_status']] ?? 0) + 1;
            if (in_array($r['row_status'], ['ready', 'review'], true) && !$r['default_off']) {
                $s['import']++;
                if ($r['kind'] === 'sunday') $s['sundays'][$r['event_date']] = true;
            }
        }
        $s['sundays'] = count($s['sundays']);
        return $s;
    }

    // ── Commit / discard ────────────────────────────────────────────────────

    /**
     * Writes the ticked ready/review rows. INSERT IGNORE on the UNIQUE keys:
     * a row that appeared in the portal since the preview is left untouched,
     * so this can never overwrite or duplicate. The staging rows are deleted
     * afterwards — they were only ever a preview.
     *
     * @param int[] $ids staging ids the admin left ticked
     * @return array{sunday:int,prayer_meeting:int,students_hangout:int,kept:int,error:?string}
     */
    public function commit(string $batch, array $ids, ?int $by): array
    {
        $res = ['sunday' => 0, 'prayer_meeting' => 0, 'students_hangout' => 0, 'kept' => 0, 'error' => null];
        $tick = array_flip(array_map('intval', $ids));
        $rows = $this->getBatch($batch);
        if (!$rows) { $res['error'] = 'That import has expired or was already completed. Please upload the file again.'; return $res; }
        $sun = $this->db->prepare(
            "INSERT IGNORE INTO service_attendances (service_date, service_name, adults, kids, kids_recorded, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ga = $this->db->prepare(
            "INSERT IGNORE INTO gathering_attendances (gathering_type, event_date, attendance, notes, recorded_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        try {
            $this->db->beginTransaction();
            foreach ($rows as $r) {
                if (!in_array($r['row_status'], ['ready', 'review'], true) || !isset($tick[(int)$r['id']])) continue;
                if ($r['kind'] === 'sunday') {
                    $kr = $r['count_b'] === null ? 0 : 1;
                    $sun->execute([$r['event_date'], $r['service_name'], (int)$r['count_a'], $kr ? (int)$r['count_b'] : 0, $kr,
                                   $r['notes'], $by ?: null]);
                    $st = $sun;
                } elseif (GatheringAttendance::isType($r['kind'])) {
                    $ga->execute([$r['kind'], $r['event_date'], (int)$r['count_a'], $r['notes'], $by ?: null]);
                    $st = $ga;
                } else continue;
                if ($st->rowCount() > 0) $res[$r['kind']]++; else $res['kept']++;
            }
            $this->db->prepare("DELETE FROM attendance_import_staging WHERE batch_id = ?")->execute([$batch]);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('AttendanceImport::commit: ' . $e->getMessage());
            $res = ['sunday' => 0, 'prayer_meeting' => 0, 'students_hangout' => 0, 'kept' => 0,
                    'error' => 'The import failed and nothing was saved. Please try again.'];
        }
        return $res;
    }

    public function discard(string $batch): void
    {
        try {
            $this->db->prepare("DELETE FROM attendance_import_staging WHERE batch_id = ?")->execute([$batch]);
            // Abandoned previews older than a day are tidied up too.
            $this->db->exec("DELETE FROM attendance_import_staging WHERE dateadded < (NOW() - INTERVAL 1 DAY)");
        } catch (PDOException $e) {
            error_log('AttendanceImport::discard: ' . $e->getMessage());
        }
    }

    /**
     * Month-by-month check: what this batch would import (ready/review rows,
     * plus rows already in the portal) against the sheet's MONTHLY TOTAL cells.
     */
    public static function crossCheck(array $blocks, array $rows): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $calc = ['svc' => 0, 'kids' => 0, 'pm' => 0, 'sh' => 0, 'sundays' => []];
            foreach ($rows as $r) {
                if (!in_array($r['row_status'], ['ready', 'review', 'existing'], true) || !empty($r['default_off'])) continue;
                if (!preg_match('/!([A-Z]+)(\d+)/', (string)$r['source_cell'], $m)) continue;
                $row = (int)$m[2];
                if ($row < $b['from'] || $row > $b['to']) continue;
                if ($r['kind'] === 'sunday') {
                    $calc['svc'] += (int)$r['count_a']; $calc['kids'] += (int)$r['count_b'];
                    $calc['sundays'][$r['event_date']] = true;
                } elseif ($r['kind'] === 'prayer_meeting')   $calc['pm'] += (int)$r['count_a'];
                elseif ($r['kind'] === 'students_hangout')    $calc['sh'] += (int)$r['count_a'];
            }
            $out[] = ['month' => $b['month'], 'sundays' => count($calc['sundays'])] + $calc + [
                'sheet_svc' => $b['sheet_service'], 'sheet_kids' => $b['sheet_kids'],
                'sheet_pm' => $b['sheet_prayer'], 'sheet_sh' => $b['sheet_hangout'],
            ];
        }
        return $out;
    }
}
