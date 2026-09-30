<?php
require_once 'models/ProgramAttendance.php';
require_once 'models/SfTopic.php';

/**
 * Cumulative Spiritual Foundations progress.
 *
 * A participant's SF completion is the union of every SF batch they appear in,
 * not the result of any single batch: a topic counts as completed once they have
 * a Present/Late for it in ANY batch. Someone who missed weeks 7-9 in Batch 1
 * and caught them up in Batch 2 is complete, and Batch 1's history is never
 * rewritten to make that true.
 *
 * Nothing here is stored. Every figure — including certificate eligibility — is
 * recomputed from the attendance rows on each request, for every participant.
 */
class SfProgress {
    const PROGRAM_TYPE = 'spiritual_foundations';

    private $db;
    private SfTopic $topics;

    /** participantKey => progress array (lazily built once per request). */
    private ?array $cache = null;

    public function __construct($db, ?SfTopic $topics = null) {
        $this->db     = $db;
        $this->topics = $topics ?: new SfTopic($db);
    }

    /**
     * Stable identity for a participant across batches: the linked member when
     * there is one, otherwise the normalised display name. Same convention
     * ProgramAttendance::getMemberStats() already uses for de-duping.
     */
    public static function participantKey(array $rec): string {
        return !empty($rec['member_id'])
            ? 'm_' . (int)$rec['member_id']
            : 'u_' . strtolower(trim((string)($rec['full_name_display'] ?? '')));
    }

    /**
     * Maps one attendance record's session grid onto curriculum week numbers.
     *
     * Records saved by the current form carry an explicit `weeks` map, so a
     * later curriculum edit can never re-point historical rows at a different
     * topic. Older rows only have the date-keyed `sessions` list, so those fall
     * back to position (1st row = week 1, …) which is how they were entered.
     *
     * @return array<int,array{status:string,key:string}> week_no => entry
     */
    public static function weekStatuses($extraData, array $weekNos): array {
        $ed = is_string($extraData) ? json_decode($extraData, true) : $extraData;
        if (!is_array($ed)) return [];
        $sessions = (!empty($ed['sessions']) && is_array($ed['sessions'])) ? $ed['sessions'] : [];

        $out = [];
        if (!empty($ed['weeks']) && is_array($ed['weeks'])) {
            $dates = array_keys($sessions);
            $i = 0;
            foreach ($ed['weeks'] as $wk => $status) {
                $out[(int)$wk] = ['status' => (string)$status, 'key' => (string)($dates[$i] ?? '')];
                $i++;
            }
            ksort($out);
            return $out;
        }
        // Legacy / positional fallback.
        $i = 0;
        foreach ($sessions as $date => $status) {
            $wk = $weekNos[$i] ?? null;
            if ($wk !== null) $out[(int)$wk] = ['status' => (string)$status, 'key' => (string)$date];
            $i++;
        }
        ksort($out);
        return $out;
    }

    /** Per-record (single batch) roll-up: completed / required within that batch. */
    public static function batchStats(array $weekStatuses, array $requiredWeekNos): array {
        $completed = 0; $absent = 0; $notRequired = 0; $missed = [];
        foreach ($requiredWeekNos as $wk) {
            if (!isset($weekStatuses[$wk])) continue;         // not on this record's grid
            $s = $weekStatuses[$wk]['status'];
            if (ProgramAttendance::isNotEnrolledStatus($s))      { $notRequired++; continue; }
            if (ProgramAttendance::isClassCancelledStatus($s))   { continue; }
            if (ProgramAttendance::isAttendedStatus($s))         { $completed++; }
            else { $missed[] = $wk; if (strtoupper(trim($s)) === 'A') $absent++; }
        }
        $required = count($requiredWeekNos);
        return [
            'completed'    => $completed,
            'required'     => $required,
            'absent'       => $absent,
            'not_required' => $notRequired,
            'missed'       => $missed,
            'percent'      => $required > 0 ? (int)round($completed / $required * 100) : 0,
        ];
    }

    /**
     * Builds cumulative progress for every SF participant.
     * Deliberately ignores the page's filters — overall completion must consider
     * all of a person's batches even when the table is showing only one.
     */
    public function all(): array {
        if ($this->cache !== null) return $this->cache;

        $requiredWeeks = array_keys($this->topics->getRequiredWeeks());
        $allWeekNos    = array_keys($this->topics->getClassWeeks(false)); // incl. inactive, for legacy position mapping
        sort($requiredWeeks); sort($allWeekNos);

        $rows = [];
        try {
            $stmt = $this->db->prepare(
                "SELECT pa.id, pa.member_id, pa.full_name_display, pa.batch_label, pa.program_year, pa.extra_data
                   FROM program_attendances pa
                  WHERE pa.program_type = ? AND pa.is_deleted = 0 AND pa.status = 'active'
                  ORDER BY pa.program_year, pa.batch_label, pa.id"
            );
            $stmt->execute([self::PROGRAM_TYPE]);
            $rows = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("SfProgress::all error: " . $e->getMessage());
        }

        $out = [];
        foreach ($rows as $rec) {
            $key = self::participantKey($rec);
            if (!isset($out[$key])) {
                $out[$key] = [
                    'name'      => $rec['full_name_display'],
                    'member_id' => $rec['member_id'] ? (int)$rec['member_id'] : null,
                    'weeks'     => [],   // week_no => ['completed'=>bool, 'batches'=>[label=>status]]
                    'batches'   => [],   // batch label => batch roll-up
                    'required'  => count($requiredWeeks),
                ];
                foreach ($requiredWeeks as $wk) {
                    $out[$key]['weeks'][$wk] = ['completed' => false, 'batches' => []];
                }
            }

            $weekStatuses = self::weekStatuses($rec['extra_data'], $allWeekNos);
            $batchLabel   = trim((string)($rec['batch_label'] ?? '')) ?: ('Year ' . (int)$rec['program_year']);

            foreach ($weekStatuses as $wk => $entry) {
                if (!isset($out[$key]['weeks'][$wk])) continue;   // not a currently-required week
                $out[$key]['weeks'][$wk]['batches'][$batchLabel] = $entry['status'];
                // Completion is a one-way latch: a P/L in ANY batch completes the
                // topic, and a later A in another batch cannot un-complete it.
                if (ProgramAttendance::isAttendedStatus($entry['status'])) {
                    $out[$key]['weeks'][$wk]['completed'] = true;
                }
            }

            $out[$key]['batches'][$batchLabel] = self::batchStats($weekStatuses, $requiredWeeks)
                + ['record_id' => (int)$rec['id'], 'year' => (int)$rec['program_year']];
        }

        // Final per-participant totals.
        foreach ($out as $key => &$p) {
            $completed = 0; $missing = [];
            foreach ($p['weeks'] as $wk => $w) {
                if ($w['completed']) $completed++; else $missing[] = $wk;
            }
            $p['completed'] = $completed;
            $p['missing']   = $missing;
            $p['percent']   = $p['required'] > 0 ? (int)round($completed / $p['required'] * 100) : 0;
            $p['eligible']  = ($p['required'] > 0 && $completed >= $p['required']);
        }
        unset($p);

        return $this->cache = $out;
    }

    /** Cumulative progress for one attendance row's participant. */
    public function forRecord(array $rec): array {
        $all = $this->all();
        $key = self::participantKey($rec);
        return $all[$key] ?? [
            'name' => $rec['full_name_display'] ?? '', 'member_id' => null,
            'weeks' => [], 'batches' => [], 'required' => $this->topics->getRequiredWeekCount(),
            'completed' => 0, 'missing' => [], 'percent' => 0, 'eligible' => false,
        ];
    }

    /** Human-readable reason the certificate is locked (empty when eligible). */
    public function lockReason(array $progress, array $weekTopics): string {
        if (!empty($progress['eligible'])) return '';
        if (empty($progress['missing'])) return 'No Spiritual Foundations attendance recorded yet.';
        $names = [];
        foreach ($progress['missing'] as $wk) {
            $names[] = 'Week ' . $wk . (isset($weekTopics[$wk]) ? ' (' . $weekTopics[$wk]['topic'] . ')' : '');
        }
        return count($progress['missing']) . ' of ' . $progress['required']
             . ' topics still to complete: ' . implode(', ', $names);
    }
}
?>
