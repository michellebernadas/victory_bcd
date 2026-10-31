-- ============================================================================
-- Victory Bacolod — facility request lifecycle: soft delete, ownership, series
--
-- WHAT THIS ADDS (all on facility_requests; no existing column changes)
--
--   is_deleted / deleted_at / deleted_by / delete_reason
--       Soft delete, for MISTAKES ONLY (test rows, duplicates). Admin only,
--       reason required (config/permissions.php canDeleteFacilityRequest).
--       Cancel stays the normal workflow outcome and is kept on record. A
--       deleted request is excluded everywhere: lists, calendar, stats,
--       waiting badge, conflict checks, setup sheet, exports. The row and its
--       history are never removed; 'deleted' / 'restored' history rows record
--       who did it and why. Deleting a confirmed booking frees the room.
--
--   submitted_by
--       accounts.id of the person who filed the request in the portal — the
--       ownership model behind "My Requests", edit-own / resubmit and
--       cancel-own. NULL for imported requests (the importing admin is not the
--       requestor) and for every row that existed before this migration.
--       ON DELETE SET NULL: removing an account never removes a booking.
--
--   series_id
--       Shared by the separate requests created from one "Repeat weekly"
--       submission, so the detail view can say "Part of a weekly series
--       (3 of 8)". Each request still goes through the workflow on its own.
--
-- facility_request_history.action is VARCHAR(50), not an ENUM, so the new
-- action values ('deleted', 'restored', 'resubmitted') need no schema change.
--
-- "EXPIRED" IS NOT STORED. A pending request whose date has passed is shown as
-- Expired at read time; nothing here (or anywhere) changes its status.
--
-- MariaDB 10.1 compatible: ADD COLUMN / ADD INDEX IF NOT EXISTS (10.0.2+), the
-- FK guarded via information_schema + PREPARE (no ADD CONSTRAINT IF NOT
-- EXISTS), no generated columns, no expression defaults, no JSON.
-- Safe / idempotent: re-running it changes nothing.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_13_facility_request_lifecycle.sql
-- ============================================================================

ALTER TABLE `facility_requests`
    ADD COLUMN IF NOT EXISTS `is_deleted`    TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT '1 = soft-deleted by an admin (mistakes / duplicates only). Excluded everywhere.',
    ADD COLUMN IF NOT EXISTS `deleted_at`    DATETIME     DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `deleted_by`    INT(11)      DEFAULT NULL COMMENT 'accounts.id',
    ADD COLUMN IF NOT EXISTS `delete_reason` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `submitted_by`  INT(11)      DEFAULT NULL
        COMMENT 'accounts.id of the portal submitter (owner). NULL for imported / pre-existing rows.',
    ADD COLUMN IF NOT EXISTS `series_id`     VARCHAR(36)  DEFAULT NULL
        COMMENT 'Shared by requests created together from one Repeat weekly submission.';

ALTER TABLE `facility_requests`
    ADD INDEX IF NOT EXISTS `idx_fr_deleted_status_date` (`is_deleted`, `status`, `date_needed`),
    ADD INDEX IF NOT EXISTS `idx_fr_submitted_by` (`submitted_by`),
    ADD INDEX IF NOT EXISTS `idx_fr_series` (`series_id`);

-- Guarded FK: losing an account sets ownership to NULL, never deletes a booking.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME        = 'facility_requests'
       AND CONSTRAINT_NAME   = 'fk_fr_submitted_by'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `facility_requests` ADD CONSTRAINT `fk_fr_submitted_by`
         FOREIGN KEY (`submitted_by`) REFERENCES `accounts` (`id`)
         ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_13_facility_request_lifecycle.sql',
        'facility_requests: +is_deleted/deleted_at/deleted_by/delete_reason (admin soft delete), +submitted_by (owner, FK accounts SET NULL), +series_id (repeat weekly).');
