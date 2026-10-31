<?php
require_once 'models/Facility.php';
require_once 'models/FacilityRequest.php';
require_once 'models/Ministry.php';
require_once __DIR__ . '/../config/permissions.php';

/**
 * Facility booking — calendar, request form, and the four-step approval
 * workflow from the Victory Bacolod Facilities, Equipment & Resource Request
 * Policy.
 *
 * Each workflow transition is its own action so that the audit trail has one
 * row per decision and no screen can perform two steps at once. The model owns
 * the rules (who may move what, when); this layer only marshals POST data and
 * redirects with &notif= / &error=, matching the rest of the portal.
 */
class FacilityController {
    private $db;
    private $facilityModel;
    private $requestModel;
    private $ministryModel;

    public function __construct($db) {
        $this->db            = $db;
        $this->facilityModel = new Facility($db);
        $this->requestModel  = new FacilityRequest($db);
        $this->ministryModel = new Ministry($db);
    }

    private function userId() {
        return (int)($_SESSION['user']['id'] ?? 0) ?: null;
    }

    /** JSON 403 for the AJAX endpoints, matching the other ajax* routes. */
    private function denyAjax($message) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit();
    }

    /** Query keys that describe "where the user was" — carried through every POST. */
    private const RETURN_KEYS = ['tab', 'list', 'status', 'f_facility', 'f_ministry', 'date_from', 'date_to', 'q', 'week'];

    /**
     * The view's current position (tab, list tab, filters) as a query string,
     * whitelisted. Every workflow form posts it back as _return, so after an
     * action the user lands on the same tab with the same filters.
     */
    public static function returnQuery(array $src) {
        $keep = [];
        foreach (self::RETURN_KEYS as $k) {
            if (isset($src[$k]) && !is_array($src[$k]) && trim((string)$src[$k]) !== '') {
                $keep[$k] = mb_substr(trim((string)$src[$k]), 0, 100);
            }
        }
        return http_build_query($keep);
    }

    /** '&tab=..&list=..' from the posted _return, defaulting to the Requests tab. */
    private function returnExtra() {
        $raw = (string)($_POST['_return'] ?? $_GET['_return'] ?? '');
        $arr = [];
        if ($raw !== '') parse_str($raw, $arr);
        $q = self::returnQuery($arr);
        return $q !== '' ? '&' . $q : '&tab=requests';
    }

    /** The list tabs this account may see (Deleted is admin-only). */
    private function visibleListTabs() {
        $tabs = FacilityRequest::LIST_TABS;
        if (!canViewDeletedFacilityRequests()) unset($tabs['deleted']);
        return $tabs;
    }

    /** Sunday-start week containing $date (matches the calendar's week view). */
    public static function weekBounds($date) {
        $ts = strtotime((string)$date);
        if ($ts === false) $ts = time();
        $start = strtotime('-' . (int)date('w', $ts) . ' days', strtotime(date('Y-m-d', $ts)));
        return [date('Y-m-d', $start), date('Y-m-d', strtotime('+6 days', $start))];
    }

    /** Calendar + request list page. Renders fine against entirely empty tables. */
    public function showCalendar() {
        if (!canViewFacilityBooking()) { header('Location: index.php'); exit(); }
        $tab = $_GET['tab'] ?? 'calendar';
        // "Waiting on me" used to be a top-level tab; old links still land on it.
        $list = $_GET['list'] ?? '';
        if ($tab === 'waiting') { $tab = 'requests'; $list = 'waiting'; }

        // Calendar window: a generous span around the focused month so the month
        // grid's leading/trailing days and the week view are both covered without
        // a second round trip. Client-side JS filters it down per view.
        $focus = $_GET['date'] ?? date('Y-m-d');
        if (strtotime($focus) === false) $focus = date('Y-m-d');
        $windowFrom = date('Y-m-d', strtotime('first day of ' . date('Y-m', strtotime($focus)) . ' -1 month'));
        $windowTo   = date('Y-m-d', strtotime('last day of '  . date('Y-m', strtotime($focus)) . ' +1 month'));

        $facilityFilter = isset($_GET['facility_id']) ? array_filter((array)$_GET['facility_id'], 'strlen') : [];
        $facilityFilter = array_map('intval', $facilityFilter);

        $facilities       = $this->facilityModel->getAllFacilities();
        $bookable         = $this->facilityModel->getBookableFacilities();
        $calendarRequests = $this->requestModel->getCalendarRequests($windowFrom, $windowTo, $facilityFilter);

        // Request-list filters, applied inside whichever list tab is open.
        // Validated rather than HTML-escaped: they go into prepared statements,
        // and the view escapes on output.
        $filters = [];
        if (!empty($_GET['status']) && isset(FacilityRequest::STATUSES[$_GET['status']])) $filters['status'] = (string)$_GET['status'];
        if (!empty($_GET['f_facility']))  $filters['facility_id'] = (int)$_GET['f_facility'];
        if (!empty($_GET['f_ministry']))  $filters['ministry']    = mb_substr(trim((string)$_GET['f_ministry']), 0, 100);
        foreach (['date_from', 'date_to'] as $dk) {
            if (!empty($_GET[$dk]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET[$dk])) $filters[$dk] = (string)$_GET[$dk];
        }
        if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') $filters['search'] = mb_substr(trim((string)$_GET['q']), 0, 100);

        $listTabs   = $this->visibleListTabs();
        $activeList = isset($listTabs[$list]) ? $list : 'active';
        $uid        = $this->userId();

        // "Waiting on me": the requests at this account's stage
        // (config/permissions.php decides which statuses and whose), never
        // deleted, never read-time-expired.
        $waitingStatuses = facilityWaitingStatuses();
        $waitingFilters  = $filters + ['tab' => 'waiting', 'exclude_expired' => true,
                                       'submitted_by' => facilityWaitingOwnerScope()];
        // A status filter narrows the desk; a status not on this desk lists nothing.
        $waitingFilters['status'] = isset($filters['status']) ? [$filters['status']] : $waitingStatuses;
        $onDesk = !isset($filters['status']) || in_array($filters['status'], $waitingStatuses, true);
        $waitingRequests = ($waitingStatuses && $onDesk) ? $this->requestModel->getAllRequests($waitingFilters) : [];

        if ($activeList === 'waiting') {
            $requests = $waitingRequests;
        } elseif ($activeList === 'mine') {
            $requests = $uid ? $this->requestModel->getAllRequests($filters + ['tab' => 'mine', 'submitted_by' => $uid]) : [];
        } else {
            $requests = $this->requestModel->getAllRequests($filters + ['tab' => $activeList]);
        }
        $tabCounts = $this->requestModel->tabCounts($filters, $uid);
        $tabCounts['waiting'] = count($waitingRequests);
        if (!$uid) $tabCounts['mine'] = 0;

        $activeFilters  = $filters;
        $stats          = $this->requestModel->getStats();
        $ministries     = $this->ministryModel->getAllMinistries();
        $reqMinistries  = $this->requestModel->getDistinctMinistries();
        $allMembers     = $this->membersList();
        $statusMeta     = FacilityRequest::STATUSES;
        $statusColors   = FacilityRequest::STATUS_COLORS;
        $requestTypes   = FacilityRequest::REQUEST_TYPES;

        // Conflict + lead-time advisories, precomputed per listed request so the
        // table can flag a clash without the view issuing queries in a loop.
        $advisories = [];
        foreach ($requests as $r) {
            if (!isset($advisories[(int)$r['id']])) $advisories[(int)$r['id']] = $this->advisoryFor($r);
        }

        // Weekly setup sheet (tab=setup).
        list($setupFrom, $setupTo) = self::weekBounds($_GET['week'] ?? date('Y-m-d'));
        $setupRows = ($tab === 'setup' && canViewFacilitySetupSheet()) ? $this->requestModel->getSetupSheet($setupFrom, $setupTo) : [];

        // Where every form on this page should send the user back to.
        $fbReturn = self::returnQuery(['tab' => $tab, 'list' => $activeList] + $_GET);

        // Stepper data: who acted and when, one history query for every row.
        $stageEvents = $this->requestModel->stageEventsFor(array_keys($advisories));

        // Approvers panel: who holds each stage.
        $approvers = $this->requestModel->getApprovers([ROLE_FACILITIES, ROLE_MINISTER, ROLE_ADMIN]);

        // Prefill the request form's requestor block from the signed-in account.
        $currentAccount = [
            'username' => $_SESSION['user']['username'] ?? '',
            'email'    => $_SESSION['user']['email'] ?? '',
        ];

        $activeTab = in_array($tab, ['calendar', 'requests', 'setup'], true) ? $tab : 'calendar';
        $focusDate = $focus;

        include 'views/facility_calendar.php';
    }

    /**
     * Conflict + lead-time summary for one request. Only 'confirmed' overlaps
     * count as hard; everything else is advisory. Returns a fixed shape so the
     * view never has to test for missing keys.
     */
    private function advisoryFor(array $r) {
        $conflicts = $this->requestModel->conflictsForRequest($r);
        return [
            'hard'      => $conflicts['hard'],
            'soft'      => $conflicts['soft'],
            'lead_warn' => !empty($r['lead_time_warning']),
            'lead_days' => $r['lead_days_actual'],
            'required'  => $r['default_lead_days'] ?? Facility::DEFAULT_LEAD_DAYS,
        ];
    }

    private function membersList() {
        try {
            return $this->db->query(
                "SELECT id, full_name, ministry, contact_number, email
                   FROM members WHERE is_deleted = 0 ORDER BY full_name ASC"
            )->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Facility members list error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Defence in depth.
     *
     * index.php already gates these routes, but a route guard only protects the
     * one door it is nailed to: the moment one of these methods is called from
     * an importer, a CLI script or a new route that forgets the check, the
     * guard is gone. So each transition re-asks config/permissions.php — the
     * same helper the route and the button both ask, so the three can't drift.
     *
     * This sits ON TOP of the model's from-status validation, which answers a
     * different question ("may the request move from here?") and is untouched.
     */
    private function requirePermission($allowed, $message) {
        if ($allowed) return;
        error_log('Facility workflow denied: ' . $message
            . ' (account ' . ($this->userId() ?? 'none')
            . ', type ' . ($_SESSION['user']['accounttype'] ?? 'none') . ')');
        $this->back(null, $message);
    }

    /** Redirect to the page the user acted from (tab, list tab and filters kept). */
    private function back($notif = null, $error = null, $extra = null) {
        $url = 'index.php?action=facilityCalendar' . ($extra ?? $this->returnExtra());
        if ($notif) $url .= '&notif=' . urlencode($notif);
        if ($error) $url .= '&error=1&msg=' . urlencode($error);
        header('Location: ' . $url);
        exit();
    }

    // ── Step 1: Requestor submits (policy section 7) ─────────────────────────

    public function submitRequest(array $data) {
        $this->requirePermission(canSubmitFacilityRequest(), 'You are not allowed to submit facility requests.');
        // Portal-form rules (room + times for a Facility / Room request, end
        // after start, date required). Lead time still only warns.
        $invalid = FacilityRequest::validatePortalForm($data);
        if ($invalid !== '') $this->back(null, $invalid);

        if (!empty($data['repeat_weekly'])) $this->submitSeries($data);

        // The portal submitter owns the request (My Requests, edit, cancel own).
        $res = $this->requestModel->submit($data, $this->userId(), $this->userId());
        if (!$res['ok']) $this->back(null, $res['error']);

        // The lead-time warning never blocks; it is reported after the fact so
        // the requestor and Facilities both see it (policy section 11).
        $notif = !empty($res['lead']['warn']) ? 'submitted_short_notice' : 'submitted';
        $this->back($notif, null, '&tab=requests&list=mine');
    }

    /**
     * Repeat weekly: one SEPARATE request per kept date, linked by series_id.
     * The dates are recomputed here from the first date and the repeat
     * settings and intersected with the ticked ones, so a hand-edited POST can
     * neither add a date outside the series nor exceed SERIES_MAX. Each
     * request then goes through the four-step workflow on its own; there is
     * deliberately no bulk approval.
     */
    private function submitSeries(array $data) {
        $this->requirePermission(canRepeatFacilityRequest(), 'You are not allowed to submit facility requests.');
        $all  = self::seriesFromPost($data);
        $kept = array_values(array_intersect($all, array_map('strval', (array)($data['series_dates'] ?? []))));
        if (!$kept) $this->back(null, 'Tick at least one date to submit the weekly series.');

        $seriesId = count($kept) > 1 ? $this->requestModel->newSeriesId() : null;
        $created = 0; $failed = [];
        foreach ($kept as $i => $date) {
            $one = $data;
            $one['date_needed'] = $date;
            $note = $seriesId ? 'Part of a weekly series (' . ($i + 1) . ' of ' . count($kept) . ').' : null;
            $res = $this->requestModel->submit($one, $this->userId(), $this->userId(), $seriesId, $note);
            if ($res['ok']) $created++; else $failed[] = $date;
        }
        if (!$created) $this->back(null, 'None of the weekly requests could be saved.');
        $msg = $failed ? 'Could not save the request for ' . implode(', ', $failed) . '.' : null;
        $this->back('series_submitted', $msg, '&tab=requests&list=mine&n=' . $created);
    }

    /** The series dates the request form's Repeat weekly settings describe. */
    private static function seriesFromPost(array $data) {
        $until = (($data['repeat_mode'] ?? '') === 'until') ? trim((string)($data['repeat_until'] ?? '')) : null;
        return FacilityRequest::seriesDates($data['date_needed'] ?? '', (int)($data['repeat_weeks'] ?? 0), $until ?: null);
    }

    /** Owner edit (submitted) or edit + resubmit (for_clarification). */
    public function updateRequest($id, array $data) {
        $r = $this->requestModel->getRequestById($id);
        $this->requirePermission($r && canEditFacilityRequest($r),
            'Only the person who submitted this request can edit it, and only before Facilities has reviewed it.');
        $invalid = FacilityRequest::validatePortalForm($data);
        if ($invalid !== '') $this->back(null, $invalid);
        $res = $this->requestModel->updateRequest($id, $data, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error']);
        $this->back(!empty($res['resubmitted']) ? 'resubmitted' : 'updated');
    }

    // ── Step 2: Facilities review (policy section 8) ─────────────────────────

    public function facilitiesReview($id, array $data) {
        $this->requirePermission(canFacilitiesReview(), 'Only Facilities Personnel may perform the Facilities review.');
        $res = $this->requestModel->facilitiesReview($id, $data, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error']);
        $this->back('reviewed');
    }

    // ── Step 3: Minister decision (policy section 9) ─────────────────────────

    public function ministerDecision($id, $decision, $comments) {
        // Facilities Personnel are excluded here on purpose (policy 5.2 / 6.2):
        // reviewing and approving must not be the same pair of hands.
        $this->requirePermission(canMinisterApprove(), 'Only the Minister may approve or decline a request.');
        // An Admin acting here is an override, and the history says so.
        $res = $this->requestModel->ministerDecision($id, $decision, $comments, $this->userId(),
                                                     ministerActsAsOverride());
        if (!$res['ok']) $this->back(null, $res['error']);
        $notif = ['approved' => 'approved', 'declined' => 'declined',
                  'for_clarification' => 'clarification'][$decision] ?? 'updated';
        $this->back($notif);
    }

    // ── Step 4: Facilities confirmation — the official reservation ───────────

    public function confirmRequest($id, $notes, $force = false) {
        $this->requirePermission(canFacilitiesConfirm(), 'Only Facilities Personnel may confirm a reservation.');
        $res = $this->requestModel->confirm($id, $notes, $this->userId(), $force);
        if (!$res['ok']) $this->back(null, $res['error']);
        $this->back('confirmed');
    }

    /** Facilities / admin: any request. The owner: their own, before its date. */
    public function cancelRequest($id, $notes) {
        $r = $this->requestModel->getRequestById($id);
        $this->requirePermission($r && canCancelThisFacilityRequest($r), 'Ask Facilities Personnel to cancel this request.');
        $res = $this->requestModel->cancel($id, $notes, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error']);
        $this->back('cancelled');
    }

    // ── Soft delete (admin, mistakes only) ───────────────────────────────────

    public function deleteRequest($id, $reason) {
        $this->requirePermission(canDeleteFacilityRequest(), 'Only an admin may delete a request.');
        $res = $this->requestModel->softDelete($id, $reason, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error']);
        $this->back('deleted');
    }

    public function restoreRequest($id) {
        $this->requirePermission(canRestoreFacilityRequest(), 'Only an admin may restore a deleted request.');
        $res = $this->requestModel->restore($id, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error']);
        $this->back('restored');
    }

    // ── Weekly setup sheet export ────────────────────────────────────────────

    const SETUP_HEADERS = ['Day', 'Room', 'Time', 'Event', 'Ministry', 'Expected attendance',
                           'Setup required', 'Technical required', 'Facilities notes'];

    /**
     * Setup sheet rows as plain display strings (shared by the page, CSV and
     * Excel). Setup / technical: the Facilities review answer when there is
     * one, else what the requestor asked for.
     */
    public static function setupSheetLines(array $rows) {
        $yn = function ($fac, $need) {
            if ($fac === 'yes' || $fac === 'no') return ucfirst($fac);
            return $need ? 'Yes' : 'No';
        };
        $t = function ($v) { return $v ? date('g:i A', strtotime('2000-01-01 ' . $v)) : ''; };
        $out = [];
        foreach ($rows as $r) {
            $setup = $yn($r['fac_setup_required'] ?? null, !empty($r['needs_setup']));
            if (trim((string)($r['preferred_setup'] ?? '')) !== '') $setup .= ' — ' . $r['preferred_setup'];
            $out[] = array_combine(self::SETUP_HEADERS, [
                date('D, M j, Y', strtotime($r['date_needed'])),
                $r['facility_name'] ?: 'No room (equipment / resource only)',
                ($r['start_time'] ? trim($t($r['start_time']) . ($r['end_time'] ? ' – ' . $t($r['end_time']) : '')) : 'No time given'),
                (string)$r['event_activity'],
                (string)$r['ministry'],
                $r['expected_attendance'] !== null ? (string)$r['expected_attendance'] : '',
                $setup,
                $yn($r['fac_technical_required'] ?? null,
                    !empty($r['needs_technical']) || ($r['technical_assistance'] ?? '') === 'yes'),
                trim((string)($r['fac_other_considerations'] ?? '')),
            ]);
        }
        return $out;
    }

    public function exportSetupSheet($format, $week) {
        $this->requirePermission(canViewFacilitySetupSheet(), 'You cannot view the setup sheet.');
        list($from, $to) = self::weekBounds($week ?: date('Y-m-d'));
        $lines = self::setupSheetLines($this->requestModel->getSetupSheet($from, $to));
        $base  = 'setup-sheet-' . $from;

        if ($format === 'xlsx') {
            $book  = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Setup sheet');
            $sheet->setCellValue('A1', 'Weekly setup sheet — confirmed bookings, '
                . date('M j', strtotime($from)) . ' to ' . date('M j, Y', strtotime($to)));
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
            $sheet->fromArray(self::SETUP_HEADERS, null, 'A3');
            $sheet->getStyle('A3:I3')->getFont()->setBold(true);
            $sheet->getStyle('A3:I3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                  ->getStartColor()->setRGB('EDE3EC');
            $rowNo = 4;
            foreach ($lines as $l) { $sheet->fromArray(array_values($l), null, 'A' . $rowNo, true); $rowNo++; }
            if (!$lines) $sheet->setCellValue('A4', 'No confirmed bookings this week.');
            foreach (range('A', 'I') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->freezePane('A4');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
            header('Cache-Control: max-age=0');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');
            exit();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads the dash and accents as UTF-8
        fputcsv($out, self::SETUP_HEADERS, ',', '"', '');
        foreach ($lines as $l) fputcsv($out, array_values($l), ',', '"', '');
        fclose($out);
        exit();
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    /** Append-only approval trail for one request — mirrors ajaxHistoricalReviewHistory. */
    public function ajaxRequestHistory($id) {
        if (!canViewFacilityBooking()) $this->denyAjax('Not permitted.');
        // A deleted request is invisible to everyone but the admins who can restore it.
        $r = $this->requestModel->getRequestById($id);
        if ($r && !empty($r['is_deleted']) && !canViewDeletedFacilityRequests()) $r = null;
        header('Content-Type: application/json');
        echo json_encode(['entries' => $r ? $this->requestModel->history($id) : []]);
        exit();
    }

    /**
     * Repeat weekly preview: every date of the series with its own clash
     * check (hard vs a confirmed booking, soft vs a pending one) and lead-time
     * warning. Advisory only — the user decides which dates to keep, and each
     * kept date becomes its own request.
     */
    public function ajaxSeriesPreview(array $data) {
        if (!canRepeatFacilityRequest()) $this->denyAjax('Not permitted.');
        header('Content-Type: application/json');
        $invalid = FacilityRequest::validatePortalForm($data);
        if ($invalid !== '') { echo json_encode(['error' => $invalid]); exit(); }
        if (($data['repeat_mode'] ?? '') === 'until' && trim((string)($data['repeat_until'] ?? '')) === '') {
            echo json_encode(['error' => 'Choose the date the weekly series ends.']); exit();
        }
        $dates = self::seriesFromPost($data);
        if (count($dates) < 2) {
            echo json_encode(['error' => 'A weekly series needs at least two dates. Check the number of weeks or the end date.']); exit();
        }
        $facilityId = (int)($data['facility_id'] ?? 0) ?: null;
        $out = [];
        foreach ($dates as $i => $d) {
            list($s, $e) = FacilityRequest::deriveInterval($d, $data['start_time'] ?? '', $data['end_time'] ?? '');
            $c    = $this->requestModel->conflictsFor($facilityId, $s, $e);
            $lead = $this->requestModel->leadTimeCheck($facilityId, $d);
            $names = function ($rows) { return array_map(function ($x) { return $x['event_activity']; }, $rows); };
            $out[] = [
                'n' => $i + 1, 'date' => $d, 'label' => date('D, M j, Y', strtotime($d)),
                'hard' => $names($c['hard']), 'soft' => $names($c['soft']),
                'lead_warn' => $lead['warn'], 'lead_message' => $lead['message'],
                'checked' => (bool)($facilityId && $s && $e),
            ];
        }
        echo json_encode(['dates' => $out, 'max' => FacilityRequest::SERIES_MAX]);
        exit();
    }

    /**
     * Live conflict + lead-time check for the request form, so the requestor
     * sees a clash before submitting rather than after. Advisory in both
     * directions: the form is never prevented from submitting.
     */
    public function ajaxCheckAvailability() {
        if (!canViewFacilityBooking()) $this->denyAjax('Not permitted.');
        header('Content-Type: application/json');

        $facilityId = isset($_GET['facility_id']) && $_GET['facility_id'] !== '' ? (int)$_GET['facility_id'] : null;
        $date       = $_GET['date_needed'] ?? '';
        $start      = $_GET['start_time']  ?? '';
        $end        = $_GET['end_time']    ?? '';
        $excludeId  = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;

        list($startsAt, $endsAt) = FacilityRequest::deriveInterval($date, $start, $end);
        $conflicts = $this->requestModel->conflictsFor($facilityId, $startsAt, $endsAt, $excludeId ?: null);
        $lead      = $this->requestModel->leadTimeCheck($facilityId, $date);

        $shape = function ($rows) {
            return array_map(function ($c) {
                $meta = FacilityRequest::statusMeta($c['status']);
                return [
                    'id'       => (int)$c['id'],
                    'event'    => $c['event_activity'],
                    'ministry' => $c['ministry'],
                    'facility' => $c['facility_name'],
                    'date'     => $c['date_needed'],
                    'start'    => $c['start_time'],
                    'end'      => $c['end_time'],
                    'status'   => $c['status'],
                    'status_label' => $meta['label'],
                    'status_badge' => $meta['badge'],
                ];
            }, $rows);
        };

        echo json_encode([
            // No facility means nothing is being occupied, so no check ran at all.
            'checked' => $facilityId && $startsAt && $endsAt,
            'hard'    => $shape($conflicts['hard']),
            'soft'    => $shape($conflicts['soft']),
            'lead'    => $lead,
        ]);
        exit();
    }

    /** Read-only detail payload for the calendar's click-through modal. */
    public function ajaxRequestDetail($id) {
        if (!canViewFacilityBooking()) $this->denyAjax('Not permitted.');
        header('Content-Type: application/json');
        $r = $this->requestModel->getRequestById($id);
        $deleted = $r && !empty($r['is_deleted']);
        if (!$r || ($deleted && !canViewDeletedFacilityRequests())) {
            http_response_code(404);
            echo json_encode(['error' => 'Request not found.']);
            exit();
        }
        $conflicts = $deleted ? ['hard' => [], 'soft' => []] : $this->requestModel->conflictsForRequest($r);
        $meta      = FacilityRequest::statusMeta($r['status']);
        $expired   = !empty($r['is_expired']);

        // Stepper + the one next action this viewer may take (or whose turn it is).
        // A deleted or expired request offers no workflow step.
        require_once __DIR__ . '/../views/shared/facility_stepper.php';
        $events = $this->requestModel->stageEventsFor([(int)$r['id']]);
        $steps  = FacilityRequest::stepper($r['status'], $events[(int)$r['id']] ?? []);
        $next   = ($deleted || $expired) ? null : facilityNextAction($r['status']);
        $owner  = ($deleted || $expired) ? null : facilityStageOwner($r['status']);

        // Weekly series: "Part of a weekly series (3 of 8)" with links.
        $series = [];
        if (!empty($r['series_id'])) {
            foreach ($this->requestModel->getSeries($r['series_id']) as $i => $s) {
                $series[] = ['id' => (int)$s['id'], 'n' => $i + 1, 'date' => $s['date_needed'],
                             'status_label' => FacilityRequest::statusMeta($s['status'])['label'],
                             'current' => (int)$s['id'] === (int)$r['id']];
            }
        }

        // Never ship internal ownership / deletion ids to the browser; names only.
        $public = $r;
        unset($public['deleted_by'], $public['submitted_by']);

        echo json_encode([
            'stepper_html' => fb_stepper_html($steps, false),
            'next_action'  => $next,
            'waiting_for'  => $next ? null : $owner,
            'can_cancel'   => canCancelThisFacilityRequest($r),
            'can_close_expired' => canCloseExpiredFacilityRequest($r),
            'can_edit'     => canEditFacilityRequest($r),
            'can_delete'   => !$deleted && canDeleteFacilityRequest(),
            'can_restore'  => $deleted && canRestoreFacilityRequest(),
            'can_book_again' => !$deleted && canBookFacilityAgain(),
            'is_owner'     => isFacilityRequestOwner($r),
            'is_expired'   => $expired,
            'is_deleted'   => $deleted,
            'series'       => $series,
            'payload'      => fb_row_payload($r, count($conflicts['hard']), count($conflicts['soft'])),
            'request'   => $public,
            'status_label' => $meta['label'],
            'status_badge' => $meta['badge'],
            'is_official'  => $r['status'] === FacilityRequest::BLOCKING_STATUS,
            'hard_count'   => count($conflicts['hard']),
            'soft_count'   => count($conflicts['soft']),
        ]);
        exit();
    }
}
?>
