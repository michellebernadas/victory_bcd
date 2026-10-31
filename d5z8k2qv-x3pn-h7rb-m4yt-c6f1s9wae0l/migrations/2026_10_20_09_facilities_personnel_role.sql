-- ============================================================================
-- Victory Bacolod — add the "Facilities Personnel" account type
--
-- WHY
-- Phase G gave the portal the four-step facility workflow from the Facilities,
-- Equipment & Resource Request Policy:
--     Requestor -> Facilities Review -> Minister Approval -> Facilities Confirmation
-- Steps 2 and 4 belong to Facilities Personnel; step 3 belongs to the Minister.
-- The portal only had two account types ('admin', 'editor'), so the only way to
-- let someone run the Facilities review was to make them an admin — which also
-- handed them the Minister's approval button, user management, member import
-- and every settings tab. That collapses the separation the policy depends on:
-- section 5 step 2 and 6.2 are explicit that Facilities Personnel assess
-- logistics and do NOT give ministry approval.
--
-- THE STORED VALUE IS 'facilities'
-- Short, lowercase, matches the existing enum style ('admin', 'editor'), and is
-- the literal the application compares against in exactly one place —
-- config/permissions.php, ROLE_FACILITIES. The human label
-- "Facilities Personnel" lives in the UI only (views/users.php); it is never
-- stored. If this value is ever renamed, change the constant and this column
-- together.
--
-- WHAT THIS DOES NOT DO
-- No existing row changes type: 'admin' and 'editor' keep their meaning and
-- their position in the enum, the default stays 'editor', the column stays
-- NOT NULL, and no COMMENT is introduced where there was none. Widening an
-- ENUM by appending a value is a metadata-only change on MariaDB/MySQL and
-- cannot reinterpret stored values, because the existing members keep their
-- original ordinals.
--
-- Safe / idempotent: a MODIFY to this exact definition is naturally re-runnable
-- — running it twice leaves the column byte-identical.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_09_facilities_personnel_role.sql
-- ============================================================================

ALTER TABLE `accounts`
    MODIFY COLUMN `accounttype` ENUM('admin','editor','facilities') NOT NULL DEFAULT 'editor';

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_09_facilities_personnel_role.sql', 'accounts.accounttype gains ''facilities'' (Facilities Personnel) for the Phase G workflow.');
