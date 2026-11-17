-- ============================================================================
-- Victory Bacolod — "interested in joining" form: life stage, gender,
-- leader notification
--
-- join_requests.life_stage / gender
--   Asked on the public form so the group list can be narrowed to groups that
--   suit the person (a single man is not offered Single Women or Couples).
--   Kept on the request so staff and leaders know who they are calling.
--
-- victory_groups.notify_email
--   Where to email when someone is interested in this group. Needed because no
--   leader is linked to a member record yet, and members have no email on file.
--   Comma-separated; blank = fall back to the linked leaders' member emails and
--   the staff address in .env (JOIN_NOTIFY_EMAIL). Never shown publicly.
--
-- join_request_notices
--   One row per email attempt, so the inbox can say whether the leader was
--   actually told, and staff can resend.
--
-- Safe / idempotent. MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_17_02_interested_form.sql
-- ============================================================================

ALTER TABLE `join_requests`
    ADD COLUMN IF NOT EXISTS `life_stage` VARCHAR(30) DEFAULT NULL COMMENT 'student | single | married | single_parent | widowed | senior | other',
    ADD COLUMN IF NOT EXISTS `gender`     VARCHAR(10) DEFAULT NULL COMMENT 'man | woman';

ALTER TABLE `victory_groups`
    ADD COLUMN IF NOT EXISTS `notify_email` VARCHAR(255) DEFAULT NULL
        COMMENT 'Comma-separated emails told when someone is interested in this group. Never public.';

CREATE TABLE IF NOT EXISTS `join_request_notices` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `request_id` INT(11)      NOT NULL,
    `kind`       ENUM('leader','staff') NOT NULL,
    `recipient`  VARCHAR(255) DEFAULT NULL,
    `status`     ENUM('sent','logged','failed','no_recipient') NOT NULL,
    `detail`     VARCHAR(500) DEFAULT NULL COMMENT 'Error text, or who had no email on file.',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_jrn_request` (`request_id`),
    CONSTRAINT `fk_jrn_request` FOREIGN KEY (`request_id`) REFERENCES `join_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_17_02_interested_form.sql', 'join_requests life_stage/gender, victory_groups.notify_email, join_request_notices.');
