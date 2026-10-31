-- ============================================================================
-- Victory Bacolod — mark which `services` rows are real Sunday service times
--
-- THE PROBLEM
-- The services table is doing two jobs. Four rows are genuine service times:
--     8:30 AM   11:00 AM   2:00 PM   4:30 PM
-- Three are not times at all — they are member-status markers that were parked
-- in this table because members.service_attending was the only place to put
-- them:
--     "going to Mla"   "migrated"   "transferred to Dmgte"
--
-- The new Sunday Service Attendance module reads its Service Time dropdown
-- from this table (rather than hardcoding the times in several places). Left
-- as-is, an admin recording a Sunday head count would be offered
-- "Service Time: migrated".
--
-- THE FIX
-- A flag, not a deletion. is_service_time = 1 marks the four real times; the
-- three markers get 0. Nothing is removed, no member's service_attending value
-- stops resolving, and the markers keep working exactly as they do today on
-- the Members page.
--
-- Matching is by exact name so a renamed or newly added service is never
-- silently reclassified. Any service added later defaults to 1, because a new
-- row in this table is far more likely to be a real service time than another
-- status marker.
--
-- Safe / idempotent: ADD COLUMN IF NOT EXISTS; the UPDATE only ever touches
-- the three named markers.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_04_service_times_flag.sql
-- ============================================================================

ALTER TABLE `services`
    ADD COLUMN IF NOT EXISTS `is_service_time` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = a real service time, offered for Sunday attendance. 0 = legacy status marker (e.g. "migrated").'
        AFTER `service_period`;

-- The three known non-times. Listed explicitly rather than inferred by pattern:
-- guessing "does this look like a time?" would misfire on a future service
-- named something like "Sunrise Service".
UPDATE `services`
   SET `is_service_time` = 0
 WHERE `name` IN ('going to Mla', 'migrated', 'transferred to Dmgte');

ALTER TABLE `services`
    ADD INDEX IF NOT EXISTS `idx_services_service_time` (`is_service_time`, `is_active`);

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_04_service_times_flag.sql', 'services: +is_service_time; 3 legacy status markers flagged 0.');
