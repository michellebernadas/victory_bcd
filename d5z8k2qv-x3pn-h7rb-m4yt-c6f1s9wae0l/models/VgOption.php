<?php
class VgOption {
    private $db;

    const TYPES = [
        'group_type'        => 'Group Type',
        'group_category'    => 'Group Category',
        'day_of_week'       => 'Day of Week',
        'meeting_frequency' => 'Meeting Frequency',
    ];

    public function __construct($db) { $this->db = $db; }

    public function getAll() {
        try {
            return $this->db->query(
                "SELECT * FROM vg_options WHERE is_deleted = 0 ORDER BY option_type, sort_order, value"
            )->fetchAll();
        } catch (PDOException $e) {
            error_log("VgOption::getAll: " . $e->getMessage());
            return [];
        }
    }

    public function getByType($type, $activeOnly = true) {
        try {
            $sql = "SELECT * FROM vg_options WHERE option_type = ? AND is_deleted = 0";
            if ($activeOnly) $sql .= " AND is_active = 1";
            $sql .= " ORDER BY sort_order, value";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$type]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("VgOption::getByType: " . $e->getMessage());
            return [];
        }
    }

    public function getAllGrouped($activeOnly = false) {
        $all = $this->getAll();
        $grouped = [];
        foreach ($all as $row) {
            if ($activeOnly && !$row['is_active']) continue;
            $grouped[$row['option_type']][] = $row;
        }
        return $grouped;
    }

    public function add($type, $value, $label, $sortOrder = 0) {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO vg_options (option_type, value, label, sort_order) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$type, trim($value), trim($label), (int)$sortOrder]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("VgOption::add: " . $e->getMessage());
            return false;
        }
    }

    public function update($id, $value, $label, $sortOrder, $isActive) {
        $newValue = trim($value);
        try {
            $this->db->beginTransaction();

            // Look up the old value + option_type so we can propagate value renames to victory_groups
            $stmt = $this->db->prepare("SELECT option_type, value FROM vg_options WHERE id=?");
            $stmt->execute([(int)$id]);
            $existing = $stmt->fetch();

            $stmt = $this->db->prepare(
                "UPDATE vg_options SET value=?, label=?, sort_order=?, is_active=? WHERE id=?"
            );
            $stmt->execute([$newValue, trim($label), (int)$sortOrder, $isActive ? 1 : 0, (int)$id]);

            if ($existing && $existing['value'] !== '' && $existing['value'] !== $newValue) {
                $col = self::columnForOptionType($existing['option_type']);
                if ($col === 'day_of_week') {
                    // day_of_week may store a CSV (e.g. "Tue, Thu"). Patch any row whose CSV contains the old token.
                    $like = '%' . $existing['value'] . '%';
                    $rows = $this->db->prepare("SELECT id, day_of_week FROM victory_groups WHERE day_of_week LIKE ?");
                    $rows->execute([$like]);
                    $patch = $this->db->prepare("UPDATE victory_groups SET day_of_week = ? WHERE id = ?");
                    foreach ($rows->fetchAll() as $r) {
                        $tokens = array_map('trim', explode(',', $r['day_of_week']));
                        $changed = false;
                        foreach ($tokens as &$t) {
                            if ($t === $existing['value']) { $t = $newValue; $changed = true; }
                        }
                        if ($changed) $patch->execute([implode(', ', $tokens), $r['id']]);
                    }
                } elseif ($col !== null) {
                    $stmt = $this->db->prepare("UPDATE victory_groups SET `$col` = ? WHERE `$col` = ?");
                    $stmt->execute([$newValue, $existing['value']]);
                }
            }

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("VgOption::update: " . $e->getMessage());
            return false;
        }
    }

    public function reorder(array $ids): bool {
        try {
            $stmt = $this->db->prepare("UPDATE vg_options SET sort_order = ? WHERE id = ?");
            foreach ($ids as $order => $id) $stmt->execute([(int)$order, (int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("VgOption::reorder: " . $e->getMessage());
            return false;
        }
    }

    public function deactivate($id) {
        try {
            $this->db->prepare("UPDATE vg_options SET is_active = 0 WHERE id = ?")->execute([(int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("VgOption::deactivate: " . $e->getMessage());
            return false;
        }
    }

    public function activate($id) {
        try {
            $this->db->prepare("UPDATE vg_options SET is_active = 1 WHERE id = ?")->execute([(int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("VgOption::activate: " . $e->getMessage());
            return false;
        }
    }

    public function delete($id) {
        // Soft delete — preserves the row for audit. Filtered out of list/select queries.
        try {
            $this->db->prepare("UPDATE vg_options SET is_deleted = 1, is_active = 0 WHERE id=?")->execute([(int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("VgOption::delete: " . $e->getMessage());
            return false;
        }
    }

    /** Returns [option_type, value, label, count] — count is how many victory_groups still reference this option. */
    public function getUsageInfo($id): array {
        try {
            $stmt = $this->db->prepare("SELECT option_type, value, label FROM vg_options WHERE id = ?");
            $stmt->execute([(int)$id]);
            $row = $stmt->fetch();
            if (!$row) return ['option_type' => null, 'value' => null, 'label' => null, 'count' => 0];
            $col = self::columnForOptionType($row['option_type']);
            $count = 0;
            if ($col !== null) {
                if ($col === 'day_of_week') {
                    // day_of_week can be CSV — match whole-token using LIKE on padded value
                    $stmt = $this->db->prepare(
                        "SELECT COUNT(*) FROM victory_groups
                         WHERE CONCAT(', ', day_of_week, ', ') LIKE CONCAT('%, ', ?, ', %') AND is_deleted = 0"
                    );
                    $stmt->execute([$row['value']]);
                } else {
                    $stmt = $this->db->prepare("SELECT COUNT(*) FROM victory_groups WHERE `$col` = ? AND is_deleted = 0");
                    $stmt->execute([$row['value']]);
                }
                $count = (int)$stmt->fetchColumn();
            }
            return [
                'option_type' => $row['option_type'],
                'value'       => $row['value'],
                'label'       => $row['label'],
                'count'       => $count,
            ];
        } catch (PDOException $e) {
            error_log("VgOption::getUsageInfo: " . $e->getMessage());
            return ['option_type' => null, 'value' => null, 'label' => null, 'count' => 0];
        }
    }

    /** Map an option_type to the victory_groups column it drives. Returns null if unknown. */
    private static function columnForOptionType(?string $type): ?string {
        return [
            'group_type'        => 'group_type',
            'group_category'    => 'group_category',
            'day_of_week'       => 'day_of_week',
            'meeting_frequency' => 'meeting_frequency',
        ][$type] ?? null;
    }
}
?>
