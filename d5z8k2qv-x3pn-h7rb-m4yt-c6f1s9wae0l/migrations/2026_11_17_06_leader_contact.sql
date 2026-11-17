-- ============================================================================
-- Victory Bacolod — leader contact details, and retiring the all-classes page
--
-- 1. vg_members.email / phone
--    The Leaders page could only show contact details for leaders connected to a
--    member profile, and none are yet. Each leader / intern line on a group can
--    now carry its own email and phone, typed on the group form. A connected
--    member's own details still win when both exist.
--
-- 2. The all-classes "Attendance Records" page was removed (the Members page
--    already shows each person's completion; the per-class pages stay). Its
--    permission no longer exists, so any role rows for it are cleaned up.
--
-- Safe / idempotent. MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_17_06_leader_contact.sql
-- ============================================================================

ALTER TABLE `vg_members`
    ADD COLUMN IF NOT EXISTS `email` VARCHAR(190) DEFAULT NULL COMMENT 'Contact email of this leader / intern line (typed on the group form).',
    ADD COLUMN IF NOT EXISTS `phone` VARCHAR(60)  DEFAULT NULL COMMENT 'Contact phone of this leader / intern line.';

DELETE FROM `role_permissions` WHERE `permission` = 'attendance_records';

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_17_06_leader_contact.sql', 'vg_members.email/phone; removed the retired attendance_records permission.');
