<?php
/**
 * New password + confirm, shared by the reset page and the change-password page.
 * Needs nothing from the caller; ids are prefixed so two uses on a page can't clash.
 */
?>
<div class="mb-3">
    <label for="new_password" class="form-label fw-semibold">New password</label>
    <div class="input-group">
        <input type="password" class="form-control" id="new_password" name="new_password" required
               minlength="<?php echo (int)User::MIN_PASSWORD_LENGTH; ?>" autocomplete="new-password">
        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#new_password" aria-label="Show or hide password"><i class="bi bi-eye"></i></button>
    </div>
    <div class="form-text">At least <?php echo (int)User::MIN_PASSWORD_LENGTH; ?> characters.</div>
</div>
<div class="mb-1">
    <label for="confirm_password" class="form-label fw-semibold">Confirm new password</label>
    <div class="input-group">
        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
               minlength="<?php echo (int)User::MIN_PASSWORD_LENGTH; ?>" autocomplete="new-password">
        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#confirm_password" aria-label="Show or hide password"><i class="bi bi-eye"></i></button>
    </div>
</div>
