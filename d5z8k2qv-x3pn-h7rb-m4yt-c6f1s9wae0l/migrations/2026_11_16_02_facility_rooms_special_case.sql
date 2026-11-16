-- ============================================================================
-- Victory Bacolod — customisable rooms + "special case only" rooms
--
-- WHY
-- Rooms were seeded by migration with no screen to change them. Admins (and any
-- role granted "Manage rooms") can now add, edit, deactivate and delete rooms
-- from Facility Booking > Rooms.
--
-- SPECIAL-CASE ROOMS
-- Some spaces are not for ordinary bookings (the Office Lobby). A room flagged
-- special_case_only is still bookable, but the request form then REQUIRES the
-- requestor to say why this is a special case, and Facilities / the Minister see
-- that reason on the request. It is a prompt and a paper trail, not a block —
-- the same stance the policy takes on lead times.
--
--   facilities.special_case_only        1 = ask for a reason
--   facility_requests.special_case_reason  the requestor's reason
--
-- 'Office Lobby' is seeded as a special-case room. It is separate from the
-- existing 'Offices' and 'Lobby / Common Areas' rooms, which stay as they are.
--
-- Safe / idempotent: ADD COLUMN IF NOT EXISTS, INSERT IGNORE on the unique name.
-- MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_16_02_facility_rooms_special_case.sql
-- ============================================================================

ALTER TABLE `facilities`
    ADD COLUMN IF NOT EXISTS `special_case_only` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = bookable only for a special case; the request form requires a reason.';

ALTER TABLE `facility_requests`
    ADD COLUMN IF NOT EXISTS `special_case_reason` TEXT DEFAULT NULL
        COMMENT 'Why this booking is a special case (required for special_case_only rooms).';

INSERT IGNORE INTO `facilities`
    (`name`, `facility_type`, `default_lead_days`, `is_bookable`, `special_case_only`, `sort_order`, `notes`) VALUES
    ('Office Lobby', 'room', 3, 1, 1, 8, 'Special cases only — the requestor must explain why this space is needed.');

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_16_02_facility_rooms_special_case.sql', 'facilities.special_case_only, facility_requests.special_case_reason, Office Lobby room.');
