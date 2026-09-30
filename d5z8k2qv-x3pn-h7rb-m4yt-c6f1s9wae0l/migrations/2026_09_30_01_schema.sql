-- ============================================================================
-- Victory Bacolod — schema additions (2026-09-30)
--
-- Safe / idempotent: every statement uses IF NOT EXISTS / INSERT ... SELECT
-- guards. No table is dropped, renamed, or recreated. Existing rows are never
-- deleted. Re-running this file is a no-op.
--
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_09_30_01_schema.sql
-- ============================================================================

-- ─── 1. Water Baptism remarks / notes ───────────────────────────────────────
-- Free text for where/how the person was baptized (e.g. "Sea baptism, Punta Taytay").
ALTER TABLE `program_attendances`
    ADD COLUMN IF NOT EXISTS `water_baptism_remarks` TEXT NULL DEFAULT NULL AFTER `water_baptism`;


-- ─── 2. Worship service period (AM / PM) ────────────────────────────────────
ALTER TABLE `services`
    ADD COLUMN IF NOT EXISTS `service_period` VARCHAR(2) NOT NULL DEFAULT '' AFTER `name`;

-- Canonical worship service schedules. Insert only when missing (name match).
INSERT INTO `services` (`name`, `service_period`, `is_active`, `is_deleted`, `sort_order`)
SELECT * FROM (SELECT '8:30 AM'  AS n, 'AM' AS p, 1 AS a, 0 AS d, 1 AS s) AS t
WHERE NOT EXISTS (SELECT 1 FROM `services` WHERE `name` = '8:30 AM');
INSERT INTO `services` (`name`, `service_period`, `is_active`, `is_deleted`, `sort_order`)
SELECT * FROM (SELECT '11:00 AM' AS n, 'AM' AS p, 1 AS a, 0 AS d, 2 AS s) AS t
WHERE NOT EXISTS (SELECT 1 FROM `services` WHERE `name` = '11:00 AM');
INSERT INTO `services` (`name`, `service_period`, `is_active`, `is_deleted`, `sort_order`)
SELECT * FROM (SELECT '2:00 PM'  AS n, 'PM' AS p, 1 AS a, 0 AS d, 3 AS s) AS t
WHERE NOT EXISTS (SELECT 1 FROM `services` WHERE `name` = '2:00 PM');
INSERT INTO `services` (`name`, `service_period`, `is_active`, `is_deleted`, `sort_order`)
SELECT * FROM (SELECT '4:30 PM'  AS n, 'PM' AS p, 1 AS a, 0 AS d, 4 AS s) AS t
WHERE NOT EXISTS (SELECT 1 FROM `services` WHERE `name` = '4:30 PM');

-- Tag / re-activate the four canonical schedules (leaves any other row alone).
UPDATE `services` SET `service_period` = 'AM', `is_active` = 1, `is_deleted` = 0, `sort_order` = 1 WHERE `name` = '8:30 AM';
UPDATE `services` SET `service_period` = 'AM', `is_active` = 1, `is_deleted` = 0, `sort_order` = 2 WHERE `name` = '11:00 AM';
UPDATE `services` SET `service_period` = 'PM', `is_active` = 1, `is_deleted` = 0, `sort_order` = 3 WHERE `name` = '2:00 PM';
UPDATE `services` SET `service_period` = 'PM', `is_active` = 1, `is_deleted` = 0, `sort_order` = 4 WHERE `name` = '4:30 PM';

-- Push legacy / non-schedule service rows below the four canonical ones so the
-- dropdowns list 8:30 → 11:00 → 2:00 → 4:30 first. Values are preserved.
UPDATE `services`
   SET `sort_order` = `sort_order` + 90
 WHERE `name` NOT IN ('8:30 AM', '11:00 AM', '2:00 PM', '4:30 PM')
   AND `sort_order` < 90;


-- ─── 3. Spiritual Foundations curriculum ────────────────────────────────────
-- Topics live in their own table, completely separate from participant
-- attendance (which stays in program_attendances.extra_data). Editing a topic
-- later never rewrites a historical attendance row.
CREATE TABLE IF NOT EXISTS `sf_topics` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `week_no`     TINYINT(4)   DEFAULT NULL COMMENT 'Class week 1..N. NULL for non-class rows (e.g. NO CLASS break).',
    `sort_order`  INT(11)      NOT NULL DEFAULT 0 COMMENT 'Position in the printed curriculum, including break rows.',
    `topic`       VARCHAR(150) NOT NULL,
    `subtopics`   TEXT         DEFAULT NULL COMMENT 'Newline-separated subtopic list.',
    `is_class`    TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = NO CLASS break; never counted as attendance.',
    `is_required` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = must be completed for certificate eligibility.',
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `is_deleted`  TINYINT(1)   NOT NULL DEFAULT 0,
    `dateadded`   DATETIME     NOT NULL DEFAULT current_timestamp(),
    `dateupdated` DATETIME     DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sf_week` (`week_no`),
    KEY `idx_sf_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 10 class weeks + the No Class break. Skipped if already seeded.
-- Topics are never split into Part 1 / Part 2 — each topic is exactly one week.
INSERT INTO `sf_topics` (`week_no`, `sort_order`, `topic`, `subtopics`, `is_class`, `is_required`)
SELECT * FROM (
    SELECT  1 AS w,  1 AS s, 'Victory Day'                  AS t, 'See Victory Day Program file'                                                                  AS sub, 1 AS c, 1 AS r UNION ALL
    SELECT  2,       2,      'God and His Word',                 "God's Word\nAttributes of God's Greatness\nAttributes of God's Goodness\nThe Trinity",               1,      1 UNION ALL
    SELECT  NULL,    3,      'No Class',                         NULL,                                                                                                 0,      0 UNION ALL
    SELECT  3,       4,      'Creation, The Fall and Sin',       "Creation\nMan\nThe Fall and Sin\nThe Spiritual War",                                                 1,      1 UNION ALL
    SELECT  4,       5,      'Jesus and the Cross',              "The Person of Jesus Christ\nThe Work of Jesus Christ\nAtoning Work of Christ\nJustification and Adoption", 1, 1 UNION ALL
    SELECT  5,       6,      'Our Sanctification',               "Christlikeness\nIdentity\nLordship\nRestoration",                                                    1,      1 UNION ALL
    SELECT  6,       7,      'Future Hope',                      "Christ's Glorious Return\nEternal Judgment\nNew Creation\nLife in Light of Eternity",                1,      1 UNION ALL
    SELECT  7,       8,      'Holy Spirit and Spiritual Gifts',  "The Holy Spirit\nIntroduction to Spiritual Gifts\nSpiritual Gifts\nPracticing Spiritual Gifts",      1,      1 UNION ALL
    SELECT  8,       9,      'Spiritual Disciplines',            "Introduction to Spiritual Disciplines\nWord and Worship\nPrayer and Fasting\nService and Giving",    1,      1 UNION ALL
    SELECT  9,      10,      'The Church',                       "What is the Church?\nMetaphors of the Church\nThe Sacrament\nGod's Mission",                         1,      1 UNION ALL
    SELECT 10,      11,      'Spiritual Family',                 "Purpose\nPastors\nProvision\nParticipation\nStatement of Faith\nMembership Commitment",              1,      1
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `sf_topics` LIMIT 1);

-- Spiritual Foundations is now a live discipleship step (it was seeded inactive).
UPDATE `discipleship_steps`
   SET `is_active` = 1, `is_deleted` = 0
 WHERE `column_key` = 'spiritual_foundations';


-- ─── 4. Serve Teams ─────────────────────────────────────────────────────────
-- Mirrors the victory_groups + vg_members shape (a team header row plus a
-- membership junction that links to members.id when the person is registered).
CREATE TABLE IF NOT EXISTS `serve_teams` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `uuid`          VARCHAR(36)  NOT NULL,
    `name`          VARCHAR(150) NOT NULL,
    `ministry`      VARCHAR(100) DEFAULT '' COMMENT 'Matches ministries.name (same convention as members.ministry).',
    `service_time`  VARCHAR(100) DEFAULT '' COMMENT 'Worship service the team serves, e.g. "8:30 AM".',
    `day_of_week`   VARCHAR(255) DEFAULT NULL,
    `meetup_time`   TIME         DEFAULT NULL,
    `meeting_place` VARCHAR(255) DEFAULT '',
    `team_status`   ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `is_deleted`    TINYINT(1)   NOT NULL DEFAULT 0,
    `sort_order`    INT(11)      NOT NULL DEFAULT 0,
    `notes`         TEXT         DEFAULT NULL,
    `dateadded`     DATETIME     NOT NULL DEFAULT current_timestamp(),
    `dateupdated`   DATETIME     DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uuid` (`uuid`),
    KEY `idx_st_status` (`team_status`),
    KEY `idx_st_ministry` (`ministry`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `serve_team_members` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `team_id`    INT(11)      NOT NULL,
    `member_id`  INT(11)      DEFAULT NULL,
    `name`       VARCHAR(255) NOT NULL,
    `role`       ENUM('leader','member') NOT NULL DEFAULT 'member',
    `sort_order` INT(11)      NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_stm_team` (`team_id`),
    KEY `idx_stm_role` (`role`),
    KEY `fk_stm_member` (`member_id`),
    CONSTRAINT `fk_stm_team`   FOREIGN KEY (`team_id`)   REFERENCES `serve_teams` (`id`) ON DELETE CASCADE  ON UPDATE CASCADE,
    CONSTRAINT `fk_stm_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)     ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
