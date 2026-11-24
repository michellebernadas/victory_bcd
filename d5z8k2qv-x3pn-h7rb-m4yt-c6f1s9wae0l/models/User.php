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

    // ─── Passwords: change, force-change, forgot / reset ─────────────────────

    const MIN_PASSWORD_LENGTH = 8;
    const RESET_TTL_MINUTES   = 60;
    const RESET_MAX_PER_ACCOUNT_HOUR = 3;
    const RESET_MAX_PER_IP_HOUR      = 10;

    /** The one password rule, used by every path that sets a password. Returns an error message, or null when fine. */
    public static function passwordProblem($password) {
        $password = (string)$password;
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        if (strlen($password) > 72) {            // bcrypt ignores everything past 72 bytes
            return 'Password must be 72 characters or fewer.';
        }
        return null;
    }

    /** A readable one-time password for an admin to hand over: no 0/O/1/l/I lookalikes. */
    public static function generateTempPassword($length = 10) {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
        return $out;
    }

    public function verifyPassword($id, $password) {
        try {
            $stmt = $this->db->prepare("SELECT password FROM accounts WHERE id = ?");
            $stmt->execute([$id]);
            $hash = $stmt->fetchColumn();
            return $hash !== false && password_verify((string)$password, $hash);
        } catch (PDOException $e) {
            error_log('verifyPassword: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set a password. $mustChange = true when someone OTHER than the owner chose
     * it (admin add / admin reset): the owner is then made to pick their own.
     */
    public function setPassword($id, $password, $mustChange) {
        try {
            $stmt = $this->db->prepare("UPDATE accounts SET password = ?, must_change_password = ?, password_changed_at = " . ($mustChange ? 'NULL' : 'NOW()') . " WHERE id = ?");
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $mustChange ? 1 : 0, $id]);
            return true;
        } catch (PDOException $e) {
            error_log('setPassword: ' . $e->getMessage());
            return false;
        }
    }

    public function setMustChange($id, $flag) {
        try {
            $this->db->prepare("UPDATE accounts SET must_change_password = ? WHERE id = ?")->execute([$flag ? 1 : 0, $id]);
            return true;
        } catch (PDOException $e) {
            error_log('setMustChange: ' . $e->getMessage());
            return false;
        }
    }

    /** Active accounts matching a username or an email (an email can be shared, so several). */
    public function findActiveByLogin($login) {
        try {
            $stmt = $this->db->prepare("SELECT id, username, email FROM accounts WHERE accountstatus = 'active' AND (username = ? OR (email <> '' AND email = ?)) LIMIT 3");
            $stmt->execute([$login, $login]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('findActiveByLogin: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Make a reset token for an account. Returns the raw token (emailed, never
     * stored — only its hash is), or null when this account / IP is over the
     * hourly limit. The caller shows the same message either way.
     */
    public function createResetToken($accountId, $ip) {
        try {
            $q = $this->db->prepare("SELECT COUNT(*) FROM password_resets WHERE account_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
            $q->execute([$accountId]);
            if ((int)$q->fetchColumn() >= self::RESET_MAX_PER_ACCOUNT_HOUR) return null;
            if ($ip) {
                $q = $this->db->prepare("SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
                $q->execute([$ip]);
                if ((int)$q->fetchColumn() >= self::RESET_MAX_PER_IP_HOUR) return null;
            }
            $token = bin2hex(random_bytes(32));
            $this->db->prepare("INSERT INTO password_resets (account_id, token_hash, ip, created_at, expires_at) VALUES (?, ?, ?, NOW(), NOW() + INTERVAL " . self::RESET_TTL_MINUTES . " MINUTE)")
                     ->execute([$accountId, hash('sha256', $token), $ip]);
            return $token;
        } catch (PDOException $e) {
            error_log('createResetToken: ' . $e->getMessage());
            return null;
        }
    }

    /** The account a still-valid (unused, unexpired, active) token belongs to, or false. */
    public function findValidReset($token) {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        try {
            $stmt = $this->db->prepare("SELECT a.id, a.username, r.id AS reset_id FROM password_resets r JOIN accounts a ON a.id = r.account_id
                                        WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND a.accountstatus = 'active'");
            $stmt->execute([hash('sha256', $token)]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log('findValidReset: ' . $e->getMessage());
            return false;
        }
    }

    /** Set the new password and spend the token (and every other open one for that account). */
    public function completeReset($token, $password) {
        $row = $this->findValidReset($token);
        if (!$row) return false;
        try {
            $this->db->beginTransaction();
            if (!$this->setPassword($row['id'], $password, false)) throw new PDOException('setPassword failed');
            $this->db->prepare("UPDATE password_resets SET used_at = NOW() WHERE account_id = ? AND used_at IS NULL")->execute([$row['id']]);
            $this->db->commit();
            return $row;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('completeReset: ' . $e->getMessage());
            return false;
        }
    }
}
?>
