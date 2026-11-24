<?php include 'shared/header.php'; ?>

<body class="login-bg">
    <div class="container-fluid d-flex align-items-center justify-content-center min-vh-100">
        <div class="row w-100">
            <div class="col-md-4 mx-auto">
                <div class="card shadow-lg border-0 login-card">
                    <div class="card-header text-center py-4" style="background: linear-gradient(135deg, #1742f5 0%, #070d63 100%);">
                        <img src="images/victory-logo.png" alt="Victory Bacolod" class="img-fluid mt-2 mb-3" style="max-height: 70px; filter: brightness(0) invert(1);">
                        <h5 class="text-white mb-0 fw-bold">Forgot your password?</h5>
                        <small class="text-white-50">Victory Bacolod Admin Portal</small>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($done)): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                If that account exists and has an email on file, we have sent a link to reset the password. It works once and expires in <?php echo (int)User::RESET_TTL_MINUTES; ?> minutes.
                            </div>
                            <?php if (!$mailReady): ?>
                            <div class="alert alert-warning small" role="alert">
                                Email is not set up on this server yet, so nothing was sent. Please ask an administrator to reset your password.
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (!$mailReady): ?>
                            <div class="alert alert-warning small" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                Email is not set up on this server yet, so a reset link cannot be sent. Please ask an administrator to reset your password for you.
                            </div>
                            <?php endif; ?>
                            <p class="text-muted">Enter your username or the email on your account and we will email you a link to choose a new password.</p>
                            <form method="POST" action="index.php?action=forgotPassword">
                                <div class="mb-4">
                                    <label for="login" class="form-label fw-semibold">Username or email</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                                        <input type="text" class="form-control" id="login" name="login" required autofocus
                                               placeholder="Username or email" autocomplete="username">
                                    </div>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg"<?php echo $mailReady ? '' : ' disabled'; ?>>
                                        <i class="bi bi-envelope me-2"></i>Send reset link
                                    </button>
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
