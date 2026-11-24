<?php
require_once 'models/User.php';
require_once 'models/Mailer.php';
require_once __DIR__ . '/../config/permissions.php';

class UserController {
    private $db;
    private $userModel;

    public function __construct($db) {
        $this->db = $db;
        $this->userModel = new User($db);
    }

    public function login($username, $password) {
        return $this->userModel->login($username, $password);
    }

    public function listUsers() {
        $users = $this->userModel->getAllUsers();
        include 'views/users.php';
    }

    public function addUser($username, $password, $email, $accounttype) {
        // Validate required fields
        if (empty($username) || empty($password) || empty($email) || empty($accounttype)) {
            header('Location: index.php?action=users&error=add&msg=' . urlencode('All fields are required'));
            exit();
        }

        // Validate accounttype against the one whitelist in config/permissions.php
        // (validAccountTypes(), read from the roles table), so adding a role
        // never means remembering to widen a literal array here as well.
        if (!isValidAccountType($accounttype)) {
            header('Location: index.php?action=users&error=add&msg=' . urlencode('Invalid account type'));
            exit();
        }

        if ($problem = User::passwordProblem($password)) {
            header('Location: index.php?action=users&error=add&msg=' . urlencode($problem));
            exit();
        }

        $result = $this->userModel->addUser($username, $password, $email, $accounttype);
        if ($result) {
            // The admin chose this password, so the new user must replace it at first sign-in.
            $this->userModel->setMustChange($result, true);
            header('Location: index.php?action=users&notif=add');
        } else {
            header('Location: index.php?action=users&error=add&msg=' . urlencode('Failed to create user. Username may already exist.'));
        }
        exit();
    }

    public function editUser($id) {
        $users = $this->userModel->getAllUsers();
        $user = $this->userModel->getUserById($id);
        include 'views/users.php';
    }

    public function updateUser($username, $password, $email, $accounttype, $id) {
        // The update paths previously passed $_POST['accounttype'] straight to
        // the UPDATE with no check at all — only addUser validated. With a
        // third role in the enum that gap matters more, so both update paths
        // now use the same whitelist. An unrecognised value is refused, never
        // coerced to a default: silently downgrading someone's role is worse
        // than telling the admin the form was wrong.
        if (!isValidAccountType($accounttype)) {
            header('Location: index.php?action=users&error=update&msg=' . urlencode('Invalid account type'));
            exit();
        }
        if ($password !== '' && ($problem = User::passwordProblem($password))) {
            header('Location: index.php?action=users&error=update&msg=' . urlencode($problem));
            exit();
        }
        $result = $this->userModel->updateUser($username, $password, $email, $accounttype, $id);
        if ($result) {
            $this->afterAdminPasswordEdit($password, $id);
            // If the current user updated their own profile, refresh session data
            $this->refreshCurrentUserSession($id);
            header('Location: index.php?action=users&notif=update');
        } else {
            header('Location: index.php?action=users&error=update');
        }
        exit();
    }

    public function deleteUser($id) {
        // Prevent self-deletion
        if (isset($_SESSION['user']) && $_SESSION['user']['id'] == $id) {
            header('Location: index.php?action=users&error=self_action&msg=' . urlencode('You cannot delete your own account'));
            exit();
        }

        $result = $this->userModel->deleteUser($id);
        if ($result) {
            header('Location: index.php?action=users&notif=delete');
        } else {
            header('Location: index.php?action=users&error=delete');
        }
        exit();
    }

    public function deleteUserByUuid($uuid) {
        // Get user by UUID first
        $user = $this->userModel->getUserByUuid($uuid);

        // Prevent self-deletion
        if ($user && isset($_SESSION['user']) && $_SESSION['user']['uuid'] == $uuid) {
            header('Location: index.php?action=users&error=self_action&msg=' . urlencode('You cannot delete your own account'));
            exit();
        }

        $result = $this->userModel->deleteUserByUuid($uuid);
        if ($result) {
            header('Location: index.php?action=users&notif=delete');
        } else {
            header('Location: index.php?action=users&error=delete');
        }
        exit();
    }

    public function activateUser($id) {
        $result = $this->userModel->activateUser($id);
        if ($result) {
            // If the current user was activated, refresh session data
            $this->refreshCurrentUserSession($id);
            header('Location: index.php?action=users&notif=activate');
        } else {
            header('Location: index.php?action=users&error=activate');
        }
        exit();
    }

    public function activateUserByUuid($uuid) {
        $result = $this->userModel->activateUserByUuid($uuid);
        if ($result) {
            // If the current user was activated, refresh session data
            $user = $this->userModel->getUserByUuid($uuid);
            if ($user) {
                $this->refreshCurrentUserSession($user['id']);
            }
            header('Location: index.php?action=users&notif=activate');
        } else {
            header('Location: index.php?action=users&error=activate');
        }
        exit();
    }

    public function deactivateUser($id) {
        // Prevent self-deactivation
        if (isset($_SESSION['user']) && $_SESSION['user']['id'] == $id) {
            header('Location: index.php?action=users&error=self_action&msg=' . urlencode('You cannot deactivate your own account'));
            exit();
        }

        $result = $this->userModel->deactivateUser($id);
        if ($result) {
            header('Location: index.php?action=users&notif=deactivate');
        } else {
            header('Location: index.php?action=users&error=deactivate');
        }
        exit();
    }

    public function deactivateUserByUuid($uuid) {
        // Get user by UUID first
        $user = $this->userModel->getUserByUuid($uuid);

        // Prevent self-deactivation
        if ($user && isset($_SESSION['user']) && $_SESSION['user']['uuid'] == $uuid) {
            header('Location: index.php?action=users&error=self_action&msg=' . urlencode('You cannot deactivate your own account'));
            exit();
        }

        $result = $this->userModel->deactivateUserByUuid($uuid);
        if ($result) {
            header('Location: index.php?action=users&notif=deactivate');
        } else {
            header('Location: index.php?action=users&error=deactivate');
        }
        exit();
    }

    public function updateUserByUuid($username, $password, $email, $accounttype, $uuid) {
        if (!isValidAccountType($accounttype)) {
            header('Location: index.php?action=users&error=update&msg=' . urlencode('Invalid account type'));
            exit();
        }
        if ($password !== '' && ($problem = User::passwordProblem($password))) {
            header('Location: index.php?action=users&error=update&msg=' . urlencode($problem));
            exit();
        }
        $result = $this->userModel->updateUserByUuid($username, $password, $email, $accounttype, $uuid);
        if ($result) {
            // If the current user updated their own profile, refresh session data
            $user = $this->userModel->getUserByUuid($uuid);
            if ($user) {
                $this->afterAdminPasswordEdit($password, $user['id']);
                $this->refreshCurrentUserSession($user['id']);
            }
            header('Location: index.php?action=users&notif=update');
        } else {
            header('Location: index.php?action=users&error=update');
        }
        exit();
    }

    // ─── Passwords ───────────────────────────────────────────────────────────

    /**
     * After the Edit User form saved a non-empty password: someone else's
     * account must now choose its own at next sign-in; the admin's own account
     * does not (they just typed it themselves).
     */
    private function afterAdminPasswordEdit($password, $id) {
        if ($password === '') return;
        $self = isset($_SESSION['user']) && $_SESSION['user']['id'] == $id;
        $this->userModel->setMustChange($id, !$self);
    }

    /** Admin button: give a user a fresh temporary password, shown once, and make them change it at next sign-in. */
    public function adminResetPassword($uuid) {
        $user = $this->userModel->getUserByUuid($uuid);
        if (!$user) {
            header('Location: index.php?action=users&error=update&msg=' . urlencode('User not found'));
            exit();
        }
        if (isset($_SESSION['user']) && $_SESSION['user']['id'] == $user['id']) {
            header('Location: index.php?action=users&error=self_action&msg=' . urlencode('Use "Change Password" on your profile for your own account'));
            exit();
        }
        $temp = User::generateTempPassword();
        if (!$this->userModel->setPassword($user['id'], $temp, true)) {
            header('Location: index.php?action=users&error=update&msg=' . urlencode('Could not reset the password'));
            exit();
        }
        // Shown once on the next Users page load, then gone. Never put in the URL.
        $_SESSION['temp_password'] = ['username' => $user['username'], 'password' => $temp];
        header('Location: index.php?action=users');
        exit();
    }

    /** Signed-in user changes their own password (profile), or is made to (forced after an admin set it). */
    public function changePassword() {
        $forced = !empty($_SESSION['user']['must_change_password']);
        $error = null; $success = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id      = $_SESSION['user']['id'];
            $current = (string)($_POST['current_password'] ?? '');
            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if (!$this->userModel->verifyPassword($id, $current)) {
                $error = 'Your current password is not correct.';
            } elseif ($problem = User::passwordProblem($new)) {
                $error = $problem;
            } elseif ($new !== $confirm) {
                $error = 'The new passwords do not match.';
            } elseif ($new === $current) {
                $error = 'Choose a password different from the current one.';
            } elseif (!$this->userModel->setPassword($id, $new, false)) {
                $error = 'Could not save the new password. Please try again.';
            } else {
                $_SESSION['user']['must_change_password'] = 0;
                session_regenerate_id(true);
                if ($forced) {
                    header('Location: index.php');
                    exit();
                }
                $success = 'Your password has been changed.';
            }
        }
        include 'views/change_password.php';
    }

    /** "Forgot password?" on the sign-in page. Same reply whether or not the account exists. */
    public function forgotPassword() {
        $done = false;
        $mailReady = Mailer::configured();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $login = trim((string)($_POST['login'] ?? ''));
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if ($login !== '' && $mailReady) {
                foreach ($this->userModel->findActiveByLogin($login) as $acct) {
                    if (!filter_var($acct['email'], FILTER_VALIDATE_EMAIL)) continue;
                    $token = $this->userModel->createResetToken($acct['id'], $ip);
                    if ($token === null) continue;      // over the hourly limit: stay silent
                    $url = $this->baseUrl() . 'index.php?action=resetPassword&token=' . $token;
                    $text = "Hello " . $acct['username'] . ",\n\nSomeone asked to reset the password for your Victory Bacolod admin account.\n\n"
                          . "Choose a new password here (the link works once and expires in " . User::RESET_TTL_MINUTES . " minutes):\n" . $url . "\n\n"
                          . "If this wasn't you, ignore this email: your password has not changed.";
                    $html = '<p>Hello ' . htmlspecialchars($acct['username']) . ',</p><p>Someone asked to reset the password for your Victory Bacolod admin account.</p>'
                          . '<p><a href="' . htmlspecialchars($url) . '">Choose a new password</a><br><small>The link works once and expires in ' . User::RESET_TTL_MINUTES . ' minutes.</small></p>'
                          . '<p>If this wasn\'t you, ignore this email: your password has not changed.</p>';
                    Mailer::send([$acct['email']], 'Reset your Victory Bacolod password', $text, $html);
                }
            }
            $done = true;
        }
        include 'views/forgot_password.php';
    }

    /** The page the emailed link opens: choose a new password. */
    public function resetPassword() {
        $token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
        $account = $this->userModel->findValidReset($token);
        $error = null;
        if ($account && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if ($problem = User::passwordProblem($new)) {
                $error = $problem;
            } elseif ($new !== $confirm) {
                $error = 'The passwords do not match.';
            } elseif ($this->userModel->completeReset($token, $new)) {
                $_SESSION['info_message'] = 'Your password has been changed. Sign in with the new one.';
                header('Location: index.php?action=login');
                exit();
            } else {
                $error = 'Could not save the new password. Please request a new link.';
            }
        }
        include 'views/reset_password.php';
    }

    /** Where emailed links point. Set APP_URL in .env in production so a forged Host header cannot redirect them. */
    private function baseUrl() {
        $env = isset($_ENV['APP_URL']) ? trim($_ENV['APP_URL']) : '';
        if ($env !== '') return rtrim($env, '/') . '/';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) $host = 'localhost';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return ($https ? 'https://' : 'http://') . $host . $dir . '/';
    }

    /**
     * Helper method to refresh session data if current user was updated
     */
    private function refreshCurrentUserSession($id) {
        if (isset($_SESSION['user']) && $_SESSION['user']['id'] == $id) {
            $updatedUser = $this->userModel->getUserById($id);
            if ($updatedUser) {
                $_SESSION['user']['username'] = $updatedUser['username'];
                $_SESSION['user']['email'] = $updatedUser['email'];
                $_SESSION['user']['accounttype'] = $updatedUser['accounttype'];
                $_SESSION['user']['accountstatus'] = $updatedUser['accountstatus'];
            }
        }
    }

    public function getUserModel() {
        return $this->userModel;
    }
}
?>
