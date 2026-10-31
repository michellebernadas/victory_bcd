-- ============================================================================
-- Victory Bacolod — import staging tables
--
-- THE PRINCIPLE BEHIND ALL THREE TABLES
-- Nothing from a spreadsheet is ever written straight into a live table. Rows
-- land in staging, an admin reviews what was found, and only an explicit
-- confirmation promotes them:
--
--     UPLOAD -> PARSE -> VALIDATE -> PREVIEW -> ADMIN CONFIRMATION -> IMPORT
--
-- The old import.php did the opposite: it read a hardcoded path and ran
-- `DELETE FROM vg_members; DELETE FROM victory_groups;` before inserting,
-- with no login required and no preview. These tables are what replace it.
--
-- raw_data is TEXT holding JSON, not a JSON column: MariaDB 10.4 has no native
-- JSON type, and program_attendances.extra_data already uses exactly this
-- convention.
--
-- NO PERSONAL DATA IS SEEDED. These tables are created empty; real names and
-- contact details only arrive at runtime, from a file the admin uploads.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS. Creates no rows.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_08_import_staging.sql
-- ============================================================================

-- ─── 1. Member imports (section C) ──────────────────────────────────────────
-- One row per spreadsheet row. row_status is what the preview screen counts to
-- produce "X will be created, Y require review, Z already exist".
CREATE TABLE IF NOT EXISTS `member_imports` (
    `id`        INT(11)     NOT NULL AUTO_INCREMENT,
    `batch_id`  VARCHAR(36) NOT NULL COMMENT 'Groups one uploaded file. Shown as a single preview.',
    `row_number` INT(11)    NOT NULL COMMENT 'Source row number, so the admin can find it in their file.',
    `raw_data`  TEXT        DEFAULT NULL COMMENT 'The parsed row as JSON, exactly as read.',

    -- Normalised candidate values
    `first_name` VARCHAR(120) DEFAULT NULL,
    `last_name`  VARCHAR(120) DEFAULT NULL,
    `full_name`  VARCHAR(255) DEFAULT NULL COMMENT 'Canonical "Last, First".',
    `email`      VARCHAR(255) DEFAULT NULL,

    `row_status` ENUM('ready','needs_review','duplicate','existing','invalid')
                 NOT NULL DEFAULT 'needs_review'
        COMMENT 'ready = will create. duplicate/existing = matched someone. invalid = missing or malformed required field.',
    `issues`     TEXT    DEFAULT NULL COMMENT 'JSON array of human-readable warnings for this row.',
    `matched_member_id` INT(11) DEFAULT NULL COMMENT 'members.id this row appears to already be.',
    `match_reason`      VARCHAR(100) DEFAULT NULL COMMENT 'email_exact | name_exact | name_normalized',

    `created_member_id` INT(11)  DEFAULT NULL COMMENT 'Set once the row is actually imported. Prevents double-insert.',
    `imported_at`       DATETIME DEFAULT NULL,
    `dateadded`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_mi_batch` (`batch_id`, `row_status`),
    KEY `idx_mi_matched` (`matched_member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. Group / Staff VG Schedule staging (section G) ───────────────────────
-- The 2026 Staff VG Schedule is a visual grid, not a table: group "cards" laid
-- left-to-right, couple names joined with "&" or "and", Excel time fractions
-- next to free text like "3-5PM", and the same leader appearing in two groups.
--
-- display_name keeps whatever was written, verbatim. A couple is ONE row, never
-- split into two people, and no member is ever auto-created from it. When a
-- name cannot be matched safely the row stays 'needs_link' until an admin
-- connects it — which they can only do after they have added that 2026 member.
CREATE TABLE IF NOT EXISTS `group_import_staging` (
    `id`        INT(11)     NOT NULL AUTO_INCREMENT,
    `batch_id`  VARCHAR(36) NOT NULL,
    `raw_data`  TEXT        DEFAULT NULL COMMENT 'The parsed group card as JSON.',

    -- Group facts as read from the sheet
    `source_label`      VARCHAR(255) DEFAULT NULL COMMENT 'Original cell text. Never overwritten.',
    `group_kind`        ENUM('victory_group','leadership_group','campus') NOT NULL DEFAULT 'victory_group'
        COMMENT 'Campus rows are separated here, not left mixed in with Victory Groups.',
    `audience`          VARCHAR(50)  DEFAULT NULL COMMENT 'From "Type of VG", e.g. Couples, Single Men, Campus.',
    `day_of_week`       VARCHAR(100) DEFAULT NULL,
    `meeting_time`      TIME         DEFAULT NULL COMMENT 'NULL when the source time could not be parsed.',
    `raw_time`          VARCHAR(100) DEFAULT NULL COMMENT 'The original time text, e.g. "3-5PM", "12:00 NN", "0.79166666666667".',
    `meeting_frequency` VARCHAR(50)  DEFAULT NULL,
    `venue`             VARCHAR(255) DEFAULT NULL,

    -- One person as written on the sheet
    `person_role`    ENUM('leader','intern','attendee','lg_leader') DEFAULT NULL,
    `display_name`   VARCHAR(255) DEFAULT NULL COMMENT 'VERBATIM. "Amy & Carl" stays one string.',
    `is_couple_name` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Contains & / and. Flagged for review, NEVER auto-split.',
    `matched_member_id` INT(11) DEFAULT NULL,
    `link_status`    ENUM('linked','needs_link','not_a_member') NOT NULL DEFAULT 'needs_link',

    `row_status`     ENUM('ready','needs_review','invalid') NOT NULL DEFAULT 'needs_review',
    `issues`         TEXT     DEFAULT NULL COMMENT 'JSON array of warnings, e.g. unparseable time, mononym.',
    `created_group_id` INT(11) DEFAULT NULL COMMENT 'Set once promoted into victory_groups.',
    `imported_at`    DATETIME DEFAULT NULL,
    `dateadded`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gis_batch` (`batch_id`, `row_status`),
    KEY `idx_gis_link` (`link_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 3. Facility request staging (section Q) ────────────────────────────────
-- The church's FACILITIES REQUEST FORM.xlsx holds a blank template, two real
-- requests, and two unrelated finance sheets. Only request-shaped sheets are
-- staged; the finance ones are ignored.
--
-- Critically: both real requests have a BLANK Facilities Review and a BLANK
-- Minister Decision. They import as Submitted, never Approved. Existing in a
-- spreadsheet is not an approval.
CREATE TABLE IF NOT EXISTS `facility_request_imports` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `batch_id`     VARCHAR(36)  NOT NULL,
    `source_sheet` VARCHAR(150) DEFAULT NULL COMMENT 'Worksheet name the row came from.',
    `raw_data`     TEXT         DEFAULT NULL COMMENT 'The parsed form as JSON.',
    `parsed_status` ENUM('ready','needs_review','invalid') NOT NULL DEFAULT 'needs_review',
    `issues`       TEXT         DEFAULT NULL,
    `created_request_id` INT(11) DEFAULT NULL,
    `reviewed_by`  INT(11)      DEFAULT NULL COMMENT 'accounts.id',
    `reviewed_at`  DATETIME     DEFAULT NULL,
    `imported_at`  DATETIME     DEFAULT NULL,
    `dateadded`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_fri_batch` (`batch_id`, `parsed_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_08_import_staging.sql', 'New: member_imports, group_import_staging, facility_request_imports.');
