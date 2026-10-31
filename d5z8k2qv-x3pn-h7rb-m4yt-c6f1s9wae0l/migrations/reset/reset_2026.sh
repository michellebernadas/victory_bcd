#!/usr/bin/env bash
# =============================================================================
# VICTORY BACOLOD — 2026 FOUNDATION RESET RUNNER
#
# The ONLY supported way to run 2026_reset_foundation.sql. It refuses to do
# anything destructive unless a real backup exists on disk first.
#
# WHAT IT GUARANTEES
#   1. A timestamped SQL backup is taken BEFORE anything is deleted.
#   2. The backup is verified non-empty and structurally complete (it must
#      contain the dump's own completion marker) before the reset is allowed.
#   3. You see exact row counts and must type a confirmation phrase.
#   4. The reset itself runs in one transaction inside the SQL file.
#   5. Row counts are re-checked afterwards and mismatches are reported.
#
# USAGE
#   ./reset_2026.sh --dry-run                 # backup + report, changes NOTHING
#   ./reset_2026.sh                           # interactive, requires typed confirmation
#   ./reset_2026.sh --db victory_bacolod --user root
#
# ALWAYS run --dry-run first, and always against a COPY before production.
# =============================================================================

set -euo pipefail

DB_NAME="victory_bacolod"
DB_USER="root"
DB_PASS=""
DB_HOST="localhost"
DRY_RUN=0

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RESET_SQL="$SCRIPT_DIR/2026_reset_foundation.sql"
# Backups live OUTSIDE the app directory: the church must retain them even if
# the application is redeployed, and .gitignore must never swallow them.
BACKUP_DIR="${VB_BACKUP_DIR:-$SCRIPT_DIR/../../../_backups}"

# XAMPP ships its own client; prefer it, fall back to whatever is on PATH.
MYSQL_BIN="${VB_MYSQL_BIN:-/c/xampp/mysql/bin/mysql.exe}"
MYSQLDUMP_BIN="${VB_MYSQLDUMP_BIN:-/c/xampp/mysql/bin/mysqldump.exe}"
command -v "$MYSQL_BIN"     >/dev/null 2>&1 || MYSQL_BIN="mysql"
command -v "$MYSQLDUMP_BIN" >/dev/null 2>&1 || MYSQLDUMP_BIN="mysqldump"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --db)      DB_NAME="$2"; shift 2 ;;
        --user)    DB_USER="$2"; shift 2 ;;
        --password) DB_PASS="$2"; shift 2 ;;
        --host)    DB_HOST="$2"; shift 2 ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

mysql_args=(-u"$DB_USER" -h"$DB_HOST")
[[ -n "$DB_PASS" ]] && mysql_args+=(-p"$DB_PASS")

say()  { printf '%s\n' "$*"; }
rule() { printf '%s\n' "------------------------------------------------------------"; }
die()  { printf 'ABORTED: %s\n' "$*" >&2; exit 1; }

rule
say "  VICTORY BACOLOD — 2026 FOUNDATION RESET"
say "  Database : $DB_NAME @ $DB_HOST"
say "  Mode     : $([[ $DRY_RUN -eq 1 ]] && echo 'DRY RUN (nothing will be deleted)' || echo '*** LIVE — DATA WILL BE DELETED ***')"
rule

[[ -f "$RESET_SQL" ]] || die "reset SQL not found at $RESET_SQL"

# ─── 0. Connectivity ─────────────────────────────────────────────────────────
"$MYSQL_BIN" "${mysql_args[@]}" -e "USE \`$DB_NAME\`;" 2>/dev/null \
    || die "cannot connect to '$DB_NAME' as '$DB_USER'. Check credentials and that MySQL is running."

# ─── 1. Report what is about to be cleared ───────────────────────────────────
say ""
say "Rows that WILL BE CLEARED:"
"$MYSQL_BIN" "${mysql_args[@]}" "$DB_NAME" --table -e "
SELECT 'members' AS table_name, COUNT(*) AS rows_to_delete FROM members
UNION ALL SELECT 'member_discipleship',           COUNT(*) FROM member_discipleship
UNION ALL SELECT 'program_attendances',           COUNT(*) FROM program_attendances
UNION ALL SELECT 'vg_members',                    COUNT(*) FROM vg_members
UNION ALL SELECT 'victory_groups',                COUNT(*) FROM victory_groups
UNION ALL SELECT 'serve_team_members',            COUNT(*) FROM serve_team_members
UNION ALL SELECT 'serve_teams',                   COUNT(*) FROM serve_teams
UNION ALL SELECT 'historical_completion_reviews', COUNT(*) FROM historical_completion_reviews
UNION ALL SELECT 'service_attendances',           COUNT(*) FROM service_attendances
UNION ALL SELECT 'facility_requests',             COUNT(*) FROM facility_requests
UNION ALL SELECT 'facility_request_history',      COUNT(*) FROM facility_request_history
UNION ALL SELECT 'import staging (all 3)',
    (SELECT COUNT(*) FROM member_imports) + (SELECT COUNT(*) FROM group_import_staging)
  + (SELECT COUNT(*) FROM facility_request_imports);"

say ""
say "Rows that WILL BE KEPT:"
"$MYSQL_BIN" "${mysql_args[@]}" "$DB_NAME" --table -e "
SELECT 'accounts (LOGINS)' AS table_name, COUNT(*) AS rows_kept FROM accounts
UNION ALL SELECT 'discipleship_steps',   COUNT(*) FROM discipleship_steps
UNION ALL SELECT 'ministries',           COUNT(*) FROM ministries
UNION ALL SELECT 'services',             COUNT(*) FROM services
UNION ALL SELECT 'sf_topics (CURRICULUM)', COUNT(*) FROM sf_topics
UNION ALL SELECT 'serve_options',        COUNT(*) FROM serve_options
UNION ALL SELECT 'vg_options',           COUNT(*) FROM vg_options
UNION ALL SELECT 'vgl_stat_definitions', COUNT(*) FROM vgl_stat_definitions;"

# ─── 2. Backup — taken in BOTH modes. This is the whole point of the script. ─
mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_FILE="$BACKUP_DIR/${DB_NAME}_pre_reset_${STAMP}.sql"

say ""
say "Taking backup -> $BACKUP_FILE"
"$MYSQLDUMP_BIN" "${mysql_args[@]}" \
    --single-transaction --routines --events --triggers \
    --add-drop-table --complete-insert \
    "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null \
    || die "mysqldump failed. Nothing was deleted."

# Verify the backup is real, not a truncated or empty file.
[[ -s "$BACKUP_FILE" ]] || die "backup file is empty. Nothing was deleted."
BACKUP_BYTES=$(wc -c < "$BACKUP_FILE" | tr -d '[:space:]')
(( BACKUP_BYTES > 10240 )) || die "backup is only ${BACKUP_BYTES} bytes — implausibly small. Nothing was deleted."
# mysqldump writes this marker as its final line only on a complete dump.
grep -q "Dump completed" "$BACKUP_FILE" \
    || die "backup has no completion marker — it is truncated. Nothing was deleted."
# A backup that cannot be found later is not a backup.
grep -q "CREATE TABLE \`members\`" "$BACKUP_FILE" \
    || die "backup does not contain the members table. Nothing was deleted."

say "Backup verified: ${BACKUP_BYTES} bytes, structurally complete."

if [[ $DRY_RUN -eq 1 ]]; then
    say ""
    rule
    say "DRY RUN COMPLETE — the database was NOT modified."
    say "A verified backup was still taken, at:"
    say "  $BACKUP_FILE"
    say ""
    say "To restore from it at any time:"
    say "  $MYSQL_BIN -u$DB_USER $DB_NAME < \"$BACKUP_FILE\""
    say ""
    say "When you are ready, re-run WITHOUT --dry-run."
    rule
    exit 0
fi

# ─── 3. Explicit human confirmation ──────────────────────────────────────────
say ""
rule
say "  THIS WILL PERMANENTLY DELETE THE 2023 OPERATIONAL DATA LISTED ABOVE."
say "  Admin logins and all configuration will be preserved."
say "  Backup: $BACKUP_FILE"
rule
say ""
printf 'Type exactly  RESET 2026  to proceed (anything else aborts): '
read -r CONFIRM
[[ "$CONFIRM" == "RESET 2026" ]] || die "confirmation phrase not matched. Nothing was deleted."

# ─── 4. Run the reset ────────────────────────────────────────────────────────
say ""
say "Running reset..."
if ! "$MYSQL_BIN" "${mysql_args[@]}" "$DB_NAME" --table < "$RESET_SQL"; then
    say ""
    die "reset FAILED. The SQL ran in a transaction, so the database should be unchanged.
     Verify with the counts above, and restore if needed:
       $MYSQL_BIN -u$DB_USER $DB_NAME < \"$BACKUP_FILE\""
fi

# ─── 5. Independent post-check ───────────────────────────────────────────────
# The SQL file prints its own verification; this re-checks from outside it so a
# silent partial success cannot pass unnoticed.
REMAINING=$("$MYSQL_BIN" "${mysql_args[@]}" "$DB_NAME" -N -B -e "
SELECT (SELECT COUNT(*) FROM members)
     + (SELECT COUNT(*) FROM member_discipleship)
     + (SELECT COUNT(*) FROM program_attendances)
     + (SELECT COUNT(*) FROM vg_members)
     + (SELECT COUNT(*) FROM victory_groups)
     + (SELECT COUNT(*) FROM serve_team_members)
     + (SELECT COUNT(*) FROM serve_teams)
     + (SELECT COUNT(*) FROM historical_completion_reviews)
     + (SELECT COUNT(*) FROM service_attendances)
     + (SELECT COUNT(*) FROM facility_requests)
     + (SELECT COUNT(*) FROM facility_request_history)
     + (SELECT COUNT(*) FROM member_imports)
     + (SELECT COUNT(*) FROM group_import_staging)
     + (SELECT COUNT(*) FROM facility_request_imports);")
ACCOUNTS=$("$MYSQL_BIN" "${mysql_args[@]}" "$DB_NAME" -N -B -e "SELECT COUNT(*) FROM accounts;")

say ""
rule
if [[ "$REMAINING" == "0" && "$ACCOUNTS" != "0" ]]; then
    say "RESET COMPLETE."
    say "  Operational rows remaining : 0"
    say "  Admin accounts preserved   : $ACCOUNTS"
    say "  Backup retained at         : $BACKUP_FILE"
    say ""
    say "NEXT: the church can now encode their 2026 members and discipleship data."
    say "Keep the backup file somewhere safe and OUTSIDE the web root."
else
    say "POST-CHECK FAILED."
    say "  Operational rows remaining : $REMAINING  (expected 0)"
    say "  Admin accounts             : $ACCOUNTS  (expected > 0)"
    say ""
    say "Restore immediately with:"
    say "  $MYSQL_BIN -u$DB_USER $DB_NAME < \"$BACKUP_FILE\""
    rule
    exit 1
fi
rule
