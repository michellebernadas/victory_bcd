-- ============================================================================
-- Victory Bacolod — "completed, I only know the year"
--
-- Leadership 113 and Spiritual Foundations are proven by a session grid, so
-- someone who finished in 1985 and remembers nothing but the year could never be
-- marked complete. program_attendances.completed_manual = 1 records exactly
-- that: the person completed the class in program_year, no session detail.
-- It counts as completed on the member's profile; it does NOT unlock the
-- certificate (that still needs real session attendance).
--
-- The other classes (Victory Day, Church Community, Making Disciples,
-- Empowering Leaders) already count as completed once a record exists.
--
-- Safe / idempotent. MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_17_04_completed_manual.sql
-- ============================================================================

ALTER TABLE `program_attendances`
    ADD COLUMN IF NOT EXISTS `completed_manual` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = marked completed with just the year; no session detail (Leadership 113 / Spiritual Foundations).';

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_17_04_completed_manual.sql', 'program_attendances.completed_manual for year-only completions.');
