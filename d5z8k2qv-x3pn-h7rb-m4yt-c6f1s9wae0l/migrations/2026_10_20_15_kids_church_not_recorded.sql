-- ============================================================================
-- Victory Bacolod — Sunday attendance: "Kids Church not recorded" is not 0
--
-- THE PROBLEM
-- The church's Worship Service Annual Attendance sheet has Sundays where the
-- Sunday Service count was taken but the Kids Church count was not (July,
-- August, September and October 2026). With only a NOT NULL kids column the
-- one way to store those services was "0 kids" — which claims nobody came and
-- drags every Kids Church average down.
--
-- WHAT THE COLUMNS MEAN (labels changed in the app, columns did not)
--   adults -> shown everywhere as "Sunday Service": everyone in the main
--             service listening to the sermon, not adults only.
--   kids   -> shown everywhere as "Kids Church".
-- The columns keep their names on purpose: grand_total is a generated column
-- that references both, and changing a column a generated column depends on
-- is the riskiest ALTER available (on the dev MariaDB 10.4 it crashed the
-- server mid-ALTER). Relabelling is done in PHP and the views instead.
--
-- THE FIX
-- A plain flag column. kids_recorded = 0 means "Kids Church was not counted
-- at this service": kids is stored as 0 only because the column is NOT NULL,
-- and every Kids Church total/average in the app filters on kids_recorded = 1
-- so the missing count is ignored rather than averaged in as 0.
-- No existing column is changed and grand_total is not touched. Existing rows
-- default to 1 (recorded), which is what they were.
--
-- Safe / idempotent: ADD COLUMN IF NOT EXISTS. No data rows change.
-- MariaDB 10.1 compatible; plain SQL for phpMyAdmin's SQL tab.
-- ============================================================================

ALTER TABLE `service_attendances`
    ADD COLUMN IF NOT EXISTS `kids_recorded` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = Kids Church was counted (kids holds it). 0 = not recorded; ignore kids.'
        AFTER `kids`;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_15_kids_church_not_recorded.sql', 'service_attendances: +kids_recorded flag (0 = Kids Church not recorded for that service). Labels: adults = Sunday Service, kids = Kids Church.');
