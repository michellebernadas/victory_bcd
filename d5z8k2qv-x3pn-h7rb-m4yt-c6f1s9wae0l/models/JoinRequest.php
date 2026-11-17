<?php
/**
 * Join requests submitted from the public site (join.php in the site root).
 * Personal data: reachable only through the admin screen gated by the
 * join_requests permission.
 */
class JoinRequest {
    private $db;

    const STATUSES = [
        'new'       => ['label' => 'New',       'badge' => 'danger'],
        'contacted' => ['label' => 'Contacted', 'badge' => 'primary'],
        'placed'    => ['label' => 'Placed in a group', 'badge' => 'success'],
        'closed'    => ['label' => 'Closed',    'badge' => 'secondary'],
    ];

    public function __construct($db) { $this->db = $db; }

    /** Requests, newest first, with the group they asked about (kind, day, leaders). */
    public function getAll($status = '', $search = '') {
        try {
            $sql = "SELECT jr.*, vg.group_kind, vg.group_type, vg.day_of_week, vg.meeting_time, vg.location AS group_location,
                           (SELECT GROUP_CONCAT(COALESCE(NULLIF(vm.display_name, ''), vm.name) ORDER BY vm.sort_order, vm.id SEPARATOR ', ')
                              FROM vg_members vm WHERE vm.group_id = vg.id AND vm.role = 'leader') AS group_leaders,
                           au.username AS handled_by_name
                      FROM join_requests jr
                      LEFT JOIN victory_groups vg ON vg.id = jr.group_id
                      LEFT JOIN accounts au ON au.id = jr.handled_by
                     WHERE 1 = 1";
            $params = [];
            if ($status !== '' && isset(self::STATUSES[$status])) { $sql .= " AND jr.status = ?"; $params[] = $status; }
            if ($search !== '') {
                $sql .= " AND (jr.full_name LIKE ? OR jr.email LIKE ? OR jr.phone LIKE ? OR jr.area LIKE ?)";
                $like = '%' . $search . '%';
                array_push($params, $like, $like, $like, $like);
            }
            $sql .= " ORDER BY FIELD(jr.status, 'new', 'contacted', 'placed', 'closed'), jr.created_at DESC LIMIT 500";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $this->attachNotices($stmt->fetchAll() ?: []);
        } catch (PDOException $e) {
            error_log('JoinRequest::getAll ' . $e->getMessage());
            return [];
        }
    }

    /** Adds 'notices' => ['leader' => row|null, 'staff' => row|null] (the most recent attempt of each kind). */
    private function attachNotices(array $rows) {
        if (!$rows) return $rows;
        $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
        $map = [];
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $this->db->prepare("SELECT * FROM join_request_notices WHERE request_id IN ($ph) ORDER BY id ASC");
            $st->execute($ids);
            foreach ($st->fetchAll() as $n) $map[(int)$n['request_id']][$n['kind']] = $n;   // later rows overwrite earlier: latest wins
        } catch (PDOException $e) { error_log('JoinRequest::attachNotices ' . $e->getMessage()); }
        foreach ($rows as &$r) $r['notices'] = $map[(int)$r['id']] ?? [];
        unset($r);
        return $rows;
    }

    /** status => count, for the filter tabs. */
    public function counts() {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        try {
            foreach ($this->db->query("SELECT status, COUNT(*) c FROM join_requests GROUP BY status")->fetchAll() as $r) $out[$r['status']] = (int)$r['c'];
        } catch (PDOException $e) { error_log('JoinRequest::counts ' . $e->getMessage()); }
        return $out;
    }

    /** New requests, for the sidebar badge. 0 when the table does not exist yet. */
    public function countNew() {
        try { return (int)$this->db->query("SELECT COUNT(*) FROM join_requests WHERE status = 'new'")->fetchColumn(); }
        catch (PDOException $e) { return 0; }
    }

    public function update($id, $status, $notes, $userId) {
        if (!isset(self::STATUSES[$status])) return false;
        try {
            $notes = trim((string)$notes);
            $this->db->prepare("UPDATE join_requests SET status = ?, staff_notes = ?, handled_by = ? WHERE id = ?")
                     ->execute([$status, $notes !== '' ? mb_substr($notes, 0, 1000) : null, $userId ?: null, (int)$id]);
            return true;
        } catch (PDOException $e) { error_log('JoinRequest::update ' . $e->getMessage()); return false; }
    }

    public function delete($id) {
        try { $this->db->prepare("DELETE FROM join_requests WHERE id = ?")->execute([(int)$id]); return true; }
        catch (PDOException $e) { error_log('JoinRequest::delete ' . $e->getMessage()); return false; }
    }
}
