-- ============================================================================
-- Victory Bacolod — Facilities / rooms / bookable resources
--
-- The catalogue of things a ministry can request, taken from section 2 of the
-- Victory Bacolod Church Facilities, Equipment & Resource Request Policy
-- (effective September 1, 2026).
--
-- LEAD TIMES ARE DATA, NOT CODE
-- Policy section 10 gives a recommended minimum lead time per request type —
-- 7 days for the Sanctuary / Main Hall, 3 days for a routine room. Section 11
-- then says last-minute requests MAY still be considered. So the lead time is
-- stored per facility and used to raise a WARNING, never to block a request.
-- An admin can change a number here without a code deploy.
--
-- WHAT IS SEEDED
-- Only the generic facility names printed in the policy. No personal names,
-- no contact details, no ministry assignments — none of that belongs in a
-- migration, and the real request data stays in the spreadsheet.
--
-- facility_type mirrors the policy's four request categories so that furniture
-- and equipment can later be catalogued in the same table rather than needing
-- their own. Only rooms are seeded now, because only rooms are booked against
-- a calendar; is_bookable distinguishes them.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE on a UNIQUE
-- name, so re-running never duplicates a facility or resets an edited one.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_06_facilities.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `facilities` (
    `id`                INT(11)      NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(150) NOT NULL,
    `facility_type`     ENUM('room','furniture','equipment','office_resource') NOT NULL DEFAULT 'room'
        COMMENT 'The four request categories from policy section 4.',
    `capacity`          INT(11)      DEFAULT NULL COMMENT 'Informational only. Never enforced against expected attendance.',
    `default_lead_days` INT(11)      NOT NULL DEFAULT 3
        COMMENT 'Recommended minimum lead time (policy section 10). Drives a warning, never a block.',
    `is_bookable`       TINYINT(1)   NOT NULL DEFAULT 1
        COMMENT '1 = occupies the reservation calendar and is checked for conflicts.',
    `notes`             VARCHAR(255) DEFAULT NULL,
    `sort_order`        INT(11)      NOT NULL DEFAULT 0,
    `is_active`         TINYINT(1)   NOT NULL DEFAULT 1,
    `is_deleted`        TINYINT(1)   NOT NULL DEFAULT 0,
    `dateadded`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `dateupdated`       DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_facility_name` (`name`),
    KEY `idx_fac_type_active` (`facility_type`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rooms listed in policy section 2 / section 4A. Lead times from section 10:
-- Sanctuary / Main Hall = 7 days; every other routine room request = 3 days.
INSERT IGNORE INTO `facilities`
    (`name`, `facility_type`, `default_lead_days`, `is_bookable`, `sort_order`, `notes`) VALUES
    ('Main Hall / Sanctuary',  'room', 7, 1, 1, 'Policy section 10: 7 days recommended lead time.'),
    ('Conference Room',        'room', 3, 1, 2, NULL),
    ('Kids Church Facility',   'room', 3, 1, 3, NULL),
    ('Music Room',             'room', 3, 1, 4, NULL),
    ('Chapel',                 'room', 3, 1, 5, NULL),
    ('Offices',                'room', 3, 1, 6, NULL),
    ('Lobby / Common Areas',   'room', 3, 1, 7, 'Shared space — check for overlapping church-wide activities.');

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_06_facilities.sql', 'New: facilities + 7 seeded rooms from the facility request policy.');
