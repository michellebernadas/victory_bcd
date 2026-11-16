-- ============================================================================
-- Victory Bacolod — database-backed roles with per-role permissions
--
-- WHY
-- accounts.accounttype was a fixed ENUM, so adding a role meant a migration
-- plus a code edit, and what a role could reach was hard-coded. Admins now
-- create roles in the portal and tick which sections each role may open.
--
-- WHAT CHANGES
--   * roles             one row per role. `slug` is what accounts.accounttype stores.
--   * role_permissions  the permission keys granted to a role (see
--                       PERMISSION_CATALOG in config/permissions.php).
--   * accounts.accounttype  ENUM -> VARCHAR(50), so a role created later needs
--                       no schema change. Existing values are untouched.
--
-- 'admin' is a system role: it always has every permission and its rows are
-- ignored by the code, so it can never be locked out by a bad edit.
--
-- EXISTING ROLES KEEP TODAY'S ACCESS. editor / facilities / minister could
-- already open every discipleship page, so they are seeded with those same
-- permissions; nobody loses access when this runs. Trim them afterwards from
-- Administration > Roles. The three new roles start narrow.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS, INSERT IGNORE, MODIFY to an
-- exact definition. MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_16_01_roles_and_permissions.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `roles` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `slug`        VARCHAR(50)  NOT NULL COMMENT 'Stored in accounts.accounttype',
    `label`       VARCHAR(100) NOT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `is_system`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = built in; cannot be deleted',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`    INT(11)     NOT NULL,
    `permission` VARCHAR(60) NOT NULL,
    PRIMARY KEY (`role_id`, `permission`),
    CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free-text slug instead of the closed enum.
ALTER TABLE `accounts`
    MODIFY COLUMN `accounttype` VARCHAR(50) NOT NULL DEFAULT 'editor';

-- ─── Roles ──────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `roles` (`slug`, `label`, `description`, `is_system`) VALUES
    ('admin',               'Admin',                'Everything, including users, roles and settings.', 1),
    ('editor',              'Editor',               'Discipleship records. May submit facility requests and view the calendar.', 1),
    ('facilities',          'Facilities Personnel', 'Facility review (stage 2), confirmation (stage 4) and cancel.', 1),
    ('minister',            'Minister',             'Facility approval (stage 3).', 1),
    ('ministry_coordinator','Ministry Coordinator', 'Members, serve teams and attendance for their ministry; can request a room.', 0),
    ('serve_team',          'Serve Team',           'Serve Teams and the members on them.', 0),
    ('victory_group',       'Victory Group',        'Victory Groups, leaders and members only.', 0);

-- ─── Permissions seeded per role (keys: PERMISSION_CATALOG) ─────────────────
-- Existing roles: today's access, unchanged.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.permission
FROM `roles` r
JOIN (
    SELECT 'dashboard' AS permission UNION ALL SELECT 'members' UNION ALL SELECT 'attendance_records' UNION ALL SELECT 'victory_groups'
    UNION ALL SELECT 'serve_teams' UNION ALL SELECT 'service_attendance' UNION ALL SELECT 'gathering_attendance'
    UNION ALL SELECT 'classes' UNION ALL SELECT 'facility_view' UNION ALL SELECT 'facility_submit'
) p
WHERE r.slug IN ('editor', 'facilities', 'minister');

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.permission FROM `roles` r
JOIN (SELECT 'facility_review' AS permission UNION ALL SELECT 'facility_confirm' UNION ALL SELECT 'facility_cancel') p
WHERE r.slug = 'facilities';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, 'facility_approve' FROM `roles` r WHERE r.slug = 'minister';

-- New roles: narrow by design.
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.permission FROM `roles` r
JOIN (
    SELECT 'dashboard' AS permission UNION ALL SELECT 'members' UNION ALL SELECT 'serve_teams' UNION ALL SELECT 'service_attendance'
    UNION ALL SELECT 'gathering_attendance' UNION ALL SELECT 'facility_view' UNION ALL SELECT 'facility_submit'
) p
WHERE r.slug = 'ministry_coordinator';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.permission FROM `roles` r
JOIN (SELECT 'serve_teams' AS permission UNION ALL SELECT 'members') p
WHERE r.slug = 'serve_team';

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT r.id, p.permission FROM `roles` r
JOIN (SELECT 'victory_groups' AS permission UNION ALL SELECT 'members') p
WHERE r.slug = 'victory_group';

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_16_01_roles_and_permissions.sql', 'roles + role_permissions tables; accounts.accounttype ENUM -> VARCHAR(50).');
