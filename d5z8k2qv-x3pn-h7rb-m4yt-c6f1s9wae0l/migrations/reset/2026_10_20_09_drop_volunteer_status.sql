-- ============================================================================
-- Victory Bacolod — remove members.volunteer_status
--
--   ####################################################################
--   #  DESTRUCTIVE — DROPS A COLUMN. DO NOT RUN YET.                   #
--   #                                                                  #
--   #  RUN THIS ONLY AFTER the code that reads volunteer_status has    #
--   #  been removed and deployed. Running it early will FATAL the      #
--   #  Members page and the Dashboard.                                 #
--   #                                                                  #
--   #  It lives in migrations/reset/ rather than migrations/ precisely #
--   #  so it is not picked up by a "run everything in order" sweep.    #
--   ####################################################################
--
-- WHY THIS IS SEQUENCED LAST
-- volunteer_status is read in roughly twenty places. The unguarded query at
-- views/dashboard.php (the "Volunteers & Their Discipleship Journey" panel)
-- SELECTs the column directly with no try/catch around the failure path, so
-- dropping it before that code goes would break the home page for every admin.
--
-- Order of operations:
--   1. Remove the Volunteer Status field from Member add/edit  (code)
--   2. Remove it from the Members filters, table column and charts  (code)
--   3. Remove it from the Member Profile  (code)
--   4. Delete the Dashboard volunteer panel + its query  (code)
--   5. Deploy and verify the portal works end to end
--   6. THEN run this migration
--
-- WHAT IS LOST
-- The column currently holds: ACTIVE (126), NEW (35), INACTIVE (22),
-- FOR AUDITION (4) — and blank for 272 of 459 members. It was never reliably
-- maintained, which is part of why the church asked for it to go. Serve Team
-- membership is now the real answer to "who serves here".
--
-- RECOVERY
-- The pre-reset backup contains this column and all its values. If the church
-- later wants the data back, it is in that dump.
--
-- NOT idempotent by itself — guarded below so a second run is a safe no-op.
-- Run with:  mysql -uroot victory_bacolod < migrations/reset/2026_10_20_09_drop_volunteer_status.sql
-- ============================================================================

-- Guarded drop: MariaDB supports DROP COLUMN IF EXISTS, but the explicit
-- lookup also documents the intent and keeps the output readable.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'members'
       AND COLUMN_NAME  = 'volunteer_status'
);
SET @sql := IF(@col_exists > 0,
    'ALTER TABLE `members` DROP COLUMN `volunteer_status`',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members'
        AND COLUMN_NAME = 'volunteer_status') = 0,
    'OK — members.volunteer_status is gone.',
    'FAILED — the column is still present.'
) AS result;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_09_drop_volunteer_status.sql', 'Dropped members.volunteer_status after code removal.');
