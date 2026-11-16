<?php
require_once __DIR__ . '/../models/Role.php';
require_once __DIR__ . '/../config/permissions.php';

/**
 * Administration > Roles. Admin only — index.php gates every route with
 * canAdminister(), and each method re-checks it.
 */
class RoleController {
    private $db;
    private $roleModel;

    public function __construct($db) {
        $this->db = $db;
        $this->roleModel = new Role($db);
    }

    public function listRoles() {
        if (!canAdminister()) { header('Location: index.php'); exit(); }
        $roles = $this->roleModel->getAll();
        include 'views/roles.php';
    }

    public function saveRole(array $post, $slug = null) {
        if (!canAdminister()) { header('Location: index.php'); exit(); }

        $label = trim((string)($post['label'] ?? ''));
        $desc  = trim((string)($post['description'] ?? ''));
        $perms = sanitizePermissionKeys($post['permissions'] ?? []);

        if ($label === '' || mb_strlen($label) > 100) {
            $this->back('error', 'Give the role a name (up to 100 characters).');
        }
        if (mb_strlen($desc) > 500) $desc = mb_substr($desc, 0, 500);

        if ($slug === null) {
            if ($this->roleModel->labelExists($label)) $this->back('error', 'A role with that name already exists.');
            $newSlug = $this->roleModel->create($label, $desc, $perms);
            $newSlug ? $this->back('notif', 'Role created.') : $this->back('error', 'Could not create the role.');
        }

        if ($slug === ROLE_ADMIN) $this->back('error', 'The Admin role always has full access and cannot be edited.');
        if (!$this->roleModel->getBySlug($slug)) $this->back('error', 'That role does not exist.');
        if ($this->roleModel->labelExists($label, $slug)) $this->back('error', 'A role with that name already exists.');

        $this->roleModel->update($slug, $label, $desc, $perms)
            ? $this->back('notif', 'Role updated.')
            : $this->back('error', 'Could not update the role.');
    }

    public function deleteRole($slug) {
        if (!canAdminister()) { header('Location: index.php'); exit(); }
        $res = $this->roleModel->delete((string)$slug);
        $res === true ? $this->back('notif', 'Role deleted.') : $this->back('error', $res);
    }

    private function back($kind, $msg) {
        header('Location: index.php?action=roles&' . $kind . '=' . urlencode($msg));
        exit();
    }
}
