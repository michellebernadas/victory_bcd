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
