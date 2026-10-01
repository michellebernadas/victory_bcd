<?php
require_once 'models/ProgramAttendance.php';
require_once 'models/SfTopic.php';
require_once 'models/SfProgress.php';

/**
 * The single source of truth for discipleship progress.
 *
 *     CLASS / SESSION ATTENDANCE   (program_attendances — the evidence)
 *                 ↓
 *     DISCIPLESHIP JOURNEY         (member_discipleship — derived state)
 *                 ↓
 *     members.<step> booleans      (compatibility cache only)
 *
 * Nothing outside this service may decide that a step is complete. Previously
 * three code paths could each set a flag independently — syncMemberFlag, the
 * member form's checkbox group, and a blind `UPDATE members SET
 * victory_weekend = 1` in AttendanceController — so deleting the only
 * attendance record left the flag stuck at 1 forever. Now every change funnels
 * through recalculateMember(), which DERIVES the state from current evidence
 * instead of setting it.
 *
 * Two kinds of evidence count:
 *   attendance — a valid, linked, active attendance record that satisfies the
 *                class's own completion rule
 *   historical — an admin-approved legacy completion for which no attendance
 *                record exists (old church records). Recalculation never
 *                deletes these; that is what makes recalculation safe to run.
 *
 * NOTE FOR A FUTURE LARAVEL MIGRATION: members.victory_weekend and its five
 * siblings are DERIVED CACHE COLUMNS, not inputs. They exist only because older
 * list/filter queries still read them. Port member_discipleship, not the flags.
 */
class DiscipleshipProgressService {
    /** Steps whose evidence is "any valid attendance row for this program_type". */
    const SIMPLE_ATTENDANCE_STEPS = [
        'victory_weekend', 'church_community', 'making_disciples', 'empowering_leaders',
    ];

    private $db;
    private ProgramAttendance $pa;
    private ?SfProgress $sf = null;
    private ?array $steps = null;

    public function __construct($db) {
        $this->db = $db;
        $this->pa = new ProgramAttendance($db);
    }

    /** Active, non-deleted steps that have a members column mapping. */
    private function steps(): array {
        if ($this->steps !== null) return $this->steps;
        try {
            $this->steps = $this->db->query(
                "SELECT id, name, column_key, abbreviation FROM discipleship_steps
                  WHERE is_deleted = 0 AND column_key IS NOT NULL AND column_key != ''
                  ORDER BY sort_order ASC, id ASC"
            )->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("DiscipleshipProgressService::steps error: " . $e->getMessage());
            $this->steps = [];
        }
        return $this->steps;
    }

    private function sfProgress(): SfProgress {
        if ($this->sf === null) $this->sf = new SfProgress($this->db);
        return $this->sf;
    }

    /** Boolean columns that actually exist on `members`, so we never write a stray one. */
    private function memberColumns(): array {
        static $cols = null;
        if ($cols !== null) return $cols;
        $cols = [];
        try {
            foreach ($this->db->query("SHOW COLUMNS FROM members")->fetchAll() as $c) {
                if (stripos($c['Type'], 'tinyint') === 0) $cols[] = $c['Field'];
            }
        } catch (PDOException $e) { /* leave empty — flag sync is then skipped */ }
        return $cols;
    }

    // ── Evidence ────────────────────────────────────────────────────────────

    /**
     * Attendance evidence for one member + step. Returns the evidencing
     * program_attendances.id, or null when no valid evidence exists.
     *
     * "Valid" deliberately excludes: soft-deleted rows, status != active, rows
     * not linked to a member (member_id IS NULL can never complete anyone), and
     * for the session classes, rows that don't meet that class's rule.
     */
    public function attendanceEvidence(int $memberId, string $columnKey): ?int {
        if ($memberId <= 0) return null;

        if (in_array($columnKey, self::SIMPLE_ATTENDANCE_STEPS, true)) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT id FROM program_attendances
                      WHERE member_id = ? AND program_type = ?
                        AND is_deleted = 0 AND status = 'active'
                      ORDER BY event_date IS NULL, event_date ASC, id ASC LIMIT 1"
                );
                $stmt->execute([$memberId, $columnKey]);
                $id = $stmt->fetchColumn();
                return $id === false ? null : (int)$id;
            } catch (PDOException $e) {
                error_log("attendanceEvidence($columnKey) error: " . $e->getMessage());
                return null;
            }
        }

        // Leadership 1-1-3 — unchanged rule: a record whose session grid has no
        // missed session. Batch lengths vary, so no expected-count check (0).
        if ($columnKey === 'leadership_113') {
            try {
                $stmt = $this->db->prepare(
                    "SELECT id, extra_data FROM program_attendances
                      WHERE member_id = ? AND program_type = 'leadership_113'
                        AND is_deleted = 0 AND status = 'active'
                      ORDER BY program_year ASC, id ASC"
                );
                $stmt->execute([$memberId]);
                foreach ($stmt->fetchAll() as $row) {
                    if (ProgramAttendance::certificateStatus($row['extra_data'], 0)['eligible']) {
                        return (int)$row['id'];
                    }
                }
            } catch (PDOException $e) {
                error_log("attendanceEvidence(l113) error: " . $e->getMessage());
            }
            return null;
        }

        // Spiritual Foundations — cumulative across batches. Reuses the already
        // tested SfProgress logic: a topic counts once it has a P/L in ANY batch,
        // NC gives no credit, and all required topics must be covered.
        if ($columnKey === 'spiritual_foundations') {
            try {
                $stmt = $this->db->prepare(
                    "SELECT id, member_id, full_name_display FROM program_attendances
                      WHERE member_id = ? AND program_type = 'spiritual_foundations'
                        AND is_deleted = 0 AND status = 'active' ORDER BY id ASC LIMIT 1"
                );
                $stmt->execute([$memberId]);
                $first = $stmt->fetch();
                if (!$first) return null;
                $progress = $this->sfProgress()->forRecord($first);
                return !empty($progress['eligible']) ? (int)$first['id'] : null;
            } catch (PDOException $e) {
                error_log("attendanceEvidence(sf) error: " . $e->getMessage());
            }
            return null;
        }

        // Any other step (e.g. Purple Book Class) has no attendance program, so
        // it can only ever be completed historically.
        return null;
    }

    /**
     * The member's approved historical completion for a step, or null.
     *
     * Keyed on historical_approved, NOT on completion_source: the source is
     * derived and flips to 'attendance' whenever evidence exists, whereas the
     * approval is a durable fact that has to outlive that.
     *
     * A rejected review (historical_verification_status = 'rejected') never
     * reaches here because rejectHistorical() already clears
     * historical_approved — this filter is belt-and-suspenders so a rejected
     * row can never again count as a completion, even if something else
     * touches historical_approved later.
     */
    public function historicalRow(int $memberId, int $stepId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM member_discipleship
                  WHERE member_id = ? AND step_id = ? AND historical_approved = 1
                    AND historical_verification_status != 'rejected' LIMIT 1"
            );
            $stmt->execute([$memberId, $stepId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * The existing member_discipleship row regardless of approval/rejection
     * status, or null. Used only to carry a rejection's audit annotations
     * (who/when/why) through recalculation — historicalRow() deliberately
     * excludes rejected rows from counting as a completion, but recalculation
     * must not therefore wipe out those same fields when it upserts the row.
     */
    private function existingRow(int $memberId, int $stepId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM member_discipleship WHERE member_id = ? AND step_id = ? LIMIT 1"
            );
            $stmt->execute([$memberId, $stepId]);
            $r = $stmt->fetch();
            return $r ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    // ── Recalculation ───────────────────────────────────────────────────────

    /**
     * Derives every step for one member from current evidence, writes
     * member_discipleship, and syncs the legacy boolean cache.
     *
     * Approved historical rows are left untouched: if attendance later appears
     * the row is upgraded to `attendance`, and if the attendance is deleted the
     * step falls back to the historical row rather than to incomplete.
     *
     * @return array column_key => ['completed'=>bool,'source'=>string|null]
     */
    public function recalculateMember(int $memberId): array {
        $result = [];
        if ($memberId <= 0) return $result;

        $cols = $this->memberColumns();
        foreach ($this->steps() as $step) {
            $key    = $step['column_key'];
            $stepId = (int)$step['id'];

            $evidenceId = $this->attendanceEvidence($memberId, $key);
            $historical = $this->historicalRow($memberId, $stepId);

            if ($evidenceId !== null) {
                // Attendance wins as the source, but a historical row's notes /
                // approver are kept so the audit trail survives the upgrade.
                //
                // historicalRow() excludes rejected rows (so they can't grant a
                // completion), but a rejection still has to survive this upsert —
                // otherwise every recalculation after a reject would quietly null
                // out WHO rejected it, WHEN, and WHY. So when there's no *approved*
                // historical row, fall back to whatever row already exists (which
                // may be a rejected one) purely to carry its audit fields forward.
                $carry = $historical ?: $this->existingRow($memberId, $stepId);
                $this->upsert($memberId, $stepId, 'attendance', $evidenceId,
                              $carry ? $carry['notes'] : null,
                              $carry ? $carry['verified_by'] : null,
                              $carry ? $carry['verified_at'] : null,
                              $historical ? 1 : 0,
                              $carry ? $carry['completed_at'] : null);
                $result[$key] = ['completed' => true, 'source' => 'attendance'];
            } elseif ($historical) {
                // No attendance evidence, but an approved legacy completion
                // stands. This is the branch that stops recalculation from
                // quietly erasing decades of paper records.
                $this->upsert($memberId, $stepId, 'historical', null,
                              $historical['notes'], $historical['verified_by'],
                              $historical['verified_at'], 1, $historical['completed_at']);
                $result[$key] = ['completed' => true, 'source' => 'historical'];
            } else {
                // No evidence of any kind → genuinely incomplete. Only
                // attendance-sourced rows are removed; historical never is.
                $this->deleteUnapprovedRow($memberId, $stepId);
                $result[$key] = ['completed' => false, 'source' => null];
            }

            // Compatibility cache — derived, never an input.
            if (in_array($key, $cols, true)) {
                try {
                    $this->db->prepare("UPDATE members SET `{$key}` = ? WHERE id = ?")
                        ->execute([$result[$key]['completed'] ? 1 : 0, $memberId]);
                } catch (PDOException $e) {
                    error_log("flag sync {$key} error: " . $e->getMessage());
                }
            }
        }
        return $result;
    }

    /** Recalculates the member linked to an attendance record (by id). */
    public function recalculateFromRecord(int $recordId): void {
        try {
            $stmt = $this->db->prepare("SELECT member_id FROM program_attendances WHERE id = ?");
            $stmt->execute([$recordId]);
            $mid = $stmt->fetchColumn();
            if ($mid) $this->recalculateMember((int)$mid);
        } catch (PDOException $e) {
            error_log("recalculateFromRecord error: " . $e->getMessage());
        }
    }

    /**
     * Full reconciliation. Idempotent: running it twice changes nothing.
     * @return array{members:int,completed:int,attendance:int,historical:int}
     */
    public function recalculateAll(?callable $progress = null): array {
        $out = ['members' => 0, 'completed' => 0, 'attendance' => 0, 'historical' => 0];
        try {
            $ids = $this->db->query("SELECT id FROM members WHERE is_deleted = 0 ORDER BY id")
                ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            return $out;
        }
        foreach ($ids as $id) {
            $res = $this->recalculateMember((int)$id);
            $out['members']++;
            foreach ($res as $r) {
                if (!$r['completed']) continue;
                $out['completed']++;
                if ($r['source'] === 'attendance') $out['attendance']++;
                else                               $out['historical']++;
            }
            if ($progress) $progress($out['members'], count($ids));
        }
        return $out;
    }

    /**
     * ONE-TIME reconciliation for the session classes (L113 / SF).
     *
     * Before this cleanup, those steps completed on the mere EXISTENCE of an
     * attendance record. They now require the class's own completion rule — no
     * missed sessions for L113, all required topics for SF — which is correct,
     * but it means members who were previously marked complete on a record
     * containing absences would silently lose the step.
     *
     * The spec is explicit: a legacy completion with no valid attendance
     * evidence must be PRESERVED as historical, never discarded. So for anyone
     * who has a real record for the class but doesn't meet the stricter rule,
     * this writes a `historical` row explaining why.
     *
     * Guarded by app_reconciliations so it runs exactly once. Running it again
     * later would wrongly grant historical completion to a genuinely new
     * incomplete record, so the guard is load-bearing, not decoration.
     *
     * @return array{ran:bool,created:int,skipped:string}
     */
    public function preserveLegacySessionCompletions(): array {
        $pass = 'preserve_session_legacy_v1';
        try {
            $stmt = $this->db->prepare("SELECT 1 FROM app_reconciliations WHERE name = ? LIMIT 1");
            $stmt->execute([$pass]);
            if ($stmt->fetchColumn() !== false) {
                return ['ran' => false, 'created' => 0, 'skipped' => 'already run'];
            }
        } catch (PDOException $e) {
            return ['ran' => false, 'created' => 0, 'skipped' => 'marker table missing — run migration 03 first'];
        }

        $created = 0;
        foreach (['leadership_113', 'spiritual_foundations'] as $key) {
            $step = null;
            foreach ($this->steps() as $s) if ($s['column_key'] === $key) $step = $s;
            if (!$step) continue;
            $stepId = (int)$step['id'];

            try {
                // Everyone who genuinely sat this class (has a real, linked record).
                $stmt = $this->db->prepare(
                    "SELECT DISTINCT member_id FROM program_attendances
                      WHERE program_type = ? AND member_id IS NOT NULL
                        AND is_deleted = 0 AND status = 'active'"
                );
                $stmt->execute([$key]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                    $mid = (int)$mid;
                    // Meets the stricter rule → already attendance-backed, nothing to preserve.
                    if ($this->attendanceEvidence($mid, $key) !== null) continue;
                    // Already has a row of some kind → leave it alone.
                    $chk = $this->db->prepare(
                        "SELECT 1 FROM member_discipleship WHERE member_id = ? AND step_id = ? LIMIT 1"
                    );
                    $chk->execute([$mid, $stepId]);
                    if ($chk->fetchColumn() !== false) continue;

                    $this->db->prepare(
                        "INSERT IGNORE INTO member_discipleship
                            (member_id, step_id, status, completion_source, historical_approved,
                             source_record_id, notes, verified_by, verified_at, completed_at)
                         VALUES (?,?, 'completed', 'historical', 1, NULL, ?, NULL, NOW(), NULL)"
                    )->execute([$mid, $stepId,
                        'Reconciled: completed under the previous rule (attendance record on file) '
                        . 'but does not meet the stricter session-completion rule. Preserved as historical.']);
                    $created++;
                    // Re-sync the compatibility flag for this member.
                    $this->recalculateMember($mid);
                }
            } catch (PDOException $e) {
                error_log("preserveLegacySessionCompletions($key) error: " . $e->getMessage());
            }
        }

        try {
            $this->db->prepare("INSERT IGNORE INTO app_reconciliations (name, notes) VALUES (?, ?)")
                ->execute([$pass, 'Preserved ' . $created . ' legacy L113/SF completions as historical.']);
        } catch (PDOException $e) { /* marker write failed — next run will retry */ }

        return ['ran' => true, 'created' => $created, 'skipped' => ''];
    }

    // ── Read model (member profile / dashboard) ─────────────────────────────

    /**
     * Authoritative progress for one member.
     * @return array step_id => ['step'=>array,'completed'=>bool,'source'=>string|null, …]
     */
    public function getMemberProgress(int $memberId): array {
        $rows = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM member_discipleship WHERE member_id = ?"
            );
            $stmt->execute([$memberId]);
            foreach ($stmt->fetchAll() as $r) $rows[(int)$r['step_id']] = $r;
        } catch (PDOException $e) { /* fall through to "all incomplete" */ }

        $out = [];
        foreach ($this->steps() as $step) {
            $sid = (int)$step['id'];
            $r   = $rows[$sid] ?? null;
            // A rejected historical row is kept for its audit trail but must
            // never count as a completion (see rejectHistorical()) — UNLESS
            // completion_source has since flipped to 'attendance': upsert()
            // never resets historical_verification_status, so a step that is
            // genuinely attendance-complete can still carry a stale 'rejected'
            // tag from an earlier historical review that no longer matters.
            if ($r && $r['completion_source'] === 'historical' && $r['historical_verification_status'] === 'rejected') $r = null;
            $out[$sid] = [
                'step'         => $step,
                'completed'    => (bool)$r,
                'source'       => $r ? $r['completion_source'] : null,
                'completed_at' => $r ? $r['completed_at'] : null,
                'notes'        => $r ? $r['notes'] : null,
                'verified_at'  => $r ? $r['verified_at'] : null,
                'historical_verification_status' => $r ? $r['historical_verification_status'] : null,
            ];
        }
        return $out;
    }

    /** Completed-member counts per step, from the derived state. */
    public function getStepCounts(bool $activeMembersOnly = false): array {
        $out = [];
        try {
            $sql = "SELECT ds.column_key, md.completion_source, COUNT(*) AS n
                      FROM member_discipleship md
                      JOIN discipleship_steps ds ON ds.id = md.step_id
                      JOIN members m ON m.id = md.member_id
                     WHERE m.is_deleted = 0
                       AND NOT (md.completion_source = 'historical' AND md.historical_verification_status = 'rejected')";
            if ($activeMembersOnly) $sql .= " AND m.member_status = 'active'";
            $sql .= " GROUP BY ds.column_key, md.completion_source";
            foreach ($this->db->query($sql)->fetchAll() as $r) {
                $k = $r['column_key'];
                if (!isset($out[$k])) $out[$k] = ['total' => 0, 'attendance' => 0, 'historical' => 0];
                $out[$k]['total'] += (int)$r['n'];
                $out[$k][$r['completion_source']] = (int)$r['n'];
            }
        } catch (PDOException $e) {
            error_log("getStepCounts error: " . $e->getMessage());
        }
        return $out;
    }

    // ── Historical completions (admin workflow) ─────────────────────────────

    /** id + current review status for one member+step row, or null if none exists. */
    private function reviewRowMeta(int $memberId, int $stepId): ?array {
        try {
            $stmt = $this->db->prepare(
                "SELECT id, historical_verification_status FROM member_discipleship
                  WHERE member_id = ? AND step_id = ? LIMIT 1"
            );
            $stmt->execute([$memberId, $stepId]);
            $r = $stmt->fetch();
            return $r ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Appends one row to the review audit trail. Never updates or deletes an
     * existing row — this is the only way a prior reviewer's decision (who,
     * when, why) survives a later Reopen/Restore overwriting the CURRENT
     * verified_by/verified_at/notes on member_discipleship.
     */
    private function logReview(int $mdId, string $action, string $from, string $to,
                               ?string $notes, ?int $performedBy): void {
        try {
            $this->db->prepare(
                "INSERT INTO historical_completion_reviews
                    (member_discipleship_id, action, from_status, to_status, notes, performed_by)
                 VALUES (?,?,?,?,?,?)"
            )->execute([$mdId, $action, $from, $to, $notes, $performedBy ?: null]);
        } catch (PDOException $e) {
            error_log("logReview error: " . $e->getMessage());
        }
    }

    /**
     * Approves a historical completion. One row per member+step, enforced by
     * the existing uq_member_step unique key, so this can't duplicate.
     *
     * Marked 'verified' immediately, not 'pending': unlike the migration's bulk
     * preservation (no admin judgment involved), this is a live admin filling
     * out the Add Historical Completion form right now, under their own
     * account — the review the verification status exists to capture has
     * already happened.
     *
     * @return array{ok:bool,error:string}
     */
    public function setHistorical(int $memberId, int $stepId, ?string $completedAt,
                                  ?string $notes, ?int $verifiedBy): array {
        if ($memberId <= 0 || $stepId <= 0) return ['ok' => false, 'error' => 'Member and step are required.'];
        $completedAt = trim((string)$completedAt);
        $completedAt = $completedAt !== '' ? $completedAt : null;   // never invent a date
        try {
            $this->db->prepare(
                "INSERT INTO member_discipleship
                    (member_id, step_id, status, completion_source, historical_approved,
                     historical_verification_status, source_record_id, notes, verified_by,
                     verified_at, completed_at)
                 VALUES (?,?, 'completed', 'historical', 1, 'verified', NULL, ?, ?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE
                    historical_approved             = 1,
                    historical_verification_status  = 'verified',
                    notes               = VALUES(notes),
                    verified_by         = VALUES(verified_by),
                    verified_at         = VALUES(verified_at),
                    completed_at        = VALUES(completed_at)"
            )->execute([$memberId, $stepId, ($notes !== null && trim($notes) !== '') ? trim($notes) : null,
                        $verifiedBy ?: null, $completedAt]);
            // Re-derive: if attendance also exists the row is upgraded back to
            // `attendance`, which is correct — evidence outranks a manual note.
            $this->recalculateMember($memberId);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("setHistorical error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the historical completion.'];
        }
    }

    /**
     * Confirms a PENDING historical completion. The member stays completed
     * with historical_approved untouched — verifying is a review of an
     * already-counted completion, not a new grant of one.
     *
     * Only valid from 'pending' — a rejected row must be restored first
     * (restoreHistorical) before it can be verified; it can never jump
     * straight from rejected to verified.
     *
     * @return array{ok:bool,error:string}
     */
    public function verifyHistorical(int $memberId, int $stepId, ?string $notes, ?int $verifiedBy): array {
        if ($memberId <= 0 || $stepId <= 0) return ['ok' => false, 'error' => 'Member and step are required.'];
        $meta = $this->reviewRowMeta($memberId, $stepId);
        if (!$meta) return ['ok' => false, 'error' => 'No historical completion found for this member and step.'];
        if ($meta['historical_verification_status'] !== 'pending') {
            return ['ok' => false, 'error' => 'Only a pending completion can be verified.'];
        }
        $notes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;
        try {
            $this->db->prepare(
                "UPDATE member_discipleship
                    SET historical_approved = 1,
                        historical_verification_status = 'verified',
                        verified_by = ?, verified_at = NOW(),
                        notes = COALESCE(?, notes)
                  WHERE id = ?"
            )->execute([$verifiedBy ?: null, $notes, $meta['id']]);
            $this->logReview((int)$meta['id'], 'verified', 'pending', 'verified', $notes, $verifiedBy);
            $this->recalculateMember($memberId);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("verifyHistorical error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not verify the historical completion.'];
        }
    }

    /**
     * Rejects a historical completion — valid from 'pending' OR 'verified',
     * i.e. an admin can reject a completion they (or someone else) verified
     * earlier if new information surfaces. Not valid from 'rejected' (already
     * rejected; nothing to do).
     *
     * The row is kept — not deleted — with historical_approved cleared so the
     * step reverts to whatever attendance evidence says, while verified_by /
     * verified_at / notes are overwritten with WHO rejected it, WHEN, and why.
     * The PRIOR decision (e.g. the earlier verification) is preserved in
     * historical_completion_reviews, not lost.
     *
     * recalculateMember() then either keeps the step complete (Source:
     * Attendance, if valid evidence exists) or drops it to incomplete (if not)
     * — historicalRow() already excludes rejected rows from counting.
     */
    public function rejectHistorical(int $memberId, int $stepId, ?string $notes, ?int $rejectedBy): array {
        if ($memberId <= 0 || $stepId <= 0) return ['ok' => false, 'error' => 'Member and step are required.'];
        $meta = $this->reviewRowMeta($memberId, $stepId);
        if (!$meta) return ['ok' => false, 'error' => 'No historical completion found for this member and step.'];
        $from = $meta['historical_verification_status'];
        if ($from === 'rejected') {
            return ['ok' => false, 'error' => 'This completion is already rejected.'];
        }
        $notes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;
        try {
            $this->db->prepare(
                "UPDATE member_discipleship
                    SET historical_approved = 0,
                        historical_verification_status = 'rejected',
                        verified_by = ?, verified_at = NOW(),
                        notes = COALESCE(?, notes)
                  WHERE id = ?"
            )->execute([$rejectedBy ?: null, $notes, $meta['id']]);
            $this->logReview((int)$meta['id'], 'rejected', $from, 'rejected', $notes, $rejectedBy);
            $this->recalculateMember($memberId);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("rejectHistorical error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not reject the historical completion.'];
        }
    }

    /**
     * Reopen for Review: verified -> pending. Does NOT touch historical_approved
     * (stays 1) so the completion keeps counting while it awaits a fresh
     * decision — only the review status resets, deliberately NOT to 'verified'
     * again, so an admin must make a new explicit call.
     */
    public function reopenHistorical(int $memberId, int $stepId, ?string $notes, ?int $performedBy): array {
        if ($memberId <= 0 || $stepId <= 0) return ['ok' => false, 'error' => 'Member and step are required.'];
        $meta = $this->reviewRowMeta($memberId, $stepId);
        if (!$meta) return ['ok' => false, 'error' => 'No historical completion found for this member and step.'];
        if ($meta['historical_verification_status'] !== 'verified') {
            return ['ok' => false, 'error' => 'Only a verified completion can be reopened for review.'];
        }
        $notes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;
        try {
            $this->db->prepare(
                "UPDATE member_discipleship
                    SET historical_approved = 1,
                        historical_verification_status = 'pending',
                        verified_by = ?, verified_at = NOW(),
                        notes = COALESCE(?, notes)
                  WHERE id = ?"
            )->execute([$performedBy ?: null, $notes, $meta['id']]);
            $this->logReview((int)$meta['id'], 'reopened', 'verified', 'pending', $notes, $performedBy);
            $this->recalculateMember($memberId);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("reopenHistorical error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not reopen the historical completion.'];
        }
    }

    /**
     * Restore for Review: rejected -> pending. Re-sets historical_approved = 1
     * so the completion counts again while awaiting review, deliberately NOT
     * restored straight to 'verified' — an admin must re-verify explicitly.
     */
    public function restoreHistorical(int $memberId, int $stepId, ?string $notes, ?int $performedBy): array {
        if ($memberId <= 0 || $stepId <= 0) return ['ok' => false, 'error' => 'Member and step are required.'];
        $meta = $this->reviewRowMeta($memberId, $stepId);
        if (!$meta) return ['ok' => false, 'error' => 'No historical completion found for this member and step.'];
        if ($meta['historical_verification_status'] !== 'rejected') {
            return ['ok' => false, 'error' => 'Only a rejected completion can be restored for review.'];
        }
        $notes = ($notes !== null && trim($notes) !== '') ? trim($notes) : null;
        try {
            $this->db->prepare(
                "UPDATE member_discipleship
                    SET historical_approved = 1,
                        historical_verification_status = 'pending',
                        verified_by = ?, verified_at = NOW(),
                        notes = COALESCE(?, notes)
                  WHERE id = ?"
            )->execute([$performedBy ?: null, $notes, $meta['id']]);
            $this->logReview((int)$meta['id'], 'restored', 'rejected', 'pending', $notes, $performedBy);
            $this->recalculateMember($memberId);
            return ['ok' => true, 'error' => ''];
        } catch (PDOException $e) {
            error_log("restoreHistorical error: " . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not restore the historical completion.'];
        }
    }

    /**
     * Full review history for one member_discipleship row, newest first, for
     * the Review History modal. Joins accounts for a display name only —
     * no technical/internal fields are exposed.
     */
    public function reviewHistory(int $memberId, int $stepId): array {
        try {
            $stmt = $this->db->prepare(
                "SELECT hcr.action, hcr.from_status, hcr.to_status, hcr.notes, hcr.performed_at,
                        a.username AS performed_by_name
                   FROM historical_completion_reviews hcr
                   JOIN member_discipleship md ON md.id = hcr.member_discipleship_id
                   LEFT JOIN accounts a ON a.id = hcr.performed_by
                  WHERE md.member_id = ? AND md.step_id = ?
                  ORDER BY hcr.performed_at DESC, hcr.id DESC"
            );
            $stmt->execute([$memberId, $stepId]);
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("reviewHistory error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * All historical completions (pending, verified, AND rejected), for the
     * admin review list. Rejected rows have historical_approved = 0 — they no
     * longer count as a completion — but still need to show up here with an
     * audit trail, so this reads by historical_verification_status instead of
     * historical_approved.
     *
     * Each row also carries `has_other_evidence` — whether current
     * attendance/class evidence (NOT this historical row) would still satisfy
     * the step on its own. The UI uses this to tell the admin, before they
     * reject the review, whether the member will stay completed or drop to
     * incomplete.
     *
     * @param array{status?:string,step_id?:int,q?:string} $filters
     */
    public function listHistorical(array $filters = []): array {
        try {
            $sql = "SELECT md.*, m.full_name, m.member_status, ds.name AS step_name, ds.abbreviation,
                           ds.color AS step_color, ds.column_key,
                           a.username AS verified_by_name
                      FROM member_discipleship md
                      JOIN members m  ON m.id  = md.member_id
                      JOIN discipleship_steps ds ON ds.id = md.step_id
                      LEFT JOIN accounts a ON a.id = md.verified_by
                     WHERE (md.historical_approved = 1 OR md.historical_verification_status = 'rejected')
                       AND m.is_deleted = 0";
            $params = [];

            $status = $filters['status'] ?? '';
            if (in_array($status, ['pending', 'verified', 'rejected'], true)) {
                $sql .= " AND md.historical_verification_status = ?";
                $params[] = $status;
            }
            $stepId = (int)($filters['step_id'] ?? 0);
            if ($stepId > 0) {
                $sql .= " AND md.step_id = ?";
                $params[] = $stepId;
            }
            $q = trim((string)($filters['q'] ?? ''));
            if ($q !== '') {
                $sql .= " AND m.full_name LIKE ?";
                $params[] = '%' . $q . '%';
            }

            // Pending first so unreviewed records surface by default.
            $sql .= " ORDER BY FIELD(md.historical_verification_status, 'pending', 'verified', 'rejected'),
                      m.full_name ASC, ds.sort_order ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
            foreach ($rows as &$r) {
                $r['has_other_evidence'] = $this->attendanceEvidence((int)$r['member_id'], (string)$r['column_key']) !== null;
            }
            unset($r);
            return $rows;
        } catch (PDOException $e) {
            error_log("listHistorical error: " . $e->getMessage());
            return [];
        }
    }

    /** Needs Verification / Verified / Total Historical counts for the summary cards. */
    public function historicalCounts(): array {
        $out = ['pending' => 0, 'verified' => 0, 'rejected' => 0];
        try {
            foreach ($this->db->query(
                "SELECT historical_verification_status, COUNT(*) AS n
                   FROM member_discipleship md JOIN members m ON m.id = md.member_id
                  WHERE (md.historical_approved = 1 OR md.historical_verification_status = 'rejected')
                    AND m.is_deleted = 0
                  GROUP BY historical_verification_status"
            )->fetchAll() as $r) {
                $out[$r['historical_verification_status']] = (int)$r['n'];
            }
        } catch (PDOException $e) {
            error_log("historicalCounts error: " . $e->getMessage());
        }
        // Rejected is excluded from "active historical completion" by definition.
        $out['total'] = $out['pending'] + $out['verified'];
        return $out;
    }

    // ── Private writers ─────────────────────────────────────────────────────

    private function upsert(int $memberId, int $stepId, string $source, ?int $recordId,
                            ?string $notes, $verifiedBy, ?string $verifiedAt,
                            int $historicalApproved = 0, ?string $completedAt = null): void {
        try {
            $this->db->prepare(
                "INSERT INTO member_discipleship
                    (member_id, step_id, status, completion_source, historical_approved,
                     source_record_id, notes, verified_by, verified_at, completed_at)
                 VALUES (?,?, 'completed', ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    completion_source   = VALUES(completion_source),
                    historical_approved = VALUES(historical_approved),
                    source_record_id    = VALUES(source_record_id),
                    notes               = VALUES(notes),
                    verified_by         = VALUES(verified_by),
                    verified_at         = VALUES(verified_at),
                    completed_at        = VALUES(completed_at)"
            )->execute([$memberId, $stepId, $source, $historicalApproved, $recordId, $notes,
                        $verifiedBy ?: null, $verifiedAt, $completedAt]);
        } catch (PDOException $e) {
            error_log("DiscipleshipProgressService::upsert error: " . $e->getMessage());
        }
    }

    /**
     * Removes the row only when no admin has approved it as a legacy
     * completion AND it was never rejected. A rejected row also has
     * historical_approved = 0 (that's what drops the step to incomplete), but
     * it must survive every future recalculation — deleting it here would
     * silently erase the review's audit trail the first time this member's
     * progress is ever recalculated again.
     */
    private function deleteUnapprovedRow(int $memberId, int $stepId): void {
        try {
            $this->db->prepare(
                "DELETE FROM member_discipleship
                  WHERE member_id = ? AND step_id = ? AND historical_approved = 0
                    AND historical_verification_status != 'rejected'"
            )->execute([$memberId, $stepId]);
        } catch (PDOException $e) {
            error_log("DiscipleshipProgressService::deleteUnapprovedRow error: " . $e->getMessage());
        }
    }
}
?>
