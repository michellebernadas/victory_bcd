-- ============================================================================
-- Victory Bacolod — password reset, forced change, self-service change
--
-- 1. accounts.must_change_password
--    Set when an admin creates an account, sets someone else's password or
--    resets it. The person is sent to "choose your own password" at their next
--    sign-in, so the password the admin typed (or forgot) stops mattering.
--    accounts.password_changed_at records when the owner last chose one.
--
-- 2. password_resets
--    One row per "Forgot password?" request. Only a SHA-256 of the emailed
--    token is stored, so a copy of this table cannot be used to reset anyone.
--    A token works once and expires (1 hour). Rows also back the rate limit.
--
-- Safe / idempotent. MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_24_01_password_reset.sql
-- ============================================================================

ALTER TABLE `accounts`
    ADD COLUMN IF NOT EXISTS `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = must choose a new password at next sign-in.',
    ADD COLUMN IF NOT EXISTS `password_changed_at`  DATETIME DEFAULT NULL COMMENT 'When the owner last chose their own password.';

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` INT(11) NOT NULL,
    `token_hash` CHAR(64) NOT NULL COMMENT 'sha256 of the emailed token (the token itself is never stored).',
    `ip`         VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at`    DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_account_created` (`account_id`, `created_at`),
    KEY `idx_ip_created` (`ip`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_24_01_password_reset.sql', 'accounts.must_change_password + password_resets (forgot / reset / forced change).');
