-- ============================================================================
-- Victory Bacolod — Sunday Service Attendance ("Church Health")
--
-- One row per service, per Sunday. Deliberately the simplest table in the
-- schema: the church records four numbers and nothing else, because anything
-- more would not get filled in week after week.
--
--     Date  +  Service Time  →  Adults  +  Kids  =  Grand Total
--
-- THREE RULES ENFORCED BY THE DATABASE, NOT BY PHP
--
-- 1. Grand Total is a STORED GENERATED COLUMN.
--    The admin never types it and no code can write it, so it can never
--    disagree with adults + kids. Verified working on MariaDB 10.4.32, which
--    is the production version.
--
-- 2. One entry per Date + Service Time (uq_service_date_name).
--    "Prevent duplicate entries for the SAME Date + Service Time unless the
--    user explicitly edits the existing entry" — a UNIQUE key makes that true
--    even if a form is double-submitted or two admins save at once. The app
--    catches the violation and offers to open the existing record instead.
--
-- 3. Counts cannot be negative (chk_sa_counts).
--
-- WHAT IS DELIBERATELY ABSENT
--
--   * No average column. Averages are computed from the rows
--     (AVG(grand_total) over the filtered range) so they can never go stale.
--   * No "count" column. "Count" in the request means the row number shown in
--     the table; storing it would be a second, immediately-wrong copy of
--     something the view already knows.
--
-- service_name is a VARCHAR matching services.name, following the convention
-- already used by members.service_attending and serve_teams.service_name — not
-- an FK, because a service can be renamed or retired without invalidating the
-- historical head counts recorded under its old name.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS. Creates no rows.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_05_service_attendances.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `service_attendances` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `service_date` DATE         NOT NULL COMMENT 'The Sunday (or other service day) being counted.',
    `service_name` VARCHAR(100) NOT NULL COMMENT 'Matches services.name where is_service_time = 1, e.g. "8:30 AM".',
    `adults`       INT(11)      NOT NULL DEFAULT 0,
    `kids`         INT(11)      NOT NULL DEFAULT 0,
    `grand_total`  INT(11) AS (`adults` + `kids`) PERSISTENT
        COMMENT 'Always adults + kids. Generated — never written by the app, never typed by an admin.',
    `notes`        VARCHAR(255) DEFAULT NULL COMMENT 'Optional, e.g. "Baptism Sunday", "typhoon".',
    `recorded_by`  INT(11)      DEFAULT NULL COMMENT 'accounts.id of the admin who entered this.',
    `dateadded`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `dateupdated`  DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_service_date_name` (`service_date`, `service_name`),
    KEY `idx_sa_date` (`service_date`),
    CONSTRAINT `chk_sa_counts` CHECK (`adults` >= 0 AND `kids` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_05_service_attendances.sql', 'New: service_attendances (Sunday head counts, generated grand_total).');
