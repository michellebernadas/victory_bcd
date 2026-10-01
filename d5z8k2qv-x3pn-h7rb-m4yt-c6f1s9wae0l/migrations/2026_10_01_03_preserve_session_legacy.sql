-- ============================================================================
-- Victory Bacolod — one-time marker table for data reconciliations
--
-- Some reconciliation passes must run EXACTLY once: re-running them would
-- change meaning rather than converge. The Leadership 1-1-3 pass is the
-- example — it converts a pre-existing legacy completion into a `historical`
-- row, and if it ran again later it would wrongly grant historical completion
-- to a genuinely new, genuinely incomplete record.
--
-- This table records which passes have run so they become no-ops afterwards.
--
-- Safe / idempotent.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_01_03_preserve_session_legacy.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `app_reconciliations` (
    `id`        INT(11)      NOT NULL AUTO_INCREMENT,
    `name`      VARCHAR(100) NOT NULL COMMENT 'Reconciliation pass identifier.',
    `notes`     VARCHAR(255) DEFAULT NULL,
    `ran_at`    DATETIME     NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_recon_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
