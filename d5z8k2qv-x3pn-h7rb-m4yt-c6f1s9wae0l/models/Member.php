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
                             WHERE md.member_id = members.id AND ds.is_active = 1 AND ds.is_deleted = 0
                               AND NOT (md.completion_source = 'historical' AND md.historical_verification_status = 'rejected')),
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
                        $sql .= " AND EXISTS (SELECT 1 FROM member_discipleship md WHERE md.member_id = members.id AND md.step_id = ? AND NOT (md.completion_source = 'historical' AND md.historical_verification_status = 'rejected'))";
                        $params[] = $stepId;
                    } elseif ($val == '0') {
                        $sql .= " AND NOT EXISTS (SELECT 1 FROM member_discipleship md WHERE md.member_id = members.id AND md.step_id = ? AND NOT (md.completion_source = 'historical' AND md.historical_verification_status = 'rejected'))";
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

    /**
     * Updates the member's own details only.
     *
     * Discipleship completion is NOT written here any more. This method used to
     * set members.purple_book_class and members.spiritual_foundations straight
     * from the form's checkbox group, which made the edit form a second source
     * of truth: ticking a box marked a step complete with no evidence, and SF
     * could disagree with its own attendance records.
     *
     * Completion is now derived by DiscipleshipProgressService from attendance,
     * plus admin-approved historical completions (Settings › Historical
     * Completions). We recalculate after saving because member_status affects
     * the dashboard's active-member roll-ups.
     */
    public function updateMember($id, $data) {
        try {
            $names = $this->normalizeNameFields($data);

            $stmt = $this->db->prepare("
                UPDATE members SET
                    full_name = ?, last_name = ?, first_name = ?,
                    civil_status = ?, ministry = ?, service_attending = ?,
                    volunteer_status = ?, contact_number = ?,
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
                $data['member_status'] ?? 'active',
                $data['notes'] ?? '',
                $id
            ]);

            // Re-derive the journey from current evidence (no-op if nothing changed).
            require_once 'models/DiscipleshipProgressService.php';
            (new DiscipleshipProgressService($this->db))->recalculateMember((int)$id);

            return true;
        } catch (PDOException $e) {
            error_log("Update member error: " . $e->getMessage());
            return false;
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

    /**
     * Step counts read from member_discipleship — the authoritative derived
     * state — rather than the members.<step> cache columns, so the dashboard
     * can't disagree with a member's own profile.
     */
    public function getStats() {
        $stats = ['total' => 0, 'active' => 0];
        try {
            $stats['total']  = (int)$this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0")->fetchColumn();
            $stats['active'] = (int)$this->db->query("SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND member_status = 'active'")->fetchColumn();
            foreach ($this->stepCompletionCounts(false) as $key => $n) $stats[$key] = $n;
        } catch (PDOException $e) {
            error_log("getStats error: " . $e->getMessage());
        }
        // Keys the dashboard reads unconditionally.
        foreach (['victory_weekend','church_community','making_disciples','empowering_leaders','leadership_113'] as $k) {
            $stats[$k] = $stats[$k] ?? 0;
        }
        return $stats;
    }

    /**
     * column_key => number of completed members, from the derived state.
     * @param bool $activeOnly restrict to members whose status is active
     */
    private function stepCompletionCounts(bool $activeOnly): array {
        $out = [];
        try {
            $sql = "SELECT ds.column_key, COUNT(*) AS n
                      FROM member_discipleship md
                      JOIN discipleship_steps ds ON ds.id = md.step_id
                      JOIN members m ON m.id = md.member_id
                     WHERE m.is_deleted = 0
                       AND ds.column_key IS NOT NULL AND ds.column_key != ''
                       AND NOT (md.completion_source = 'historical' AND md.historical_verification_status = 'rejected')";
            if ($activeOnly) $sql .= " AND m.member_status = 'active'";
            $sql .= " GROUP BY ds.column_key";
            foreach ($this->db->query($sql)->fetchAll() as $r) {
                $out[$r['column_key']] = (int)$r['n'];
            }
        } catch (PDOException $e) {
            error_log("stepCompletionCounts error: " . $e->getMessage());
        }
        return $out;
    }

    /**
     * Same shape as getStats(), but every figure is restricted to ACTIVE members.
     * The dashboard's Discipleship Journey Overview reports "out of active
     * members", so both the numerator and the denominator must exclude
     * inactive/soft-deleted people — otherwise the percentages understate reality.
     *
     * Step keys are read from discipleship_steps.column_key so a newly activated
     * step (e.g. Spiritual Foundations) is picked up without touching this code.
     */
    public function getActiveStats(): array {
        $stats = ['total' => 0];
        try {
            $stats['total'] = (int)$this->db->query(
                "SELECT COUNT(*) FROM members WHERE is_deleted = 0 AND member_status = 'active'"
            )->fetchColumn();
            $stats['active'] = $stats['total'];
            // Derived state, not the members.<step> cache columns.
            foreach ($this->stepCompletionCounts(true) as $key => $n) $stats[$key] = $n;
        } catch (PDOException $e) {
            error_log("getActiveStats error: " . $e->getMessage());
        }
        return $stats;
    }

    /** Active-member counts per worship service, split into AM / PM. */
    public function getServiceAttendanceStats(): array {
        $out = [];
        try {
            $services = $this->db->query(
                "SELECT name, service_period FROM services
                  WHERE is_active = 1 AND is_deleted = 0 ORDER BY sort_order ASC, name ASC"
            )->fetchAll();
            // members.service_attending is a CSV ("8:30 AM, 11:00 AM") — match whole tokens.
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM members
                  WHERE is_deleted = 0 AND member_status = 'active'
                    AND CONCAT(', ', service_attending, ', ') LIKE CONCAT('%, ', ?, ', %')"
            );
            foreach ($services as $svc) {
                $stmt->execute([$svc['name']]);
                $out[] = [
                    'name'   => $svc['name'],
                    'period' => $svc['service_period'] ?? '',
                    'count'  => (int)$stmt->fetchColumn(),
                ];
            }
        } catch (PDOException $e) {
            error_log("getServiceAttendanceStats error: " . $e->getMessage());
        }
        return $out;
    }

    // ── Removed: former duplicate sources of truth ──────────────────────────
    // buildManualBooleans / syncManualJunction / syncMemberDiscipleship /
    // buildLegacyBooleans / getMemberBooleanColumns used to write the
    // members.<step> flags and member_discipleship rows straight from form
    // input. They are gone so completion can only ever come from
    // DiscipleshipProgressService, which derives it from attendance plus
    // admin-approved historical completions.

    // ── Private helpers ────────────────────────────────────────────────────

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
