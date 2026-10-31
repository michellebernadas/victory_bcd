-- ============================================================================
-- Victory Bacolod — Members: Email + Facebook Profile URL
--
-- The church asked for two optional contact fields on a member record:
--   * email          — validated for format; may later drive notifications or
--                      member login, but NOTHING is built on it yet.
--   * facebook_url   — validated as a URL; the profile shows a plain
--                      "Facebook Profile" link. Nothing is embedded.
--
-- Both are NULLable with no default: an absent value stays absent rather than
-- becoming an empty string that then has to be distinguished from "not set".
-- Existing rows are untouched and keep NULL.
--
-- The email index is non-unique ON PURPOSE. Couples and families in this church
-- routinely share one address, and a UNIQUE key would reject the second person
-- outright. The Excel importer treats an exact email match as a DUPLICATE
-- WARNING for an admin to judge — not as a database-level rejection.
--
-- volunteer_status is NOT dropped here. That column still has ~20 live call
-- sites; dropping it before the code stops reading it would fatal the Members
-- page and the Dashboard. It is removed in its own later migration, after the
-- code changes land.
--
-- Safe / idempotent: ADD COLUMN IF NOT EXISTS / ADD INDEX IF NOT EXISTS.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_02_member_contact_fields.sql
-- ============================================================================

ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) DEFAULT NULL
        COMMENT 'Optional. Validated for format. Not unique — families share addresses.'
        AFTER `contact_number`;

ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `facebook_url` VARCHAR(500) DEFAULT NULL
        COMMENT 'Optional. Validated as a URL. Rendered as a plain outbound link.'
        AFTER `email`;

-- Supports the importer's exact-email duplicate check without scanning members.
-- Prefix of 191 chars: production is MariaDB 10.1, whose default 767-byte
-- index limit rejects a full utf8mb4 VARCHAR(255) (1020 bytes). 191 x 4 = 764.
ALTER TABLE `members`
    ADD INDEX IF NOT EXISTS `idx_members_email` (`email`(191));

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_02_member_contact_fields.sql', 'Members: +email, +facebook_url.');
