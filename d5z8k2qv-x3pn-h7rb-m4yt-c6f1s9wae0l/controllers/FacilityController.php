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

    /** Calendar + request list page. Renders fine against entirely empty tables. */
    public function showCalendar() {
        if (!canViewFacilityBooking()) { header('Location: index.php'); exit(); }
        $tab = $_GET['tab'] ?? 'calendar';

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

        // Request-list filters (the table tab).
        $filters = [];
        if (!empty($_GET['status']))      $filters['status']      = sanitizeInput($_GET['status']);
        if (!empty($_GET['f_facility']))  $filters['facility_id'] = (int)$_GET['f_facility'];
        if (!empty($_GET['f_ministry']))  $filters['ministry']    = sanitizeInput($_GET['f_ministry']);
        if (!empty($_GET['date_from']))   $filters['date_from']   = sanitizeInput($_GET['date_from']);
        if (!empty($_GET['date_to']))     $filters['date_to']     = sanitizeInput($_GET['date_to']);

        $requests       = $this->requestModel->getAllRequests($filters);
        $activeFilters  = $filters;
        $stats          = $this->requestModel->getStats();
        $ministries     = $this->ministryModel->getAllMinistries();
        $reqMinistries  = $this->requestModel->getDistinctMinistries();
        $allMembers     = $this->membersList();
        $statusMeta     = FacilityRequest::STATUSES;
        $statusColors   = FacilityRequest::STATUS_COLORS;
        $requestTypes   = FacilityRequest::REQUEST_TYPES;

        // "Waiting on me": the requests at this account's stage
        // (config/permissions.php decides which statuses those are).
        $waitingStatuses = facilityWaitingStatuses();
        $waitingRequests = $waitingStatuses
            ? $this->requestModel->getAllRequests(['status' => $waitingStatuses]) : [];

        // Conflict + lead-time advisories, precomputed per listed request so the
        // table can flag a clash without the view issuing queries in a loop.
        $advisories = [];
        foreach (array_merge($requests, $waitingRequests) as $r) {
            if (!isset($advisories[(int)$r['id']])) $advisories[(int)$r['id']] = $this->advisoryFor($r);
        }

        // Stepper data: who acted and when, one history query for every row.
        $stageEvents = $this->requestModel->stageEventsFor(array_keys($advisories));

        // Approvers panel: who holds each stage.
        $approvers = $this->requestModel->getApprovers([ROLE_FACILITIES, ROLE_MINISTER, ROLE_ADMIN]);

        // Prefill the request form's requestor block from the signed-in account.
        $currentAccount = [
            'username' => $_SESSION['user']['username'] ?? '',
            'email'    => $_SESSION['user']['email'] ?? '',
        ];

        $activeTab = in_array($tab, ['calendar', 'requests', 'waiting'], true) ? $tab : 'calendar';
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
        $this->back(null, $message, '&tab=requests');
    }

    private function back($notif = null, $error = null, $extra = '') {
        $url = 'index.php?action=facilityCalendar' . $extra;
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
        if ($invalid !== '') $this->back(null, $invalid, '&tab=requests');
        $res = $this->requestModel->submit($data, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error'], '&tab=requests');

        // The lead-time warning never blocks; it is reported after the fact so
        // the requestor and Facilities both see it (policy section 11).
        $notif = !empty($res['lead']['warn']) ? 'submitted_short_notice' : 'submitted';
        $this->back($notif, null, '&tab=requests');
    }

    public function updateRequest($id, array $data) {
        $this->requirePermission(canSubmitFacilityRequest(), 'You are not allowed to edit facility requests.');
        $invalid = FacilityRequest::validatePortalForm($data);
        if ($invalid !== '') $this->back(null, $invalid, '&tab=requests');
        $res =$this->requestModel->updateRequest($id, $data, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error'], '&tab=requests');
        $this->back('updated', null, '&tab=requests');
    }

    // ── Step 2: Facilities review (policy section 8) ─────────────────────────

    public function facilitiesReview($id, array $data) {
        $this->requirePermission(canFacilitiesReview(), 'Only Facilities Personnel may perform the Facilities review.');
        $res = $this->requestModel->facilitiesReview($id, $data, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error'], '&tab=requests');
        $this->back('reviewed', null, '&tab=requests');
    }

    // ── Step 3: Minister decision (policy section 9) ─────────────────────────

    public function ministerDecision($id, $decision, $comments) {
        // Facilities Personnel are excluded here on purpose (policy 5.2 / 6.2):
        // reviewing and approving must not be the same pair of hands.
        $this->requirePermission(canMinisterApprove(), 'Only the Minister may approve or decline a request.');
        // An Admin acting here is an override, and the history says so.
        $res = $this->requestModel->ministerDecision($id, $decision, $comments, $this->userId(),
                                                     ministerActsAsOverride());
        if (!$res['ok']) $this->back(null, $res['error'], '&tab=requests');
        $notif = ['approved' => 'approved', 'declined' => 'declined',
                  'for_clarification' => 'clarification'][$decision] ?? 'updated';
        $this->back($notif, null, '&tab=requests');
    }

    // ── Step 4: Facilities confirmation — the official reservation ───────────

    public function confirmRequest($id, $notes, $force = false) {
        $this->requirePermission(canFacilitiesConfirm(), 'Only Facilities Personnel may confirm a reservation.');
        $res = $this->requestModel->confirm($id, $notes, $this->userId(), $force);
        if (!$res['ok']) $this->back(null, $res['error'], '&tab=requests');
        $this->back('confirmed', null, '&tab=requests');
    }

    public function cancelRequest($id, $notes) {
        $this->requirePermission(canCancelFacilityRequest(), 'Ask Facilities Personnel to cancel this request.');
        $res = $this->requestModel->cancel($id, $notes, $this->userId());
        if (!$res['ok']) $this->back(null, $res['error'], '&tab=requests');
        $this->back('cancelled', null, '&tab=requests');
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    /** Append-only approval trail for one request — mirrors ajaxHistoricalReviewHistory. */
    public function ajaxRequestHistory($id) {
        if (!canViewFacilityBooking()) $this->denyAjax('Not permitted.');
        header('Content-Type: application/json');
        echo json_encode(['entries' => $this->requestModel->history($id)]);
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
        if (!$r) {
            http_response_code(404);
            echo json_encode(['error' => 'Request not found.']);
            exit();
        }
        $conflicts = $this->requestModel->conflictsForRequest($r);
        $meta      = FacilityRequest::statusMeta($r['status']);

        // Stepper + the one next action this viewer may take (or whose turn it is).
        require_once __DIR__ . '/../views/shared/facility_stepper.php';
        $events = $this->requestModel->stageEventsFor([(int)$r['id']]);
        $steps  = FacilityRequest::stepper($r['status'], $events[(int)$r['id']] ?? []);
        $next   = facilityNextAction($r['status']);
        $owner  = facilityStageOwner($r['status']);

        echo json_encode([
            'stepper_html' => fb_stepper_html($steps, false),
            'next_action'  => $next,
            'waiting_for'  => $next ? null : $owner,
            'can_cancel'   => canCancelFacilityRequest() && !in_array($r['status'], ['cancelled', 'declined'], true),
            'payload'      => fb_row_payload($r, count($conflicts['hard']), count($conflicts['soft'])),
            'request'   => $r,
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
