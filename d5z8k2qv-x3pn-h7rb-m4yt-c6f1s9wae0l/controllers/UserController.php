<?php
require_once 'models/User.php';

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

        // Validate accounttype
        $validAccountTypes = ['admin', 'editor'];
        if (!in_array($accounttype, $validAccountTypes)) {
            header('Location: index.php?action=users&error=add&msg=' . urlencode('Invalid account type'));
            exit();
        }

        $result = $this->userModel->addUser($username, $password, $email, $accounttype);
        if ($result) {
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
        $result = $this->userModel->updateUser($username, $password, $email, $accounttype, $id);
        if ($result) {
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
        $result = $this->userModel->updateUserByUuid($username, $password, $email, $accounttype, $uuid);
        if ($result) {
            // If the current user updated their own profile, refresh session data
            $user = $this->userModel->getUserByUuid($uuid);
            if ($user) {
                $this->refreshCurrentUserSession($user['id']);
            }
            header('Location: index.php?action=users&notif=update');
        } else {
            header('Location: index.php?action=users&error=update');
        }
        exit();
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
