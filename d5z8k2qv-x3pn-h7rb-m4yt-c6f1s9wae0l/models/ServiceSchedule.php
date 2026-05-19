<?php
class ServiceSchedule {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAllServices() {
        try {
            $stmt = $this->db->query("SELECT * FROM services WHERE is_deleted = 0 ORDER BY sort_order ASC, name ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get all services error: " . $e->getMessage());
            return [];
        }
    }

    public function addService($name) {
        try {
            $stmt = $this->db->prepare("INSERT INTO services (name) VALUES (?)");
            $stmt->execute([trim($name)]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Add service error: " . $e->getMessage());
            return false;
        }
    }

    public function updateService($id, $name) {
        $newName = trim($name);
        try {
            $this->db->beginTransaction();

            // Look up the old name so we can propagate the rename to members
            $stmt = $this->db->prepare("SELECT name FROM services WHERE id = ?");
            $stmt->execute([$id]);
            $oldName = $stmt->fetchColumn();

            $stmt = $this->db->prepare("UPDATE services SET name = ? WHERE id = ?");
            $stmt->execute([$newName, $id]);

            if ($oldName !== false && $oldName !== '' && $oldName !== $newName) {
                // members.service_attending can be a CSV like "9:00 AM, 11:00 AM" — tokenize and replace.
                $like = '%' . $oldName . '%';
                $rows = $this->db->prepare("SELECT id, service_attending FROM members WHERE service_attending LIKE ?");
                $rows->execute([$like]);
                $patch = $this->db->prepare("UPDATE members SET service_attending = ? WHERE id = ?");
                foreach ($rows->fetchAll() as $r) {
                    $tokens = array_map('trim', explode(',', $r['service_attending']));
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
            error_log("Update service error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteService($id) {
        try {
            $stmt = $this->db->prepare("UPDATE services SET is_deleted = 1, is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Delete service error: " . $e->getMessage());
            return false;
        }
    }

    /** Returns [name, count] — count is how many members are still attending this service. */
    public function getUsageInfo($id): array {
        try {
            $stmt = $this->db->prepare("SELECT name FROM services WHERE id = ?");
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            if ($name === false) return ['name' => null, 'count' => 0];
            // Whole-token match against the CSV service_attending column.
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM members
                 WHERE is_deleted = 0
                   AND CONCAT(', ', service_attending, ', ') LIKE CONCAT('%, ', ?, ', %')"
            );
            $stmt->execute([$name]);
            return ['name' => $name, 'count' => (int)$stmt->fetchColumn()];
        } catch (PDOException $e) {
            error_log("Service usage check error: " . $e->getMessage());
            return ['name' => null, 'count' => 0];
        }
    }

    public function deactivateService($id) {
        try {
            $stmt = $this->db->prepare("UPDATE services SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Deactivate service error: " . $e->getMessage());
            return false;
        }
    }

    public function activateService($id) {
        try {
            $stmt = $this->db->prepare("UPDATE services SET is_active = 1 WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Activate service error: " . $e->getMessage());
            return false;
        }
    }

    public function reorder($items) {
        try {
            $stmt = $this->db->prepare("UPDATE services SET sort_order = ? WHERE id = ?");
            foreach ($items as $order => $id) {
                $stmt->execute([$order, $id]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Reorder services error: " . $e->getMessage());
            return false;
        }
    }
}
?>
