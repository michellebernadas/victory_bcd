<?php
class VictoryGroup {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // ── Fetch all groups with member arrays attached ──────────────────────────

    public function getAllGroups($filters = []) {
        try {
            $sql = "SELECT vg.* FROM victory_groups vg WHERE vg.is_deleted = 0";
            $params = [];

            if (!empty($filters['group_category'])) {
                $sql .= " AND vg.group_category = ?";
                $params[] = $filters['group_category'];
            }
            if (!empty($filters['day_of_week'])) {
                $sql .= " AND vg.day_of_week = ?";
                $params[] = $filters['day_of_week'];
            }
            if (!empty($filters['meeting_frequency'])) {
                $sql .= " AND vg.meeting_frequency = ?";
                $params[] = $filters['meeting_frequency'];
            }
            if (!empty($filters['group_status'])) {
                $sql .= " AND vg.group_status = ?";
                $params[] = $filters['group_status'];
            }
            if (!empty($filters['group_type'])) {
                $sql .= " AND vg.group_type LIKE ?";
                $params[] = '%' . $filters['group_type'] . '%';
            }

            $dayOrder = "CASE vg.day_of_week
                WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                WHEN 'Sunday' THEN 7 ELSE 8 END";
            $sql .= " ORDER BY $dayOrder, vg.meeting_time ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $groups = $stmt->fetchAll();

            // Attach member arrays in a single second query
            if (!empty($groups)) {
                $groups = $this->attachMembers($groups);
            }

            return $groups;
        } catch (PDOException $e) {
            error_log("Get all groups error: " . $e->getMessage());
            return [];
        }
    }

    public function getGroupById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM victory_groups WHERE id = ?");
            $stmt->execute([$id]);
            $group = $stmt->fetch();
            if ($group) {
                $group = $this->attachMembers([$group])[0];
            }
            return $group;
        } catch (PDOException $e) {
            error_log("Get group by ID error: " . $e->getMessage());
            return false;
        }
    }

    // Fetch all vg_members for a set of groups and attach as arrays
    private function attachMembers(array $groups) {
        $groupIds = array_column($groups, 'id');
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

        // COALESCE: use live members.full_name if linked, otherwise fall back to stored name
        $mStmt = $this->db->prepare(
            "SELECT vm.group_id, COALESCE(m.full_name, vm.name) AS name,
                    vm.gender, vm.role, vm.member_id
             FROM vg_members vm
             LEFT JOIN members m ON vm.member_id = m.id
             WHERE vm.group_id IN ($placeholders)
             ORDER BY vm.sort_order ASC, vm.id ASC"
        );
        $mStmt->execute($groupIds);
        $rows = $mStmt->fetchAll();

        $byGroup = [];
        foreach ($rows as $row) {
            $byGroup[$row['group_id']][$row['role']][] = $row;
        }

        foreach ($groups as &$g) {
            $gid = $g['id'];
            $g['leaders']   = $byGroup[$gid]['leader']   ?? [];
            $g['interns']   = $byGroup[$gid]['intern']   ?? [];
            $g['attendees'] = $byGroup[$gid]['attendee'] ?? [];
        }

        return $groups;
    }

    // ── Add / Update / Delete ─────────────────────────────────────────────────

    public function addGroup($data, $leaders, $interns, $attendees) {
        try {
            $uuid = $this->generateUUID();
            $stmt = $this->db->prepare("
                INSERT INTO victory_groups
                    (uuid, group_type, group_category, day_of_week, meeting_time,
                     location, meeting_frequency, group_status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $dayOfWeek = is_array($data['day_of_week'] ?? null)
                ? implode(', ', array_filter(array_map('trim', $data['day_of_week'])))
                : ($data['day_of_week'] ?? '');
            $stmt->execute([
                $uuid,
                $data['group_type']        ?? '',
                $data['group_category']    ?? '',
                $dayOfWeek,
                $data['meeting_time']      ?: null,
                $data['location']          ?? '',
                $data['meeting_frequency'] ?? 'weekly',
                $data['group_status']      ?? 'active',
                $data['notes']             ?? '',
            ]);
            $groupId = $this->db->lastInsertId();
            $this->syncMembers($groupId, $leaders, $interns, $attendees);
            return $groupId;
        } catch (PDOException $e) {
            error_log("Add group error: " . $e->getMessage());
            return false;
        }
    }

    public function updateGroup($id, $data, $leaders, $interns, $attendees) {
        try {
            $stmt = $this->db->prepare("
                UPDATE victory_groups SET
                    group_type = ?, group_category = ?, day_of_week = ?, meeting_time = ?,
                    location = ?, meeting_frequency = ?, group_status = ?, notes = ?
                WHERE id = ?
            ");
            $dayOfWeek = is_array($data['day_of_week'] ?? null)
                ? implode(', ', array_filter(array_map('trim', $data['day_of_week'])))
                : ($data['day_of_week'] ?? '');
            $stmt->execute([
                $data['group_type']        ?? '',
                $data['group_category']    ?? '',
                $dayOfWeek,
                $data['meeting_time']      ?: null,
                $data['location']          ?? '',
                $data['meeting_frequency'] ?? 'weekly',
                $data['group_status']      ?? 'active',
                $data['notes']             ?? '',
                $id,
            ]);
            $this->syncMembers($id, $leaders, $interns, $attendees);
            return true;
        } catch (PDOException $e) {
            error_log("Update group error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteGroup($id) {
        try {
            $this->db->prepare("UPDATE victory_groups SET group_status = 'inactive' WHERE id = ?")->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Deactivate group error: " . $e->getMessage());
            return false;
        }
    }

    public function activateGroup($id) {
        try {
            $this->db->prepare("UPDATE victory_groups SET group_status = 'active' WHERE id = ?")->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Activate group error: " . $e->getMessage());
            return false;
        }
    }

    /** Returns [leader_names, member_count] — member_count is rows in vg_members. */
    public function getUsageInfo($id): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT GROUP_CONCAT(COALESCE(m.full_name, vm.name) ORDER BY vm.sort_order SEPARATOR ', ') AS leaders
                 FROM vg_members vm
                 LEFT JOIN members m ON m.id = vm.member_id
                 WHERE vm.group_id = ? AND vm.role = 'leader'"
            );
            $stmt->execute([(int)$id]);
            $leaders = $stmt->fetchColumn() ?: '(no leader)';

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM vg_members WHERE group_id = ?");
            $stmt->execute([(int)$id]);
            $count = (int)$stmt->fetchColumn();
            return ['leaders' => $leaders, 'member_count' => $count];
        } catch (PDOException $e) {
            error_log("VictoryGroup::getUsageInfo error: " . $e->getMessage());
            return ['leaders' => '', 'member_count' => 0];
        }
    }

    /** Soft delete a victory group — preserves vg_members rows for audit. Hidden from list queries. */
    public function hardDelete($id): bool {
        try {
            $this->db->prepare("UPDATE victory_groups SET is_deleted = 1, group_status = 'inactive' WHERE id = ?")->execute([(int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("VictoryGroup::hardDelete error: " . $e->getMessage());
            return false;
        }
    }

    // Replace all vg_members rows for a group in one operation
    private function syncMembers($groupId, $leaders, $interns, $attendees) {
        $this->db->prepare("DELETE FROM vg_members WHERE group_id = ?")->execute([$groupId]);
        $stmt = $this->db->prepare(
            "INSERT INTO vg_members (group_id, member_id, name, gender, role, sort_order) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $all = [
            'leader'   => $leaders,
            'intern'   => $interns,
            'attendee' => $attendees,
        ];
        foreach ($all as $role => $members) {
            foreach ($members as $i => $m) {
                $name = trim($m['name'] ?? '');
                if ($name === '') continue;
                // If a member_id was passed (from form), use it; otherwise try to match by name
                $memberId = isset($m['member_id']) && $m['member_id'] ? (int)$m['member_id'] : $this->findMemberIdByName($name);
                $stmt->execute([$groupId, $memberId ?: null, $name, trim($m['gender'] ?? ''), $role, $i]);
            }
        }
    }

    private function findMemberIdByName($name) {
        try {
            $stmt = $this->db->prepare(
                "SELECT id FROM members WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1"
            );
            $stmt->execute([$name]);
            return $stmt->fetchColumn() ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function getStats() {
        try {
            $stats = [];
            $stats['total']  = $this->db->query("SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0")->fetchColumn();
            $stats['active'] = $this->db->query("SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0 AND group_status = 'active'")->fetchColumn();
            $stats['vg']     = $this->db->query("SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0 AND group_type LIKE 'VG%' AND group_status = 'active'")->fetchColumn();
            $stats['lg']     = $this->db->query("SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0 AND group_type LIKE 'LG%' AND group_status = 'active'")->fetchColumn();
            return $stats;
        } catch (PDOException $e) {
            return ['total' => 0, 'active' => 0, 'vg' => 0, 'lg' => 0];
        }
    }

    public function getDistinctMemberNames($role) {
        try {
            // CRITICAL: use the SAME display-name expression as attachMembers() — COALESCE(m.full_name, vm.name).
            // Otherwise the dropdown shows the raw vg_members.name (e.g. "Aiber Arela") while rows store
            // the linked members.full_name (e.g. "Arela, Aiber") in their data-leaders attribute, and
            // the filter never matches anything.
            $stmt = $this->db->prepare(
                "SELECT DISTINCT TRIM(COALESCE(m.full_name, vm.name)) AS name
                 FROM vg_members vm
                 JOIN victory_groups vg ON vg.id = vm.group_id
                 LEFT JOIN members m ON m.id = vm.member_id
                 WHERE vm.role = ?
                   AND TRIM(COALESCE(m.full_name, vm.name)) != ''
                   AND vg.is_deleted = 0
                 ORDER BY name"
            );
            $stmt->execute([$role]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("getDistinctMemberNames error: " . $e->getMessage());
            return [];
        }
    }

    public function getAllMembersList() {
        try {
            return $this->db->query(
                "SELECT id, full_name FROM members WHERE is_deleted = 0 ORDER BY full_name ASC"
            )->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getDistinctLocations() {
        try {
            return $this->db->query(
                "SELECT DISTINCT TRIM(location) AS location FROM victory_groups WHERE TRIM(location) != '' AND is_deleted = 0 ORDER BY location ASC"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getDistinctTimes() {
        try {
            return $this->db->query(
                "SELECT DISTINCT meeting_time FROM victory_groups WHERE meeting_time IS NOT NULL AND is_deleted = 0 ORDER BY meeting_time"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
?>
