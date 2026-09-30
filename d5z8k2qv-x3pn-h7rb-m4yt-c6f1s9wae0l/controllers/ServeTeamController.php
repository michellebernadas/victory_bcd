<?php
require_once 'models/ServeTeam.php';
require_once 'models/ServeOption.php';
require_once 'models/Member.php';
require_once 'models/Ministry.php';
require_once 'models/ServiceSchedule.php';

class ServeTeamController {
    private $db;
    private $teamModel;
    private $optionModel;
    private $memberModel;

    /** Day list shared by the team form and the option editor. */
    const DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    public function __construct($db) {
        $this->db          = $db;
        $this->teamModel   = new ServeTeam($db);
        $this->optionModel = new ServeOption($db);
        $this->memberModel = new Member($db);
    }

    public function listTeams(): void {
        $filters = [];
        if (!empty($_GET['ministry']))     $filters['ministry']     = sanitizeInput($_GET['ministry']);
        if (!empty($_GET['team_status']))  $filters['team_status']  = sanitizeInput($_GET['team_status']);
        if (!empty($_GET['service_name'])) $filters['service_name'] = sanitizeInput($_GET['service_name']);

        $teams           = $this->teamModel->getAll($filters);
        $teamStats       = $this->teamModel->getStats();
        $ministries      = $this->memberModel->getAllMinistries();
        $serveOptions    = $this->optionModel->getAllGrouped();          // active only, for the form
        $serveOptionsAll = $this->optionModel->getAllGrouped(false);     // incl. inactive, for the editor
        $serviceDefaults = $this->optionModel->getServiceDefaults();     // drives Day/Time auto-fill
        $optionUsage     = $this->optionModel->getUsageMap();
        $DAYS            = self::DAYS;
        $activeFilters   = $filters;
        $isAdmin         = $this->isAdmin();
        $activeTab       = in_array($_GET['tab'] ?? '', ['teams', 'options'], true) ? $_GET['tab'] : 'teams';
        include 'views/serve_teams.php';
    }

    public function addTeam(array $data): void {
        [$leaders, $members] = $this->parseMembersFromPost();
        if ($err = $this->teamModel->validate($data, $leaders, $members)) $this->redirectError($err);
        $result = $this->teamModel->add($data, $leaders, $members);
        $this->redirect($result ? 'add' : 'error');
    }

    public function updateTeam(int $id, array $data): void {
        [$leaders, $members] = $this->parseMembersFromPost();
        if ($err = $this->teamModel->validate($data, $leaders, $members)) $this->redirectError($err);
        $result = $this->teamModel->update($id, $data, $leaders, $members);
        $this->redirect($result ? 'update' : 'error');
    }

    public function activateTeam(int $id): void {
        $this->redirect($this->teamModel->setStatus($id, 'active') ? 'activate' : 'error');
    }

    public function deactivateTeam(int $id): void {
        $this->redirect($this->teamModel->setStatus($id, 'inactive') ? 'deactivate' : 'error');
    }

    public function deleteTeam(int $id): void {
        $this->redirect($this->teamModel->delete($id) ? 'delete' : 'error');
    }

    // ── Service / Place dropdown values (admin only, like Settings) ──────────

    private function isAdmin(): bool {
        return ($_SESSION['user']['accounttype'] ?? '') === 'admin';
    }

    private function requireAdmin(): void {
        if (!$this->isAdmin()) {
            $this->redirectError('Only administrators can change the Service and Place lists.', 'options');
        }
    }

    public function addOption(array $data): void {
        $this->requireAdmin();
        $res = $this->optionModel->add($data);
        $res['ok'] ? $this->redirect('opt_add', 'options') : $this->redirectError($res['error'], 'options');
    }

    public function updateOption(int $id, array $data): void {
        $this->requireAdmin();
        $res = $this->optionModel->update($id, $data);
        $res['ok'] ? $this->redirect('opt_update', 'options') : $this->redirectError($res['error'], 'options');
    }

    public function activateOption(int $id): void {
        $this->requireAdmin();
        $res = $this->optionModel->setActive($id, true);
        $res['ok'] ? $this->redirect('opt_activate', 'options') : $this->redirectError($res['error'], 'options');
    }

    public function deactivateOption(int $id): void {
        $this->requireAdmin();
        $res = $this->optionModel->setActive($id, false);
        $res['ok'] ? $this->redirect('opt_deactivate', 'options') : $this->redirectError($res['error'], 'options');
    }

    public function deleteOption(int $id): void {
        $this->requireAdmin();
        $res = $this->optionModel->delete($id);
        if (!$res['ok']) $this->redirectError($res['error'], 'options');
        $this->redirect($res['mode'] === 'archived' ? 'opt_archived' : 'opt_delete', 'options');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Reads the leader / member Select2 multi-selects. Each option value is
     * either a members.id (registered person) or a raw typed name (Select2 tags).
     *
     * @return array{0:array,1:array}
     */
    private function parseMembersFromPost(): array {
        return [
            $this->resolvePeople($_POST['leader_ids'] ?? []),
            $this->resolvePeople($_POST['member_ids'] ?? []),
        ];
    }

    private function resolvePeople($values): array {
        if (!is_array($values)) $values = [$values];
        $ids = [];
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v !== '' && ctype_digit($v)) $ids[] = (int)$v;
        }
        // One lookup for every selected member id so the stored name matches members.full_name.
        $names = [];
        if ($ids) {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare("SELECT id, full_name FROM members WHERE id IN ($ph)");
            $stmt->execute($ids);
            $names = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        $out = [];
        foreach ($values as $v) {
            $v = trim((string)$v);
            if ($v === '') continue;
            if (ctype_digit($v) && isset($names[$v])) {
                $out[] = ['member_id' => (int)$v, 'name' => $names[$v]];
            } else {
                // Typed-in name (Select2 tag) — stored unlinked, same as vg_members does.
                $out[] = ['member_id' => null, 'name' => $v];
            }
        }
        return $out;
    }

    private function redirect(string $notif, string $tab = 'teams'): void {
        header('Location: index.php?action=serveTeams&tab=' . $tab . '&notif=' . $notif);
        exit();
    }

    private function redirectError(string $msg, string $tab = 'teams'): void {
        header('Location: index.php?action=serveTeams&tab=' . $tab . '&error=1&msg=' . urlencode($msg));
        exit();
    }
}
?>
