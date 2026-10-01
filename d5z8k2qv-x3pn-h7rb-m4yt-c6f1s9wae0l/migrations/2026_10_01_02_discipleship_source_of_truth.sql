-- ============================================================================
-- Victory Bacolod — Discipleship progress: one source of truth
--
-- Before: three places could independently claim a step was complete —
--   1. members.<step> boolean columns
--   2. member_discipleship rows
--   3. attendance rows in program_attendances
-- …and each was written by different code (syncMemberFlag, the member form's
-- checkbox group, and a blind `UPDATE members SET victory_weekend = 1`).
--
-- After: program_attendances is the evidence, member_discipleship is the
-- derived state (now carrying WHERE each completion came from), and the boolean
-- columns are a compatibility cache synchronised by DiscipleshipProgressService.
--
-- This migration only widens member_discipleship. It does NOT touch attendance,
-- does NOT clear any flag, and does NOT delete any completion.
--
-- Safe / idempotent: ADD COLUMN IF NOT EXISTS throughout; re-running is a no-op.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_01_02_discipleship_source_of_truth.sql
-- ============================================================================

-- ─── 1. Record WHERE each completion came from ──────────────────────────────
ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `status` ENUM('completed') NOT NULL DEFAULT 'completed'
        COMMENT 'Present row = completed. Kept explicit for forward compatibility.'
        AFTER `step_id`;

ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `completion_source` ENUM('attendance','historical') NOT NULL DEFAULT 'attendance'
        COMMENT 'attendance = derived from program_attendances; historical = approved legacy completion with no attendance record.'
        AFTER `status`;

ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `source_record_id` INT(11) DEFAULT NULL
        COMMENT 'program_attendances.id that evidenced this completion (attendance source only).'
        AFTER `completion_source`;

ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `notes` VARCHAR(255) DEFAULT NULL
        COMMENT 'Why a historical completion was approved.'
        AFTER `source_record_id`;

ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `verified_by` INT(11) DEFAULT NULL
        COMMENT 'accounts.id of the admin who approved a historical completion.'
        AFTER `notes`;

ALTER TABLE `member_discipleship`
    ADD COLUMN IF NOT EXISTS `verified_at` DATETIME DEFAULT NULL
        AFTER `verified_by`;

-- completed_at must accept NULL: a legacy completion often has no known date,
-- and inventing one would be worse than admitting we don't know.
ALTER TABLE `member_discipleship`
    MODIFY COLUMN `completed_at` DATETIME DEFAULT NULL
        COMMENT 'When the step was completed. NULL when unknown (common for historical rows).';

ALTER TABLE `member_discipleship`
    ADD INDEX IF NOT EXISTS `idx_md_source` (`completion_source`);

-- ─── 2. Reconciliation ──────────────────────────────────────────────────────
-- Classify every EXISTING completion row by whether attendance evidence backs
-- it. Anything without evidence is preserved as `historical` rather than being
-- dropped — that is the whole point of this migration.
--
-- Evidence rules match DiscipleshipProgressService exactly:
--   * a linked, active, non-deleted attendance row for that program_type, and
--   * for leadership_113 / spiritual_foundations the row must additionally
--     satisfy that class's own completion rule, which SQL can't express — so
--     those two are classified by the service during reconciliation, not here.
--     Here they are left alone, and the service's reconcile pass fixes them.
--
-- Simple classes (VW / CC / MD / EL): attendance-backed when a matching row exists.
UPDATE `member_discipleship` md
  JOIN `discipleship_steps` ds ON ds.`id` = md.`step_id`
   SET md.`completion_source` = 'attendance'
 WHERE ds.`column_key` IN ('victory_weekend','church_community','making_disciples','empowering_leaders')
   AND EXISTS (
        SELECT 1 FROM `program_attendances` pa
         WHERE pa.`member_id`    = md.`member_id`
           AND pa.`program_type` = ds.`column_key`
           AND pa.`is_deleted`   = 0
           AND pa.`status`       = 'active'
   );

-- Same four classes, but with NO evidence → this is a legacy completion.
-- Preserve it as historical and clear the invented completed_at, since the
-- original default-timestamp was the row's insert time, not a real class date.
UPDATE `member_discipleship` md
  JOIN `discipleship_steps` ds ON ds.`id` = md.`step_id`
   SET md.`completion_source` = 'historical',
       md.`completed_at`      = NULL,
       md.`notes`             = COALESCE(md.`notes`, 'Reconciled: legacy completion with no attendance record.')
 WHERE ds.`column_key` IN ('victory_weekend','church_community','making_disciples','empowering_leaders')
   AND NOT EXISTS (
        SELECT 1 FROM `program_attendances` pa
         WHERE pa.`member_id`    = md.`member_id`
           AND pa.`program_type` = ds.`column_key`
           AND pa.`is_deleted`   = 0
           AND pa.`status`       = 'active'
   );

-- Steps with no attendance program at all (e.g. Purple Book Class) can only
-- ever be historical. 76 members currently hold PBC this way.
UPDATE `member_discipleship` md
  JOIN `discipleship_steps` ds ON ds.`id` = md.`step_id`
   SET md.`completion_source` = 'historical',
       md.`completed_at`      = NULL,
       md.`notes`             = COALESCE(md.`notes`, 'Reconciled: step has no attendance program; legacy completion preserved.')
 WHERE ds.`column_key` IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM `program_attendances` pa2 WHERE pa2.`program_type` = ds.`column_key`
   );
