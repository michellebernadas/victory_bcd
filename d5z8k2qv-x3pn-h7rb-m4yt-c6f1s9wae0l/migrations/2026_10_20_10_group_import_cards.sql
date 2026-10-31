-- ============================================================================
-- Victory Bacolod — Staff VG Schedule import: the GROUP CARD staging table
--
-- WHY A SECOND TABLE
-- 2026_10_20_08_import_staging.sql created `group_import_staging`, which holds
-- ONE ROW PER PERSON. That is the right shape for reviewing names, but it has
-- no way to say "these eight people belong to the same group card", and no way
-- to record what happened to the GROUP itself as opposed to a person in it.
--
-- The Staff VG Schedule is a visual grid: group "cards" laid out five across
-- and four down, each card being
--     VGL: / Intern: / Members: 1..n / Type of VG: / Day: / Time: /
--     Frequency of meeting: / Venue:
-- So one card = one victory_groups row + n vg_members rows. `group_import_cards`
-- is the card; `group_import_staging` rows point back at it via card_index.
--
-- WHAT THIS DOES NOT DO
-- It creates nothing in victory_groups or vg_members. A staged batch is inert
-- until an admin presses Confirm on the review screen, and a confirmed row is
-- recorded here (created_group_id / created_vg_member_id) so re-confirming the
-- same batch can never produce a second copy.
--
-- CAMPUS IS NOT A VICTORY GROUP. group_kind is decided at parse time from the
-- card's own "Type of VG" text and shown on the review screen before anything
-- is written, because a Campus group filed as a Victory Group would quietly
-- inflate every Victory Group count in the app.
--
-- NO PERSONAL DATA IS SEEDED. Created empty; names only arrive at runtime from
-- a file an admin uploads.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS.
-- Creates no rows.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_10_group_import_cards.sql
-- ============================================================================

-- ─── 1. One row per group card found on the sheet ───────────────────────────
CREATE TABLE IF NOT EXISTS `group_import_cards` (
    `id`         INT(11)     NOT NULL AUTO_INCREMENT,
    `batch_id`   VARCHAR(36) NOT NULL COMMENT 'Groups one uploaded file into a single preview.',
    `card_index` INT(11)     NOT NULL COMMENT 'Position of the card in the sheet. Joins group_import_staging.card_index.',
    `sheet_name` VARCHAR(150) DEFAULT NULL,
    `cell_ref`   VARCHAR(20)  DEFAULT NULL COMMENT 'Top-left cell of the card, e.g. "A6", so the admin can find it in Excel.',
    `raw_data`   TEXT         DEFAULT NULL COMMENT 'The whole parsed card as JSON, exactly as read.',

    -- Group facts, as read
    `source_label`      VARCHAR(255) DEFAULT NULL COMMENT 'Verbatim spreadsheet text. Copied to victory_groups.source_label and never overwritten.',
    `group_kind`        ENUM('victory_group','leadership_group','campus') NOT NULL DEFAULT 'victory_group',
    `audience`          VARCHAR(50)  DEFAULT NULL COMMENT 'Normalised from "Type of VG": couples, single men, campus, ...',
    `raw_audience`      VARCHAR(255) DEFAULT NULL COMMENT 'The "Type of VG" cell exactly as written.',
    `group_name`        VARCHAR(150) DEFAULT NULL COMMENT 'Readable label built for victory_groups.name.',
    `day_of_week`       VARCHAR(100) DEFAULT NULL,
    `meeting_time`      TIME         DEFAULT NULL COMMENT 'NULL when the source time could not be read.',
    `raw_time`          VARCHAR(100) DEFAULT NULL COMMENT 'Original text: "0.41666666666667", "3-5PM", "12:00 NN".',
    `meeting_frequency` VARCHAR(50)  DEFAULT NULL,
    `venue`             VARCHAR(255) DEFAULT NULL,

    -- Review state
    `people_count`     INT(11) NOT NULL DEFAULT 0,
    `needs_link_count` INT(11) NOT NULL DEFAULT 0,
    `row_status`   ENUM('ready','needs_review','invalid') NOT NULL DEFAULT 'needs_review'
        COMMENT 'invalid = no leader and no people; there is no group to create.',
    `issues`       TEXT    DEFAULT NULL COMMENT 'JSON array of human-readable warnings.',
    `created_group_id` INT(11)  DEFAULT NULL COMMENT 'Set on confirm. Makes the commit idempotent per card.',
    `imported_at`      DATETIME DEFAULT NULL,
    `dateadded`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_gic_batch_card` (`batch_id`, `card_index`),
    KEY `idx_gic_batch` (`batch_id`, `row_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── 2. Tie each staged PERSON back to its card ─────────────────────────────
-- group_import_staging already holds the person columns (display_name,
-- is_couple_name, link_status, matched_member_id). These three columns are the
-- only thing it was missing: which card the person is on, where they sit in the
-- list, and which vg_members row they eventually became.
ALTER TABLE `group_import_staging`
    ADD COLUMN IF NOT EXISTS `card_index` INT(11) NOT NULL DEFAULT 0
        COMMENT 'Joins group_import_cards.card_index within the same batch_id.'
        AFTER `batch_id`;

ALTER TABLE `group_import_staging`
    ADD COLUMN IF NOT EXISTS `sort_order` INT(11) NOT NULL DEFAULT 0
        COMMENT 'Order within the card, so the imported list reads like the sheet.'
        AFTER `card_index`;

ALTER TABLE `group_import_staging`
    ADD COLUMN IF NOT EXISTS `created_vg_member_id` INT(11) DEFAULT NULL
        COMMENT 'Set on confirm. Makes the commit idempotent per person, not just per card.'
        AFTER `created_group_id`;

ALTER TABLE `group_import_staging`
    ADD INDEX IF NOT EXISTS `idx_gis_batch_card` (`batch_id`, `card_index`, `sort_order`);

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_10_group_import_cards.sql', 'New: group_import_cards. group_import_staging gains card_index, sort_order, created_vg_member_id.');
