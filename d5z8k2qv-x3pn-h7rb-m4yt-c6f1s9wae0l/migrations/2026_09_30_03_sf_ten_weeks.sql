-- ============================================================================
-- Victory Bacolod — Spiritual Foundations correction: 12 weeks → 10 weeks
--
-- The first cut split "Our Sanctification" and "Spiritual Family" into
-- Part 1 / Part 2 to reach 12 weeks. Confirmed structure is 10 class weeks with
-- those topics whole, so this migration merges the splits back, renumbers the
-- weeks 1..10, and rewrites the sample attendance from 9/12 to 7/10.
--
-- Safe / idempotent:
--   * No table is dropped or recreated; no member row is touched.
--   * Topic rows are matched by NAME, so re-running lands on the same result.
--   * week_no is blanked before renumbering because of UNIQUE KEY uq_sf_week —
--     MySQL/MariaDB allows many NULLs in a unique index, so there is no window
--     where two rows collide on the same week number.
--   * Michelle's grid is only rewritten while it still holds 12 sessions, so a
--     re-run (or a later manual edit by an admin) is never clobbered.
--
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_09_30_03_sf_ten_weeks.sql
-- ============================================================================

-- ─── 1. Merge the artificial Part 1 / Part 2 splits ─────────────────────────
-- Fold the Part 2 subtopics into the Part 1 row, then drop the Part 2 row.
UPDATE `sf_topics`
   SET `topic`     = 'Our Sanctification',
       `subtopics` = CONCAT('Christlikeness', CHAR(10), 'Identity', CHAR(10), 'Lordship', CHAR(10), 'Restoration')
 WHERE `topic` IN ('Our Sanctification (Part 1)', 'Our Sanctification');

UPDATE `sf_topics`
   SET `topic`     = 'Spiritual Family',
       `subtopics` = CONCAT('Purpose', CHAR(10), 'Pastors', CHAR(10), 'Provision', CHAR(10),
                            'Participation', CHAR(10), 'Statement of Faith', CHAR(10), 'Membership Commitment')
 WHERE `topic` IN ('Spiritual Family (Part 1)', 'Spiritual Family');

-- The Part 2 rows no longer carry anything the Part 1 rows don't.
DELETE FROM `sf_topics` WHERE `topic` IN ('Our Sanctification (Part 2)', 'Spiritual Family (Part 2)');

-- Guard against an earlier partial run leaving two rows with the same topic.
DELETE t FROM `sf_topics` t
  JOIN `sf_topics` keep
    ON keep.`topic` = t.`topic` AND keep.`id` < t.`id`;


-- ─── 2. Renumber to exactly 10 class weeks ──────────────────────────────────
-- Blank every week_no first so the UNIQUE index can't collide mid-renumber.
UPDATE `sf_topics` SET `week_no` = NULL;

UPDATE `sf_topics` SET `week_no` =  1, `sort_order` =  1, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Victory Day';
UPDATE `sf_topics` SET `week_no` =  2, `sort_order` =  2, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'God and His Word';
UPDATE `sf_topics` SET `week_no` =  3, `sort_order` =  4, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Creation, The Fall and Sin';
UPDATE `sf_topics` SET `week_no` =  4, `sort_order` =  5, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Jesus and the Cross';
UPDATE `sf_topics` SET `week_no` =  5, `sort_order` =  6, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Our Sanctification';
UPDATE `sf_topics` SET `week_no` =  6, `sort_order` =  7, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Future Hope';
UPDATE `sf_topics` SET `week_no` =  7, `sort_order` =  8, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Holy Spirit and Spiritual Gifts';
UPDATE `sf_topics` SET `week_no` =  8, `sort_order` =  9, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Spiritual Disciplines';
UPDATE `sf_topics` SET `week_no` =  9, `sort_order` = 10, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'The Church';
UPDATE `sf_topics` SET `week_no` = 10, `sort_order` = 11, `is_class` = 1, `is_required` = 1, `is_active` = 1, `is_deleted` = 0 WHERE `topic` = 'Spiritual Family';

-- Re-assert every subtopic list against its correct parent topic.
UPDATE `sf_topics` SET `subtopics` = 'See Victory Day Program file' WHERE `topic` = 'Victory Day';
UPDATE `sf_topics` SET `subtopics` = CONCAT("God's Word", CHAR(10), "Attributes of God's Greatness", CHAR(10), "Attributes of God's Goodness", CHAR(10), 'The Trinity') WHERE `topic` = 'God and His Word';
UPDATE `sf_topics` SET `subtopics` = CONCAT('Creation', CHAR(10), 'Man', CHAR(10), 'The Fall and Sin', CHAR(10), 'The Spiritual War') WHERE `topic` = 'Creation, The Fall and Sin';
UPDATE `sf_topics` SET `subtopics` = CONCAT('The Person of Jesus Christ', CHAR(10), 'The Work of Jesus Christ', CHAR(10), 'Atoning Work of Christ', CHAR(10), 'Justification and Adoption') WHERE `topic` = 'Jesus and the Cross';
UPDATE `sf_topics` SET `subtopics` = CONCAT("Christ's Glorious Return", CHAR(10), 'Eternal Judgment', CHAR(10), 'New Creation', CHAR(10), 'Life in Light of Eternity') WHERE `topic` = 'Future Hope';
UPDATE `sf_topics` SET `subtopics` = CONCAT('The Holy Spirit', CHAR(10), 'Introduction to Spiritual Gifts', CHAR(10), 'Spiritual Gifts', CHAR(10), 'Practicing Spiritual Gifts') WHERE `topic` = 'Holy Spirit and Spiritual Gifts';
UPDATE `sf_topics` SET `subtopics` = CONCAT('Introduction to Spiritual Disciplines', CHAR(10), 'Word and Worship', CHAR(10), 'Prayer and Fasting', CHAR(10), 'Service and Giving') WHERE `topic` = 'Spiritual Disciplines';
UPDATE `sf_topics` SET `subtopics` = CONCAT('What is the Church?', CHAR(10), 'Metaphors of the Church', CHAR(10), 'The Sacrament', CHAR(10), "God's Mission") WHERE `topic` = 'The Church';


-- ─── 3. The break row is "No Class" (NC) and is never counted ───────────────
UPDATE `sf_topics`
   SET `topic`       = 'No Class',
       `week_no`     = NULL,   -- not a class week, so it holds no week number
       `sort_order`  = 3,      -- sits between Week 2 and Week 3 in the printed plan
       `is_class`    = 0,
       `is_required` = 0,
       `subtopics`   = NULL
 WHERE `topic` IN ('NO CLASS', 'No Class', 'NC');

-- Insert it if this DB never had the break row.
INSERT INTO `sf_topics` (`week_no`, `sort_order`, `topic`, `subtopics`, `is_class`, `is_required`)
SELECT * FROM (SELECT NULL AS w, 3 AS s, 'No Class' AS t, NULL AS sub, 0 AS c, 0 AS r) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `sf_topics` WHERE `is_class` = 0);


-- ─── 4. Sample attendance: 9/12 → 7/10 ──────────────────────────────────────
-- Michelle Bernadas (members.id = 43), SF Batch 1. Present weeks 1-6 and 10,
-- absent weeks 7, 8 and 9 → 7 of 10 → certificate NOT eligible.
-- Eligibility itself is never stored; it is recomputed from this grid on every
-- render (ProgramAttendance::certificateStatus), for every participant.
UPDATE `program_attendances` pa
  JOIN `members` m ON m.`id` = pa.`member_id`
   SET pa.`extra_data` = CONCAT(
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
    )
 WHERE pa.`program_type` = 'spiritual_foundations'
   AND pa.`batch_label`  = 'Batch 1'
   AND pa.`is_deleted`   = 0
   AND m.`full_name`     = 'Bernadas, Michelle'
   -- Only rewrite the original 12-week seed; leaves a corrected or
   -- hand-edited grid untouched, which is what makes a re-run a no-op.
   AND JSON_LENGTH(pa.`extra_data`, '$.sessions') = 12;
