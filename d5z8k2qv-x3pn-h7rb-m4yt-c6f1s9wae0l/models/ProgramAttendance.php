<?php
class ProgramAttendance {
    private $db;

    const PROGRAM_LABELS = [
        'victory_weekend'    => 'Victory Weekend',
        'church_community'   => 'Church Community',
        'making_disciples'   => 'Making Disciples',
        'empowering_leaders' => 'Empowering Leaders',
        'leadership_113'     => 'Leadership 113',
    ];

    const PROGRAM_COLORS = [
        'victory_weekend'    => 'primary',
        'church_community'   => 'secondary',
        'making_disciples'   => 'success',
        'empowering_leaders' => 'warning',
        'leadership_113'     => 'danger',
    ];

    const PROGRAM_ICONS = [
        'victory_weekend'    => 'bi-sun',
        'church_community'   => 'bi-building',
        'making_disciples'   => 'bi-person-plus',
        'empowering_leaders' => 'bi-star',
        'leadership_113'     => 'bi-trophy',
    ];

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
        'victory_weekend'    => 'VICTORY WEEKEND',
        'church_community'   => 'CHURCH COMMUNITY',
        'making_disciples'   => 'MAKING DISCIPLES',
        'empowering_leaders' => 'EMPOWERING LEADERS',
        'leadership_113'     => 'LEADERSHIP 113',
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
            $stmt = $this->db->prepare("
                INSERT INTO program_attendances
                    (member_id, raw_last_name, raw_first_name, full_name_display,
                     program_type, program_year, program_label, event_date,
                     counselor_name, counselor_contact,
                     water_baptism, contact_number,
                     md_part1, md_part2,
                     batch_label, notes, extra_data)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
            } elseif ($pt === 'leadership_113' || $pt === 'making_disciples') {
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
            $stmt = $this->db->prepare("
                UPDATE program_attendances
                SET member_id=?, raw_last_name=?, raw_first_name=?, full_name_display=?,
                    program_type=?, program_year=?, program_label=?, event_date=?,
                    counselor_name=?, counselor_contact=?,
                    water_baptism=?, contact_number=?,
                    md_part1=?, md_part2=?,
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
            'victory_weekend'    => 'victory_weekend',
            'church_community'   => 'church_community',
            'making_disciples'   => 'making_disciples',
            'empowering_leaders' => 'empowering_leaders',
            'leadership_113'     => 'leadership_113',
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

    /** Returns distinct session-status values used across all L113 records (e.g. P, A, L, NO CLASS, MUSIC SUMMIT, ...) */
    public function getDistinctL113SessionStatuses(): array {
        try {
            $rows = $this->db->query(
                "SELECT extra_data FROM program_attendances
                 WHERE program_type='leadership_113' AND extra_data IS NOT NULL AND is_deleted = 0"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
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

    /** Returns distinct batch_label values for L113 records (column-based since migration). */
    public function getDistinctL113BatchNames(): array {
        try {
            $rows = $this->db->query(
                "SELECT DISTINCT batch_label
                 FROM program_attendances
                 WHERE program_type = 'leadership_113'
                   AND batch_label IS NOT NULL
                   AND batch_label != ''
                   AND is_deleted = 0
                 ORDER BY batch_label"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return $rows;
        } catch (PDOException $e) {
            return [];
        }
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
