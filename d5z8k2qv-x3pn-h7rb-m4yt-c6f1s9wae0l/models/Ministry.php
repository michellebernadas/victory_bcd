<?php
class Ministry {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAllMinistries() {
        try {
            $stmt = $this->db->query("SELECT * FROM ministries WHERE is_deleted = 0 ORDER BY sort_order ASC, name ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get all ministries error: " . $e->getMessage());
            return [];
        }
    }

    public function addMinistry($name) {
        try {
            $stmt = $this->db->prepare("INSERT INTO ministries (name) VALUES (?)");
            $stmt->execute([trim($name)]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Add ministry error: " . $e->getMessage());
            return false;
        }
    }

    public function updateMinistry($id, $name) {
        $newName = trim($name);
        try {
            $this->db->beginTransaction();

            // Look up the old name so we can propagate the rename to members
            $stmt = $this->db->prepare("SELECT name FROM ministries WHERE id = ?");
            $stmt->execute([$id]);
            $oldName = $stmt->fetchColumn();

            // Update the ministry record
            $stmt = $this->db->prepare("UPDATE ministries SET name = ? WHERE id = ?");
            $stmt->execute([$newName, $id]);

            // If the name actually changed, propagate to all members assigned to it.
            // members.ministry may be a single value OR a CSV like "MUSIC, USHERING",
            // so we tokenize and replace the matching token.
            if ($oldName !== false && $oldName !== '' && $oldName !== $newName) {
                $like = '%' . $oldName . '%';
                $rows = $this->db->prepare("SELECT id, ministry FROM members WHERE ministry LIKE ?");
                $rows->execute([$like]);
                $patch = $this->db->prepare("UPDATE members SET ministry = ? WHERE id = ?");
                foreach ($rows->fetchAll() as $r) {
                    $tokens = array_map('trim', explode(',', $r['ministry']));
                    $changed = false;
                    foreach ($tokens as &$t) {
                        if ($t === $oldName) { $t = $newName; $changed = true; }
                    }
                    if ($changed) $patch->execute([implode(', ', $tokens), $r['id']]);
                }
            }

            $this->db->commit();
            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Update ministry error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteMinistry($id) {
        try {
            $stmt = $this->db->prepare("UPDATE ministries SET is_deleted = 1, is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Delete ministry error: " . $e->getMessage());
            return false;
        }
    }

    /** Returns [name, count] for the ministry — count is how many members are still assigned to it. */
    public function getUsageInfo($id): array {
        try {
            $stmt = $this->db->prepare("SELECT name FROM ministries WHERE id = ?");
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            if ($name === false) return ['name' => null, 'count' => 0];
            // Whole-token match against the CSV ministry column (so "MUSIC, USHERING" counts for both).
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM members
                 WHERE is_deleted = 0
                   AND CONCAT(', ', ministry, ', ') LIKE CONCAT('%, ', ?, ', %')"
            );
            $stmt->execute([$name]);
            return ['name' => $name, 'count' => (int)$stmt->fetchColumn()];
        } catch (PDOException $e) {
            error_log("Ministry usage check error: " . $e->getMessage());
            return ['name' => null, 'count' => 0];
        }
    }

    public function deactivateMinistry($id) {
        try {
            $stmt = $this->db->prepare("UPDATE ministries SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Deactivate ministry error: " . $e->getMessage());
            return false;
        }
    }

    public function activateMinistry($id) {
        try {
            $stmt = $this->db->prepare("UPDATE ministries SET is_active = 1 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Activate ministry error: " . $e->getMessage());
            return false;
        }
    }

    public function reorder($items) {
        try {
            $stmt = $this->db->prepare("UPDATE ministries SET sort_order = ? WHERE id = ?");
            foreach ($items as $order => $id) {
                $stmt->execute([$order, $id]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Reorder ministries error: " . $e->getMessage());
            return false;
        }
    }
}
?>
