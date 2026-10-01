-- ============================================================================
-- Victory Bacolod — keep the historical approval independent of the live source
--
-- member_discipleship has one row per member+step (uq_member_step). That meant
-- `completion_source` was doing two jobs at once: recording the approval AND
-- reporting the current source. When attendance appeared, the row's source was
-- flipped to 'attendance' and the historical approval was destroyed — so
-- deleting that attendance later dropped the step to incomplete even though an
-- admin had approved it.
--
-- Splitting them fixes it:
--   historical_approved — a durable fact an admin asserted; survives everything
--   completion_source   — derived: 'attendance' when evidence exists, else
--                         'historical' when approved
--
-- Safe / idempotent. No completion is added or removed by this migration.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_01_04_historical_approved_flag.sql
-- ============================================================================

ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `historical_approved` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'An admin approved this as a legacy completion. Durable: never cleared by recalculation, and survives attendance appearing then being removed.'
        AFTER `completion_source`;

-- Backfill: every row currently marked historical IS an approved legacy completion.
UPDATE `member_discipleship`
   SET `historical_approved` = 1
 WHERE `completion_source` = 'historical'
   AND `historical_approved` = 0;

ALTER TABLE `member_discipleship`
    ADD INDEX IF NOT EXISTS `idx_md_hist_approved` (`historical_approved`);
