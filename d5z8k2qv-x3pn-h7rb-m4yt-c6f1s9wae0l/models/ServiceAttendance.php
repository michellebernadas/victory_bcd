<?php
/**
 * Sunday Service Attendance — "Church Health" head counts.
 *
 * One row per (service_date, service_name). Four numbers per service:
 *   Date + Service Time -> Adults + Kids = Grand Total
 *
 * TWO THINGS THE DATABASE OWNS, NOT THIS CLASS
 *
 * 1. grand_total is a STORED GENERATED column. It is NEVER named in an INSERT
 *    or UPDATE column list — MySQL/MariaDB rejects that outright — so the
 *    total can never disagree with adults + kids.
 *
 * 2. uq_service_date_name (service_date, service_name) is what actually
 *    prevents a duplicate entry, even under a double-submit or two admins
 *    saving at once. A pre-check alone would race. The add/update methods
 *    catch errorInfo[1] == 1062 and hand back the id of the row already
 *    holding that slot, so the controller can offer "edit the existing one"
 *    rather than surfacing a raw SQL error.
 *
 * There is deliberately no average column. getStats() computes
 * AVG(grand_total) over the filtered rows, so an average can never go stale.
 */
class ServiceAttendance
{
    /** MySQL duplicate-key error number behind SQLSTATE 23000. */
    private const ERR_DUPLICATE = 1062;

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    /**
     * Records, newest Sunday first.
     *
     * @param array $filters date_from, date_to, service_name
     * @return array rows (empty array on error or when the table is empty)
     */
    public function getAll(array $filters = []): array
    {
        try {
            [$where, $params] = $this->buildWhere($filters);
            $stmt = $this->db->prepare(
                "SELECT sa.*, a.username AS recorded_by_name
                   FROM service_attendances sa
                   LEFT JOIN accounts a ON a.id = sa.recorded_by
                  {$where}
                  ORDER BY sa.service_date DESC, sa.service_name ASC"
            );
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getAll error: " . $e->getMessage());
            return [];
        }
    }

    public function getById($id)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM service_attendances WHERE id = ?");
            $stmt->execute([(int)$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getById error: " . $e->getMessage());
            return false;
        }
    }

    /** The row occupying a given Date + Service Time slot, or false. */
    public function findByDateAndService(string $date, string $serviceName)
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM service_attendances WHERE service_date = ? AND service_name = ? LIMIT 1"
            );
            $stmt->execute([$date, $serviceName]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("ServiceAttendance::findByDateAndService error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Roll-up over the filtered rows. Every figure is derived here and nowhere
     * stored: `records` is a row count, `average` is AVG(grand_total) rounded
     * to a whole person, and all of them come back as 0 when there are no rows
     * (AVG of an empty set is NULL, which would otherwise render as blank).
     *
     * @return array{records:int,sundays:int,adults:int,kids:int,total:int,average:int,
     *               first_date:?string,last_date:?string}
     */
    public function getStats(array $filters = []): array
    {
        $base = [
            'records' => 0, 'sundays' => 0, 'adults' => 0, 'kids' => 0,
            'total' => 0, 'average' => 0, 'first_date' => null, 'last_date' => null,
        ];
        try {
            [$where, $params] = $this->buildWhere($filters, 'sa');
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)                       AS records,
                        COUNT(DISTINCT sa.service_date) AS sundays,
                        COALESCE(SUM(sa.adults), 0)     AS adults,
                        COALESCE(SUM(sa.kids), 0)       AS kids,
                        COALESCE(SUM(sa.grand_total), 0) AS total,
                        AVG(sa.grand_total)             AS average,
                        MIN(sa.service_date)            AS first_date,
                        MAX(sa.service_date)            AS last_date
                   FROM service_attendances sa
                  {$where}"
            );
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row) return $base;
            return [
                'records'    => (int)$row['records'],
                'sundays'    => (int)$row['sundays'],
                'adults'     => (int)$row['adults'],
                'kids'       => (int)$row['kids'],
                'total'      => (int)$row['total'],
                // AVG() is NULL on an empty set — cast only after the null check
                // so an empty table reports 0 rather than an empty string.
                'average'    => $row['average'] === null ? 0 : (int)round((float)$row['average']),
                'first_date' => $row['first_date'] ?: null,
                'last_date'  => $row['last_date']  ?: null,
            ];
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getStats error: " . $e->getMessage());
            return $base;
        }
    }

    /**
     * The most recent service DAY, summed across every service time on it.
     * "Latest Sunday total" means the whole day, not whichever single service
     * happened to be entered last.
     *
     * @return array{date:?string,adults:int,kids:int,total:int,services:int,average:int}
     */
    public function getLatestSunday(array $filters = []): array
    {
        $base = ['date' => null, 'adults' => 0, 'kids' => 0, 'total' => 0, 'services' => 0, 'average' => 0];
        try {
            [$where, $params] = $this->buildWhere($filters, 'sa');
            $dateStmt = $this->db->prepare("SELECT MAX(sa.service_date) FROM service_attendances sa {$where}");
            $dateStmt->execute($params);
            $latest = $dateStmt->fetchColumn();
            if (!$latest) return $base;

            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(adults), 0)      AS adults,
                        COALESCE(SUM(kids), 0)        AS kids,
                        COALESCE(SUM(grand_total), 0) AS total,
                        COUNT(*)                      AS services,
                        AVG(grand_total)              AS average
                   FROM service_attendances
                  WHERE service_date = ?"
            );
            $stmt->execute([$latest]);
            $row = $stmt->fetch();
            if (!$row) return $base;
            return [
                'date'     => $latest,
                'adults'   => (int)$row['adults'],
                'kids'     => (int)$row['kids'],
                'total'    => (int)$row['total'],
                'services' => (int)$row['services'],
                'average'  => $row['average'] === null ? 0 : (int)round((float)$row['average']),
            ];
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getLatestSunday error: " . $e->getMessage());
            return $base;
        }
    }

    /**
     * Trend series: one point per service DAY, adults/kids/total summed across
     * that day's services. Returned oldest-first so the chart reads left to
     * right. Empty arrays when there is nothing to plot — the view checks
     * `labels` before drawing a canvas.
     *
     * @return array{labels:array,totals:array,adults:array,kids:array}
     */
    public function getTrendSeries(array $filters = [], int $limit = 26): array
    {
        $empty = ['labels' => [], 'totals' => [], 'adults' => [], 'kids' => []];
        try {
            [$where, $params] = $this->buildWhere($filters, 'sa');
            // LIMIT is an int clamped here, not a bound parameter: emulated
            // prepares are off, and MySQL will not accept a placeholder in
            // LIMIT in that mode.
            $limit = max(1, min(260, $limit));
            $stmt = $this->db->prepare(
                "SELECT sa.service_date                 AS d,
                        COALESCE(SUM(sa.adults), 0)      AS adults,
                        COALESCE(SUM(sa.kids), 0)        AS kids,
                        COALESCE(SUM(sa.grand_total), 0) AS total
                   FROM service_attendances sa
                  {$where}
                  GROUP BY sa.service_date
                  ORDER BY sa.service_date DESC
                  LIMIT {$limit}"
            );
            $stmt->execute($params);
            $rows = array_reverse($stmt->fetchAll() ?: []);
            if (!$rows) return $empty;
            return [
                'labels' => array_map(fn($r) => date('M j', strtotime($r['d'])), $rows),
                'totals' => array_map(fn($r) => (int)$r['total'],  $rows),
                'adults' => array_map(fn($r) => (int)$r['adults'], $rows),
                'kids'   => array_map(fn($r) => (int)$r['kids'],   $rows),
            ];
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getTrendSeries error: " . $e->getMessage());
            return $empty;
        }
    }

    /** Per-service-time roll-up, for the records page summary. */
    public function getByServiceName(array $filters = []): array
    {
        try {
            [$where, $params] = $this->buildWhere($filters, 'sa');
            $stmt = $this->db->prepare(
                "SELECT sa.service_name,
                        COUNT(*)                        AS records,
                        COALESCE(SUM(sa.grand_total),0) AS total,
                        AVG(sa.grand_total)             AS average
                   FROM service_attendances sa
                  {$where}
                  GROUP BY sa.service_name
                  ORDER BY sa.service_name ASC"
            );
            $stmt->execute($params);
            $out = [];
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $out[] = [
                    'service_name' => $r['service_name'],
                    'records'      => (int)$r['records'],
                    'total'        => (int)$r['total'],
                    'average'      => $r['average'] === null ? 0 : (int)round((float)$r['average']),
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getByServiceName error: " . $e->getMessage());
            return [];
        }
    }

    /** Distinct service names that actually appear in the records, for the filter. */
    public function getDistinctServiceNames(): array
    {
        try {
            return $this->db->query(
                "SELECT DISTINCT service_name FROM service_attendances ORDER BY service_name ASC"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getDistinctServiceNames error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * The configured service times — the only values the Service Time dropdown
     * may offer. Sourced from `services` where is_service_time = 1, so the
     * three legacy status markers ("migrated", "going to Mla",
     * "transferred to Dmgte") can never be recorded as a service.
     */
    public function getServiceTimeOptions(): array
    {
        try {
            return $this->db->query(
                "SELECT name, service_period
                   FROM services
                  WHERE is_service_time = 1 AND is_active = 1 AND is_deleted = 0
                  ORDER BY sort_order ASC, name ASC"
            )->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getServiceTimeOptions error: " . $e->getMessage());
            return [];
        }
    }

    // ── Dashboard scoping ───────────────────────────────────────────────────

    /** The three dashboard range options, in menu order. */
    const RANGES = [
        'last4'   => 'Last 4 Sundays',
        'last3mo' => 'Last 3 Months',
        'year'    => 'This Year',
    ];
    const DEFAULT_RANGE = 'last4';

    public static function normaliseRange($range): string
    {
        return array_key_exists($range, self::RANGES) ? $range : self::DEFAULT_RANGE;
    }

    /**
     * Turns a range key into the filter array every other method already
     * understands, so ONE scope drives the cards, the average AND the chart —
     * they cannot drift apart.
     *
     * "Last 4 Sundays" is counted in service DAYS that actually have records
     * (not calendar weeks), because a cancelled or unrecorded Sunday should
     * still leave four points on the chart rather than two.
     *
     * @return array{filters:array,limit:int,label:string}
     */
    public function rangeScope($range): array
    {
        $range = self::normaliseRange($range);

        if ($range === 'last3mo') {
            return [
                'filters' => ['date_from' => date('Y-m-d', strtotime('-3 months'))],
                'limit'   => 26,
                'label'   => self::RANGES['last3mo'],
            ];
        }
        if ($range === 'year') {
            return [
                'filters' => ['date_from' => date('Y') . '-01-01', 'date_to' => date('Y') . '-12-31'],
                'limit'   => 60,
                'label'   => self::RANGES['year'],
            ];
        }

        // last4 — find the 4th most recent service day and start there. With
        // fewer than four recorded days (including none at all) the filter is
        // simply empty, which still scopes to "everything there is".
        $filters = [];
        try {
            $cut = $this->db->query(
                "SELECT service_date FROM (
                     SELECT DISTINCT service_date FROM service_attendances
                      ORDER BY service_date DESC LIMIT 4
                 ) t ORDER BY service_date ASC LIMIT 1"
            )->fetchColumn();
            if ($cut) $filters['date_from'] = $cut;
        } catch (PDOException $e) {
            error_log("ServiceAttendance::rangeScope error: " . $e->getMessage());
        }
        return ['filters' => $filters, 'limit' => 4, 'label' => self::RANGES['last4']];
    }

    // ── Writes ──────────────────────────────────────────────────────────────

    /**
     * @return array{ok:bool,id?:int,error?:string,duplicate?:bool,existing_id?:int}
     */
    public function add(array $data, ?int $recordedBy = null): array
    {
        $clean = $this->normalise($data);
        if (isset($clean['error'])) return ['ok' => false, 'error' => $clean['error']];

        try {
            // grand_total is absent from this column list ON PURPOSE — it is a
            // STORED GENERATED column and naming it is an error.
            $stmt = $this->db->prepare(
                "INSERT INTO service_attendances
                     (service_date, service_name, adults, kids, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $clean['service_date'],
                $clean['service_name'],
                $clean['adults'],
                $clean['kids'],
                $clean['notes'],
                $recordedBy ?: null,
            ]);
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                return $this->duplicateResult($clean['service_date'], $clean['service_name']);
            }
            error_log("ServiceAttendance::add error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save this entry. Please try again.'];
        }
    }

    /**
     * Updating a row never collides with itself: the UNIQUE key only fires when
     * the new Date + Service Time belongs to a DIFFERENT row, which is exactly
     * the "exclude self from the duplicate check" rule, enforced by the index
     * rather than a hand-written query.
     *
     * @return array{ok:bool,error?:string,duplicate?:bool,existing_id?:int}
     */
    public function update($id, array $data): array
    {
        $id = (int)$id;
        if ($id <= 0) return ['ok' => false, 'error' => 'Missing record id.'];

        $clean = $this->normalise($data);
        if (isset($clean['error'])) return ['ok' => false, 'error' => $clean['error']];

        try {
            // grand_total deliberately absent — see add().
            $stmt = $this->db->prepare(
                "UPDATE service_attendances
                    SET service_date = ?, service_name = ?, adults = ?, kids = ?, notes = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $clean['service_date'],
                $clean['service_name'],
                $clean['adults'],
                $clean['kids'],
                $clean['notes'],
                $id,
            ]);
            return ['ok' => true, 'id' => $id];
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                $dup = $this->duplicateResult($clean['service_date'], $clean['service_name']);
                // Guard against reporting the row back to itself.
                if (($dup['existing_id'] ?? 0) === $id) return ['ok' => true, 'id' => $id];
                return $dup;
            }
            error_log("ServiceAttendance::update error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update this entry. Please try again.'];
        }
    }

    /**
     * Hard delete. There is no soft-delete column on this table and no child
     * rows hang off it, so a head count that was entered by mistake is simply
     * removed — the confirm() dialog on the button is the safeguard.
     */
    public function delete($id): bool
    {
        try {
            $this->db->prepare("DELETE FROM service_attendances WHERE id = ?")->execute([(int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("ServiceAttendance::delete error: " . $e->getMessage());
            return false;
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Validates + coerces POST data. Returns ['error' => ...] on a bad input. */
    private function normalise(array $data): array
    {
        $date = trim((string)($data['service_date'] ?? ''));
        $name = trim((string)($data['service_name'] ?? ''));

        if ($date === '') return ['error' => 'Date is required.'];
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) return ['error' => 'Date is not a valid calendar date.'];
        if ($name === '') return ['error' => 'Service Time is required.'];

        // Only a configured service time may be stored, whatever the form posts.
        $allowed = array_column($this->getServiceTimeOptions(), 'name');
        if ($allowed && !in_array($name, $allowed, true)) {
            return ['error' => 'That Service Time is not one of the configured service times.'];
        }

        // chk_sa_counts rejects negatives at the database; clamping here turns
        // a typo into a friendly validation message instead of a 500.
        $adults = $this->toCount($data['adults'] ?? 0);
        $kids   = $this->toCount($data['kids']   ?? 0);
        if ($adults === null || $kids === null) {
            return ['error' => 'Adults and Kids must be whole numbers of zero or more.'];
        }

        $notes = trim((string)($data['notes'] ?? ''));
        if ($notes === '') $notes = null;
        elseif (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

        return [
            'service_date' => $date,
            'service_name' => $name,
            'adults'       => $adults,
            'kids'         => $kids,
            'notes'        => $notes,
        ];
    }

    /** @return int|null null when the value isn't a non-negative whole number. */
    private function toCount($value): ?int
    {
        if (is_string($value)) $value = trim($value);
        if ($value === '' || $value === null) return 0;
        if (!is_numeric($value)) return null;
        $n = (int)$value;
        if ($n < 0 || (float)$value != (float)$n) return null;
        return $n;
    }

    private function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) == self::ERR_DUPLICATE
            || $e->getCode() === '23000';
    }

    /** Builds the friendly duplicate payload, including the clashing row's id. */
    private function duplicateResult(string $date, string $name): array
    {
        $existing = $this->findByDateAndService($date, $name);
        return [
            'ok'          => false,
            'duplicate'   => true,
            'existing_id' => $existing ? (int)$existing['id'] : 0,
            'error'       => sprintf(
                'An entry already exists for %s at %s. Open that entry and edit it instead of adding a second one.',
                date('M j, Y', strtotime($date)),
                $name
            ),
        ];
    }

    /** @return array{0:string,1:array} WHERE clause + bound params. */
    private function buildWhere(array $filters, string $alias = 'sa'): array
    {
        $clauses = [];
        $params  = [];
        if (!empty($filters['date_from'])) {
            $clauses[] = "{$alias}.service_date >= ?";
            $params[]  = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = "{$alias}.service_date <= ?";
            $params[]  = $filters['date_to'];
        }
        if (!empty($filters['service_name'])) {
            $clauses[] = "{$alias}.service_name = ?";
            $params[]  = $filters['service_name'];
        }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
?>
