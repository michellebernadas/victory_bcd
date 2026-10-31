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

            if (!empty($filters['group_kind'])) {
                $sql .= " AND vg.group_kind = ?";
                $params[] = $filters['group_kind'];
            }
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
            // Date range — filters on when the group was created (victory_groups.dateadded).
            if (!empty($filters['date_from'])) {
                $sql .= " AND DATE(vg.dateadded) >= ?";
                $params[] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $sql .= " AND DATE(vg.dateadded) <= ?";
                $params[] = $filters['date_to'];
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

        // display_name is the verbatim name on file (couples never split); it wins over
        // everything else. Fall back to COALESCE(members.full_name, vg_members.name) only
        // when display_name is empty (legacy rows written before that column existed).
        // The clickable link text, when linked, is still the live members.full_name.
        //
        // A couple row ("Amy & Carl") keeps that ONE verbatim string as its name and
        // carries TWO member links: member_id is Person 1, member_id_2 is Person 2, each
        // with its own link_status. Both are joined here so the Victory Groups screen can
        // show who each slot resolved to without a second query per row. member2_name is
        // NULL on every non-couple row, which is what keeps their rendering unchanged.
        $mStmt = $this->db->prepare(
            "SELECT vm.id AS vg_member_id, vm.group_id,
                    COALESCE(NULLIF(vm.display_name, ''), m.full_name, vm.name) AS name,
                    vm.gender, vm.role, vm.member_id, vm.link_status,
                    vm.is_couple, vm.member_id_2, vm.link_status_2,
                    m.full_name  AS member_name,
                    m2.full_name AS member2_name
             FROM vg_members vm
             LEFT JOIN members m  ON vm.member_id   = m.id
             LEFT JOIN members m2 ON vm.member_id_2 = m2.id
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
            $g['leaders']    = $byGroup[$gid]['leader']    ?? [];
            $g['interns']    = $byGroup[$gid]['intern']    ?? [];
            $g['attendees']  = $byGroup[$gid]['attendee']  ?? [];
            $g['lg_leaders'] = $byGroup[$gid]['lg_leader'] ?? [];
            // Leadership Groups imported before the 'lg_leader' role existed stored their
            // leaders as plain 'leader'. Treat those as LG leaders so the LG tab, its
            // filter and its stat card all see the same people.
            if (($g['group_kind'] ?? '') === 'leadership_group' && empty($g['lg_leaders'])) {
                $g['lg_leaders'] = $g['leaders'];
                $g['leaders']    = [];
            }
        }

        return $groups;
    }

    // ── Add / Update / Delete ─────────────────────────────────────────────────

    private const VALID_KINDS = ['victory_group', 'leadership_group', 'campus'];

    public function addGroup($data, $leaders, $interns, $attendees, $lgLeaders = []) {
        try {
            $uuid = $this->generateUUID();
            $groupKind = in_array($data['group_kind'] ?? '', self::VALID_KINDS, true)
                ? $data['group_kind'] : 'victory_group';
            $stmt = $this->db->prepare("
                INSERT INTO victory_groups
                    (uuid, group_kind, group_type, group_category, day_of_week, meeting_time,
                     location, meeting_frequency, group_status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $dayOfWeek = is_array($data['day_of_week'] ?? null)
                ? implode(', ', array_filter(array_map('trim', $data['day_of_week'])))
                : ($data['day_of_week'] ?? '');
            $stmt->execute([
                $uuid,
                $groupKind,
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
            $this->syncMembers($groupId, $leaders, $interns, $attendees, $lgLeaders);
            return $groupId;
        } catch (PDOException $e) {
            error_log("Add group error: " . $e->getMessage());
            return false;
        }
    }

    public function updateGroup($id, $data, $leaders, $interns, $attendees, $lgLeaders = []) {
        try {
            $groupKind = in_array($data['group_kind'] ?? '', self::VALID_KINDS, true)
                ? $data['group_kind'] : 'victory_group';
            $stmt = $this->db->prepare("
                UPDATE victory_groups SET
                    group_kind = ?, group_type = ?, group_category = ?, day_of_week = ?, meeting_time = ?,
                    location = ?, meeting_frequency = ?, group_status = ?, notes = ?
                WHERE id = ?
            ");
            $dayOfWeek = is_array($data['day_of_week'] ?? null)
                ? implode(', ', array_filter(array_map('trim', $data['day_of_week'])))
                : ($data['day_of_week'] ?? '');
            $stmt->execute([
                $groupKind,
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
            $this->syncMembers($id, $leaders, $interns, $attendees, $lgLeaders);
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

    /**
     * Replace all vg_members rows for a group in one operation.
     *
     * COUPLE LINKS SURVIVE AN EDIT. This method deletes and re-inserts the whole
     * roster, so without the snapshot below, saving an unrelated change on the
     * Edit Group form (a new venue, a reordered member) would silently throw away
     * every second-spouse link an admin had hand-made on that group. The form has
     * no field for member_id_2, so it cannot resend them. They are read back out
     * first, keyed on (role, name), and re-applied to the row that carries the
     * same verbatim name.
     *
     * is_couple is recomputed from the name with the same test the importer uses,
     * so a couple typed straight into the form gets its second slot too.
     */
    private function syncMembers($groupId, $leaders, $interns, $attendees, $lgLeaders = []) {
        require_once 'models/GroupImport.php';

        // Snapshot the hand-made second links before the delete.
        $keepSlot2 = [];
        try {
            $prev = $this->db->prepare(
                "SELECT role, COALESCE(NULLIF(display_name, ''), name) AS nm, member_id_2, link_status_2
                 FROM vg_members
                 WHERE group_id = ? AND member_id_2 IS NOT NULL"
            );
            $prev->execute([$groupId]);
            foreach ($prev->fetchAll() as $r) {
                $keepSlot2[$r['role'] . '|' . mb_strtolower(trim($r['nm']), 'UTF-8')] =
                    [(int)$r['member_id_2'], $r['link_status_2']];
            }
        } catch (PDOException $e) {
            error_log("VictoryGroup::syncMembers snapshot error: " . $e->getMessage());
        }

        $this->db->prepare("DELETE FROM vg_members WHERE group_id = ?")->execute([$groupId]);
        $stmt = $this->db->prepare(
            "INSERT INTO vg_members (group_id, member_id, member_id_2, name, display_name, is_couple,
                                     gender, role, link_status, link_status_2, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $all = [
            'leader'    => $leaders,
            'intern'    => $interns,
            'attendee'  => $attendees,
            'lg_leader' => $lgLeaders,
        ];
        foreach ($all as $role => $members) {
            foreach ($members as $i => $m) {
                $name = trim($m['name'] ?? '');
                if ($name === '') continue;
                $isCouple = GroupImport::isCoupleName($name);
                // A couple string names two people, so it must never be matched to a
                // single member by name — exactly as the importer refuses to.
                $memberId = isset($m['member_id']) && $m['member_id']
                    ? (int)$m['member_id']
                    : ($isCouple ? null : $this->findMemberIdByName($name));
                $linkStatus = $memberId ? 'linked' : 'needs_link';

                [$mid2, $status2] = $keepSlot2[$role . '|' . mb_strtolower($name, 'UTF-8')] ?? [null, 'needs_link'];

                $stmt->execute([
                    $groupId, $memberId ?: null, $mid2, $name, $name, $isCouple ? 1 : 0,
                    trim($m['gender'] ?? ''), $role, $linkStatus, $status2, $i,
                ]);
            }
        }
    }

    // ── Manual linking of one vg_members row to a member ──────────────────────

    /**
     * Point one slot of one vg_members row at one member.
     *
     * This is the ONLY way a couple ever becomes linked: an admin picks Person 1
     * and Person 2 by hand, from members that already exist. It creates nothing —
     * no member is inserted, and display_name is never touched, so "Amy & Carl"
     * still reads "Amy & Carl" after both spouses are linked.
     *
     * Slot 2 is refused on a row that is not a couple: there is no second person
     * in "Alejandro Abad" to link, and allowing it would put a member on a row
     * whose text does not name them.
     *
     * @param int $slot 1 or 2.
     * @return array{ok:bool,msg:string}
     */
    public function linkVgMember(int $vgMemberId, int $slot, int $memberId): array {
        if ($slot !== 1 && $slot !== 2) return ['ok' => false, 'msg' => 'Unknown link slot.'];
        try {
            $stmt = $this->db->prepare(
                "SELECT id, is_couple, member_id, member_id_2 FROM vg_members WHERE id = ?"
            );
            $stmt->execute([$vgMemberId]);
            $row = $stmt->fetch();
            if (!$row) return ['ok' => false, 'msg' => 'That name is no longer on this group.'];

            if ($slot === 2 && !(int)$row['is_couple']) {
                return ['ok' => false, 'msg' => 'This name is only one person, so it has a single link.'];
            }

            $stmt = $this->db->prepare("SELECT full_name FROM members WHERE id = ? AND is_deleted = 0");
            $stmt->execute([$memberId]);
            $fullName = $stmt->fetchColumn();
            if ($fullName === false) return ['ok' => false, 'msg' => 'That member could not be found.'];

            // The same member in both slots of one couple would double-count them.
            $other = $slot === 1 ? $row['member_id_2'] : $row['member_id'];
            if ($other !== null && (int)$other === $memberId) {
                // Plain text: every display site escapes msg itself (escaping here too
                // showed "O&amp;apos;Brien" on screen).
                return ['ok' => false, 'msg' => $fullName . ' is already linked to the other half of this name.'];
            }

            $col  = $slot === 1 ? 'member_id'   : 'member_id_2';
            $stat = $slot === 1 ? 'link_status' : 'link_status_2';
            $this->db->prepare("UPDATE vg_members SET `$col` = ?, `$stat` = 'linked' WHERE id = ?")
                     ->execute([$memberId, $vgMemberId]);
            return ['ok' => true, 'msg' => 'Linked to ' . $fullName . '.'];
        } catch (PDOException $e) {
            error_log("VictoryGroup::linkVgMember error: " . $e->getMessage());
            return ['ok' => false, 'msg' => 'The link could not be saved.'];
        }
    }

    /**
     * Clear one slot, returning it to Needs Link.
     *
     * The verbatim name stays exactly where it is — unlinking a spouse removes
     * the pointer, never the person's name from the group.
     */
    public function unlinkVgMember(int $vgMemberId, int $slot): array {
        if ($slot !== 1 && $slot !== 2) return ['ok' => false, 'msg' => 'Unknown link slot.'];
        try {
            $col  = $slot === 1 ? 'member_id'   : 'member_id_2';
            $stat = $slot === 1 ? 'link_status' : 'link_status_2';
            $stmt = $this->db->prepare(
                "UPDATE vg_members SET `$col` = NULL, `$stat` = 'needs_link' WHERE id = ?"
            );
            $stmt->execute([$vgMemberId]);
            if ($stmt->rowCount() === 0) return ['ok' => false, 'msg' => 'Nothing to unlink.'];
            return ['ok' => true, 'msg' => 'Unlinked. The name is back on Needs Link.'];
        } catch (PDOException $e) {
            error_log("VictoryGroup::unlinkVgMember error: " . $e->getMessage());
            return ['ok' => false, 'msg' => 'The link could not be removed.'];
        }
    }

    /** The group a vg_members row belongs to, so a link action can redirect back to its tab. */
    public function getGroupKindForVgMember(int $vgMemberId): string {
        try {
            $stmt = $this->db->prepare(
                "SELECT vg.group_kind FROM vg_members vm
                 JOIN victory_groups vg ON vg.id = vm.group_id WHERE vm.id = ?"
            );
            $stmt->execute([$vgMemberId]);
            return (string)($stmt->fetchColumn() ?: '');
        } catch (PDOException $e) {
            return '';
        }
    }

    // ── Needs Link work list ──────────────────────────────────────────────────

    /**
     * SQL test for "this slot still needs an admin decision".
     *
     * A slot is RESOLVED when it is linked to a member that still exists, or when
     * an admin marked it not_a_member. Everything else is unresolved — including a
     * row still flagged 'linked' whose member_id was emptied by the
     * ON DELETE SET NULL foreign key when that member was hard-deleted.
     * Slot 2 only exists on couple rows: link_status_2 defaults to 'needs_link' on
     * EVERY row, so without the is_couple guard each single name would count twice.
     */
    private const SLOT1_OPEN = "(vm.link_status <> 'not_a_member' AND NOT (vm.link_status = 'linked' AND vm.member_id IS NOT NULL))";
    private const SLOT2_OPEN = "(vm.is_couple = 1 AND vm.link_status_2 <> 'not_a_member' AND NOT (vm.link_status_2 = 'linked' AND vm.member_id_2 IS NOT NULL))";

    /**
     * Unresolved person slots across all non-deleted groups — one COUNT query,
     * cheap enough for the sidebar badge on every page.
     */
    public function countUnresolvedSlots(): int {
        try {
            return (int)$this->db->query(
                "SELECT COALESCE(SUM(" . self::SLOT1_OPEN . ") + SUM(" . self::SLOT2_OPEN . "), 0)
                   FROM vg_members vm
                   JOIN victory_groups vg ON vg.id = vm.group_id
                  WHERE vg.is_deleted = 0"
            )->fetchColumn();
        } catch (PDOException $e) {
            error_log("VictoryGroup::countUnresolvedSlots error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Progress over every person slot (a single name = 1 slot, a couple = 2):
     * linked + not_a_member + to_go always equals total.
     *
     * @return array{total:int,linked:int,not_a_member:int,to_go:int}
     */
    public function getLinkProgress(): array {
        $out = ['total' => 0, 'linked' => 0, 'not_a_member' => 0, 'to_go' => 0];
        try {
            $r = $this->db->query(
                "SELECT COUNT(*) + SUM(vm.is_couple = 1) AS total,
                        SUM(vm.link_status = 'linked' AND vm.member_id IS NOT NULL)
                          + SUM(vm.is_couple = 1 AND vm.link_status_2 = 'linked' AND vm.member_id_2 IS NOT NULL) AS linked,
                        SUM(vm.link_status = 'not_a_member')
                          + SUM(vm.is_couple = 1 AND vm.link_status_2 = 'not_a_member') AS nam,
                        SUM(" . self::SLOT1_OPEN . ") + SUM(" . self::SLOT2_OPEN . ") AS to_go
                   FROM vg_members vm
                   JOIN victory_groups vg ON vg.id = vm.group_id
                  WHERE vg.is_deleted = 0"
            )->fetch();
            if ($r) {
                $out = ['total' => (int)$r['total'], 'linked' => (int)$r['linked'],
                        'not_a_member' => (int)$r['nam'], 'to_go' => (int)$r['to_go']];
            }
        } catch (PDOException $e) {
            error_log("VictoryGroup::getLinkProgress error: " . $e->getMessage());
        }
        return $out;
    }

    private function needsLinkSelect(): string {
        return "SELECT vm.id AS vg_member_id, vm.group_id, vm.role, vm.is_couple,
                       COALESCE(NULLIF(vm.display_name, ''), vm.name) AS written_name,
                       vm.member_id, vm.link_status, vm.member_id_2, vm.link_status_2,
                       m.full_name AS member_name, m2.full_name AS member2_name,
                       vg.group_kind, vg.name AS group_name, vg.group_type, vg.day_of_week,
                       vg.meeting_time, vg.location, vg.group_status
                  FROM vg_members vm
                  JOIN victory_groups vg ON vg.id = vm.group_id AND vg.is_deleted = 0
                  LEFT JOIN members m  ON m.id  = vm.member_id
                  LEFT JOIN members m2 ON m2.id = vm.member_id_2";
    }

    /** Every vg_members row with at least one unresolved slot, in schedule order. */
    public function getUnresolvedRows(): array {
        try {
            $rows = $this->db->query(
                $this->needsLinkSelect() . "
                 WHERE " . self::SLOT1_OPEN . " OR " . self::SLOT2_OPEN . "
                 ORDER BY FIELD(vg.group_kind, 'victory_group', 'leadership_group', 'campus'),
                          CASE SUBSTRING_INDEX(vg.day_of_week, ',', 1)
                              WHEN 'Monday' THEN 1 WHEN 'Tuesday' THEN 2 WHEN 'Wednesday' THEN 3
                              WHEN 'Thursday' THEN 4 WHEN 'Friday' THEN 5 WHEN 'Saturday' THEN 6
                              WHEN 'Sunday' THEN 7 ELSE 8 END,
                          vg.meeting_time, vg.id, vm.sort_order, vm.id"
            )->fetchAll();
            return $this->attachGroupLeaders($rows);
        } catch (PDOException $e) {
            error_log("VictoryGroup::getUnresolvedRows error: " . $e->getMessage());
            return [];
        }
    }

    /** One row in the same shape, resolved or not — used to redraw a row after an action. */
    public function getNeedsLinkRow(int $vgMemberId) {
        try {
            $stmt = $this->db->prepare($this->needsLinkSelect() . " WHERE vm.id = ?");
            $stmt->execute([$vgMemberId]);
            $row = $stmt->fetch();
            return $row ? $this->attachGroupLeaders([$row])[0] : false;
        } catch (PDOException $e) {
            error_log("VictoryGroup::getNeedsLinkRow error: " . $e->getMessage());
            return false;
        }
    }

    /** Adds group_leaders (verbatim names, comma-joined) and a normalised role_key. */
    private function attachGroupLeaders(array $rows): array {
        if (!$rows) return $rows;
        $gids = array_values(array_unique(array_map('intval', array_column($rows, 'group_id'))));
        $leaders = [];
        try {
            $ph = implode(',', array_fill(0, count($gids), '?'));
            $stmt = $this->db->prepare(
                "SELECT group_id,
                        GROUP_CONCAT(COALESCE(NULLIF(display_name, ''), name) ORDER BY sort_order, id SEPARATOR ', ') AS names
                   FROM vg_members
                  WHERE role IN ('leader', 'lg_leader') AND group_id IN ($ph)
                  GROUP BY group_id"
            );
            $stmt->execute($gids);
            foreach ($stmt->fetchAll() as $l) $leaders[(int)$l['group_id']] = $l['names'];
        } catch (PDOException $e) {
            error_log("VictoryGroup::attachGroupLeaders error: " . $e->getMessage());
        }
        foreach ($rows as &$r) {
            $r['group_leaders'] = $leaders[(int)$r['group_id']] ?? '';
            // Same legacy rule as attachMembers(): a plain 'leader' in a Leadership
            // Group is that group's LG Leader.
            $r['role_key'] = ($r['group_kind'] === 'leadership_group' && $r['role'] === 'leader')
                ? 'lg_leader' : $r['role'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Mark one slot "Not a church member" — a person on the schedule who will
     * never have a member record (a guest, a visiting pastor). Resolved without a
     * link: excluded from every people count (those require 'linked') and off the
     * Needs Link list. Reversible: unlinkVgMember() puts it back on Needs Link.
     */
    public function markVgMemberNotAMember(int $vgMemberId, int $slot): array {
        if ($slot !== 1 && $slot !== 2) return ['ok' => false, 'msg' => 'Unknown link slot.'];
        try {
            $stmt = $this->db->prepare("SELECT is_couple FROM vg_members WHERE id = ?");
            $stmt->execute([$vgMemberId]);
            $isCouple = $stmt->fetchColumn();
            if ($isCouple === false) return ['ok' => false, 'msg' => 'That name is no longer on this group.'];
            if ($slot === 2 && !(int)$isCouple) {
                return ['ok' => false, 'msg' => 'This name is only one person, so it has a single link.'];
            }
            $col  = $slot === 1 ? 'member_id'   : 'member_id_2';
            $stat = $slot === 1 ? 'link_status' : 'link_status_2';
            $this->db->prepare("UPDATE vg_members SET `$col` = NULL, `$stat` = 'not_a_member' WHERE id = ?")
                     ->execute([$vgMemberId]);
            return ['ok' => true, 'msg' => 'Marked as not a church member.'];
        } catch (PDOException $e) {
            error_log("VictoryGroup::markVgMemberNotAMember error: " . $e->getMessage());
            return ['ok' => false, 'msg' => 'The change could not be saved.'];
        }
    }

    /** Active, non-deleted members for the suggestion matcher. */
    public function getMembersForMatching(): array {
        try {
            return $this->db->query(
                "SELECT id, full_name, first_name, last_name FROM members
                  WHERE is_deleted = 0 AND member_status = 'active'"
            )->fetchAll();
        } catch (PDOException $e) {
            error_log("VictoryGroup::getMembersForMatching error: " . $e->getMessage());
            return [];
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

    /**
     * Group counts are simple row counts (by group_kind). Person counts (leaders/interns/
     * lg_leaders) are COUNT(DISTINCT member_id) restricted to link_status='linked' — so one
     * person leading 3 Victory Groups counts once, and needs-link rows are excluded entirely.
     * Campus is its own group_kind and is NEVER folded into the victory_group counts.
     *
     * COUPLES COUNT AS THE PEOPLE ACTUALLY LINKED, NOT AS ONE ROW.
     * A couple row ("Amy & Carl") is one vg_members row holding up to two member links.
     * The query below UNIONs the two link slots into one "(group_kind, role, person)"
     * stream before counting, so a couple with both spouses linked contributes 2, with one
     * spouse linked contributes 1, and with neither contributes 0 — and a spouse who is
     * also listed individually on another card still counts once overall, because the
     * DISTINCT spans the whole union, not each slot separately.
     */
    public function getStats() {
        $stats = [
            'total' => 0, 'active' => 0,
            'vg' => 0, 'vg_active' => 0, 'vg_leaders' => 0, 'vg_interns' => 0,
            'lg' => 0, 'lg_active' => 0, 'lg_leaders' => 0,
            'campus' => 0, 'campus_active' => 0, 'campus_leaders' => 0, 'campus_interns' => 0,
        ];
        try {
            $stats['total']  = (int)$this->db->query("SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0")->fetchColumn();
            $stats['active'] = (int)$this->db->query("SELECT COUNT(*) FROM victory_groups WHERE is_deleted = 0 AND group_status = 'active'")->fetchColumn();

            // Group counts per kind (row counts, not person counts).
            $kindRows = $this->db->query(
                "SELECT group_kind,
                        COUNT(*) AS total,
                        SUM(group_status = 'active') AS active
                 FROM victory_groups
                 WHERE is_deleted = 0
                 GROUP BY group_kind"
            )->fetchAll();
            foreach ($kindRows as $row) {
                $prefix = $row['group_kind'] === 'leadership_group' ? 'lg' : ($row['group_kind'] === 'campus' ? 'campus' : 'vg');
                $stats[$prefix]            = (int)$row['total'];
                $stats[$prefix . '_active'] = (int)$row['active'];
            }

            // Distinct linked people per (group_kind, role), counting only active groups.
            // A plain 'leader' inside a Leadership Group is a legacy pre-'lg_leader' row —
            // normalised here so it lands in lg_leaders, matching what the LG tab shows.
            $personRows = $this->db->query(
                "SELECT group_kind, role, COUNT(DISTINCT member_id) AS cnt
                 FROM (
                     SELECT vg.group_kind,
                            IF(vg.group_kind = 'leadership_group' AND vm.role = 'leader', 'lg_leader', vm.role) AS role,
                            vm.member_id
                     FROM vg_members vm
                     JOIN victory_groups vg ON vg.id = vm.group_id
                     WHERE vg.is_deleted = 0 AND vg.group_status = 'active'
                       AND vm.link_status = 'linked' AND vm.member_id IS NOT NULL
                     UNION ALL
                     SELECT vg.group_kind,
                            IF(vg.group_kind = 'leadership_group' AND vm.role = 'leader', 'lg_leader', vm.role) AS role,
                            vm.member_id_2 AS member_id
                     FROM vg_members vm
                     JOIN victory_groups vg ON vg.id = vm.group_id
                     WHERE vg.is_deleted = 0 AND vg.group_status = 'active'
                       AND vm.link_status_2 = 'linked' AND vm.member_id_2 IS NOT NULL
                 ) AS linked_people
                 GROUP BY group_kind, role"
            )->fetchAll();
            foreach ($personRows as $row) {
                $kind = $row['group_kind'];
                $cnt  = (int)$row['cnt'];
                if ($kind === 'victory_group' && $row['role'] === 'leader') $stats['vg_leaders'] = $cnt;
                if ($kind === 'victory_group' && $row['role'] === 'intern') $stats['vg_interns'] = $cnt;
                if ($kind === 'leadership_group' && $row['role'] === 'lg_leader') $stats['lg_leaders'] = $cnt;
                if ($kind === 'campus' && $row['role'] === 'leader') $stats['campus_leaders'] = $cnt;
                if ($kind === 'campus' && $row['role'] === 'intern') $stats['campus_interns'] = $cnt;
            }

            return $stats;
        } catch (PDOException $e) {
            error_log("VictoryGroup::getStats error: " . $e->getMessage());
            return $stats;
        }
    }

    public function getDistinctMemberNames($role) {
        try {
            // CRITICAL: use the SAME display-name expression as attachMembers() —
            // COALESCE(NULLIF(vm.display_name,''), m.full_name, vm.name). Otherwise the dropdown
            // shows a name that never matches what the row's data-leaders attribute carries, and
            // the filter never matches anything.
            // Legacy Leadership Groups store their leaders as role 'leader'; attachMembers()
            // re-buckets those into lg_leaders, so this dropdown has to do the same or the
            // LG Leader filter would be empty while the rows show names.
            // A couple contributes ONE option — its verbatim string, "Amy & Carl" — because
            // that is the single name the table cell and its data-leaders attribute carry,
            // whether zero, one or both spouses are linked. Offering the two spouses'
            // member names separately would list choices that match no row. This is a
            // name filter, not a people count; getStats() is what counts people.
            $stmt = $this->db->prepare(
                "SELECT DISTINCT TRIM(COALESCE(NULLIF(vm.display_name, ''), m.full_name, vm.name)) AS name
                 FROM vg_members vm
                 JOIN victory_groups vg ON vg.id = vm.group_id
                 LEFT JOIN members m ON m.id = vm.member_id
                 WHERE IF(vg.group_kind = 'leadership_group' AND vm.role = 'leader', 'lg_leader', vm.role) = ?
                   AND TRIM(COALESCE(NULLIF(vm.display_name, ''), m.full_name, vm.name)) != ''
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
