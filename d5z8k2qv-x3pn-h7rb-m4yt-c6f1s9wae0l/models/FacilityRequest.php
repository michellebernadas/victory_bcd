<?php
require_once 'models/Facility.php';

/**
 * Facility requests — the booking workflow from the Victory Bacolod Church
 * Facilities, Equipment & Resource Request Policy.
 *
 * THE ONE RULE (policy 3.6 / 20)
 * "Submitting a request does not mean that the request has been approved."
 * A reservation becomes official only after
 *     Minister Approval -> Facilities Confirmation -> Calendar Entry.
 * So only `status = 'confirmed'` blocks a room. An approved-but-unconfirmed
 * request appears on the calendar in a different colour and reserves nothing;
 * see conflictsFor(), whose hard-conflict query filters on 'confirmed' alone.
 *
 * FOUR-STEP WORKFLOW (policy section 5 / Appendix A)
 *     1 submit()              requestor          -> submitted
 *     2 facilitiesReview()    facilities         -> pending_minister
 *     3 ministerDecision()    minister           -> approved | declined | for_clarification
 *     4 confirm()             facilities         -> confirmed   <= official reservation
 * Each step writes its own review/decision columns AND appends exactly one row
 * to facility_request_history. Nothing is ever overwritten; the current state
 * lives on facility_requests, how it got there lives in the history table. Same
 * shape as historical_completion_reviews / DiscipleshipProgressService::logReview().
 *
 * DERIVED starts_at / ends_at
 * The form captures a date and two times. Conflict detection needs one
 * comparable instant, so every write recomputes the indexed DATETIME pair.
 * They are never typed by a user and are the only columns the overlap query
 * reads. The interval is half-open [starts_at, ends_at): a 10-11 and an 11-12
 * booking do not conflict.
 *
 * LEAD TIME IS A WARNING, NEVER A BLOCK
 * Policy section 10 recommends a minimum lead time per facility; section 11
 * says last-minute requests may still be considered. So submission records
 * lead_days_actual / lead_time_warning and lets the request through.
 */
class FacilityRequest {
    private $db;
    private $facilityModel;

    public function __construct($db) {
        $this->db = $db;
        $this->facilityModel = new Facility($db);
    }

    /** Every status the schema allows, with its UI label and badge colour. */
    const STATUSES = [
        'draft'             => ['label' => 'Draft',             'badge' => 'secondary',    'icon' => 'bi-pencil'],
        'submitted'         => ['label' => 'Submitted',         'badge' => 'info',         'icon' => 'bi-send'],
        'facilities_review' => ['label' => 'Facilities Review', 'badge' => 'primary',      'icon' => 'bi-clipboard-check'],
        'pending_minister'  => ['label' => 'Pending Minister',  'badge' => 'warning',      'icon' => 'bi-hourglass-split'],
        'for_clarification' => ['label' => 'For Clarification', 'badge' => 'purple-light', 'icon' => 'bi-question-circle'],
        'approved'          => ['label' => 'Approved',          'badge' => 'purple',       'icon' => 'bi-hand-thumbs-up'],
        'declined'          => ['label' => 'Declined',          'badge' => 'danger',       'icon' => 'bi-x-circle'],
        'confirmed'         => ['label' => 'CONFIRMED',         'badge' => 'success',      'icon' => 'bi-calendar2-check'],
        'cancelled'         => ['label' => 'Cancelled',         'badge' => 'dark',         'icon' => 'bi-slash-circle'],
    ];

    /**
     * Calendar colours. Deliberately: only 'confirmed' is solid green — an
     * official reservation must not be mistakable for an approved one, which is
     * purple and reserves nothing (policy 3.6).
     */
    const STATUS_COLORS = [
        'draft'             => '#9aa0a6',
        'submitted'         => '#0dcaf0',
        'facilities_review' => '#0d6efd',
        'pending_minister'  => '#ffc107',
        'for_clarification' => '#99298e',
        'approved'          => '#51154b',
        'declined'          => '#dc3545',
        'confirmed'         => '#198754',
        'cancelled'         => '#495057',
    ];

    /** Statuses that still appear on the calendar at all. */
    const CALENDAR_STATUSES = ['submitted', 'facilities_review', 'pending_minister',
                               'for_clarification', 'approved', 'confirmed'];

    /** The only status that is an official reservation (policy 3.6 / 20). */
    const BLOCKING_STATUS = 'confirmed';

    /**
     * Statuses that are "live" but reserve nothing — a soft clash worth warning
     * Facilities about so they can spot a looming conflict early (policy 8/13).
     */
    const SOFT_STATUSES = ['submitted', 'facilities_review', 'pending_minister',
                           'for_clarification', 'approved'];

    /** Statuses waiting on an approver (stages 2, 3 and 4) — the "Awaiting Action" stat. */
    const APPROVER_DESK_STATUSES = ['submitted', 'facilities_review', 'pending_minister', 'approved'];

    const REQUEST_TYPES = [
        'facility_room'   => 'Facility / Room',
        'furniture'       => 'Furniture',
        'equipment'       => 'Equipment',
        'office_resource' => 'Office Resource',
    ];

    /**
     * EXPIRED — computed at read time, never stored.
     * A request still pending (any SOFT_STATUSES value) whose date_needed has
     * passed. Its real status is untouched, no history row is written, and
     * nothing is auto-cancelled: it is simply shown as Expired, kept out of
     * Active / Waiting-on-me / the menu badge / Awaiting Action, and listed
     * under Past. Facilities can still cancel it ("Close as expired").
     */
    const EXPIRED_META = ['label' => 'Expired', 'badge' => 'secondary', 'icon' => 'bi-hourglass-bottom'];

    /** The Requests view's list tabs, in display order. 'deleted' is admin-only. */
    const LIST_TABS = [
        'active'   => ['label' => 'Active',                 'icon' => 'bi-lightning-charge'],
        'waiting'  => ['label' => 'Waiting on me',          'icon' => 'bi-person-check'],
        'mine'     => ['label' => 'My Requests',            'icon' => 'bi-person'],
        'upcoming' => ['label' => 'Upcoming confirmed',     'icon' => 'bi-calendar2-check'],
        'past'     => ['label' => 'Past',                   'icon' => 'bi-clock-history'],
        'closed'   => ['label' => 'Cancelled & declined',   'icon' => 'bi-x-octagon'],
        'all'      => ['label' => 'All',                    'icon' => 'bi-list-ul'],
        'deleted'  => ['label' => 'Deleted',                'icon' => 'bi-trash3'],
    ];

    /** Weekly series cap (Repeat weekly). */
    const SERIES_MAX = 12;

    /** SQL truth of "expired" for alias fr. Uses only constants, so safe to inline. */
    private static function expiredSql() {
        return "(fr.status IN ('" . implode("','", self::SOFT_STATUSES) . "')"
             . " AND fr.date_needed IS NOT NULL AND fr.date_needed < CURDATE())";
    }

    // "Today" is always the DATABASE's CURDATE(): PHP runs in UTC here while
    // MariaDB uses the server's local time, so a PHP date('Y-m-d') could
    // disagree for part of the day. SELECT_BASE therefore returns is_expired
    // and date_passed computed in SQL, and the PHP side reads those flags.

    /**
     * WHERE fragment for one list tab (alias fr), already excluding deleted
     * rows except on the Deleted tab. Unknown tab = 'all'.
     */
    private static function tabSql($tab) {
        $soft    = "'" . implode("','", self::SOFT_STATUSES) . "'";
        $expired = self::expiredSql();
        switch ($tab) {
            case 'deleted':
                return "fr.is_deleted = 1";
            case 'active':
                return "fr.is_deleted = 0 AND ("
                     . "(fr.status IN ($soft) AND NOT $expired)"
                     . " OR (fr.status = 'confirmed' AND (fr.date_needed IS NULL OR fr.date_needed >= CURDATE())))";
            case 'upcoming':
                return "fr.is_deleted = 0 AND fr.status = 'confirmed' AND fr.date_needed >= CURDATE()";
            case 'past':
                return "fr.is_deleted = 0 AND fr.date_needed < CURDATE() AND fr.status IN ('confirmed', $soft)";
            case 'closed':
                return "fr.is_deleted = 0 AND fr.status IN ('cancelled','declined')";
            default: // all, mine, waiting — further narrowed by filters
                return "fr.is_deleted = 0";
        }
    }

    public static function statusMeta($status) {
        return self::STATUSES[$status] ?? ['label' => ucfirst((string)$status), 'badge' => 'secondary', 'icon' => 'bi-dot'];
    }

    public static function statusColor($status) {
        return self::STATUS_COLORS[$status] ?? '#6c757d';
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /** SELECT list shared by every read, so the facility name is always present. */
    private const SELECT_BASE =
        "SELECT fr.*, f.name AS facility_name, f.facility_type, f.default_lead_days,
                ra.username AS fac_reviewed_by_name, ma.username AS minister_by_name,
                ca.username AS confirmed_by_name, da.username AS deleted_by_name,
                sa.username AS submitted_by_name,
                (fr.status IN ('submitted','facilities_review','pending_minister','for_clarification','approved')
                 AND fr.date_needed IS NOT NULL AND fr.date_needed < CURDATE()) AS is_expired,
                (fr.date_needed IS NOT NULL AND fr.date_needed < CURDATE()) AS date_passed
           FROM facility_requests fr
           LEFT JOIN facilities f ON f.id = fr.facility_id
           LEFT JOIN accounts ra ON ra.id = fr.fac_reviewed_by
           LEFT JOIN accounts ma ON ma.id = fr.minister_by
           LEFT JOIN accounts ca ON ca.id = fr.confirmed_by
           LEFT JOIN accounts da ON da.id = fr.deleted_by
           LEFT JOIN accounts sa ON sa.id = fr.submitted_by";

    /**
     * Request list for the table view. Every filter is optional; with no filters
     * and an empty table this returns [] — which the view renders as an empty
     * state, never a notice.
     *
     * $filters['tab'] picks one of LIST_TABS (see tabSql); soft-deleted rows
     * are excluded on every tab except 'deleted'. 'submitted_by' restricts to
     * one owner (My Requests, a requestor's Waiting-on-me); 'exclude_expired'
     * drops read-time-expired rows (Waiting-on-me).
     */
    public function getAllRequests($filters = []) {
        try {
            $tab    = $filters['tab'] ?? 'all';
            $sql    = self::SELECT_BASE . " WHERE " . self::tabSql($tab);
            $params = [];

            $sql .= $this->filterClause($filters, $params);

            // Forward-looking lists read soonest first; archives newest first.
            if (in_array($tab, ['active', 'upcoming', 'waiting'], true)) {
                $sql .= " ORDER BY (fr.date_needed IS NULL) ASC, fr.date_needed ASC, fr.start_time ASC, fr.id ASC";
            } elseif ($tab === 'deleted') {
                $sql .= " ORDER BY fr.deleted_at DESC, fr.id DESC";
            } else {
                $sql .= " ORDER BY fr.date_needed DESC, fr.start_time ASC, fr.id DESC";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Get all facility requests error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * The optional list filters (owner, expiry, status, room, ministry, date
     * range, search) as AND-ed SQL for alias fr. Shared by getAllRequests() and
     * tabCounts(), so a tab's badge always counts exactly what the tab lists.
     */
    private function filterClause(array $filters, array &$params) {
        $sql = '';
        if (isset($filters['submitted_by']) && $filters['submitted_by'] !== null) {
            $sql .= " AND fr.submitted_by = ?";
            $params[] = (int)$filters['submitted_by'];
        }
        if (!empty($filters['exclude_expired'])) {
            $sql .= " AND NOT " . self::expiredSql();
        }

        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $statuses = array_values(array_intersect($statuses, array_keys(self::STATUSES)));
            if ($statuses) {
                $sql .= " AND fr.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
                $params = array_merge($params, $statuses);
            }
        }
        if (!empty($filters['facility_id'])) {
            $sql .= " AND fr.facility_id = ?";
            $params[] = (int)$filters['facility_id'];
        }
        if (!empty($filters['ministry'])) {
            $sql .= " AND fr.ministry = ?";
            $params[] = $filters['ministry'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND fr.date_needed >= ?";
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND fr.date_needed <= ?";
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (fr.event_activity LIKE ? OR fr.requestor_name LIKE ? OR fr.ministry LIKE ?)";
            $like = '%' . $filters['search'] . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        return $sql;
    }

    public function getRequestById($id) {
        try {
            $stmt = $this->db->prepare(self::SELECT_BASE . " WHERE fr.id = ?");
            $stmt->execute([(int)$id]);
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Get facility request by ID error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calendar feed for a date window. Cancelled / declined / draft rows are
     * excluded — the calendar is the official schedule, not an archive.
     *
     * @param string $from  Y-m-d inclusive
     * @param string $to    Y-m-d inclusive
     * @param array  $facilityIds  Empty = all facilities, including NULL-facility requests.
     */
    public function getCalendarRequests($from, $to, $facilityIds = []) {
        try {
            $sql = self::SELECT_BASE
                 . " WHERE fr.is_deleted = 0
                       AND fr.date_needed IS NOT NULL
                       AND fr.date_needed BETWEEN ? AND ?
                       AND fr.status IN (" . implode(',', array_fill(0, count(self::CALENDAR_STATUSES), '?')) . ")";
            $params = array_merge([$from, $to], self::CALENDAR_STATUSES);

            $facilityIds = array_values(array_filter(array_map('intval', (array)$facilityIds)));
            if ($facilityIds) {
                $sql .= " AND fr.facility_id IN (" . implode(',', array_fill(0, count($facilityIds), '?')) . ")";
                $params = array_merge($params, $facilityIds);
            }
            $sql .= " ORDER BY fr.date_needed ASC, fr.start_time ASC, fr.id ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Get calendar requests error: " . $e->getMessage());
            return [];
        }
    }

    /** Dashboard-style counts. Always returns every key, so a view never hits an undefined index. */
    public function getStats() {
        $out = ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'upcoming' => 0, 'needs_action' => 0,
                'waiting_requestor' => 0, 'expired' => 0];
        foreach (array_keys(self::STATUSES) as $s) $out[$s] = 0;
        try {
            // Soft-deleted rows never count. "Live" = not deleted AND not
            // read-time-expired; only live rows count as pending / on a desk.
            $live = [];
            foreach ($this->db->query(
                "SELECT fr.status, " . self::expiredSql() . " AS expired, COUNT(*) c
                   FROM facility_requests fr WHERE fr.is_deleted = 0
                  GROUP BY fr.status, expired"
            ) as $row) {
                $c = (int)$row['c'];
                $out[$row['status']] += $c;
                $out['total'] += $c;
                if ((int)$row['expired']) $out['expired'] += $c;
                else $live[$row['status']] = ($live[$row['status']] ?? 0) + $c;
            }
            // "Pending" = anything still moving through the workflow (and not expired).
            foreach (self::SOFT_STATUSES as $s) $out['pending'] += $live[$s] ?? 0;
            // "Needs action" = sitting on an approver's desk right now: Facilities
            // review (submitted / facilities_review), the Minister
            // (pending_minister), or Facilities confirmation (approved). An
            // expired request is no longer actionable, so it is left out.
            foreach (self::APPROVER_DESK_STATUSES as $s) $out['needs_action'] += $live[$s] ?? 0;
            // for_clarification is the requestor's turn — counted, not hidden.
            $out['waiting_requestor'] = $live['for_clarification'] ?? 0;

            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM facility_requests
                  WHERE is_deleted = 0 AND status = ? AND date_needed >= CURDATE()"
            );
            $stmt->execute([self::BLOCKING_STATUS]);
            $out['upcoming'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Facility request stats error: " . $e->getMessage());
        }
        return $out;
    }

    /** Distinct ministries that have ever filed a request — for the filter dropdown. */
    public function getDistinctMinistries() {
        try {
            return $this->db->query(
                "SELECT DISTINCT TRIM(ministry) AS ministry FROM facility_requests
                  WHERE is_deleted = 0 AND TRIM(ministry) <> '' ORDER BY ministry ASC"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            error_log("Facility request ministries error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * One COUNT over the given statuses — the menu's "waiting on me" badge.
     * Cheap: a single indexed aggregate, 0 for an empty list or any error.
     */
    public function countByStatuses(array $statuses, $submittedBy = null) {
        $statuses = array_values(array_intersect($statuses, array_keys(self::STATUSES)));
        if (!$statuses) return 0;
        try {
            // Deleted and read-time-expired requests are on nobody's desk.
            $sql = "SELECT COUNT(*) FROM facility_requests fr
                     WHERE fr.is_deleted = 0 AND NOT " . self::expiredSql() . "
                       AND fr.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
            $params = $statuses;
            if ($submittedBy !== null) { $sql .= " AND fr.submitted_by = ?"; $params[] = (int)$submittedBy; }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Facility request count error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * One count per list tab, honouring the same filters the list uses, in a
     * single aggregate. 'waiting' is filled in by the caller (it depends on the
     * role) and 'mine' needs the viewer's account id.
     */
    public function tabCounts(array $filters = [], $userId = null) {
        $out = array_fill_keys(array_keys(self::LIST_TABS), 0);
        try {
            $params = [];
            $where  = $this->filterClause($filters, $params);
            $sum = function ($tab) { return "SUM(CASE WHEN " . self::tabSql($tab) . " THEN 1 ELSE 0 END)"; };
            $sql = "SELECT {$sum('active')} AS active, {$sum('upcoming')} AS upcoming, {$sum('past')} AS past,
                           {$sum('closed')} AS closed, {$sum('all')} AS `all`, {$sum('deleted')} AS deleted,
                           SUM(CASE WHEN fr.is_deleted = 0 AND fr.submitted_by = ? THEN 1 ELSE 0 END) AS mine
                      FROM facility_requests fr WHERE 1=1" . $where;
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([(int)($userId ?? 0)], $params));
            foreach (($stmt->fetch() ?: []) as $k => $v) {
                if (isset($out[$k])) $out[$k] = (int)$v;
            }
        } catch (PDOException $e) {
            error_log("Facility request tab counts error: " . $e->getMessage());
        }
        return $out;
    }

    /**
     * The other requests of a weekly series, in date order, deleted ones left
     * out. Returns [] for a request that is not part of a series.
     */
    public function getSeries($seriesId) {
        $seriesId = trim((string)$seriesId);
        if ($seriesId === '') return [];
        try {
            $stmt = $this->db->prepare(
                "SELECT id, date_needed, status, event_activity FROM facility_requests
                  WHERE series_id = ? AND is_deleted = 0
                  ORDER BY date_needed ASC, id ASC"
            );
            $stmt->execute([$seriesId]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Facility series error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Weekly setup sheet: CONFIRMED, non-deleted bookings in [from, to], in
     * day -> room -> start order. Only confirmed is an official reservation
     * (policy 3.6 / 20), so nothing else is set up for.
     */
    public function getSetupSheet($from, $to) {
        try {
            $stmt = $this->db->prepare(
                self::SELECT_BASE . "
                  WHERE fr.is_deleted = 0 AND fr.status = ?
                    AND fr.date_needed BETWEEN ? AND ?
                  ORDER BY fr.date_needed ASC, (f.name IS NULL) ASC, f.name ASC,
                           (fr.start_time IS NULL) ASC, fr.start_time ASC, fr.id ASC"
            );
            $stmt->execute([self::BLOCKING_STATUS, $from, $to]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Facility setup sheet error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Active accounts that hold a workflow stage, for the Approvers panel.
     * Keyed by account type; takes the role values from the caller so the
     * role list itself stays in config/permissions.php.
     */
    public function getApprovers(array $accountTypes) {
        $out = array_fill_keys($accountTypes, []);
        if (!$accountTypes) return $out;
        try {
            $stmt = $this->db->prepare(
                "SELECT id, username, email, accounttype FROM accounts
                  WHERE accountstatus = 'active' AND accounttype IN ("
                . implode(',', array_fill(0, count($accountTypes), '?')) . ")
                  ORDER BY username ASC"
            );
            $stmt->execute(array_values($accountTypes));
            foreach ($stmt->fetchAll() ?: [] as $row) $out[$row['accounttype']][] = $row;
        } catch (PDOException $e) {
            error_log("Facility approvers error: " . $e->getMessage());
        }
        return $out;
    }

    // ── Stage progress (4-step stepper) ──────────────────────────────────────

    /** The four policy stages, in order, with the history actions that complete each. */
    const STAGES = [
        'request'  => ['label' => 'Request',           'actions' => ['submitted']],
        'review'   => ['label' => 'Facilities Review', 'actions' => ['facilities_reviewed']],
        'minister' => ['label' => 'Minister',          'actions' => ['approved', 'declined', 'clarification_requested']],
        'confirm'  => ['label' => 'Confirmed',         'actions' => ['confirmed']],
    ];

    /**
     * Who acted / when, per request, from the append-only history — one query
     * for a whole list of ids. Returns [request_id => [action => latest row]],
     * plus '_cancel_from' => from_status of the latest cancellation.
     */
    public function stageEventsFor(array $ids) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        if (!$ids) return $out;
        try {
            $stmt = $this->db->prepare(
                "SELECT frh.request_id, frh.action, frh.from_status, frh.notes, frh.performed_at,
                        a.username AS performed_by_name
                   FROM facility_request_history frh
                   LEFT JOIN accounts a ON a.id = frh.performed_by
                  WHERE frh.request_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
                  ORDER BY frh.performed_at ASC, frh.id ASC"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $rid = (int)$row['request_id'];
                // Ascending order, so the latest row for each action wins.
                $out[$rid][$row['action']] = $row;
                if ($row['action'] === 'cancelled') $out[$rid]['_cancel_from'] = $row['from_status'];
            }
        } catch (PDOException $e) {
            error_log("Facility stage events error: " . $e->getMessage());
        }
        return $out;
    }

    /**
     * Build the four stepper steps for one request.
     *
     * Each step: key, label, state (done|current|upcoming|declined|clarify|
     * cancelled|skipped), who, when, note. Derived from status (where the
     * request is) plus history (who did each completed step). A cancelled
     * request is drawn as it stood when cancelled, with that step marked.
     */
    public static function stepper($status, array $events = []) {
        $position = [
            'draft' => 0, 'submitted' => 1, 'facilities_review' => 1,
            'pending_minister' => 2, 'for_clarification' => 2, 'approved' => 3,
            'declined' => 2, 'confirmed' => 4,
        ];
        $effective = $status;
        if ($status === 'cancelled') {
            $effective = $events['_cancel_from'] ?? 'submitted';
            if ($effective === 'cancelled' || !isset($position[$effective])) $effective = 'submitted';
        }
        $cur = $position[$effective] ?? 1;

        $steps = [];
        $i = 0;
        foreach (self::STAGES as $key => $stage) {
            $ev = null;
            foreach ($stage['actions'] as $a) {
                if (isset($events[$a]) && (!$ev || $events[$a]['performed_at'] >= $ev['performed_at'])) $ev = $events[$a];
            }
            if ($i < $cur)       $state = 'done';
            elseif ($i === $cur) $state = 'current';
            else                 $state = 'upcoming';

            if ($key === 'minister' && $effective === 'declined')          $state = 'declined';
            if ($key === 'minister' && $effective === 'for_clarification') $state = 'clarify';
            if ($effective === 'declined' && $key === 'confirm')           $state = 'skipped';
            if ($status === 'cancelled') {
                if ($i === $cur || ($cur === 4 && $i === 3)) $state = ($cur === 4) ? 'done' : 'cancelled';
                elseif ($i > $cur) $state = 'skipped';
            }

            $showWho = in_array($state, ['done', 'declined', 'clarify'], true) && $ev;
            $steps[] = [
                'key'   => $key,
                'label' => $stage['label'],
                'state' => $state,
                'who'   => $showWho ? ($ev['performed_by_name'] ?: 'Unknown') : null,
                'when'  => $showWho ? $ev['performed_at'] : null,
                'note'  => $showWho ? $ev['notes'] : null,
            ];
            $i++;
        }
        return $steps;
    }

    // ── Portal form validation ───────────────────────────────────────────────

    /**
     * Rules for a request typed into the portal form (the importer of paper
     * forms does not go through here — historical forms are taken as written).
     * Returns '' when valid, else the message to show. Lead time is NOT checked
     * here: it warns, never blocks (policy section 11).
     *
     *   - at least one type of request
     *   - a date is required
     *   - Facility / Room => a room AND start + end times are required
     *   - when both times are given, end must be after start
     */
    public static function validatePortalForm(array $d) {
        $types = $d['request_type'] ?? [];
        if (!is_array($types)) $types = array_filter(array_map('trim', explode(',', (string)$types)));
        $types = array_values(array_intersect($types, array_keys(self::REQUEST_TYPES)));
        if (!$types) return 'Choose at least one type of request (Facility / Room, Furniture, Equipment or Office Resource).';

        $date  = trim((string)($d['date_needed'] ?? ''));
        $start = trim((string)($d['start_time'] ?? ''));
        $end   = trim((string)($d['end_time'] ?? ''));
        if ($date === '' || strtotime($date) === false) return 'Date needed is required.';

        if (in_array('facility_room', $types, true)) {
            if ((int)($d['facility_id'] ?? 0) <= 0) return 'A Facility / Room request needs a room. Choose the room, or untick "Facility / Room" for an equipment-only request.';
            if ($start === '' || $end === '')      return 'A Facility / Room request needs a start time and an end time.';
        }
        if ($start !== '' && $end !== '') {
            $s = strtotime('2000-01-01 ' . $start);
            $e = strtotime('2000-01-01 ' . $end);
            if ($s === false || $e === false) return 'Enter valid start and end times.';
            if ($e <= $s) return 'End time must be after the start time.';
        }
        return '';
    }

    // ── Conflict detection ───────────────────────────────────────────────────

    /**
     * Derive the indexed [starts_at, ends_at) pair from the form's date + times.
     *
     * Returns [null, null] when there is not enough information — a request with
     * no date, or no times, occupies no interval and can therefore never
     * conflict. An end time at or before the start time is treated as crossing
     * midnight (a 22:00-01:00 booking), which is the only reading that does not
     * silently produce a negative-length interval.
     */
    public static function deriveInterval($dateNeeded, $startTime, $endTime) {
        $dateNeeded = trim((string)$dateNeeded);
        $startTime  = trim((string)$startTime);
        $endTime    = trim((string)$endTime);
        if ($dateNeeded === '' || $startTime === '' || $endTime === '') return [null, null];

        $startTs = strtotime($dateNeeded . ' ' . $startTime);
        $endTs   = strtotime($dateNeeded . ' ' . $endTime);
        if ($startTs === false || $endTs === false) return [null, null];
        if ($endTs <= $startTs) $endTs = strtotime('+1 day', $endTs);

        return [date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)];
    }

    /**
     * Overlap check on one facility, over a half-open [starts_at, ends_at)
     * interval: rows overlap when existing.starts_at < new.ends_at AND
     * existing.ends_at > new.starts_at. Back-to-back bookings (10-11, 11-12)
     * therefore do NOT conflict.
     *
     * Returns ['hard' => [...], 'soft' => [...]]:
     *   hard — overlapping CONFIRMED reservations. These are the only official
     *          reservations (policy 3.6 / 20) and the only real blocker.
     *   soft — overlapping requests still in the workflow (submitted through
     *          approved). They reserve nothing, but Facilities needs to see a
     *          looming clash to refer it to the Minister (policy 13).
     *
     * Skipped entirely — returns two empty arrays — when facility_id is NULL or
     * the interval could not be derived. An equipment-only request occupies no
     * room, and the church's own live examples are exactly that.
     *
     * @param int|null $excludeId  Request being edited, so it never conflicts with itself.
     */
    public function conflictsFor($facilityId, $startsAt, $endsAt, $excludeId = null) {
        $empty = ['hard' => [], 'soft' => []];
        $facilityId = (int)$facilityId;
        if ($facilityId <= 0 || empty($startsAt) || empty($endsAt)) return $empty;

        try {
            $sql = "SELECT fr.id, fr.uuid, fr.event_activity, fr.ministry, fr.requestor_name,
                           fr.status, fr.date_needed, fr.start_time, fr.end_time,
                           fr.starts_at, fr.ends_at, f.name AS facility_name
                      FROM facility_requests fr
                      LEFT JOIN facilities f ON f.id = fr.facility_id
                     WHERE fr.facility_id = ?
                       AND fr.is_deleted = 0
                       AND fr.starts_at IS NOT NULL AND fr.ends_at IS NOT NULL
                       AND fr.starts_at < ? AND fr.ends_at > ?
                       AND fr.status IN (" . implode(',', array_fill(0, count(self::CALENDAR_STATUSES), '?')) . ")";
            $params = array_merge([$facilityId, $endsAt, $startsAt], self::CALENDAR_STATUSES);

            if ($excludeId) {
                $sql .= " AND fr.id <> ?";
                $params[] = (int)$excludeId;
            }
            $sql .= " ORDER BY fr.starts_at ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];

            $out = $empty;
            foreach ($rows as $row) {
                // ONLY 'confirmed' is an official reservation. An approved but
                // unconfirmed request lands in 'soft' and blocks nothing.
                $out[$row['status'] === self::BLOCKING_STATUS ? 'hard' : 'soft'][] = $row;
            }
            return $out;
        } catch (PDOException $e) {
            error_log("conflictsFor error: " . $e->getMessage());
            return $empty;
        }
    }

    /** Conflicts for an existing request, recomputed from its stored interval. */
    public function conflictsForRequest(array $request) {
        return $this->conflictsFor(
            $request['facility_id'] ?? null,
            $request['starts_at'] ?? null,
            $request['ends_at'] ?? null,
            (int)($request['id'] ?? 0) ?: null
        );
    }

    // ── Lead time (policy sections 10 + 11) ──────────────────────────────────

    /**
     * Compare a requested date against the facility's recommended lead time.
     *
     * ADVISORY ONLY. Section 11 explicitly allows last-minute requests, so this
     * never blocks: it returns the numbers and a message for the UI to show
     * beside a "submit anyway" button.
     *
     * @return array{warn:bool,lead_days:int,required:int,message:string}
     */
    public function leadTimeCheck($facilityId, $dateNeeded, $submittedAt = null) {
        $required = $this->facilityModel->leadDaysFor($facilityId);
        $out = ['warn' => false, 'lead_days' => 0, 'required' => $required, 'message' => ''];

        $dateNeeded = trim((string)$dateNeeded);
        if ($dateNeeded === '') return $out;

        $needTs = strtotime($dateNeeded);
        if ($needTs === false) return $out;

        $baseTs = $submittedAt ? strtotime($submittedAt) : time();
        if ($baseTs === false) $baseTs = time();

        // Whole days between the two calendar dates, ignoring clock time.
        $leadDays = (int)floor((strtotime(date('Y-m-d', $needTs)) - strtotime(date('Y-m-d', $baseTs))) / 86400);
        $out['lead_days'] = $leadDays;

        if ($leadDays < $required) {
            $out['warn'] = true;
            $facility = $this->facilityModel->getFacilityById($facilityId);
            $what = $facility ? $facility['name'] : 'this type of request';
            if ($leadDays < 0) {
                $out['message'] = 'The date needed is in the past.';
            } else {
                $out['message'] = sprintf(
                    'Short notice: %s day%s of lead time. The recommended minimum for %s is %d day%s (policy section 10). '
                  . 'Last-minute requests may still be considered (policy section 11) — this is a warning, not a block.',
                    $leadDays, $leadDays === 1 ? '' : 's', $what, $required, $required === 1 ? '' : 's'
                );
            }
        }
        return $out;
    }

    // ── Step 1: Requestor submits (policy section 5 step 1, form section 7) ──

    /**
     * Create a request from the section 7 A-F form.
     *
     * Records lead_days_actual / lead_time_warning but never refuses on them,
     * and appends the creation history row in the same transaction so a request
     * can never exist without a trail.
     *
     * @return array{ok:bool,id:int,error:string,lead:array}
     */
    /**
     * @param int|null    $submittedBy  accounts.id of the portal submitter (the
     *                                  owner). NULL for imports: the importing
     *                                  admin is not the requestor.
     * @param string|null $seriesId     Shared id of a Repeat weekly series.
     * @param string|null $historyNote  Extra note for the 'submitted' history row.
     */
    public function submit(array $data, $performedBy = null, $submittedBy = null, $seriesId = null, $historyNote = null) {
        $fields = $this->normalize($data);
        if ($fields['event_activity'] === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'Event / Activity is required.', 'lead' => []];
        }
        if ($fields['requestor_name'] === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'Requestor name is required.', 'lead' => []];
        }

        $lead   = $this->leadTimeCheck($fields['facility_id'], $fields['date_needed']);
        $status = 'submitted';

        try {
            $this->db->beginTransaction();

            $sql = "INSERT INTO facility_requests
                      (uuid, requestor_member_id, requestor_name, ministry, ministry_leader,
                       contact_number, email,
                       request_type, event_activity, purpose, date_needed, start_time, end_time,
                       expected_attendance, starts_at, ends_at,
                       facility_id, preferred_setup, num_chairs, num_tables, other_requirements,
                       equipment_requested, equipment_quantity, person_responsible,
                       technical_assistance, technical_assistance_details,
                       needs_setup, needs_technical, needs_security, needs_cleaning, needs_other,
                       additional_details,
                       acknowledged, acknowledged_name, acknowledged_at,
                       status, lead_days_actual, lead_time_warning, source, source_label,
                       submitted_by, series_id, submitted_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $this->generateUUID(),
                $fields['requestor_member_id'], $fields['requestor_name'], $fields['ministry'],
                $fields['ministry_leader'], $fields['contact_number'], $fields['email'],
                $fields['request_type'], $fields['event_activity'], $fields['purpose'],
                $fields['date_needed'], $fields['start_time'], $fields['end_time'],
                $fields['expected_attendance'], $fields['starts_at'], $fields['ends_at'],
                $fields['facility_id'], $fields['preferred_setup'], $fields['num_chairs'],
                $fields['num_tables'], $fields['other_requirements'],
                $fields['equipment_requested'], $fields['equipment_quantity'], $fields['person_responsible'],
                $fields['technical_assistance'], $fields['technical_assistance_details'],
                $fields['needs_setup'], $fields['needs_technical'], $fields['needs_security'],
                $fields['needs_cleaning'], $fields['needs_other'], $fields['additional_details'],
                $fields['acknowledged'], $fields['acknowledged_name'], $fields['acknowledged_at'],
                $status, $lead['lead_days'], $lead['warn'] ? 1 : 0, $fields['source'], $fields['source_label'],
                ((int)$submittedBy) ?: null,
                ($seriesId !== null && $seriesId !== '') ? mb_substr((string)$seriesId, 0, 36) : null,
            ]);

            $id = (int)$this->db->lastInsertId();
            $note = trim(($historyNote ? $historyNote . ' ' : '')
                  . ($lead['warn'] ? 'Submitted with a lead-time warning: ' . $lead['message'] : ''));
            $this->logHistory($id, 'submitted', null, $status, $note !== '' ? $note : null, $performedBy);

            $this->db->commit();
            return ['ok' => true, 'id' => $id, 'error' => '', 'lead' => $lead];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Facility request submit error: " . $e->getMessage());
            return ['ok' => false, 'id' => 0, 'error' => 'Could not save the request.', 'lead' => $lead];
        }
    }

    /**
     * Edit the requestor-facing fields of a request that has not been confirmed.
     *
     * Re-derives the interval and the lead-time flag, and logs an 'edited' row
     * (status unchanged, so from_status === to_status). A confirmed reservation
     * is deliberately not editable here: policy section 18 wants a cancel and a
     * fresh review rather than a silent change under an approved booking.
     */
    /** Statuses a request may still be edited in: it is still with the requestor. */
    const EDITABLE_STATUSES = ['draft', 'submitted', 'for_clarification'];

    /** Labels for the "what changed" note on an edited / resubmitted history row. */
    private const CHANGE_LABELS = [
        'requestor_name' => 'requestor', 'ministry' => 'ministry', 'ministry_leader' => 'ministry leader',
        'contact_number' => 'contact number', 'email' => 'email', 'request_type' => 'type of request',
        'event_activity' => 'event / activity', 'purpose' => 'purpose', 'date_needed' => 'date',
        'start_time' => 'start time', 'end_time' => 'end time', 'expected_attendance' => 'expected attendance',
        'facility_id' => 'room', 'preferred_setup' => 'preferred setup', 'num_chairs' => 'chairs',
        'num_tables' => 'tables', 'other_requirements' => 'other requirements',
        'equipment_requested' => 'equipment', 'equipment_quantity' => 'quantity',
        'person_responsible' => 'person responsible', 'technical_assistance' => 'technical assistance',
        'technical_assistance_details' => 'technical details', 'needs_setup' => 'setup assistance',
        'needs_technical' => 'technical assistance (E)', 'needs_security' => 'security / access',
        'needs_cleaning' => 'cleaning', 'needs_other' => 'other (E)', 'additional_details' => 'additional details',
    ];

    /** Human list of the fields that differ between the stored row and the normalised edit. */
    private static function changedFields(array $current, array $fields) {
        $out = [];
        foreach (self::CHANGE_LABELS as $col => $label) {
            $a = $current[$col] ?? null; $b = $fields[$col] ?? null;
            // TIME columns come back as HH:MM:SS; the form sends HH:MM.
            if (in_array($col, ['start_time', 'end_time'], true)) {
                $a = $a !== null ? substr((string)$a, 0, 5) : null;
                $b = $b !== null ? substr((string)$b, 0, 5) : null;
            }
            if ((string)$a !== (string)$b) $out[] = $label;
        }
        return $out;
    }

    /**
     * Edit a request that is still with the requestor (EDITABLE_STATUSES).
     *
     * From 'for_clarification' this is a RESUBMISSION: the status moves back
     * to 'submitted' (Facilities reviews it again) and the history row is
     * 'resubmitted', listing what changed. From 'submitted' it is a plain
     * 'edited' row. After Facilities has picked a request up it can no longer
     * be edited, and a confirmed reservation never (policy section 18).
     */
    public function updateRequest($id, array $data, $performedBy = null) {
        $id      = (int)$id;
        $current = $this->getRequestById($id);
        if (!$current || !empty($current['is_deleted'])) return ['ok' => false, 'error' => 'Request not found.'];
        if ($current['status'] === self::BLOCKING_STATUS) {
            return ['ok' => false, 'error' => 'A confirmed reservation cannot be edited. Cancel it and submit a new request (policy section 18).'];
        }
        if (!in_array($current['status'], self::EDITABLE_STATUSES, true)) {
            return ['ok' => false, 'error' => 'This request is already with Facilities or the Minister and can no longer be edited.'];
        }
        $resubmit  = ($current['status'] === 'for_clarification');
        $newStatus = $resubmit ? 'submitted' : $current['status'];

        $fields = $this->normalize($data);
        if ($fields['event_activity'] === '') return ['ok' => false, 'error' => 'Event / Activity is required.'];
        if ($fields['requestor_name'] === '') return ['ok' => false, 'error' => 'Requestor name is required.'];

        $lead = $this->leadTimeCheck($fields['facility_id'], $fields['date_needed'], $current['submitted_at'] ?? null);

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "UPDATE facility_requests SET
                    requestor_member_id = ?, requestor_name = ?, ministry = ?, ministry_leader = ?,
                    contact_number = ?, email = ?,
                    request_type = ?, event_activity = ?, purpose = ?, date_needed = ?,
                    start_time = ?, end_time = ?, expected_attendance = ?, starts_at = ?, ends_at = ?,
                    facility_id = ?, preferred_setup = ?, num_chairs = ?, num_tables = ?, other_requirements = ?,
                    equipment_requested = ?, equipment_quantity = ?, person_responsible = ?,
                    technical_assistance = ?, technical_assistance_details = ?,
                    needs_setup = ?, needs_technical = ?, needs_security = ?, needs_cleaning = ?, needs_other = ?,
                    additional_details = ?, acknowledged = ?, acknowledged_name = ?,
                    lead_days_actual = ?, lead_time_warning = ?, status = ?
                  WHERE id = ?"
            );
            $stmt->execute([
                $fields['requestor_member_id'], $fields['requestor_name'], $fields['ministry'],
                $fields['ministry_leader'], $fields['contact_number'], $fields['email'],
                $fields['request_type'], $fields['event_activity'], $fields['purpose'],
                $fields['date_needed'], $fields['start_time'], $fields['end_time'],
                $fields['expected_attendance'], $fields['starts_at'], $fields['ends_at'],
                $fields['facility_id'], $fields['preferred_setup'], $fields['num_chairs'],
                $fields['num_tables'], $fields['other_requirements'],
                $fields['equipment_requested'], $fields['equipment_quantity'], $fields['person_responsible'],
                $fields['technical_assistance'], $fields['technical_assistance_details'],
                $fields['needs_setup'], $fields['needs_technical'], $fields['needs_security'],
                $fields['needs_cleaning'], $fields['needs_other'], $fields['additional_details'],
                $fields['acknowledged'], $fields['acknowledged_name'],
                $lead['lead_days'], $lead['warn'] ? 1 : 0, $newStatus,
                $id,
            ]);

            $changed = self::changedFields($current, $fields);
            $what    = $changed ? 'Changed: ' . implode(', ', $changed) . '.' : 'No details changed.';
            if ($resubmit) {
                $this->logHistory($id, 'resubmitted', $current['status'], $newStatus,
                    'Resubmitted by the requestor after clarification. ' . $what, $performedBy);
            } else {
                $this->logHistory($id, 'edited', $current['status'], $newStatus,
                    'Request details edited. ' . $what, $performedBy);
            }

            $this->db->commit();
            return ['ok' => true, 'error' => '', 'lead' => $lead, 'resubmitted' => $resubmit];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Facility request update error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not update the request.'];
        }
    }

    /**
     * Coerce the raw form POST into exactly the columns the table has, with the
     * derived interval computed here rather than trusted from the client.
     * Empty strings become NULL so DATE / TIME / INT columns never receive ''.
     */
    private function normalize(array $d) {
        $str = function ($k, $max = null) use ($d) {
            $v = trim((string)($d[$k] ?? ''));
            return ($max && $v !== '') ? mb_substr($v, 0, $max) : $v;
        };
        $nullable = function ($v) { return $v === '' ? null : $v; };
        $intOrNull = function ($k) use ($d) {
            $v = trim((string)($d[$k] ?? ''));
            return ($v === '' || !is_numeric($v)) ? null : (int)$v;
        };

        // SET column: section 4 allows more than one category per request.
        $types = $d['request_type'] ?? [];
        if (!is_array($types)) $types = array_filter(array_map('trim', explode(',', (string)$types)));
        $types = array_values(array_intersect($types, array_keys(self::REQUEST_TYPES)));
        if (!$types) $types = ['facility_room'];

        $dateNeeded = $nullable($str('date_needed'));
        $startTime  = $nullable($str('start_time'));
        $endTime    = $nullable($str('end_time'));
        list($startsAt, $endsAt) = self::deriveInterval($dateNeeded, $startTime, $endTime);

        $facilityId = $intOrNull('facility_id');

        $acknowledged = !empty($d['acknowledged']) ? 1 : 0;

        return [
            'requestor_member_id' => $intOrNull('requestor_member_id'),
            'requestor_name'      => $str('requestor_name', 255),
            'ministry'            => $str('ministry', 100),
            'ministry_leader'     => $nullable($str('ministry_leader', 255)),
            'contact_number'      => $nullable($str('contact_number', 100)),
            'email'               => $nullable($str('email', 255)),

            'request_type'        => implode(',', $types),
            'event_activity'      => $str('event_activity', 255),
            'purpose'             => $nullable($str('purpose')),
            'date_needed'         => $dateNeeded,
            'start_time'          => $startTime,
            'end_time'            => $endTime,
            'expected_attendance' => $intOrNull('expected_attendance'),
            'starts_at'           => $startsAt,
            'ends_at'             => $endsAt,

            'facility_id'         => $facilityId,
            'preferred_setup'     => $nullable($str('preferred_setup', 255)),
            'num_chairs'          => $intOrNull('num_chairs'),
            'num_tables'          => $intOrNull('num_tables'),
            'other_requirements'  => $nullable($str('other_requirements')),

            'equipment_requested' => $nullable($str('equipment_requested')),
            'equipment_quantity'  => $nullable($str('equipment_quantity', 100)),
            'person_responsible'  => $nullable($str('person_responsible', 255)),
            'technical_assistance' => (($d['technical_assistance'] ?? 'no') === 'yes') ? 'yes' : 'no',
            'technical_assistance_details' => $nullable($str('technical_assistance_details')),

            'needs_setup'       => !empty($d['needs_setup'])     ? 1 : 0,
            'needs_technical'   => !empty($d['needs_technical']) ? 1 : 0,
            'needs_security'    => !empty($d['needs_security'])  ? 1 : 0,
            'needs_cleaning'    => !empty($d['needs_cleaning'])  ? 1 : 0,
            'needs_other'       => !empty($d['needs_other'])     ? 1 : 0,
            'additional_details' => $nullable($str('additional_details')),

            'acknowledged'      => $acknowledged,
            'acknowledged_name' => $nullable($str('acknowledged_name', 255)) ?: ($acknowledged ? $str('requestor_name', 255) : null),
            'acknowledged_at'   => $acknowledged ? date('Y-m-d H:i:s') : null,

            'source'            => (($d['source'] ?? 'portal') === 'excel_import') ? 'excel_import' : 'portal',
            // Which sheet of the uploaded workbook this request came from. Set on
            // INSERT rather than UPDATEd afterwards, so an imported request has
            // never existed without its provenance attached.
            'source_label'      => $nullable($str('source_label', 255)),
        ];
    }

    // ── Step 2: Facilities review (policy section 8) ─────────────────────────

    /**
     * Facilities Personnel record the logistical assessment and hand the request
     * to the Minister. They explicitly do NOT approve (policy section 5 step 2 /
     * 6.2), so this can only ever move the request to 'pending_minister'.
     *
     * The schedule-conflict answer is defaulted from the live overlap check when
     * the reviewer leaves it blank, so the form agrees with the calendar.
     */
    public function facilitiesReview($id, array $data, $performedBy = null) {
        $id      = (int)$id;
        $current = $this->getRequestById($id);
        // A soft-deleted request is out of the workflow until an admin restores it.
        if (!$current || !empty($current['is_deleted'])) return ['ok' => false, 'error' => 'Request not found.'];
        if (!in_array($current['status'], ['submitted', 'facilities_review', 'for_clarification'], true)) {
            return ['ok' => false, 'error' => 'Only a submitted request (or one returned for clarification) can be reviewed by Facilities.'];
        }

        $conflicts = $this->conflictsForRequest($current);
        $available = (($data['fac_available'] ?? '') === 'no') ? 'no' : 'yes';

        $scheduleConflict = $data['fac_schedule_conflict'] ?? '';
        if ($scheduleConflict !== 'yes' && $scheduleConflict !== 'none') {
            $scheduleConflict = (!empty($conflicts['hard']) || !empty($conflicts['soft'])) ? 'yes' : 'none';
        }

        $enum = function ($v, array $allowed) { return in_array($v, $allowed, true) ? $v : null; };
        $notes = trim((string)($data['fac_other_considerations'] ?? ''));

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "UPDATE facility_requests SET
                    fac_available = ?, fac_schedule_conflict = ?, fac_setup_required = ?,
                    fac_technical_required = ?, fac_other_considerations = ?, fac_recommendation = ?,
                    fac_reviewed_by = ?, fac_reviewed_at = NOW(), status = 'pending_minister'
                  WHERE id = ?"
            );
            $stmt->execute([
                $available,
                $scheduleConflict,
                $enum($data['fac_setup_required'] ?? '', ['no', 'yes']) ?? ($current['needs_setup'] ? 'yes' : 'no'),
                $enum($data['fac_technical_required'] ?? '', ['no', 'yes']) ?? ($current['needs_technical'] ? 'yes' : 'no'),
                $notes !== '' ? $notes : null,
                $enum($data['fac_recommendation'] ?? '', ['no_concern', 'requires_adjustment', 'requires_discussion']),
                $performedBy ?: null,
                $id,
            ]);

            $trail = 'Facilities review: availability ' . $available . ', conflict ' . $scheduleConflict . '.';
            if (!empty($conflicts['hard'])) {
                $trail .= ' ' . count($conflicts['hard']) . ' confirmed reservation(s) overlap.';
            }
            if ($notes !== '') $trail .= ' ' . $notes;

            $this->logHistory($id, 'facilities_reviewed', $current['status'], 'pending_minister', $trail, $performedBy);
            $this->db->commit();
            return ['ok' => true, 'error' => '', 'conflicts' => $conflicts];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("facilitiesReview error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the Facilities review.'];
        }
    }

    // ── Step 3: Minister decision (policy section 9) ─────────────────────────

    /**
     * APPROVE / DECLINE / FOR CLARIFICATION.
     *
     * An approval moves the request to 'approved' and nothing more: it is still
     * not an official reservation and still does not block the room. That only
     * happens at step 4 (policy 3.6 / 20).
     */
    /**
     * @param bool $override  True when an Admin (not the Minister) records the
     *                        decision. The history row then says so, e.g.
     *                        "Approved by Admin (override)", so the audit trail
     *                        shows in which capacity the step was taken. The
     *                        minister_comments column keeps the comments as typed.
     */
    public function ministerDecision($id, $decision, $comments = null, $performedBy = null, $override = false) {
        $id      = (int)$id;
        $current = $this->getRequestById($id);
        // A soft-deleted request is out of the workflow until an admin restores it.
        if (!$current || !empty($current['is_deleted'])) return ['ok' => false, 'error' => 'Request not found.'];
        if (!in_array($decision, ['approved', 'declined', 'for_clarification'], true)) {
            return ['ok' => false, 'error' => 'Invalid decision.'];
        }
        if (!in_array($current['status'], ['pending_minister', 'for_clarification', 'approved'], true)) {
            return ['ok' => false, 'error' => 'This request has not reached the Minister yet. Facilities must review it first (policy section 5).'];
        }
        if ($current['status'] === 'approved' && $decision === 'approved') {
            return ['ok' => false, 'error' => 'This request is already approved.'];
        }

        $comments = trim((string)$comments);
        $comments = $comments !== '' ? $comments : null;

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "UPDATE facility_requests SET
                    minister_decision = ?, minister_comments = ?, minister_by = ?, minister_at = NOW(),
                    status = ?
                  WHERE id = ?"
            );
            $stmt->execute([$decision, $comments, $performedBy ?: null, $decision, $id]);

            $action = ['approved' => 'approved', 'declined' => 'declined',
                       'for_clarification' => 'clarification_requested'][$decision];
            $verb = ['approved' => 'Approved', 'declined' => 'Declined',
                     'for_clarification' => 'Returned for clarification'][$decision];
            $capacity = $verb . ($override ? ' by Admin (override)' : ' by Minister') . '.';
            $this->logHistory($id, $action, $current['status'], $decision,
                $capacity . ($comments !== null ? ' ' . $comments : ''), $performedBy);

            $this->db->commit();
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("ministerDecision error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not record the Minister decision.'];
        }
    }

    // ── Step 4: Facilities confirmation (policy section 5 step 4 / 20) ───────

    /**
     * THE step that creates an official reservation and makes the booking block
     * the room. Reachable only from 'approved': Minister Approval ->
     * Facilities Confirmation -> Calendar Entry, in that order, always.
     *
     * A hard conflict against an already-confirmed reservation refuses the
     * confirmation. This is the one place the system says no outright, because
     * double-booking a room is the exact failure the whole policy exists to
     * prevent, and section 11 says a last-minute request never overrides a
     * previously confirmed reservation. The reviewer can still override
     * deliberately via $force, which is recorded in the history.
     */
    public function confirm($id, $notes = null, $performedBy = null, $force = false) {
        $id      = (int)$id;
        $current = $this->getRequestById($id);
        // A soft-deleted request is out of the workflow until an admin restores it.
        if (!$current || !empty($current['is_deleted'])) return ['ok' => false, 'error' => 'Request not found.'];
        if ($current['status'] !== 'approved') {
            return ['ok' => false, 'error' => 'Only a Minister-approved request can be confirmed (policy section 20).'];
        }

        $conflicts = $this->conflictsForRequest($current);
        if (!empty($conflicts['hard']) && !$force) {
            $names = array_map(function ($c) { return $c['event_activity']; }, $conflicts['hard']);
            return [
                'ok'    => false,
                'error' => 'This slot overlaps a confirmed reservation (' . implode('; ', $names)
                         . '). A new request does not override a confirmed reservation (policy section 11). '
                         . 'Refer the conflict to the Minister, or confirm anyway if the clash has been resolved.',
                'conflicts' => $conflicts,
                'blocked'   => true,
            ];
        }

        $notes = trim((string)$notes);
        $notes = $notes !== '' ? $notes : null;
        if (!empty($conflicts['hard']) && $force) {
            $notes = trim('CONFIRMED OVER AN EXISTING CONFIRMED RESERVATION. ' . (string)$notes);
        }

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "UPDATE facility_requests SET confirmed_by = ?, confirmed_at = NOW(), status = 'confirmed'
                  WHERE id = ?"
            );
            $stmt->execute([$performedBy ?: null, $id]);
            $this->logHistory($id, 'confirmed', $current['status'], 'confirmed', $notes, $performedBy);
            $this->db->commit();
            return ['ok' => true, 'error' => '', 'conflicts' => $conflicts];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("confirm error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not confirm the reservation.'];
        }
    }

    /**
     * Cancel (policy section 18). Frees the slot for other ministries without
     * deleting anything: the row stays, the history keeps the trail, and the
     * calendar stops showing it.
     */
    public function cancel($id, $notes = null, $performedBy = null) {
        $id      = (int)$id;
        $current = $this->getRequestById($id);
        // A soft-deleted request is out of the workflow until an admin restores it.
        if (!$current || !empty($current['is_deleted'])) return ['ok' => false, 'error' => 'Request not found.'];
        if ($current['status'] === 'cancelled') return ['ok' => false, 'error' => 'This request is already cancelled.'];

        $notes = trim((string)$notes);
        $notes = $notes !== '' ? $notes : null;

        try {
            $this->db->beginTransaction();
            $this->db->prepare("UPDATE facility_requests SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?")
                     ->execute([$id]);
            $this->logHistory($id, 'cancelled', $current['status'], 'cancelled', $notes, $performedBy);
            $this->db->commit();
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("cancel error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not cancel the request.'];
        }
    }

    // ── Soft delete (mistakes only) ──────────────────────────────────────────

    /**
     * Soft-delete a request entered by mistake (a test row, a duplicate). NOT
     * the workflow outcome — that is cancel(). The row stays, its status is
     * untouched, and a 'deleted' history row records who, when and why; from
     * then on every read (lists, calendar, stats, waiting badge, conflict
     * checks, setup sheet, exports) leaves it out. A deleted confirmed booking
     * therefore frees its room.
     */
    public function softDelete($id, $reason, $performedBy = null) {
        $id      = (int)$id;
        $reason  = trim((string)$reason);
        if ($reason === '') return ['ok' => false, 'error' => 'A reason is required to delete a request.'];
        $reason  = mb_substr($reason, 0, 255);
        $current = $this->getRequestById($id);
        if (!$current) return ['ok' => false, 'error' => 'Request not found.'];
        if (!empty($current['is_deleted'])) return ['ok' => false, 'error' => 'This request is already deleted.'];

        try {
            $this->db->beginTransaction();
            $this->db->prepare(
                "UPDATE facility_requests SET is_deleted = 1, deleted_at = NOW(), deleted_by = ?, delete_reason = ?
                  WHERE id = ? AND is_deleted = 0"
            )->execute([$performedBy ?: null, $reason, $id]);
            $freed = $current['status'] === self::BLOCKING_STATUS && $current['facility_id']
                ? ' The confirmed booking no longer reserves ' . ($current['facility_name'] ?: 'the room') . '.' : '';
            $this->logHistory($id, 'deleted', $current['status'], $current['status'],
                'Deleted (entered by mistake): ' . $reason . $freed, $performedBy);
            $this->db->commit();
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Facility request softDelete error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not delete the request.'];
        }
    }

    /**
     * Undo a soft delete. The request returns exactly as it was (same status,
     * same history plus a 'restored' row). Restoring a CONFIRMED booking whose
     * room has since been confirmed for someone else would double-book it —
     * the one failure the policy exists to prevent — so that is refused.
     */
    public function restore($id, $performedBy = null) {
        $id      = (int)$id;
        $current = $this->getRequestById($id);
        if (!$current) return ['ok' => false, 'error' => 'Request not found.'];
        if (empty($current['is_deleted'])) return ['ok' => false, 'error' => 'This request is not deleted.'];

        if ($current['status'] === self::BLOCKING_STATUS) {
            $clash = $this->conflictsForRequest($current);
            if (!empty($clash['hard'])) {
                $names = array_map(function ($c) { return $c['event_activity']; }, $clash['hard']);
                return ['ok' => false, 'error' => 'Cannot restore: this confirmed booking would now overlap another confirmed '
                    . 'reservation in the same room (' . implode('; ', $names) . '). Resolve that clash first.'];
            }
        }

        try {
            $this->db->beginTransaction();
            $this->db->prepare(
                "UPDATE facility_requests SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL
                  WHERE id = ? AND is_deleted = 1"
            )->execute([$id]);
            $this->logHistory($id, 'restored', $current['status'], $current['status'],
                'Restored. It had been deleted' . ($current['delete_reason'] ? ' because: ' . $current['delete_reason'] : '') . '.',
                $performedBy);
            $this->db->commit();
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log("Facility request restore error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not restore the request.'];
        }
    }

    // ── Repeat weekly ────────────────────────────────────────────────────────

    /**
     * The dates of a weekly series starting on $firstDate: either $weeks
     * occurrences, or every 7 days up to and including $untilDate. Always
     * capped at SERIES_MAX. Returns [] for an unreadable first date.
     */
    public static function seriesDates($firstDate, $weeks = null, $untilDate = null) {
        $first = strtotime((string)$firstDate);
        if ($first === false) return [];
        $out = [];
        $until = $untilDate ? strtotime((string)$untilDate) : false;
        $n = $until !== false ? self::SERIES_MAX : max(1, min(self::SERIES_MAX, (int)$weeks));
        for ($i = 0; $i < $n; $i++) {
            $ts = strtotime('+' . ($i * 7) . ' days', $first);
            if ($until !== false && $ts > $until) break;
            $out[] = date('Y-m-d', $ts);
        }
        return $out;
    }

    /** A fresh id for a weekly series (same UUID shape as facility_requests.uuid). */
    public function newSeriesId() {
        return $this->generateUUID();
    }

    // ── Append-only approval / status history ────────────────────────────────

    /**
     * One INSERT per transition. Never UPDATEd, never DELETEd — same contract as
     * historical_completion_reviews. Called inside the caller's transaction so a
     * state change and its trail commit together or not at all.
     */
    private function logHistory($requestId, $action, $fromStatus, $toStatus, $notes, $performedBy) {
        $notes = ($notes !== null && trim((string)$notes) !== '') ? mb_substr(trim((string)$notes), 0, 500) : null;
        $this->db->prepare(
            "INSERT INTO facility_request_history
                (request_id, action, from_status, to_status, notes, performed_by)
             VALUES (?,?,?,?,?,?)"
        )->execute([(int)$requestId, $action, $fromStatus, $toStatus, $notes, $performedBy ?: null]);
    }

    /**
     * Full trail for one request, newest first, for the AJAX history panel.
     * Mirrors DiscipleshipProgressService::reviewHistory(): joins accounts for a
     * display name only, exposes no internal fields.
     */
    public function history($requestId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT frh.action, frh.from_status, frh.to_status, frh.notes, frh.performed_at,
                        a.username AS performed_by_name
                   FROM facility_request_history frh
                   LEFT JOIN accounts a ON a.id = frh.performed_by
                  WHERE frh.request_id = ?
                  ORDER BY frh.performed_at DESC, frh.id DESC"
            );
            $stmt->execute([(int)$requestId]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Facility request history error: " . $e->getMessage());
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
