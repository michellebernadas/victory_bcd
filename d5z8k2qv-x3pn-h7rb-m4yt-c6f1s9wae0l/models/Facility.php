<?php
/**
 * Facility catalogue — the rooms / furniture / equipment / office resources a
 * ministry can request (policy section 2 and section 4).
 *
 * Read-mostly. The rows are seeded by migration 2026_10_20_06_facilities.sql and
 * edited by an admin; nothing in the booking workflow writes to this table.
 *
 * `default_lead_days` is the one column the booking workflow reads for anything
 * other than display: it drives the lead-time WARNING (policy section 10), never
 * a block (policy section 11).
 */
class Facility {
    private $db;

    /**
     * Lead time used when a request names no facility (equipment-only requests,
     * which the church's own live examples are). Policy section 10's baseline
     * for furniture / equipment / office resources.
     */
    const DEFAULT_LEAD_DAYS = 3;

    const TYPES = [
        'room'           => 'Facility / Room',
        'furniture'      => 'Furniture',
        'equipment'      => 'Equipment',
        'office_resource' => 'Office Resource',
    ];

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * @param bool $bookableOnly   Only rows that occupy the reservation calendar.
     * @param bool $includeInactive Include deactivated rows (for edit forms that
     *                              must still render a request's historical facility).
     */
    public function getAllFacilities($bookableOnly = false, $includeInactive = false) {
        try {
            $sql = "SELECT * FROM facilities WHERE is_deleted = 0";
            if (!$includeInactive) $sql .= " AND is_active = 1";
            if ($bookableOnly)     $sql .= " AND is_bookable = 1";
            $sql .= " ORDER BY sort_order ASC, name ASC";
            return $this->db->query($sql)->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Get all facilities error: " . $e->getMessage());
            return [];
        }
    }

    /** Bookable rooms only — what the calendar's facility filter offers. */
    public function getBookableFacilities() {
        return $this->getAllFacilities(true, false);
    }

    public function getFacilityById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM facilities WHERE id = ?");
            $stmt->execute([(int)$id]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Get facility by ID error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Recommended lead time for a facility, falling back to the policy baseline
     * when the request names no facility or the facility has since been deleted.
     */
    public function leadDaysFor($facilityId) {
        $facilityId = (int)$facilityId;
        if ($facilityId <= 0) return self::DEFAULT_LEAD_DAYS;
        try {
            $stmt = $this->db->prepare("SELECT default_lead_days FROM facilities WHERE id = ?");
            $stmt->execute([$facilityId]);
            $days = $stmt->fetchColumn();
            return ($days === false || $days === null) ? self::DEFAULT_LEAD_DAYS : (int)$days;
        } catch (PDOException $e) {
            error_log("leadDaysFor error: " . $e->getMessage());
            return self::DEFAULT_LEAD_DAYS;
        }
    }

    // ── Room management (Facility Booking > Rooms) ───────────────────────────

    /** Every non-deleted room with how many requests point at it, for the Rooms tab. */
    public function getAllWithUsage() {
        try {
            return $this->db->query(
                "SELECT f.*, (SELECT COUNT(*) FROM facility_requests fr
                               WHERE fr.facility_id = f.id AND fr.is_deleted = 0) AS request_count
                   FROM facilities f
                  WHERE f.is_deleted = 0
                  ORDER BY f.sort_order ASC, f.name ASC"
            )->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Facility usage list error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean a posted room form into column values, or return an error string.
     * @return array|string
     */
    public static function cleanInput(array $d) {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') return 'Give the room a name.';
        if (mb_strlen($name) > 150) return 'The room name is too long (150 characters at most).';
        $type = (string)($d['facility_type'] ?? 'room');
        if (!isset(self::TYPES[$type])) $type = 'room';
        $cap  = trim((string)($d['capacity'] ?? ''));
        $lead = trim((string)($d['default_lead_days'] ?? ''));
        $notes = trim((string)($d['notes'] ?? ''));
        return [
            'name'              => $name,
            'facility_type'     => $type,
            'capacity'          => ($cap !== '' && is_numeric($cap) && (int)$cap >= 0) ? (int)$cap : null,
            'default_lead_days' => ($lead !== '' && is_numeric($lead) && (int)$lead >= 0) ? min(365, (int)$lead) : self::DEFAULT_LEAD_DAYS,
            'is_bookable'       => !empty($d['is_bookable']) ? 1 : 0,
            'special_case_only' => !empty($d['special_case_only']) ? 1 : 0,
            'notes'             => $notes !== '' ? mb_substr($notes, 0, 255) : null,
        ];
    }

    /** Is the name taken by another live room? (Case-insensitive.) */
    public function nameTaken($name, $exceptId = 0) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM facilities WHERE is_deleted = 0 AND LOWER(name) = LOWER(?) AND id <> ?");
        $stmt->execute([$name, (int)$exceptId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Add a room. A previously deleted room with the same name is brought back
     * with the new values (the name is UNIQUE, and its old requests still point at it).
     * @return int|false new / revived id
     */
    public function create(array $c) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM facilities WHERE is_deleted = 1 AND LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$c['name']]);
            $dead = $stmt->fetchColumn();
            $max  = (int)$this->db->query("SELECT COALESCE(MAX(sort_order), 0) FROM facilities")->fetchColumn();
            if ($dead) {
                $this->db->prepare(
                    "UPDATE facilities SET name = ?, facility_type = ?, capacity = ?, default_lead_days = ?, is_bookable = ?,
                            special_case_only = ?, notes = ?, sort_order = ?, is_active = 1, is_deleted = 0 WHERE id = ?"
                )->execute([$c['name'], $c['facility_type'], $c['capacity'], $c['default_lead_days'], $c['is_bookable'],
                            $c['special_case_only'], $c['notes'], $max + 1, (int)$dead]);
                return (int)$dead;
            }
            $this->db->prepare(
                "INSERT INTO facilities (name, facility_type, capacity, default_lead_days, is_bookable, special_case_only, notes, sort_order)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([$c['name'], $c['facility_type'], $c['capacity'], $c['default_lead_days'], $c['is_bookable'],
                        $c['special_case_only'], $c['notes'], $max + 1]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Facility create error: " . $e->getMessage());
            return false;
        }
    }

    public function update($id, array $c) {
        try {
            $this->db->prepare(
                "UPDATE facilities SET name = ?, facility_type = ?, capacity = ?, default_lead_days = ?, is_bookable = ?,
                        special_case_only = ?, notes = ? WHERE id = ? AND is_deleted = 0"
            )->execute([$c['name'], $c['facility_type'], $c['capacity'], $c['default_lead_days'], $c['is_bookable'],
                        $c['special_case_only'], $c['notes'], (int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Facility update error: " . $e->getMessage());
            return false;
        }
    }

    /** Hide / show a room in the request form without touching its history. */
    public function setActive($id, $active) {
        try {
            $this->db->prepare("UPDATE facilities SET is_active = ? WHERE id = ? AND is_deleted = 0")
                     ->execute([$active ? 1 : 0, (int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Facility setActive error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a room. Refused while an OPEN request (anything still in play —
     * not cancelled, declined or already past) uses it; otherwise a room with
     * history is soft-deleted so old requests keep their room name, and a room
     * nobody ever used is removed outright.
     * @return true|string  true, or the reason it was refused
     */
    public function delete($id) {
        $id = (int)$id;
        try {
            $open = $this->db->prepare(
                "SELECT COUNT(*) FROM facility_requests
                  WHERE facility_id = ? AND is_deleted = 0
                    AND status NOT IN ('cancelled','declined')
                    AND (date_needed IS NULL OR date_needed >= CURDATE())"
            );
            $open->execute([$id]);
            $n = (int)$open->fetchColumn();
            if ($n > 0) return "$n upcoming request(s) still use this room. Cancel or move them first, or deactivate the room instead.";

            $any = $this->db->prepare("SELECT COUNT(*) FROM facility_requests WHERE facility_id = ?");
            $any->execute([$id]);
            if ((int)$any->fetchColumn() > 0) {
                $this->db->prepare("UPDATE facilities SET is_deleted = 1, is_active = 0 WHERE id = ?")->execute([$id]);
            } else {
                $this->db->prepare("DELETE FROM facilities WHERE id = ?")->execute([$id]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Facility delete error: " . $e->getMessage());
            return 'Could not delete the room.';
        }
    }

    /** Save a new display order from a list of ids (drag-and-drop on the Rooms tab). */
    public function reorder(array $ids) {
        try {
            $stmt = $this->db->prepare("UPDATE facilities SET sort_order = ? WHERE id = ?");
            foreach (array_values($ids) as $i => $id) $stmt->execute([$i + 1, (int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Facility reorder error: " . $e->getMessage());
            return false;
        }
    }

    /** id => name, for cheap lookups in views without a second query per row. */
    public function nameMap() {
        $map = [];
        foreach ($this->getAllFacilities(false, true) as $f) {
            $map[(int)$f['id']] = $f['name'];
        }
        return $map;
    }
}
?>
