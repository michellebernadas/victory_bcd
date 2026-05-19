<?php
require_once 'models/DiscipleshipStep.php';

class Member {
    private $db;
    private $stepModel;

    public function __construct($db) {
        $this->db = $db;
        $this->stepModel = new DiscipleshipStep($db);
    }

    public function getAllMembers($filters = []) {
        try {
            $sql = "SELECT members.*,
                        IFNULL(
                            (SELECT GROUP_CONCAT(md.step_id ORDER BY md.step_id SEPARATOR ',')
                             FROM member_discipleship md
                             JOIN discipleship_steps ds ON ds.id = md.step_id
                             WHERE md.member_id = members.id AND ds.is_active = 1 AND ds.is_deleted = 0),
                            ''
                        ) AS completed_step_ids_str
                    FROM members WHERE is_deleted = 0";
            $params = [];

            if (!empty($filters['ministry'])) {
                $sql .= " AND ministry = ?";
                $params[] = $filters['ministry'];
            }
            if (!empty($filters['civil_status'])) {
                $sql .= " AND civil_status = ?";
                $params[] = $filters['civil_status'];
            }
            if (!empty($filters['volunteer_status'])) {
                $sql .= " AND volunteer_status = ?";
                $params[] = $filters['volunteer_status'];
            }
            if (!empty($filters['member_status'])) {
                $sql .= " AND member_status = ?";
                $params[] = $filters['member_status'];
            }

            // Dynamic discipleship step filters via junction table
            if (!empty($filters['discipleship']) && is_array($filters['discipleship'])) {
                foreach ($filters['discipleship'] as $stepId => $val) {
                    if ($val === '' || $val === null) continue;
                    $stepId = (int)$stepId;
                    if ($val == '1') {
                        $sql .= " AND EXISTS (SELECT 1 FROM member_discipleship md WHERE md.member_id = members.id AND md.step_id = ?)";
                        $params[] = $stepId;
                    } elseif ($val == '0') {
                        $sql .= " AND NOT EXISTS (SELECT 1 FROM member_discipleship md WHERE md.member_id = members.id AND md.step_id = ?)";
                        $params[] = $stepId;
                    }
                }
            }

            $sql .= " ORDER BY full_name ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Convert comma-separated string to array of ints
            foreach ($rows as &$row) {
                $str = $row['completed_step_ids_str'] ?? '';
                $row['completed_step_ids'] = $str !== '' ? array_map('intval', explode(',', $str)) : [];
                unset($row['completed_step_ids_str']);
                $row['vg_memberships'] = [];
            }
            unset($row);

            // Fetch each member's VG/LG memberships in a single query, then attach
            if (!empty($rows)) {
                $ids = array_column($rows, 'id');
                $ph  = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->db->prepare(
                    "SELECT vm.member_id, vm.role, vg.id AS group_id, vg.group_type, vg.group_category,
                            vg.day_of_week, vg.meeting_time, vg.meeting_frequency, vg.location, vg.group_status,
                            (SELECT GROUP_CONCAT(COALESCE(m2.full_name, vm2.name) ORDER BY vm2.sort_order SEPARATOR '||')
                             FROM vg_members vm2 LEFT JOIN members m2 ON m2.id = vm2.member_id
                             WHERE vm2.group_id = vg.id AND vm2.role = 'leader') AS leader_list,
                            (SELECT GROUP_CONCAT(COALESCE(m2.full_name, vm2.name) ORDER BY vm2.sort_order SEPARATOR '||')
                             FROM vg_members vm2 LEFT JOIN members m2 ON m2.id = vm2.member_id
                             WHERE vm2.group_id = vg.id AND vm2.role = 'intern') AS intern_list,
                            (SELECT GROUP_CONCAT(COALESCE(m2.full_name, vm2.name) ORDER BY vm2.sort_order SEPARATOR '||')
                             FROM vg_members vm2 LEFT JOIN members m2 ON m2.id = vm2.member_id
                             WHERE vm2.group_id = vg.id AND vm2.role = 'attendee') AS attendee_list,
                            (SELECT GROUP_CONCAT(COALESCE(m2.full_name, vm2.name) ORDER BY vm2.sort_order SEPARATOR ', ')
                             FROM vg_members vm2 LEFT JOIN members m2 ON m2.id = vm2.member_id
                             WHERE vm2.group_id = vg.id AND vm2.role = 'leader') AS leader_names
                     FROM vg_members vm
                     JOIN victory_groups vg ON vg.id = vm.group_id
                     WHERE vm.member_id IN ($ph) AND vg.is_deleted = 0
                     ORDER BY FIELD(vm.role, 'leader', 'intern', 'attendee'), vg.group_status"
                );
                $stmt->execute($ids);
                $byMember = [];
                foreach ($stmt->fetchAll() as $r) {
                    $byMember[$r['member_id']][] = $r;
                }
                foreach ($rows as &$row) {
                    $row['vg_memberships'] = $byMember[$row['id']] ?? [];
                }
                unset($row);
            }

            return $rows;
        } catch (PDOException $e) {
            error_log("Get all members error: " . $e->getMessage());
            return [];
        }
    }

    public function getMemberById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $member = $stmt->fetch();
            if ($member) {
                $member['completed_step_ids'] = $this->stepModel->getMemberCompletedStepIds((int)$member['id']);
            }
            return $member;
        } catch (PDOException $e) {
            error_log("Get member by ID error: " . $e->getMessage());
            return false;
        }
    }

    public function getMemberByUuid($uuid) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM members WHERE uuid = ?");
            $stmt->execute([$uuid]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    public function addMember($data) {
        try {
            $uuid  = $this->generateUUID();
            $names = $this->normalizeNameFields($data);

            // New members start with all discipleship flags = 0. They get auto-set as
            // attendance records are added on the Attendance Records / L113 pages.
            $stmt = $this->db->prepare("
                INSERT INTO members (uuid, full_name, last_name, first_name,
                    civil_status, ministry, service_attending,
                    volunteer_status, contact_number,
                    victory_weekend, church_community, making_disciples, empowering_leaders,
                    leadership_113, purple_book_class, spiritual_foundations, member_status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, ?, ?)
            ");
            $stmt->execute([
                $uuid,
                $names['full_name'],
                $names['last_name'],
                $names['first_name'],
                $data['civil_status'] ?? '',
                $data['ministry'] ?? '',
                $data['service_attending'] ?? '',
                strtoupper(trim($data['volunteer_status'] ?? '')),
                $data['contact_number'] ?? '',
                $data['member_status'] ?? 'active',
                $data['notes'] ?? ''
            ]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Add member error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Accepts either separate first_name / last_name fields (preferred) or a single full_name
     * in "Last, First" canonical format (legacy). Returns the three normalized values:
     *   ['first_name' => ..., 'last_name' => ..., 'full_name' => 'Last, First']
     * Used by addMember + updateMember so all three columns stay in sync.
     */
    private function normalizeNameFields(array $data): array {
        $first = trim($data['first_name'] ?? '');
        $last  = trim($data['last_name']  ?? '');
        $full  = trim($data['full_name']  ?? '');
        // If the caller only supplied full_name (legacy clients), parse it back into parts.
        if ($first === '' && $last === '' && $full !== '' && strpos($full, ',') !== false) {
            [$lastRaw, $firstRaw] = array_pad(explode(',', $full, 2), 2, '');
            $last  = trim($lastRaw);
            $first = trim($firstRaw);
        }
        // Always rebuild the canonical from the parts so it stays consistent.
        $full = ($last !== '' && $first !== '') ? ($last . ', ' . $first) : ($last ?: $first);
        return ['first_name' => $first, 'last_name' => $last, 'full_name' => $full];
    }

    public function updateMember($id, $data) {
        try {
            $names = $this->normalizeNameFields($data);
            // The 5 attendance-tracked steps (VW/CC/MD/EL/L113) are auto-derived from records and
            // intentionally not written here. PBC + SF have no attendance flow, so we still accept
            // their values from the form's checkbox group.
            $manualKeys = ['purple_book_class', 'spiritual_foundations'];
            $manualFlags = $this->buildManualBooleans($data['discipleship_steps'] ?? [], $manualKeys);

            $stmt = $this->db->prepare("
                UPDATE members SET
                    full_name = ?, last_name = ?, first_name = ?,
                    civil_status = ?, ministry = ?, service_attending = ?,
                    volunteer_status = ?, contact_number = ?,
                    purple_book_class = ?, spiritual_foundations = ?,
                    member_status = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $names['full_name'],
                $names['last_name'],
                $names['first_name'],
                $data['civil_status'] ?? '',
                $data['ministry'] ?? '',
                $data['service_attending'] ?? '',
                strtoupper(trim($data['volunteer_status'] ?? '')),
                $data['contact_number'] ?? '',
                $manualFlags['purple_book_class'],
                $manualFlags['spiritual_foundations'],
                $data['member_status'] ?? 'active',
                $data['notes'] ?? '',
                $id
            ]);

            // Keep the junction table aligned for the manual steps only.
            $this->syncManualJunction($id, $data['discipleship_steps'] ?? [], $manualKeys);

            return true;
        } catch (PDOException $e) {
            error_log("Update member error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * For the given submitted step-ids, returns ['column_key' => 1|0] but only for the keys in
     * $allowedKeys. Used to scope which boolean columns the member form is allowed to write.
     */
    private function buildManualBooleans(array $stepIds, array $allowedKeys): array {
        $out = array_fill_keys($allowedKeys, 0);
        if (empty($stepIds)) return $out;
        $stepIds = array_map('intval', $stepIds);
        $placeholders = implode(',', array_fill(0, count($stepIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT column_key FROM discipleship_steps WHERE id IN ($placeholders) AND column_key IS NOT NULL"
        );
        $stmt->execute($stepIds);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (in_array($key, $allowedKeys, true)) $out[$key] = 1;
        }
        return $out;
    }

    /**
     * Add/remove junction rows for the given manual steps. Attendance-tracked junction rows are
     * managed by ProgramAttendance::syncMemberFlag and are NOT touched here.
     */
    private function syncManualJunction(int $memberId, array $stepIds, array $allowedKeys): void {
        // Resolve step ids whose column_key is in $allowedKeys
        $idsForAllowed = $this->db->prepare(
            "SELECT id, column_key FROM discipleship_steps WHERE column_key IN (" . implode(',', array_fill(0, count($allowedKeys), '?')) . ")"
        );
        $idsForAllowed->execute($allowedKeys);
        $allowedIds = [];
        foreach ($idsForAllowed->fetchAll(PDO::FETCH_KEY_PAIR) as $sid => $_k) $allowedIds[(int)$sid] = true;
        // Wipe just the allowed rows
        $this->db->prepare(
            "DELETE FROM member_discipleship WHERE member_id = ? AND step_id IN (" . implode(',', array_keys($allowedIds) ?: [0]) . ")"
        )->execute([$memberId]);
        // Insert ticked allowed step ids
        $tickedAllowed = array_intersect(array_map('intval', $stepIds), array_keys($allowedIds));
        if ($tickedAllowed) {
            $ins = $this->db->prepare("INSERT IGNORE INTO member_discipleship (member_id, step_id) VALUES (?, ?)");
            foreach ($tickedAllowed as $sid) $ins->execute([$memberId, (int)$sid]);
        }
    }

    public function deactivateMember($id) {
        try {
            $stmt = $this->db->prepare("UPDATE members SET member_status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Deactivate member error: " . $e->getMessage());
            return false;
        }
    }

    public function activateMember($id) {
        try {
            $stmt = $this->db->prepare("UPDATE members SET member_status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Activate member error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteMember($id) {
        // Soft delete — preserves data and history. Filtered out of list views.
        try {
            $stmt = $this->db->prepare("UPDATE members SET is_deleted = 1, member_status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Delete member error: " . $e->getMessage());
            return false;
        }
    }

    /** Returns [name, attendance_count, vg_count, total] — counts external references that prevent safe hard-delete. */
    public function getUsageInfo($id): array {
        try {
            $stmt = $this->db->prepare("SELECT full_name FROM members WHERE id = ?");
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            if ($name === false) return ['name' => null, 'attendance_count' => 0, 'vg_count' => 0, 'total' => 0];

            $stmt = $this->db->prepare("SELECT COUNT(*) FROM program_attendances WHERE member_id = ? AND is_deleted = 0");
            $stmt->execute([$id]);
            $attCount = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM vg_members vm
                 JOIN victory_groups vg ON vg.id = vm.group_id
                 WHERE vm.member_id = ? AND vg.is_deleted = 0"
            );
            $stmt->execute([$id]);
            $vgCount = (int)$stmt->fetchColumn();

            return [
                'name'             => $name,
                'attendance_count' => $attCount,
                'vg_count'         => $vgCount,
                'total'            => $attCount + $vgCount,
            ];
        } catch (PDOException $e) {
            error_log("Member usage check error: " . $e->getMessage());
            return ['name' => null, 'attendance_count' => 0, 'vg_count' => 0, 'total' => 0];
        }
    }

    public function getDiscipleshipSteps() {
        return $this->stepModel->getActiveSteps();
    }

    public function getMemberCompletedStepIds($memberId) {
        return $this->stepModel->getMemberCompletedStepIds($memberId);
    }

    public function getDistinctMinistries() {
        try {
            $stmt = $this->db->query("SELECT DISTINCT ministry FROM members WHERE ministry != '' AND is_deleted = 0 ORDER BY ministry ASC");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getAllMinistries() {
        try {
            $stmt = $this->db->query("SELECT * FROM ministries WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getAllServices() {
        try {
            $stmt = $this->db->query("SELECT * FROM services WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getStats() {
        try {
            $stats = [];
            $stats['total'] = $this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0")->fetchColumn();
            $stats['active'] = $this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND member_status = 'active'")->fetchColumn();
            $stats['victory_weekend'] = $this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND victory_weekend = 1")->fetchColumn();
            $stats['church_community'] = $this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND church_community = 1")->fetchColumn();
            $stats['making_disciples'] = $this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND making_disciples = 1")->fetchColumn();
            $stats['empowering_leaders'] = $this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND empowering_leaders = 1")->fetchColumn();
            $stats['leadership_113'] = $this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND leadership_113 = 1")->fetchColumn();
            return $stats;
        } catch (PDOException $e) {
            return ['total' => 0, 'active' => 0, 'victory_weekend' => 0, 'church_community' => 0,
                    'making_disciples' => 0, 'empowering_leaders' => 0, 'leadership_113' => 0];
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Syncs the member_discipleship junction table for a given member.
     * $stepIds is an array of step IDs (integers or strings).
     */
    private function syncMemberDiscipleship($memberId, $stepIds) {
        // Delete existing rows
        $stmt = $this->db->prepare("DELETE FROM member_discipleship WHERE member_id = ?");
        $stmt->execute([$memberId]);

        if (empty($stepIds)) return;

        $stmt = $this->db->prepare("INSERT IGNORE INTO member_discipleship (member_id, step_id) VALUES (?, ?)");
        foreach ($stepIds as $stepId) {
            $stepId = (int)$stepId;
            if ($stepId > 0) {
                $stmt->execute([$memberId, $stepId]);
            }
        }
    }

    /**
     * Given an array of step IDs, look up their column_keys and return an array
     * of legacy boolean column values [column_key => 0|1].
     */
    private function buildLegacyBooleans($stepIds) {
        $defaults = [
            'victory_weekend'     => 0,
            'church_community'    => 0,
            'making_disciples'    => 0,
            'empowering_leaders'  => 0,
            'leadership_113'      => 0,
            'purple_book_class'   => 0,
            'spiritual_foundations' => 0,
        ];

        if (empty($stepIds)) return $defaults;

        try {
            if (count($stepIds) === 0) return $defaults;

            $placeholders = implode(',', array_fill(0, count($stepIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT column_key FROM discipleship_steps WHERE id IN ($placeholders) AND column_key IS NOT NULL"
            );
            $stmt->execute(array_map('intval', $stepIds));
            $keys = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($keys as $key) {
                if (isset($defaults[$key])) {
                    $defaults[$key] = 1;
                }
            }
        } catch (PDOException $e) {
            error_log("Build legacy booleans error: " . $e->getMessage());
        }

        return $defaults;
    }

    public function searchByName(string $term, int $limit = 30): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, full_name, ministry FROM members
                 WHERE full_name LIKE ? AND member_status = 'active' AND is_deleted = 0
                 ORDER BY full_name LIMIT ?"
            );
            $stmt->execute(['%' . $term . '%', $limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Returns one row matching the canonical "Lastname, Firstname" exactly (case-insensitive), or null. */
    public function findByFullName(string $fullName): ?array {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, full_name, ministry FROM members
                 WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?))
                   AND member_status = 'active' AND is_deleted = 0
                 LIMIT 1"
            );
            $stmt->execute([$fullName]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
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
