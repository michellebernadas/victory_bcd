<?php
/**
 * "Back to Member" banner.
 *
 * Leadership 1-1-3 and Spiritual Foundations are too complex for a modal — each
 * needs a per-session / per-topic grid — so the Member Profile sends the admin
 * here in the SAME browser tab. Without a way back, a non-technical admin is
 * stranded on a list of every participant with no obvious route to the person
 * they started from.
 *
 * Rendered only when ?back_member=<id> is present and resolves to a real
 * member, so a stale or hand-edited URL shows nothing rather than a dead link.
 *
 * Takes its own connection rather than requiring $db in scope: this partial is
 * included from views rendered by two different controllers, and the
 * session-class controllers expose only $this->db, which a view cannot see.
 * Depending on a variable that is present in one view and absent in another is
 * exactly how a partial silently renders nothing.
 */
$btmId = (int)($_GET['back_member'] ?? 0);
if ($btmId > 0) {
    $btmDb = $db ?? (function_exists('getDB') ? getDB() : null);
}
if ($btmId > 0 && $btmDb instanceof PDO) {
    try {
        $btmStmt = $btmDb->prepare("SELECT id, full_name FROM members WHERE id = ? AND is_deleted = 0");
        $btmStmt->execute([$btmId]);
        $btmMember = $btmStmt->fetch();
    } catch (PDOException $e) {
        $btmMember = null;
    }

    if (!empty($btmMember)): ?>
    <div class="alert alert-primary d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
        <span class="small">
            <i class="bi bi-person-circle me-1"></i>
            You came here from <strong><?php echo htmlspecialchars($btmMember['full_name']); ?></strong>'s profile.
            Add or edit their record below, then head back.
        </span>
        <a href="index.php?action=memberProfile&id=<?php echo (int)$btmMember['id']; ?>"
           class="btn btn-sm btn-primary">
            <i class="bi bi-arrow-left me-1"></i>Back to Member
        </a>
    </div>
    <?php endif;
}
