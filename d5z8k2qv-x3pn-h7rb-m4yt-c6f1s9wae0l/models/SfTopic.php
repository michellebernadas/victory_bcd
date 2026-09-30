<?php
/**
 * Spiritual Foundations curriculum.
 *
 * Deliberately separate from participant attendance: topics live in sf_topics
 * while attendance stays in program_attendances.extra_data. Renaming or
 * re-ordering a topic therefore never rewrites a historical attendance row —
 * a stored session grid is a list of dates + statuses, and the week position
 * is what maps it back to the curriculum.
 */
class SfTopic {
    /** program_type key used on program_attendances for this class. */
    const PROGRAM_TYPE = 'spiritual_foundations';

    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /** Every curriculum row in printed order, including the NO CLASS break. */
    public function getAll(bool $activeOnly = true): array {
        try {
            $sql = "SELECT * FROM sf_topics WHERE is_deleted = 0";
            if ($activeOnly) $sql .= " AND is_active = 1";
            $sql .= " ORDER BY sort_order ASC, id ASC";
            return $this->db->query($sql)->fetchAll();
        } catch (PDOException $e) {
            error_log("SfTopic::getAll error: " . $e->getMessage());
            return [];
        }
    }

    /** Only the real class weeks (No Class breaks excluded), keyed by week_no. */
    public function getClassWeeks(bool $activeOnly = true): array {
        $out = [];
        foreach ($this->getAll($activeOnly) as $row) {
            if (!(int)$row['is_class'] || $row['week_no'] === null) continue;
            $out[(int)$row['week_no']] = $row;
        }
        ksort($out);
        return $out;
    }

    /** Class weeks that must be completed for a certificate, keyed by week_no. */
    public function getRequiredWeeks(bool $activeOnly = true): array {
        return array_filter($this->getClassWeeks($activeOnly), fn($r) => (int)$r['is_required'] === 1);
    }

    public function getById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM sf_topics WHERE id = ?");
            $stmt->execute([(int)$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /** The No Class break row (class/session-level, never a numbered topic). */
    public function getBreakRow() {
        try {
            return $this->db->query("SELECT * FROM sf_topics WHERE is_class = 0 AND is_deleted = 0 LIMIT 1")->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    // ── CRUD ────────────────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,error:string,id:int}
     */
    public function add(array $data): array {
        $topic = trim($data['topic'] ?? '');
        if ($topic === '') return ['ok' => false, 'error' => 'Topic name is required.', 'id' => 0];

        $isClass = !isset($data['is_class']) || (int)$data['is_class'] === 1;
        $weekNo  = $isClass ? (int)($data['week_no'] ?? 0) : null;

        if ($isClass) {
            if ($weekNo < 1) return ['ok' => false, 'error' => 'Week number must be 1 or higher.', 'id' => 0];
            if ($this->weekTaken($weekNo)) {
                return ['ok' => false, 'error' => 'Week ' . $weekNo . ' is already used by another topic.', 'id' => 0];
            }
        }
        if ($this->topicNameTaken($topic)) {
            return ['ok' => false, 'error' => 'A topic named "' . $topic . '" already exists.', 'id' => 0];
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO sf_topics (week_no, sort_order, topic, subtopics, is_class, is_required, is_active)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $weekNo,
                (int)($data['sort_order'] ?? ($weekNo ?: $this->nextSortOrder())),
                $topic,
                $this->normalizeSubtopics($data['subtopics'] ?? ''),
                $isClass ? 1 : 0,
                ($isClass && !empty($data['is_required'])) ? 1 : 0,
                empty($data['is_active']) ? 0 : 1,
            ]);
            return ['ok' => true, 'error' => '', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            error_log("SfTopic::add error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the topic.', 'id' => 0];
        }
    }

    public function update($id, array $data): array {
        $row = $this->getById($id);
        if (!$row) return ['ok' => false, 'error' => 'Topic not found.'];

        $topic = trim($data['topic'] ?? '');
        if ($topic === '') return ['ok' => false, 'error' => 'Topic name is required.'];

        $isClass = (int)$row['is_class'] === 1;   // class vs break never changes after creation
        $weekNo  = $isClass ? (int)($data['week_no'] ?? 0) : null;

        if ($isClass) {
            if ($weekNo < 1) return ['ok' => false, 'error' => 'Week number must be 1 or higher.'];
            if ($this->weekTaken($weekNo, (int)$id)) {
                return ['ok' => false, 'error' => 'Week ' . $weekNo . ' is already used by another topic.'];
            }
            // Renumbering re-points existing attendance at a different topic.
            if ($weekNo !== (int)$row['week_no'] && $this->hasAnyAttendance()) {
                return ['ok' => false, 'error' =>
                    'Week numbers cannot be changed while Spiritual Foundations attendance exists — '
                    . 'historical records are stored against week numbers and would be re-pointed at a '
                    . 'different topic. Rename the topic instead, or deactivate it and add a new one.'];
            }
        }
        if ($this->topicNameTaken($topic, (int)$id)) {
            return ['ok' => false, 'error' => 'Another topic is already named "' . $topic . '".'];
        }

        try {
            $stmt = $this->db->prepare(
                "UPDATE sf_topics SET week_no = ?, topic = ?, subtopics = ?, is_required = ?, is_active = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $weekNo,
                $topic,
                $this->normalizeSubtopics($data['subtopics'] ?? ''),
                ($isClass && !empty($data['is_required'])) ? 1 : 0,
                empty($data['is_active']) ? 0 : 1,
                (int)$id,
            ]);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("SfTopic::update error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the topic.'];
        }
    }

    public function setActive($id, bool $active): array {
        try {
            $this->db->prepare("UPDATE sf_topics SET is_active = ? WHERE id = ?")
                ->execute([$active ? 1 : 0, (int)$id]);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Could not change the topic status.'];
        }
    }

    /**
     * Deletes a topic — but only a genuinely unused one.
     *
     * A topic that any participant has attendance against is archived
     * (is_deleted = 1 keeps the row, and therefore the week number, intact) so
     * historical grids still resolve to the right topic. A never-used topic is
     * removed outright.
     *
     * @return array{ok:bool,error:string,mode:string,usage:int}
     */
    public function delete($id): array {
        $row = $this->getById($id);
        if (!$row) return ['ok' => false, 'error' => 'Topic not found.', 'mode' => '', 'usage' => 0];

        $usage = $this->getUsageCount($row);
        try {
            if ($usage > 0) {
                // Referenced by attendance → archive, never destroy.
                $this->db->prepare("UPDATE sf_topics SET is_active = 0, is_deleted = 1 WHERE id = ?")
                    ->execute([(int)$id]);
                return ['ok' => true, 'error' => '', 'mode' => 'archived', 'usage' => $usage];
            }
            $this->db->prepare("DELETE FROM sf_topics WHERE id = ?")->execute([(int)$id]);
            return ['ok' => true, 'error' => '', 'mode' => 'deleted', 'usage' => 0];
        } catch (PDOException $e) {
            error_log("SfTopic::delete error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not remove the topic.', 'mode' => '', 'usage' => $usage];
        }
    }

    /**
     * Renumbers class weeks from an ordered list of topic ids. Only allowed
     * while no SF attendance exists, because week numbers are the key history
     * is stored against.
     */
    public function reorder(array $ids): array {
        if ($this->hasAnyAttendance()) {
            return ['ok' => false, 'error' =>
                'Reordering is locked because Spiritual Foundations attendance already exists — '
                . 'renumbering weeks would re-point historical records at different topics.'];
        }
        try {
            $this->db->beginTransaction();
            // Blank first: week_no carries a UNIQUE index, so a straight
            // reassignment could collide mid-loop.
            $this->db->exec("UPDATE sf_topics SET week_no = NULL WHERE is_class = 1");
            $stmt = $this->db->prepare("UPDATE sf_topics SET week_no = ?, sort_order = ? WHERE id = ? AND is_class = 1");
            $wk = 0;
            foreach ($ids as $id) {
                $wk++;
                $stmt->execute([$wk, $wk, (int)$id]);
            }
            $this->db->commit();
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("SfTopic::reorder error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not reorder the curriculum.'];
        }
    }

    // ── Usage / safety helpers ──────────────────────────────────────────────

    /** How many active SF attendance records carry an entry for this topic's week. */
    public function getUsageCount($row): int {
        if (!(int)($row['is_class'] ?? 0) || ($row['week_no'] ?? null) === null) return 0;
        $wk = (int)$row['week_no'];
        try {
            $stmt = $this->db->prepare(
                "SELECT extra_data FROM program_attendances
                  WHERE program_type = ? AND extra_data IS NOT NULL AND is_deleted = 0"
            );
            $stmt->execute([self::PROGRAM_TYPE]);
            $n = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $ed = json_decode($json, true);
                if (!is_array($ed)) continue;
                if (!empty($ed['weeks']) && is_array($ed['weeks'])) {
                    if (array_key_exists((string)$wk, $ed['weeks']) || array_key_exists($wk, $ed['weeks'])) $n++;
                    continue;
                }
                // Legacy positional row: week N is referenced when the grid is at least N long.
                if (!empty($ed['sessions']) && is_array($ed['sessions']) && count($ed['sessions']) >= $wk) $n++;
            }
            return $n;
        } catch (PDOException $e) {
            // On error assume it IS used — safest default, keeps the row.
            return 1;
        }
    }

    /** Usage counts keyed by sf_topics.id, for rendering the curriculum table. */
    public function getUsageMap(): array {
        $out = [];
        foreach ($this->getAll(false) as $row) {
            $out[(int)$row['id']] = $this->getUsageCount($row);
        }
        return $out;
    }

    public function hasAnyAttendance(): bool {
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM program_attendances WHERE program_type = ? AND is_deleted = 0 LIMIT 1"
            );
            $stmt->execute([self::PROGRAM_TYPE]);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return true;
        }
    }

    private function weekTaken(int $weekNo, int $exceptId = 0): bool {
        try {
            $sql  = "SELECT 1 FROM sf_topics WHERE week_no = ?";
            $args = [$weekNo];
            if ($exceptId) { $sql .= " AND id <> ?"; $args[] = $exceptId; }
            $stmt = $this->db->prepare($sql . " LIMIT 1");
            $stmt->execute($args);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return true;
        }
    }

    private function topicNameTaken(string $topic, int $exceptId = 0): bool {
        try {
            $sql  = "SELECT 1 FROM sf_topics WHERE LOWER(TRIM(topic)) = LOWER(TRIM(?)) AND is_deleted = 0";
            $args = [$topic];
            if ($exceptId) { $sql .= " AND id <> ?"; $args[] = $exceptId; }
            $stmt = $this->db->prepare($sql . " LIMIT 1");
            $stmt->execute($args);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function nextSortOrder(): int {
        try {
            return ((int)$this->db->query("SELECT MAX(sort_order) FROM sf_topics")->fetchColumn()) + 1;
        } catch (PDOException $e) {
            return 0;
        }
    }

    private function normalizeSubtopics($raw): ?string {
        if (is_array($raw)) $raw = implode("\n", $raw);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$raw)), 'strlen'));
        return $lines ? implode("\n", $lines) : null;
    }

    /** How many class weeks the program has — the denominator for "9/12". */
    public function getWeekCount(bool $activeOnly = true): int {
        return count($this->getClassWeeks($activeOnly));
    }

    /** How many class weeks are required for certificate eligibility. */
    public function getRequiredWeekCount(bool $activeOnly = true): int {
        $n = 0;
        foreach ($this->getClassWeeks($activeOnly) as $row) {
            if ((int)$row['is_required']) $n++;
        }
        return $n;
    }

    /** Subtopics as an array (stored newline-separated). */
    public static function subtopicList($row): array {
        $raw = trim((string)($row['subtopics'] ?? ''));
        if ($raw === '') return [];
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), 'strlen'));
    }

    /** "Week 3 — Creation, The Fall and Sin" */
    public static function weekLabel($row): string {
        $wk = $row['week_no'] ?? null;
        return ($wk !== null ? 'Week ' . (int)$wk . ' — ' : '') . ($row['topic'] ?? '');
    }
}
?>
