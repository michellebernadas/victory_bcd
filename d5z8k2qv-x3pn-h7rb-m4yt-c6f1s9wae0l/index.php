<?php
// E_STRICT was removed in PHP 8.4 and merely naming the constant raises a
// deprecation on every request — noisy in the log on PHP 8.5, which is what
// production runs. E_ALL has included its old meaning since PHP 5.4 anyway.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Catch what the ini settings above don't: uncaught exceptions and fatal
// errors (e.g. a PHP-version incompatibility in a required file) otherwise
// reach the server as a blank 500 with nothing in error.log.
set_exception_handler(function (Throwable $e) {
    error_log('[UNCAUGHT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
        . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo 'Something went wrong. The error has been logged.';
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[FATAL] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) http_response_code(500);
    }
});

// Session security
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'domain' => '',
    'secure' => false,     // Set to true when using HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);
ini_set('session.gc_maxlifetime', 3600);
session_start();

require_once 'config/config.php';
// The permission matrix. Required before the controllers so their own
// defence-in-depth checks can call the same helpers the routes below use.
require_once 'config/permissions.php';
require_once 'controllers/UserController.php';
require_once 'controllers/MemberController.php';
require_once 'controllers/VictoryGroupController.php';
require_once 'controllers/AttendanceController.php';
require_once 'controllers/SettingsController.php';
require_once 'controllers/VglStatController.php';
require_once 'controllers/FacilityController.php';

$db = getDB();
$userController = new UserController($db);
$memberController = new MemberController($db);
$groupController = new VictoryGroupController($db);
$statController  = new VglStatController($db);
$attendanceController = new AttendanceController($db);
$settingsController = new SettingsController($db);
$facilityController = new FacilityController($db);

$action = isset($_GET['action']) ? $_GET['action'] : '';

$currentUserType = $_SESSION['user']['accounttype'] ?? null;

function sanitizeInput($value) {
    return filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
}

switch ($action) {

    // ─── Authentication ──────────────────────────────────────────────
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user = $userController->login($_POST['username'], $_POST['password']);
            if (isset($user['error'])) {
                $_SESSION['login_error'] = $user['message'];
                header('Location: index.php?action=login');
                exit();
            } elseif ($user) {
                $_SESSION['user'] = $user;
                header('Location: index.php');
                exit();
            } else {
                $_SESSION['login_error'] = "Invalid username or password";
                header('Location: index.php?action=login');
                exit();
            }
        } else {
            $loginError = $_SESSION['login_error'] ?? null;
            $infoMessage = $_SESSION['info_message'] ?? null;
            unset($_SESSION['login_error'], $_SESSION['info_message']);
            include 'views/login.php';
        }
        break;

    case 'logout':
        $_SESSION['logout_message'] = "You have been successfully logged out.";
        session_unset();
        session_destroy();
        header("Location: index.php");
        exit();

    // ─── Members ─────────────────────────────────────────────────────
    case 'members':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $memberController->listMembers();
        break;

    case 'addMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $memberController->addMember($_POST);
        } else {
            $memberController->listMembers();
        }
        break;

    case 'editMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $memberController->editMember($_GET['id']);
        break;

    case 'updateMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $memberController->updateMember($_GET['id'], $_POST);
        } else {
            header('Location: index.php?action=members');
        }
        break;

    case 'deleteMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $memberController->deleteMember($_GET['id']);
        break;

    case 'deactivateMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $memberController->deactivateMember($_GET['id']);
        break;

    case 'activateMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $memberController->activateMember($_GET['id']);
        break;

    case 'memberProfile':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $memberController->memberProfile($_GET['id']);
        break;

    // Group / Serve Team membership from the member profile. Same gate as the
    // group and team pages (updateGroup / updateServeTeam): signed in.
    case 'memberGroupSave':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?action=members'); exit(); }
        $memberController->memberGroupSave();
        break;

    case 'memberTeamSave':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?action=members'); exit(); }
        $memberController->memberTeamSave();
        break;

    // ─── Member import: UPLOAD → PREVIEW → CONFIRM → IMPORT ──────────────
    // Replaces the old top-level import.php, which required no login and
    // deleted whole tables before inserting. Admin-only: a bad import is
    // laborious to undo even with the preview.
    case 'memberImport':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=members'); exit(); }
        require_once 'controllers/MemberImportController.php';
        (new MemberImportController($db))->showUpload();
        break;

    case 'memberImportUpload':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=members'); exit(); }
        require_once 'controllers/MemberImportController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new MemberImportController($db))->upload();
        } else {
            header('Location: index.php?action=memberImport');
        }
        break;

    case 'memberImportCommit':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=members'); exit(); }
        require_once 'controllers/MemberImportController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new MemberImportController($db))->commit();
        } else {
            header('Location: index.php?action=memberImport');
        }
        break;

    case 'memberImportDiscard':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=members'); exit(); }
        require_once 'controllers/MemberImportController.php';
        (new MemberImportController($db))->discard();
        break;

    case 'memberImportReview':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=members'); exit(); }
        require_once 'controllers/MemberImportController.php';
        (new MemberImportController($db))->downloadReview();
        break;

    // ─── Staff VG Schedule import: UPLOAD → PREVIEW → CONFIRM → IMPORT ───
    // Same staged shape as memberImport above. Admin-only. Creates groups and
    // their people; never creates a member — an unmatched name is saved
    // verbatim as "Needs Link" for an admin to resolve by hand.
    case 'groupImport':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=victoryGroups'); exit(); }
        require_once 'controllers/GroupImportController.php';
        (new GroupImportController($db))->showUpload();
        break;

    case 'groupImportUpload':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=victoryGroups'); exit(); }
        require_once 'controllers/GroupImportController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new GroupImportController($db))->upload();
        } else {
            header('Location: index.php?action=groupImport');
        }
        break;

    case 'groupImportCommit':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=victoryGroups'); exit(); }
        require_once 'controllers/GroupImportController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new GroupImportController($db))->commit();
        } else {
            header('Location: index.php?action=groupImport');
        }
        break;

    case 'groupImportDiscard':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=victoryGroups'); exit(); }
        require_once 'controllers/GroupImportController.php';
        (new GroupImportController($db))->discard();
        break;

    // ─── Facilities Request Form import ──────────────────────────────────
    // Same staged shape again. Every imported request lands at Submitted; the
    // importer has no path to approved or confirmed (see
    // FacilityRequestImport::commit).
    case 'facilityImport':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=facilityCalendar'); exit(); }
        require_once 'controllers/FacilityImportController.php';
        (new FacilityImportController($db))->showUpload();
        break;

    case 'facilityImportUpload':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=facilityCalendar'); exit(); }
        require_once 'controllers/FacilityImportController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new FacilityImportController($db))->upload();
        } else {
            header('Location: index.php?action=facilityImport');
        }
        break;

    case 'facilityImportCommit':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=facilityCalendar'); exit(); }
        require_once 'controllers/FacilityImportController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            (new FacilityImportController($db))->commit();
        } else {
            header('Location: index.php?action=facilityImport');
        }
        break;

    // One-row-per-request import template, generated live (rooms, ministries).
    case 'facilityImportTemplate':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canImportFacilityRequests($currentUserType)) { header('Location: index.php?action=facilityCalendar'); exit(); }
        require_once 'controllers/FacilityImportController.php';
        (new FacilityImportController($db))->downloadTemplate();
        break;

    case 'facilityImportDiscard':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=facilityCalendar'); exit(); }
        require_once 'controllers/FacilityImportController.php';
        (new FacilityImportController($db))->discard();
        break;

    // ─── Victory Groups ───────────────────────────────────────────────
    case 'victoryGroups':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $groupController->listGroups();
        break;

    // Read-only list, same gate as the group lists it is built from.
    case 'groupLeaders':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $groupController->groupLeaders();
        break;

    case 'addGroup':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $groupController->addGroup($_POST);
        } else {
            $groupController->listGroups();
        }
        break;

    case 'editGroup':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $groupController->editGroup($_GET['id']);
        break;

    case 'updateGroup':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $groupController->updateGroup($_GET['id'], $_POST);
        } else {
            header('Location: index.php?action=victoryGroups');
        }
        break;

    case 'deleteGroup':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $groupController->deleteGroup($_GET['id']);
        break;

    case 'activateGroup':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $groupController->activateGroup($_GET['id']);
        break;

    case 'hardDeleteGroup':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $groupController->hardDeleteGroup($_GET['id']);
        break;

    // ─── Manual person linking on a group roster ─────────────────────────
    // The only way a name imported as "Needs Link" ever gains a member, and the
    // only way a couple ("Amy & Carl") is resolved — one row, two slots, two
    // separate hand-made links. Nothing here creates a member or alters the
    // verbatim name, so it is admin-only but not destructive.
    case 'linkVgMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canAdminister()) { header('Location: index.php?action=victoryGroups'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $groupController->linkVgMember();
        } else {
            header('Location: index.php?action=victoryGroups');
        }
        break;

    case 'unlinkVgMember':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canAdminister()) { header('Location: index.php?action=victoryGroups'); exit(); }
        $groupController->unlinkVgMember();
        break;

    // ─── Needs Link: every unresolved imported name on one page ──────────
    // Same admin gate as linkVgMember/unlinkVgMember above, and the same model
    // methods underneath; needsLinkSave adds "Not a church member" (reversible).
    case 'needsLink':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canAdminister()) { header('Location: index.php?action=victoryGroups'); exit(); }
        $groupController->needsLink();
        break;

    case 'needsLinkSave':
        if (!isset($_SESSION['user'])) {
            if (!empty($_POST['ajax'])) { http_response_code(401); exit(); }
            header('Location: index.php?action=login'); exit();
        }
        if (!canAdminister()) {
            if (!empty($_POST['ajax'])) { http_response_code(403); exit(); }
            header('Location: index.php?action=victoryGroups'); exit();
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?action=needsLink'); exit(); }
        $groupController->needsLinkSave();
        break;

    // ─── VGL Stat Definitions ────────────────────────────────────────
    case 'addVglStat':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $statController->addStat($_POST);
        } else {
            header('Location: index.php?action=victoryGroups&tab=stats');
        }
        break;

    case 'updateVglStat':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $statController->updateStat($_GET['id'], $_POST);
        } else {
            header('Location: index.php?action=victoryGroups&tab=stats');
        }
        break;

    case 'deleteVglStat':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $statController->deleteStat($_GET['id']);
        break;

    case 'deactivateVglStat':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $statController->deactivateStat($_GET['id']);
        break;

    case 'activateVglStat':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $statController->activateStat($_GET['id']);
        break;

    case 'updateVglStatOrder':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $statController->updateOrder();
        }
        break;

    // ─── Users (Admin only) ──────────────────────────────────────────
    case 'users':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $userController->listUsers();
        break;

    case 'addUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $userController->addUser($_POST['username'], $_POST['password'], $_POST['email'], $_POST['accounttype']);
        } else {
            $userController->listUsers();
        }
        break;

    // These two were the only user-management routes without a role check.
    // editUser only renders a view that redirects non-admins, but updateUser
    // acts directly: any logged-in account could POST to it and set its own
    // accounttype to 'admin'. Closed here rather than left for the new role to
    // walk through.
    case 'editUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canAdminister($currentUserType)) { header('Location: index.php'); exit(); }
        $userController->editUser($_GET['id']);
        break;

    case 'updateUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canAdminister($currentUserType)) { header('Location: index.php'); exit(); }
        if (isset($_GET['uuid'])) {
            $userController->updateUserByUuid($_POST['username'], $_POST['password'], $_POST['email'], $_POST['accounttype'], $_GET['uuid']);
        } else {
            $userController->updateUser($_POST['username'], $_POST['password'], $_POST['email'], $_POST['accounttype'], $_GET['id']);
        }
        break;

    case 'deleteUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if (isset($_GET['uuid'])) {
            $userController->deleteUserByUuid($_GET['uuid']);
        } else {
            $userController->deleteUser($_GET['id']);
        }
        break;

    case 'activateUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        isset($_GET['uuid']) ? $userController->activateUserByUuid($_GET['uuid']) : $userController->activateUser($_GET['id']);
        break;

    case 'deactivateUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        isset($_GET['uuid']) ? $userController->deactivateUserByUuid($_GET['uuid']) : $userController->deactivateUser($_GET['id']);
        break;

    // ─── Attendance Records ───────────────────────────────────────────
    case 'attendanceRecords':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $attendanceController->listAttendances();
        break;
    case 'addAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') $attendanceController->addAttendance($_POST);
        else { header('Location: index.php?action=attendanceRecords'); exit(); }
        break;
    case 'updateAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $atId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $atId) $attendanceController->updateAttendance($atId, $_POST);
        else { header('Location: index.php?action=attendanceRecords'); exit(); }
        break;
    case 'deactivateAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $atId = (int)($_GET['id'] ?? 0);
        if ($atId) $attendanceController->deactivateAttendance($atId);
        else { header('Location: index.php?action=attendanceRecords'); exit(); }
        break;
    case 'activateAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $atId = (int)($_GET['id'] ?? 0);
        if ($atId) $attendanceController->activateAttendance($atId);
        else { header('Location: index.php?action=attendanceRecords'); exit(); }
        break;
    case 'deleteAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $atId = (int)($_GET['id'] ?? 0);
        if ($atId) $attendanceController->deleteAttendance($atId);
        else { header('Location: index.php?action=attendanceRecords'); exit(); }
        break;

    // ─── Sunday Service Attendance (Church Health) ───────────────────
    case 'serviceAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServiceAttendanceController.php';
        (new ServiceAttendanceController($db))->listRecords();
        break;
    case 'addServiceAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServiceAttendanceController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new ServiceAttendanceController($db))->addRecord($_POST);
        else { header('Location: index.php?action=serviceAttendance'); exit(); }
        break;
    case 'updateServiceAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServiceAttendanceController.php';
        $saId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $saId) (new ServiceAttendanceController($db))->updateRecord($saId, $_POST);
        else { header('Location: index.php?action=serviceAttendance'); exit(); }
        break;
    case 'deleteServiceAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServiceAttendanceController.php';
        $saId = (int)($_GET['id'] ?? 0);
        if ($saId) (new ServiceAttendanceController($db))->deleteRecord($saId);
        else { header('Location: index.php?action=serviceAttendance'); exit(); }
        break;
    // "Enter a Sunday" — every service time of one date, saved in one transaction.
    case 'saveServiceDay':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServiceAttendanceController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new ServiceAttendanceController($db))->saveDay($_POST);
        else { header('Location: index.php?action=serviceAttendance'); exit(); }
        break;
    case 'ajaxServiceDay':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        require_once 'controllers/ServiceAttendanceController.php';
        (new ServiceAttendanceController($db))->ajaxDay();
        break;

    // ─── Prayer Meeting & Students' Hangout attendance ───────────────
    // Same gate as Sunday attendance: any logged-in account.
    case 'gatheringAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/GatheringAttendanceController.php';
        (new GatheringAttendanceController($db))->listRecords();
        break;
    case 'addGatheringAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/GatheringAttendanceController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new GatheringAttendanceController($db))->addRecord($_POST);
        else { header('Location: index.php?action=gatheringAttendance'); exit(); }
        break;
    case 'updateGatheringAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/GatheringAttendanceController.php';
        $gaId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $gaId) (new GatheringAttendanceController($db))->updateRecord($gaId, $_POST);
        else { header('Location: index.php?action=gatheringAttendance'); exit(); }
        break;
    case 'deleteGatheringAttendance':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/GatheringAttendanceController.php';
        $gaId = (int)($_GET['id'] ?? 0);
        if ($gaId) (new GatheringAttendanceController($db))->deleteRecord($gaId);
        else { header('Location: index.php?action=gatheringAttendance'); exit(); }
        break;

    // ─── Worship Service Annual Attendance import (staged, admin-only) ─
    case 'attendanceImport':
    case 'attendanceImportUpload':
    case 'attendanceImportCommit':
    case 'attendanceImportDiscard':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canAdminister($currentUserType)) { header('Location: index.php?action=serviceAttendance'); exit(); }
        require_once 'controllers/AttendanceImportController.php';
        $aiCtl = new AttendanceImportController($db);
        if ($action === 'attendanceImport') $aiCtl->showUpload();
        elseif ($action === 'attendanceImportDiscard') $aiCtl->discard();
        elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php?action=attendanceImport'); exit(); }
        elseif ($action === 'attendanceImportUpload') $aiCtl->upload();
        else $aiCtl->commit();
        break;

    // ─── AJAX: member search for Select2 ─────────────────────────────
    case 'ajaxSearchMembers':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        $term = trim($_GET['q'] ?? '');
        header('Content-Type: application/json');
        $memberModel = new Member($db);
        $rows = $memberModel->searchByName($term, 50);
        // first_name / last_name ride along so the form can fill the name fields
        // the moment a member is picked — the admin never retypes a name that
        // the member record already holds.
        $results = array_map(fn($r) => [
            'id'         => $r['id'],
            'text'       => $r['full_name'] . ($r['ministry'] ? ' — ' . strtoupper($r['ministry']) : ''),
            'first_name' => $r['first_name'] ?? '',
            'last_name'  => $r['last_name']  ?? '',
            'full_name'  => $r['full_name']  ?? '',
        ], $rows);
        echo json_encode(['results' => $results]);
        exit();

    // ─── AJAX: exact-match member lookup by canonical "Lastname, Firstname" ───
    case 'ajaxFindMemberByName':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        $fullName = trim($_GET['name'] ?? '');
        header('Content-Type: application/json');
        if ($fullName === '') { echo json_encode(['match' => null]); exit(); }
        $memberModel = new Member($db);
        $row = $memberModel->findByFullName($fullName);
        echo json_encode(['match' => $row ? [
            'id'       => (int)$row['id'],
            'name'     => $row['full_name'],
            'ministry' => $row['ministry'] ?? '',
        ] : null]);
        exit();

    // ─── AJAX: check if a member already has an attendance record for class+year ───
    case 'ajaxCheckAttendanceDuplicate':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        $memberId = (int)($_GET['member_id'] ?? 0);
        $pt       = trim($_GET['program_type'] ?? '');
        $yr       = (int)($_GET['program_year'] ?? 0);
        $excludeId = (int)($_GET['exclude_id'] ?? 0); // when editing, skip the row being edited
        header('Content-Type: application/json');
        if (!$memberId || !$pt || !$yr) { echo json_encode(['exists' => false]); exit(); }
        try {
            $sql = "SELECT COUNT(*) FROM program_attendances
                    WHERE member_id = ? AND program_type = ? AND program_year = ?
                      AND is_deleted = 0 AND status = 'active'";
            $params = [$memberId, $pt, $yr];
            if ($excludeId > 0) { $sql .= " AND id <> ?"; $params[] = $excludeId; }
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $cnt = (int)$stmt->fetchColumn();
            echo json_encode(['exists' => $cnt > 0, 'count' => $cnt]);
        } catch (PDOException $e) {
            echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
        }
        exit();

    // ─── Leadership 113 (Classes) ─────────────────────────────────────
    case 'leadership113':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/Leadership113Controller.php';
        (new Leadership113Controller($db))->listRecords();
        break;
    case 'addL113Record':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/Leadership113Controller.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new Leadership113Controller($db))->addRecord($_POST);
        else { header('Location: index.php?action=leadership113'); exit(); }
        break;
    case 'updateL113Record':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/Leadership113Controller.php';
        $l113Id = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $l113Id) (new Leadership113Controller($db))->updateRecord($l113Id, $_POST);
        else { header('Location: index.php?action=leadership113'); exit(); }
        break;
    case 'deactivateL113Record':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/Leadership113Controller.php';
        $l113Id = (int)($_GET['id'] ?? 0);
        if ($l113Id) (new Leadership113Controller($db))->deactivateRecord($l113Id);
        else { header('Location: index.php?action=leadership113'); exit(); }
        break;
    case 'activateL113Record':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/Leadership113Controller.php';
        $l113Id = (int)($_GET['id'] ?? 0);
        if ($l113Id) (new Leadership113Controller($db))->activateRecord($l113Id);
        else { header('Location: index.php?action=leadership113'); exit(); }
        break;
    case 'deleteL113Record':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/Leadership113Controller.php';
        $l113Id = (int)($_GET['id'] ?? 0);
        if ($l113Id) (new Leadership113Controller($db))->deleteRecord($l113Id);
        else { header('Location: index.php?action=leadership113'); exit(); }
        break;

    // ─── Spiritual Foundations (Classes) ──────────────────────────────
    case 'spiritualFoundations':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        (new SpiritualFoundationsController($db))->listRecords();
        break;
    case 'addSfRecord':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new SpiritualFoundationsController($db))->addRecord($_POST);
        else { header('Location: index.php?action=spiritualFoundations'); exit(); }
        break;
    case 'updateSfRecord':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        $sfId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sfId) (new SpiritualFoundationsController($db))->updateRecord($sfId, $_POST);
        else { header('Location: index.php?action=spiritualFoundations'); exit(); }
        break;
    case 'deactivateSfRecord':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        $sfId = (int)($_GET['id'] ?? 0);
        if ($sfId) (new SpiritualFoundationsController($db))->deactivateRecord($sfId);
        else { header('Location: index.php?action=spiritualFoundations'); exit(); }
        break;
    case 'activateSfRecord':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        $sfId = (int)($_GET['id'] ?? 0);
        if ($sfId) (new SpiritualFoundationsController($db))->activateRecord($sfId);
        else { header('Location: index.php?action=spiritualFoundations'); exit(); }
        break;
    case 'deleteSfRecord':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        $sfId = (int)($_GET['id'] ?? 0);
        if ($sfId) (new SpiritualFoundationsController($db))->deleteRecord($sfId);
        else { header('Location: index.php?action=spiritualFoundations'); exit(); }
        break;

    // ─── Spiritual Foundations curriculum (Admin only) ────────────────
    case 'addSfTopic':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=spiritualFoundations&tab=curriculum'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new SpiritualFoundationsController($db))->addTopic($_POST);
        else { header('Location: index.php?action=spiritualFoundations&tab=curriculum'); exit(); }
        break;
    case 'updateSfTopic':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=spiritualFoundations&tab=curriculum'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        $sfTopicId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $sfTopicId) (new SpiritualFoundationsController($db))->updateTopic($sfTopicId, $_POST);
        else { header('Location: index.php?action=spiritualFoundations&tab=curriculum'); exit(); }
        break;
    case 'activateSfTopic':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=spiritualFoundations&tab=curriculum'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        (new SpiritualFoundationsController($db))->activateTopic((int)($_GET['id'] ?? 0));
        break;
    case 'deactivateSfTopic':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=spiritualFoundations&tab=curriculum'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        (new SpiritualFoundationsController($db))->deactivateTopic((int)($_GET['id'] ?? 0));
        break;
    case 'deleteSfTopic':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=spiritualFoundations&tab=curriculum'); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        (new SpiritualFoundationsController($db))->deleteTopic((int)($_GET['id'] ?? 0));
        break;
    case 'reorderSfTopics':
        if (!isset($_SESSION['user']) || $currentUserType !== 'admin') { http_response_code(403); exit(); }
        require_once 'controllers/SpiritualFoundationsController.php';
        $sfIds = $_POST['ids'] ?? [];
        (new SpiritualFoundationsController($db))->reorderTopics(is_array($sfIds) ? $sfIds : []);
        break;

    // ─── Serve Teams ──────────────────────────────────────────────────
    case 'serveTeams':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        (new ServeTeamController($db))->listTeams();
        break;
    case 'addServeTeam':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new ServeTeamController($db))->addTeam($_POST);
        else { header('Location: index.php?action=serveTeams'); exit(); }
        break;
    case 'updateServeTeam':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        $stId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $stId) (new ServeTeamController($db))->updateTeam($stId, $_POST);
        else { header('Location: index.php?action=serveTeams'); exit(); }
        break;
    case 'activateServeTeam':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        (new ServeTeamController($db))->activateTeam((int)($_GET['id'] ?? 0));
        break;
    case 'deactivateServeTeam':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        (new ServeTeamController($db))->deactivateTeam((int)($_GET['id'] ?? 0));
        break;
    case 'deleteServeTeam':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        (new ServeTeamController($db))->deleteTeam((int)($_GET['id'] ?? 0));
        break;

    // ─── Serve Team Service / Place dropdown values (Admin only) ──────
    case 'addServeOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=serveTeams&tab=options'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') (new ServeTeamController($db))->addOption($_POST);
        else { header('Location: index.php?action=serveTeams&tab=options'); exit(); }
        break;
    case 'updateServeOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=serveTeams&tab=options'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        $soId = (int)($_GET['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $soId) (new ServeTeamController($db))->updateOption($soId, $_POST);
        else { header('Location: index.php?action=serveTeams&tab=options'); exit(); }
        break;
    case 'activateServeOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=serveTeams&tab=options'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        (new ServeTeamController($db))->activateOption((int)($_GET['id'] ?? 0));
        break;
    case 'deactivateServeOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=serveTeams&tab=options'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        (new ServeTeamController($db))->deactivateOption((int)($_GET['id'] ?? 0));
        break;
    case 'deleteServeOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php?action=serveTeams&tab=options'); exit(); }
        require_once 'controllers/ServeTeamController.php';
        (new ServeTeamController($db))->deleteOption((int)($_GET['id'] ?? 0));
        break;

    // ─── Settings (Admin only) ────────────────────────────────────────
    case 'settings':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->showSettings();
        break;

    // ─── Historical discipleship completions (Admin only) ────────────
    case 'addHistoricalCompletion':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->addHistoricalCompletion($_POST);
        } else {
            header('Location: index.php?action=settings&tab=historical');
        }
        break;

    case 'verifyHistoricalCompletion':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->verifyHistoricalCompletion(
            (int)($_POST['member_id'] ?? 0), (int)($_POST['step_id'] ?? 0), $_POST['notes'] ?? null
        );
        break;

    case 'rejectHistoricalCompletion':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->rejectHistoricalCompletion(
            (int)($_POST['member_id'] ?? 0), (int)($_POST['step_id'] ?? 0), $_POST['notes'] ?? null
        );
        break;

    case 'reopenHistoricalCompletion':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->reopenHistoricalCompletion(
            (int)($_POST['member_id'] ?? 0), (int)($_POST['step_id'] ?? 0), $_POST['notes'] ?? null
        );
        break;

    case 'restoreHistoricalCompletion':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->restoreHistoricalCompletion(
            (int)($_POST['member_id'] ?? 0), (int)($_POST['step_id'] ?? 0), $_POST['notes'] ?? null
        );
        break;

    case 'ajaxHistoricalReviewHistory':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        if ($currentUserType !== 'admin') { http_response_code(403); exit(); }
        $settingsController->ajaxHistoricalReviewHistory(
            (int)($_GET['member_id'] ?? 0), (int)($_GET['step_id'] ?? 0)
        );
        break;

    case 'recalculateProgress':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->recalculateAllProgress();
        break;

    case 'addMinistry':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->addMinistry($_POST['name'] ?? '');
        } else {
            header('Location: index.php?action=settings&tab=ministries');
        }
        break;

    case 'updateMinistry':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->updateMinistry($_GET['id'] ?? 0, $_POST['name'] ?? '');
        } else {
            header('Location: index.php?action=settings&tab=ministries');
        }
        break;

    case 'deleteMinistry':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deleteMinistry($_GET['id'] ?? 0);
        break;

    case 'deactivateMinistry':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deactivateMinistry($_GET['id'] ?? 0);
        break;

    case 'activateMinistry':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->activateMinistry($_GET['id'] ?? 0);
        break;

    case 'addService':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->addService($_POST['name'] ?? '');
        } else {
            header('Location: index.php?action=settings&tab=services');
        }
        break;

    case 'updateService':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->updateService($_GET['id'] ?? 0, $_POST['name'] ?? '');
        } else {
            header('Location: index.php?action=settings&tab=services');
        }
        break;

    case 'deleteService':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deleteService($_GET['id'] ?? 0);
        break;

    case 'deactivateService':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deactivateService($_GET['id'] ?? 0);
        break;

    case 'activateService':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->activateService($_GET['id'] ?? 0);
        break;

    case 'addDiscipleshipStep':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->addDiscipleshipStep(
                $_POST['name'] ?? '',
                $_POST['abbreviation'] ?? '',
                $_POST['icon'] ?? '',
                $_POST['color'] ?? ''
            );
        } else {
            header('Location: index.php?action=settings&tab=discipleship');
        }
        break;

    case 'updateDiscipleshipStep':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->updateDiscipleshipStep(
                $_GET['id'] ?? 0,
                $_POST['name'] ?? '',
                $_POST['abbreviation'] ?? '',
                $_POST['icon'] ?? '',
                $_POST['color'] ?? ''
            );
        } else {
            header('Location: index.php?action=settings&tab=discipleship');
        }
        break;

    case 'deleteDiscipleshipStep':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deleteDiscipleshipStep($_GET['id'] ?? 0);
        break;

    case 'deactivateDiscipleshipStep':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deactivateDiscipleshipStep($_GET['id'] ?? 0);
        break;

    case 'activateDiscipleshipStep':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->activateDiscipleshipStep($_GET['id'] ?? 0);
        break;

    case 'addVgOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->addVgOption($_POST);
        } else {
            header('Location: index.php?action=settings&tab=vgoptions');
        }
        break;

    case 'updateVgOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $settingsController->updateVgOption($_GET['id'], $_POST);
        } else {
            header('Location: index.php?action=settings&tab=vgoptions');
        }
        break;

    case 'deleteVgOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deleteVgOption($_GET['id']);
        break;

    case 'deactivateVgOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->deactivateVgOption($_GET['id']);
        break;

    case 'reorderMinistries':
        if (!isset($_SESSION['user']) || $currentUserType !== 'admin') { http_response_code(403); exit(); }
        $ids = $_POST['ids'] ?? [];
        $settingsController->reorderMinistries(is_array($ids) ? array_map('intval', $ids) : []);
        break;
    case 'reorderServices':
        if (!isset($_SESSION['user']) || $currentUserType !== 'admin') { http_response_code(403); exit(); }
        $ids = $_POST['ids'] ?? [];
        $settingsController->reorderServices(is_array($ids) ? array_map('intval', $ids) : []);
        break;
    case 'reorderDiscipleshipSteps':
        if (!isset($_SESSION['user']) || $currentUserType !== 'admin') { http_response_code(403); exit(); }
        $ids = $_POST['ids'] ?? [];
        $settingsController->reorderDiscipleshipSteps(is_array($ids) ? array_map('intval', $ids) : []);
        break;
    case 'reorderVgOptions':
        if (!isset($_SESSION['user']) || $currentUserType !== 'admin') { http_response_code(403); exit(); }
        $ids = $_POST['ids'] ?? [];
        $settingsController->reorderVgOptions(is_array($ids) ? array_map('intval', $ids) : []);
        break;

    case 'activateVgOption':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->activateVgOption($_GET['id']);
        break;

    // ─── Facility Booking (Phase G) ──────────────────────────────────
    // Calendar + the four policy steps: Requestor -> Facilities Review ->
    // Minister -> Facilities Confirmation. Each transition is its own action
    // so the append-only history gets exactly one row per decision.
    //
    // Every gate below asks config/permissions.php rather than comparing an
    // accounttype string, so the matrix lives in exactly one file. The
    // controller re-checks the same helpers — a route guard alone would be
    // bypassable the moment a method is called from anywhere else.
    case 'facilityCalendar':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canViewFacilityBooking($currentUserType)) { header('Location: index.php'); exit(); }
        $facilityController->showCalendar();
        break;

    case 'submitFacilityRequest':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canSubmitFacilityRequest($currentUserType)) { header('Location: index.php'); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facilityController->submitRequest($_POST);
        } else {
            header('Location: index.php?action=facilityCalendar');
        }
        break;

    case 'updateFacilityRequest':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        // Owner only, while submitted / for_clarification (canEditFacilityRequest).
        $fbReq = (new FacilityRequest($db))->getRequestById((int)($_GET['id'] ?? 0));
        if (!$fbReq || !canEditFacilityRequest($fbReq, $currentUserType)) { header('Location: index.php?action=facilityCalendar&tab=requests&error=1&msg=' . urlencode('Only the person who submitted this request can edit it, and only before Facilities has reviewed it.')); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facilityController->updateRequest((int)($_GET['id'] ?? 0), $_POST);
        } else {
            header('Location: index.php?action=facilityCalendar');
        }
        break;

    case 'facilitiesReviewRequest':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canFacilitiesReview($currentUserType)) { header('Location: index.php?action=facilityCalendar&tab=requests&error=1&msg=' . urlencode('Only Facilities Personnel may perform the Facilities review.')); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facilityController->facilitiesReview((int)($_GET['id'] ?? 0), $_POST);
        } else {
            header('Location: index.php?action=facilityCalendar');
        }
        break;

    case 'ministerDecideRequest':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        // Admin only. Facilities Personnel are deliberately excluded (policy 5.2 / 6.2).
        if (!canMinisterApprove($currentUserType)) { header('Location: index.php?action=facilityCalendar&tab=requests&error=1&msg=' . urlencode('Only the Minister may approve or decline a request.')); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facilityController->ministerDecision(
                (int)($_GET['id'] ?? 0), $_POST['decision'] ?? '', $_POST['minister_comments'] ?? null
            );
        } else {
            header('Location: index.php?action=facilityCalendar');
        }
        break;

    case 'confirmFacilityRequest':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canFacilitiesConfirm($currentUserType)) { header('Location: index.php?action=facilityCalendar&tab=requests&error=1&msg=' . urlencode('Only Facilities Personnel may confirm a reservation.')); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facilityController->confirmRequest(
                (int)($_GET['id'] ?? 0), $_POST['notes'] ?? null, !empty($_POST['force'])
            );
        } else {
            header('Location: index.php?action=facilityCalendar');
        }
        break;

    case 'cancelFacilityRequest':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        // Facilities / admin: any request. The owner (submitted_by): their own,
        // before its date has passed (canCancelThisFacilityRequest).
        $fbReq = (new FacilityRequest($db))->getRequestById((int)($_GET['id'] ?? 0));
        if (!$fbReq || !canCancelThisFacilityRequest($fbReq, $currentUserType)) { header('Location: index.php?action=facilityCalendar&tab=requests&error=1&msg=' . urlencode('Ask Facilities Personnel to cancel this request.')); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $facilityController->cancelRequest((int)($_GET['id'] ?? 0), $_POST['notes'] ?? null);
        } else {
            header('Location: index.php?action=facilityCalendar');
        }
        break;

    // Soft delete / restore: admin only, for mistakes (test rows, duplicates).
    case 'deleteFacilityRequest':
    case 'restoreFacilityRequest':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $fbDel = ($action === 'deleteFacilityRequest');
        if (!($fbDel ? canDeleteFacilityRequest($currentUserType) : canRestoreFacilityRequest($currentUserType))) { header('Location: index.php?action=facilityCalendar&tab=requests&error=1&msg=' . urlencode('Only an admin may delete or restore a request.')); exit(); }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $fbDel ? $facilityController->deleteRequest((int)($_GET['id'] ?? 0), $_POST['reason'] ?? '')
                   : $facilityController->restoreRequest((int)($_GET['id'] ?? 0));
        } else {
            header('Location: index.php?action=facilityCalendar');
        }
        break;

    // Weekly setup sheet export (CSV / Excel) — confirmed bookings only.
    case 'facilitySetupSheetExport':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canViewFacilitySetupSheet($currentUserType)) { header('Location: index.php'); exit(); }
        $facilityController->exportSetupSheet(($_GET['format'] ?? '') === 'xlsx' ? 'xlsx' : 'csv', (string)($_GET['week'] ?? ''));
        break;

    // Repeat weekly preview (POST of the request form, JSON back).
    case 'ajaxFacilitySeriesPreview':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        if (!canRepeatFacilityRequest($currentUserType) || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(403); exit(); }
        $facilityController->ajaxSeriesPreview($_POST);
        break;

    // Read-only AJAX. Any logged-in account, including one that cannot act on
    // the workflow — a person who may not confirm may still need to read why a
    // room is taken and who decided it.
    case 'ajaxFacilityRequestHistory':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        if (!canViewFacilityBooking($currentUserType)) { http_response_code(403); exit(); }
        $facilityController->ajaxRequestHistory((int)($_GET['id'] ?? 0));
        break;

    case 'ajaxFacilityRequestDetail':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        if (!canViewFacilityBooking($currentUserType)) { http_response_code(403); exit(); }
        $facilityController->ajaxRequestDetail((int)($_GET['id'] ?? 0));
        break;

    case 'ajaxFacilityAvailability':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        if (!canViewFacilityBooking($currentUserType)) { http_response_code(403); exit(); }
        $facilityController->ajaxCheckAvailability();
        break;

    // Church Health (dashboard) -> Excel. Same gate as the dashboard itself.
    case 'churchHealthExport':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if (!canViewDashboard($currentUserType)) { header('Location: index.php'); exit(); }
        require_once 'controllers/ChurchHealthExportController.php';
        (new ChurchHealthExportController($db))->download($_GET, (string)($_SESSION['user']['username'] ?? ''));
        break;

    // ─── Default / Dashboard ─────────────────────────────────────────
    default:
        if (isset($_SESSION['user'])) {
            include 'views/dashboard.php';
        } else {
            include 'views/login.php';
        }
        break;
}
?>
