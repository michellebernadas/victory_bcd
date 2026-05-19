<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
if (!isset($_SESSION['user']['accounttype']) || $_SESSION['user']['accounttype'] !== 'admin') {
    header('Location: index.php'); exit();
}
include 'shared/header.php';
$editUser = $editUser ?? null;
?>

<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-person-gear me-2 text-warning"></i>Admin Users</h1>
                    <p class="text-muted mb-0">Manage system access and user accounts</p>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-person-plus me-1"></i> Add User
                </button>
            </div>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
                <?php
                $notif = $_GET['notif'];
                $alertClass = '';
                $iconClass = '';
                if ($notif === 'add') {
                    $alertClass = 'alert-success';
                    $iconClass = 'bi-check-circle-fill';
                } elseif ($notif === 'update') {
                    $alertClass = 'alert-info';
                    $iconClass = 'bi-info-circle-fill';
                } elseif ($notif === 'delete') {
                    $alertClass = 'alert-danger';
                    $iconClass = 'bi-trash-fill';
                } elseif ($notif === 'activate') {
                    $alertClass = 'alert-success';
                    $iconClass = 'bi-check-circle-fill';
                } elseif ($notif === 'deactivate') {
                    $alertClass = 'alert-info';
                    $iconClass = 'bi-info-circle-fill';
                }
                ?>
                <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                    <i class="<?php echo $iconClass; ?> me-2"></i>
                    User <?php echo ucfirst($notif); ?>d successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($_GET['msg'] ?? 'An error occurred.'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Users Table -->
            <div class="card">
                <div class="card-header">
                    <span><i class="bi bi-table me-2"></i>Users List</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover data-table mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Last Login</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $i => $u): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold">
                                        <img src="https://api.dicebear.com/9.x/initials/svg?seed=<?php echo urlencode($u['username']); ?>"
                                             width="28" height="28" class="rounded-circle me-2">
                                        <?php echo htmlspecialchars($u['username']); ?>
                                        <?php if ($u['id'] == $_SESSION['user']['id']): ?><span class="badge bg-info ms-1">You</span><?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><span class="badge <?php echo $u['accounttype'] === 'admin' ? 'bg-danger' : 'bg-primary'; ?>"><?php echo ucfirst($u['accounttype']); ?></span></td>
                                    <td><?php echo ($u['last_login'] && $u['last_login'] != '0000-00-00 00:00:00') ? date('M d, Y g:i A', strtotime($u['last_login'])) : '<span class="text-muted">Never</span>'; ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $u['accountstatus'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($u['accountstatus']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditUserModal(<?php echo htmlspecialchars(json_encode($u)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($u['accountstatus'] === 'active'): ?>
                                        <a href="index.php?action=deactivateUser&uuid=<?php echo $u['uuid']; ?>" class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                           onclick="return confirm('Deactivate this user?')">
                                            <i class="bi bi-person-x"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="index.php?action=activateUser&uuid=<?php echo $u['uuid']; ?>" class="btn btn-sm btn-outline-success me-1" title="Activate"
                                           onclick="return confirm('Activate this user?')">
                                            <i class="bi bi-person-check"></i>
                                        </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"
                                            onclick="confirmDeleteUser('<?php echo $u['uuid']; ?>', '<?php echo htmlspecialchars(addslashes($u['username'])); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($users)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addUser">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required placeholder="Enter username">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required placeholder="Enter email">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="add_password" class="form-control" required placeholder="Enter password">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#add_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="accounttype" class="form-select" required>
                            <option value="editor">Editor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Create User</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editUserForm" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" id="eu_username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="eu_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password <small class="text-muted">(leave blank to keep current)</small></label>
                        <div class="input-group">
                            <input type="password" name="password" id="eu_password" class="form-control" placeholder="Enter new password">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="#eu_password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="accounttype" id="eu_accounttype" class="form-select" required>
                            <option value="editor">Editor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update User</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Delete user <strong id="deleteUsername"></strong>?</p>
                    <p class="text-muted small">This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <a id="deleteUserBtn" href="#" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1"></i>Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script>
    function openEditUserModal(u) {
        document.getElementById('eu_username').value = u.username;
        document.getElementById('eu_email').value = u.email || '';
        document.getElementById('eu_password').value = '';
        document.getElementById('eu_accounttype').value = u.accounttype;
        document.getElementById('editUserForm').action = 'index.php?action=updateUser&uuid=' + u.uuid;
        var modal = new bootstrap.Modal(document.getElementById('editUserModal'));
        modal.show();
    }
    function confirmDeleteUser(uuid, username) {
        document.getElementById('deleteUsername').textContent = username;
        document.getElementById('deleteUserBtn').href = 'index.php?action=deleteUser&uuid=' + uuid;
        var modal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
        modal.show();
    }
    </script>

<?php include 'shared/footer.php'; ?>
