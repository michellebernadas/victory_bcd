-- ============================================================================
-- Victory Bacolod — Settings values the Staff VG Schedule import writes
--
-- THE PROBLEM
-- The VG Schedule importer saves each group's legacy group_type as 'VG' or
-- 'VGcampus' (group_kind is the real classification; group_type is only kept
-- for older views). Neither value was in Settings → VG Options, which only had
-- the pre-2026 codes (VGmen, VGcouples, VGcampusM, ...). So every imported
-- group showed a "this group type is inactive" warning, and the Edit form's
-- Group Type dropdown had no matching choice. The 2026 sheet also uses
-- "Young Adult", and its own header lists "Seniors" as a type of VG; neither
-- was a category.
--
-- THE FIX
-- Add those values to vg_options. Configuration only: no group or person row
-- is touched. INSERT IGNORE on the (option_type, value) unique key, so running
-- this twice changes nothing, and an option an admin has since renamed or
-- deactivated is left exactly as they set it.
--
-- MariaDB 10.1 compatible.
-- Run with:  mysql -u USER -p DBNAME < migrations/2026_10_20_14_vg_options_for_import.sql
-- ============================================================================

INSERT IGNORE INTO `vg_options` (`option_type`, `value`, `label`, `sort_order`, `is_active`, `is_deleted`) VALUES
    ('group_type',     'VG',          'Victory Group', 0,  1, 0),
    ('group_type',     'VGcampus',    'Campus',        6,  1, 0),
    ('group_category', 'young adult', 'Young Adult',   22, 1, 0),
    ('group_category', 'seniors',     'Seniors',       23, 1, 0);

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_14_vg_options_for_import.sql', 'vg_options gains VG / VGcampus group types and Young Adult / Seniors categories used by the 2026 VG Schedule import.');
