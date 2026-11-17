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

/**
 * ROLES ARE DATA NOW
 * Roles live in the `roles` table and their permissions in `role_permissions`
 * (migrations/2026_11_16_01_roles_and_permissions.sql); admins manage both from
 * Administration > Roles. accounts.accounttype stores a role's slug. The one
 * exception is 'admin': a built-in role that is granted EVERYTHING in code, so
 * no edit to the tables can lock the church out of its own portal.
 *
 * What a permission means is defined here, in PERMISSION_CATALOG, and checked
 * through hasPermission(). Roles are plain data; the catalog is the code.
 */

/**
 * Every permission a role can be given, grouped for the Roles screen.
 * key => [label, help]. Keys are stored in role_permissions.permission, so
 * never rename one without a migration.
 */
const PERMISSION_GROUPS = [
    'General' => [
        'dashboard' => ['Dashboard & Church Health', 'The church-wide statistics landing page and its Excel export. Without it the account lands on a short welcome page instead.'],
    ],
    'Discipleship' => [
        'members'              => ['Members',            'View, add and edit members and their profiles.'],
        'attendance_records'   => ['Attendance Records', 'The all-classes Attendance Records page. Individual class pages are granted separately below.'],
        'serve_teams'          => ['Serve Teams',        'Serve teams and their members.'],
        'service_attendance'   => ['Sunday Attendance',  'Sunday service attendance.'],
    ],
    'Groups' => [
        'group_vg'      => ['Victory Groups',     'The Victory Groups list (and its statistics).'],
        'group_lg'      => ['Leadership Groups',  'The Leadership Groups list.'],
        'group_campus'  => ['Campus',             'The Campus groups list.'],
        'group_leaders' => ['Leaders list',       'The read-only list of group leaders.'],
    ],
    'Gatherings' => [
        'prayer_meeting'   => ['Prayer Meeting',     'Prayer Meeting attendance (Tuesday / Wednesday).'],
        'students_hangout' => ['Students\' Hangout', 'Students\' Hangout attendance (Friday).'],
    ],
    'Classes' => [
        'class_victory_weekend'       => ['Victory Weekend',       'Victory Weekend attendance records.'],
        'class_church_community'      => ['Church Community',      'Church Community attendance records.'],
        'class_making_disciples'      => ['Making Disciples',      'Making Disciples attendance records.'],
        'class_empowering_leaders'    => ['Empowering Leaders',    'Empowering Leaders attendance records.'],
        'class_leadership_113'        => ['Leadership 1-1-3',      'Leadership 1-1-3 records.'],
        'class_spiritual_foundations' => ['Spiritual Foundations', 'Spiritual Foundations records.'],
    ],
    'Public site' => [
        'join_requests' => ['Interested people (join inbox)', 'The inbox of people who said they are interested in joining a Victory Group on the public website. Contains names and contact details.'],
    ],
    'Facility Booking' => [
        'facility_view'    => ['View calendar & requests',  'See the calendar, requests, history and the setup sheet.'],
        'facility_submit'  => ['Submit requests',           'Step 1 — request a room, and edit / resubmit / cancel their own requests.'],
        'facility_review'  => ['Facilities review',         'Step 2 — assess logistics.'],
        'facility_approve' => ['Minister approval',         'Step 3 — approve, decline or return for clarification.'],
        'facility_confirm' => ['Facilities confirmation',   'Step 4 — make it an official reservation.'],
        'facility_cancel'  => ['Cancel any request',        'Cancel or close-as-expired anyone\'s request.'],
        'facility_rooms'   => ['Manage rooms',              'Add, edit, hide, reorder and delete the rooms people can book, and mark a room as special-case only.'],
    ],
];

/**
 * The three bundled permissions this app used to have, and the separate
 * permissions each one became. Used by the migration's logic (mirrored in SQL)
 * and by the pre-migration fallback roles, so a role that held a bundle keeps
 * exactly the same access after the split.
 */
const LEGACY_PERMISSION_MAP = [
    'victory_groups'       => ['group_vg', 'group_lg', 'group_campus', 'group_leaders'],
    'gathering_attendance' => ['prayer_meeting', 'students_hangout'],
    'classes'              => ['class_victory_weekend', 'class_church_community', 'class_making_disciples',
                            'class_empowering_leaders', 'class_leadership_113', 'class_spiritual_foundations'],
];

/** Flat key => label view of PERMISSION_GROUPS (for validation and display). */
function permissionCatalog() {
    $flat = [];
    foreach (PERMISSION_GROUPS as $perms) {
        foreach ($perms as $key => $meta) $flat[$key] = $meta[0];
    }
    return $flat;
}

/** Keep only keys that exist in the catalog — the write whitelist for role edits. */
function sanitizePermissionKeys($keys) {
    $catalog = permissionCatalog();
    $out = [];
    foreach ((array)$keys as $k) {
        if (is_string($k) && isset($catalog[$k])) $out[$k] = true;
    }
    return array_keys($out);
}

/**
 * The PDO handle roles are read through. index.php calls setPermissionDb($db)
 * once, right after getDB(); this file loads before the handle exists.
 */
function setPermissionDb($db) {
    $GLOBALS['__vb_perm_db'] = $db;
    $GLOBALS['__vb_roles_cache'] = null;
}

/**
 * All roles, once per request: slug => ['id','slug','label','description',
 * 'is_system','permissions'=>[key,...]]. Falls back to the four original
 * built-in roles if the tables do not exist yet (migration not applied), so a
 * half-deployed site keeps working instead of locking everyone out.
 */
function allRoles() {
    if (isset($GLOBALS['__vb_roles_cache']) && is_array($GLOBALS['__vb_roles_cache'])) {
        return $GLOBALS['__vb_roles_cache'];
    }
    $roles = [];
    $db = $GLOBALS['__vb_perm_db'] ?? null;
    if ($db instanceof PDO) {
        try {
            foreach ($db->query("SELECT id, slug, label, description, is_system FROM roles ORDER BY is_system DESC, id ASC")->fetchAll() as $r) {
                $r['permissions'] = [];
                $roles[$r['slug']] = $r;
            }
            $byId = [];
            foreach ($roles as $slug => $r) $byId[(int)$r['id']] = $slug;
            foreach ($db->query("SELECT role_id, permission FROM role_permissions")->fetchAll() as $p) {
                $slug = $byId[(int)$p['role_id']] ?? null;
                if ($slug !== null) $roles[$slug]['permissions'][] = $p['permission'];
            }
        } catch (PDOException $e) {
            error_log('allRoles: ' . $e->getMessage());
            $roles = [];
        }
    }
    if (!$roles) {
        $all = array_keys(permissionCatalog());
        $facBase = array_merge(['dashboard','members','attendance_records','serve_teams','service_attendance','facility_view','facility_submit'], LEGACY_PERMISSION_MAP['victory_groups'], LEGACY_PERMISSION_MAP['gathering_attendance'], LEGACY_PERMISSION_MAP['classes']);
        $roles = [
            ROLE_ADMIN      => ['id'=>0,'slug'=>ROLE_ADMIN,      'label'=>'Admin',                'description'=>'', 'is_system'=>1, 'permissions'=>$all],
            ROLE_EDITOR     => ['id'=>0,'slug'=>ROLE_EDITOR,     'label'=>'Editor',               'description'=>'', 'is_system'=>1, 'permissions'=>$facBase],
            ROLE_FACILITIES => ['id'=>0,'slug'=>ROLE_FACILITIES, 'label'=>'Facilities Personnel', 'description'=>'', 'is_system'=>1, 'permissions'=>array_merge($facBase, ['facility_review','facility_confirm','facility_cancel'])],
            ROLE_MINISTER   => ['id'=>0,'slug'=>ROLE_MINISTER,   'label'=>'Minister',             'description'=>'', 'is_system'=>1, 'permissions'=>array_merge($facBase, ['facility_approve'])],
        ];
    }
    return $GLOBALS['__vb_roles_cache'] = $roles;
}

/** Drop the per-request cache after a role is created, edited or deleted. */
function resetRolesCache() {
    $GLOBALS['__vb_roles_cache'] = null;
}

/** Every slug accounts.accounttype may hold — the write whitelist. */
function validAccountTypes() {
    return array_keys(allRoles());
}

/** slug => label for dropdowns and badges. */
function accountTypeLabels() {
    $out = [];
    foreach (allRoles() as $slug => $r) $out[$slug] = $r['label'];
    return $out;
}

/** slug => one-line description for the Users page role help. */
function accountTypeHelp() {
    $out = [];
    foreach (allRoles() as $slug => $r) {
        $out[$slug] = $slug === ROLE_ADMIN
            ? 'Everything, including users, roles and settings. May act on any facility stage; a Minister decision by an Admin is recorded as an override.'
            : (string)($r['description'] ?? '');
    }
    return $out;
}

/**
 * May this role do $permission? Admin always may. An unknown or null role may
 * not. $role defaults to the signed-in account's.
 */
function hasPermission($permission, $role = null) {
    $r = currentRole($role);
    if ($r === null) return false;
    if ($r === ROLE_ADMIN) return true;
    $roles = allRoles();
    return isset($roles[$r]) && in_array($permission, $roles[$r]['permissions'], true);
}

/** True when the role has at least one of the given permissions. */
function hasAnyPermission(array $permissions, $role = null) {
    foreach ($permissions as $p) if (hasPermission($p, $role)) return true;
    return false;
}

/**
 * Routed actions guarded by ONE fixed permission. The front controller
 * (index.php) consults this and requiredPermissionsFor() before every request,
 * so a new route cannot quietly skip the gate. Actions absent from both keep
 * whatever gate they already had (login only, or canAdminister()).
 */
const ACTION_PERMISSIONS = [
    // Members
    'members' => 'members', 'addMember' => 'members', 'editMember' => 'members',
    'updateMember' => 'members', 'deleteMember' => 'members', 'deactivateMember' => 'members',
    'activateMember' => 'members', 'memberProfile' => 'members',
    'memberGroupSave' => 'members', 'memberTeamSave' => 'members',
    // Group leaders list
    'groupLeaders' => 'group_leaders',
    // Public-site join requests inbox
    'joinRequests' => 'join_requests', 'updateJoinRequest' => 'join_requests', 'resendJoinNotice' => 'join_requests', 'deleteJoinRequest' => 'join_requests',
    // Victory-group statistics definitions live on the Victory Groups page
    'addVglStat' => 'group_vg', 'updateVglStat' => 'group_vg', 'deleteVglStat' => 'group_vg',
    'deactivateVglStat' => 'group_vg', 'activateVglStat' => 'group_vg', 'updateVglStatOrder' => 'group_vg',
    // Serve teams
    'serveTeams' => 'serve_teams', 'addServeTeam' => 'serve_teams', 'updateServeTeam' => 'serve_teams',
    'activateServeTeam' => 'serve_teams', 'deactivateServeTeam' => 'serve_teams', 'deleteServeTeam' => 'serve_teams',
    // Sunday service
    'serviceAttendance' => 'service_attendance', 'addServiceAttendance' => 'service_attendance',
    'updateServiceAttendance' => 'service_attendance', 'deleteServiceAttendance' => 'service_attendance',
    'saveServiceDay' => 'service_attendance', 'ajaxServiceDay' => 'service_attendance',
    // Dedicated class pages
    'leadership113' => 'class_leadership_113', 'addL113Record' => 'class_leadership_113',
    'updateL113Record' => 'class_leadership_113', 'deactivateL113Record' => 'class_leadership_113',
    'activateL113Record' => 'class_leadership_113', 'deleteL113Record' => 'class_leadership_113',
    'spiritualFoundations' => 'class_spiritual_foundations', 'addSfRecord' => 'class_spiritual_foundations',
    'updateSfRecord' => 'class_spiritual_foundations', 'deactivateSfRecord' => 'class_spiritual_foundations',
    'activateSfRecord' => 'class_spiritual_foundations', 'deleteSfRecord' => 'class_spiritual_foundations',
];

/** group_kind => the permission for that list. */
const GROUP_KIND_PERMISSIONS = [
    'victory_group' => 'group_vg', 'leadership_group' => 'group_lg', 'campus' => 'group_campus',
];

/** ?tab= value on the Groups page => permission. Stats tabs belong to Victory Groups. */
const GROUP_TAB_PERMISSIONS = [
    'vg' => 'group_vg', 'lg' => 'group_lg', 'campus' => 'group_campus',
    'stats' => 'group_vg', 'statDefs' => 'group_vg',
];

/** gathering_type => permission. */
const GATHERING_PERMISSIONS = [
    'prayer_meeting' => 'prayer_meeting', 'students_hangout' => 'students_hangout',
];

/** One column of one stored row, for the actions that carry only an id. Whitelisted. */
function permLookup($table, $column, $id) {
    $allowed = [
        'victory_groups'       => 'group_kind',
        'program_attendances'  => 'program_type',
        'gathering_attendances' => 'gathering_type',
    ];
    if (($allowed[$table] ?? null) !== $column) return null;
    $db = $GLOBALS['__vb_perm_db'] ?? null;
    if (!($db instanceof PDO) || (int)$id <= 0) return null;
    try {
        $stmt = $db->prepare("SELECT `$column` FROM `$table` WHERE id = ?");
        $stmt->execute([(int)$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (PDOException $e) {
        error_log('permLookup: ' . $e->getMessage());
        return null;
    }
}

/** The permission a class page needs, from its program_type; '' = the all-classes page. */
function classPermission($programType) {
    $programType = (string)$programType;
    if ($programType === '') return 'attendance_records';
    $key = 'class_' . $programType;
    return isset(permissionCatalog()[$key]) ? $key : 'attendance_records';
}

/**
 * What a request needs, resolved from the action plus the unit it targets:
 * the group tab / kind, the class program_type, the gathering type. Stored
 * rows are looked up when only an id is sent, so an id cannot be used to reach
 * a unit the role does not hold.
 *
 * @return array|null  ['all', [perms…]] every one needed,
 *                     ['any', [perms…]] at least one needed, or null = no gate here.
 */
function requiredPermissionsFor($action) {
    $get  = $_GET;
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : [];
    $val  = function ($k) use ($get, $post) { return (string)($post[$k] ?? $get[$k] ?? ''); };

    if (isset(ACTION_PERMISSIONS[$action])) return ['all', [ACTION_PERMISSIONS[$action]]];

    switch ($action) {
        // ── Groups: Victory Groups / Leadership Groups / Campus are separate ──
        case 'victoryGroups': {
            $tab = $val('tab') !== '' ? $val('tab') : 'vg';
            if (isset(GROUP_TAB_PERMISSIONS[$tab])) return ['all', [GROUP_TAB_PERMISSIONS[$tab]]];
            // Unknown tab: the list falls back to a group_kind filter (or Victory Groups).
            $kind = $val('group_kind');
            return ['all', [GROUP_KIND_PERMISSIONS[$kind] ?? 'group_vg']];
        }
        case 'addGroup': {
            $kind = (string)($post['group_kind'] ?? '');
            $p = $kind !== '' ? (GROUP_KIND_PERMISSIONS[$kind] ?? 'group_vg')
                              : (GROUP_TAB_PERMISSIONS[$val('tab') ?: 'vg'] ?? 'group_vg');
            return ['all', [$p]];
        }
        case 'editGroup': case 'deleteGroup': case 'activateGroup': case 'hardDeleteGroup': {
            $kind = permLookup('victory_groups', 'group_kind', $get['id'] ?? 0);
            return ['all', [GROUP_KIND_PERMISSIONS[$kind] ?? 'group_vg']];
        }
        case 'updateGroup': {
            $need = [GROUP_KIND_PERMISSIONS[permLookup('victory_groups', 'group_kind', $get['id'] ?? 0)] ?? 'group_vg'];
            if (!empty($post['group_kind'])) $need[] = GROUP_KIND_PERMISSIONS[$post['group_kind']] ?? 'group_vg';
            return ['all', array_values(array_unique($need))];
        }

        // ── Class attendance records, one permission per class ───────────────
        case 'attendanceRecords':
            return ['all', [classPermission($val('program_type'))]];
        case 'addAttendance':
            return ['all', [classPermission($post['program_type'] ?? '')]];
        case 'updateAttendance': {
            $need = [classPermission(permLookup('program_attendances', 'program_type', $get['id'] ?? 0) ?? '')];
            if (isset($post['program_type'])) $need[] = classPermission($post['program_type']);
            return ['all', array_values(array_unique($need))];
        }
        case 'deactivateAttendance': case 'activateAttendance': case 'deleteAttendance':
            return ['all', [classPermission(permLookup('program_attendances', 'program_type', $get['id'] ?? 0) ?? '')]];

        // ── Prayer Meeting and Students' Hangout are separate ────────────────
        case 'gatheringAttendance': {
            $t = $val('gathering_type');
            return isset(GATHERING_PERMISSIONS[$t])
                ? ['all', [GATHERING_PERMISSIONS[$t]]]
                : ['any', array_values(GATHERING_PERMISSIONS)];
        }
        case 'addGatheringAttendance': {
            $t = (string)($post['gathering_type'] ?? '');
            return ['all', [GATHERING_PERMISSIONS[$t] ?? 'prayer_meeting']];
        }
        case 'updateGatheringAttendance': {
            $old  = permLookup('gathering_attendances', 'gathering_type', $get['id'] ?? 0);
            $need = [GATHERING_PERMISSIONS[$old ?? ''] ?? 'prayer_meeting'];
            if (!empty($post['gathering_type'])) $need[] = GATHERING_PERMISSIONS[$post['gathering_type']] ?? 'prayer_meeting';
            return ['all', array_values(array_unique($need))];
        }
        case 'deleteGatheringAttendance': {
            $t = permLookup('gathering_attendances', 'gathering_type', $get['id'] ?? 0);
            return ['all', [GATHERING_PERMISSIONS[$t ?? ''] ?? 'prayer_meeting']];
        }

        // ── Member-name pickers: any discipleship section opens them ─────────
        case 'ajaxSearchMembers': case 'ajaxFindMemberByName': case 'ajaxCheckAttendanceDuplicate':
            return ['any', memberDataPermissions()];
    }
    return null;
}

/** May the account open this request? Requests with no mapped permission pass. */
function canAccessAction($action, $role = null) {
    $req = requiredPermissionsFor($action);
    if ($req === null) return true;
    list($mode, $perms) = $req;
    return $mode === 'any' ? hasAnyPermission($perms, $role) : hasAllPermissions($perms, $role);
}

/** True when the role has every one of the given permissions. */
function hasAllPermissions(array $permissions, $role = null) {
    foreach ($permissions as $p) if (!hasPermission($p, $role)) return false;
    return true;
}

/**
 * Member-name lookups (pickers on the group, team, attendance and class
 * forms). Shared by every discipleship section, so holding any of them opens
 * it — but a role limited to Facility Booking cannot search the membership.
 */
function memberDataPermissions() {
    $keys = ['members', 'attendance_records', 'serve_teams', 'service_attendance'];
    foreach (['Groups', 'Gatherings', 'Classes'] as $g) $keys = array_merge($keys, array_keys(PERMISSION_GROUPS[$g]));
    return $keys;
}

/** The permissions that open some page of the Groups / Gatherings / Classes families. */
function anyGroupPermission($role = null)     { return hasAnyPermission(array_keys(PERMISSION_GROUPS['Groups']), $role); }
function anyGatheringPermission($role = null) { return hasAnyPermission(array_keys(PERMISSION_GROUPS['Gatherings']), $role); }
function anyClassPermission($role = null)     { return hasAnyPermission(array_keys(PERMISSION_GROUPS['Classes']), $role); }


/**
 * The first page this account may land on after login / when redirected away
 * from somewhere forbidden. The dashboard is open to everyone signed in.
 */
function homeUrl() {
    return 'index.php';
}

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
    return accountTypeLabels()[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

function isValidAccountType($type) {
    return in_array($type, validAccountTypes(), true);
}

// ── Facility booking (Phase G) ───────────────────────────────────────────────

/**
 * See the calendar, the request list, a request's detail and its approval
 * history. Any logged-in account: the church wants the schedule visible so
 * ministries can see what the rooms are already doing before they ask for one.
 * Read-only — seeing a request is not being able to move it.
 */
function canViewFacilityBooking($role = null) {
    return hasPermission('facility_view', $role);
}

/**
 * Step 1 — submit a request, and edit one that has not been confirmed.
 * Any logged-in account: anyone in a ministry may ask for a room.
 */
function canSubmitFacilityRequest($role = null) {
    return hasPermission('facility_submit', $role);
}

/**
 * Step 2 — the Facilities logistical review (policy section 8).
 * Facilities Personnel, and admins because the portal must stay operable if
 * no facilities account is logged in.
 */
function canFacilitiesReview($role = null) {
    return hasPermission('facility_review', $role);
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
    return hasPermission('facility_approve', $role);
}

/**
 * True when a step-3 decision is made in the Admin capacity rather than by a
 * role that was given the approval permission on purpose. Only Admin is an
 * override: a custom role granted 'facility_approve' is a real approver.
 */
function ministerActsAsOverride($role = null) {
    return canMinisterApprove($role) && isAdmin($role);
}

/**
 * Step 4 — Facilities confirmation, the ONLY step that creates an official
 * reservation (policy section 20). Facilities Personnel and admins.
 */
function canFacilitiesConfirm($role = null) {
    return hasPermission('facility_confirm', $role);
}

/**
 * Cancel ANY request (policy section 18) — the staff capacity.
 *
 * Facilities Personnel and admins. A requestor may additionally cancel their
 * OWN request, which needs the request itself, so that rule lives in
 * canCancelThisFacilityRequest() below. Everyone else still cannot cancel.
 */
function canCancelFacilityRequest($role = null) {
    return hasPermission('facility_cancel', $role);
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

/** Add / edit / hide / delete rooms (Facility Booking > Rooms). Admin always; other roles by permission. */
function canManageFacilityRooms($role = null) {
    return hasPermission('facility_rooms', $role);
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
    return hasPermission('dashboard', $role);
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
