-- ============================================================================
-- Victory Bacolod — Spiritual Foundations sample data (2026-09-30)
--
-- Creates SF "Batch 1" with one participant: the EXISTING member record for
-- Michelle Bernadas (looked up by full_name — no new member is created).
--
-- 10 sessions (the program has 10 class weeks). Present on weeks 1-6 and 10,
-- absent on weeks 7, 8 and 9, so completion is 7/10 and certificate
-- eligibility evaluates to FALSE.
-- Eligibility itself is NEVER stored — it is recomputed from these session
-- values for every participant (see ProgramAttendance::certificateStatus).
--
-- Idempotent: skipped entirely if an SF Batch 1 row for this member exists.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_09_30_02_sf_sample_data.sql
-- ============================================================================

INSERT INTO `program_attendances`
    (`member_id`, `raw_last_name`, `raw_first_name`, `full_name_display`,
     `program_type`, `program_year`, `program_label`, `batch_label`,
     `event_date`, `contact_number`, `extra_data`, `status`, `is_deleted`)
SELECT
    m.`id`,
    'Bernadas',
    'Michelle',
    'Michelle Bernadas',
    'spiritual_foundations',
    2026,
    'Spiritual Foundations',
    'Batch 1',
    '2026-01-11',
    COALESCE(m.`contact_number`, ''),
    CONCAT(
        '{"sessions":{',
            '"2026-01-11":"P",',   -- Week 1  — Victory Day
            '"2026-01-18":"P",',   -- Week 2  — God and His Word
            '"2026-01-25":"P",',   -- Week 3  — Creation, The Fall and Sin
            '"2026-02-01":"P",',   -- Week 4  — Jesus and the Cross
            '"2026-02-08":"P",',   -- Week 5  — Our Sanctification
            '"2026-02-15":"P",',   -- Week 6  — Future Hope
            '"2026-02-22":"A",',   -- Week 7  — Holy Spirit and Spiritual Gifts
            '"2026-03-01":"A",',   -- Week 8  — Spiritual Disciplines
            '"2026-03-08":"A",',   -- Week 9  — The Church
            '"2026-03-15":"P"',    -- Week 10 — Spiritual Family
        '},"attended":7,"total_sessions":10}'
    ),
    'active',
    0
FROM `members` m
WHERE m.`full_name` = 'Bernadas, Michelle'
  AND m.`is_deleted` = 0
  AND NOT EXISTS (
        SELECT 1 FROM `program_attendances` pa
         WHERE pa.`member_id`    = m.`id`
           AND pa.`program_type` = 'spiritual_foundations'
           AND pa.`batch_label`  = 'Batch 1'
           AND pa.`is_deleted`   = 0
  )
LIMIT 1;

-- Keep the members.spiritual_foundations flag + junction row in sync with the
-- record above (same rule ProgramAttendance::syncMemberFlag applies at runtime:
-- "has at least one active record for this class", NOT "certificate eligible").
UPDATE `members` m
   SET m.`spiritual_foundations` = 1
 WHERE m.`is_deleted` = 0
   AND EXISTS (
        SELECT 1 FROM `program_attendances` pa
         WHERE pa.`member_id`    = m.`id`
           AND pa.`program_type` = 'spiritual_foundations'
           AND pa.`is_deleted`   = 0
           AND pa.`status`       = 'active'
   );

INSERT IGNORE INTO `member_discipleship` (`member_id`, `step_id`)
SELECT m.`id`, ds.`id`
  FROM `members` m
  JOIN `discipleship_steps` ds ON ds.`column_key` = 'spiritual_foundations'
 WHERE m.`spiritual_foundations` = 1
   AND m.`is_deleted` = 0;
