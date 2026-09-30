<?php
/**
 * Managed dropdown values for Serve Teams: the Service/activity list and the
 * Place list.
 *
 * Same shape as vg_options (one table, an option_type discriminator) so the
 * CRUD, ordering and activate/deactivate behaviour match the rest of the
 * portal's lookup lists.
 *
 * A service carries its regular day and time, which the team form uses to
 * auto-fill Day / Time when one is picked. Sunday Worship also carries its
 * extra slots in `time_options`. Those are DEFAULTS only — the user can always
 * override them on the team.
 */
class ServeOption {
    const TYPES = ['service' => 'Service / Activity', 'place' => 'Place'];

    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /** @return array rows of one type, ordered for display. */
    public function getByType(string $type, bool $activeOnly = true): array {
        try {
            $sql = "SELECT * FROM serve_options WHERE option_type = ? AND is_deleted = 0";
            if ($activeOnly) $sql .= " AND is_active = 1";
            $sql .= " ORDER BY sort_order ASC, name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$type]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("ServeOption::getByType error: " . $e->getMessage());
            return [];
        }
    }

    /** ['service' => [...], 'place' => [...]] */
    public function getAllGrouped(bool $activeOnly = true): array {
        $out = [];
        foreach (array_keys(self::TYPES) as $t) $out[$t] = $this->getByType($t, $activeOnly);
        return $out;
    }

    public function getById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM serve_options WHERE id = ?");
            $stmt->execute([(int)$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Service defaults keyed by name, for the team form's auto-fill.
     *
     * `times` is the Service Time Slot list. Multi-session services (Sunday
     * Worship) declare their slots explicitly in time_options; every other
     * service falls back to its single usual time, so the slot dropdown is
     * useful for all of them rather than only the one with extra slots.
     *
     * @return array<string,array{day:string,time:string,times:array,notes:string}>
     */
    public function getServiceDefaults(): array {
        $out = [];
        foreach ($this->getByType('service') as $row) {
            $times = array_values(array_filter(array_map('trim', explode(',', (string)$row['time_options'])), 'strlen'));
            // HTML <input type="time"> wants HH:MM.
            $time  = $row['default_time'] ? substr((string)$row['default_time'], 0, 5) : '';
            if (!$times && $time !== '') $times = [$time];
            $out[$row['name']] = [
                'day'   => (string)($row['default_day'] ?? ''),
                'time'  => $time,
                'times' => $times,
                'notes' => (string)($row['notes'] ?? ''),
            ];
        }
        return $out;
    }

    // ── CRUD ────────────────────────────────────────────────────────────────

    public function add(array $data): array {
        $type = $data['option_type'] ?? '';
        $name = trim($data['name'] ?? '');
        if (!isset(self::TYPES[$type])) return ['ok' => false, 'error' => 'Unknown option type.'];
        if ($name === '')                return ['ok' => false, 'error' => 'Name is required.'];
        if ($this->nameTaken($type, $name)) {
            return ['ok' => false, 'error' => '"' . $name . '" already exists in this list.'];
        }
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO serve_options
                    (option_type, name, default_day, default_time, time_options, notes, sort_order, is_active)
                 VALUES (?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $type, $name,
                $this->nz($data['default_day'] ?? ''),
                $this->nz($data['default_time'] ?? ''),
                $this->normalizeTimes($data['time_options'] ?? ''),
                $this->nz($data['notes'] ?? ''),
                (int)($data['sort_order'] ?? $this->nextSortOrder($type)),
                empty($data['is_active']) ? 0 : 1,
            ]);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("ServeOption::add error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the value.'];
        }
    }

    public function update($id, array $data): array {
        $row = $this->getById($id);
        if (!$row) return ['ok' => false, 'error' => 'Value not found.'];
        $name = trim($data['name'] ?? '');
        if ($name === '') return ['ok' => false, 'error' => 'Name is required.'];
        if ($this->nameTaken($row['option_type'], $name, (int)$id)) {
            return ['ok' => false, 'error' => '"' . $name . '" already exists in this list.'];
        }
        try {
            $stmt = $this->db->prepare(
                "UPDATE serve_options
                    SET name = ?, default_day = ?, default_time = ?, time_options = ?, notes = ?, is_active = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $name,
                $this->nz($data['default_day'] ?? ''),
                $this->nz($data['default_time'] ?? ''),
                $this->normalizeTimes($data['time_options'] ?? ''),
                $this->nz($data['notes'] ?? ''),
                empty($data['is_active']) ? 0 : 1,
                (int)$id,
            ]);
            // Keep teams pointing at this value in step with a rename.
            if ($name !== $row['name']) $this->propagateRename($row['option_type'], $row['name'], $name);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("ServeOption::update error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the value.'];
        }
    }

    public function setActive($id, bool $active): array {
        try {
            $this->db->prepare("UPDATE serve_options SET is_active = ? WHERE id = ?")
                ->execute([$active ? 1 : 0, (int)$id]);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'Could not change the status.'];
        }
    }

    /**
     * A value still used by a team is archived rather than removed, so those
     * teams keep a meaningful Service/Place. Unused values delete outright.
     *
     * @return array{ok:bool,error:string,mode:string,usage:int}
     */
    public function delete($id): array {
        $row = $this->getById($id);
        if (!$row) return ['ok' => false, 'error' => 'Value not found.', 'mode' => '', 'usage' => 0];
        $usage = $this->getUsageCount($row);
        try {
            if ($usage > 0) {
                $this->db->prepare("UPDATE serve_options SET is_active = 0, is_deleted = 1 WHERE id = ?")
                    ->execute([(int)$id]);
                return ['ok' => true, 'error' => '', 'mode' => 'archived', 'usage' => $usage];
            }
            $this->db->prepare("DELETE FROM serve_options WHERE id = ?")->execute([(int)$id]);
            return ['ok' => true, 'error' => '', 'mode' => 'deleted', 'usage' => 0];
        } catch (PDOException $e) {
            error_log("ServeOption::delete error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not remove the value.', 'mode' => '', 'usage' => $usage];
        }
    }

    public function reorder(string $type, array $ids): bool {
        try {
            $stmt = $this->db->prepare("UPDATE serve_options SET sort_order = ? WHERE id = ? AND option_type = ?");
            foreach (array_values($ids) as $i => $id) $stmt->execute([$i + 1, (int)$id, $type]);
            return true;
        } catch (PDOException $e) {
            error_log("ServeOption::reorder error: " . $e->getMessage());
            return false;
        }
    }

    /** How many serve teams reference this value. */
    public function getUsageCount($row): int {
        $col = $row['option_type'] === 'service' ? 'service_name' : 'meeting_place';
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM serve_teams WHERE `{$col}` = ? AND is_deleted = 0"
            );
            $stmt->execute([$row['name']]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 1;   // assume used — safest, keeps the row
        }
    }

    /** id => usage count, for the management table. */
    public function getUsageMap(): array {
        $out = [];
        foreach ($this->getAllGrouped(false) as $rows) {
            foreach ($rows as $row) $out[(int)$row['id']] = $this->getUsageCount($row);
        }
        return $out;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function propagateRename(string $type, string $old, string $new): void {
        $col = $type === 'service' ? 'service_name' : 'meeting_place';
        try {
            $this->db->prepare("UPDATE serve_teams SET `{$col}` = ? WHERE `{$col}` = ?")->execute([$new, $old]);
        } catch (PDOException $e) {
            error_log("ServeOption::propagateRename error: " . $e->getMessage());
        }
    }

    private function nameTaken(string $type, string $name, int $exceptId = 0): bool {
        try {
            $sql  = "SELECT 1 FROM serve_options WHERE option_type = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) AND is_deleted = 0";
            $args = [$type, $name];
            if ($exceptId) { $sql .= " AND id <> ?"; $args[] = $exceptId; }
            $stmt = $this->db->prepare($sql . " LIMIT 1");
            $stmt->execute($args);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function nextSortOrder(string $type): int {
        try {
            $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM serve_options WHERE option_type = ?");
            $stmt->execute([$type]);
            return ((int)$stmt->fetchColumn()) + 1;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /** "08:30, 11:00" → "08:30,11:00"; drops anything that isn't HH:MM. */
    private function normalizeTimes($raw): ?string {
        if (is_array($raw)) $raw = implode(',', $raw);
        $out = [];
        foreach (explode(',', (string)$raw) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) {
                $out[] = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            }
        }
        return $out ? implode(',', $out) : null;
    }

    private function nz($v): ?string {
        $v = trim((string)$v);
        return $v !== '' ? $v : null;
    }
}
?>
