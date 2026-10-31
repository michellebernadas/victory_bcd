-- ============================================================================
-- Victory Bacolod — add the "Minister" account type
--
-- WHY
-- The Facilities, Equipment & Resource Request Policy (section 5 / Appendix A)
-- is a four-step workflow:
--     Requestor -> Facilities Review -> Minister Approval -> Facilities Confirmation
-- Step 3 belongs to the Minister. Until now only an admin could record it,
-- because there was no Minister account type (see 2026_10_20_09). The church
-- decided the Minister signs in personally, so the role gets its own value.
--
-- WHAT A MINISTER MAY DO (enforced in config/permissions.php, nowhere else)
--   * Minister Approval / Decline / For Clarification (step 3)
--   * submit requests and view the calendar, requests and history
--   * NOT the Facilities review (step 2) or confirmation (step 4)
--   * NOT any admin surface (users, settings, member import, ...) — same
--     non-facility access as 'editor'.
-- Admins keep step 3 as an override so the portal still works with no Minister
-- account; the history row records which capacity acted.
--
-- THE STORED VALUE IS 'minister'
-- Appended to the end of the enum, so existing values keep their ordinals and
-- no stored row is reinterpreted. Default stays 'editor', NOT NULL stays, no
-- COMMENT added — the column is otherwise byte-identical to
-- 2026_10_20_09_facilities_personnel_role.sql.
--
-- MariaDB 10.1 compatible. Safe / idempotent: MODIFY to an exact definition is
-- naturally re-runnable.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_12_minister_role.sql
-- ============================================================================

ALTER TABLE `accounts`
    MODIFY COLUMN `accounttype` ENUM('admin','editor','facilities','minister') NOT NULL DEFAULT 'editor';

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_12_minister_role.sql', 'accounts.accounttype gains ''minister'' (Minister) for step 3 of the facility workflow.');
