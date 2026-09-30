-- ============================================================================
-- Victory Bacolod — Spiritual Foundations: explicit week map + Batch 2 catch-up
--
-- 1. Backfills a `weeks` map (week_no → status) into every existing SF record.
--    Grids used to resolve by ROW POSITION, which meant a later curriculum edit
--    could silently re-point a saved status at a different topic. Storing the
--    week number alongside the dates removes that coupling and is what makes
--    curriculum Add/Edit/Archive safe.
--
-- 2. Adds the Batch 2 catch-up record for the existing member Michelle Bernadas
--    (members.id = 43 — no new member is created):
--       Weeks 1-6  = NC  (already completed in Batch 1, not required again)
--       Weeks 7-9  = P   (the topics she came back for)
--       Week  10   = NC  (already completed in Batch 1)
--    Batch 1 is left EXACTLY as it is (P P P P P P A A A P = 7/10), so her
--    overall completion becomes 10/10 from the union of both batches.
--
-- Nothing about eligibility is stored — it is recomputed per participant from
-- these rows on every request (see SfProgress).
--
-- Safe / idempotent: guarded by NOT EXISTS / JSON checks, so re-running is a
-- no-op and no existing row is overwritten twice.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_09_30_04_sf_weeks_and_batch2.sql
-- ============================================================================

-- ─── 1. Backfill the week map on Michelle's Batch 1 (10 rows → weeks 1..10) ──
UPDATE `program_attendances` pa
   SET pa.`extra_data` = JSON_MERGE_PATCH(
        pa.`extra_data`,
        '{"weeks":{"1":"P","2":"P","3":"P","4":"P","5":"P","6":"P","7":"A","8":"A","9":"A","10":"P"}}'
   )
 WHERE pa.`program_type` = 'spiritual_foundations'
   AND pa.`batch_label`  = 'Batch 1'
   AND pa.`is_deleted`   = 0
   AND JSON_LENGTH(pa.`extra_data`, '$.sessions') = 10
   AND JSON_EXTRACT(pa.`extra_data`, '$.weeks') IS NULL;


-- ─── 2. Batch 2 catch-up record ─────────────────────────────────────────────
INSERT INTO `program_attendances`
    (`member_id`, `raw_last_name`, `raw_first_name`, `full_name_display`,
     `program_type`, `program_year`, `program_label`, `batch_label`,
     `event_date`, `contact_number`, `extra_data`, `status`, `is_deleted`)
SELECT
    m.`id`, 'Bernadas', 'Michelle', 'Michelle Bernadas',
    'spiritual_foundations', 2026, 'Spiritual Foundations', 'Batch 2',
    '2026-06-07',
    COALESCE(m.`contact_number`, ''),
    CONCAT(
        '{"sessions":{',
            '"2026-06-07":"NC",',  -- Week 1  — Victory Day                     (done in Batch 1)
            '"2026-06-14":"NC",',  -- Week 2  — God and His Word                (done in Batch 1)
            '"2026-06-21":"NC",',  -- Week 3  — Creation, The Fall and Sin      (done in Batch 1)
            '"2026-06-28":"NC",',  -- Week 4  — Jesus and the Cross             (done in Batch 1)
            '"2026-07-05":"NC",',  -- Week 5  — Our Sanctification              (done in Batch 1)
            '"2026-07-12":"NC",',  -- Week 6  — Future Hope                     (done in Batch 1)
            '"2026-07-19":"P",',   -- Week 7  — Holy Spirit and Spiritual Gifts (CATCH-UP)
            '"2026-07-26":"P",',   -- Week 8  — Spiritual Disciplines           (CATCH-UP)
            '"2026-08-02":"P",',   -- Week 9  — The Church                      (CATCH-UP)
            '"2026-08-09":"NC"',   -- Week 10 — Spiritual Family                (done in Batch 1)
        '},',
        '"weeks":{"1":"NC","2":"NC","3":"NC","4":"NC","5":"NC","6":"NC","7":"P","8":"P","9":"P","10":"NC"},',
        -- attended / total_sessions describe THIS batch only; overall completion
        -- is derived across batches at read time.
        '"attended":3,"total_sessions":3}'
    ),
    'active', 0
FROM `members` m
WHERE m.`full_name` = 'Bernadas, Michelle'
  AND m.`is_deleted` = 0
  AND NOT EXISTS (
        SELECT 1 FROM `program_attendances` pa
         WHERE pa.`member_id`    = m.`id`
           AND pa.`program_type` = 'spiritual_foundations'
           AND pa.`batch_label`  = 'Batch 2'
           AND pa.`is_deleted`   = 0
  )
LIMIT 1;
