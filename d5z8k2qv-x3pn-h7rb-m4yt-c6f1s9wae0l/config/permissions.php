<?php
/**
 * Victory Bacolod — who may do what.
 *
 * THE ONE PLACE THE PERMISSION MATRIX LIVES
 * Every gate in the app — the routes in index.php, the methods in
 * FacilityController, and the workflow buttons in the views — calls one of
 * these functions. Nothing outside this file compares an account type string
 * for a facility decision, so a rule can only ever be changed here, in one
 * edit, and the three layers cannot drift apart.
 *
 * WHY config/ AND NOT index.php
 * These functions are needed by three different layers: index.php (routing),
 * controllers/FacilityController.php (defence in depth), and the view partials
 * (button visibility). index.php already `require_once`s config/config.php at
 * the top of every request, so a sibling file there is reachable from all three
 * without any of them growing a new dependency, and it is just as reachable
 * from a CLI script or a future entry point that boots config/ but not the
 * front controller. Putting them next to sanitizeInput() in index.php would
 * tie the matrix to the front controller.
 *
 * WHY THE POLICY SPLITS THE ROLES AT ALL
 * Facilities, Equipment & Resource Request Policy section 5 step 2 and section
 * 6.2: Facilities Personnel assess logistics (step 2) and perform the final
 * confirmation (step 4). They explicitly do NOT give ministry approval — that
 * is the Minister's, step 3. An account that could do all three could walk a
 * request from submission to official reservation unaided, which is exactly
 * the single-hand approval the four-step workflow exists to prevent.
 *
 * The workflow order itself (Requestor -> Facilities Review -> Minister
 * Approval -> Facilities Confirmation) is enforced separately, by the
 * from-status checks in models/FacilityRequest.php. These helpers answer
 * "may THIS PERSON act?"; the model answers "may the request move from HERE?".
 * Both must pass.
 */

/** Stored accounts.accounttype values. The UI labels live in views/users.php. */
const ROLE_ADMIN      = 'admin';
const ROLE_EDITOR     = 'editor';
const ROLE_FACILITIES = 'facilities';
const ROLE_MINISTER   = 'minister';   // migrations/2026_10_20_12_minister_role.sql

/** Every value accounts.accounttype may legally hold — the write whitelist. */
const VALID_ACCOUNT_TYPES = [ROLE_ADMIN, ROLE_EDITOR, ROLE_FACILITIES, ROLE_MINISTER];

/** Human labels for the role dropdown / badges. Display only, never stored. */
const ACCOUNT_TYPE_LABELS = [
    ROLE_ADMIN      => 'Admin',
    ROLE_EDITOR     => 'Editor',
    ROLE_FACILITIES => 'Facilities Personnel',
    ROLE_MINISTER   => 'Minister',
];

/**
 * One-line help per role for the Users page, so whoever assigns a role can see
 * which facility workflow stages it acts on. Display only.
 */
const ACCOUNT_TYPE_HELP = [
    ROLE_EDITOR     => 'Discipleship records. May submit facility requests and view the calendar; may edit, resubmit or cancel their own requests; cannot review, approve, confirm or cancel anyone else\'s.',
    ROLE_FACILITIES => 'Facility stage 2 (Facilities Review) and stage 4 (Confirmation), plus cancel. Cannot give the Minister\'s approval or open admin settings.',
    ROLE_MINISTER   => 'Facility stage 3 only (Approve / Decline / For Clarification). Cannot review or confirm, and has no admin access.',
    ROLE_ADMIN      => 'Everything, including users and settings. May act on any stage; a Minister decision by an Admin is recorded as an override.',
];

/**
 * The acting account's role, or null when nobody is logged in.
 *
 * Every helper below takes an optional explicit role so a caller that already
 * has one (e.g. $currentUserType in a view) can pass it, and so these stay
 * testable without a session.
 */
function currentRole($role = null) {
    if ($role !== null) return $role;
    return $_SESSION['user']['accounttype'] ?? null;
}

function isLoggedIn() {
    return isset($_SESSION['user']);
}

function isAdmin($role = null) {
    return currentRole($role) === ROLE_ADMIN;
}

function isFacilitiesPersonnel($role = null) {
    return currentRole($role) === ROLE_FACILITIES;
}

function isMinister($role = null) {
    return currentRole($role) === ROLE_MINISTER;
}

/** Human label for a stored account type. */
function accountTypeLabel($type) {
    return ACCOUNT_TYPE_LABELS[$type] ?? ucfirst((string)$type);
}

function isValidAccountType($type) {
    return in_array($type, VALID_ACCOUNT_TYPES, true);
}

// ── Facility booking (Phase G) ───────────────────────────────────────────────

/**
 * See the calendar, the request list, a request's detail and its approval
 * history. Any logged-in account: the church wants the schedule visible so
 * ministries can see what the rooms are already doing before they ask for one.
 * Read-only — seeing a request is not being able to move it.
 */
function canViewFacilityBooking($role = null) {
    return currentRole($role) !== null;
}

/**
 * Step 1 — submit a request, and edit one that has not been confirmed.
 * Any logged-in account: anyone in a ministry may ask for a room.
 */
function canSubmitFacilityRequest($role = null) {
    return canViewFacilityBooking($role);
}

/**
 * Step 2 — the Facilities logistical review (policy section 8).
 * Facilities Personnel, and admins because the portal must stay operable if
 * no facilities account is logged in.
 */
function canFacilitiesReview($role = null) {
    $r = currentRole($role);
    return $r === ROLE_FACILITIES || $r === ROLE_ADMIN;
}

/**
 * Step 3 — the Minister's approve / decline / return-for-clarification
 * (policy section 9). The Minister, and Admin as an OVERRIDE.
 *
 * This is the deliberate hole in the Facilities Personnel role. Granting it to
 * 'facilities' would let one person review AND approve AND confirm, which is
 * precisely what sections 5 and 6.2 separate. Admin keeps it so the portal
 * still works when no Minister account exists; ministerActsAsOverride() lets
 * the append-only history record which capacity acted.
 */
function canMinisterApprove($role = null) {
    $r = currentRole($role);
    return $r === ROLE_MINISTER || $r === ROLE_ADMIN;
}

/** True when a step-3 decision is made by someone other than the Minister (an Admin override). */
function ministerActsAsOverride($role = null) {
    return canMinisterApprove($role) && !isMinister($role);
}

/**
 * Step 4 — Facilities confirmation, the ONLY step that creates an official
 * reservation (policy section 20). Facilities Personnel and admins.
 */
function canFacilitiesConfirm($role = null) {
    $r = currentRole($role);
    return $r === ROLE_FACILITIES || $r === ROLE_ADMIN;
}

/**
 * Cancel ANY request (policy section 18) — the staff capacity.
 *
 * Facilities Personnel and admins. A requestor may additionally cancel their
 * OWN request, which needs the request itself, so that rule lives in
 * canCancelThisFacilityRequest() below. Everyone else still cannot cancel.
 */
function canCancelFacilityRequest($role = null) {
    $r = currentRole($role);
    return $r === ROLE_FACILITIES || $r === ROLE_ADMIN;
}

/** The acting account's id, or null when nobody is logged in. */
function currentUserId($userId = null) {
    if ($userId !== null) return (int)$userId ?: null;
    return isset($_SESSION['user']['id']) ? ((int)$_SESSION['user']['id'] ?: null) : null;
}

/**
 * True when $userId filed this request in the portal (facility_requests.
 * submitted_by, migrations/2026_10_20_13). Imported and pre-existing requests
 * have submitted_by NULL and therefore no owner.
 */
function isFacilityRequestOwner(array $request, $userId = null) {
    $uid   = currentUserId($userId);
    $owner = isset($request['submitted_by']) ? (int)$request['submitted_by'] : 0;
    return $uid !== null && $owner > 0 && $owner === $uid;
}

/**
 * The date a request is for has already passed. Prefers the date_passed flag
 * computed by the database (FacilityRequest SELECT_BASE), so it agrees with
 * the SQL expiry rule even though PHP and MariaDB may run in different time
 * zones; falls back to PHP's date for a row built elsewhere.
 */
function facilityRequestDatePassed(array $request) {
    if (array_key_exists('date_passed', $request)) return (int)$request['date_passed'] === 1;
    $d = trim((string)($request['date_needed'] ?? ''));
    return $d !== '' && $d < date('Y-m-d');
}

/**
 * Cancel THIS request.
 *   Facilities / admin — any request not already cancelled or declined.
 *   The owner          — their own, before it is cancelled/declined and
 *                        before its date has passed.
 * A soft-deleted request cannot be cancelled by anyone (restore it first).
 */
function canCancelThisFacilityRequest(array $request, $role = null, $userId = null) {
    if (!empty($request['is_deleted'])) return false;
    if (in_array($request['status'] ?? '', ['cancelled', 'declined'], true)) return false;
    if (canCancelFacilityRequest($role)) return true;
    return isFacilityRequestOwner($request, $userId) && !facilityRequestDatePassed($request);
}

/**
 * Edit THIS request (and, when it was returned for clarification, resubmit
 * it). Owner only, and only while it is still with the requestor:
 *   submitted          — before Facilities has picked it up
 *   for_clarification  — saving moves it back to submitted ("resubmitted")
 * No editing after Facilities review; a confirmed booking is never edited
 * (policy section 18: cancel and request again). Admins and Facilities do not
 * get an edit-anyone capacity: the request is the requestor's own words.
 */
function canEditFacilityRequest(array $request, $role = null, $userId = null) {
    if (!empty($request['is_deleted'])) return false;
    if (!in_array($request['status'] ?? '', ['submitted', 'for_clarification'], true)) return false;
    return currentRole($role) !== null && isFacilityRequestOwner($request, $userId);
}

/**
 * Soft delete — for MISTAKES ONLY (test rows, duplicates). Admin only, reason
 * required. Cancel remains the normal workflow outcome. Restoring is the same
 * capacity, as is seeing the Deleted list.
 */
function canDeleteFacilityRequest($role = null) {
    return isAdmin($role);
}

function canRestoreFacilityRequest($role = null) {
    return isAdmin($role);
}

function canViewDeletedFacilityRequests($role = null) {
    return isAdmin($role);
}

/**
 * Book again / Repeat weekly — both just create NEW requests through the
 * normal step 1, so they are exactly the submit capacity.
 */
function canBookFacilityAgain($role = null) {
    return canSubmitFacilityRequest($role);
}

function canRepeatFacilityRequest($role = null) {
    return canSubmitFacilityRequest($role);
}

/** Weekly setup sheet (confirmed bookings only): anyone who can see Facility Booking. */
function canViewFacilitySetupSheet($role = null) {
    return canViewFacilityBooking($role);
}

/** The import page and its template are admin surfaces, like the import itself. */
function canImportFacilityRequests($role = null) {
    return canAdminister($role);
}

/**
 * "Close as expired" — a cancel with a prefilled note, so it is the staff
 * cancel capacity on a request that is still pending after its date.
 */
function canCloseExpiredFacilityRequest(array $request, $role = null) {
    return empty($request['is_deleted']) && !empty($request['is_expired']) && canCancelFacilityRequest($role);
}

/**
 * True when the account can perform ANY workflow transition. Used by the views
 * to decide whether to render the workflow modals at all.
 */
function canActOnFacilityWorkflow($role = null) {
    return canFacilitiesReview($role) || canMinisterApprove($role)
        || canFacilitiesConfirm($role) || canCancelFacilityRequest($role);
}

/**
 * "Waiting on me": the request statuses sitting at THIS role's stage.
 *   Facilities — submitted / facilities_review (stage 2) and approved (stage 4)
 *   Minister   — pending_minister (stage 3)
 *   Admin      — every desk above (an admin may act on all of them)
 *   Requestor  — for_clarification (the request came back for more detail)
 * for_clarification is the requestor's turn, so approvers see it separately as
 * "waiting on requestor" rather than in their own queue.
 */
function facilityWaitingStatuses($role = null) {
    $r = currentRole($role);
    if ($r === null) return [];
    $statuses = [];
    if (canFacilitiesReview($r))       { $statuses[] = 'submitted'; $statuses[] = 'facilities_review'; }
    if (canMinisterApprove($r))        $statuses[] = 'pending_minister';
    if (canFacilitiesConfirm($r))      $statuses[] = 'approved';
    if (!canActOnFacilityWorkflow($r)) $statuses[] = 'for_clarification';
    return array_values(array_unique($statuses));
}

/**
 * Whose requests count as "waiting on me". An approver's desk is every request
 * at their stage; a requestor's turn (for_clarification) is only THEIR OWN
 * requests, now that ownership exists (submitted_by). Returns the account id
 * to restrict to, or null for "everyone's".
 */
function facilityWaitingOwnerScope($role = null, $userId = null) {
    $r = currentRole($role);
    if ($r === null) return null;
    return canActOnFacilityWorkflow($r) ? null : (currentUserId($userId) ?? -1);
}

/**
 * Who holds the next step for a request in $status, for "Waiting for ...".
 * Describes the policy, not the viewer, so it takes no role.
 */
function facilityStageOwner($status) {
    $map = [
        'draft'             => 'Requestor',
        'submitted'         => 'Facilities Personnel',
        'facilities_review' => 'Facilities Personnel',
        'pending_minister'  => 'Minister',
        'for_clarification' => 'Requestor',
        'approved'          => 'Facilities Personnel',
    ];
    return $map[$status] ?? null;
}

/**
 * The ONE next workflow action this account can take on a request in $status,
 * or null. The status picks the step (mirroring the model's from-status rules,
 * which still decide); the role helpers above decide whether this person may
 * take it. Returns ['key' => review|decide|confirm, 'label', 'hint'].
 *
 * for_clarification: the requestor's turn. The model still lets Facilities
 * re-review it and the Minister decide it once the answer is in, so those
 * roles get a button labelled for that situation.
 */
function facilityNextAction($status, $role = null) {
    $r = currentRole($role);
    switch ($status) {
        case 'submitted':
        case 'facilities_review':
            if (canFacilitiesReview($r)) return ['key' => 'review', 'label' => 'Facilities review', 'hint' => 'Stage 2 of 4: assess logistics, then forward to the Minister'];
            break;
        case 'pending_minister':
            if (canMinisterApprove($r)) return ['key' => 'decide',
                'label' => ministerActsAsOverride($r) ? 'Minister decision (override)' : 'Approve / decline',
                'hint'  => 'Stage 3 of 4: Minister decision' . (ministerActsAsOverride($r) ? ', recorded as an Admin override' : '')];
            break;
        case 'for_clarification':
            if (canFacilitiesReview($r)) return ['key' => 'review', 'label' => 'Re-review', 'hint' => 'Stage 2 of 4: re-review once the requestor has clarified'];
            if (canMinisterApprove($r))  return ['key' => 'decide', 'label' => 'Decide', 'hint' => 'Stage 3 of 4: record the decision once clarified'];
            break;
        case 'approved':
            if (canFacilitiesConfirm($r)) return ['key' => 'confirm', 'label' => 'Confirm reservation', 'hint' => 'Stage 4 of 4: make it an official reservation that blocks the room'];
            break;
    }
    return null;
}

// ── Dashboard ────────────────────────────────────────────────────────────────

/**
 * The dashboard, and its Church Health Excel export. Any logged-in account
 * sees the dashboard, so the export carries the same gate: it is the same
 * attendance figures in a spreadsheet, not anything the page does not show.
 */
function canViewDashboard($role = null) {
    return currentRole($role) !== null;
}

// ── Everything else stays admin-only ─────────────────────────────────────────

/**
 * Member admin screens, settings tabs, user management, member import — the
 * surfaces already gated on 'admin' before this role existed. Facilities
 * Personnel are NOT admins; adding the role must not widen anything.
 */
function canAdminister($role = null) {
    return isAdmin($role);
}
