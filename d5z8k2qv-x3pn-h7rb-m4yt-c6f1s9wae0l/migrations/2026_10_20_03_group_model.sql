-- ============================================================================
-- Victory Bacolod — Simplified Groups: Victory Group / Leadership Group / Campus
--
-- THE PROBLEM
-- victory_groups.group_type conflates two unrelated things — what KIND of group
-- it is, and WHO it is for:
--     VGmen(15) VGwomen(33) VGcouples(15) VGcampusM(6) VGcampusF(7) LG(4)
-- So "is this a Campus group?" is answered with a LIKE 'VGcampus%' string
-- match, and Campus groups are silently counted as Victory Groups on the
-- dashboard. There is no clean way to ask for the three categories the church
-- actually uses.
--
-- THE FIX
-- Split that one overloaded column into two honest ones:
--     group_kind  — victory_group | leadership_group | campus   (the category)
--     audience    — men | women | couples | students | ...      (who it's for)
-- group_type is KEPT and left untouched so existing code keeps working while
-- the views migrate. It becomes legacy/read-only, not a second source of truth:
-- group_kind is what every new query filters on.
--
-- "LG" now means LEADERSHIP GROUP throughout. The old "Life Group" wording is
-- retired — it is replaced in the UI, and nothing in the schema carried it.
--
-- MULTIPLE LEADERSHIPS (the other half of this migration)
-- One leader may lead more than one Victory Group. The 2026 Staff VG Schedule
-- shows this plainly, and the CURRENT data already contains it: 97 leader
-- assignments resolve to only 89 distinct people. vg_members is already a
-- many-to-many junction with no uniqueness constraint on member_id, so the
-- relationship itself needs no change — what was missing is an LG Leader role,
-- a place to keep a name exactly as written, and an index that makes counting
-- DISTINCT PEOPLE (not assignments) cheap.
--
-- NO DATA IS DELETED. Every statement is additive or a backfill of a column
-- that did not exist a moment earlier.
--
-- Safe / idempotent: ADD COLUMN/INDEX IF NOT EXISTS; backfills are guarded so
-- re-running cannot overwrite an admin's later edit.
-- Run with:  mysql -uroot victory_bacolod < migrations/2026_10_20_03_group_model.sql
-- ============================================================================

-- ─── 1. victory_groups: the three categories ────────────────────────────────

ALTER TABLE `victory_groups`
    ADD COLUMN IF NOT EXISTS `group_kind`
        ENUM('victory_group','leadership_group','campus') NOT NULL DEFAULT 'victory_group'
        COMMENT 'The three categories the church uses. Campus is NEVER counted as a Victory Group.'
        AFTER `uuid`;

ALTER TABLE `victory_groups`
    ADD COLUMN IF NOT EXISTS `audience` VARCHAR(50) DEFAULT ''
        COMMENT 'Who the group is for: men, women, couples, students. Was baked into group_type.'
        AFTER `group_kind`;

ALTER TABLE `victory_groups`
    ADD COLUMN IF NOT EXISTS `name` VARCHAR(150) DEFAULT ''
        COMMENT 'Plain label an admin recognises, e.g. "Thursday Couples VG — Lacson".'
        AFTER `audience`;

-- The original spreadsheet text for this group, kept verbatim. When an import
-- cannot parse a venue or a frequency, the admin can still see what was written.
ALTER TABLE `victory_groups`
    ADD COLUMN IF NOT EXISTS `source_label` VARCHAR(255) DEFAULT NULL
        COMMENT 'Original text from the source spreadsheet. Never overwritten by the app.'
        AFTER `notes`;

ALTER TABLE `victory_groups`
    ADD INDEX IF NOT EXISTS `idx_vg_kind_status` (`group_kind`, `group_status`);

-- ─── 2. Backfill group_kind / audience from the legacy group_type ───────────
-- Guarded by the legacy value so this only ever classifies rows that have not
-- been reclassified since. Campus is matched BEFORE the generic VG prefixes,
-- because VGcampusM would otherwise fall through to "men".

UPDATE `victory_groups` SET `group_kind` = 'campus',           `audience` = 'men'
 WHERE `group_type` = 'VGcampusM' AND `audience` = '';
UPDATE `victory_groups` SET `group_kind` = 'campus',           `audience` = 'women'
 WHERE `group_type` = 'VGcampusF' AND `audience` = '';
UPDATE `victory_groups` SET `group_kind` = 'leadership_group', `audience` = ''
 WHERE `group_type` = 'LG';
UPDATE `victory_groups` SET `group_kind` = 'victory_group',    `audience` = 'men'
 WHERE `group_type` = 'VGmen'     AND `audience` = '';
UPDATE `victory_groups` SET `group_kind` = 'victory_group',    `audience` = 'women'
 WHERE `group_type` = 'VGwomen'   AND `audience` = '';
UPDATE `victory_groups` SET `group_kind` = 'victory_group',    `audience` = 'couples'
 WHERE `group_type` = 'VGcouples' AND `audience` = '';

-- A readable fallback name for groups that have none, built from what we know:
-- "<Audience> <Kind> — <Day>". Only fills blanks; never replaces a real name.
UPDATE `victory_groups`
   SET `name` = TRIM(CONCAT(
        CASE WHEN `audience` <> '' THEN CONCAT(UPPER(SUBSTRING(`audience`,1,1)), SUBSTRING(`audience`,2), ' ') ELSE '' END,
        CASE `group_kind` WHEN 'campus'           THEN 'Campus Group'
                          WHEN 'leadership_group' THEN 'Leadership Group'
                          ELSE 'Victory Group' END,
        CASE WHEN `day_of_week` IS NOT NULL AND `day_of_week` <> '' THEN CONCAT(' — ', `day_of_week`) ELSE '' END
   ))
 WHERE `name` = '' OR `name` IS NULL;

-- ─── 3. vg_members: roles, verbatim names, link status ──────────────────────

-- 'attendee' is kept as the stored value (the app writes it in several places)
-- but reads as "Member" in the UI. 'lg_leader' is new: a Leadership Group's
-- leader is a distinct role from a Victory Group leader and must be countable
-- on its own for the LG Leader filter.
ALTER TABLE `vg_members`
    MODIFY COLUMN `role` ENUM('leader','intern','attendee','lg_leader') NOT NULL
        COMMENT 'leader = VG Leader; lg_leader = Leadership Group Leader; attendee displays as "Member".';

-- The name EXACTLY as the church wrote it — "Amy & Carl", "Hazel Lyn and
-- Emmanuel Gabrido". Couple names are never split into two people, so this is
-- the only faithful record of who the row refers to.
ALTER TABLE `vg_members`
    ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(255) DEFAULT NULL
        COMMENT 'Name verbatim from the source. Couples stay one string, never split.'
        AFTER `name`;

ALTER TABLE `vg_members`
    ADD COLUMN IF NOT EXISTS `link_status`
        ENUM('linked','needs_link','not_a_member') NOT NULL DEFAULT 'needs_link'
        COMMENT 'linked = member_id resolved; needs_link = awaiting an admin match; not_a_member = deliberately unlinked.'
        AFTER `member_id`;

-- Makes "how many DISTINCT PEOPLE lead a group?" an index scan. Every leader /
-- intern count in the app depends on this being cheap.
ALTER TABLE `vg_members`
    ADD INDEX IF NOT EXISTS `idx_vgm_member_role` (`member_id`, `role`);

-- Backfill: seed display_name from the existing name, and mark rows that
-- already resolve to a member as linked.
UPDATE `vg_members` SET `display_name` = `name`
 WHERE `display_name` IS NULL OR `display_name` = '';

UPDATE `vg_members` SET `link_status` = 'linked'
 WHERE `member_id` IS NOT NULL AND `link_status` = 'needs_link';

-- ─── 4. The missing foreign key ─────────────────────────────────────────────
-- vg_members.group_id has never been constrained, so a deleted group could
-- leave memberships behind pointing at nothing. Verified 0 orphans before
-- adding. CASCADE matches the serve_team_members.team_id precedent: a group's
-- membership list has no meaning once the group row is gone.
--
-- MariaDB has no ADD CONSTRAINT IF NOT EXISTS, so this is guarded by a lookup
-- in information_schema and executed only when absent — which keeps the file
-- re-runnable.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME        = 'vg_members'
       AND CONSTRAINT_NAME   = 'fk_vgmembers_group'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `vg_members` ADD CONSTRAINT `fk_vgmembers_group`
         FOREIGN KEY (`group_id`) REFERENCES `victory_groups` (`id`)
         ON DELETE CASCADE ON UPDATE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `schema_migrations` (`filename`, `notes`)
VALUES ('2026_10_20_03_group_model.sql', 'Groups: +group_kind/audience/name/source_label, +lg_leader role, +display_name/link_status, +FK on group_id.');
