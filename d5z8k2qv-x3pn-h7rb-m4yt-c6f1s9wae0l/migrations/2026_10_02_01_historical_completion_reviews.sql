-- ============================================================================
-- Victory Bacolod — append-only audit trail for historical completion reviews
--
-- Historical completions can now move through pending -> verified -> rejected
-- and back again (Reopen for Review / Restore for Review). member_discipleship
-- only has room to store the CURRENT verified_by/verified_at/notes, so every
-- transition before the latest one was being silently overwritten.
--
-- historical_completion_reviews is append-only: one row is INSERTed per
-- transition and existing rows are never updated or deleted. It is a pure log
-- next to member_discipleship, which continues to represent current state.
--
-- Safe / idempotent: CREATE TABLE IF NOT EXISTS, no existing data touched.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_02_01_historical_completion_reviews.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `historical_completion_reviews` (
    `id`                    INT(11)     NOT NULL AUTO_INCREMENT,
    `member_discipleship_id` INT(11)    NOT NULL COMMENT 'member_discipleship.id this review event belongs to.',
    `action`                ENUM('verified','rejected','reopened','restored') NOT NULL
        COMMENT 'verified/rejected = admin decision. reopened = verified->pending. restored = rejected->pending.',
    `from_status`           ENUM('pending','verified','rejected') NOT NULL,
    `to_status`             ENUM('pending','verified','rejected') NOT NULL,
    `notes`                 VARCHAR(255) DEFAULT NULL,
    `performed_by`          INT(11)     DEFAULT NULL COMMENT 'accounts.id of the admin who performed this action.',
    `performed_at`          DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_hcr_md_id` (`member_discipleship_id`),
    KEY `idx_hcr_performed_at` (`performed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed one 'verified' row for every row that is CURRENTLY verified, so the
-- review-history modal has at least one entry for completions an admin
-- already confirmed before this table existed. Guarded by NOT EXISTS so
-- re-running this migration never duplicates the seed.
INSERT INTO `historical_completion_reviews`
    (`member_discipleship_id`, `action`, `from_status`, `to_status`, `notes`, `performed_by`, `performed_at`)
SELECT md.`id`, 'verified', 'pending', 'verified', md.`notes`, md.`verified_by`,
       COALESCE(md.`verified_at`, NOW())
  FROM `member_discipleship` md
 WHERE md.`historical_verification_status` = 'verified'
   AND NOT EXISTS (
        SELECT 1 FROM `historical_completion_reviews` hcr
         WHERE hcr.`member_discipleship_id` = md.`id`
   );
