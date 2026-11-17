-- ============================================================================
-- Victory Bacolod — public site: which groups are shown, and join requests
--
-- 1. victory_groups.show_public
--    The public pages (groups.php, services.php in the site root) list only
--    groups with show_public = 1 that are active, not deleted, and of kind
--    victory_group or campus. Leadership Groups are never public.
--    Existing ACTIVE Victory Groups and Campus groups are published by this
--    migration, because making them public is the point. Untick any group in
--    its edit form to hide it. New groups default to HIDDEN until ticked.
--
-- 2. join_requests
--    The inbox for the public "Request to join" form. Holds personal data, so
--    it is admin-only (permission join_requests) and the visitor's IP is stored
--    only as a hash, used for rate limiting.
--
-- Safe / idempotent: ADD COLUMN IF NOT EXISTS, CREATE TABLE IF NOT EXISTS. The
-- publish step only runs while this file is not yet in the ledger, so a re-run
-- never re-publishes a group an admin has since hidden.
-- MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_17_01_public_site.sql
-- ============================================================================

ALTER TABLE `victory_groups`
    ADD COLUMN IF NOT EXISTS `show_public` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = listed on the public Groups page (victory_group / campus only).';

UPDATE `victory_groups`
   SET `show_public` = 1
 WHERE `group_kind` IN ('victory_group', 'campus')
   AND `group_status` = 'active' AND `is_deleted` = 0
   AND NOT EXISTS (SELECT 1 FROM `schema_migrations` m WHERE m.filename = '2026_11_17_01_public_site.sql');

CREATE TABLE IF NOT EXISTS `join_requests` (
    `id`          INT(11)      NOT NULL AUTO_INCREMENT,
    `group_id`    INT(11)      DEFAULT NULL COMMENT 'The group the visitor asked about; NULL = any / unsure.',
    `full_name`   VARCHAR(150) NOT NULL,
    `email`       VARCHAR(190) DEFAULT NULL,
    `phone`       VARCHAR(60)  DEFAULT NULL,
    `area`        VARCHAR(150) DEFAULT NULL COMMENT 'Where they live / prefer to meet.',
    `message`     VARCHAR(1000) DEFAULT NULL,
    `source`      VARCHAR(40)  NOT NULL DEFAULT 'public_site' COMMENT 'Which page/form sent it (the landing page will set its own).',
    `status`      ENUM('new','contacted','placed','closed') NOT NULL DEFAULT 'new',
    `staff_notes` VARCHAR(1000) DEFAULT NULL,
    `handled_by`  INT(11)      DEFAULT NULL COMMENT 'accounts.id of whoever last updated it.',
    `ip_hash`     CHAR(64)     DEFAULT NULL COMMENT 'sha256 of the visitor IP; rate limiting only.',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_jr_status` (`status`, `created_at`),
    KEY `idx_jr_group` (`group_id`),
    KEY `idx_jr_ip` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_17_01_public_site.sql', 'victory_groups.show_public + join_requests inbox for the public site.');
