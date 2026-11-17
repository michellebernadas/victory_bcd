<?php
require_once __DIR__ . '/../models/JoinRequest.php';
require_once __DIR__ . '/../models/GroupMatcher.php';
require_once __DIR__ . '/../models/Mailer.php';

/**
 * Admin inbox for the public "Request to join" form. index.php gates every
 * route with the join_requests permission; each method re-checks it.
 */
class JoinRequestController {
    private $model;

    public function __construct($db) { $this->model = new JoinRequest($db); }

    private function guard() {
        if (!hasPermission('join_requests')) { header('Location: index.php'); exit(); }
    }

    public function listRequests() {
        $this->guard();
        $status = isset(JoinRequest::STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
        $search = trim((string)($_GET['q'] ?? ''));
        $requests = $this->model->getAll($status, $search);
        $counts   = $this->model->counts();
        include 'views/join_requests.php';
    }

    public function update($id, array $post) {
        $this->guard();
        $ok = $this->model->update($id, (string)($post['status'] ?? ''), $post['staff_notes'] ?? '', (int)($_SESSION['user']['id'] ?? 0));
        $this->back($ok ? 'Request updated.' : null, $ok ? null : 'Could not update the request.', $post);
    }

    /** Try the leader / staff emails again (e.g. after email was set up or the group got an address). */
    public function resend($id) {
        $this->guard();
        require_once __DIR__ . '/../models/JoinNotifier.php';
        $r = JoinNotifier::run($GLOBALS['db'], (int)$id);
        $bits = [];
        foreach (['leader', 'staff'] as $k) if ($r[$k]) $bits[] = $k . ': ' . $r[$k];
        $this->back($bits ? 'Notification attempted (' . implode(', ', $bits) . ').' : null, $bits ? null : 'Nobody to notify: add an email on the group or set JOIN_NOTIFY_EMAIL.', $_POST);
    }

    public function delete($id) {
        $this->guard();
        $ok = $this->model->delete($id);
        $this->back($ok ? 'Request deleted.' : null, $ok ? null : 'Could not delete the request.', $_POST);
    }

    private function back($notif, $error, array $src) {
        $q = 'index.php?action=joinRequests';
        $st = (string)($src['_status'] ?? '');
        if (isset(JoinRequest::STATUSES[$st])) $q .= '&status=' . $st;
        if ($notif) $q .= '&notif=' . urlencode($notif);
        if ($error) $q .= '&error=' . urlencode($error);
        header('Location: ' . $q);
        exit();
    }
}
