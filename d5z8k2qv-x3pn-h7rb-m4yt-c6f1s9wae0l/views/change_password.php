<?php
// Signed-in user changing their own password. $forced = an admin set/reset it,
// so this is the only page they can use until they have chosen their own.
include 'shared/header.php';
?>

<body<?php echo $forced ? ' class="login-bg"' : ''; ?>>
    <?php if (!$forced) include 'shared/menu.php'; ?>
    <div class="<?php echo $forced ? 'container-fluid d-flex align-items-center justify-content-center min-vh-100' : 'main-content'; ?>">
        <div class="<?php echo $forced ? 'col-md-4 mx-auto' : 'container-fluid'; ?>">
            <?php if (!$forced): ?>
            <div class="mb-4">
                <h1 class="h3 mb-0"><i class="bi bi-key me-2 text-warning"></i>Change Password</h1>
                <p class="text-muted mb-0">Signed in as <strong><?php echo htmlspecialchars($_SESSION['user']['username'] ?? ''); ?></strong></p>
            </div>
            <?php endif; ?>

            <div class="card <?php echo $forced ? 'shadow-lg border-0' : ''; ?>" style="max-width: 480px;">
                <?php if ($forced): ?>
                <div class="card-header text-center py-3" style="background: linear-gradient(135deg, #1742f5 0%, #070d63 100%);">
                    <h5 class="text-white mb-0 fw-bold">Choose your own password</h5>
                </div>
                <?php endif; ?>
                <div class="card-body p-4">
                    <?php if ($forced): ?>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle-fill me-1"></i>
                        Your password was set by an administrator. Please choose your own before you continue. Type the password you were given as the current password.
                    </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                    <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="index.php?action=changePassword">
                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-semibold">Current password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password" autofocus>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#current_password" aria-label="Show or hide password"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <?php include 'shared/password_fields.php'; ?>
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Change password</button>
                            <?php if ($forced): ?>
                            <a href="index.php?action=logout" class="btn btn-outline-secondary">Sign out</a>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if (!$forced): ?>
                    <p class="text-muted small mt-3 mb-0">Forgot your current password? Sign out and use <em>Forgot password?</em> on the sign-in page, or ask an administrator to reset it.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

<?php include 'shared/footer.php'; ?>
