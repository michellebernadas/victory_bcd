-- ============================================================================
-- Victory Bacolod — Water Baptism becomes its own class
--
-- WHY
-- Water baptism was a tick box (plus a remarks box) inside each Victory Weekend
-- (now Victory Day) record. It is its own milestone, so it now has its own class:
-- its own records, year (1950 onwards), sidebar item, permission and step on the
-- discipleship journey. Victory Day records no longer carry the tick box.
--
-- WHAT THIS DOES
--   1. members.water_baptism            cache flag, like the other class flags
--   2. discipleship_steps row           "Water Baptism" (WB), right after Victory Day
--   3. COPIES existing baptised Victory Day records into Water Baptism records
--      (same person, year, date, batch, contact; the old remarks become the note)
--   4. grants the class_water_baptism permission to every role that can already
--      open Victory Day
--
-- NOTHING IS DELETED. The old water_baptism / water_baptism_remarks columns on
-- Victory Day records are left exactly as they were (the app just stops showing
-- them), so this can be checked against the originals or undone.
--
-- AFTER RUNNING: Settings > Recalculate progress (or save any class record) so
-- members who were baptised show Water Baptism as completed on their journey.
--
-- Safe / idempotent: every step checks before it acts. MariaDB 10.1 compatible.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_11_17_05_water_baptism_class.sql
-- ============================================================================

-- 1. member flag cache ------------------------------------------------------
ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `water_baptism` TINYINT(1) NOT NULL DEFAULT 0;

-- 2. the journey step: make room after Victory Day, then add it (first run only)
UPDATE `discipleship_steps`
   SET `sort_order` = `sort_order` + 1
 WHERE `sort_order` >= 1
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM `discipleship_steps` WHERE `column_key` = 'water_baptism') x);

INSERT INTO `discipleship_steps` (`name`, `abbreviation`, `icon`, `color`, `column_key`, `is_active`, `is_deleted`, `sort_order`)
SELECT 'Water Baptism', 'WB', 'bi-droplet', 'info', 'water_baptism', 1, 0, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `discipleship_steps` WHERE `column_key` = 'water_baptism');

-- 3. copy baptised Victory Day records into Water Baptism records ------------
INSERT INTO `program_attendances`
    (`member_id`, `raw_last_name`, `raw_first_name`, `full_name_display`, `program_type`, `program_year`, `program_label`,
     `batch_label`, `notes`, `event_date`, `contact_number`, `status`, `is_deleted`)
SELECT pa.`member_id`, pa.`raw_last_name`, pa.`raw_first_name`, pa.`full_name_display`, 'water_baptism', pa.`program_year`, 'WATER BAPTISM',
       pa.`batch_label`, NULLIF(TRIM(pa.`water_baptism_remarks`), ''), pa.`event_date`, pa.`contact_number`, pa.`status`, 0
  FROM `program_attendances` pa
 WHERE pa.`program_type` = 'victory_weekend' AND pa.`water_baptism` = 1 AND pa.`is_deleted` = 0
   AND NOT EXISTS (SELECT 1 FROM `program_attendances` w
                    WHERE w.`program_type` = 'water_baptism' AND w.`is_deleted` = 0
                      AND w.`full_name_display` = pa.`full_name_display` AND w.`program_year` = pa.`program_year`
                      AND (w.`member_id` <=> pa.`member_id`));

-- 4. permission --------------------------------------------------------------
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission`)
SELECT rp.`role_id`, 'class_water_baptism' FROM `role_permissions` rp WHERE rp.`permission` = 'class_victory_weekend';

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_11_17_05_water_baptism_class.sql', 'Water Baptism is its own class: step, member flag, permission, and a copy of existing baptism data.');
