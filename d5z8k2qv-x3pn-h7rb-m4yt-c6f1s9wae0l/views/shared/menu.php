<div id="main-menu" class="d-flex flex-column flex-shrink-0 p-3 sidebar position-fixed" style="width: 260px; overflow-y: auto; height: 100vh;">
    <a href="index.php" class="d-flex align-items-center mx-auto link-body-emphasis text-decoration-none mb-2">
        <img src="images/victory-logo.png" class="img-fluid my-2 px-3" style="max-height: 60px;" alt="Victory Bacolod">
    </a>
    <div class="text-center mb-2">
        <small class="text-muted fw-semibold" style="font-size: 11px; letter-spacing: 1px;">ADMIN PORTAL</small>
    </div>
    <hr class="mt-0">
    <ul class="nav nav-pills flex-column mb-auto">
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo (!isset($_GET['action']) || $_GET['action'] == '') ? 'active' : ''; ?>">
                <i class="bi bi-house-door me-2"></i>Dashboard
            </a>
        </li>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">DISCIPLESHIP</small>
        </li>
        <li class="nav-item">
            <a href="index.php?action=members" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'members') ? 'active' : ''; ?>">
                <i class="bi bi-people me-2"></i>Members
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=attendanceRecords" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'attendanceRecords' && empty($_GET['program_type'])) ? 'active' : ''; ?>">
                <i class="bi bi-calendar-check me-2"></i>Attendance Records
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=victoryGroups" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'victoryGroups') ? 'active' : ''; ?>">
                <i class="bi bi-diagram-3 me-2"></i>Victory Groups / LG
            </a>
        </li>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">CLASSES</small>
        </li>
        <?php
        $atAction  = $_GET['action']       ?? '';
        $atProgram = $_GET['program_type'] ?? '';
        $isAtRecs  = $atAction === 'attendanceRecords';
        ?>
        <li class="nav-item">
            <a href="index.php?action=attendanceRecords&program_type=victory_weekend"
               class="nav-link <?php echo ($isAtRecs && $atProgram === 'victory_weekend') ? 'active' : ''; ?>">
                <i class="bi bi-sun me-2"></i>Victory Weekend
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=attendanceRecords&program_type=church_community"
               class="nav-link <?php echo ($isAtRecs && $atProgram === 'church_community') ? 'active' : ''; ?>">
                <i class="bi bi-building me-2"></i>Church Community
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=attendanceRecords&program_type=making_disciples"
               class="nav-link <?php echo ($isAtRecs && $atProgram === 'making_disciples') ? 'active' : ''; ?>">
                <i class="bi bi-person-plus me-2"></i>Making Disciples
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=attendanceRecords&program_type=empowering_leaders"
               class="nav-link <?php echo ($isAtRecs && $atProgram === 'empowering_leaders') ? 'active' : ''; ?>">
                <i class="bi bi-star me-2"></i>Empowering Leaders
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=leadership113"
               class="nav-link <?php echo ((isset($_GET['action']) && $_GET['action'] == 'leadership113') || ($isAtRecs && $atProgram === 'leadership_113')) ? 'active' : ''; ?>">
                <i class="bi bi-trophy me-2"></i>Leadership 1-1-3
            </a>
        </li>
        <?php if (isset($_SESSION['user']['accounttype']) && $_SESSION['user']['accounttype'] === 'admin') { ?>
        <li class="mt-2 mb-1">
            <small class="text-muted px-3 fw-bold" style="font-size: 11px; letter-spacing: 1px;">ADMINISTRATION</small>
        </li>
        <li class="nav-item">
            <a href="index.php?action=users" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'users') ? 'active' : ''; ?>">
                <i class="bi bi-person-gear me-2"></i>Users
            </a>
        </li>
        <li class="nav-item">
            <a href="index.php?action=settings" class="nav-link <?php echo (isset($_GET['action']) && $_GET['action'] == 'settings') ? 'active' : ''; ?>">
                <i class="bi bi-gear me-2"></i>Settings
            </a>
        </li>
        <?php } ?>
    </ul>

    <hr>

    <div class="dropdown">
        <a href="#" class="d-flex align-items-center link-body-emphasis text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <img src="https://api.dicebear.com/9.x/initials/svg?seed=<?php echo isset($_SESSION['user']['username']) ? urlencode($_SESSION['user']['username']) : 'User'; ?>"
                alt="" width="32" height="32" class="rounded-circle me-2">
            <strong class="text-truncate" style="max-width: 150px;">Hi, <?php echo isset($_SESSION['user']['username']) ? htmlspecialchars($_SESSION['user']['username']) : 'User'; ?></strong>
        </a>
        <ul class="dropdown-menu text-small shadow">
            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="index.php?action=logout"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
        </ul>
    </div>

    <!-- Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true" data-bs-backdrop="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileModalLabel"><i class="bi bi-person-circle me-2"></i>My Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (isset($_SESSION['user'])): ?>
                        <div class="text-center mb-3">
                            <img src="https://api.dicebear.com/9.x/initials/svg?seed=<?php echo isset($_SESSION['user']['username']) ? urlencode($_SESSION['user']['username']) : 'User'; ?>"
                                alt="Profile" width="80" height="80" class="rounded-circle mb-2">
                            <h5 class="mb-1"><?php echo htmlspecialchars($_SESSION['user']['username']); ?></h5>
                            <span class="badge bg-primary"><?php echo ucfirst($_SESSION['user']['accounttype'] ?? 'Editor'); ?></span>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user']['email'] ?? ''); ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Last Login</label>
                            <input type="text" class="form-control" value="<?php echo isset($_SESSION['user']['last_login']) ? date('M d, Y g:i A', strtotime($_SESSION['user']['last_login'])) : 'N/A'; ?>" readonly>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <?php if (isset($_SESSION['user']['accounttype']) && $_SESSION['user']['accounttype'] === 'admin'): ?>
                    <a href="index.php?action=editUser&id=<?php echo $_SESSION['user']['id'] ?? ''; ?>" class="btn btn-primary">Edit Profile</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div id="profileModalBackdrop" class="modal-backdrop fade" style="display: none; z-index: 1040;"></div>
</div>
