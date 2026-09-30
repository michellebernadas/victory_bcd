<?php
class ProgramAttendance {
    private $db;

    const PROGRAM_LABELS = [
        'victory_weekend'       => 'Victory Weekend',
        'church_community'      => 'Church Community',
        'making_disciples'      => 'Making Disciples',
        'empowering_leaders'    => 'Empowering Leaders',
        'leadership_113'        => 'Leadership 113',
        'spiritual_foundations' => 'Spiritual Foundations',
    ];

    const PROGRAM_COLORS = [
        'victory_weekend'       => 'primary',
        'church_community'      => 'secondary',
        'making_disciples'      => 'success',
        'empowering_leaders'    => 'warning',
        'leadership_113'        => 'danger',
        'spiritual_foundations' => 'info',
    ];

    const PROGRAM_ICONS = [
        'victory_weekend'       => 'bi-sun',
        'church_community'      => 'bi-building',
        'making_disciples'      => 'bi-person-plus',
        'empowering_leaders'    => 'bi-star',
        'leadership_113'        => 'bi-trophy',
        'spiritual_foundations' => 'bi-shield',
    ];

    /**
     * Session-status vocabulary shared by every session-based class
     * (Leadership 1-1-3, Spiritual Foundations, …).
     *
     * ATTENDED_STATUSES  count towards completion.
     * NO_CLASS_STATUSES  are skipped entirely — neither attended nor required.
     * Anything else (most importantly 'A') counts as required-but-missed.
     */
    const ATTENDED_STATUSES = ['P', 'L', 'MUSIC SUMMIT', 'KIDS SUMMIT'];
    const NO_CLASS_STATUSES = ['NO CLASS', 'HOLY WEEK', 'DC 2023', 'NC', ''];

    /**
     * Statuses a participant may legitimately be given for a session that DID
     * happen. Spiritual Foundations is validated against this list, because
     * "the class didn't happen" is a property of the session — it belongs in the
     * curriculum as a No Class break row, not on one person's attendance row.
     */
    const PARTICIPANT_STATUSES = ['P', 'A', 'L', 'MUSIC SUMMIT', 'KIDS SUMMIT'];

    /**
     * The class itself did not take place on that date, so it applies to every
     * participant in the batch. Verified in the Leadership 1-1-3 data: these
     * values always appear on ALL participants sharing a date.
     */
    const CLASS_CANCELLED_STATUSES = ['NO CLASS', 'HOLY WEEK', 'DC 2023'];

    /**
     * Per-participant "this session is not counted for this person" — used by
     * Leadership 1-1-3 for someone who joined a batch late (the class ran, they
     * simply weren't enrolled yet), which is why it is excluded from THEIR
     * required total but not from their classmates'. Historical meaning; kept
     * as-is so existing L113 records are not re-interpreted.
     */
    const NOT_ENROLLED_STATUSES = ['NC'];

    public static function isClassCancelledStatus(?string $status): bool {
        return in_array(strtoupper(trim((string)$status)), self::CLASS_CANCELLED_STATUSES, true);
    }

    public static function isNotEnrolledStatus(?string $status): bool {
        return in_array(strtoupper(trim((string)$status)), self::NOT_ENROLLED_STATUSES, true);
    }

    /**
     * Valid participant-level statuses: the person attended, missed, was late,
     * or the topic was not required of them (NC). Class-level cancellations are
     * NOT in this list — those describe the session, not one person.
     */
    public static function isParticipantStatus(?string $status): bool {
        $s = strtoupper(trim((string)$status));
        return in_array($s, self::PARTICIPANT_STATUSES, true) || in_array($s, self::NOT_ENROLLED_STATUSES, true);
    }

    /**
     * ONE definition of the status colour language, shared by Leadership 1-1-3
     * and Spiritual Foundations so the same status never means two things
     * visually.
     *
     * Three strings per status, for three different jobs:
     *   label — the badge glyph ("P", "NC")
     *   text  — a short cell value ("Not required"); the badge beside it already
     *           carries the code, so the cell must not repeat the whole legend
     *   title — the full explanation, for tooltips only
     *
     * @return array{badge:string,label:string,text:string,title:string,kind:string}
     *         kind: attended | late | absent | no_class | not_required | other
     */
    public static function statusStyle(?string $status): array {
        $s = strtoupper(trim((string)$status));

        if (self::isNotEnrolledStatus($s)) {
            return ['badge' => 'bg-light text-muted border', 'label' => 'NC', 'kind' => 'not_required',
                    'text'  => 'Not required',
                    'title' => 'NC — not required of this participant (e.g. already completed in an earlier batch)'];
        }
        if (self::isClassCancelledStatus($s)) {
            return ['badge' => 'bg-secondary', 'label' => $s, 'kind' => 'no_class',
                    'text'  => 'No class held',
                    'title' => $s . ' — no class was held for the whole batch'];
        }
        if ($s === 'L') {
            return ['badge' => 'bg-warning text-dark', 'label' => 'L', 'kind' => 'late',
                    'text'  => 'Late', 'title' => 'Late — counts as completed'];
        }
        if (self::isAttendedStatus($s)) {
            return ['badge' => 'bg-success', 'label' => $s === 'P' ? 'P' : $s, 'kind' => 'attended',
                    'text'  => $s === 'P' ? 'Present' : ucwords(strtolower($s)),
                    'title' => $s === 'P' ? 'Present — counts as completed' : $s . ' — counts as completed'];
        }
        if ($s === 'A') {
            return ['badge' => 'bg-danger', 'label' => 'A', 'kind' => 'absent',
                    'text'  => 'Absent', 'title' => 'Absent — not completed'];
        }
        if ($s === '') {
            return ['badge' => 'bg-light text-muted border', 'label' => '—', 'kind' => 'other',
                    'text'  => 'No record', 'title' => 'No record'];
        }
        return ['badge' => 'bg-warning text-dark', 'label' => $s, 'kind' => 'other',
                'text'  => ucwords(strtolower($s)), 'title' => $s];
    }

    /** Class types whose attendance is recorded as a per-session grid in extra_data. */
    const SESSION_PROGRAMS = ['leadership_113', 'spiritual_foundations'];

    public static function isAttendedStatus(?string $status): bool {
        return in_array(strtoupper(trim((string)$status)), self::ATTENDED_STATUSES, true);
    }

    public static function isNoClassStatus(?string $status): bool {
        return in_array(strtoupper(trim((string)$status)), self::NO_CLASS_STATUSES, true);
    }

    /**
     * Normalizes a record's extra_data into session counters.
     * Accepts the raw JSON string, an already-decoded array, or null.
     *
     * @return array{sessions:array,attended:int,required:int,absent:int,percent:int,missed:array}
     *         `required` excludes NO CLASS rows. `missed` lists the session keys
     *         that are required but not attended.
     */
    public static function sessionStats($extraData): array {
        $ed = is_string($extraData) ? json_decode($extraData, true) : $extraData;
        $sessions = (is_array($ed) && !empty($ed['sessions']) && is_array($ed['sessions'])) ? $ed['sessions'] : [];

        $attended = 0; $required = 0; $absent = 0; $missed = [];
        foreach ($sessions as $key => $status) {
            if (self::isNoClassStatus($status)) continue;   // break weeks never count
            $required++;
            if (self::isAttendedStatus($status)) {
                $attended++;
            } else {
                $missed[] = (string)$key;
                if (strtoupper(trim((string)$status)) === 'A') $absent++;
            }
        }
        return [
            'sessions' => $sessions,
            'attended' => $attended,
            'required' => $required,
            'absent'   => $absent,
            'percent'  => $required > 0 ? (int)round($attended / $required * 100) : 0,
            'missed'   => $missed,
        ];
    }

    /**
     * Certificate eligibility for one participant record. Always computed from
     * the live session grid — never stored, never hard-coded per person.
     *
     * A participant is eligible only when BOTH hold:
     *   1. every required (non-NO-CLASS) session they have is attended, and
     *   2. they have at least $expectedSessions required sessions on file
     *      (so a half-filled grid can't pass). Pass 0 to skip that check.
     *
     * When $expectedSessions is given (Spiritual Foundations passes its 10
     * curriculum weeks) it is the authoritative denominator: a no-class value
     * sitting on one person's row can never shrink what is required of them.
     * With 0 (Leadership 1-1-3, whose batches vary in length) the denominator
     * comes from the grid, which is what its historical records rely on.
     *
     * @return array{eligible:bool,attended:int,required:int,expected:int,missed:array,reason:string}
     */
    public static function certificateStatus($extraData, int $expectedSessions = 0): array {
        $s        = self::sessionStats($extraData);
        $required = $s['required'];
        $attended = $s['attended'];
        $target   = $expectedSessions > 0 ? max($expectedSessions, $required) : $required;

        $eligible = false;
        $reason   = '';
        if ($required === 0) {
            $reason = 'No session attendance recorded yet.';
        } elseif (!empty($s['missed'])) {
            $reason = count($s['missed']) . ' session(s) not completed: ' . implode(', ', $s['missed']);
        } elseif ($expectedSessions > 0 && $required < $expectedSessions) {
            $reason = 'Only ' . $required . ' of ' . $expectedSessions . ' sessions recorded.';
        } else {
            $eligible = true;
            $reason   = 'All required sessions completed.';
        }

        return [
            'eligible' => $eligible,
            'attended' => $attended,
            'required' => $required,
            'expected' => $target,
            'missed'   => $s['missed'],
            'reason'   => $reason,
        ];
    }

    public function __construct($db) {
        $this->db = $db;
    }

    public function getByMember(int $memberId): array {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM program_attendances
                WHERE member_id = ? AND is_deleted = 0
                ORDER BY program_type, program_year
            ");
            $stmt->execute([$memberId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getByMember error: " . $e->getMessage());
            return [];
        }
    }

    public function getByMemberGrouped(int $memberId): array {
        $rows = $this->getByMember($memberId);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['program_type']][$row['program_year']][] = $row;
        }
        return $grouped;
    }

    public function getSummaryStats(): array {
        try {
            $stmt = $this->db->query("
                SELECT program_type, program_year, COUNT(*) as count
                FROM program_attendances
                WHERE is_deleted = 0
                GROUP BY program_type, program_year
                ORDER BY program_type, program_year
            ");
            $rows = $stmt->fetchAll();
            $stats = [];
            foreach ($rows as $row) {
                $stats[$row['program_type']][$row['program_year']] = $row['count'];
            }
            return $stats;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Unique-member counts per program_type/program_year. Used by the All Classes pivot view
     * so the year-filter button counts match the actual pivot rows displayed (one per member).
     * Matched members are deduped by member_id; unmatched rows are deduped by lower-cased
     * full_name_display — same key the view uses to build pivot rows.
     */
    public function getMemberStats(): array {
        try {
            $stmt = $this->db->query("
                SELECT program_type, program_year,
                       COUNT(DISTINCT IF(member_id IS NOT NULL,
                                         CONCAT('m_', member_id),
                                         CONCAT('u_', LOWER(TRIM(full_name_display))))) AS count
                FROM program_attendances
                WHERE is_deleted = 0
                GROUP BY program_type, program_year
                ORDER BY program_type, program_year
            ");
            $rows = $stmt->fetchAll();
            $stats = [];
            foreach ($rows as $row) {
                $stats[$row['program_type']][$row['program_year']] = (int)$row['count'];
            }
            return $stats;
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getTotalByProgram(): array {
        try {
            $stmt = $this->db->query("
                SELECT program_type, COUNT(*) as total, COUNT(DISTINCT member_id) as matched_members
                FROM program_attendances
                WHERE is_deleted = 0
                GROUP BY program_type
            ");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Distinct PEOPLE per class, plus how many of them are linked to a member.
     *
     * getTotalByProgram() counts rows, which double-counts anyone who attended
     * the same class in two different years — so it answers "how many records"
     * rather than the question a leader actually asks, "how many people".
     * Identity is member_id when linked, else the normalised display name (the
     * same key getMemberStats() uses).
     *
     * @return array<string,array{people:int,records:int,linked:int,unlinked:int}>
     */
    public function getParticipantsByProgram(): array {
        try {
            $rows = $this->db->query(
                "SELECT program_type,
                        COUNT(*) AS records,
                        COUNT(DISTINCT IF(member_id IS NOT NULL,
                                          CONCAT('m_', member_id),
                                          CONCAT('u_', LOWER(TRIM(full_name_display))))) AS people,
                        COUNT(DISTINCT member_id) AS linked
                   FROM program_attendances
                  WHERE is_deleted = 0 AND status = 'active'
                  GROUP BY program_type"
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $people = (int)$r['people'];
                $linked = (int)$r['linked'];
                $out[$r['program_type']] = [
                    'people'   => $people,
                    'records'  => (int)$r['records'],
                    'linked'   => $linked,
                    'unlinked' => max(0, $people - $linked),
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log("getParticipantsByProgram error: " . $e->getMessage());
            return [];
        }
    }

    public function getUnmatchedCount(): int {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM program_attendances WHERE member_id IS NULL AND is_deleted = 0")->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function getRecentAttendances(int $limit = 20): array {
        try {
            $stmt = $this->db->prepare("
                SELECT pa.*, m.full_name as member_name, m.ministry
                FROM program_attendances pa
                LEFT JOIN members m ON pa.member_id = m.id
                WHERE pa.is_deleted = 0
                ORDER BY pa.dateadded DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function searchByName(string $name): array {
        try {
            $stmt = $this->db->prepare("
                SELECT pa.*, m.full_name as member_name
                FROM program_attendances pa
                LEFT JOIN members m ON pa.member_id = m.id
                WHERE pa.full_name_display LIKE ? AND pa.is_deleted = 0
                ORDER BY pa.program_type, pa.program_year
            ");
            $stmt->execute(['%' . $name . '%']);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getFiltered(array $filters): array {
        try {
            $where = ['pa.is_deleted = 0'];
            $params = [];
            if (!empty($filters['program_type'])) {
                $where[] = 'pa.program_type = ?';
                $params[] = $filters['program_type'];
            }
            if (!empty($filters['program_year'])) {
                $where[] = 'pa.program_year = ?';
                $params[] = (int)$filters['program_year'];
            }
            if (!empty($filters['search'])) {
                $where[] = 'pa.full_name_display LIKE ?';
                $params[] = '%' . $filters['search'] . '%';
            }
            if (!empty($filters['event_date_from'])) {
                $where[] = 'pa.event_date >= ?';
                $params[] = $filters['event_date_from'];
            }
            if (!empty($filters['event_date_to'])) {
                $where[] = 'pa.event_date <= ?';
                $params[] = $filters['event_date_to'];
            }
            $sql = "SELECT pa.*,
                           m.full_name  AS member_name,
                           m.ministry   AS member_ministry,
                           m.uuid       AS member_uuid
                    FROM program_attendances pa
                    LEFT JOIN members m ON pa.member_id = m.id";
            if ($where) $sql .= " WHERE " . implode(" AND ", $where);
            $sql .= " ORDER BY pa.event_date DESC, pa.program_year DESC, pa.full_name_display";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("getFiltered error: " . $e->getMessage());
            return [];
        }
    }

    public function getAvailableYears(): array {
        try {
            $stmt = $this->db->query("SELECT DISTINCT program_year FROM program_attendances WHERE is_deleted = 0 ORDER BY program_year DESC");
            return array_column($stmt->fetchAll(), 'program_year');
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getDistinctEventDatesByTypeAndYear(string $programType, int $programYear): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT event_date FROM program_attendances
                 WHERE program_type = ? AND program_year = ? AND event_date IS NOT NULL AND is_deleted = 0
                 ORDER BY event_date"
            );
            $stmt->execute([$programType, $programYear]);
            return array_column($stmt->fetchAll(), 'event_date');
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getAvailableYearsByType(string $programType): array {
        try {
            $stmt = $this->db->prepare("SELECT DISTINCT program_year FROM program_attendances WHERE program_type=? AND is_deleted = 0 ORDER BY program_year DESC");
            $stmt->execute([$programType]);
            return array_column($stmt->fetchAll(), 'program_year');
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getDistinctBatchesByType(string $programType): array {
        try {
            $stmt = $this->db->prepare("SELECT DISTINCT program_label FROM program_attendances WHERE program_type=? AND program_label != '' AND is_deleted = 0 ORDER BY program_year DESC, program_label");
            $stmt->execute([$programType]);
            return array_column($stmt->fetchAll(), 'program_label');
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Returns all distinct batch_label values keyed by program_type for Select2 pre-loading. */
    public function getAllDistinctBatchLabels(): array {
        try {
            $rows = $this->db->query(
                "SELECT program_type, batch_label
                 FROM program_attendances
                 WHERE batch_label IS NOT NULL AND batch_label != '' AND is_deleted = 0
                 GROUP BY program_type, batch_label
                 ORDER BY program_type, batch_label"
            )->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[$r['program_type']][] = $r['batch_label'];
            }
            return $result;
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Returns all distinct event labels keyed by program_type for Select2 pre-loading */
    public function getAllDistinctLabels(): array {
        try {
            $rows = $this->db->query(
                "SELECT program_type, program_label, event_date
                 FROM program_attendances
                 WHERE program_label != '' AND is_deleted = 0
                 GROUP BY program_type, program_label
                 ORDER BY program_type, program_year DESC, program_label"
            )->fetchAll();
            $result = [];
            foreach ($rows as $r) {
                $result[$r['program_type']][] = [
                    'label' => $r['program_label'],
                    'date'  => $r['event_date'] ?? '',
                ];
            }
            return $result;
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Returns all distinct counselor names for Select2 pre-loading */
    public function getAllDistinctCounselors(): array {
        try {
            return $this->db->query(
                "SELECT DISTINCT counselor_name FROM program_attendances
                 WHERE counselor_name != '' AND is_deleted = 0 ORDER BY counselor_name"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getCountByFilter(string $program_type = '', int $program_year = 0): int {
        try {
            $where = ['is_deleted = 0'];
            $params = [];
            if ($program_type) { $where[] = 'program_type = ?'; $params[] = $program_type; }
            if ($program_year) { $where[] = 'program_year = ?'; $params[] = $program_year; }
            $sql = "SELECT COUNT(*) FROM program_attendances WHERE " . implode(" AND ", $where);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function getById(int $id): array|false {
        try {
            $stmt = $this->db->prepare("SELECT * FROM program_attendances WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    private static $defaultLabels = [
        'victory_weekend'       => 'VICTORY WEEKEND',
        'church_community'      => 'CHURCH COMMUNITY',
        'making_disciples'      => 'MAKING DISCIPLES',
        'empowering_leaders'    => 'EMPOWERING LEADERS',
        'leadership_113'        => 'LEADERSHIP 113',
        'spiritual_foundations' => 'SPIRITUAL FOUNDATIONS',
    ];

    public function add(array $data): int|false {
        try {
            $fn = trim(($data['raw_first_name'] ?? '') . ' ' . ($data['raw_last_name'] ?? ''));
            if (!$fn) $fn = trim($data['full_name_display'] ?? '');
            $pt = $data['program_type'] ?? '';
            // Auto-populate program_label from program_type when not provided
            $label = trim($data['program_label'] ?? '');
            if (empty($label)) $label = self::$defaultLabels[$pt] ?? strtoupper(str_replace('_', ' ', $pt));
            $data['program_label'] = $label;
            // Build extra_data for L113 sessions (kept in JSON; other program extra fields
            // now have dedicated columns). MD records may also use extra_data to store
            // per-part dates (e.g. 2025 MD has separate dates for Part 1 and Part 2).
            $extraData = null;
            if (!empty($data['l113_extra'])) {
                $extraData = $data['l113_extra']; // caller passes JSON string
            }
            $batchLabel = trim($data['batch_label'] ?? '');
            $notes      = trim($data['notes'] ?? '');
            // The remark belongs to the WATER BAPTISM data point (program_attendances.water_baptism),
            // not to any one class. If the record says the person was not baptized there is nothing
            // to remark on, so the value is dropped — a remark can never be orphaned on a
            // non-baptism record.
            $isBaptized  = !empty($data['water_baptism']);
            $wbapRemarks = $isBaptized ? trim($data['water_baptism_remarks'] ?? '') : '';
            $stmt = $this->db->prepare("
                INSERT INTO program_attendances
                    (member_id, raw_last_name, raw_first_name, full_name_display,
                     program_type, program_year, program_label, event_date,
                     counselor_name, counselor_contact,
                     water_baptism, water_baptism_remarks, contact_number,
                     md_part1, md_part2,
                     batch_label, notes, extra_data)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                ($data['member_id'] ?? null) ?: null,
                trim($data['raw_last_name'] ?? ''),
                trim($data['raw_first_name'] ?? ''),
                $fn,
                $pt,
                (int)($data['program_year'] ?? date('Y')),
                trim($data['program_label'] ?? ''),
                ($data['event_date'] ?? '') ?: null,
                trim($data['counselor_name'] ?? ''),
                trim($data['counselor_contact'] ?? '') ?: null,
                !empty($data['water_baptism']) ? 1 : 0,
                $wbapRemarks !== '' ? $wbapRemarks : null,
                trim($data['contact_number'] ?? ''),
                ($pt === 'making_disciples' && !empty($data['md_part1']))  ? 1 : 0,
                ($pt === 'making_disciples' && !empty($data['md_part2']))  ? 1 : 0,
                $batchLabel !== '' ? $batchLabel : null,
                $notes !== '' ? $notes : null,
                $extraData,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("ProgramAttendance::add error: " . $e->getMessage());
            return false;
        }
    }

    public function update(int $id, array $data): bool {
        try {
            $fn = trim(($data['raw_first_name'] ?? '') . ' ' . ($data['raw_last_name'] ?? ''));
            if (!$fn) $fn = trim($data['full_name_display'] ?? '');
            $pt = $data['program_type'] ?? '';
            // Auto-populate program_label from program_type when not provided
            $label = trim($data['program_label'] ?? '');
            if (empty($label)) $label = self::$defaultLabels[$pt] ?? strtoupper(str_replace('_', ' ', $pt));
            $data['program_label'] = $label;
            // Preserve or update extra_data. L113 uses it for sessions; MD uses it for per-part dates.
            $extraDataSql = 'extra_data=?';
            $extraDataVal = null;
            if (!empty($data['l113_extra'])) {
                $extraDataVal = $data['l113_extra'];
            } elseif (in_array($pt, self::SESSION_PROGRAMS, true) || $pt === 'making_disciples') {
                // Keep existing extra_data unchanged when not provided
                $extraDataSql = 'extra_data=COALESCE(?,extra_data)';
            }
            // batch_label: only update when the caller explicitly sent the field.
            $batchSql = 'batch_label=batch_label';
            $batchVal = null;
            $batchProvided = array_key_exists('batch_label', $data);
            if ($batchProvided) {
                $batchSql = 'batch_label=?';
                $batchVal = trim($data['batch_label'] ?? '');
                $batchVal = $batchVal !== '' ? $batchVal : null;
            }
            // notes: same pattern — only touch when the form actually sent the field.
            $notesSql = 'notes=notes';
            $notesVal = null;
            $notesProvided = array_key_exists('notes', $data);
            if ($notesProvided) {
                $notesSql = 'notes=?';
                $notesVal = trim($data['notes'] ?? '');
                $notesVal = $notesVal !== '' ? $notesVal : null;
            }
            // water_baptism_remarks belongs to the WATER BAPTISM flag, so it is only written
            // when the form actually sent the field (class views that don't render it can't
            // blank an existing remark), and it is always cleared when water_baptism is 0.
            $wbapSql = 'water_baptism_remarks=water_baptism_remarks';
            $wbapVal = null;
            $wbapProvided = array_key_exists('water_baptism_remarks', $data);
            if (empty($data['water_baptism'])) {
                // Not baptized on this record → no baptism remark can apply to it.
                $wbapSql      = 'water_baptism_remarks=NULL';
                $wbapProvided = false;
            } elseif ($wbapProvided) {
                $wbapSql = 'water_baptism_remarks=?';
                $wbapVal = trim($data['water_baptism_remarks'] ?? '');
                $wbapVal = $wbapVal !== '' ? $wbapVal : null;
            }
            $stmt = $this->db->prepare("
                UPDATE program_attendances
                SET member_id=?, raw_last_name=?, raw_first_name=?, full_name_display=?,
                    program_type=?, program_year=?, program_label=?, event_date=?,
                    counselor_name=?, counselor_contact=?,
                    water_baptism=?, contact_number=?,
                    md_part1=?, md_part2=?,
                    {$wbapSql},
                    {$batchSql},
                    {$notesSql},
                    {$extraDataSql}
                WHERE id=?
            ");
            $params = [
                ($data['member_id'] ?? null) ?: null,
                trim($data['raw_last_name'] ?? ''),
                trim($data['raw_first_name'] ?? ''),
                $fn,
                $pt,
                (int)($data['program_year'] ?? date('Y')),
                trim($data['program_label'] ?? ''),
                ($data['event_date'] ?? '') ?: null,
                trim($data['counselor_name'] ?? ''),
                trim($data['counselor_contact'] ?? '') ?: null,
                !empty($data['water_baptism']) ? 1 : 0,
                trim($data['contact_number'] ?? ''),
                ($pt === 'making_disciples' && !empty($data['md_part1']))  ? 1 : 0,
                ($pt === 'making_disciples' && !empty($data['md_part2']))  ? 1 : 0,
            ];
            if ($wbapProvided)  $params[] = $wbapVal;
            if ($batchProvided) $params[] = $batchVal;
            if ($notesProvided) $params[] = $notesVal;
            $params[] = $extraDataVal;
            $params[] = $id;
            $stmt->execute($params);
            return true;
        } catch (PDOException $e) {
            error_log("ProgramAttendance::update error: " . $e->getMessage());
            return false;
        }
    }

    public function updateStatus(int $id, string $status): bool {
        try {
            $this->db->prepare("UPDATE program_attendances SET status=? WHERE id=?")->execute([$status, $id]);
            return true;
        } catch (PDOException $e) {
            error_log("ProgramAttendance::updateStatus error: " . $e->getMessage());
            return false;
        }
    }

    /** Soft delete a single attendance row — sets is_deleted=1. The row is kept in the DB but excluded from list queries. */
    public function hardDelete(int $id): bool {
        try {
            $this->db->prepare("UPDATE program_attendances SET is_deleted=1, status='inactive' WHERE id=?")->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("ProgramAttendance::hardDelete error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Keeps the members.<class> boolean flag in sync with what attendance the member actually has.
     * Sets the flag to 1 when at least one active (not soft-deleted, status='active') record exists
     * for that member+class; 0 otherwise. Safe to call after add / update / deactivate / activate /
     * delete — always recomputes from the current DB state.
     *
     * @param int    $memberId    The members.id; pass 0/null to skip silently.
     * @param string $programType e.g. 'victory_weekend' — maps 1:1 to a members boolean column.
     */
    public function syncMemberFlag(?int $memberId, string $programType): void {
        if (!$memberId) return;
        // Whitelist of program_type → members column. Anything else is a no-op.
        $colMap = [
            'victory_weekend'       => 'victory_weekend',
            'church_community'      => 'church_community',
            'making_disciples'      => 'making_disciples',
            'empowering_leaders'    => 'empowering_leaders',
            'leadership_113'        => 'leadership_113',
            'spiritual_foundations' => 'spiritual_foundations',
        ];
        if (!isset($colMap[$programType])) return;
        $col = $colMap[$programType];
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM program_attendances
                 WHERE member_id = ? AND program_type = ? AND is_deleted = 0 AND status = 'active'
                 LIMIT 1"
            );
            $stmt->execute([$memberId, $programType]);
            $hasActive = $stmt->fetchColumn() !== false ? 1 : 0;
            $this->db->prepare("UPDATE members SET {$col} = ? WHERE id = ?")
                ->execute([$hasActive, $memberId]);

            // Also mirror the change into the member_discipleship junction table so list filters
            // (which join against it) stay accurate.
            $stmt = $this->db->prepare("SELECT id FROM discipleship_steps WHERE column_key = ? LIMIT 1");
            $stmt->execute([$col]);
            $stepId = $stmt->fetchColumn();
            if ($stepId) {
                if ($hasActive) {
                    $this->db->prepare("INSERT IGNORE INTO member_discipleship (member_id, step_id) VALUES (?, ?)")
                        ->execute([$memberId, (int)$stepId]);
                } else {
                    $this->db->prepare("DELETE FROM member_discipleship WHERE member_id = ? AND step_id = ?")
                        ->execute([$memberId, (int)$stepId]);
                }
            }
        } catch (PDOException $e) {
            error_log("ProgramAttendance::syncMemberFlag error: " . $e->getMessage());
        }
    }

    /** Helper for the controller deactivate / activate / delete actions — looks up the member_id + program_type, then syncs. */
    public function syncMemberFlagFromRecord(int $recordId): void {
        try {
            $stmt = $this->db->prepare("SELECT member_id, program_type FROM program_attendances WHERE id = ?");
            $stmt->execute([$recordId]);
            $row = $stmt->fetch();
            if (!$row) return;
            $this->syncMemberFlag((int)($row['member_id'] ?? 0), (string)($row['program_type'] ?? ''));
        } catch (PDOException $e) {
            error_log("ProgramAttendance::syncMemberFlagFromRecord error: " . $e->getMessage());
        }
    }

    /**
     * Distinct session-status values used across a session-based class
     * (e.g. P, A, L, NO CLASS, MUSIC SUMMIT, ...).
     */
    public function getDistinctSessionStatuses(string $programType): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT extra_data FROM program_attendances
                 WHERE program_type = ? AND extra_data IS NOT NULL AND is_deleted = 0"
            );
            $stmt->execute([$programType]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $set = [];
            foreach ($rows as $json) {
                $ed = json_decode($json, true);
                if (!is_array($ed) || empty($ed['sessions']) || !is_array($ed['sessions'])) continue;
                foreach ($ed['sessions'] as $status) {
                    $s = trim((string)$status);
                    if ($s !== '') $set[$s] = true;
                }
            }
            $list = array_keys($set);
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);
            return $list;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Distinct session keys (dates) used across a session-based class, sorted.
     * Drives the "Filter by Topic / Session" dropdowns.
     */
    public function getDistinctSessionKeys(string $programType): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT extra_data FROM program_attendances
                 WHERE program_type = ? AND extra_data IS NOT NULL AND is_deleted = 0"
            );
            $stmt->execute([$programType]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $set = [];
            foreach ($rows as $json) {
                $ed = json_decode($json, true);
                if (!is_array($ed) || empty($ed['sessions']) || !is_array($ed['sessions'])) continue;
                foreach (array_keys($ed['sessions']) as $key) {
                    $k = trim((string)$key);
                    if ($k !== '') $set[$k] = true;
                }
            }
            $list = array_keys($set);
            sort($list, SORT_NATURAL | SORT_FLAG_CASE);
            return $list;
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Distinct batch_label values for one class (column-based since migration). */
    public function getDistinctBatchNamesByType(string $programType): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT batch_label
                 FROM program_attendances
                 WHERE program_type = ?
                   AND batch_label IS NOT NULL
                   AND batch_label != ''
                   AND is_deleted = 0
                 ORDER BY batch_label"
            );
            $stmt->execute([$programType]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /** @deprecated Kept for existing L113 call sites — use getDistinctSessionStatuses(). */
    public function getDistinctL113SessionStatuses(): array {
        return $this->getDistinctSessionStatuses('leadership_113');
    }

    /** @deprecated Kept for existing L113 call sites — use getDistinctBatchNamesByType(). */
    public function getDistinctL113BatchNames(): array {
        return $this->getDistinctBatchNamesByType('leadership_113');
    }

    /** Returns map of program_type => true when that class has any non-empty batch_label. */
    public function getBatchLabelPresence(): array {
        try {
            $rows = $this->db->query(
                "SELECT program_type, COUNT(*) AS c
                 FROM program_attendances
                 WHERE is_deleted = 0
                   AND batch_label IS NOT NULL
                   AND batch_label != ''
                 GROUP BY program_type"
            )->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $out = [];
            foreach ($rows as $pt => $c) $out[$pt] = ((int)$c) > 0;
            return $out;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Average attendance per period, derived from records that carry an event_date.
     * One "period" = a calendar week / month / quarter / year that actually had at
     * least one recorded attendance, so empty stretches don't drag the average down.
     *
     * @param string $programType optional class filter ('' = all classes)
     * @return array<string,array{avg:float,periods:int,total:int,label:string}>
     */
    public function getAttendanceAverages(string $programType = '', int $year = 0): array {
        $buckets = [
            'weekly'    => ['expr' => "DATE_FORMAT(event_date, '%x-W%v')", 'label' => 'Weekly',    'unit' => 'per week'],
            'monthly'   => ['expr' => "DATE_FORMAT(event_date, '%Y-%m')",  'label' => 'Monthly',   'unit' => 'per month'],
            'quarterly' => ['expr' => "CONCAT(YEAR(event_date), '-Q', QUARTER(event_date))", 'label' => 'Quarterly', 'unit' => 'per quarter'],
            'annually'  => ['expr' => "YEAR(event_date)", 'label' => 'Annually', 'unit' => 'per year'],
        ];
        $out = [];
        foreach ($buckets as $key => $b) {
            $out[$key] = ['avg' => 0.0, 'periods' => 0, 'total' => 0, 'label' => $b['label'], 'unit' => $b['unit']];
            try {
                $sql = "SELECT COUNT(DISTINCT {$b['expr']}) AS periods, COUNT(*) AS total
                        FROM program_attendances
                        WHERE is_deleted = 0 AND status = 'active' AND event_date IS NOT NULL";
                $params = [];
                if ($programType !== '') { $sql .= " AND program_type = ?";      $params[] = $programType; }
                if ($year > 0)           { $sql .= " AND program_year = ?";      $params[] = $year; }
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch() ?: [];
                $periods = (int)($row['periods'] ?? 0);
                $total   = (int)($row['total']   ?? 0);
                $out[$key] = [
                    'avg'     => $periods > 0 ? round($total / $periods, 1) : 0.0,
                    'periods' => $periods,
                    'total'   => $total,
                    'label'   => $b['label'],
                    'unit'    => $b['unit'],
                ];
            } catch (PDOException $e) {
                error_log("getAttendanceAverages error: " . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * Per-period attendance series, for the dashboard trend chart.
     * Returns the most recent $limit periods in chronological order.
     *
     * @param string $bucket weekly | monthly | quarterly | annually
     * @return array{labels:array,values:array}
     */
    public function getAttendanceSeries(string $bucket = 'monthly', string $programType = '', int $year = 0, int $limit = 12): array {
        $exprs = [
            'weekly'    => "DATE_FORMAT(event_date, '%x-W%v')",
            'monthly'   => "DATE_FORMAT(event_date, '%Y-%m')",
            'quarterly' => "CONCAT(YEAR(event_date), '-Q', QUARTER(event_date))",
            'annually'  => "CAST(YEAR(event_date) AS CHAR)",
        ];
        $expr = $exprs[$bucket] ?? $exprs['monthly'];
        try {
            $sql = "SELECT {$expr} AS period, COUNT(*) AS total
                    FROM program_attendances
                    WHERE is_deleted = 0 AND status = 'active' AND event_date IS NOT NULL";
            $params = [];
            if ($programType !== '') { $sql .= " AND program_type = ?"; $params[] = $programType; }
            if ($year > 0)           { $sql .= " AND program_year = ?"; $params[] = $year; }
            $sql .= " GROUP BY period ORDER BY period DESC LIMIT " . max(1, min(60, $limit));
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = array_reverse($stmt->fetchAll() ?: []);
            return [
                'labels' => array_column($rows, 'period'),
                'values' => array_map('intval', array_column($rows, 'total')),
            ];
        } catch (PDOException $e) {
            error_log("getAttendanceSeries error: " . $e->getMessage());
            return ['labels' => [], 'values' => []];
        }
    }

    /**
     * Church health — attendance measured against the size of the leadership base.
     * "Leaders" reuses the existing Victory Group leader relationship
     * (vg_members.role = 'leader' on a non-deleted, active group).
     *
     * @return array{leaders:int,interns:int,attendees:int,attendance:int,active_members:int,
     *               attendance_per_leader:float,attendees_per_leader:float,
     *               members_per_leader:float,groups_per_leader:float,groups:int}
     */
    public function getChurchHealthStats(string $programType = '', int $year = 0): array {
        $base = [
            'leaders' => 0, 'interns' => 0, 'attendees' => 0, 'attendance' => 0,
            'active_members' => 0, 'groups' => 0,
            'attendance_per_leader' => 0.0, 'attendees_per_leader' => 0.0,
            'members_per_leader' => 0.0, 'groups_per_leader' => 0.0,
        ];
        try {
            $roleCounts = $this->db->query(
                "SELECT vm.role, COUNT(*) AS c
                   FROM vg_members vm
                   JOIN victory_groups vg ON vg.id = vm.group_id
                  WHERE vg.is_deleted = 0 AND vg.group_status = 'active'
                  GROUP BY vm.role"
            )->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            $base['leaders']   = (int)($roleCounts['leader']   ?? 0);
            $base['interns']   = (int)($roleCounts['intern']   ?? 0);
            $base['attendees'] = (int)($roleCounts['attendee'] ?? 0);

            $base['groups'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0 AND group_status = 'active'"
            )->fetchColumn();

            // Attendance respects the dashboard filter; the leadership base is
            // always "leaders we have today", which is the point of the ratio.
            $sql = "SELECT COUNT(*) FROM program_attendances WHERE is_deleted = 0 AND status = 'active'";
            $params = [];
            if ($programType !== '') { $sql .= " AND program_type = ?"; $params[] = $programType; }
            if ($year > 0)           { $sql .= " AND program_year = ?"; $params[] = $year; }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $base['attendance'] = (int)$stmt->fetchColumn();

            $base['active_members'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND member_status = 'active'"
            )->fetchColumn();

            $l = $base['leaders'];
            if ($l > 0) {
                $base['attendance_per_leader'] = round($base['attendance']     / $l, 1);
                $base['attendees_per_leader']  = round($base['attendees']      / $l, 1);
                $base['members_per_leader']    = round($base['active_members'] / $l, 1);
                $base['groups_per_leader']     = round($base['groups']         / $l, 2);
            }
        } catch (PDOException $e) {
            error_log("getChurchHealthStats error: " . $e->getMessage());
        }
        return $base;
    }

    public function getMatchStats(): array {
        try {
            $rows = $this->db->query("
                SELECT program_type,
                       COUNT(*) as total,
                       SUM(member_id IS NOT NULL) as matched,
                       SUM(member_id IS NULL) as unmatched
                FROM program_attendances
                WHERE is_deleted = 0
                GROUP BY program_type
            ")->fetchAll();
            $stats = [];
            foreach ($rows as $r) $stats[$r['program_type']] = $r;
            return $stats;
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>
