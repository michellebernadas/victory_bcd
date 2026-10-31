# Importing the 2026 Reference Spreadsheets

Two importers bring the church's **current 2026 starting point** into the
portal: the **Staff VG Schedule** and the **Facilities Request Forms**.

Both work exactly like [Importing Members from Excel](IMPORT.md):

```
UPLOAD → CHECK WHAT WAS FOUND → CONFIRM → SAVED
```

Uploading a file only reads and checks it. **Nothing is created until you press
Confirm**, so an unexpected file costs you a glance, not a cleanup.

Two things neither importer will ever do:

- **It never creates a member.** A name it cannot match is saved exactly as
  written and marked **Needs Link** for you to resolve.
- **It never approves or confirms anything.** A request sitting in a spreadsheet
  is not an approved request.

---

# 1. Staff VG Schedule

Admin → **Victory Groups → Import VG Schedule**

## What your file needs

The schedule as the church already keeps it — a grid of group cards. Each card
starts with **VGL:** and the leader's name beside it:

```
VGL:                    Philip and Sab Porras
Intern:
Members:
 1.                     Alejandro Abad
 2.                     Cara Abad
Type of VG:             Couples
Day:                    Thursday
Time:                   7:00 PM
Frequency of meeting:   Weekly
Venue:                  Quinos Lacson
```

Cards do not have to line up with each other. One column's card can run eleven
members long while the card beside it has two — each is read on its own.

Accepted formats: `.xlsx`, `.xls`, up to 5 MB. **Not CSV** — a CSV loses the
card layout.

## Reading the preview

The top of the screen gives you the two counts that matter:

> **11 group cards found** — 9 Victory Groups, 0 Leadership Groups, **2 Campus**
>
> **86 names on the sheet** — 9 matched to a member, **77 Needs Link**

Then one panel per group card, showing everything read from it and every name
with its link status. Untick any card you do not want.

Each card gets one of three outcomes:

| Status | Meaning | Imported? |
|---|---|---|
| **Ready to import** | Everything read cleanly and every name matched | Yes, unless you untick it |
| **Needs review** | Something is worth a look — an unreadable time, a missing venue, or names that need linking | Yes, unless you untick it |
| **Cannot import** | No leader and no members — there is no group to create | Never |

**Needs review does not hold a group back.** Before the 2026 members have been
encoded, almost every name will need linking — that is the expected starting
state, not a problem to fix first.

## Campus is never a Victory Group

The **Type of VG** cell decides which of the three categories a card belongs to:

| Type of VG says | Filed as |
|---|---|
| Campus (anywhere in the cell) | **Campus** |
| Couples, Single Men, Single Women, Men, Women, Young Adult, Seniors, … | Victory Group |
| Leadership Group, LG | Leadership Group |
| *anything else* | Victory Group, with the text kept as the audience and the card flagged |
| *blank* | Victory Group, and the card is flagged so you can check |

A Campus group filed as a Victory Group would quietly inflate every Victory
Group count in the app, so Campus is checked first and on its own, and the count
is shown on the preview before you confirm.

## How names are handled

Names are saved **exactly as written** — the original text is never rewritten.

**A couple is always one name.** `Amy & Carl`,
`Hazel Lyn and Emmanuel Gabrido`, `Jason Aguilos & Ariannah Lim` stay as one
person-row with the name spelled the way the church spelled it. They are never
split into two people, and never auto-matched — the string refers to two people,
so there is no single member it could honestly point at.

A name is linked to a member only when it matches **exactly one** person on your
list, ignoring case, spaces, punctuation and accents. Everything else becomes
**Needs Link**:

| What was written | What happens |
|---|---|
| `Jedi Salvio`, and one member matches | **Linked** |
| `Amy & Carl` — a couple | Needs Link |
| `Myka`, `Val`, `Stephen` — only a first name | Needs Link |
| A name two members share | Needs Link — the wrong one is never picked |
| A name nobody on your list has | Needs Link |

A **Needs Link** name still imports. It appears on the Victory Groups page with
a yellow *Needs Link* badge. **No member record is created for it.** Add that
person as a member, then link them by hand — which is the right order for the
2026 start, and the only way the importer can stay honest about who is really
on your list.

Group counts in the app only ever count linked people, so needs-link rows never
inflate a leader or member total.

**Download Needs-Link List** gives you a CSV of every unmatched name with the
group it belongs to and the reason — useful for working through them offline.

## Times, days and frequency

The schedule writes times three different ways, and all three are read:

| On the sheet | Saved as |
|---|---|
| `0.41666666666667` (Excel's own time format) | 10:00 AM |
| `12:00 NN` | 12:00 PM |
| `3-5PM`, `1PM-2:30PM` (a range) | the **start** time — a group has one Time field |

A time that cannot be read leaves the group with no meeting time rather than a
guess, and says so on the preview. The original text is always kept.

Day names are tidied up (including `Thurday` → Thursday). Anything that is not a
day name is saved as written and flagged.

## One person, several groups

Fully supported. A leader who runs two groups is linked to both — the app counts
them as one person, not two.

---

# 2. Facilities Request Forms

Admin → **Facility Booking → Import Request Forms**

## What your file needs

The request workbook as the church already keeps it — **one request per
worksheet**. A sheet is treated as a request form when it carries the form's
labels (`NAME:`, `EVENT/ACTIVITY:`, `DATE NEEDED:`) and has been filled in.

Everything else is skipped and **listed on the preview with the reason**, so you
can see nothing was quietly dropped:

- the blank `REQUEST FORM` template — right labels, nothing filled in
- unrelated sheets, such as the budget and collections sheets

Accepted formats: `.xlsx`, `.xls`, up to 5 MB (`.csv` for the template below).

## Or: the one-row-per-request template

For many requests at once, press **Download template** on the import page. The
file is generated on the spot, so its dropdowns list **today's** rooms and
ministries. It has:

- an **Instructions** sheet — the steps, every column, which are required, and
  the date / time formats accepted;
- a **Requests** sheet — one request per row, columns in the form's own words
  (sections A–F), header frozen, dropdowns for room, type of request, ministry
  and the Yes/No columns. Row 3 is a **SAMPLE** row and is always skipped.

There are **no Facilities-review or Minister columns**, so a template row can
only ever import as **Submitted**. The importer recognises the template by its
header row and keeps reading the one-sheet-per-request workbook as before.

The portal form's rules are checked per row and shown on the preview with the
row number — a row that breaks one is listed as *Cannot import* until fixed,
never silently dropped:

- `Row 7: End time is before (or the same as) the start time.`
- `Row 9: A Facility / Room request needs a room and a start and end time.`
- `Row 12: Date needed is required.`

Imported requests have **no owner** (`submitted_by` is empty): the admin who
imports them is not the requestor, so they do not appear in anyone's *My
Requests*.

## Being in a spreadsheet is not an approval

**This is the rule the whole importer is built around.**

Every imported request arrives as **Submitted**. The preview carries two
counters that are always zero:

> **Approved on import: 0** &nbsp;&nbsp; **Confirmed on import: 0**

They are not zero because nothing happened to qualify. They are zero because the
importer has no way to produce either status. It can create a Submitted request,
and it can apply a Facilities review. An approval is the **Minister's** to give
in the portal, and only a **confirmed** booking reserves a room.

If a sheet has a Minister decision written on it, the preview tells you so and
the decision is **not imported**. Record it in the portal afterwards.

## When a sheet shows a Facilities review

Some sheets have the **THIS AREA IS TO BE FILLED BY FACILITIES PERSONNEL**
section completed. Those requests are imported in two steps, in the order they
really happened:

1. imported as **Submitted**
2. the **Facilities review** applied on top → **Pending Minister**

So the request's history shows **two entries**, not one — a truthful record
rather than a request that appears to have sprung into existence half-way
through the workflow. The preview tells you which sheets this applies to and
which answers it found.

> **A pre-printed name is not a review.** `FACILITIES PERSONNEL: JANDRIC L.
> SALVIO` is printed on every sheet of the workbook — including the blank
> template. A name on its own is not treated as a completed review; the preview
> says so and the request imports as plain Submitted. A review counts only when
> the review *answers* are filled in — availability, schedule conflict, setup,
> technical support, recommendation, other considerations, or a review date.

## Dates and times

The forms record these in whatever way was convenient, and all of it is
converted before anything is saved:

| On the sheet | Saved as |
|---|---|
| `46312` (Excel's own date format) | 17 Oct 2026 |
| `10/17/2026, Saturday` | 17 Oct 2026 — the day name is dropped |
| `0.41666666666667` (Excel's own time format) | 10:00 AM |
| `12:00 NN` | 12:00 PM |

A date that cannot be read leaves the request without one rather than guessing,
and the preview says so. A request with no date does not appear on the calendar
until you set one.

## Rooms

A requested facility is matched only on an **exact** name. A name that does not
match a facility on file imports without a room, and the preview tells you —
half-guessing a room would book the wrong one.

Both of the church's current requests are equipment-only and have no room at
all, which is normal and reserves nothing.

---

# Confirming, re-confirming and discarding

**Confirm** is the only step that writes anything.

Re-pressing Confirm on the same batch is **safe**. Groups, people and requests
that were already created are skipped, never duplicated.

**Discard** throws away a batch you have not confirmed. Since nothing was
written, nothing is lost. Anything already imported is kept as a record of what
was brought in.

---

# After importing

**Groups.** The importer creates the groups and their people. Unmatched names
sit as *Needs Link*. Once you have encoded the 2026 members, open the group and
link each name to the right person. Leader and member counts across the app
only count linked people, so they stay honest while you work through them.

**Requests.** Everything is Submitted and waiting. Move each one along the normal
workflow — Facilities review, Minister decision, Facilities confirmation. Only
that last step reserves a room.

---

# Notes for developers

| | Staff VG Schedule | Facilities Request Forms |
|---|---|---|
| Entry point | `index.php?action=groupImport` | `index.php?action=facilityImport` |
| Controller | `controllers/GroupImportController.php` | `controllers/FacilityImportController.php` |
| Model | `models/GroupImport.php` | `models/FacilityRequestImport.php` |
| Review screen | `views/group_import.php` | `views/facility_import.php` |
| Staging | `group_import_cards` + `group_import_staging` | `facility_request_imports` |
| Idempotent via | `created_group_id`, `created_vg_member_id` | `created_request_id` |

- Both are **admin-only**, guarded in `index.php` the same way `memberImport` is.
- Migrations: `2026_10_20_08_import_staging.sql` created the staging tables;
  `2026_10_20_10_group_import_cards.sql` added `group_import_cards` and the
  `card_index` / `sort_order` / `created_vg_member_id` columns that tie a staged
  person to their card.
- **No member is ever created by either importer.** `GroupImport::classifyPerson()`
  is the only place a name is resolved, and its four outcomes are couple →
  needs_link, mononym → needs_link, exactly one match → linked, everything else →
  needs_link. There is no insert into `members` anywhere in either file.
- **No request can reach `approved` or `confirmed`.**
  `FacilityRequestImport::commit()` calls `FacilityRequest::submit()` and
  `facilitiesReview()` and nothing else. `ministerDecision()` and `confirm()` are
  never called from the importer, so there is no code path to an approval —
  the guarantee is structural, not a check applied afterwards.
- All Excel normalisation (serial dates, time fractions, free-text dates, time
  ranges) happens in the importers. `FacilityRequest::deriveInterval()` only ever
  receives a clean `Y-m-d` and `H:i`.
- `FacilityRequest::submit()` accepts `source_label` (the worksheet name), set on
  INSERT rather than updated afterwards, so an imported request has never existed
  without its provenance attached.
- `facility_request_imports.created_request_id` has no foreign key. `getBatch()`
  LEFT JOINs and sets a `request_missing` flag, so a hard-deleted request shows as
  a notice on the review screen rather than a blank row.
