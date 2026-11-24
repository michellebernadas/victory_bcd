<?php include 'shared/header.php'; ?>

<body class="login-bg">
    <div class="container-fluid d-flex align-items-center justify-content-center min-vh-100">
        <div class="row w-100">
            <div class="col-md-4 mx-auto">
                <div class="card shadow-lg border-0 login-card">
                    <div class="card-header text-center py-4" style="background: linear-gradient(135deg, #1742f5 0%, #070d63 100%);">
                        <img src="images/victory-logo.png" alt="Victory Bacolod" class="img-fluid mt-2 mb-3" style="max-height: 70px; filter: brightness(0) invert(1);">
                        <h5 class="text-white mb-0 fw-bold">Choose a new password</h5>
                        <small class="text-white-50">Victory Bacolod Admin Portal</small>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!$account): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                This reset link is invalid, has expired or has already been used.
                            </div>
                            <div class="d-grid"><a class="btn btn-primary" href="index.php?action=forgotPassword">Request a new link</a></div>
                        <?php else: ?>
                            <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?></div>
                            <?php endif; ?>
                            <p class="text-muted">Choosing a new password for <strong><?php echo htmlspecialchars($account['username']); ?></strong>.</p>
                            <form method="POST" action="index.php?action=resetPassword">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                <?php include 'shared/password_fields.php'; ?>
                                <div class="d-grid mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-check-lg me-2"></i>Save new password</button>
                                </div>
                            </form>
                        <?php endif; ?>
                        <div class="text-center mt-3"><a href="index.php?action=login"><i class="bi bi-arrow-left me-1"></i>Back to sign in</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include 'shared/footer.php'; ?>
