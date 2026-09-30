<?php
/**
 * Serve Teams.
 *
 * Reuses the shape that already works for Victory Groups: a team header row
 * (serve_teams) plus a membership junction (serve_team_members) whose rows link
 * to members.id when the person is a registered member and otherwise keep the
 * typed name. Ministry / service values are plain strings matching
 * ministries.name and services.name, exactly like members.ministry does today.
 */
class ServeTeam {
    private $db;

    const ROLES = ['leader' => 'Leader', 'member' => 'Member'];

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll(array $filters = []): array {
        try {
            $sql    = "SELECT * FROM serve_teams WHERE is_deleted = 0";
            $params = [];
            if (!empty($filters['ministry']))    { $sql .= " AND ministry = ?";     $params[] = $filters['ministry']; }
            if (!empty($filters['team_status'])) { $sql .= " AND team_status = ?";  $params[] = $filters['team_status']; }
            if (!empty($filters['service_name'])){ $sql .= " AND service_name = ?"; $params[] = $filters['service_name']; }
            $sql .= " ORDER BY sort_order ASC, name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $teams = $stmt->fetchAll();
            return $teams ? $this->attachMembers($teams) : [];
        } catch (PDOException $e) {
            error_log("ServeTeam::getAll error: " . $e->getMessage());
            return [];
        }
    }

    public function getById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM serve_teams WHERE id = ? AND is_deleted = 0");
            $stmt->execute([(int)$id]);
            $team = $stmt->fetch();
            return $team ? $this->attachMembers([$team])[0] : false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Attaches ['leaders' => [...], 'members' => [...]] to each team in one query. */
    private function attachMembers(array $teams): array {
        $ids = array_column($teams, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT stm.team_id, stm.role, stm.member_id,
                    COALESCE(m.full_name, stm.name) AS name,
                    m.ministry AS member_ministry
               FROM serve_team_members stm
               LEFT JOIN members m ON m.id = stm.member_id
              WHERE stm.team_id IN ($ph)
              ORDER BY FIELD(stm.role,'leader','member'), stm.sort_order ASC, stm.id ASC"
        );
        $stmt->execute($ids);
        $byTeam = [];
        foreach ($stmt->fetchAll() as $row) {
            $byTeam[$row['team_id']][$row['role']][] = $row;
        }
        foreach ($teams as &$t) {
            $t['leaders'] = $byTeam[$t['id']]['leader'] ?? [];
            $t['members'] = $byTeam[$t['id']]['member'] ?? [];
        }
        unset($t);
        return $teams;
    }

    /**
     * Validates the required fields. Everything a team needs to be actionable
     * is mandatory: without a service, day, time or place nobody knows when or
     * where to show up, and without a leader and at least one member there is
     * no team. Returns null when the data is usable.
     */
    public function validate(array $data, array $leaders, array $members): ?string {
        if (trim($data['name'] ?? '') === '')          return 'Team Name is required.';
        if (trim($data['ministry'] ?? '') === '')      return 'Ministry is required.';
        if (trim($data['service_name'] ?? '') === '')  return 'Service is required.';
        if ($this->normalizeDays($data['day_of_week'] ?? '') === '') return 'Day is required.';
        if (trim($data['meetup_time'] ?? '') === '')   return 'Call Time is required.';
        if (trim($data['meeting_place'] ?? '') === '') return 'Place is required.';
        if (empty($leaders))                           return 'At least one Team Leader is required.';
        if (empty($members))                           return 'At least one Team Member is required.';
        return null;
    }

    public function add(array $data, array $leaders, array $members) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO serve_teams
                    (uuid, name, ministry, service_name, service_time, day_of_week, meetup_time,
                     meeting_place, team_status, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $this->generateUUID(),
                trim($data['name'] ?? ''),
                trim($data['ministry'] ?? ''),
                trim($data['service_name'] ?? ''),
                trim($data['service_time'] ?? ''),
                $this->normalizeDays($data['day_of_week'] ?? ''),
                ($data['meetup_time'] ?? '') ?: null,
                trim($data['meeting_place'] ?? ''),
                ($data['team_status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                trim($data['notes'] ?? ''),
            ]);
            $teamId = (int)$this->db->lastInsertId();
            $this->syncMembers($teamId, $leaders, $members);
            return $teamId;
        } catch (PDOException $e) {
            error_log("ServeTeam::add error: " . $e->getMessage());
            return false;
        }
    }

    public function update($id, array $data, array $leaders, array $members): bool {
        try {
            $stmt = $this->db->prepare("
                UPDATE serve_teams
                   SET name = ?, ministry = ?, service_name = ?, service_time = ?, day_of_week = ?,
                       meetup_time = ?, meeting_place = ?, team_status = ?, notes = ?
                 WHERE id = ?
            ");
            $stmt->execute([
                trim($data['name'] ?? ''),
                trim($data['ministry'] ?? ''),
                trim($data['service_name'] ?? ''),
                trim($data['service_time'] ?? ''),
                $this->normalizeDays($data['day_of_week'] ?? ''),
                ($data['meetup_time'] ?? '') ?: null,
                trim($data['meeting_place'] ?? ''),
                ($data['team_status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                trim($data['notes'] ?? ''),
                (int)$id,
            ]);
            $this->syncMembers((int)$id, $leaders, $members);
            return true;
        } catch (PDOException $e) {
            error_log("ServeTeam::update error: " . $e->getMessage());
            return false;
        }
    }

    public function setStatus($id, string $status): bool {
        try {
            $this->db->prepare("UPDATE serve_teams SET team_status = ? WHERE id = ?")
                ->execute([$status === 'inactive' ? 'inactive' : 'active', (int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("ServeTeam::setStatus error: " . $e->getMessage());
            return false;
        }
    }

    /** Soft delete — the row and its membership history stay in the DB. */
    public function delete($id): bool {
        try {
            $this->db->prepare("UPDATE serve_teams SET is_deleted = 1, team_status = 'inactive' WHERE id = ?")
                ->execute([(int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("ServeTeam::delete error: " . $e->getMessage());
            return false;
        }
    }

    public function getStats(): array {
        try {
            return [
                'total'    => (int)$this->db->query("SELECT COUNT(*) FROM serve_teams WHERE is_deleted = 0")->fetchColumn(),
                'active'   => (int)$this->db->query("SELECT COUNT(*) FROM serve_teams WHERE is_deleted = 0 AND team_status = 'active'")->fetchColumn(),
                'leaders'  => (int)$this->db->query(
                    "SELECT COUNT(*) FROM serve_team_members stm
                       JOIN serve_teams st ON st.id = stm.team_id
                      WHERE st.is_deleted = 0 AND stm.role = 'leader'")->fetchColumn(),
                'servers'  => (int)$this->db->query(
                    "SELECT COUNT(DISTINCT COALESCE(CONCAT('m', stm.member_id), CONCAT('n', LOWER(stm.name))))
                       FROM serve_team_members stm
                       JOIN serve_teams st ON st.id = stm.team_id
                      WHERE st.is_deleted = 0")->fetchColumn(),
            ];
        } catch (PDOException $e) {
            return ['total' => 0, 'active' => 0, 'leaders' => 0, 'servers' => 0];
        }
    }

    /** Teams a given member serves on — used by the member profile page. */
    public function getByMember(int $memberId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT st.id, st.name, st.ministry, st.service_name, st.service_time, st.team_status, stm.role
                   FROM serve_team_members stm
                   JOIN serve_teams st ON st.id = stm.team_id
                  WHERE stm.member_id = ? AND st.is_deleted = 0
                  ORDER BY FIELD(stm.role,'leader','member'), st.name"
            );
            $stmt->execute([$memberId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getDistinctMeetingPlaces(): array {
        try {
            return $this->db->query(
                "SELECT DISTINCT TRIM(meeting_place) AS p FROM serve_teams
                  WHERE TRIM(meeting_place) != '' AND is_deleted = 0 ORDER BY p"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /** Replaces the whole membership list for a team (same approach as VictoryGroup). */
    private function syncMembers(int $teamId, array $leaders, array $members): void {
        $this->db->prepare("DELETE FROM serve_team_members WHERE team_id = ?")->execute([$teamId]);
        $ins = $this->db->prepare(
            "INSERT INTO serve_team_members (team_id, member_id, name, role, sort_order) VALUES (?,?,?,?,?)"
        );
        foreach (['leader' => $leaders, 'member' => $members] as $role => $people) {
            foreach (array_values($people) as $i => $p) {
                $name = trim(is_array($p) ? ($p['name'] ?? '') : (string)$p);
                if ($name === '') continue;
                $memberId = (is_array($p) && !empty($p['member_id'])) ? (int)$p['member_id'] : $this->findMemberIdByName($name);
                $ins->execute([$teamId, $memberId ?: null, $name, $role, $i]);
            }
        }
    }

    private function findMemberIdByName(string $name) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM members WHERE LOWER(TRIM(full_name)) = LOWER(TRIM(?)) LIMIT 1");
            $stmt->execute([$name]);
            return $stmt->fetchColumn() ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    private function normalizeDays($days): string {
        if (is_array($days)) return implode(', ', array_filter(array_map('trim', $days), 'strlen'));
        return trim((string)$days);
    }

    private function generateUUID(): string {
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
