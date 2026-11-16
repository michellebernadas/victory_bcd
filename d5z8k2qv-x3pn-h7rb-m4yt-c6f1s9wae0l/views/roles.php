<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
if (!canAdminister()) { header('Location: index.php'); exit(); }
include 'shared/header.php';
$allPermLabels = permissionCatalog();
?>

<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-shield-lock me-2 text-warning"></i>Roles &amp; Access</h1>
                    <p class="text-muted mb-0">Create roles and choose exactly which sections each one can open</p>
                </div>
                <button class="btn btn-primary" onclick="openRoleModal(null)">
                    <i class="bi bi-plus-lg me-1"></i> Add Role
                </button>
            </div>

            <?php if (!empty($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_GET['notif']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (!empty($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_GET['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><i class="bi bi-table me-2"></i>Roles</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Can access</th>
                                    <th class="text-center">Users</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($roles as $slug => $r):
                                $isAdminRole = ($slug === ROLE_ADMIN);
                                $jsRole = [
                                    'slug' => $slug, 'label' => $r['label'],
                                    'description' => $r['description'] ?? '',
                                    'permissions' => $isAdminRole ? array_keys($allPermLabels) : $r['permissions'],
                                    'is_system' => (int)$r['is_system'], 'locked' => $isAdminRole,
                                ];
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($r['label']); ?>
                                            <?php if (!empty($r['is_system'])): ?><span class="badge bg-secondary ms-1">Built-in</span><?php endif; ?>
                                        </div>
                                        <?php $desc = $isAdminRole ? 'Everything, including users, roles and settings.' : ($r['description'] ?? ''); ?>
                                        <?php if ($desc !== ''): ?><div class="small text-muted"><?php echo htmlspecialchars($desc); ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isAdminRole): ?>
                                            <span class="badge bg-danger">Full access</span>
                                        <?php elseif (!$r['permissions']): ?>
                                            <span class="text-muted small">Dashboard only</span>
                                        <?php else: foreach ($r['permissions'] as $p): ?>
                                            <span class="badge bg-light text-dark border me-1 mb-1"><?php echo htmlspecialchars($allPermLabels[$p] ?? $p); ?></span>
                                        <?php endforeach; endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo (int)$r['user_count']; ?></td>
                                    <td class="text-center text-nowrap">
                                        <?php if ($isAdminRole): ?>
                                            <span class="text-muted small">Locked</span>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick='openRoleModal(<?php echo htmlspecialchars(json_encode($jsRole), ENT_QUOTES); ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if (empty($r['is_system'])): ?>
                                        <a href="index.php?action=deleteRole&slug=<?php echo urlencode($slug); ?>"
                                           class="btn btn-sm btn-outline-danger <?php echo $r['user_count'] > 0 ? 'disabled' : ''; ?>"
                                           title="<?php echo $r['user_count'] > 0 ? 'Move its users to another role first' : 'Delete'; ?>"
                                           data-confirm="Delete the role &quot;<?php echo htmlspecialchars($r['label']); ?>&quot;?">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                        <?php endif; endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add / Edit Role Modal -->
    <div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="roleModalTitle"><i class="bi bi-shield-lock me-2"></i>Add Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="roleForm" action="index.php?action=addRole">
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Role name <span class="text-danger">*</span></label>
                            <input type="text" name="label" id="role_label" class="form-control" required maxlength="100" placeholder="e.g. Youth Pastor">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Description</label>
                            <input type="text" name="description" id="role_description" class="form-control" maxlength="500" placeholder="Shown to whoever assigns this role">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label fw-semibold mb-0">What can this role open?</label>
                        <span class="small">
                            <a href="#" id="permAll">Select all</a> &middot; <a href="#" id="permNone">Clear</a>
                        </span>
                    </div>
                    <p class="small text-muted">Everyone signed in sees the Dashboard. Users, Roles and Settings are always Admin-only.</p>

                    <?php foreach (PERMISSION_GROUPS as $groupName => $perms): ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="fw-bold small text-uppercase text-muted mb-2" style="letter-spacing:1px"><?php echo htmlspecialchars($groupName); ?></div>
                        <div class="row g-2">
                        <?php foreach ($perms as $key => [$pLabel, $pHelp]): ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input perm-box" type="checkbox" name="permissions[]"
                                           value="<?php echo htmlspecialchars($key); ?>" id="perm_<?php echo htmlspecialchars($key); ?>">
                                    <label class="form-check-label" for="perm_<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($pLabel); ?>
                                        <div class="form-text mt-0" style="font-size:.75rem"><?php echo htmlspecialchars($pHelp); ?></div>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><span id="roleSubmitLabel">Create Role</span></button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openRoleModal(role) {
        var form = document.getElementById('roleForm');
        document.getElementById('roleModalTitle').innerHTML = '<i class="bi bi-shield-lock me-2"></i>' + (role ? 'Edit Role' : 'Add Role');
        document.getElementById('roleSubmitLabel').textContent = role ? 'Save Changes' : 'Create Role';
        form.action = role ? 'index.php?action=updateRole&slug=' + encodeURIComponent(role.slug) : 'index.php?action=addRole';
        document.getElementById('role_label').value = role ? role.label : '';
        document.getElementById('role_description').value = role ? (role.description || '') : '';
        var granted = role ? role.permissions : [];
        document.querySelectorAll('.perm-box').forEach(function (cb) { cb.checked = granted.indexOf(cb.value) !== -1; });
        new bootstrap.Modal(document.getElementById('roleModal')).show();
    }
    document.getElementById('permAll').addEventListener('click', function (e) {
        e.preventDefault(); document.querySelectorAll('.perm-box').forEach(function (cb) { cb.checked = true; });
    });
    document.getElementById('permNone').addEventListener('click', function (e) {
        e.preventDefault(); document.querySelectorAll('.perm-box').forEach(function (cb) { cb.checked = false; });
    });
    // Approving needs the ability to see the request, so keep the pairing honest.
    document.querySelectorAll('.perm-box').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var view = document.getElementById('perm_facility_view');
            if (cb.checked && cb.value.indexOf('facility_') === 0 && cb.value !== 'facility_view') view.checked = true;
        });
    });
    </script>

<?php include 'shared/footer.php'; ?>
