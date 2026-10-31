# 2026 Foundation Reset — Procedure

Giving the church a clean 2026 portal: the old 2023 operational data is
cleared, every admin login and every configuration setting is kept, and the
old data is retained as an external SQL backup in case leadership ever needs
to look something up.

> **Nothing here runs by itself.** The reset is a deliberate, confirmed,
> one-off action. Read this page to the end before starting.

---

## What gets cleared, and what stays

### Cleared — the 2023 operational data

| Table | Rows (as audited) | What it holds |
|---|---:|---|
| `historical_completion_reviews` | 3 | Review history for legacy completions |
| `member_discipleship` | 492 | Derived completion state |
| `program_attendances` | 473 | Class attendance evidence (VW/CC/MD/EL/L113/SF) |
| `vg_members` | 646 | Victory / Leadership Group memberships |
| `victory_groups` | 80 | The groups themselves |
| `serve_team_members` | 6 | Serve Team rosters |
| `serve_teams` | 1 | Serve Teams |
| `members` | 459 | The member roster |

### Kept — logins, configuration and reference data

| Table | Rows | Why it stays |
|---|---:|---|
| `accounts` | 1 | **Admin logins.** Clearing this locks everyone out. |
| `discipleship_steps` | 7 | VW / CC / MD / EL / L113 / PBC / SF definitions |
| `ministries` | 8 | ADMIN, KIDS, MUSIC, PRAYER, TSM, USHERING, PASTOR, No Ministry |
| `services` | 7 | Service times and legacy status markers |
| `sf_topics` | 11 | The 10-week Spiritual Foundations curriculum — teaching content, not member data |
| `serve_options` | 11 | Serve Team dropdown values |
| `vg_options` | 49 | Group dropdown values |
| `vgl_stat_definitions` | 28 | Stats configuration |
| `facilities` | 7 | The bookable rooms |
| `schema_migrations` | — | Which migrations have been applied |
| `app_reconciliations` | — | Audit trail; the reset **appends** a row here |

**No table is ever dropped.** Every table survives the reset — the operational
ones are simply empty.

---

## Before you start

1. **Apply the eight migrations first.** The reset expects `schema_migrations`
   and `facilities` to exist. See [Migration order](#migration-order).
2. **Practise on a copy.** Never rehearse on production.
3. **Tell the church.** After the reset the portal looks empty until they
   encode their 2026 data. That is expected, and it should not be a surprise.
4. **Make sure MySQL is running** (XAMPP Control Panel → MySQL → Start).

---

## Step 1 — Test against a copy

Always do this first. It proves the reset behaves on *their* data, not just in
principle.

```bash
# Snapshot production into a throwaway database
cd /c/xampp/mysql/bin
./mysqldump.exe -uroot --single-transaction --routines --events victory_bacolod > /tmp/vb_snapshot.sql
./mysql.exe -uroot -e "DROP DATABASE IF EXISTS vb_test; CREATE DATABASE vb_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
./mysql.exe -uroot vb_test < /tmp/vb_snapshot.sql

# Apply the migrations to the copy
cd /c/xampp/htdocs/2026/victory-bacolod/d5z8k2qv-x3pn-h7rb-m4yt-c6f1s9wae0l
for f in migrations/2026_10_20_*.sql; do /c/xampp/mysql/bin/mysql.exe -uroot vb_test < "$f"; done

# Reset the copy
cd migrations/reset
./reset_2026.sh --db vb_test          # type: RESET 2026
```

Check the copy looks right, then throw it away:

```bash
/c/xampp/mysql/bin/mysql.exe -uroot -e "DROP DATABASE vb_test;"
```

---

## Step 2 — Dry run against production

The dry run **takes and verifies a real backup**, reports exactly what would be
deleted, and then stops without changing anything.

**Git Bash:**
```bash
cd /c/xampp/htdocs/2026/victory-bacolod/d5z8k2qv-x3pn-h7rb-m4yt-c6f1s9wae0l/migrations/reset
./reset_2026.sh --dry-run
```

**Windows (no Git Bash):**
```bat
cd C:\xampp\htdocs\2026\victory-bacolod\d5z8k2qv-x3pn-h7rb-m4yt-c6f1s9wae0l\migrations\reset
reset_2026.bat
```

Read the two tables it prints. The "WILL BE KEPT" list must show
`accounts (LOGINS)` with at least 1 row. If it shows 0, **stop** — something is
wrong with the connection or the database name.

---

## Step 3 — Keep the backup somewhere safe

The dry run leaves a verified backup at:

```
<project>/../_backups/victory_bacolod_pre_reset_YYYYMMDD_HHMMSS.sql
```

**Copy it off this machine** — church Google Drive, an external disk, wherever
leadership keeps records. This file is the only route back to the 2023 data,
and it contains members' personal information, so treat it accordingly:

- Never commit it to git (`.gitignore` already blocks `_backups/` and
  `*_pre_reset_*.sql`).
- Never leave it inside the web root where a browser could request it.
- Store it where the church, not just the developer, can find it later.

---

## Step 4 — Run the reset

Only after steps 1–3.

**Git Bash:**
```bash
./reset_2026.sh
```

**Windows:**
```bat
reset_2026.bat LIVE
```

You will be asked to type `RESET 2026` exactly. Anything else aborts.

The script refuses to proceed unless it has taken a backup that is:
non-empty, larger than 10 KB, carries mysqldump's own completion marker, and
contains the `members` table. A truncated or failed dump aborts the run before
anything is deleted.

### What you should see

```
RESET COMPLETE.
  Operational rows remaining : 0
  Admin accounts preserved   : 1
  Backup retained at         : .../victory_bacolod_pre_reset_20261020_233647.sql
```

If the post-check fails, the script tells you to restore and gives the exact
command. The SQL runs inside a single transaction, so a mid-run failure rolls
back and leaves the database as it was.

---

## Step 5 — Verify the portal

1. Log in. The existing admin account still works.
2. Dashboard shows zeros — correct.
3. Members page is empty with its empty-state message — correct.
4. **Settings → Ministries / Services / Discipleship Steps still populated** —
   this is the important one. If these are empty, the wrong thing was cleared;
   restore from the backup.
5. Spiritual Foundations → Curriculum still lists the 10 weeks.

---

## Restoring

If anything is wrong, restore immediately. This puts back *everything* as it
was at backup time.

```bash
/c/xampp/mysql/bin/mysql.exe -uroot victory_bacolod < "/path/to/victory_bacolod_pre_reset_YYYYMMDD_HHMMSS.sql"
```

```bat
C:\xampp\mysql\bin\mysql.exe -uroot victory_bacolod < "C:\path\to\victory_bacolod_pre_reset_YYYYMMDD_HHMMSS.sql"
```

The dump is taken with `--add-drop-table`, so it replaces the current contents
rather than merging into them. Verified working: a full reset followed by a
restore returned all 2,160 operational rows.

---

## Migration order

Apply in this order. Each is safe to run twice — re-running is a no-op.

| # | File | What it does | Destructive |
|---|---|---|---|
| 1 | `2026_10_20_01_schema_migrations.sql` | Migration ledger + backfill of the 12 already applied | No |
| 2 | `2026_10_20_02_member_contact_fields.sql` | `members` + `email`, `facebook_url` | No |
| 3 | `2026_10_20_03_group_model.sql` | VG / LG / Campus split; multi-group leadership; missing FK | No |
| 4 | `2026_10_20_04_service_times_flag.sql` | Marks which `services` rows are real service times | No |
| 5 | `2026_10_20_05_service_attendances.sql` | Sunday attendance table | No |
| 6 | `2026_10_20_06_facilities.sql` | Rooms from the facility policy | No |
| 7 | `2026_10_20_07_facility_requests.sql` | Facility requests + approval history | No |
| 8 | `2026_10_20_08_import_staging.sql` | Staging tables for all three importers | No |
| — | `reset/2026_reset_foundation.sql` | **The clean slate** | **YES** |
| — | `reset/2026_10_20_09_drop_volunteer_status.sql` | Drops `members.volunteer_status` | **YES** |

```bash
cd /c/xampp/htdocs/2026/victory-bacolod/d5z8k2qv-x3pn-h7rb-m4yt-c6f1s9wae0l
for f in migrations/2026_10_20_*.sql; do
    echo "Applying $f"
    /c/xampp/mysql/bin/mysql.exe -uroot victory_bacolod < "$f" || break
done
```

Check what has been applied:

```sql
SELECT filename, applied_at FROM schema_migrations ORDER BY filename;
```

### A note on `2026_10_20_09_drop_volunteer_status.sql`

It lives in `migrations/reset/`, **not** `migrations/`, so a "run everything in
order" loop cannot pick it up by accident.

Run it **only after** the code that reads `volunteer_status` has been removed
and deployed. Roughly twenty places still reference the column, including an
unguarded query on the Dashboard — dropping it early breaks the home page.

---

## Why the delete order matters

The reset deletes children before parents:

```
historical_completion_reviews → member_discipleship → program_attendances
→ vg_members → victory_groups → serve_team_members → serve_teams → members
```

Three foreign keys use `ON DELETE SET NULL`
(`program_attendances.member_id`, `vg_members.member_id`,
`serve_team_members.member_id`). If `members` were cleared first, those tables
would not error — they would quietly have every `member_id` blanked, severing
the link between people and their records *before* those records were removed.
A failure after that point would leave unrecoverable orphans in a database that
still looked structurally fine.

With children first, nothing is ever orphaned, and `FOREIGN_KEY_CHECKS` never
has to be disabled.

`DELETE` is used rather than `TRUNCATE`: InnoDB refuses to truncate a table
with inbound foreign keys, and truncation is not transactional. `DELETE` lets
the whole reset run in one transaction that rolls back cleanly.
`AUTO_INCREMENT` is reset separately afterwards, so the church's first 2026
member is id 1.

---

## After the reset

The church encodes their current 2026 information:

1. **Members** — manually, or via the Excel import (upload → preview →
   confirm). Nothing is inserted until an admin confirms the preview.
2. **Groups** — from the 2026 Staff VG Schedule, through staging and review.
   Names that cannot be matched are flagged *Needs Member Link* rather than
   guessed at, and couple names are never split.
3. **Discipleship** — unchanged: adding an attendance record is what marks a
   step complete. There are no manual completion checkboxes, by design.

Historical Completions remains available and will simply be empty until
there is something to review.
