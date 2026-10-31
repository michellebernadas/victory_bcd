<?php
require_once 'models/FacilityRequest.php';
require_once 'models/Facility.php';

/**
 * Facilities Request Form import.
 *
 *     UPLOAD -> PARSE -> VALIDATE -> PREVIEW -> ADMIN CONFIRMATION -> IMPORT
 *
 * Nothing reaches facility_requests until an admin presses Confirm. Parsed
 * forms live in facility_request_imports until then.
 *
 * THE WORKBOOK IS A STACK OF FORMS, NOT A TABLE
 * FACILITIES REQUEST FORM.xlsx holds one worksheet per request — plus a blank
 * template and two unrelated finance sheets. Each request sheet is a label /
 * value pair column:
 *
 *     NAME:              HAZEL LYN B. GABRIDO
 *     MINISTRY/DEPARTMENT:  PASTORAL SERVICES
 *     DATE NEEDED:       10/17/2026, Saturday
 *     START TIME:        0.41666666666667
 *     ...
 *
 * So sheets are identified by their labels, not their position, and the finance
 * sheets are ignored because they carry none.
 *
 * EXISTING IN A SPREADSHEET IS NOT AN APPROVAL
 * Every imported request lands at 'submitted'. Nothing this file does can
 * produce 'approved' or 'confirmed' — see the ALLOWED check in commit().
 *
 * WHEN A SHEET SHOWS A REAL FACILITIES REVIEW
 * The request is submitted FIRST and the Facilities review applied SECOND, as
 * two separate calls, producing two truthful history rows in the order they
 * actually happened. The importer never writes a later status directly.
 */
class FacilityRequestImport {

    private $db;
    private $requestModel;
    private $facilityModel;

    /** Label -> field. Keys are lower-cased with every non-alphanumeric dropped. */
    private const LABELS = [
        'name'                    => 'requestor_name',
        'ministrydepartment'      => 'ministry',
        'ministryleader'          => 'ministry_leader',
        'contactnumber'           => 'contact_number',
        'email'                   => 'email',
        'typeofrequest'           => 'request_type_raw',
        'eventactivity'           => 'event_activity',
        'purposeofrequest'        => 'purpose',
        'dateneeded'              => 'date_needed_raw',
        'starttime'               => 'start_time_raw',
        'endtime'                 => 'end_time_raw',
        'expectedattendance'      => 'expected_attendance',
        'requestedfacilityroom'   => 'facility_raw',
        'preferredsetup'          => 'preferred_setup',
        'numberoftables'          => 'num_tables',
        'numberofchairs'          => 'num_chairs',
        'otherrequirements'       => 'other_requirements',
        'equipmentrequested'      => 'equipment_requested',
        'quantity'                => 'equipment_quantity',
        'personresponsible'       => 'person_responsible',
        'technicalassistance'     => 'technical_assistance_raw',
        'additionalrequirements'  => 'additional_requirements_raw',
        'details'                 => 'additional_details',
        'requestor'               => 'acknowledged_name',
        // Facilities Personnel block
        'facilityequipmentavailability' => 'fac_available_raw',
        'scheduleconflict'              => 'fac_schedule_conflict_raw',
        'setuprequired'                 => 'fac_setup_required_raw',
        'technicalsupportrequired'      => 'fac_technical_required_raw',
        'otherconsiderations'           => 'fac_other_considerations',
        'facilitiesrecommendation'      => 'fac_recommendation_raw',
        'facilitiespersonnel'           => 'fac_personnel_name',
        // Minister block — read only so the preview can show it is empty.
        'decision'            => 'minister_decision_raw',
        'commentsconditions'  => 'minister_comments',
        'minister'            => 'minister_name',
    ];

    /** A sheet needs these labels before it is treated as a request form at all. */
    private const REQUIRED_LABELS = ['name', 'eventactivity', 'dateneeded'];

    /** "DATE:" appears three times — after REQUESTOR, FACILITIES PERSONNEL, MINISTER. */
    private const DATE_LABEL = 'date';

    /** TYPE OF REQUEST text -> the request_type SET value. */
    private const TYPE_MAP = [
        'facility' => 'facility_room', 'facilityroom' => 'facility_room',
        'room' => 'facility_room', 'venue' => 'facility_room',
        'furniture' => 'furniture',
        'equipment' => 'equipment',
        'officeresource' => 'office_resource', 'office' => 'office_resource',
        'resource' => 'office_resource',
    ];

    public function __construct($db) {
        $this->db            = $db;
        $this->requestModel  = new FacilityRequest($db);
        $this->facilityModel = new Facility($db);
    }

    public static function clean($v): string {
        return trim(preg_replace('/\s+/u', ' ', (string)$v));
    }

    private static function labelKey($v): string {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$v), 'UTF-8')) ?? '';
    }

    // ── Excel value normalisation (all of it lives here, not in the model) ──

    /**
     * A date, in any of the three shapes the real workbook uses.
     *
     *   46312                  Excel date serial          -> 2026-10-17
     *   "10/17/2026, Saturday" free text with a day name  -> 2026-10-17
     *   "2026-10-17"           already ISO                -> unchanged
     *
     * Returns ['Y-m-d' or null, note]. Unreadable text returns null with a
     * note rather than a guessed date — a wrong booking date is worse than a
     * blank one, and the raw text is kept in raw_data either way.
     */
    public static function parseDate($raw): array {
        $raw = self::clean($raw);
        if ($raw === '') return [null, null];

        // 1. Excel serial. Day 1 is 1900-01-01, with Excel's 1900 leap-year bug
        //    meaning serials above 60 are one day ahead of a naive count — the
        //    1899-12-30 epoch is the standard correction.
        if (is_numeric($raw) && strpos($raw, '.') === false) {
            $serial = (int)$raw;
            if ($serial > 0 && $serial < 2958466) {    // through year 9999
                $ts = strtotime('1899-12-30 +' . $serial . ' days');
                if ($ts !== false) return [date('Y-m-d', $ts), null];
            }
            return [null, 'Date "' . $raw . '" is not a date the importer recognises.'];
        }

        // 2. Free text. Strip a trailing day name ("10/17/2026, Saturday") —
        //    it is a restatement of the date, and strtotime reads the pair
        //    inconsistently.
        $text = preg_replace(
            '/[,\s]+(mon|tues?|wed(nes)?|thur?s?|fri|sat(ur)?|sun)(day)?\.?$/i', '', $raw
        );
        $text = trim($text);

        // m/d/Y is the church's own convention; let it win over strtotime's guess.
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $text, $m)) {
            if (checkdate((int)$m[1], (int)$m[2], (int)$m[3])) {
                return [sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]), null];
            }
        }
        $ts = strtotime($text);
        if ($ts === false) {
            return [null, 'Date "' . $raw . '" could not be read. The request will have no date and will not '
                        . 'appear on the calendar until one is set.'];
        }
        return [date('Y-m-d', $ts), null];
    }

    /**
     * A time. Excel stores these as a fraction of a day, which is what both
     * real requests contain: 0.41666666666667 = 10:00, 0.58333333333333 =
     * 14:00. Free text ("10:00 AM", "1PM") is accepted too.
     * Returns ['H:i' or null, note].
     */
    public static function parseTime($raw): array {
        $raw = self::clean($raw);
        if ($raw === '') return [null, null];

        if (is_numeric($raw)) {
            $f = (float)$raw;
            if ($f < 0 || $f >= 2) return [null, 'Time "' . $raw . '" is not a recognisable time.'];
            $mins = (int)round(fmod($f, 1.0) * 1440);
            $mins = max(0, min(1439, $mins));
            return [sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60), null];
        }

        $text = $raw;
        if (preg_match('/\b(nn|noon)\b/i', $text)) {
            $stripped = trim(preg_replace('/\b(nn|noon)\b/i', '', $text));
            if ($stripped === '' || $stripped === '12' || $stripped === '12:00') return ['12:00', null];
            $text = $stripped . ' PM';
        }
        $ts = strtotime('2000-01-01 ' . $text);
        if ($ts === false) return [null, 'Time "' . $raw . '" could not be read.'];
        return [date('H:i', $ts), null];
    }

    /** YES / NO / Y / N / blank, from a form filled in by hand. */
    public static function parseYesNo($raw): ?string {
        $k = self::labelKey($raw);
        if ($k === '') return null;
        if (in_array($k, ['yes', 'y', 'true', '1', 'yespls', 'yesplease'], true)) return 'yes';
        if (in_array($k, ['no', 'n', 'false', '0', 'na', 'none'], true))          return 'no';
        return null;
    }

    /** TYPE OF REQUEST free text -> the request_type SET values it names. */
    public static function parseTypes($raw): array {
        $out = [];
        foreach (preg_split('/[,;\/]+|\s+and\s+/i', (string)$raw) as $piece) {
            $k = self::labelKey($piece);
            if ($k === '') continue;
            if (isset(self::TYPE_MAP[$k])) { $out[] = self::TYPE_MAP[$k]; continue; }
            foreach (self::TYPE_MAP as $needle => $value) {
                if (strpos($k, $needle) !== false) { $out[] = $value; break; }
            }
        }
        return array_values(array_unique($out));
    }

    // ── Parse ───────────────────────────────────────────────────────────────

    /**
     * Read every request-shaped sheet in the workbook.
     * Returns ['forms' => [...], 'skipped' => ['Sheet name' => why], 'error' => ?string].
     * NOTHING is written here.
     */
    public function parseFile(string $path, string $originalName = ''): array {
        if (!is_readable($path)) {
            return ['forms' => [], 'skipped' => [], 'error' => 'The uploaded file could not be read.'];
        }
        try {
            // An upload's temp file has no extension, and PhpSpreadsheet then
            // falls back to mime_content_type(), which is fatal where
            // ext-fileinfo is off. The original filename is the reliable hint.
            // See MemberImport::openSpreadsheet() for the full reasoning.
            require_once 'models/MemberImport.php';
            $book = MemberImport::openSpreadsheet($path, $originalName);
        } catch (\Throwable $e) {
            error_log('FacilityRequestImport::parseFile: ' . $e->getMessage());
            return ['forms' => [], 'skipped' => [],
                    'error' => 'That file could not be opened as a spreadsheet. Please save it as .xlsx and try again.'];
        }

        $forms = []; $skipped = [];
        foreach ($book->getSheetNames() as $sheetName) {
            $grid = $book->getSheetByName($sheetName)->toArray(null, true, false, false);
            $form = $this->readSheet($grid, $sheetName);

            if ($form === null) {
                // Not a request form at all — the two finance sheets land here.
                $skipped[$sheetName] = 'Not a facilities request form — no NAME / EVENT / DATE NEEDED labels.';
                continue;
            }
            if (self::clean($form['requestor_name'] ?? '') === ''
                && self::clean($form['event_activity'] ?? '') === '') {
                // The blank REQUEST FORM template: right labels, nothing filled in.
                $skipped[$sheetName] = 'A blank form template — no requestor and no event, so there is nothing to import.';
                continue;
            }
            $forms[] = $form;
        }

        if (!$forms && !$skipped) {
            return ['forms' => [], 'skipped' => [],
                    'error' => 'No sheets were found in that file.'];
        }
        if (!$forms) {
            return ['forms' => [], 'skipped' => $skipped,
                    'error' => 'No filled-in request forms were found. Every sheet was either blank or not a request form.'];
        }
        return ['forms' => $forms, 'skipped' => $skipped, 'error' => null];
    }

    /**
     * Read one worksheet as a label/value form.
     *
     * Values sit in the first non-empty cell to the right of the label, which
     * survives the merged cells the real form uses. Returns null when the sheet
     * carries none of the required labels.
     */
    private function readSheet(array $grid, string $sheetName): ?array {
        $form  = ['_sheet' => $sheetName];
        $found = [];
        // "DATE:" repeats under REQUESTOR / FACILITIES PERSONNEL / MINISTER, so
        // each occurrence is numbered in the order it appears.
        $dateSeen = 0;

        foreach ($grid as $row) {
            if (!is_array($row)) continue;
            foreach ($row as $c => $cell) {
                $key = self::labelKey($cell);
                if ($key === '') continue;

                $isDate = ($key === self::DATE_LABEL);
                if (!$isDate && !isset(self::LABELS[$key])) continue;

                // First non-empty cell to the right.
                $value = '';
                for ($n = $c + 1; $n < count($row); $n++) {
                    $v = self::clean($row[$n] ?? '');
                    if ($v !== '') { $value = $v; break; }
                }

                if ($isDate) {
                    $dateSeen++;
                    $field = [1 => 'requestor_date_raw', 2 => 'fac_date_raw', 3 => 'minister_date_raw'][$dateSeen] ?? null;
                    if ($field !== null) $form[$field] = $value;
                    continue;
                }

                $found[$key] = true;
                // A label repeated inside one sheet keeps its first filled value.
                $field = self::LABELS[$key];
                if (!isset($form[$field]) || $form[$field] === '') $form[$field] = $value;
            }
        }

        foreach (self::REQUIRED_LABELS as $req) {
            if (!isset($found[$req])) return null;
        }
        return $form;
    }

    // ── Deciding what status a sheet has actually earned ────────────────────

    /**
     * Did Facilities Personnel genuinely review this request?
     *
     * THE SUBTLE PART, AND WHY IT MATTERS
     * "FACILITIES PERSONNEL: JANDRIC L. SALVIO" is on every sheet of the real
     * workbook — INCLUDING the blank REQUEST FORM template, which has nothing
     * else filled in. It is pre-printed stationery, not a signature. Treating
     * that name as a completed review would manufacture an approval step that
     * never happened, which is exactly what rule 4 forbids.
     *
     * So a review counts only when the review ANSWERS carry content: the
     * availability / conflict / setup / technical answers, the recommendation,
     * the other-considerations note, or a date against the Facilities block.
     * A name on its own is reported on the preview as pre-printed and ignored.
     *
     * @return array{reviewed:bool, name_only:bool, evidence:string[]}
     */
    public static function detectFacilitiesReview(array $form): array {
        $evidence = [];

        $answers = [
            'fac_available_raw'          => 'availability',
            'fac_schedule_conflict_raw'  => 'schedule conflict',
            'fac_setup_required_raw'     => 'setup required',
            'fac_technical_required_raw' => 'technical support required',
            'fac_recommendation_raw'     => 'recommendation',
            'fac_other_considerations'   => 'other considerations',
            'fac_date_raw'               => 'review date',
        ];
        foreach ($answers as $field => $label) {
            if (self::clean($form[$field] ?? '') !== '') $evidence[] = $label;
        }

        $hasName = self::clean($form['fac_personnel_name'] ?? '') !== '';
        return [
            'reviewed'  => !empty($evidence),
            'name_only' => $hasName && empty($evidence),
            'evidence'  => $evidence,
        ];
    }

    /**
     * Did the Minister decide? Read ONLY so the preview can say "no, so this
     * stays Submitted". The importer has no path to 'approved', 'declined' or
     * 'confirmed' at all — see the comment on commit().
     */
    public static function detectMinisterDecision(array $form): bool {
        return self::clean($form['minister_decision_raw'] ?? '') !== ''
            || self::clean($form['minister_date_raw'] ?? '') !== '';
    }

    // ── Validate + stage ────────────────────────────────────────────────────

    /**
     * Normalise every form, work out the status it has earned, and write it to
     * facility_request_imports. Returns the batch id.
     *
     * NOTHING reaches facility_requests here.
     */
    public function stage(array $forms): string {
        $batchId   = $this->uuid();
        $facilities = $this->facilityIndex();

        $ins = $this->db->prepare(
            "INSERT INTO facility_request_imports
                (batch_id, source_sheet, raw_data, parsed_status, issues)
             VALUES (?,?,?,?,?)"
        );
        foreach ($forms as $form) {
            $prepared = $this->prepare($form, $facilities);
            $ins->execute([
                $batchId,
                mb_substr((string)($form['_sheet'] ?? ''), 0, 150),
                json_encode($prepared, JSON_UNESCAPED_UNICODE),
                $prepared['_row_status'],
                $prepared['_issues'] ? json_encode($prepared['_issues'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        }
        return $batchId;
    }

    /**
     * Turn one raw form into the exact array submit() expects, plus the review
     * metadata the preview screen needs. All Excel normalisation happens here —
     * deriveInterval() only ever sees a clean Y-m-d and H:i.
     */
    private function prepare(array $form, array $facilities): array {
        $issues = [];
        $get = function ($k) use ($form) { return self::clean($form[$k] ?? ''); };

        [$dateNeeded, $dateNote] = self::parseDate($form['date_needed_raw'] ?? '');
        if ($dateNote !== null) $issues[] = $dateNote;
        elseif ($dateNeeded === null) $issues[] = 'No date needed on this form.';

        [$startTime, $startNote] = self::parseTime($form['start_time_raw'] ?? '');
        if ($startNote !== null) $issues[] = $startNote;
        [$endTime, $endNote] = self::parseTime($form['end_time_raw'] ?? '');
        if ($endNote !== null) $issues[] = $endNote;
        if (($startTime === null) !== ($endTime === null)) {
            $issues[] = 'Only one of the start / end times could be read, so this request occupies no '
                      . 'time slot and will not be checked for conflicts.';
        }

        $types = self::parseTypes($form['request_type_raw'] ?? '');
        if (!$types) {
            $raw = $get('request_type_raw');
            $issues[] = $raw === ''
                ? 'No type of request on this form — filed as Facility / Room.'
                : 'Type of request "' . $raw . '" was not recognised — filed as Facility / Room.';
            $types = ['facility_room'];
        }

        // A room is matched only on an exact name. A half-guess here books the
        // wrong room, so an unmatched name is left for the admin instead.
        $facilityId = null;
        $facilityRaw = $get('facility_raw');
        if ($facilityRaw !== '') {
            $key = self::labelKey($facilityRaw);
            if (isset($facilities[$key])) {
                $facilityId = (int)$facilities[$key]['id'];
            } else {
                $issues[] = 'Facility "' . $facilityRaw . '" does not match a facility on file. '
                          . 'The request imports without a room — set it from the request screen.';
            }
        }

        $review   = self::detectFacilitiesReview($form);
        $minister = self::detectMinisterDecision($form);

        if ($review['name_only']) {
            $issues[] = 'The Facilities Personnel name is pre-printed on this sheet but no review answers were '
                      . 'filled in, so no review is recorded. The request imports as Submitted.';
        }
        if ($review['reviewed']) {
            $issues[] = 'This sheet shows a completed Facilities review (' . implode(', ', $review['evidence'])
                      . '). It will be imported as Submitted first, then the Facilities review applied — '
                      . 'two history entries, in the order they happened.';
        }
        if ($minister) {
            $issues[] = 'A Minister decision is written on this sheet. It is NOT imported: an approval has to be '
                      . 'recorded in the portal by the Minister. Record it there after importing.';
        }

        $status = 'ready';
        if ($get('event_activity') === '')   { $issues[] = 'No event / activity on this form.';  $status = 'invalid'; }
        if ($get('requestor_name')  === '')  { $issues[] = 'No requestor name on this form.';    $status = 'invalid'; }
        if ($status !== 'invalid' && $issues) $status = 'needs_review';

        $email = $get('email');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'Email "' . $email . '" is not a valid address — it will be left blank.';
            $email = '';
            if ($status === 'ready') $status = 'needs_review';
        }

        return [
            // Exactly the keys FacilityRequest::normalize() reads.
            'requestor_name'      => $get('requestor_name'),
            'ministry'            => $get('ministry'),
            'ministry_leader'     => $get('ministry_leader'),
            'contact_number'      => $get('contact_number'),
            'email'               => $email,
            'request_type'        => $types,
            'event_activity'      => $get('event_activity'),
            'purpose'             => $get('purpose'),
            'date_needed'         => $dateNeeded ?? '',
            'start_time'          => $startTime ?? '',
            'end_time'            => $endTime ?? '',
            'expected_attendance' => $get('expected_attendance'),
            'facility_id'         => $facilityId ?? '',
            'preferred_setup'     => $get('preferred_setup'),
            'num_chairs'          => $get('num_chairs'),
            'num_tables'          => $get('num_tables'),
            'other_requirements'  => $get('other_requirements'),
            'equipment_requested' => $get('equipment_requested'),
            'equipment_quantity'  => $get('equipment_quantity'),
            'person_responsible'  => $get('person_responsible'),
            'technical_assistance'=> self::parseYesNo($form['technical_assistance_raw'] ?? '') === 'yes' ? 'yes' : 'no',
            'additional_details'  => $get('additional_details'),
            // The form's acknowledgement block is pre-printed text; the signature
            // line being filled in is what shows the requestor accepted it.
            'acknowledged'        => $get('acknowledged_name') !== '' ? 1 : 0,
            'acknowledged_name'   => $get('acknowledged_name'),
            'source'              => 'excel_import',
            'source_label'        => mb_substr((string)($form['_sheet'] ?? ''), 0, 255),

            // Preview / commit metadata, prefixed so it can never be mistaken
            // for a column.
            '_sheet'              => (string)($form['_sheet'] ?? ''),
            '_row_status'         => $status,
            '_issues'             => array_values($issues),
            '_review'             => $review,
            '_minister_on_sheet'  => $minister,
            '_fac_personnel_name' => $get('fac_personnel_name'),
            '_fac_review_fields'  => [
                'fac_available'            => self::parseYesNo($form['fac_available_raw'] ?? ''),
                'fac_schedule_conflict'    => self::parseYesNo($form['fac_schedule_conflict_raw'] ?? '') === 'yes' ? 'yes' : '',
                'fac_setup_required'       => self::parseYesNo($form['fac_setup_required_raw'] ?? ''),
                'fac_technical_required'   => self::parseYesNo($form['fac_technical_required_raw'] ?? ''),
                'fac_other_considerations' => $get('fac_other_considerations'),
                'fac_recommendation'       => self::labelKey($form['fac_recommendation_raw'] ?? ''),
            ],
            '_raw'                => $form,
        ];
    }

    /** Facilities keyed by normalised name, for exact-match room resolution. */
    private function facilityIndex(): array {
        $out = [];
        try {
            foreach ($this->facilityModel->getAllFacilities() ?: [] as $f) {
                $out[self::labelKey($f['name'])] = $f;
            }
        } catch (\Throwable $e) {
            error_log('FacilityRequestImport::facilityIndex: ' . $e->getMessage());
        }
        return $out;
    }

    // ── Preview ─────────────────────────────────────────────────────────────

    /**
     * The staged forms for the review screen, each with its decoded raw_data
     * and — once imported — the live status of the request it became.
     *
     * created_request_id has no foreign key, so a request that was hard-deleted
     * after import leaves a dangling id here. That is handled by LEFT JOIN plus
     * a 'missing' flag rather than by assuming the row is still there.
     */
    public function getBatch(string $batchId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT i.*, fr.status AS live_status, fr.uuid AS live_uuid, fr.event_activity AS live_event
                   FROM facility_request_imports i
                   LEFT JOIN facility_requests fr ON fr.id = i.created_request_id
                  WHERE i.batch_id = ?
                  ORDER BY i.id"
            );
            $stmt->execute([$batchId]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log('FacilityRequestImport::getBatch: ' . $e->getMessage());
            return [];
        }
        foreach ($rows as &$r) {
            $r['data']   = json_decode($r['raw_data'] ?? '{}', true) ?: [];
            $r['issues_list'] = json_decode($r['issues'] ?? '[]', true) ?: [];
            // Imported, but the request it created is gone.
            $r['request_missing'] = !empty($r['created_request_id']) && $r['live_status'] === null;
        }
        unset($r);
        return $rows;
    }

    /** Counts for the review screen. Every key always present, so an empty batch renders zeros. */
    public function getSummary(string $batchId): array {
        $out = [
            'total' => 0, 'ready' => 0, 'needs_review' => 0, 'invalid' => 0,
            'will_create' => 0, 'already_imported' => 0,
            'as_submitted' => 0, 'with_fac_review' => 0,
            'approved' => 0, 'confirmed' => 0,     // always 0 — see commit()
        ];
        foreach ($this->getBatch($batchId) as $r) {
            $out['total']++;
            $status = $r['parsed_status'];
            if (isset($out[$status])) $out[$status]++;
            if (!empty($r['created_request_id'])) $out['already_imported']++;

            if ($status === 'invalid') continue;
            if (!empty($r['data']['_review']['reviewed'])) $out['with_fac_review']++;
            else                                           $out['as_submitted']++;
        }
        $out['will_create'] = max(0, $out['total'] - $out['invalid'] - $out['already_imported']);
        return $out;
    }

    // ── Commit ──────────────────────────────────────────────────────────────

    /**
     * THE ONLY function that writes to facility_requests.
     *
     * TWO RULES, BOTH STRUCTURAL RATHER THAN CHECKED AFTERWARDS
     *
     * 1. NOTHING REACHES approved OR confirmed. This function calls exactly two
     *    things: submit(), which can only produce 'submitted', and
     *    facilitiesReview(), which can only produce 'pending_minister'.
     *    ministerDecision() and confirm() are never called from the importer at
     *    all, so there is no code path to an approval. A Minister decision
     *    written on a sheet is surfaced on the preview and deliberately
     *    discarded — an approval belongs to the Minister in the portal, not to
     *    whoever typed it into a spreadsheet.
     *
     * 2. HISTORY IS TRUTHFUL. A sheet showing a completed Facilities review is
     *    submitted FIRST and reviewed SECOND, as two separate calls. That
     *    produces two history rows — 'submitted' then 'facilities_reviewed' —
     *    in the order those things actually happened. The importer never writes
     *    a later status directly and never backdates one.
     *
     * Idempotent per row via created_request_id, so re-confirming a batch can
     * never create a second request.
     *
     * @param array $skipRowIds facility_request_imports.id the admin unticked.
     */
    public function commit(string $batchId, array $skipRowIds = [], $performedBy = null): array {
        $rows = $this->getBatch($batchId);
        $skip = array_flip(array_map('intval', $skipRowIds));

        $created = 0; $reviewed = 0; $skipped = 0; $errors = [];

        $mark = $this->db->prepare(
            "UPDATE facility_request_imports
                SET created_request_id = ?, reviewed_by = ?, reviewed_at = NOW(), imported_at = NOW()
              WHERE id = ?"
        );

        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            if (!empty($row['created_request_id'])) { $skipped++; continue; }   // already imported
            if ($row['parsed_status'] === 'invalid') { $skipped++; continue; }  // no event / no requestor
            if (isset($skip[$rowId]))                { $skipped++; continue; }  // admin unticked it

            $data = $row['data'];

            // STEP 1 — always a plain submission. This is the only status an
            // imported request can be created at.
            $res = $this->requestModel->submit($data, $performedBy);
            if (empty($res['ok'])) {
                $errors[] = ($row['source_sheet'] ?: 'A sheet') . ': ' . ($res['error'] ?? 'could not be saved.');
                $skipped++;
                continue;
            }
            $requestId = (int)$res['id'];
            $created++;

            // STEP 2 — only when the sheet genuinely carries review answers.
            // facilitiesReview() accepts from-status 'submitted', which is
            // exactly why submitting first works and why the trail reads true.
            if (!empty($data['_review']['reviewed'])) {
                $fields = $data['_fac_review_fields'] ?? [];
                $who    = self::clean($data['_fac_personnel_name'] ?? '');
                $note   = self::clean($fields['fac_other_considerations'] ?? '');
                $trail  = 'Imported from the Facilities Request Form'
                        . ($row['source_sheet'] ? ' (' . $row['source_sheet'] . ')' : '')
                        . ($who !== '' ? '. Reviewed on the sheet by ' . $who : '') . '.';
                $fields['fac_other_considerations'] = trim($note . ($note !== '' ? ' ' : '') . $trail);

                $rev = $this->requestModel->facilitiesReview($requestId, $fields, $performedBy);
                if (!empty($rev['ok'])) {
                    $reviewed++;
                } else {
                    // The request still exists as Submitted, which is the honest
                    // state. Surfaced rather than silently swallowed.
                    $errors[] = ($row['source_sheet'] ?: 'A sheet')
                              . ': imported as Submitted, but the Facilities review could not be applied ('
                              . ($rev['error'] ?? 'unknown reason') . ').';
                }
            }

            $mark->execute([$requestId, $performedBy ?: null, $rowId]);
        }

        return ['created' => $created, 'reviewed' => $reviewed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** Throw away a batch that was never confirmed. Imported rows stay as the audit trail. */
    public function discardBatch(string $batchId): void {
        try {
            $this->db->prepare("DELETE FROM facility_request_imports WHERE batch_id = ? AND created_request_id IS NULL")
                     ->execute([$batchId]);
        } catch (PDOException $e) {
            error_log('FacilityRequestImport::discardBatch: ' . $e->getMessage());
        }
    }

    private function uuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    }
}
