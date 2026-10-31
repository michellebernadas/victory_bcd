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

/** Every value accounts.accounttype may legally hold — the write whitelist. */
const VALID_ACCOUNT_TYPES = [ROLE_ADMIN, ROLE_EDITOR, ROLE_FACILITIES];

/** Human labels for the role dropdown / badges. Display only, never stored. */
const ACCOUNT_TYPE_LABELS = [
    ROLE_ADMIN      => 'Admin',
    ROLE_EDITOR     => 'Editor',
    ROLE_FACILITIES => 'Facilities Personnel',
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
 * (policy section 9). Admin ONLY.
 *
 * This is the deliberate hole in the Facilities Personnel role. Granting it to
 * 'facilities' would let one person review AND approve AND confirm, which is
 * precisely what sections 5 and 6.2 separate. Admin holds it for now because
 * the portal has no Minister account type yet — see the follow-up note in the
 * migration: if the church wants the Minister to sign in personally, this is
 * the single function that changes.
 */
function canMinisterApprove($role = null) {
    return currentRole($role) === ROLE_ADMIN;
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
 * Cancel a request (policy section 18).
 *
 * Restricted to Facilities Personnel and admins. The app has no column tying a
 * request to the account that submitted it (requestor_member_id is a MEMBER
 * id, not an accounts.id), so there is no way to say "the requestor may cancel
 * their own" without inventing an ownership model. Until that exists, letting
 * every logged-in account cancel would mean any account could cancel any other
 * ministry's confirmed reservation. Withholding it is the recoverable mistake:
 * a requestor asks Facilities to cancel. See docs/ follow-ups.
 */
function canCancelFacilityRequest($role = null) {
    $r = currentRole($role);
    return $r === ROLE_FACILITIES || $r === ROLE_ADMIN;
}

/**
 * True when the account can perform ANY workflow transition. Used by the views
 * to decide whether to render the workflow modals at all.
 */
function canActOnFacilityWorkflow($role = null) {
    return canFacilitiesReview($role) || canMinisterApprove($role)
        || canFacilitiesConfirm($role) || canCancelFacilityRequest($role);
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
