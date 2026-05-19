<?php
require_once 'models/VglStatDefinition.php';

class VglStatController {
    private $db;
    private $model;

    public function __construct($db) {
        $this->db    = $db;
        $this->model = new VglStatDefinition($db);
    }

    public function addStat($data) {
        if (empty(trim($data['label'] ?? '')) || empty(trim($data['section'] ?? '')) || empty(trim($data['filter_value'] ?? ''))) {
            header('Location: index.php?action=victoryGroups&tab=statDefs&error=stat&msg=' . urlencode('Label, section and filter value are required'));
            exit();
        }
        $result = $this->model->add($data);
        header('Location: index.php?action=victoryGroups&tab=statDefs&notif=' . ($result ? 'stat_add' : 'error'));
        exit();
    }

    public function updateStat($id, $data) {
        $result = $this->model->update($id, $data);
        header('Location: index.php?action=victoryGroups&tab=statDefs&notif=' . ($result ? 'stat_update' : 'error'));
        exit();
    }

    public function deleteStat($id) {
        $result = $this->model->delete($id);
        header('Location: index.php?action=victoryGroups&tab=statDefs&notif=' . ($result ? 'stat_delete' : 'error'));
        exit();
    }

    public function deactivateStat($id) {
        $result = $this->model->setActive($id, 0);
        header('Location: index.php?action=victoryGroups&tab=statDefs&notif=' . ($result ? 'stat_deactivate' : 'error'));
        exit();
    }

    public function activateStat($id) {
        $result = $this->model->setActive($id, 1);
        header('Location: index.php?action=victoryGroups&tab=statDefs&notif=' . ($result ? 'stat_activate' : 'error'));
        exit();
    }

    public function updateOrder() {
        header('Content-Type: application/json');
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) { echo json_encode(['success' => false]); exit(); }
        $result = $this->model->updateOrder($ids);
        echo json_encode(['success' => $result]);
        exit();
    }
}
?>
