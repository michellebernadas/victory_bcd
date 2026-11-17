-- ============================================================================
-- Victory Bacolod — class renames
--   Victory Weekend  -> Victory Day
--   Leadership 1-1-3 -> Leadership 113
--
-- Display names only. The stored keys (victory_weekend, leadership_113) are
-- unchanged, so no record, link, permission or report loses its place.
-- Updates the discipleship step names (Settings > Discipleship) and the label
-- printed on existing class records. Only exact old values are touched, so a
-- name an admin already customised is left alone.
-- Safe / idempotent: re-running changes nothing. MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_17_03_class_renames.sql
-- ============================================================================

UPDATE `discipleship_steps` SET `name` = 'Victory Day',    `abbreviation` = 'VD'
 WHERE `column_key` = 'victory_weekend' AND `name` = 'Victory Weekend';
UPDATE `discipleship_steps` SET `name` = 'Leadership 113'
 WHERE `column_key` = 'leadership_113'  AND `name` = 'Leadership 1-1-3';

UPDATE `program_attendances` SET `program_label` = 'VICTORY DAY'
 WHERE `program_type` = 'victory_weekend' AND UPPER(`program_label`) = 'VICTORY WEEKEND';
UPDATE `program_attendances` SET `program_label` = 'LEADERSHIP 113'
 WHERE `program_type` = 'leadership_113'  AND UPPER(`program_label`) IN ('LEADERSHIP 1-1-3', 'LEADERSHIP 113');

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_17_03_class_renames.sql', 'Display names: Victory Weekend -> Victory Day, Leadership 1-1-3 -> Leadership 113.');
