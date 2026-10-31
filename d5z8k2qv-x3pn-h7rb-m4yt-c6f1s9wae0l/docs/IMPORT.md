# Importing Members from Excel

Admin → **Members → Import from Excel**

> Importing the **Staff VG Schedule** or the **Facilities Request Forms**?
> See [Importing the 2026 Reference Spreadsheets](IMPORT-2026-REFERENCE.md).
> Both work the same staged way as this one.

The import never adds anyone until you have seen exactly what it found and
pressed **Confirm Import**.

```
UPLOAD → CHECK THE ROWS → CONFIRM → MEMBERS ADDED
```

Uploading a file only reads and checks it. Nothing is written to your member
list at that point, so an unexpected file costs you a glance, not a cleanup.

---

## What your file needs

A header row containing at least:

- **First Name** and **Last Name** — or a single **Full Name** column

These extra columns are picked up automatically when present:

| Column | Also accepts |
|---|---|
| Email | Email Address |
| Contact Number | Contact, Mobile, Phone, Cellphone |
| Facebook | Facebook URL, Facebook Profile, FB |
| Ministry | Department |
| Service | Service Attending |
| Life Stage | Civil Status |
| Notes | Remarks |

Header matching ignores case, spaces and punctuation, so `First Name`,
`FIRSTNAME` and `first_name` all work. The header does not have to be row 1 —
the importer looks for it in the first 20 rows.

Accepted formats: `.xlsx`, `.xls`, `.csv`, up to 5 MB.

---

## Reading the preview

Every row gets one of five outcomes:

| Status | Meaning | Imported? |
|---|---|---|
| **Ready to import** | Clean row, no problems found | Yes, automatically |
| **Needs review** | Saved fine, but something looks odd | Only if you tick it |
| **Repeated in file** | The same name appears earlier in this file | Only if you tick it |
| **Already a member** | Matches someone already on your list | Only if you tick it |
| **Cannot import** | No usable name | Never |

The summary line above the table is the thing to read:

> **12 members will be created. 3 rows require review. 2 rows appear to already exist.**

Ticking a row updates that count live, so the number on the Confirm button is
always what will actually happen.

**Download Review List** gives you a CSV of every row that is not automatically
ready, with the reason for each — useful for fixing the source file offline and
re-uploading.

---

## How duplicates are detected

Three checks, all exact after normalising:

1. **Email** — lower-cased, exact match
2. **Name** — first + last, ignoring case, spaces, punctuation and accents, so
   `O'Brien` = `OBrien` and `José` = `Jose`
3. **Repeats within your own file**

There is deliberately **no fuzzy matching**. In a church, two different people
genuinely can be called *Maria Santos*, and quietly merging them would be far
worse than asking. Everything found is flagged for you to decide — the importer
never merges records on its own.

A duplicate is a **warning, not a block**. If the two really are different
people, tick the row and import it.

> **Note:** two members may share one email address. Families often do, and the
> importer allows it — it just flags the match so you can check.

---

## How names are handled

Names are saved **exactly as written**. These are all accepted with no warning:

- `O'Brien`, `D'Souza` — apostrophes
- `Ñuñez-Lim`, `Smith-Jones` — accents and hyphens
- `José`, `Peña` — accented characters
- `dela Cruz`, `San Juan`, `Ma. Teresa` — compound names and periods

You will get a **warning** (not a rejection) for:

- a name that is a single character — it may be an initial
- a name containing digits
- a name in ALL CAPS — it will be saved that way
- unusual symbols

A row is only **Cannot import** when there is no usable name at all.

If a row has only a Full Name with no comma, the **first** word is taken as the
given name and everything after it as the surname — so `Juan Dela Cruz` becomes
*Dela Cruz, Juan*. This suits Filipino compound surnames, but it is worth
checking those rows in the preview.

---

## Confirming

**Confirm Import** is the only step that creates members. It runs as a single
database transaction: either every row is added, or none is, so a failure
part-way through cannot leave your list half-updated.

Re-pressing Confirm on the same batch is safe — rows already imported are
skipped, never duplicated.

**Discard** throws the batch away. Since nothing was written, nothing is lost.

---

## After importing

The importer only creates the member record. It does **not** set discipleship
progress — that still comes from class attendance records, exactly as it does
for a member added by hand. Add their Victory Weekend, Church Community,
Making Disciples or Empowering Leaders record and the matching step lights up
automatically on their profile.

---

## Notes for developers

- Entry point: `index.php?action=memberImport` → `MemberImportController`
- Staging table: `member_imports` (see `migrations/2026_10_20_08_import_staging.sql`)
- Parsing, validation and duplicate logic: `models/MemberImport.php`
- Admin-only. The old top-level `import.php` has been **removed**: it required
  no login, read a hardcoded path, and ran `DELETE FROM vg_members` /
  `DELETE FROM victory_groups` before inserting.
- `commit()` is idempotent per row via `member_imports.created_member_id`.
