<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

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
require_once 'controllers/UserController.php';
require_once 'controllers/MemberController.php';
require_once 'controllers/VictoryGroupController.php';
require_once 'controllers/AttendanceController.php';
require_once 'controllers/SettingsController.php';
require_once 'controllers/VglStatController.php';

$db = getDB();
$userController = new UserController($db);
$memberController = new MemberController($db);
$groupController = new VictoryGroupController($db);
$statController  = new VglStatController($db);
$attendanceController = new AttendanceController($db);
$settingsController = new SettingsController($db);

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

    // ─── Victory Groups ───────────────────────────────────────────────
    case 'victoryGroups':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $groupController->listGroups();
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

    case 'editUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        $userController->editUser($_GET['id']);
        break;

    case 'updateUser':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
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

    // ─── AJAX: member search for Select2 ─────────────────────────────
    case 'ajaxSearchMembers':
        if (!isset($_SESSION['user'])) { http_response_code(401); exit(); }
        $term = trim($_GET['q'] ?? '');
        header('Content-Type: application/json');
        $memberModel = new Member($db);
        $rows = $memberModel->searchByName($term, 50);
        $results = array_map(fn($r) => [
            'id'   => $r['id'],
            'text' => $r['full_name'] . ($r['ministry'] ? ' — ' . strtoupper($r['ministry']) : ''),
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

    // ─── Settings (Admin only) ────────────────────────────────────────
    case 'settings':
        if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
        if ($currentUserType !== 'admin') { header('Location: index.php'); exit(); }
        $settingsController->showSettings();
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
