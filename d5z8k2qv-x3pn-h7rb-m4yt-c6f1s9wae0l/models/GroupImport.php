<?php
require_once 'models/MemberImport.php';

/**
 * Staff VG Schedule import — groups and the people on them.
 *
 *     UPLOAD -> PARSE -> VALIDATE -> PREVIEW -> ADMIN CONFIRMATION -> IMPORT
 *
 * Nothing reaches victory_groups / vg_members until an admin presses Confirm.
 * Parsed cards live in group_import_cards and their people in
 * group_import_staging until then.
 *
 * THE SHEET IS A GRID, NOT A TABLE
 * The 2026 Staff VG Schedule lays group "cards" out five across and several
 * down. Each card is a label column followed by a value column:
 *
 *     VGL:                    | Philip and Sab Porras
 *     Intern:                 |
 *     Members:                |
 *     1.                      | Alejandro Abad
 *     2.                      | Cara Abad
 *     Type of VG:             | Couples
 *     Day:                    | Thurday
 *     Time:                   | 0.79166666666667
 *     Frequency of meeting:   | Weekly
 *     Venue:                  | Quinos Lacson
 *
 * So the parser walks every column looking for a "VGL:" label, then reads
 * downward until the card's own "Venue:" (or the next VGL:) closes it. Cards do
 * NOT line up row-for-row across the sheet — the first column's second card is
 * 9 members long while its neighbour's is 2 — so each is tracked independently.
 *
 * THREE RULES THIS FILE EXISTS TO ENFORCE
 *  1. NEVER auto-create a member. A name that does not resolve to exactly one
 *     existing member is stored verbatim with link_status='needs_link'.
 *  2. NEVER split a couple. "Amy & Carl", "Hazel Lyn and Emmanuel Gabrido" are
 *     one person-row with one verbatim display_name. They are flagged
 *     is_couple_name and are never matched to a member automatically. The flag
 *     travels to vg_members.is_couple, where it earns the row a SECOND member
 *     link (member_id_2) that an admin fills in by hand — one row, one verbatim
 *     string, up to two real members. See migration 2026_10_20_11.
 *  3. Campus is NEVER filed as a Victory Group. group_kind comes from the
 *     card's own "Type of VG" text and is shown on the review screen.
 */
class GroupImport {

    private $db;

    /** Label cells that open / fill a card. Compared case- and punctuation-insensitively. */
    private const L_LEADER = 'vgl';
    private const L_INTERN = 'intern';
    private const L_MEMBERS = 'members';
    private const L_TYPE   = 'typeofvg';
    private const L_DAY    = 'day';
    private const L_TIME   = 'time';
    private const L_FREQ   = 'frequencyofmeeting';
    private const L_VENUE  = 'venue';

    /** Everything that closes or belongs to a card, so a stray cell cannot extend one. */
    private const CARD_LABELS = [
        self::L_LEADER, self::L_INTERN, self::L_MEMBERS,
        self::L_TYPE, self::L_DAY, self::L_TIME, self::L_FREQ, self::L_VENUE,
    ];

    /**
     * "Type of VG" text -> [group_kind, audience].
     * Campus is checked FIRST and on its own: a card typed "Campus" must never
     * fall through to a Victory Group, because every Victory Group count in the
     * app would then include it.
     */
    private const AUDIENCE_MAP = [
        'campus'        => ['campus',           'campus'],
        'singlemen'     => ['victory_group',    'single men'],
        'singlewomen'   => ['victory_group',    'single women'],
        'couples'       => ['victory_group',    'couples'],
        'couplesvg'     => ['victory_group',    'couples'],
        'men'           => ['victory_group',    'men'],
        'women'         => ['victory_group',    'women'],
        'youngadult'    => ['victory_group',    'young adult'],
        'youngadults'   => ['victory_group',    'young adult'],
        'youngpro'      => ['victory_group',    'young professionals'],
        'seniors'       => ['victory_group',    'seniors'],
        'moms'          => ['victory_group',    'moms'],
        'wives'         => ['victory_group',    'wives'],
        'leadershipgroup' => ['leadership_group', ''],
        'lg'              => ['leadership_group', ''],
    ];

    /** Day spellings the church actually uses, including the ones with typos. */
    private const DAY_MAP = [
        'monday' => 'Monday', 'mon' => 'Monday',
        'tuesday' => 'Tuesday', 'tues' => 'Tuesday', 'tue' => 'Tuesday',
        'wednesday' => 'Wednesday', 'wed' => 'Wednesday',
        'thursday' => 'Thursday', 'thurday' => 'Thursday',   // the sheet's own typo
        'thurs' => 'Thursday', 'thu' => 'Thursday', 'thursdays' => 'Thursday',
        'friday' => 'Friday', 'fri' => 'Friday',
        'saturday' => 'Saturday', 'sat' => 'Saturday',
        'sunday' => 'Sunday', 'sun' => 'Sunday',
    ];

    /** Frequency text -> the vg_options.meeting_frequency value the app stores. */
    private const FREQ_MAP = [
        'weekly' => 'weekly', 'onceaweek' => 'weekly', 'everyweek' => 'weekly',
        'everyotherweek' => 'every other week', 'eow' => 'every other week',
        'biweekly' => 'every other week', 'twiceamonth' => 'twice a month',
        'thriceamonth' => 'thrice a month', 'onceamonth' => 'once a month',
        'monthly' => 'once a month', '23timesinamonth' => '2-3 times in a month',
    ];

    public function __construct($db) { $this->db = $db; }

    // ── Small helpers ───────────────────────────────────────────────────────

    /** Collapse whitespace, keep the text otherwise exactly as written. */
    public static function clean($v): string {
        return trim(preg_replace('/\s+/u', ' ', (string)$v));
    }

    /** Comparison key for a LABEL cell: lower-cased, non-alphanumerics dropped. */
    private static function labelKey($v): string {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$v), 'UTF-8')) ?? '';
    }

    /** True for "1.", "2", "10." — the numbered Members slots. */
    private static function isMemberSlot($v): bool {
        $v = trim((string)$v);
        return $v !== '' && preg_match('/^\d{1,3}\.?$/', $v) === 1;
    }

    /**
     * A couple / compound name — "Amy & Carl", "Hazel Lyn and Emmanuel Gabrido".
     * These are NEVER split and never auto-matched: the string refers to two
     * people, so there is no single member it could honestly link to.
     */
    public static function isCoupleName(string $name): bool {
        return preg_match('/\s(&|and|\+)\s/iu', $name) === 1 || strpos($name, '&') !== false;
    }

    /**
     * A single word with no surname — "Myka", "Stephen", "Clint", "Val".
     * Perfectly real as written, but far too thin to match a member on.
     */
    public static function isMononym(string $name): bool {
        return $name !== '' && strpos(self::clean($name), ' ') === false;
    }

    // ── Time normalisation ──────────────────────────────────────────────────

    /**
     * Excel hands times over in three shapes, all of which appear in the real
     * file. Returns ['H:i' or null, note].
     *
     *   0.41666666666667  Excel time fraction  -> 10:00
     *   "12:00 NN"        noon with a Filipino -> 12:00
     *                     suffix
     *   "3-5PM" / "1PM-2:30PM"  a RANGE        -> 15:00 (the start only; the
     *                                            schema has one meeting_time)
     *
     * Anything unreadable returns null rather than a guess — the raw text is
     * kept in raw_time either way, so nothing is lost.
     */
    public static function parseTime($raw): array {
        $raw = self::clean($raw);
        if ($raw === '') return [null, null];

        // 1. Excel serial fraction, e.g. 0.79166666666667 = 7:00 PM.
        if (is_numeric($raw)) {
            $f = (float)$raw;
            if ($f < 0 || $f >= 2) return [null, 'Time "' . $raw . '" is not a recognisable time.'];
            $f = fmod($f, 1.0);
            $mins = (int)round($f * 1440);
            $mins = max(0, min(1439, $mins));
            return [sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60), null];
        }

        $note = null;
        $text = $raw;

        // 2. A range: keep the start, say so on the review screen.
        if (preg_match('/^(.+?)\s*(?:-|–|—|to)\s*(.+)$/iu', $text, $m)) {
            $note = 'Written as a range ("' . $raw . '"). The start time is used; the sheet has one Time field.';
            $text = trim($m[1]);
            // "3-5PM": the AM/PM lives on the END of the range only.
            if (!preg_match('/[ap]\.?m|nn|noon/i', $text) && preg_match('/([ap])\.?m/i', $m[2], $ap)) {
                $text .= ' ' . strtoupper($ap[1]) . 'M';
            }
        }

        // 3. "12:00 NN" / "12 NN" — Filipino shorthand for noon.
        if (preg_match('/\b(nn|noon)\b/i', $text)) {
            $text = trim(preg_replace('/\b(nn|noon)\b/i', '', $text));
            if ($text === '' || $text === '12' || $text === '12:00') return ['12:00', $note];
            $text .= ' PM';
        }

        $ts = strtotime('2000-01-01 ' . $text);
        if ($ts === false) {
            return [null, 'Time "' . $raw . '" could not be read. The group will be created without a meeting time.'];
        }
        return [date('H:i', $ts), $note];
    }

    // ── Classification ──────────────────────────────────────────────────────

    /** "Type of VG" text -> [group_kind, audience]. Unknown text stays a Victory Group but is flagged. */
    public static function classify(string $rawType): array {
        $key = self::labelKey($rawType);
        if ($key === '') return ['victory_group', '', true];

        // Campus wins outright, wherever the word appears in the cell.
        if (strpos($key, 'campus') !== false) return ['campus', 'campus', false];

        if (isset(self::AUDIENCE_MAP[$key])) {
            [$kind, $aud] = self::AUDIENCE_MAP[$key];
            return [$kind, $aud, false];
        }
        // "Couples VG", "Single Men VG" etc. — try again with a trailing "vg" dropped.
        $trimmed = preg_replace('/vg$/', '', $key);
        if ($trimmed !== $key && isset(self::AUDIENCE_MAP[$trimmed])) {
            [$kind, $aud] = self::AUDIENCE_MAP[$trimmed];
            return [$kind, $aud, false];
        }
        if (strpos($key, 'leadership') !== false) return ['leadership_group', '', false];

        return ['victory_group', mb_strtolower(self::clean($rawType), 'UTF-8'), true];
    }

    public static function normalizeDay(string $raw): array {
        $raw = self::clean($raw);
        if ($raw === '') return ['', false];
        $key = self::labelKey($raw);
        if (isset(self::DAY_MAP[$key])) return [self::DAY_MAP[$key], false];
        return [$raw, true];
    }

    public static function normalizeFrequency(string $raw): array {
        $raw = self::clean($raw);
        if ($raw === '') return ['weekly', false];
        $key = self::labelKey($raw);
        if (isset(self::FREQ_MAP[$key])) return [self::FREQ_MAP[$key], false];
        return [mb_strtolower($raw, 'UTF-8'), true];
    }

    // ── Parse ───────────────────────────────────────────────────────────────

    /**
     * Read the schedule into group cards.
     *
     * Returns ['cards' => [...], 'error' => ?string]. Every card is
     *   ['sheet','cell','source_label','raw_type','day','raw_time',
     *    'frequency','venue','people' => [['role','name','slot'], ...]]
     * with `people` in sheet order: leader, intern, then the numbered members.
     *
     * NOTHING is written here. This function only reads.
     */
    public function parseFile(string $path, string $originalName = ''): array {
        if (!is_readable($path)) {
            return ['cards' => [], 'error' => 'The uploaded file could not be read.'];
        }
        try {
            // An upload's temp file has no extension, and PhpSpreadsheet then
            // falls back to mime_content_type(), which is fatal where
            // ext-fileinfo is off. The original filename is the reliable hint.
            // See MemberImport::openSpreadsheet() for the full reasoning.
            $book = MemberImport::openSpreadsheet($path, $originalName);
        } catch (\Throwable $e) {
            error_log('GroupImport::parseFile: ' . $e->getMessage());
            return ['cards' => [],
                    'error' => 'That file could not be opened as a spreadsheet. Please save it as .xlsx and try again.'];
        }

        $cards = [];
        foreach ($book->getSheetNames() as $sheetName) {
            $grid = $book->getSheetByName($sheetName)->toArray(null, true, false, false);
            if (!$grid) continue;
            foreach ($this->parseGrid($grid, $sheetName) as $card) $cards[] = $card;
        }

        if (!$cards) {
            return ['cards' => [],
                    'error' => 'No group cards were found. The schedule needs a "VGL:" label with the leader\'s '
                             . 'name in the cell beside it, and a "Type of VG:" / "Day:" / "Time:" block below.'];
        }
        return ['cards' => $cards, 'error' => null];
    }

    /**
     * Find every card on one sheet.
     *
     * A card opens at a "VGL:" label cell and owns the value column immediately
     * to its right. It stays open while the rows below continue to hold its own
     * labels (Intern / Members / 1. / Type of VG / ... / Venue) and closes at
     * its Venue row, at the next VGL: in the same column, or when the labels
     * run out. Cards in different columns are completely independent — in the
     * real sheet one column's card runs 9 members long while its neighbour's
     * next card has already started.
     */
    private function parseGrid(array $grid, string $sheetName): array {
        $cards   = [];
        $maxRow  = count($grid);
        $maxCol  = 0;
        foreach ($grid as $row) $maxCol = max($maxCol, count($row));

        for ($c = 0; $c < $maxCol; $c++) {
            for ($r = 0; $r < $maxRow; $r++) {
                if (self::labelKey($grid[$r][$c] ?? '') !== self::L_LEADER) continue;

                $card = $this->readCard($grid, $r, $c, $maxRow, $sheetName);
                if ($card !== null) {
                    $cards[] = $card;
                    // Jump past the card we just consumed so its own rows cannot
                    // be re-read as the start of another.
                    $r = $card['_end_row'];
                }
            }
        }
        // Drop the internal cursor before the card leaves this function.
        foreach ($cards as &$card) unset($card['_end_row']);
        unset($card);
        return $cards;
    }

    /** Read one card downward from its "VGL:" row. Returns null for an entirely blank card. */
    private function readCard(array $grid, int $startRow, int $col, int $maxRow, string $sheetName): ?array {
        $val = function (int $r) use ($grid, $col) {
            return self::clean($grid[$r][$col + 1] ?? '');
        };

        $card = [
            'sheet'        => $sheetName,
            'cell'         => $this->cellRef($col, $startRow),
            'source_label' => '',
            'raw_type'     => '',
            'day'          => '',
            'raw_time'     => '',
            'frequency'    => '',
            'venue'        => '',
            'people'       => [],
            '_end_row'     => $startRow,
        ];

        $leaderName = $val($startRow);
        if ($leaderName !== '') {
            $card['people'][] = ['role' => 'leader', 'name' => $leaderName, 'slot' => 'VGL'];
        }

        $sawVenue = false;
        for ($r = $startRow + 1; $r < $maxRow; $r++) {
            $labelCell = $grid[$r][$col] ?? '';
            $key       = self::labelKey($labelCell);

            if ($key === self::L_LEADER) break;            // the next card starts here
            if ($sawVenue) break;                          // Venue is the last line of a card

            if ($key === self::L_INTERN) {
                $n = $val($r);
                if ($n !== '') $card['people'][] = ['role' => 'intern', 'name' => $n, 'slot' => 'Intern'];
                $card['_end_row'] = $r; continue;
            }
            if ($key === self::L_MEMBERS) { $card['_end_row'] = $r; continue; }
            if (self::isMemberSlot($labelCell)) {
                $n = $val($r);
                if ($n !== '') {
                    $card['people'][] = ['role' => 'attendee', 'name' => $n, 'slot' => trim((string)$labelCell)];
                }
                $card['_end_row'] = $r; continue;
            }
            if ($key === self::L_TYPE)  { $card['raw_type']  = $val($r); $card['_end_row'] = $r; continue; }
            if ($key === self::L_DAY)   { $card['day']       = $val($r); $card['_end_row'] = $r; continue; }
            if ($key === self::L_TIME)  { $card['raw_time']  = $val($r); $card['_end_row'] = $r; continue; }
            if ($key === self::L_FREQ)  { $card['frequency'] = $val($r); $card['_end_row'] = $r; continue; }
            if ($key === self::L_VENUE) {
                $card['venue'] = $val($r); $card['_end_row'] = $r; $sawVenue = true; continue;
            }
            // A blank spacer row inside a card is normal; anything else ends it.
            if ($key === '' && self::clean($grid[$r][$col + 1] ?? '') === '') continue;
            break;
        }

        // An empty slot in the printed grid ("VGL:" with nothing anywhere) is a
        // blank template cell, not a group. Skip it silently.
        if (!$card['people'] && $card['raw_type'] === '' && $card['day'] === ''
            && $card['raw_time'] === '' && $card['venue'] === '') {
            return null;
        }

        // The verbatim label the church would recognise this card by: its
        // leader as written, with the type of VG when the sheet gave one.
        $lead = $card['people'][0]['name'] ?? '';
        $card['source_label'] = self::clean(
            ($card['raw_type'] !== '' ? $card['raw_type'] . ' — ' : '') . ($lead !== '' ? 'VGL: ' . $lead : 'VGL: (none)')
        );
        return $card;
    }

    /** 0-based column + 0-based row -> "B6", for "find this in your file". */
    private function cellRef(int $col, int $row): string {
        $ref = '';
        for ($n = $col + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $ref = chr(65 + (($n - 1) % 26)) . $ref;
        }
        return $ref . ($row + 1);
    }

    // ── Validate + stage ────────────────────────────────────────────────────

    /**
     * Classify every card and every person, write them to the staging tables,
     * and return the batch id.
     *
     * NOTHING is written to victory_groups or vg_members here. This is the
     * step whose entire output is a preview.
     */
    public function stage(array $cards): string {
        $batchId = $this->uuid();
        $index   = $this->memberIndex();

        $insCard = $this->db->prepare(
            "INSERT INTO group_import_cards
                (batch_id, card_index, sheet_name, cell_ref, raw_data, source_label, group_kind,
                 audience, raw_audience, group_name, day_of_week, meeting_time, raw_time,
                 meeting_frequency, venue, people_count, needs_link_count, row_status, issues)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $insPerson = $this->db->prepare(
            "INSERT INTO group_import_staging
                (batch_id, card_index, sort_order, raw_data, source_label, group_kind, audience,
                 day_of_week, meeting_time, raw_time, meeting_frequency, venue,
                 person_role, display_name, is_couple_name, matched_member_id, link_status,
                 row_status, issues)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        foreach ($cards as $i => $card) {
            $issues = [];

            [$kind, $audience, $unknownType] = self::classify($card['raw_type']);
            if ($card['raw_type'] === '') {
                $issues[] = 'No "Type of VG" on this card — filed as a Victory Group. Check it is not a Campus group.';
            } elseif ($unknownType) {
                $issues[] = 'Type of VG "' . $card['raw_type'] . '" is not one the app knows. '
                          . 'Filed as a Victory Group with that text kept as the audience.';
            }
            if ($kind === 'campus') {
                $issues[] = 'Filed as a CAMPUS group, not a Victory Group — it will not be counted as one.';
            }

            [$day, $dayOdd] = self::normalizeDay($card['day']);
            if ($card['day'] === '')      $issues[] = 'No meeting day on this card.';
            elseif ($dayOdd)              $issues[] = 'Day "' . $card['day'] . '" is not a day name — saved as written.';

            [$time, $timeNote] = self::parseTime($card['raw_time']);
            if ($timeNote !== null)       $issues[] = $timeNote;
            elseif ($card['raw_time'] === '') $issues[] = 'No meeting time on this card.';

            [$freq, $freqOdd] = self::normalizeFrequency($card['frequency']);
            if ($freqOdd) $issues[] = 'Frequency "' . $card['frequency'] . '" is not a standard option — saved as written.';

            if (self::clean($card['venue']) === '') $issues[] = 'No venue on this card.';

            // Leaders of a Leadership Group hold a different role to a VG leader.
            $leaderRole = $kind === 'leadership_group' ? 'lg_leader' : 'leader';

            $people     = [];
            $needsLink  = 0;
            $seenOnCard = [];
            foreach ($card['people'] as $order => $person) {
                $name = self::clean($person['name']);
                if ($name === '') continue;

                $role = $person['role'] === 'leader' ? $leaderRole : $person['role'];
                $p    = $this->classifyPerson($name, $index);
                $p['role']  = $role;
                $p['slot']  = $person['slot'];
                $p['order'] = $order;

                // The same person listed twice on one card is a transcription slip,
                // not two people. Flagged, never silently merged or dropped.
                $dupKey = MemberImport::nameKey($name);
                if (isset($seenOnCard[$dupKey])) {
                    $p['issues'][] = 'Also listed on this card as "' . $seenOnCard[$dupKey] . '".';
                } else {
                    $seenOnCard[$dupKey] = $person['slot'];
                }

                if ($p['link_status'] !== 'linked') $needsLink++;
                $people[] = $p;
            }

            if (!$people) {
                $issues[]   = 'No leader and no members on this card — there is nothing to create.';
                $cardStatus = 'invalid';
            } elseif ($issues || $needsLink > 0) {
                $cardStatus = 'needs_review';
            } else {
                $cardStatus = 'ready';
            }
            if ($needsLink > 0 && $cardStatus !== 'invalid') {
                array_unshift($issues, $needsLink . ' of ' . count($people)
                    . ' name(s) could not be matched to a member. They import as "Needs Link" — '
                    . 'no member record is created for them.');
            }

            // "<Audience> <Kind> — <Day>", matching the fallback name the group
            // migration builds. The audience prefix is dropped when it only
            // repeats the kind, so a Campus group reads "Campus Group", not
            // "Campus Campus Group".
            $kindLabel = $kind === 'campus' ? 'Campus Group'
                       : ($kind === 'leadership_group' ? 'Leadership Group' : 'Victory Group');
            $prefix    = ($audience !== '' && stripos($kindLabel, $audience) === false)
                       ? ucwords($audience) . ' ' : '';
            $groupName = self::clean($prefix . $kindLabel . ($day !== '' ? ' — ' . $day : ''));

            $insCard->execute([
                $batchId, $i, $card['sheet'], $card['cell'],
                json_encode($card, JSON_UNESCAPED_UNICODE),
                mb_substr($card['source_label'], 0, 255),
                $kind, mb_substr($audience, 0, 50), mb_substr(self::clean($card['raw_type']), 0, 255),
                mb_substr($groupName, 0, 150), mb_substr($day, 0, 100), $time,
                mb_substr(self::clean($card['raw_time']), 0, 100), mb_substr($freq, 0, 50),
                mb_substr(self::clean($card['venue']), 0, 255),
                count($people), $needsLink, $cardStatus,
                $issues ? json_encode(array_values($issues), JSON_UNESCAPED_UNICODE) : null,
            ]);

            foreach ($people as $p) {
                $insPerson->execute([
                    $batchId, $i, $p['order'],
                    json_encode(['slot' => $p['slot'], 'name' => $p['name']], JSON_UNESCAPED_UNICODE),
                    mb_substr($card['source_label'], 0, 255), $kind, mb_substr($audience, 0, 50),
                    mb_substr($day, 0, 100), $time, mb_substr(self::clean($card['raw_time']), 0, 100),
                    mb_substr($freq, 0, 50), mb_substr(self::clean($card['venue']), 0, 255),
                    $p['role'], mb_substr($p['name'], 0, 255), $p['is_couple'] ? 1 : 0,
                    $p['matched_member_id'], $p['link_status'],
                    $p['link_status'] === 'linked' ? 'ready' : 'needs_review',
                    $p['issues'] ? json_encode(array_values($p['issues']), JSON_UNESCAPED_UNICODE) : null,
                ]);
            }
        }
        return $batchId;
    }

    /**
     * Decide whether one written name resolves to exactly one existing member.
     *
     * THIS FUNCTION NEVER CREATES A MEMBER, and never returns a match it is not
     * certain of. Four outcomes, in order:
     *
     *   couple name   -> needs_link. "Amy & Carl" is two people; there is no
     *                    single member record it could honestly point at, and
     *                    splitting it would invent two names the church did not
     *                    write. Kept as one verbatim string.
     *   mononym       -> needs_link. "Myka", "Val" — real as written, far too
     *                    thin to match on.
     *   one exact hit -> linked.     Normalised full name or "Last, First",
     *                    case/accent/punctuation-insensitive.
     *   two+ hits or
     *   no hit        -> needs_link. Two real people genuinely can share a
     *                    name; picking one silently is the worst outcome here.
     */
    private function classifyPerson(string $name, array $index): array {
        $out = [
            'name' => $name, 'is_couple' => false,
            'matched_member_id' => null, 'link_status' => 'needs_link', 'issues' => [],
        ];

        if (self::isCoupleName($name)) {
            $out['is_couple'] = true;
            $out['issues'][] = 'Written as a couple — kept as one name exactly as spelled, never split into two people. '
                             . 'It imports as one row with TWO link slots: link each spouse to their own member '
                             . 'record by hand on the Victory Groups page, once the 2026 members are encoded.';
            return $out;
        }
        if (self::isMononym($name)) {
            $out['issues'][] = 'Only a first name is given — too little to match a member on. Needs linking by hand.';
            return $out;
        }

        $key = MemberImport::nameKey($name);
        $hits = $index[$key] ?? [];

        if (count($hits) === 1) {
            $out['matched_member_id'] = (int)$hits[0]['id'];
            $out['link_status']       = 'linked';
            return $out;
        }
        if (count($hits) > 1) {
            $out['issues'][] = count($hits) . ' members share this name — left unlinked so the wrong one is not picked.';
            return $out;
        }
        $out['issues'][] = 'No member matches this name yet. It imports as "Needs Link" — no member is created for it.';
        return $out;
    }

    /**
     * Every living member, keyed by normalised name, in one query.
     * Both "Last, First" and "First Last" spellings map to the same key, so the
     * sheet's "Joshua Demetria" finds a member stored as "Demetria, Joshua".
     * A key with more than one member is deliberately ambiguous — see above.
     */
    private function memberIndex(): array {
        $index = [];
        try {
            $rows = $this->db->query(
                "SELECT id, full_name, first_name, last_name FROM members WHERE is_deleted = 0"
            )->fetchAll();
        } catch (PDOException $e) {
            error_log('GroupImport::memberIndex: ' . $e->getMessage());
            return [];
        }
        foreach ($rows as $m) {
            $keys = [
                MemberImport::nameKey($m['full_name']),
                MemberImport::nameKey($m['first_name'] . ' ' . $m['last_name']),
                MemberImport::nameKey($m['last_name'] . ' ' . $m['first_name']),
            ];
            foreach (array_unique(array_filter($keys)) as $k) {
                // One member reachable by three spellings must not look like
                // three candidates for the same key.
                if (!isset($index[$k])) $index[$k] = [];
                foreach ($index[$k] as $existing) {
                    if ((int)$existing['id'] === (int)$m['id']) continue 2;
                }
                $index[$k][] = $m;
            }
        }
        return $index;
    }

    // ── Preview ─────────────────────────────────────────────────────────────

    /** The staged cards, each with its people attached, for the review screen. */
    public function getBatch(string $batchId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM group_import_cards WHERE batch_id = ? ORDER BY card_index"
            );
            $stmt->execute([$batchId]);
            $cards = $stmt->fetchAll() ?: [];
            if (!$cards) return [];

            $stmt = $this->db->prepare(
                "SELECT s.*, m.full_name AS matched_full_name
                   FROM group_import_staging s
                   LEFT JOIN members m ON m.id = s.matched_member_id
                  WHERE s.batch_id = ?
                  ORDER BY s.card_index, s.sort_order, s.id"
            );
            $stmt->execute([$batchId]);
            $byCard = [];
            foreach ($stmt->fetchAll() ?: [] as $p) $byCard[(int)$p['card_index']][] = $p;

            foreach ($cards as &$c) $c['people'] = $byCard[(int)$c['card_index']] ?? [];
            unset($c);
            return $cards;
        } catch (PDOException $e) {
            error_log('GroupImport::getBatch: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * The counts the review screen leads with. Every key is always present, so
     * an empty batch renders zeros rather than an undefined-index notice.
     */
    public function getSummary(string $batchId): array {
        $out = [
            'total' => 0, 'ready' => 0, 'needs_review' => 0, 'invalid' => 0,
            'victory_group' => 0, 'leadership_group' => 0, 'campus' => 0,
            'people' => 0, 'linked' => 0, 'needs_link' => 0, 'couples' => 0,
            'will_create' => 0, 'already_imported' => 0,
        ];
        try {
            $stmt = $this->db->prepare(
                "SELECT row_status, group_kind, COUNT(*) n, SUM(created_group_id IS NOT NULL) done
                   FROM group_import_cards WHERE batch_id = ? GROUP BY row_status, group_kind"
            );
            $stmt->execute([$batchId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $n = (int)$r['n'];
                $out['total']            += $n;
                $out[$r['row_status']]   += $n;
                $out[$r['group_kind']]   += $n;
                $out['already_imported'] += (int)$r['done'];
            }

            $stmt = $this->db->prepare(
                "SELECT link_status, COUNT(*) n, SUM(is_couple_name) couples
                   FROM group_import_staging WHERE batch_id = ? GROUP BY link_status"
            );
            $stmt->execute([$batchId]);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $n = (int)$r['n'];
                $out['people']  += $n;
                $out['couples'] += (int)$r['couples'];
                if ($r['link_status'] === 'linked') $out['linked'] += $n;
                else                                $out['needs_link'] += $n;
            }
        } catch (PDOException $e) {
            error_log('GroupImport::getSummary: ' . $e->getMessage());
            return $out;
        }

        // Every card that is not broken and not already imported will be created.
        // Needs-link PEOPLE never hold a group back — they are the normal case
        // before the 2026 members have been encoded.
        $out['will_create'] = max(0, $out['total'] - $out['invalid'] - $out['already_imported']);
        return $out;
    }

    // ── Commit ──────────────────────────────────────────────────────────────

    /**
     * THE ONLY function that writes to victory_groups / vg_members.
     *
     * One transaction for the whole batch: either every card lands or none
     * does. Idempotent twice over — a card with created_group_id is skipped,
     * and within a card a person with created_vg_member_id is skipped — so
     * pressing Confirm again can never produce a second copy.
     *
     * Members are NEVER created here. A needs-link person becomes a vg_members
     * row with member_id NULL and link_status='needs_link', which the Victory
     * Groups screen shows as a "Needs Link" badge for an admin to resolve.
     *
     * @param array $skipCardIds group_import_cards.id the admin unticked.
     */
    public function commit(string $batchId, array $skipCardIds = []): array {
        $cards = $this->getBatch($batchId);
        $skip  = array_flip(array_map('intval', $skipCardIds));

        $created = 0; $people = 0; $skipped = 0; $needsLink = 0; $errors = [];

        try {
            $this->db->beginTransaction();

            $insGroup = $this->db->prepare(
                "INSERT INTO victory_groups
                    (uuid, group_kind, audience, name, group_type, group_category, day_of_week,
                     meeting_time, location, leader_name, meeting_frequency, group_status,
                     notes, source_label)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,'active',?,?)"
            );
            // is_couple rides across from the staging row. It records that the
            // sheet named TWO people in one cell, which is what entitles the row
            // to a second member link on the Victory Groups screen. The string
            // itself is copied verbatim into display_name and never split.
            $insPerson = $this->db->prepare(
                "INSERT INTO vg_members
                    (group_id, member_id, link_status, is_couple, name, display_name, role, sort_order)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $markCard   = $this->db->prepare(
                "UPDATE group_import_cards SET created_group_id = ?, imported_at = NOW() WHERE id = ?"
            );
            $markPerson = $this->db->prepare(
                "UPDATE group_import_staging
                    SET created_group_id = ?, created_vg_member_id = ?, imported_at = NOW()
                  WHERE id = ?"
            );

            foreach ($cards as $card) {
                $cardId = (int)$card['id'];
                if (!empty($card['created_group_id'])) { $skipped++; continue; }   // already imported
                if ($card['row_status'] === 'invalid') { $skipped++; continue; }   // nothing to create
                if (isset($skip[$cardId]))             { $skipped++; continue; }   // admin unticked it

                // group_type is the legacy column the older views still read. It is
                // derived from the two honest columns, never the other way round.
                $legacyType = $card['group_kind'] === 'campus' ? 'VGcampus'
                            : ($card['group_kind'] === 'leadership_group' ? 'LG' : 'VG');

                $insGroup->execute([
                    $this->uuid(), $card['group_kind'], (string)$card['audience'],
                    (string)$card['group_name'], $legacyType, (string)$card['audience'],
                    (string)$card['day_of_week'], $card['meeting_time'] ?: null,
                    (string)$card['venue'],
                    // leader_name is NOT NULL on this legacy table; the verbatim name
                    // also lives on the vg_members row, which is what the app reads.
                    mb_substr($this->firstLeaderName($card), 0, 255),
                    (string)$card['meeting_frequency'],
                    'Imported from the Staff VG Schedule (' . (string)$card['sheet_name'] . ' ' . (string)$card['cell_ref'] . ').',
                    $card['source_label'],
                ]);
                $groupId = (int)$this->db->lastInsertId();

                foreach ($card['people'] as $p) {
                    if (!empty($p['created_vg_member_id'])) continue;
                    $name = (string)$p['display_name'];
                    if (trim($name) === '') continue;

                    $linked = $p['link_status'] === 'linked' && !empty($p['matched_member_id']);
                    $insPerson->execute([
                        $groupId,
                        $linked ? (int)$p['matched_member_id'] : null,
                        $linked ? 'linked' : 'needs_link',
                        !empty($p['is_couple_name']) ? 1 : 0,
                        $name, $name, $p['person_role'], (int)$p['sort_order'],
                    ]);
                    $markPerson->execute([$groupId, (int)$this->db->lastInsertId(), (int)$p['id']]);
                    $people++;
                    if (!$linked) $needsLink++;
                }

                $markCard->execute([$groupId, $cardId]);
                $created++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('GroupImport::commit: ' . $e->getMessage());
            return ['created' => 0, 'people' => 0, 'needs_link' => 0, 'skipped' => count($cards),
                    'errors'  => ['The import was cancelled and nothing was saved: ' . $e->getMessage()]];
        }
        return ['created' => $created, 'people' => $people, 'needs_link' => $needsLink,
                'skipped' => $skipped, 'errors' => $errors];
    }

    /** The card's leader as written, for the legacy NOT NULL victory_groups.leader_name. */
    private function firstLeaderName(array $card): string {
        foreach ($card['people'] ?? [] as $p) {
            if (in_array($p['person_role'], ['leader', 'lg_leader'], true)) {
                return (string)$p['display_name'];
            }
        }
        return '';
    }

    /** Throw away a batch that was never confirmed. Imported cards are kept as the audit trail. */
    public function discardBatch(string $batchId): void {
        try {
            $this->db->prepare("DELETE FROM group_import_staging WHERE batch_id = ? AND created_group_id IS NULL")
                     ->execute([$batchId]);
            $this->db->prepare("DELETE FROM group_import_cards WHERE batch_id = ? AND created_group_id IS NULL")
                     ->execute([$batchId]);
        } catch (PDOException $e) {
            error_log('GroupImport::discardBatch: ' . $e->getMessage());
        }
    }

    /** CSV of every name that will import as Needs Link, for working through offline. */
    public function buildReviewCsv(string $batchId): string {
        $out = "Card,Where,Group,Kind,Role,Name As Written,Status,Why\n";
        $cell = function ($v) { return '"' . str_replace('"', '""', (string)$v) . '"'; };
        foreach ($this->getBatch($batchId) as $card) {
            foreach ($card['people'] as $p) {
                if ($p['link_status'] === 'linked') continue;
                $why = implode('; ', json_decode($p['issues'] ?? '[]', true) ?: []);
                $out .= implode(',', [
                    (int)$card['card_index'] + 1, $cell($card['cell_ref']), $cell($card['source_label']),
                    $cell($card['group_kind']), $cell($p['person_role']), $cell($p['display_name']),
                    $cell('Needs Link'), $cell($why),
                ]) . "\n";
            }
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
