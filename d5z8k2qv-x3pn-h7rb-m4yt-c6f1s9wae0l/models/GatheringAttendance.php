<?php
/**
 * Weekday gatherings — Prayer Meeting and Students' Hangout head counts.
 *
 * One row per (gathering_type, event_date): one count per gathering per date.
 * migrations/2026_10_20_16_gathering_attendances.sql.
 *
 * gathering_type is a VARCHAR whitelisted by TYPES below, so another weekday
 * gathering is added by adding one entry here — no ALTER TABLE.
 *
 * The UNIQUE key (uq_gathering_type_date) is what really prevents a duplicate;
 * add()/update() catch error 1062 and return the clashing row's id so the
 * controller can offer "edit the existing one instead". attendance >= 0 is
 * validated here because MariaDB 10.1 does not enforce CHECK constraints.
 */
class GatheringAttendance
{
    private const ERR_DUPLICATE = 1062;

    /** key => [label, usual day, icon, Bootstrap colour] */
    const TYPES = [
        'prayer_meeting'   => ['label' => 'Prayer Meeting',     'day' => 'Tuesday or Wednesday', 'icon' => 'bi-fire',         'color' => 'danger'],
        'students_hangout' => ['label' => "Students' Hangout",  'day' => 'Friday',               'icon' => 'bi-mortarboard',  'color' => 'success'],
    ];

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public static function isType($type): bool
    {
        return is_string($type) && array_key_exists($type, self::TYPES);
    }

    public static function label($type): string
    {
        return self::TYPES[$type]['label'] ?? (string)$type;
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    /** @param array $filters gathering_type, date_from, date_to */
    public function getAll(array $filters = []): array
    {
        try {
            [$where, $params] = $this->buildWhere($filters);
            $stmt = $this->db->prepare(
                "SELECT ga.*, a.username AS recorded_by_name
                   FROM gathering_attendances ga
                   LEFT JOIN accounts a ON a.id = ga.recorded_by
                  {$where}
                  ORDER BY ga.event_date DESC, ga.gathering_type ASC"
            );
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("GatheringAttendance::getAll error: " . $e->getMessage());
            return [];
        }
    }

    public function getById($id)
    {
        try {
            $stmt = $this->db->prepare("SELECT * FROM gathering_attendances WHERE id = ?");
            $stmt->execute([(int)$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("GatheringAttendance::getById error: " . $e->getMessage());
            return false;
        }
    }

    public function findByTypeAndDate(string $type, string $date)
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM gathering_attendances WHERE gathering_type = ? AND event_date = ? LIMIT 1"
            );
            $stmt->execute([$type, $date]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("GatheringAttendance::findByTypeAndDate error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Per-type roll-up: count, total, average, latest. Every type in TYPES is
     * present even with no rows (zeros / nulls), so views never index a gap.
     *
     * @return array<string,array{events:int,total:int,average:int,latest_date:?string,latest:?int,first_date:?string}>
     */
    public function getStatsByType(array $filters = []): array
    {
        $out = [];
        foreach (array_keys(self::TYPES) as $t) {
            $out[$t] = ['events' => 0, 'total' => 0, 'average' => 0, 'latest_date' => null, 'latest' => null, 'first_date' => null];
        }
        try {
            $f = $filters; unset($f['gathering_type']);
            [$where, $params] = $this->buildWhere($f);
            $stmt = $this->db->prepare(
                "SELECT ga.gathering_type, COUNT(*) AS events, COALESCE(SUM(ga.attendance),0) AS total,
                        AVG(ga.attendance) AS average, MIN(ga.event_date) AS first_date, MAX(ga.event_date) AS latest_date
                   FROM gathering_attendances ga
                  {$where}
                  GROUP BY ga.gathering_type"
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                if (!isset($out[$r['gathering_type']])) continue;
                $out[$r['gathering_type']] = [
                    'events'      => (int)$r['events'],
                    'total'       => (int)$r['total'],
                    'average'     => $r['average'] === null ? 0 : (int)round((float)$r['average']),
                    'latest_date' => $r['latest_date'] ?: null,
                    'latest'      => null,
                    'first_date'  => $r['first_date'] ?: null,
                ];
            }
            $latest = $this->db->prepare(
                "SELECT attendance FROM gathering_attendances WHERE gathering_type = ? AND event_date = ? LIMIT 1"
            );
            foreach ($out as $t => $s) {
                if (!$s['latest_date']) continue;
                $latest->execute([$t, $s['latest_date']]);
                $v = $latest->fetchColumn();
                $out[$t]['latest'] = $v === false ? null : (int)$v;
            }
        } catch (PDOException $e) {
            error_log("GatheringAttendance::getStatsByType error: " . $e->getMessage());
        }
        return $out;
    }

    /**
     * One point per event, oldest first, for one type within the filters.
     * @return array{labels:array,values:array}
     */
    public function getSeries(string $type, array $filters = [], int $limit = 60): array
    {
        $empty = ['labels' => [], 'values' => []];
        if (!self::isType($type)) return $empty;
        try {
            $filters['gathering_type'] = $type;
            [$where, $params] = $this->buildWhere($filters);
            $limit = max(1, min(400, $limit));
            $stmt = $this->db->prepare(
                "SELECT ga.event_date AS d, ga.attendance AS n
                   FROM gathering_attendances ga
                  {$where}
                  ORDER BY ga.event_date DESC
                  LIMIT {$limit}"
            );
            $stmt->execute($params);
            $rows = array_reverse($stmt->fetchAll() ?: []);
            return [
                'labels' => array_map(fn($r) => date('M j', strtotime($r['d'])), $rows),
                'values' => array_map(fn($r) => (int)$r['n'], $rows),
            ];
        } catch (PDOException $e) {
            error_log("GatheringAttendance::getSeries error: " . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Average attendance per month for one year, every type, Jan..Dec.
     * null for a month with no events of that type.
     *
     * @return array{labels:array,series:array<string,array>,events:array<string,array>}
     */
    public function getMonthlyAverages(int $year): array
    {
        $labels = [];
        for ($m = 1; $m <= 12; $m++) $labels[] = date('M', mktime(0, 0, 0, $m, 1, 2000));
        $series = []; $events = [];
        foreach (array_keys(self::TYPES) as $t) { $series[$t] = array_fill(0, 12, null); $events[$t] = array_fill(0, 12, 0); }
        try {
            $stmt = $this->db->prepare(
                "SELECT gathering_type, MONTH(event_date) AS m, COUNT(*) AS n, AVG(attendance) AS a
                   FROM gathering_attendances
                  WHERE event_date BETWEEN ? AND ?
                  GROUP BY gathering_type, MONTH(event_date)"
            );
            $stmt->execute([$year . '-01-01', $year . '-12-31']);
            foreach ($stmt->fetchAll() ?: [] as $r) {
                $t = $r['gathering_type']; $i = (int)$r['m'] - 1;
                if (!isset($series[$t]) || $i < 0 || $i > 11) continue;
                $series[$t][$i] = (int)round((float)$r['a']);
                $events[$t][$i] = (int)$r['n'];
            }
        } catch (PDOException $e) {
            error_log("GatheringAttendance::getMonthlyAverages error: " . $e->getMessage());
        }
        return ['labels' => $labels, 'series' => $series, 'events' => $events];
    }

    /** Years that have gathering records, newest first. */
    public function getYears(): array
    {
        try {
            return array_map('intval', $this->db->query(
                "SELECT DISTINCT YEAR(event_date) FROM gathering_attendances ORDER BY 1 DESC"
            )->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (PDOException $e) {
            error_log("GatheringAttendance::getYears error: " . $e->getMessage());
            return [];
        }
    }

    // ── Writes ──────────────────────────────────────────────────────────────

    /** @return array{ok:bool,id?:int,error?:string,duplicate?:bool,existing_id?:int} */
    public function add(array $data, ?int $recordedBy = null): array
    {
        $c = $this->normalise($data);
        if (isset($c['error'])) return ['ok' => false, 'error' => $c['error']];
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO gathering_attendances (gathering_type, event_date, attendance, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$c['gathering_type'], $c['event_date'], $c['attendance'], $c['notes'], $recordedBy ?: null]);
            return ['ok' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) return $this->duplicateResult($c['gathering_type'], $c['event_date']);
            error_log("GatheringAttendance::add error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save this entry. Please try again.'];
        }
    }

    public function update($id, array $data): array
    {
        $id = (int)$id;
        if ($id <= 0) return ['ok' => false, 'error' => 'Missing record id.'];
        $c = $this->normalise($data);
        if (isset($c['error'])) return ['ok' => false, 'error' => $c['error']];
        try {
            $stmt = $this->db->prepare(
                "UPDATE gathering_attendances
                    SET gathering_type = ?, event_date = ?, attendance = ?, notes = ?
                  WHERE id = ?"
            );
            $stmt->execute([$c['gathering_type'], $c['event_date'], $c['attendance'], $c['notes'], $id]);
            return ['ok' => true, 'id' => $id];
        } catch (PDOException $e) {
            if ($this->isDuplicate($e)) {
                $dup = $this->duplicateResult($c['gathering_type'], $c['event_date']);
                if (($dup['existing_id'] ?? 0) === $id) return ['ok' => true, 'id' => $id];
                return $dup;
            }
            error_log("GatheringAttendance::update error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update this entry. Please try again.'];
        }
    }

    public function delete($id): bool
    {
        try {
            $this->db->prepare("DELETE FROM gathering_attendances WHERE id = ?")->execute([(int)$id]);
            return true;
        } catch (PDOException $e) {
            error_log("GatheringAttendance::delete error: " . $e->getMessage());
            return false;
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function normalise(array $data): array
    {
        $type = trim((string)($data['gathering_type'] ?? ''));
        $date = trim((string)($data['event_date'] ?? ''));
        if (!self::isType($type)) return ['error' => 'Choose Prayer Meeting or Students\' Hangout.'];
        if ($date === '') return ['error' => 'Date is required.'];
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) return ['error' => 'Date is not a valid calendar date.'];

        $raw = is_string($data['attendance'] ?? null) ? trim($data['attendance']) : ($data['attendance'] ?? '');
        if ($raw === '' || $raw === null) return ['error' => 'Attendance is required.'];
        if (!is_numeric($raw) || (float)$raw != (int)$raw || (int)$raw < 0) {
            return ['error' => 'Attendance must be a whole number of zero or more.'];
        }
        $notes = trim((string)($data['notes'] ?? ''));
        if ($notes === '') $notes = null;
        elseif (mb_strlen($notes) > 255) $notes = mb_substr($notes, 0, 255);

        return ['gathering_type' => $type, 'event_date' => $date, 'attendance' => (int)$raw, 'notes' => $notes];
    }

    private function isDuplicate(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) == self::ERR_DUPLICATE || $e->getCode() === '23000';
    }

    private function duplicateResult(string $type, string $date): array
    {
        $existing = $this->findByTypeAndDate($type, $date);
        return [
            'ok'          => false,
            'duplicate'   => true,
            'existing_id' => $existing ? (int)$existing['id'] : 0,
            'error'       => sprintf(
                '%s on %s is already recorded. Open that entry and edit it instead of adding a second one.',
                self::label($type), date('M j, Y', strtotime($date))
            ),
        ];
    }

    private function buildWhere(array $filters, string $alias = 'ga'): array
    {
        $clauses = []; $params = [];
        if (!empty($filters['gathering_type']) && self::isType($filters['gathering_type'])) {
            $clauses[] = "{$alias}.gathering_type = ?"; $params[] = $filters['gathering_type'];
        }
        if (!empty($filters['date_from'])) { $clauses[] = "{$alias}.event_date >= ?"; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to']))   { $clauses[] = "{$alias}.event_date <= ?"; $params[] = $filters['date_to']; }
        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
