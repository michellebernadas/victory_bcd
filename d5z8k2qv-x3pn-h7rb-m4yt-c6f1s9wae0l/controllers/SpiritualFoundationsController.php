<?php
require_once 'controllers/SessionClassController.php';
require_once 'models/SfTopic.php';
require_once 'models/SfProgress.php';

/**
 * Spiritual Foundations — same session-grid structure as Leadership 1-1-3,
 * plus its own curriculum (sf_topics) and cumulative cross-batch completion.
 */
class SpiritualFoundationsController extends SessionClassController {
    protected string $programType = 'spiritual_foundations';
    protected string $route       = 'spiritualFoundations';
    protected string $label       = 'Spiritual Foundations';
    protected string $view        = 'views/spiritual_foundations.php';

    private SfTopic $topicModel;
    private SfProgress $progressModel;

    public function __construct($db) {
        parent::__construct($db);
        $this->topicModel    = new SfTopic($db);
        $this->progressModel = new SfProgress($db, $this->topicModel);
        // The curriculum decides the program length — never a hard-coded number.
        $this->expectedSessions = $this->topicModel->getRequiredWeekCount();
    }

    protected function extraViewData(): array {
        return [
            'sfTopics'        => $this->topicModel->getAll(false),        // incl. inactive, for the curriculum tab
            'sfWeeks'         => $this->topicModel->getClassWeeks(),      // active class weeks: week_no => row
            'sfRequiredWeeks' => $this->topicModel->getRequiredWeeks(),
            'sfWeekCount'     => $this->topicModel->getWeekCount(),
            'sfUsage'         => $this->topicModel->getUsageMap(),
            'sfHasAttendance' => $this->topicModel->hasAnyAttendance(),
            'sfBreakRow'      => $this->topicModel->getBreakRow(),
            'sfProgress'      => $this->progressModel->all(),             // cumulative, all batches
            'sfProgressModel' => $this->progressModel,
            'scExpectedCount' => $this->expectedSessions,
            'sfIsAdmin'       => $this->isAdmin(),
        ];
    }

    // ── Attendance validation ───────────────────────────────────────────────

    /**
     * Spiritual Foundations participant statuses are Present, Absent, Late and
     * NC (not required of this participant — e.g. they already completed that
     * topic in an earlier batch).
     *
     * Class-level cancellations (NO CLASS / Holy Week / DC) describe the SESSION,
     * not one person, so they stay out of participant rows: a cancelled week is
     * the curriculum's No Class break. Rejecting a hand-typed one is safer than
     * accepting it, which would quietly shrink that person's required topics.
     *
     * Also records an explicit week_no → status map so a later curriculum edit
     * can never re-point historical rows at a different topic.
     */
    protected function buildSessionExtraData(array $data, ?int $existingId = null): array {
        $dates    = $data['session_dates']    ?? [];
        $statuses = $data['session_statuses'] ?? [];
        $weeks    = $data['session_weeks']    ?? [];

        $weekMap = [];
        if (is_array($dates)) {
            foreach ($dates as $i => $d) {
                if (trim((string)$d) === '') continue;              // undated row = not recorded
                $status = trim((string)($statuses[$i] ?? ''));
                if (!ProgramAttendance::isParticipantStatus($status)) $this->rejectStatus($status);
                $wk = (int)($weeks[$i] ?? 0);
                if ($wk > 0) $weekMap[$wk] = $status;
            }
        }

        $data = parent::buildSessionExtraData($data, $existingId);

        // Merge the week map into the JSON the parent just built.
        if (!empty($data['l113_extra']) && $weekMap) {
            $extra = json_decode($data['l113_extra'], true) ?: [];
            ksort($weekMap);
            $extra['weeks'] = $weekMap;
            $data['l113_extra'] = json_encode($extra);
        }
        unset($data['session_weeks']);
        return $data;
    }

    private function rejectStatus(string $status): void {
        $shown = $status === '' ? '(blank)' : $status;
        $msg = ProgramAttendance::isClassCancelledStatus($status)
            ? sprintf('"%s" is a class-level state, not a participant status. A cancelled session applies to the '
                    . 'whole batch — record it as the No Class break in the curriculum instead. Use Present (P), '
                    . 'Absent (A), Late (L), or NC if the topic is not required of this participant.', $shown)
            : sprintf('"%s" is not a valid attendance status. Use Present (P), Absent (A), Late (L) or NC.', $shown);
        $this->fail($msg);
    }

    // ── Curriculum CRUD (admin only, same rule as Settings) ─────────────────

    private function isAdmin(): bool {
        return ($_SESSION['user']['accounttype'] ?? '') === 'admin';
    }

    /** Curriculum changes are admin-managed settings, like Ministries/Services. */
    private function requireAdmin(): void {
        if (!$this->isAdmin()) {
            $this->fail('Only administrators can change the Spiritual Foundations curriculum.', 'curriculum');
        }
    }

    public function addTopic(array $data): void {
        $this->requireAdmin();
        $res = $this->topicModel->add($data);
        $res['ok'] ? $this->done('topic_add') : $this->fail($res['error'], 'curriculum');
    }

    public function updateTopic(int $id, array $data): void {
        $this->requireAdmin();
        $res = $this->topicModel->update($id, $data);
        $res['ok'] ? $this->done('topic_update') : $this->fail($res['error'], 'curriculum');
    }

    public function activateTopic(int $id): void {
        $this->requireAdmin();
        $res = $this->topicModel->setActive($id, true);
        $res['ok'] ? $this->done('topic_activate') : $this->fail($res['error'], 'curriculum');
    }

    public function deactivateTopic(int $id): void {
        $this->requireAdmin();
        $res = $this->topicModel->setActive($id, false);
        $res['ok'] ? $this->done('topic_deactivate') : $this->fail($res['error'], 'curriculum');
    }

    public function deleteTopic(int $id): void {
        $this->requireAdmin();
        $res = $this->topicModel->delete($id);
        if (!$res['ok']) $this->fail($res['error'], 'curriculum');
        // Archived vs truly deleted produces a different confirmation message.
        $this->done($res['mode'] === 'archived' ? 'topic_archived' : 'topic_delete');
    }

    public function reorderTopics(array $ids): void {
        if (!$this->isAdmin()) { http_response_code(403); exit(); }
        $res = $this->topicModel->reorder(array_map('intval', $ids));
        header('Content-Type: application/json');
        echo json_encode($res);
        exit();
    }

    // ── Redirect helpers ────────────────────────────────────────────────────

    private function done(string $notif, string $tab = 'curriculum'): void {
        header('Location: index.php?action=spiritualFoundations&tab=' . $tab . '&notif=' . $notif);
        exit();
    }

    private function fail(string $msg, string $tab = 'records'): void {
        header('Location: index.php?action=spiritualFoundations&tab=' . $tab
             . '&error=1&msg=' . urlencode($msg));
        exit();
    }
}
?>
