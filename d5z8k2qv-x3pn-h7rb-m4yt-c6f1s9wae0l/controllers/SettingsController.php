<?php
require_once 'models/Ministry.php';
require_once 'models/ServiceSchedule.php';
require_once 'models/DiscipleshipStep.php';
require_once 'models/VgOption.php';

class SettingsController {
    private $db;
    private $ministryModel;
    private $serviceModel;
    private $stepModel;
    private $vgOptionModel;

    public function __construct($db) {
        $this->db = $db;
        $this->ministryModel = new Ministry($db);
        $this->serviceModel  = new ServiceSchedule($db);
        $this->stepModel     = new DiscipleshipStep($db);
        $this->vgOptionModel = new VgOption($db);
    }

    public function showSettings() {
        $ministries = $this->ministryModel->getAllMinistries();
        $services   = $this->serviceModel->getAllServices();
        $steps      = $this->stepModel->getAllSteps();
        $vgOptions = $this->vgOptionModel->getAllGrouped();
        $vgOptionTypes = VgOption::TYPES;

        // Pre-compute usage counts (members / records / groups still referencing each row)
        // Keyed by id for O(1) lookup in the view.
        $ministryCounts = [];
        foreach ($ministries as $m) {
            $info = $this->ministryModel->getUsageInfo((int)$m['id']);
            $ministryCounts[(int)$m['id']] = (int)($info['count'] ?? 0);
        }
        $serviceCounts = [];
        foreach ($services as $s) {
            $info = $this->serviceModel->getUsageInfo((int)$s['id']);
            $serviceCounts[(int)$s['id']] = (int)($info['count'] ?? 0);
        }
        $stepCounts = [];
        foreach ($steps as $st) {
            $info = $this->stepModel->getUsageInfo((int)$st['id']);
            $stepCounts[(int)$st['id']] = (int)($info['count'] ?? 0);
        }
        $vgOptionCounts = [];
        foreach ($vgOptions as $type => $opts) {
            foreach ($opts as $opt) {
                $info = $this->vgOptionModel->getUsageInfo((int)$opt['id']);
                $vgOptionCounts[(int)$opt['id']] = (int)($info['count'] ?? 0);
            }
        }

        include 'views/settings.php';
    }

    // ── Ministries ─────────────────────────────────────────────────────────

    public function addMinistry($name) {
        $name = trim($name);
        if (empty($name)) {
            header('Location: index.php?action=settings&tab=ministries&error=1&msg=' . urlencode('Ministry name is required'));
            exit();
        }
        $result = $this->ministryModel->addMinistry($name);
        if ($result) {
            header('Location: index.php?action=settings&tab=ministries&notif=add');
        } else {
            header('Location: index.php?action=settings&tab=ministries&error=1&msg=' . urlencode('Failed to add ministry. Name may already exist.'));
        }
        exit();
    }

    public function updateMinistry($id, $name) {
        $name = trim($name);
        if (empty($name)) {
            header('Location: index.php?action=settings&tab=ministries&error=1&msg=' . urlencode('Ministry name is required'));
            exit();
        }
        $result = $this->ministryModel->updateMinistry((int)$id, $name);
        if ($result) {
            header('Location: index.php?action=settings&tab=ministries&notif=update');
        } else {
            header('Location: index.php?action=settings&tab=ministries&error=1&msg=' . urlencode('Failed to update ministry.'));
        }
        exit();
    }

    public function deleteMinistry($id) {
        // Block delete if any members are still assigned to this ministry
        $usage = $this->ministryModel->getUsageInfo((int)$id);
        if ($usage['count'] > 0) {
            $msg = sprintf(
                'Cannot delete "%s" — %d member%s still assigned. Reassign them to another ministry first.',
                $usage['name'], $usage['count'], $usage['count'] === 1 ? ' is' : 's are'
            );
            header('Location: index.php?action=settings&tab=ministries&error=1&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->ministryModel->deleteMinistry((int)$id);
        header('Location: index.php?action=settings&tab=ministries&notif=' . ($result ? 'delete' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to delete ministry.')));
        exit();
    }

    public function deactivateMinistry($id) {
        $result = $this->ministryModel->deactivateMinistry((int)$id);
        header('Location: index.php?action=settings&tab=ministries&notif=' . ($result ? 'deactivate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to deactivate ministry.')));
        exit();
    }

    public function activateMinistry($id) {
        $result = $this->ministryModel->activateMinistry((int)$id);
        header('Location: index.php?action=settings&tab=ministries&notif=' . ($result ? 'activate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to activate ministry.')));
        exit();
    }

    // ── Services ───────────────────────────────────────────────────────────

    public function addService($name) {
        $name = trim($name);
        if (empty($name)) {
            header('Location: index.php?action=settings&tab=services&error=1&msg=' . urlencode('Service name is required'));
            exit();
        }
        $result = $this->serviceModel->addService($name);
        if ($result) {
            header('Location: index.php?action=settings&tab=services&notif=add');
        } else {
            header('Location: index.php?action=settings&tab=services&error=1&msg=' . urlencode('Failed to add service. Name may already exist.'));
        }
        exit();
    }

    public function updateService($id, $name) {
        $name = trim($name);
        if (empty($name)) {
            header('Location: index.php?action=settings&tab=services&error=1&msg=' . urlencode('Service name is required'));
            exit();
        }
        $result = $this->serviceModel->updateService((int)$id, $name);
        if ($result) {
            header('Location: index.php?action=settings&tab=services&notif=update');
        } else {
            header('Location: index.php?action=settings&tab=services&error=1&msg=' . urlencode('Failed to update service.'));
        }
        exit();
    }

    public function deleteService($id) {
        $usage = $this->serviceModel->getUsageInfo((int)$id);
        if ($usage['count'] > 0) {
            $msg = sprintf(
                'Cannot delete "%s" — %d member%s still attending this service. Reassign them first.',
                $usage['name'], $usage['count'], $usage['count'] === 1 ? ' is' : 's are'
            );
            header('Location: index.php?action=settings&tab=services&error=1&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->serviceModel->deleteService((int)$id);
        header('Location: index.php?action=settings&tab=services&notif=' . ($result ? 'delete' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to delete service.')));
        exit();
    }

    public function deactivateService($id) {
        $result = $this->serviceModel->deactivateService((int)$id);
        header('Location: index.php?action=settings&tab=services&notif=' . ($result ? 'deactivate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to deactivate service.')));
        exit();
    }

    public function activateService($id) {
        $result = $this->serviceModel->activateService((int)$id);
        header('Location: index.php?action=settings&tab=services&notif=' . ($result ? 'activate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to activate service.')));
        exit();
    }

    // ── Discipleship Steps ─────────────────────────────────────────────────

    public function addDiscipleshipStep($name, $abbr, $icon, $color) {
        $name  = trim($name);
        $abbr  = trim($abbr);
        $icon  = trim($icon)  ?: 'bi-check-circle';
        $color = trim($color) ?: 'primary';
        if (empty($name) || empty($abbr)) {
            header('Location: index.php?action=settings&tab=discipleship&error=1&msg=' . urlencode('Name and abbreviation are required'));
            exit();
        }
        $result = $this->stepModel->addStep($name, $abbr, $icon, $color);
        if ($result) {
            header('Location: index.php?action=settings&tab=discipleship&notif=add');
        } else {
            header('Location: index.php?action=settings&tab=discipleship&error=1&msg=' . urlencode('Failed to add discipleship step.'));
        }
        exit();
    }

    public function updateDiscipleshipStep($id, $name, $abbr, $icon, $color) {
        $name  = trim($name);
        $abbr  = trim($abbr);
        $icon  = trim($icon)  ?: 'bi-check-circle';
        $color = trim($color) ?: 'primary';
        if (empty($name) || empty($abbr)) {
            header('Location: index.php?action=settings&tab=discipleship&error=1&msg=' . urlencode('Name and abbreviation are required'));
            exit();
        }
        $result = $this->stepModel->updateStep((int)$id, $name, $abbr, $icon, $color);
        if ($result) {
            header('Location: index.php?action=settings&tab=discipleship&notif=update');
        } else {
            header('Location: index.php?action=settings&tab=discipleship&error=1&msg=' . urlencode('Failed to update discipleship step.'));
        }
        exit();
    }

    public function deleteDiscipleshipStep($id) {
        $usage = $this->stepModel->getUsageInfo((int)$id);
        if ($usage['count'] > 0) {
            $msg = sprintf(
                'Cannot delete "%s" — %d member%s already completed this step. Records would be lost. Deactivate it instead to hide it from the members form.',
                $usage['name'], $usage['count'], $usage['count'] === 1 ? ' has' : 's have'
            );
            header('Location: index.php?action=settings&tab=discipleship&error=1&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->stepModel->deleteStep((int)$id);
        header('Location: index.php?action=settings&tab=discipleship&notif=' . ($result ? 'delete' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to delete step.')));
        exit();
    }

    public function deactivateDiscipleshipStep($id) {
        $result = $this->stepModel->deactivateStep((int)$id);
        header('Location: index.php?action=settings&tab=discipleship&notif=' . ($result ? 'deactivate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to deactivate step.')));
        exit();
    }

    public function activateDiscipleshipStep($id) {
        $result = $this->stepModel->activateStep((int)$id);
        header('Location: index.php?action=settings&tab=discipleship&notif=' . ($result ? 'activate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to activate step.')));
        exit();
    }

    // ── VG Options ─────────────────────────────────────────────────────────

    public function addVgOption($data) {
        $type  = $data['option_type'] ?? '';
        $value = trim($data['value']  ?? '');
        $label = trim($data['label']  ?? '');
        $sort  = (int)($data['sort_order'] ?? 0);
        if (!$type || !$value || !$label) {
            header('Location: index.php?action=settings&tab=vgoptions&error=1&msg=' . urlencode('All fields required'));
            exit();
        }
        $result = $this->vgOptionModel->add($type, $value, $label, $sort);
        header('Location: index.php?action=settings&tab=vgoptions&notif=' . ($result ? 'add' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed. Value may already exist for this type.')));
        exit();
    }

    public function updateVgOption($id, $data) {
        $value    = $data['value']      ?? '';
        $label    = $data['label']      ?? '';
        $sort     = $data['sort_order'] ?? 0;
        $isActive = isset($data['is_active']) ? 1 : 0;
        $result = $this->vgOptionModel->update((int)$id, $value, $label, $sort, $isActive);
        header('Location: index.php?action=settings&tab=vgoptions&notif=' . ($result ? 'update' : 'error'));
        exit();
    }

    public function deactivateVgOption($id) {
        $result = $this->vgOptionModel->deactivate((int)$id);
        header('Location: index.php?action=settings&tab=vgoptions&notif=' . ($result ? 'deactivate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to deactivate option.')));
        exit();
    }

    public function activateVgOption($id) {
        $result = $this->vgOptionModel->activate((int)$id);
        header('Location: index.php?action=settings&tab=vgoptions&notif=' . ($result ? 'activate' : 'error') . ($result ? '' : '&msg=' . urlencode('Failed to activate option.')));
        exit();
    }

    /** AJAX-only: receives ids[] in order, persists the new sort_order. Returns JSON. */
    public function reorderMinistries(array $ids): void {
        header('Content-Type: application/json');
        $ok = $this->ministryModel->reorder($ids);
        echo json_encode(['ok' => (bool)$ok]);
        exit();
    }
    public function reorderServices(array $ids): void {
        header('Content-Type: application/json');
        $ok = $this->serviceModel->reorder($ids);
        echo json_encode(['ok' => (bool)$ok]);
        exit();
    }
    public function reorderDiscipleshipSteps(array $ids): void {
        header('Content-Type: application/json');
        $ok = $this->stepModel->reorder($ids);
        echo json_encode(['ok' => (bool)$ok]);
        exit();
    }
    public function reorderVgOptions(array $ids): void {
        header('Content-Type: application/json');
        $ok = $this->vgOptionModel->reorder($ids);
        echo json_encode(['ok' => (bool)$ok]);
        exit();
    }

    public function deleteVgOption($id) {
        $usage = $this->vgOptionModel->getUsageInfo((int)$id);
        if ($usage['count'] > 0) {
            $typeLabel = VgOption::TYPES[$usage['option_type']] ?? 'option';
            $msg = sprintf(
                'Cannot delete "%s" — %d victory group%s still using this %s. Reassign them to a different value first.',
                $usage['label'] ?: $usage['value'],
                $usage['count'],
                $usage['count'] === 1 ? ' is' : 's are',
                strtolower($typeLabel)
            );
            header('Location: index.php?action=settings&tab=vgoptions&error=1&msg=' . urlencode($msg));
            exit();
        }
        $result = $this->vgOptionModel->delete((int)$id);
        header('Location: index.php?action=settings&tab=vgoptions&notif=' . ($result ? 'delete' : 'error'));
        exit();
    }
}
?>
