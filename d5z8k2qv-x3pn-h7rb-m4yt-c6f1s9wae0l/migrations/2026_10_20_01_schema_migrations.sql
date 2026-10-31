-- ============================================================================
-- Victory Bacolod — migration tracking table
--
-- Twelve migrations have been applied to production by hand, with no record of
-- which ones ran. With the 2026 Foundation Reset adding eight more, "has this
-- already run?" stops being answerable from memory.
--
-- schema_migrations is a plain ledger: one row per applied migration file.
-- It does NOT run anything — every migration still has to be applied manually
-- with `mysql -uroot victory_bacolod < migrations/<file>.sql`. The ledger just
-- records that it happened, so a second run can be recognised as a no-op.
--
-- Every migration in this series ends by INSERT IGNORE-ing its own filename.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS, INSERT IGNORE. No existing
-- data is read, changed, or deleted.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_01_schema_migrations.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `filename`   VARCHAR(190) NOT NULL COMMENT 'Migration filename, e.g. 2026_10_20_02_member_contact_fields.sql',
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`      VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schema_migration_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Backfill the twelve migrations already applied ─────────────────────────
-- applied_at is left at NOW() because the real application time is unknown —
-- recording a guessed date would be worse than recording when we noticed.
-- INSERT IGNORE: re-running this file never duplicates a row.
INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`) VALUES
    ('2026_09_30_01_schema.sql',                          'Backfilled — applied before tracking existed.'),
    ('2026_09_30_02_sf_sample_data.sql',                  'Backfilled — applied before tracking existed.'),
    ('2026_09_30_03_sf_ten_weeks.sql',                    'Backfilled — applied before tracking existed.'),
    ('2026_09_30_04_sf_weeks_and_batch2.sql',             'Backfilled — applied before tracking existed.'),
    ('2026_10_01_01_serve_options.sql',                   'Backfilled — applied before tracking existed.'),
    ('2026_10_01_02_discipleship_source_of_truth.sql',    'Backfilled — applied before tracking existed.'),
    ('2026_10_01_03_preserve_session_legacy.sql',         'Backfilled — applied before tracking existed.'),
    ('2026_10_01_04_historical_approved_flag.sql',        'Backfilled — applied before tracking existed.'),
    ('2026_10_01_05_sf_step_color_purple.sql',            'Backfilled — applied before tracking existed.'),
    ('2026_10_01_06_pbc_lighter_purple.sql',              'Backfilled — applied before tracking existed.'),
    ('2026_10_01_07_historical_verification_status.sql',  'Backfilled — applied before tracking existed.'),
    ('2026_10_02_01_historical_completion_reviews.sql',   'Backfilled — applied before tracking existed.');

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_01_schema_migrations.sql', '2026 Foundation Reset — migration ledger.');
