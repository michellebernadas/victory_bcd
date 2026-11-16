-- ============================================================================
-- Victory Bacolod — split the bundled permissions into separate units
--
-- WHY
-- Three permissions each covered several pages, so a role could not be given
-- one without the rest:
--   victory_groups        -> Victory Groups + Leadership Groups + Campus + Leaders
--   gathering_attendance  -> Prayer Meeting + Students' Hangout
--   classes               -> six class pages
-- Each unit is now its own permission (PERMISSION_GROUPS in config/permissions.php).
--
-- NOBODY LOSES ACCESS
-- A role that held a bundle is granted every unit it contained, then the old
-- key is removed. Trim a role afterwards from Administration > Roles.
-- This only ever ADDS the new keys for roles that held the old one, so a role
-- that never had Groups still does not.
--
-- Safe / idempotent: INSERT IGNORE for the new keys, DELETE of keys that no
-- longer exist. Re-running changes nothing — including the one-time trim of
-- the two new roles at the bottom, which is skipped once this file is in the
-- ledger so it can never undo a grant an admin made afterwards.
-- MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_16_03_split_permissions.sql
-- ============================================================================

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT rp.role_id, n.permission
FROM `role_permissions` rp
JOIN (
    SELECT 'victory_groups' AS old_key, 'group_vg'     AS permission UNION ALL
    SELECT 'victory_groups', 'group_lg'      UNION ALL
    SELECT 'victory_groups', 'group_campus'  UNION ALL
    SELECT 'victory_groups', 'group_leaders' UNION ALL
    SELECT 'gathering_attendance', 'prayer_meeting'   UNION ALL
    SELECT 'gathering_attendance', 'students_hangout' UNION ALL
    SELECT 'classes', 'class_victory_weekend'       UNION ALL
    SELECT 'classes', 'class_church_community'      UNION ALL
    SELECT 'classes', 'class_making_disciples'      UNION ALL
    SELECT 'classes', 'class_empowering_leaders'    UNION ALL
    SELECT 'classes', 'class_leadership_113'        UNION ALL
    SELECT 'classes', 'class_spiritual_foundations'
) n ON n.old_key = rp.permission;

DELETE FROM `role_permissions`
WHERE `permission` IN ('victory_groups', 'gathering_attendance', 'classes');

-- ─── Narrow the three NEW roles seeded by 2026_11_16_01 (first run only) ────
-- They were created in the same release, so nobody relies on their old bundle
-- grants. Victory Group = Victory Groups only (not Leadership / Campus /
-- Leaders); Ministry Coordinator = Prayer Meeting only (not Students' Hangout).
-- Guarded by the ledger: once this file has been recorded, a re-run leaves
-- these roles exactly as an admin has since set them.
DELETE rp FROM `role_permissions` rp
JOIN `roles` r ON r.id = rp.role_id
WHERE NOT EXISTS (SELECT 1 FROM `schema_migrations` m WHERE m.filename = '2026_11_16_03_split_permissions.sql')
  AND ((r.slug = 'victory_group'        AND rp.permission IN ('group_lg', 'group_campus', 'group_leaders'))
    OR (r.slug = 'ministry_coordinator' AND rp.permission IN ('students_hangout')));

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_16_03_split_permissions.sql', 'Bundled permissions split: groups, prayer/students and classes become separate units.');
