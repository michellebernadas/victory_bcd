<?php include 'shared/header.php'; ?>

<body class="login-bg">
    <div class="container-fluid d-flex align-items-center justify-content-center min-vh-100">
        <div class="row w-100">
            <div class="col-md-4 mx-auto">
                <div class="card shadow-lg border-0 login-card">
                    <div class="card-header text-center py-4" style="background: linear-gradient(135deg, #1742f5 0%, #070d63 100%);">
                        <img src="images/victory-logo.png" alt="Victory Bacolod" class="img-fluid mt-2 mb-3" style="max-height: 70px; filter: brightness(0) invert(1);">
                        <h5 class="text-white mb-0 fw-bold">Victory Bacolod</h5>
                        <small class="text-white-50">Admin Portal</small>
                    </div>
                    <div class="card-body p-4">
                        <?php if (isset($loginError)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php echo nl2br(htmlspecialchars($loginError)); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($infoMessage)): ?>
                            <div class="alert alert-info alert-dismissible fade show" role="alert">
                                <i class="bi bi-info-circle-fill me-2"></i>
                                <?php echo htmlspecialchars($infoMessage); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['logout_message'])): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <?php echo htmlspecialchars($_SESSION['logout_message']);
                                      unset($_SESSION['logout_message']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="index.php?action=login">
                            <div class="mb-3">
                                <label for="username" class="form-label fw-semibold">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" class="form-control" id="username" name="username" required
                                           placeholder="Enter your username" autocomplete="username">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label fw-semibold">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                                    <input type="password" class="form-control" id="password" name="password" required
                                           placeholder="Enter your password" autocomplete="current-password">
                                    <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#password">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="card-footer text-center py-3 bg-light">
                        <small class="text-muted">
                            <i class="bi bi-shield-check me-1"></i>
                            Secure Login &bull; Victory Bacolod
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include 'shared/footer.php'; ?>
