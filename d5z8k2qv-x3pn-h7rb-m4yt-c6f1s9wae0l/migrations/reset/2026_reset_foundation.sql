-- ============================================================================
-- VICTORY BACOLOD — 2026 FOUNDATION RESET
--
--   ####################################################################
--   #                                                                  #
--   #   DESTRUCTIVE. DO NOT RUN THIS AGAINST PRODUCTION WITHOUT A      #
--   #   VERIFIED SQL BACKUP AND EXPLICIT APPROVAL FROM THE PROJECT     #
--   #   OWNER AND CHURCH LEADERSHIP.                                   #
--   #                                                                  #
--   #   Run `reset_2026.sh --dry-run` FIRST. It takes the backup,      #
--   #   reports exactly what would be deleted, and changes nothing.    #
--   #                                                                  #
--   ####################################################################
--
-- WHAT THIS DOES
-- Empties the 2023 operational data so the church can encode their current
-- 2026 membership and discipleship information into a clean portal.
--
-- WHAT THIS DOES NOT DO
--   * Does NOT drop a single table. Every table survives, empty.
--   * Does NOT touch admin logins, ministries, services, discipleship step
--     definitions, the Spiritual Foundations curriculum, serve/VG dropdown
--     options, or the migration ledger.
--   * Does NOT disable foreign key checks. The delete order below is chosen
--     so it never has to — see the note on ON DELETE SET NULL.
--
-- WHY THE ORDER MATTERS (this is the dangerous part)
-- Three foreign keys use ON DELETE SET NULL:
--     program_attendances.member_id -> members.id
--     vg_members.member_id          -> members.id
--     serve_team_members.member_id  -> members.id
-- If `members` were cleared FIRST, those three tables would not error — they
-- would quietly have every member_id blanked, destroying the link between a
-- person and their records before those records were themselves removed. Any
-- partial failure after that point would leave unrecoverable orphans in a
-- database that still looked structurally fine.
--
-- So: children first, parents last. Nothing is ever orphaned mid-run.
--
--   1. historical_completion_reviews  (references member_discipleship.id)
--   2. member_discipleship            (references members.id, no FK)
--   3. program_attendances            (FK -> members)
--   4. vg_members                     (FK -> members, FK -> victory_groups)
--   5. victory_groups
--   6. serve_team_members             (FK -> members, FK -> serve_teams)
--   7. serve_teams
--   8. members                        (last — everything pointing at it is gone)
--
-- WHY DELETE AND NOT TRUNCATE
-- TRUNCATE is refused by InnoDB on a table with inbound foreign keys, and it
-- is not transactional — a half-finished TRUNCATE cannot be rolled back.
-- DELETE lets the entire reset run inside ONE transaction. If any statement
-- fails, the whole thing rolls back and the database is untouched.
-- AUTO_INCREMENT is reset explicitly afterwards (DELETE does not reset it),
-- so the church's first 2026 member is id 1.
--
-- Run only via:  reset_2026.sh      (which enforces the backup)
-- ============================================================================

-- Fail loudly rather than silently truncating or coercing bad data.
SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_ALL_TABLES');

START TRANSACTION;

-- ─── Record the reset itself, so it is auditable afterwards ─────────────────
-- Written INSIDE the transaction: if the reset rolls back, so does this row.
INSERT INTO `app_reconciliations` (`name`, `notes`, `ran_at`)
SELECT '2026_foundation_reset',
       CONCAT('Cleared 2026 foundation reset. Rows removed — members: ',
              (SELECT COUNT(*) FROM `members`),
              ', program_attendances: ',    (SELECT COUNT(*) FROM `program_attendances`),
              ', member_discipleship: ',    (SELECT COUNT(*) FROM `member_discipleship`),
              ', vg_members: ',             (SELECT COUNT(*) FROM `vg_members`),
              ', victory_groups: ',         (SELECT COUNT(*) FROM `victory_groups`),
              ', serve_team_members: ',     (SELECT COUNT(*) FROM `serve_team_members`),
              ', serve_teams: ',            (SELECT COUNT(*) FROM `serve_teams`),
              ', historical_completion_reviews: ',
                                            (SELECT COUNT(*) FROM `historical_completion_reviews`),
              '.'),
       NOW()
  FROM DUAL
 WHERE NOT EXISTS (
        SELECT 1 FROM `app_reconciliations` WHERE `name` = '2026_foundation_reset'
 );

-- ─── 1. Discipleship review audit trail ─────────────────────────────────────
-- Points at member_discipleship.id with no FK, so it must go first or it
-- becomes a log about rows that no longer exist.
DELETE FROM `historical_completion_reviews`;

-- ─── 2. Derived discipleship state ──────────────────────────────────────────
-- Rebuilt from evidence by DiscipleshipProgressService. Empty is correct here:
-- with no attendance records there are no completions to derive.
DELETE FROM `member_discipleship`;

-- ─── 3. Class attendance evidence ───────────────────────────────────────────
-- The 2023-2025 class records (VW / CC / MD / EL / L113 / SF).
DELETE FROM `program_attendances`;

-- ─── 4-5. Victory Group / Leadership Group / Campus memberships and groups ──
-- vg_members before victory_groups: the FK added in migration 03 is CASCADE,
-- but deleting children explicitly keeps the row counts honest and the
-- intent obvious.
DELETE FROM `vg_members`;
DELETE FROM `victory_groups`;

-- ─── 6-7. Serve teams ───────────────────────────────────────────────────────
-- The Serve Teams MODULE stays; only its 2023 roster is cleared.
DELETE FROM `serve_team_members`;
DELETE FROM `serve_teams`;

-- ─── 7b. Sunday service + facility operational records ──────────────────────
-- Normally empty at first cutover (these tables are new), but cleared anyway so
-- the reset is re-runnable after a trial import and genuinely leaves NO old
-- operational data behind. Order matters: history and staging rows reference
-- facility_requests, so they go first.
DELETE FROM `service_attendances`;
DELETE FROM `facility_request_history`;
DELETE FROM `facility_requests`;

-- Import staging — scratch space from any trial run. Never church config.
DELETE FROM `facility_request_imports`;
-- group_import_cards holds one row per group card on the staged VG schedule;
-- group_import_staging holds the people on those cards. Cards are deleted
-- first so the child rows never outlive their parent.
DELETE FROM `group_import_staging`;
DELETE FROM `group_import_cards`;
DELETE FROM `member_imports`;

-- ─── 8. Members ─────────────────────────────────────────────────────────────
-- Last. Everything that referenced a member has already gone, so no
-- ON DELETE SET NULL can fire against a row we still care about.
DELETE FROM `members`;

-- ─── Reset the counters ─────────────────────────────────────────────────────
-- DELETE leaves AUTO_INCREMENT where it was; without this the first 2026
-- member would be id 925. These are DDL and therefore implicitly commit on
-- MariaDB, so they are deliberately placed AFTER all the DELETEs — by this
-- point every destructive statement has already succeeded.
ALTER TABLE `historical_completion_reviews` AUTO_INCREMENT = 1;
ALTER TABLE `member_discipleship`           AUTO_INCREMENT = 1;
ALTER TABLE `program_attendances`           AUTO_INCREMENT = 1;
ALTER TABLE `vg_members`                    AUTO_INCREMENT = 1;
ALTER TABLE `victory_groups`                AUTO_INCREMENT = 1;
ALTER TABLE `serve_team_members`            AUTO_INCREMENT = 1;
ALTER TABLE `serve_teams`                   AUTO_INCREMENT = 1;
ALTER TABLE `members`                       AUTO_INCREMENT = 1;
ALTER TABLE `service_attendances`           AUTO_INCREMENT = 1;
ALTER TABLE `facility_request_history`      AUTO_INCREMENT = 1;
ALTER TABLE `facility_requests`             AUTO_INCREMENT = 1;
ALTER TABLE `facility_request_imports`      AUTO_INCREMENT = 1;
ALTER TABLE `group_import_staging`          AUTO_INCREMENT = 1;
ALTER TABLE `group_import_cards`            AUTO_INCREMENT = 1;
ALTER TABLE `member_imports`                AUTO_INCREMENT = 1;

COMMIT;

-- ─── Verification ───────────────────────────────────────────────────────────
-- Every "cleared" row must read 0 and every "kept" row must be non-zero.
-- If any line below disagrees, STOP and restore from the backup.
SELECT 'CLEARED — all must be 0' AS check_group;
SELECT 'members'                       AS tbl, COUNT(*) AS rows_remaining FROM `members`
UNION ALL SELECT 'member_discipleship',            COUNT(*) FROM `member_discipleship`
UNION ALL SELECT 'program_attendances',            COUNT(*) FROM `program_attendances`
UNION ALL SELECT 'vg_members',                     COUNT(*) FROM `vg_members`
UNION ALL SELECT 'victory_groups',                 COUNT(*) FROM `victory_groups`
UNION ALL SELECT 'serve_team_members',             COUNT(*) FROM `serve_team_members`
UNION ALL SELECT 'serve_teams',                    COUNT(*) FROM `serve_teams`
UNION ALL SELECT 'historical_completion_reviews',  COUNT(*) FROM `historical_completion_reviews`
UNION ALL SELECT 'service_attendances',            COUNT(*) FROM `service_attendances`
UNION ALL SELECT 'facility_requests',              COUNT(*) FROM `facility_requests`
UNION ALL SELECT 'facility_request_history',       COUNT(*) FROM `facility_request_history`
UNION ALL SELECT 'member_imports',                 COUNT(*) FROM `member_imports`
UNION ALL SELECT 'group_import_staging',           COUNT(*) FROM `group_import_staging`
UNION ALL SELECT 'group_import_cards',             COUNT(*) FROM `group_import_cards`
UNION ALL SELECT 'facility_request_imports',       COUNT(*) FROM `facility_request_imports`;

SELECT 'KEPT — none may be 0' AS check_group;
SELECT 'accounts'             AS tbl, COUNT(*) AS rows_kept FROM `accounts`
UNION ALL SELECT 'discipleship_steps',   COUNT(*) FROM `discipleship_steps`
UNION ALL SELECT 'ministries',           COUNT(*) FROM `ministries`
UNION ALL SELECT 'services',             COUNT(*) FROM `services`
UNION ALL SELECT 'sf_topics',            COUNT(*) FROM `sf_topics`
UNION ALL SELECT 'serve_options',        COUNT(*) FROM `serve_options`
UNION ALL SELECT 'vg_options',           COUNT(*) FROM `vg_options`
UNION ALL SELECT 'vgl_stat_definitions', COUNT(*) FROM `vgl_stat_definitions`
UNION ALL SELECT 'facilities',           COUNT(*) FROM `facilities`
UNION ALL SELECT 'schema_migrations',    COUNT(*) FROM `schema_migrations`
UNION ALL SELECT 'app_reconciliations',  COUNT(*) FROM `app_reconciliations`;
