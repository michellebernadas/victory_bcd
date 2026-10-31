-- ============================================================================
-- Victory Bacolod — a couple row can hold TWO member links
--
-- THE PROBLEM
-- The Staff VG Schedule writes couples as one string: "Amy & Carl",
-- "Hazel Lyn and Emmanuel Gabrido". 34 of the 86 people on the 2026 sheet are
-- written that way. GroupImport stages each as ONE vg_members row with the text
-- kept verbatim in display_name, which is correct — but vg_members could only
-- ever point at ONE member, and a couple is two people. So a couple could never
-- be linked to anybody, and every one of them was stuck on "Needs Link"
-- forever, no matter how complete the 2026 member list became.
--
-- WHAT THE CHURCH ASKED FOR, EXACTLY
--   - do NOT create one member record representing a couple
--   - keep each spouse as a separate member
--   - preserve the original couple text exactly as written
--   - let an admin manually link Person 1 and Person 2
--   - do NOT auto-split the string, do NOT auto-create members
-- The sheet frequently gives only a first name for one spouse ("Amy & Carl"),
-- so splitting on "&" and guessing a shared surname would fabricate a person.
-- That is why the link is MANUAL and the text is never rewritten.
--
-- WHY A SECOND COLUMN AND NOT A `vg_member_links` JUNCTION TABLE
-- 1. The cardinality is genuinely two. A couple is two people by definition —
--    not "n people". A junction table models an open-ended set, which would
--    permit a third and fourth link that nobody could explain, and would need a
--    CHECK or application rule to stop it. Two nullable columns cannot be
--    over-filled.
-- 2. One row per sheet entry is the invariant that protects the verbatim text.
--    display_name stays exactly where it is, on exactly one row, and no join
--    can duplicate or shadow it. A junction table introduces a second place
--    where "who is this row" is recorded, and the two can drift.
-- 3. Every read in the app today is `vm.member_id` on the vg_members row —
--    roughly a dozen join sites across models/VictoryGroup.php,
--    models/Member.php, controllers/MemberController.php and the views. A
--    second nullable column leaves every one of those reads correct as written
--    for the ~60% of rows that are not couples, and needs a small, local
--    extension only where people are COUNTED. A junction table would require
--    rewriting all of them into EXISTS/UNION form in one go, which is a much
--    larger change for a schema that gains nothing it can use.
-- 4. Counting falls out naturally. "Distinct linked people" becomes a distinct
--    count over the union of the two columns, which yields exactly 2 for a
--    fully linked couple, 1 for a half-linked one, and 0 for an unlinked one —
--    the three outcomes the church asked to be able to see.
--
-- SLOT 2 IS ONLY MEANINGFUL ON A COUPLE ROW
-- is_couple marks the rows that were written as a couple. GroupImport already
-- detects this (GroupImport::isCoupleName) and records it on the staging row as
-- is_couple_name; this column carries that fact through to the imported row so
-- the Victory Groups screen knows to offer a second link slot. A non-couple row
-- keeps is_couple = 0, member_id_2 NULL, and behaves exactly as before.
--
-- NO DATA IS INVENTED. Every existing row gets member_id_2 = NULL,
-- link_status_2 = 'needs_link' and is_couple = 0. Nothing is split, nothing is
-- matched, no member is created. is_couple is then backfilled from the text
-- that is already stored — a flag ABOUT the text, never a change TO it.
--
-- Safe / idempotent: ADD COLUMN IF NOT EXISTS, ADD INDEX IF NOT EXISTS, and the
-- foreign key is guarded on information_schema because MariaDB 10.4 has no
-- ADD CONSTRAINT IF NOT EXISTS. Re-running changes nothing.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_11_vg_member_couple_links.sql
-- ============================================================================

-- ─── 1. The second member link ──────────────────────────────────────────────
ALTER TABLE `vg_members`
    ADD COLUMN IF NOT EXISTS `member_id_2` INT(11) DEFAULT NULL
        COMMENT 'Second spouse on a couple row. NULL on every non-couple row. Set only by an admin, by hand.'
        AFTER `member_id`;

ALTER TABLE `vg_members`
    ADD COLUMN IF NOT EXISTS `link_status_2`
        ENUM('linked','needs_link','not_a_member') NOT NULL DEFAULT 'needs_link'
        COMMENT 'Link state of member_id_2, mirroring link_status for member_id. Ignored unless is_couple = 1.'
        AFTER `link_status`;

ALTER TABLE `vg_members`
    ADD COLUMN IF NOT EXISTS `is_couple` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'The source text names two people ("Amy & Carl"). Set at import from GroupImport::isCoupleName(). The text itself is never split.'
        AFTER `display_name`;

-- ─── 2. Indexes matching the ones member_id already has ─────────────────────
-- getStats() counts DISTINCT people across both slots, and the Members page
-- looks a member up in either slot, so slot 2 needs the same reach slot 1 has.
ALTER TABLE `vg_members`
    ADD INDEX IF NOT EXISTS `idx_vgm_member2` (`member_id_2`);

ALTER TABLE `vg_members`
    ADD INDEX IF NOT EXISTS `idx_vgm_member2_role` (`member_id_2`, `role`);

ALTER TABLE `vg_members`
    ADD INDEX IF NOT EXISTS `idx_vgm_couple` (`is_couple`);

-- ─── 3. Same referential rule as slot 1 ─────────────────────────────────────
-- fk_vgmembers_member is ON DELETE SET NULL: deleting a member empties the link
-- and leaves the verbatim row standing as "Needs Link", rather than deleting the
-- group membership. Slot 2 must behave identically or a deleted spouse would
-- block the delete instead of releasing the link.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME        = 'vg_members'
       AND CONSTRAINT_NAME   = 'fk_vgmembers_member2'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `vg_members`
        ADD CONSTRAINT `fk_vgmembers_member2` FOREIGN KEY (`member_id_2`)
        REFERENCES `members` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ─── 4. Backfill is_couple from the text already on the row ─────────────────
-- This is the SQL twin of GroupImport::isCoupleName() — " & ", " and ", " + ",
-- or a bare "&" anywhere. It only READS display_name/name and writes a boolean;
-- no name is altered, split, or matched to anybody. Rows imported before this
-- migration therefore gain their second link slot without being re-imported.
-- Idempotent: re-running recomputes the same flag from the same text.
UPDATE `vg_members`
   SET `is_couple` = 1
 WHERE `is_couple` = 0
   AND (
        COALESCE(NULLIF(`display_name`, ''), `name`) REGEXP '[[:space:]](&|and|\\+)[[:space:]]'
     OR COALESCE(NULLIF(`display_name`, ''), `name`) LIKE '%&%'
   );

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_11_vg_member_couple_links.sql', 'vg_members gains member_id_2 / link_status_2 / is_couple so one verbatim couple row can be linked to two members by hand. No name is split and no member is created.');
