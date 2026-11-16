<?php
/**
 * Roles and the permissions granted to them (tables `roles`, `role_permissions`).
 *
 * Reading roles for access checks goes through allRoles() in
 * config/permissions.php, which caches per request. This model is the write
 * side used by the Roles admin screen, plus the user counts it displays.
 *
 * 'admin' is protected here as well as in the UI: it cannot be edited,
 * renamed or deleted, because hasPermission() grants it everything in code.
 */
class Role {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /** Roles with their permission keys and how many accounts use each. */
    public function getAll() {
        $roles = allRoles();
        $counts = [];
        try {
            foreach ($this->db->query("SELECT accounttype, COUNT(*) c FROM accounts GROUP BY accounttype")->fetchAll() as $r) {
                $counts[$r['accounttype']] = (int)$r['c'];
            }
        } catch (PDOException $e) {
            error_log('Role::getAll counts: ' . $e->getMessage());
        }
        foreach ($roles as $slug => &$r) $r['user_count'] = $counts[$slug] ?? 0;
        return $roles;
    }

    public function getBySlug($slug) {
        return allRoles()[$slug] ?? null;
    }

    /** A URL-safe, unique slug derived from the label, e.g. "Youth Pastor" -> youth_pastor. */
    public function makeSlug($label) {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_'));
        if ($base === '') $base = 'role';
        $base = substr($base, 0, 40);
        $slug = $base;
        $n = 2;
        while ($this->slugExists($slug)) $slug = $base . '_' . $n++;
        return $slug;
    }

    private function slugExists($slug) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM roles WHERE slug = ?");
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function labelExists($label, $exceptSlug = null) {
        $stmt = $this->db->prepare("SELECT slug FROM roles WHERE LOWER(label) = LOWER(?)");
        $stmt->execute([$label]);
        foreach ($stmt->fetchAll() as $r) {
            if ($r['slug'] !== $exceptSlug) return true;
        }
        return false;
    }

    /** Create a custom role. Returns the new slug, or false. */
    public function create($label, $description, array $permissions) {
        try {
            $slug = $this->makeSlug($label);
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("INSERT INTO roles (slug, label, description, is_system) VALUES (?, ?, ?, 0)");
            $stmt->execute([$slug, $label, $description]);
            $this->writePermissions((int)$this->db->lastInsertId(), $permissions);
            $this->db->commit();
            resetRolesCache();
            return $slug;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('Role::create: ' . $e->getMessage());
            return false;
        }
    }

    /** Update label, description and permissions. The slug never changes (accounts point at it). */
    public function update($slug, $label, $description, array $permissions) {
        if ($slug === ROLE_ADMIN) return false;
        $role = $this->getBySlug($slug);
        if (!$role) return false;
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("UPDATE roles SET label = ?, description = ? WHERE slug = ?");
            $stmt->execute([$label, $description, $slug]);
            $this->writePermissions((int)$role['id'], $permissions);
            $this->db->commit();
            resetRolesCache();
            return true;
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('Role::update: ' . $e->getMessage());
            return false;
        }
    }

    private function writePermissions($roleId, array $permissions) {
        $del = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $del->execute([$roleId]);
        $ins = $this->db->prepare("INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)");
        foreach (sanitizePermissionKeys($permissions) as $p) $ins->execute([$roleId, $p]);
    }

    public function userCount($slug) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM accounts WHERE accounttype = ?");
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete a custom role. Refused for built-in roles and for any role that
     * still has accounts — those accounts would be left with a role that no
     * longer exists, and so with no access at all.
     * Returns true, or a string reason.
     */
    public function delete($slug) {
        $role = $this->getBySlug($slug);
        if (!$role) return 'That role does not exist.';
        if (!empty($role['is_system'])) return 'Built-in roles cannot be deleted.';
        $n = $this->userCount($slug);
        if ($n > 0) return "$n user(s) still have this role. Move them to another role first.";
        try {
            $stmt = $this->db->prepare("DELETE FROM roles WHERE slug = ?");
            $stmt->execute([$slug]);
            resetRolesCache();
            return true;
        } catch (PDOException $e) {
            error_log('Role::delete: ' . $e->getMessage());
            return 'Could not delete the role.';
        }
    }
}
