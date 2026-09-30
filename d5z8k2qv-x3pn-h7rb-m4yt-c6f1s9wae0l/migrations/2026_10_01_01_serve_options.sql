-- ============================================================================
-- Victory Bacolod — Serve Teams: managed Service & Place dropdowns
--
-- A serve team serves at a church SERVICE or activity (Prayer Meeting, Students
-- Hangout, Sunday Worship Service, …), each with its own regular day and time.
-- Those were previously borrowed from the `services` table, which actually
-- holds worship service TIMES for members.service_attending — a different
-- concept. This gives Serve Teams its own managed lookup, mirroring the shape
-- of vg_options (one table, an option_type discriminator) so the CRUD, sorting
-- and activate/deactivate behaviour all match what the portal already does.
--
-- `default_day` / `default_time` let the team form auto-fill Day and Time when a
-- service is picked; `time_options` carries the extra slots for services that
-- run several times (Sunday Worship). The user can always override.
--
-- Safe / idempotent: IF NOT EXISTS + NOT EXISTS guards throughout; re-running
-- changes nothing and no existing row is touched.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_01_01_serve_options.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `serve_options` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `option_type`  ENUM('service','place') NOT NULL,
    `name`         VARCHAR(150) NOT NULL,
    `default_day`  VARCHAR(100) DEFAULT NULL COMMENT 'Regular day, e.g. Tuesday. Auto-fills the team form.',
    `default_time` TIME         DEFAULT NULL COMMENT 'Regular start time. Auto-fills the team form.',
    `time_options` VARCHAR(255) DEFAULT NULL COMMENT 'CSV of extra slots for multi-service days, e.g. "08:30,11:00,14:00,16:30".',
    `notes`        VARCHAR(255) DEFAULT NULL,
    `sort_order`   INT(11)      NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `is_deleted`   TINYINT(1)   NOT NULL DEFAULT 0,
    `dateadded`    DATETIME     NOT NULL DEFAULT current_timestamp(),
    `dateupdated`  DATETIME     DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_serve_option` (`option_type`, `name`),
    KEY `idx_so_type` (`option_type`),
    KEY `idx_so_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Services / activities ──────────────────────────────────────────────────
INSERT INTO `serve_options` (`option_type`, `name`, `default_day`, `default_time`, `time_options`, `notes`, `sort_order`)
SELECT * FROM (
    SELECT 'service' AS t, 'Sunday Worship Service' AS n, 'Sunday'   AS d, '08:30:00' AS tm,
           '08:30,11:00,14:00,16:30' AS opts, 'Four service times every Sunday.' AS nt, 1 AS s UNION ALL
    SELECT 'service', 'Prayer Meeting',         'Tuesday', '18:30:00', NULL, 'Every Tuesday, 6:30 PM.',                                  2 UNION ALL
    SELECT 'service', 'Prayer Walk',            'Tuesday', '18:30:00', NULL, 'Same slot as Prayer Meeting; held occasionally instead of it, as a community.', 3 UNION ALL
    SELECT 'service', 'Students Hangout',       'Friday',  '17:00:00', NULL, 'Every Friday, 5:00 PM.',                                   4 UNION ALL
    SELECT 'service', 'Campus Night',           NULL,      NULL,       NULL, 'Schedule varies.',                                          5 UNION ALL
    SELECT 'service', 'Worship & Prayer Night', NULL,      NULL,       NULL, 'Schedule varies.',                                          6
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `serve_options` WHERE `option_type` = 'service');

-- ─── Places ─────────────────────────────────────────────────────────────────
-- Most teams serve at the church, so that is the default first option. The list
-- is editable, so campuses / homes can be added as needed.
INSERT INTO `serve_options` (`option_type`, `name`, `notes`, `sort_order`)
SELECT * FROM (
    SELECT 'place' AS t, 'Church — Main Sanctuary' AS n, 'Default venue for most teams.' AS nt, 1 AS s UNION ALL
    SELECT 'place', 'Church — Function Room', NULL, 2 UNION ALL
    SELECT 'place', 'Campus',                 'For campus-based activities.', 3 UNION ALL
    SELECT 'place', 'Online',                 NULL, 4 UNION ALL
    SELECT 'place', 'Other / TBA',            NULL, 5
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `serve_options` WHERE `option_type` = 'place');

-- ─── serve_teams: hold the service NAME and the chosen slot separately ──────
-- service_time keeps its original job (the specific slot, e.g. "8:30 AM"); the
-- new column records WHICH service/activity the team serves.
ALTER TABLE `serve_teams`
    ADD COLUMN IF NOT EXISTS `service_name` VARCHAR(150) NOT NULL DEFAULT ''
        COMMENT 'serve_options.name where option_type = service.' AFTER `ministry`;

ALTER TABLE `serve_teams`
    MODIFY COLUMN `service_time` VARCHAR(100) DEFAULT ''
        COMMENT 'Specific slot within the service, e.g. "8:30 AM" for Sunday Worship.';
