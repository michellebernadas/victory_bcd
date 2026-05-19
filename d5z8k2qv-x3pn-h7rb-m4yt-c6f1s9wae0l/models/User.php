<?php
class User {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function login($username, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM accounts WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                return ['error' => true, 'message' => 'Invalid username or password'];
            }

            if (!password_verify($password, $user['password'])) {
                return ['error' => true, 'message' => 'Invalid username or password'];
            }

            if ($user['accountstatus'] === 'inactive') {
                return ['error' => true, 'message' => 'Your account has been deactivated. Please contact your administrator.'];
            }

            if ($user['accountstatus'] === 'active') {
                $updateStmt = $this->db->prepare("UPDATE accounts SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                $user['last_login'] = date('Y-m-d H:i:s');
                return $user;
            }

            return ['error' => true, 'message' => 'Account status error. Please contact your administrator.'];
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return ['error' => true, 'message' => 'System error. Please try again later.'];
        }
    }

    public function getAllUsers() {
        try {
            $stmt = $this->db->prepare("SELECT * FROM accounts ORDER BY dateadded DESC");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Get all users error: " . $e->getMessage());
            return [];
        }
    }

    public function getUserById($id) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Get user by ID error: " . $e->getMessage());
            return false;
        }
    }

    public function getUserByUuid($uuid) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM accounts WHERE uuid = ?");
            $stmt->execute([$uuid]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Get user by UUID error: " . $e->getMessage());
            return false;
        }
    }

    public function addUser($username, $password, $email, $accounttype) {
        try {
            $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM accounts WHERE username = ?");
            $checkStmt->execute([$username]);
            if ($checkStmt->fetchColumn() > 0) {
                error_log("Add user error: Username already exists");
                return false;
            }

            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $uuid = $this->generateUUID();
            $addedBy = $_SESSION['user']['id'] ?? 0;

            $stmt = $this->db->prepare("INSERT INTO accounts (uuid, username, password, email, accounttype, accountstatus, added_by) VALUES (?, ?, ?, ?, ?, 'active', ?)");
            $stmt->execute([$uuid, $username, $hashedPassword, $email, $accounttype, $addedBy]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Add user error: " . $e->getMessage());
            return false;
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

    public function updateUser($username, $password, $email, $accounttype, $id) {
        try {
            if (!empty($password)) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->db->prepare("UPDATE accounts SET username = ?, password = ?, email = ?, accounttype = ? WHERE id = ?");
                $stmt->execute([$username, $hashedPassword, $email, $accounttype, $id]);
            } else {
                $stmt = $this->db->prepare("UPDATE accounts SET username = ?, email = ?, accounttype = ? WHERE id = ?");
                $stmt->execute([$username, $email, $accounttype, $id]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Update user error: " . $e->getMessage());
            return false;
        }
    }

    public function updateUserByUuid($username, $password, $email, $accounttype, $uuid) {
        try {
            if (!empty($password)) {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $this->db->prepare("UPDATE accounts SET username = ?, password = ?, email = ?, accounttype = ? WHERE uuid = ?");
                $stmt->execute([$username, $hashedPassword, $email, $accounttype, $uuid]);
            } else {
                $stmt = $this->db->prepare("UPDATE accounts SET username = ?, email = ?, accounttype = ? WHERE uuid = ?");
                $stmt->execute([$username, $email, $accounttype, $uuid]);
            }
            return true;
        } catch (PDOException $e) {
            error_log("Update user by UUID error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteUser($id) {
        try {
            $stmt = $this->db->prepare("DELETE FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Delete user error: " . $e->getMessage());
            return false;
        }
    }

    public function deleteUserByUuid($uuid) {
        try {
            $stmt = $this->db->prepare("DELETE FROM accounts WHERE uuid = ?");
            $stmt->execute([$uuid]);
            return true;
        } catch (PDOException $e) {
            error_log("Delete user by UUID error: " . $e->getMessage());
            return false;
        }
    }

    public function activateUser($id) {
        try {
            $stmt = $this->db->prepare("UPDATE accounts SET accountstatus = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Activate user error: " . $e->getMessage());
            return false;
        }
    }

    public function activateUserByUuid($uuid) {
        try {
            $stmt = $this->db->prepare("UPDATE accounts SET accountstatus = 'active' WHERE uuid = ?");
            $stmt->execute([$uuid]);
            return true;
        } catch (PDOException $e) {
            error_log("Activate user by UUID error: " . $e->getMessage());
            return false;
        }
    }

    public function deactivateUser($id) {
        try {
            $stmt = $this->db->prepare("UPDATE accounts SET accountstatus = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log("Deactivate user error: " . $e->getMessage());
            return false;
        }
    }

    public function deactivateUserByUuid($uuid) {
        try {
            $stmt = $this->db->prepare("UPDATE accounts SET accountstatus = 'inactive' WHERE uuid = ?");
            $stmt->execute([$uuid]);
            return true;
        } catch (PDOException $e) {
            error_log("Deactivate user by UUID error: " . $e->getMessage());
            return false;
        }
    }
}
?>
