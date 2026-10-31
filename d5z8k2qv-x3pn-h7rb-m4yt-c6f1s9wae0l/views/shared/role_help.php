<?php
/**
 * Per-role help under the Users page role dropdown: which facility workflow
 * stages each role acts on. Text lives in ACCOUNT_TYPE_HELP
 * (config/permissions.php) beside the matrix it describes.
 */
?>
<div class="form-text" style="font-size:.75rem">
    <?php foreach (ACCOUNT_TYPE_LABELS as $roleValue => $roleLabel): ?>
        <strong><?php echo htmlspecialchars($roleLabel); ?></strong> —
        <?php echo htmlspecialchars(ACCOUNT_TYPE_HELP[$roleValue] ?? ''); ?><br>
    <?php endforeach; ?>
</div>
