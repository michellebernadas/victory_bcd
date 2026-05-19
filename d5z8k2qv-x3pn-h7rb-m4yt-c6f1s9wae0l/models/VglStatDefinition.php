<?php
class VglStatDefinition {
    private $db;

    // Human-readable section labels and display order
    const SECTIONS = [
        'overview'       => 'Grand Totals',
        'campus_vg'      => 'Campus VGs',
        'campus_vgl'     => 'Campus VG Leaders',
        'campus_intern'  => 'Campus VG Interns',
        'men_vg'         => 'Men VGs',
        'men_vgl'        => 'Men VG Leaders',
        'men_intern'     => 'Men VG Interns',
        'women_vg'       => 'Women VGs',
        'women_vgl'      => 'Women VG Leaders',
        'women_intern'   => 'Women VG Interns',
        'couples_vg'          => 'Couples VGs',
        'couples_vgl'         => 'Couples VG Leaders',
        'couples_vgl_men'     => 'Couples VG Men Leaders',
        'couples_vgl_women'   => 'Couples VG Women Leaders',
        'couples_intern'      => 'Couples VG Interns',
        'couples_intern_men'  => 'Couples VG Men Interns',
        'couples_intern_women'=> 'Couples VG Women Interns',
        'lg'             => 'Leadership Groups (LG)',
        'general_vg'     => 'General VGs',
        'general_vgl'    => 'General VG Leaders',
        'general_intern' => 'General Interns',
    ];

    public function __construct($db) {
        $this->db = $db;
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    public function getAll() {
        try {
            return $this->db->query(
                "SELECT * FROM vgl_stat_definitions ORDER BY section, sort_order, id"
            )->fetchAll();
        } catch (PDOException $e) {
            error_log("VglStatDefinition::getAll error: " . $e->getMessage());
            return [];
        }
    }

    public function getDistinctLabels() {
        try {
            return $this->db->query(
                "SELECT DISTINCT label FROM vgl_stat_definitions ORDER BY label"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM vgl_stat_definitions WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("VglStatDefinition::getById error: " . $e->getMessage());
            return false;
        }
    }

    public function add($data) {
        try {
            $maxOrder = (int)$this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM vgl_stat_definitions")->fetchColumn();
            $stmt = $this->db->prepare("
                INSERT INTO vgl_stat_definitions (label, section, count_source, filter_value, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                trim($data['label']),
                trim($data['section']),
                $data['count_source'],
                trim($data['filter_value']),
                $maxOrder + 1,
                ($data['is_active'] ?? 0) ? 1 : 0,
            ]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("VglStatDefinition::add error: " . $e->getMessage());
            return false;
        }
    }

    public function update($id, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE vgl_stat_definitions
                SET label=?, section=?, count_source=?, filter_value=?, sort_order=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([
                trim($data['label']),
                trim($data['section']),
                $data['count_source'],
                trim($data['filter_value']),
                (int)($data['sort_order'] ?? 0),
                ($data['is_active'] ?? 0) ? 1 : 0,
                $id,
            ]);
            return true;
        } catch (PDOException $e) {
            error_log("VglStatDefinition::update error: " . $e->getMessage());
            return false;
        }
    }

    public function updateOrder($ids) {
        try {
            $stmt = $this->db->prepare("UPDATE vgl_stat_definitions SET sort_order = ? WHERE id = ?");
            foreach ($ids as $i => $id) {
                $stmt->execute([$i + 1, (int)$id]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("VglStatDefinition::updateOrder error: " . $e->getMessage());
            return false;
        }
    }

    public function delete($id) {
        try {
            $this->db->prepare("DELETE FROM vgl_stat_definitions WHERE id = ?")->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("VglStatDefinition::delete error: " . $e->getMessage());
            return false;
        }
    }

    public function setActive($id, $active) {
        try {
            $this->db->prepare("UPDATE vgl_stat_definitions SET is_active = ? WHERE id = ?")
                ->execute([$active ? 1 : 0, $id]);
            return true;
        } catch (PDOException $e) {
            error_log("VglStatDefinition::setActive error: " . $e->getMessage());
            return false;
        }
    }

    // ── Statistics computation ────────────────────────────────────────────────

    /**
     * Returns definitions grouped by section, each with a computed `count`.
     * Also appends a synthetic "total" row per section.
     */
    public function getStatsGrouped() {
        $defs = $this->getAll();
        if (empty($defs)) return [];

        // Pre-compute counts for all defs in 3 bulk queries
        $groupTypeCounts  = $this->bulkCountByGroupType();
        $leaderCounts     = $this->bulkCountByMemberGender('leader');
        $internCounts     = $this->bulkCountByMemberGender('intern');

        $grouped = [];
        foreach ($defs as $d) {
            if (!$d['is_active']) continue;

            $values = array_map(function($v){ return strtolower(trim($v)); }, explode(',', $d['filter_value']));
            $count = 0;
            if ($d['count_source'] === 'victory_groups') {
                foreach ($values as $v) $count += $groupTypeCounts[$v] ?? 0;
            } elseif ($d['count_source'] === 'leaders') {
                foreach ($values as $v) $count += $leaderCounts[$v] ?? 0;
            } elseif ($d['count_source'] === 'interns') {
                foreach ($values as $v) $count += $internCounts[$v] ?? 0;
            }

            $d['count'] = $count;
            $grouped[$d['section']][] = $d;
        }

        // Append section totals
        foreach ($grouped as $section => &$rows) {
            $total = array_sum(array_column($rows, 'count'));
            $rows[] = [
                'id'           => null,
                'label'        => 'Total ' . (self::SECTIONS[$section] ?? ucfirst($section)),
                'section'      => $section,
                'count_source' => null,
                'filter_value' => null,
                'sort_order'   => 9999,
                'is_active'    => 1,
                'count'        => $total,
                'is_total_row' => true,
            ];
        }

        // Sort sections in defined order
        $sectionOrder = array_keys(self::SECTIONS);
        uksort($grouped, function($a, $b) use ($sectionOrder) {
            $ai = array_search($a, $sectionOrder);
            $bi = array_search($b, $sectionOrder);
            return ($ai === false ? 999 : $ai) - ($bi === false ? 999 : $bi);
        });

        return $grouped;
    }

    // Fetch count of active victory_groups keyed by group_type
    private function bulkCountByGroupType() {
        try {
            $rows = $this->db->query(
                "SELECT group_type, COUNT(*) AS cnt
                 FROM victory_groups
                 WHERE group_status = 'active' AND is_deleted = 0
                 GROUP BY group_type"
            )->fetchAll();
            $map = [];
            foreach ($rows as $r) $map[strtolower($r['group_type'])] = (int)$r['cnt'];
            return $map;
        } catch (PDOException $e) {
            return [];
        }
    }

    // Fetch count of vg_members keyed by gender for a given role
    private function bulkCountByMemberGender($role) {
        try {
            $stmt = $this->db->prepare(
                "SELECT m.gender, COUNT(*) AS cnt
                 FROM vg_members m
                 JOIN victory_groups vg ON vg.id = m.group_id
                 WHERE m.role = ? AND vg.group_status = 'active' AND vg.is_deleted = 0
                 GROUP BY m.gender"
            );
            $stmt->execute([$role]);
            $map = [];
            foreach ($stmt->fetchAll() as $r) $map[strtolower($r['gender'])] = (int)$r['cnt'];
            return $map;
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>
