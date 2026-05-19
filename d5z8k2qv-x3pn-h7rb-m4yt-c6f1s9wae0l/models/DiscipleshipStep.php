<?php
class DiscipleshipStep {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAllSteps() {
        try {
            $stmt = $this->db->query("SELECT * FROM discipleship_steps WHERE is_deleted = 0 ORDER BY sort_order ASC, id ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get all discipleship steps error: " . $e->getMessage());
            return [];
        }
    }

    public function getActiveSteps() {
        try {
            $stmt = $this->db->query("SELECT * FROM discipleship_steps WHERE is_active = 1 AND is_deleted = 0 ORDER BY sort_order ASC, id ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get active discipleship steps error: " . $e->getMessage());
            return [];
        }
    }

    public function addStep($name, $abbreviation, $icon, $color) {
        try {
            $stmt = $this->db->prepare("INSERT INTO discipleship_steps (name, abbreviation, icon, color) VALUES (?, ?, ?, ?)");
            $stmt->execute([trim($name), trim($abbreviation), trim($icon), trim($color)]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Add discipleship step error: " . $e->getMessage());
            return false;
        }
    }

    public function updateStep($id, $name, $abbreviation, $icon, $color) {
        try {
            $stmt = $this->db->prepare("UPDATE discipleship_steps SET name = ?, abbreviation = ?, icon = ?, color = ? WHERE id = ?");
            $stmt->execute([trim($name), trim($abbreviation), trim($icon), trim($color), $id]);
            return true;
        } catch (PDOException $e) {
            error_log("Update discipleship step error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteStep($id) {
        try {
            $stmt = $this->db->prepare("UPDATE discipleship_steps SET is_deleted = 1, is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Delete discipleship step error: " . $e->getMessage());
            return false;
        }
    }

    /** Returns [name, column_key, count] — count is how many members have completed this step. */
    public function getUsageInfo($id): array {
        try {
            $stmt = $this->db->prepare("SELECT name, column_key FROM discipleship_steps WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) return ['name' => null, 'column_key' => null, 'count' => 0];

            $count = 0;
            $colKey = $row['column_key'] ?? '';
            // Only check the count if this step is linked to a legacy column.
            // Validate against information_schema to prevent SQL injection via column_key.
            if ($colKey !== '') {
                $check = $this->db->prepare(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'members' AND column_name = ?"
                );
                $check->execute([$colKey]);
                if ((int)$check->fetchColumn() === 1) {
                    // Safe: column existence verified. Identifier wrapped in backticks.
                    $sql = "SELECT COUNT(*) FROM members WHERE `" . $colKey . "` = 1 AND is_deleted = 0";
                    $count = (int)$this->db->query($sql)->fetchColumn();
                }
            }
            // Also count completions stored in the member_discipleship pivot table (only for non-deleted members)
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM member_discipleship md
                 JOIN members m ON m.id = md.member_id
                 WHERE md.step_id = ? AND m.is_deleted = 0"
            );
            $stmt->execute([$id]);
            $count += (int)$stmt->fetchColumn();

            return ['name' => $row['name'], 'column_key' => $colKey, 'count' => $count];
        } catch (PDOException $e) {
            error_log("Discipleship step usage check error: " . $e->getMessage());
            return ['name' => null, 'column_key' => null, 'count' => 0];
        }
    }

    public function deactivateStep($id) {
        try {
            $stmt = $this->db->prepare("UPDATE discipleship_steps SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Deactivate discipleship step error: " . $e->getMessage());
            return false;
        }
    }

    public function activateStep($id) {
        try {
            $stmt = $this->db->prepare("UPDATE discipleship_steps SET is_active = 1 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Activate discipleship step error: " . $e->getMessage());
            return false;
        }
    }

    public function reorder($items) {
        try {
            $stmt = $this->db->prepare("UPDATE discipleship_steps SET sort_order = ? WHERE id = ?");
            foreach ($items as $order => $id) {
                $stmt->execute([$order, $id]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Reorder discipleship steps error: " . $e->getMessage());
            return false;
        }
    }

    public function getMemberCompletedStepIds($memberId) {
        try {
            // Only return junction rows for ACTIVE steps. Otherwise legacy entries for
            // now-deactivated steps (PBC / SF) make the completion ratio show e.g. 6/5.
            $stmt = $this->db->prepare(
                "SELECT md.step_id
                 FROM member_discipleship md
                 JOIN discipleship_steps ds ON ds.id = md.step_id
                 WHERE md.member_id = ? AND ds.is_active = 1 AND ds.is_deleted = 0"
            );
            $stmt->execute([$memberId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Get member completed step IDs error: " . $e->getMessage());
            return [];
        }
    }
}
?>
