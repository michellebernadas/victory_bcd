-- ============================================================================
-- Victory Bacolod — review status for historical completions
--
-- Historical completions are legacy records preserved because the member was
-- previously marked complete but no attendance/class evidence survives. Until
-- now there was no way to tell "an admin has looked at this and confirmed it"
-- from "this was auto-preserved by a migration and nobody has reviewed it
-- yet". historical_verification_status adds that review layer on TOP of
-- historical_approved, which keeps doing its existing job (the completion
-- gate). Reuses the existing verified_by / verified_at columns rather than
-- creating duplicate fields — they already mean "who/when", which is exactly
-- what Verify/Reject need to record.
--
-- 'Needs Verification' rows MUST keep counting as completed — this migration
-- does not touch historical_approved, completion_source, or any completed_at
-- value, so recalculateMember()'s existing logic is completely unaffected.
--
-- Safe / idempotent. No completion is added, removed, or revoked by this
-- migration. All 87 existing preserved completions become 'pending' and
-- remain historical_approved = 1 — nothing is bulk-verified.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_01_07_historical_verification_status.sql
-- ============================================================================

ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `historical_verification_status`
        ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'
        COMMENT 'Review status of a historical completion. Independent of historical_approved (the completion gate) and completion_source (the evidence origin).'
        AFTER `historical_approved`;

-- Backfill: every existing historical-approved row starts out unreviewed.
-- Does NOT change historical_approved, so these members stay completed.
UPDATE `member_discipleship`
   SET `historical_verification_status` = 'pending'
 WHERE `historical_approved` = 1
   AND `historical_verification_status` != 'pending';

ALTER TABLE `member_discipleship`
    ADD INDEX IF NOT EXISTS `idx_md_hist_verif_status` (`historical_verification_status`);
