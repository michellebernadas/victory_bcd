<?php
/**
 * Per-role help under the Users page role dropdown: which facility workflow
 * stages each role acts on. Text comes from the role's description
 * (accountTypeHelp() in config/permissions.php), editable on the Roles page.
 */
$roleHelpText = accountTypeHelp();
?>
<div class="form-text" style="font-size:.75rem">
    <?php foreach (accountTypeLabels() as $roleValue => $roleLabel): ?>
        <strong><?php echo htmlspecialchars($roleLabel); ?></strong> —
        <?php echo htmlspecialchars($roleHelpText[$roleValue] ?? ''); ?><br>
    <?php endforeach; ?>
</div>
