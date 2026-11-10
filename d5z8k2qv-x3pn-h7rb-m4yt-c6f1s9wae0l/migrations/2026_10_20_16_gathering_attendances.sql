-- ============================================================================
-- Victory Bacolod — weekday gatherings + attendance import staging
--
-- 1. gathering_attendances
--    One head count per gathering per date. Today two gatherings:
--      prayer_meeting    — a weekday (Tuesday or Wednesday)
--      students_hangout  — every Friday
--    gathering_type is a VARCHAR, not an ENUM, so another weekday gathering can
--    be added later by adding one line to GatheringAttendance::TYPES in PHP —
--    no ALTER TABLE needed. PHP whitelists the value on every write.
--    UNIQUE (gathering_type, event_date): one count per gathering per date,
--    even under a double-submit; the app turns the clash into a friendly
--    "already recorded — edit it instead" message.
--    attendance >= 0 is checked in PHP (CHECK constraints are not enforced on
--    MariaDB 10.1).
--
-- 2. attendance_import_staging
--    The Worship Service Annual Attendance workbook is loaded through the same
--    UPLOAD -> PREVIEW -> CONFIRM flow as the member / VG / facility importers.
--    Parsed values land here first; only the admin's Confirm writes them to
--    service_attendances / gathering_attendances. Rows are deleted again after
--    a commit or a discard.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS. Creates no data rows.
-- MariaDB 10.1 compatible (indexed VARCHARs <= 191 for utf8mb4); plain SQL
-- for phpMyAdmin's SQL tab.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `gathering_attendances` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `gathering_type` VARCHAR(40)  NOT NULL COMMENT 'prayer_meeting | students_hangout (whitelisted in PHP).',
    `event_date`     DATE         NOT NULL,
    `attendance`     INT(11)      NOT NULL DEFAULT 0 COMMENT 'Head count. Must be >= 0 (checked in PHP).',
    `notes`          VARCHAR(255) DEFAULT NULL,
    `recorded_by`    INT(11)      DEFAULT NULL COMMENT 'accounts.id of whoever entered or imported it.',
    `dateadded`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `dateupdated`    DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_gathering_type_date` (`gathering_type`, `event_date`),
    KEY `idx_ga_date` (`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_import_staging` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `batch_id`     VARCHAR(36)  NOT NULL,
    `source_cell`  VARCHAR(40)  DEFAULT NULL COMMENT 'Where on the sheet, e.g. "2026!I5".',
    `kind`         VARCHAR(20)  NOT NULL COMMENT 'sunday | prayer_meeting | students_hangout',
    `event_date`   DATE         DEFAULT NULL,
    `service_name` VARCHAR(100) DEFAULT NULL COMMENT 'Sunday rows: matches services.name.',
    `count_a`      INT(11)      DEFAULT NULL COMMENT 'Sunday Service count, or the gathering head count.',
    `count_b`      INT(11)      DEFAULT NULL COMMENT 'Kids Church count (Sunday rows). NULL = not recorded.',
    `notes`        VARCHAR(255) DEFAULT NULL,
    `row_status`   VARCHAR(20)  NOT NULL DEFAULT 'ready' COMMENT 'ready | existing | skipped | invalid',
    `issues`       TEXT         DEFAULT NULL COMMENT 'JSON array of messages for the preview.',
    `dateadded`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ais_batch` (`batch_id`, `row_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_16_gathering_attendances.sql', 'New: gathering_attendances (Prayer Meeting / Students'' Hangout head counts) and attendance_import_staging.');
