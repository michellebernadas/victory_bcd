<?php
/**
 * Sunday Service Attendance — "Church Health" head counts.
 *
 * One row per (service_date, service_name). Per service time:
 *   Date + Service Time -> Sunday Service + Kids Church = Total
 *
 * COLUMN NAMES vs WHAT THE CHURCH CALLS THEM (read this first)
 *   adults        = the SUNDAY SERVICE count: everyone in the main service
 *                   listening to the sermon (not adults only). Labelled
 *                   "Sunday Service" everywhere a user sees it.
 *   kids          = the KIDS CHURCH count. Labelled "Kids Church".
 *   kids_recorded = 0 when Kids Church was NOT counted at that service
 *                   (migrations/2026_10_20_15). kids is then stored as 0 only
 *                   because the column is NOT NULL; every Kids Church figure
 *                   below filters on kids_recorded = 1 so a missing count is
 *                   ignored, never averaged in as a zero.
 * The columns were relabelled rather than renamed: grand_total is a generated
 * column over both, and altering columns it depends on is the riskiest change
 * on MariaDB 10.1. grand_total = adults + kids, i.e. everyone COUNTED at that
 * service (just the Sunday Service count when Kids Church was not recorded).
 *
 * Day totals: "Total Sunday Service" = SUM(adults) across the day's service
 * times; "Total Kids Church" = SUM(kids) over the times where it was recorded;
 * Grand Total = both.
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
            'kids_missing' => 0, 'kids_days' => 0,
            'avg_service_day' => 0, 'avg_kids_day' => 0, 'avg_total_day' => 0,
        ];
        try {
            [$where, $params] = $this->buildWhere($filters, 'sa');
            // adults = Sunday Service, kids = Kids Church (see the class doc).
            $stmt = $this->db->prepare(
                "SELECT COUNT(*)                       AS records,
                        COUNT(DISTINCT sa.service_date) AS sundays,
                        COALESCE(SUM(sa.adults), 0)     AS adults,
                        COALESCE(SUM(CASE WHEN sa.kids_recorded = 1 THEN sa.kids END), 0) AS kids,
                        COALESCE(SUM(sa.grand_total), 0) AS total,
                        COALESCE(SUM(sa.kids_recorded = 0), 0) AS kids_missing,
                        MIN(sa.service_date)            AS first_date,
                        MAX(sa.service_date)            AS last_date
                   FROM service_attendances sa
                  {$where}"
            );
            $stmt->execute($params);
            $row = $stmt->fetch();
            if (!$row || (int)$row['records'] === 0) return $base;

            // Averages are PER SUNDAY (whole service day, every time summed).
            // A day with no Kids Church count at any service has a NULL kids
            // sum, which AVG() skips — so it does not pull the average down.
            $dayStmt = $this->db->prepare(
                "SELECT AVG(d.svc) AS avg_svc, AVG(d.kids) AS avg_kids, AVG(d.tot) AS avg_tot,
                        COUNT(d.kids) AS kids_days
                   FROM (SELECT sa.service_date,
                                SUM(sa.adults) AS svc,
                                SUM(CASE WHEN sa.kids_recorded = 1 THEN sa.kids END) AS kids,
                                SUM(sa.grand_total) AS tot
                           FROM service_attendances sa
                          {$where}
                          GROUP BY sa.service_date) d"
            );
            $dayStmt->execute($params);
            $day = $dayStmt->fetch() ?: [];
            $r = fn($v) => $v === null ? 0 : (int)round((float)$v);
            $sundays = (int)$row['sundays'];
            return [
                'records'    => (int)$row['records'],
                'sundays'    => $sundays,
                'adults'     => (int)$row['adults'],
                'kids'       => (int)$row['kids'],
                'total'      => (int)$row['total'],
                // Grand total per Sunday. Guarded: no days, no division.
                'average'    => $sundays > 0 ? (int)round((int)$row['total'] / $sundays) : 0,
                'first_date' => $row['first_date'] ?: null,
                'last_date'  => $row['last_date']  ?: null,
                'kids_missing'    => (int)$row['kids_missing'],
                'kids_days'       => (int)($day['kids_days'] ?? 0),
                'avg_service_day' => $r($day['avg_svc']  ?? null),
                'avg_kids_day'    => $r($day['avg_kids'] ?? null),
                'avg_total_day'   => $r($day['avg_tot']  ?? null),
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
        $base = ['date' => null, 'adults' => 0, 'kids' => 0, 'total' => 0, 'services' => 0, 'average' => 0, 'kids_missing' => 0];
        try {
            [$where, $params] = $this->buildWhere($filters, 'sa');
            $dateStmt = $this->db->prepare("SELECT MAX(sa.service_date) FROM service_attendances sa {$where}");
            $dateStmt->execute($params);
            $latest = $dateStmt->fetchColumn();
            if (!$latest) return $base;

            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(adults), 0)      AS adults,
                        COALESCE(SUM(CASE WHEN kids_recorded = 1 THEN kids END), 0) AS kids,
                        COALESCE(SUM(grand_total), 0) AS total,
                        COUNT(*)                      AS services,
                        COALESCE(SUM(kids_recorded = 0), 0) AS kids_missing,
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
                'kids_missing' => (int)$row['kids_missing'],
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
                        SUM(CASE WHEN sa.kids_recorded = 1 THEN sa.kids END) AS kids,
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
                // null (not 0) on a Sunday with no Kids Church count, so the
                // chart shows a gap instead of a false dip to zero.
                'kids'   => array_map(fn($r) => $r['kids'] === null ? null : (int)$r['kids'], $rows),
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
                        AVG(sa.grand_total)             AS average,
                        AVG(sa.adults)                  AS avg_service,
                        AVG(CASE WHEN sa.kids_recorded = 1 THEN sa.kids END) AS avg_kids
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
                    'avg_service'  => $r['avg_service'] === null ? 0 : (int)round((float)$r['avg_service']),
                    // null = Kids Church never recorded at this time in range.
                    'avg_kids'     => $r['avg_kids'] === null ? null : (int)round((float)$r['avg_kids']),
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

    /**
     * Month-by-month AVERAGE PER SUNDAY for one calendar year: Sunday Service
     * and Kids Church separately. A month's Kids Church average only counts
     * Sundays where Kids Church was recorded; null when none were.
     * Always 12 points (Jan..Dec) so years line up; months without data are null.
     *
     * @return array{labels:array,service:array,kids:array,sundays:array}
     */
    public function getMonthlyAverages(int $year): array
    {
        $labels = []; $service = array_fill(0, 12, null); $kids = array_fill(0, 12, null); $sundays = array_fill(0, 12, 0);
        for ($m = 1; $m <= 12; $m++) $labels[] = date('M', mktime(0, 0, 0, $m, 1, 2000));
        try {
            $stmt = $this->db->prepare(
                "SELECT MONTH(d.service_date) AS m, COUNT(*) AS sundays,
                        AVG(d.svc) AS avg_svc, AVG(d.kids) AS avg_kids
                   FROM (SELECT service_date,
                                SUM(adults) AS svc,
                                SUM(CASE WHEN kids_recorded = 1 THEN kids END) AS kids
                           FROM service_attendances
                          WHERE service_date BETWEEN ? AND ?
                          GROUP BY service_date) d
                  GROUP BY MONTH(d.service_date)"
            );
            $stmt->execute([$year . '-01-01', $year . '-12-31']);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $i = (int)$r['m'] - 1;
                if ($i < 0 || $i > 11) continue;
                $sundays[$i] = (int)$r['sundays'];
                $service[$i] = $r['avg_svc']  === null ? null : (int)round((float)$r['avg_svc']);
                $kids[$i]    = $r['avg_kids'] === null ? null : (int)round((float)$r['avg_kids']);
            }
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getMonthlyAverages error: " . $e->getMessage());
        }
        return ['labels' => $labels, 'service' => $service, 'kids' => $kids, 'sundays' => $sundays];
    }

    /** Years that have Sunday records, newest first. */
    public function getYears(): array
    {
        try {
            return array_map('intval', $this->db->query(
                "SELECT DISTINCT YEAR(service_date) FROM service_attendances ORDER BY 1 DESC"
            )->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getYears error: " . $e->getMessage());
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
                     (service_date, service_name, adults, kids, kids_recorded, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $clean['service_date'],
                $clean['service_name'],
                $clean['adults'],
                $clean['kids'],
                $clean['kids_recorded'],
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
                    SET service_date = ?, service_name = ?, adults = ?, kids = ?, kids_recorded = ?, notes = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $clean['service_date'],
                $clean['service_name'],
                $clean['adults'],
                $clean['kids'],
                $clean['kids_recorded'],
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

    // ── "Enter a Sunday": every service time of one date in one form ────────

    /** Entries already saved for one date, keyed by service_name. */
    public function getDay(string $date): array
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) return [];
        try {
            $stmt = $this->db->prepare(
                "SELECT id, service_name, adults, kids, kids_recorded, grand_total, notes
                   FROM service_attendances WHERE service_date = ? ORDER BY service_name"
            );
            $stmt->execute([$date]);
            $out = [];
            foreach ($stmt->fetchAll() as $r) {
                $kr = (int)$r['kids_recorded'] === 1;
                $out[$r['service_name']] = [
                    'id' => (int)$r['id'], 'adults' => (int)$r['adults'],
                    // null = Kids Church not recorded for this service.
                    'kids' => $kr ? (int)$r['kids'] : null,
                    'total' => (int)$r['grand_total'], 'notes' => (string)($r['notes'] ?? ''),
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log("ServiceAttendance::getDay error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Save a whole service day in ONE transaction.
     *
     * $rows: [['name' => '8:30 AM', 'adults' => '310', 'kids' => '42', 'delete' => '0'], ...]
     *   - both counts blank          -> skipped, never saved as zeros
     *   - blank + a saved entry      -> deleted only when 'delete' is set (the form
     *                                   asks first); otherwise the entry is kept
     *   - Sunday Service blank only  -> Sunday Service is 0
     *   - Kids Church blank only     -> Kids Church "not recorded" (kids_recorded = 0)
     *   - filled + a saved entry     -> UPDATE adults/kids (notes left untouched)
     * ('adults' = Sunday Service, 'kids' = Kids Church — see the class doc.)
     *   - filled + no entry          -> INSERT
     * grand_total is never named: it is a generated column. Every row is
     * validated before the transaction opens, so a bad value saves nothing.
     *
     * @return array{ok:bool,error?:string,saved?:int,deleted?:int,kept?:int,day_total?:int,day_services?:int}
     */
    public function saveDay(string $date, array $rows, ?int $recordedBy = null): array
    {
        $date = trim($date);
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($date === '' || !$d || $d->format('Y-m-d') !== $date) {
            return ['ok' => false, 'error' => 'Choose a valid date.'];
        }
        $allowed = array_column($this->getServiceTimeOptions(), 'name');

        $plan = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $name = trim((string)($r['name'] ?? ''));
            if ($name === '') continue;
            if (!in_array($name, $allowed, true)) {
                return ['ok' => false, 'error' => '"' . $name . '" is not one of the configured service times.'];
            }
            $a = trim((string)($r['adults'] ?? ''));
            $k = trim((string)($r['kids'] ?? ''));
            if ($a === '' && $k === '') {
                $plan[$name] = ['blank' => true, 'delete' => !empty($r['delete'])];
                continue;
            }
            $adults = $this->toCount($a);
            $kids   = $this->toCount($k);
            if ($adults === null || $kids === null) {
                return ['ok' => false, 'error' => 'Sunday Service and Kids Church for ' . $name . ' must be whole numbers of zero or more.'];
            }
            $plan[$name] = ['blank' => false, 'adults' => $adults, 'kids' => $kids, 'kr' => $k === '' ? 0 : 1];
        }

        $saved = 0; $deleted = 0; $kept = 0;
        try {
            $this->db->beginTransaction();
            // Lock this date's rows, and decide insert vs update from what is in
            // the table NOW rather than what the form saw when it was opened.
            $stmt = $this->db->prepare(
                "SELECT id, service_name FROM service_attendances WHERE service_date = ? FOR UPDATE"
            );
            $stmt->execute([$date]);
            $existing = [];
            foreach ($stmt->fetchAll() as $e) $existing[$e['service_name']] = (int)$e['id'];

            $ins = $this->db->prepare(
                "INSERT INTO service_attendances (service_date, service_name, adults, kids, kids_recorded, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $upd = $this->db->prepare("UPDATE service_attendances SET adults = ?, kids = ?, kids_recorded = ? WHERE id = ?");
            $del = $this->db->prepare("DELETE FROM service_attendances WHERE id = ?");

            foreach ($plan as $name => $p) {
                $id = $existing[$name] ?? 0;
                if ($p['blank']) {
                    if ($id && $p['delete']) { $del->execute([$id]); $deleted++; }
                    elseif ($id)             { $kept++; }
                    continue;
                }
                if ($id) $upd->execute([$p['adults'], $p['kids'], $p['kr'], $id]);
                else     $ins->execute([$date, $name, $p['adults'], $p['kids'], $p['kr'], $recordedBy ?: null]);
                $saved++;
            }

            if ($saved === 0 && $deleted === 0) {
                $this->db->rollBack();
                return ['ok' => false, 'error' => 'Nothing to save. Enter Sunday Service or Kids Church for at least one service.'];
            }
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($this->isDuplicate($e)) {
                return ['ok' => false, 'error' => 'Someone else saved this date at the same moment. Nothing was saved; open the date again to see their numbers.'];
            }
            error_log("ServiceAttendance::saveDay error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save this Sunday. Nothing was changed. Please try again.'];
        }

        $day = $this->getDay($date);
        return [
            'ok' => true, 'saved' => $saved, 'deleted' => $deleted, 'kept' => $kept,
            'day_total' => array_sum(array_column($day, 'total')), 'day_services' => count($day),
            'day_service' => array_sum(array_column($day, 'adults')), 'day_kids' => array_sum(array_map(fn($e) => (int)$e['kids'], $day)),
        ];
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

        // chk_sa_counts rejects negatives at the database (not on MariaDB 10.1,
        // which parses CHECK but ignores it), so PHP validates too.
        // adults = Sunday Service, kids = Kids Church.
        $adults = $this->toCount($data['adults'] ?? 0);
        // A blank Kids Church box (or the "not recorded" tick) means the count
        // was not taken — stored as kids_recorded = 0, never as a real 0.
        $kidsRaw = trim((string)($data['kids'] ?? ''));
        $kidsRecorded = empty($data['kids_not_recorded']) && $kidsRaw !== '' ? 1 : 0;
        $kids   = $kidsRecorded ? $this->toCount($kidsRaw) : 0;
        if ($adults === null || $kids === null) {
            return ['error' => 'Sunday Service and Kids Church must be whole numbers of zero or more.'];
        }

        $notes = trim((string)($data['notes'] ?? ''));
        if ($notes === '') $notes = null;
        elseif (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

        return [
            'service_date' => $date,
            'service_name' => $name,
            'adults'       => $adults,
            'kids'         => $kids,
            'kids_recorded'=> $kidsRecorded,
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
