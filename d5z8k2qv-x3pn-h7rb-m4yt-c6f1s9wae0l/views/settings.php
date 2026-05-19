<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
if (!isset($_SESSION['user']['accounttype']) || $_SESSION['user']['accounttype'] !== 'admin') {
    header('Location: index.php'); exit();
}
include 'shared/header.php';

$activeTab = $_GET['tab'] ?? 'ministries';
if (!in_array($activeTab, ['ministries', 'services', 'discipleship', 'vgoptions'])) {
    $activeTab = 'ministries';
}

$COLORS = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark', 'purple'];
$ICONS  = [
    'bi-check-circle', 'bi-sun', 'bi-building', 'bi-person-plus', 'bi-star',
    'bi-trophy', 'bi-book', 'bi-heart-pulse', 'bi-people', 'bi-lightbulb',
    'bi-shield', 'bi-flag', 'bi-fire', 'bi-gem', 'bi-mortarboard',
];
?>

<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-gear me-2 text-secondary"></i>Settings</h1>
                    <p class="text-muted mb-0">Manage Ministries, Church Services, and Discipleship Journey Steps</p>
                </div>
            </div>

            <!-- Notifications — Settings changes propagate through related records, so messages call that out explicitly. -->
            <?php if (isset($_GET['notif'])):
                // Each Settings tab edits a "lookup" value that's referenced from member/VG records.
                // Add/Update/Delete propagate through those references — the message reflects that.
                $resCfg = [
                    'ministries'   => ['label' => 'Ministry',          'where' => 'in the Ministry dropdown wherever members are added or edited',          'records' => 'all members assigned to this ministry'],
                    'services'     => ['label' => 'Church service',    'where' => 'in the Service Attending dropdown wherever members are added or edited', 'records' => 'all members attending this service'],
                    'discipleship' => ['label' => 'Discipleship step', 'where' => 'in the Discipleship Steps list across the admin panel',                  'records' => 'all members linked to this step'],
                    'vgoptions'    => ['label' => 'VG option',         'where' => 'in the related Victory Group dropdowns wherever groups are added or edited', 'records' => 'all victory groups using this value'],
                ][$activeTab] ?? ['label' => 'Item', 'where' => 'across the admin panel', 'records' => 'related records'];
                $messages = [
                    'add'        => $resCfg['label'] . ' has been added successfully. It is now available ' . $resCfg['where'] . '.',
                    'update'     => $resCfg['label'] . ' has been updated successfully. The new value has been propagated to ' . $resCfg['records'] . '.',
                    'deactivate' => $resCfg['label'] . ' has been deactivated successfully. Press the activate button to enable it again.',
                    'activate'   => $resCfg['label'] . ' has been reactivated successfully.',
                    'delete'     => $resCfg['label'] . ' has been deleted successfully. The record has been removed from the list.',
                ];
            ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo $messages[$_GET['notif']] ?? 'Done.'; ?>
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

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'ministries' ? 'active' : ''; ?>"
                       href="index.php?action=settings&tab=ministries">
                        <i class="bi bi-building me-1"></i>Ministries
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'services' ? 'active' : ''; ?>"
                       href="index.php?action=settings&tab=services">
                        <i class="bi bi-clock me-1"></i>Church Services
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'discipleship' ? 'active' : ''; ?>"
                       href="index.php?action=settings&tab=discipleship">
                        <i class="bi bi-bar-chart-steps me-1"></i>Discipleship Steps
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'vgoptions' ? 'active' : ''; ?>"
                       href="index.php?action=settings&tab=vgoptions">
                        <i class="bi bi-sliders me-1"></i>VG Options
                    </a>
                </li>
            </ul>

            <!-- ──────────────────────────────────────────────────────── -->
            <!-- MINISTRIES TAB                                          -->
            <!-- ──────────────────────────────────────────────────────── -->
            <?php if ($activeTab === 'ministries'): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-building me-2"></i>Ministries <span class="badge bg-light text-dark ms-1"><?php echo count($ministries); ?></span></span>
                    <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#addMinistryModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Ministry
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:30px"></th>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th class="text-center">Members</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="settings-sortable" data-reorder-url="index.php?action=reorderMinistries">
                                <?php foreach ($ministries as $i => $min):
                                    $cnt = $ministryCounts[(int)$min['id']] ?? 0;
                                ?>
                                <tr data-id="<?php echo (int)$min['id']; ?>">
                                    <td class="text-center text-muted" style="cursor:grab" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></td>
                                    <td><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($min['name']); ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $cnt > 0 ? 'bg-primary' : 'bg-light text-muted border'; ?>" title="Members currently assigned to this ministry">
                                            <?php echo $cnt; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $min['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $min['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditMinistry(<?php echo $min['id']; ?>, <?php echo htmlspecialchars(json_encode($min['name'])); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($min['is_active']): ?>
                                        <button class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                            onclick="openActionModal('deactivate','ministry',<?php echo $min['id']; ?>,'<?php echo htmlspecialchars(addslashes($min['name'])); ?>')">
                                            <i class="bi bi-pause-circle"></i>
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success me-1" title="Activate"
                                            onclick="openActionModal('activate','ministry',<?php echo $min['id']; ?>,'<?php echo htmlspecialchars(addslashes($min['name'])); ?>')">
                                            <i class="bi bi-play-circle"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"
                                            onclick="openActionModal('delete','ministry',<?php echo $min['id']; ?>,'<?php echo htmlspecialchars(addslashes($min['name'])); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($ministries)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No ministries found. Add one above.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ──────────────────────────────────────────────────────── -->
            <!-- SERVICES TAB                                            -->
            <!-- ──────────────────────────────────────────────────────── -->
            <?php if ($activeTab === 'services'): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock me-2"></i>Church Services <span class="badge bg-light text-dark ms-1"><?php echo count($services); ?></span></span>
                    <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Church Service
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:30px"></th>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th class="text-center">Members</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="settings-sortable" data-reorder-url="index.php?action=reorderServices">
                                <?php foreach ($services as $i => $svc):
                                    $cnt = $serviceCounts[(int)$svc['id']] ?? 0;
                                ?>
                                <tr data-id="<?php echo (int)$svc['id']; ?>">
                                    <td class="text-center text-muted" style="cursor:grab" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></td>
                                    <td><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($svc['name']); ?></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $cnt > 0 ? 'bg-primary' : 'bg-light text-muted border'; ?>" title="Members currently attending this service">
                                            <?php echo $cnt; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $svc['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $svc['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditService(<?php echo $svc['id']; ?>, <?php echo htmlspecialchars(json_encode($svc['name'])); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($svc['is_active']): ?>
                                        <button class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                            onclick="openActionModal('deactivate','service',<?php echo $svc['id']; ?>,'<?php echo htmlspecialchars(addslashes($svc['name'])); ?>')">
                                            <i class="bi bi-pause-circle"></i>
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success me-1" title="Activate"
                                            onclick="openActionModal('activate','service',<?php echo $svc['id']; ?>,'<?php echo htmlspecialchars(addslashes($svc['name'])); ?>')">
                                            <i class="bi bi-play-circle"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"
                                            onclick="openActionModal('delete','service',<?php echo $svc['id']; ?>,'<?php echo htmlspecialchars(addslashes($svc['name'])); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($services)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No church services found. Add one above.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ──────────────────────────────────────────────────────── -->
            <!-- DISCIPLESHIP STEPS TAB                                  -->
            <!-- ──────────────────────────────────────────────────────── -->
            <?php if ($activeTab === 'discipleship'): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bar-chart-steps me-2"></i>Discipleship Steps <span class="badge bg-light text-dark ms-1"><?php echo count($steps); ?></span></span>
                    <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#addStepModal">
                        <i class="bi bi-plus-lg me-1"></i>Add Step
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th style="width:30px"></th>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Abbreviation</th>
                                    <th>Icon</th>
                                    <th>Color</th>
                                    <th>DB Column</th>
                                    <th class="text-center">Completions</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="settings-sortable" data-reorder-url="index.php?action=reorderDiscipleshipSteps">
                                <?php foreach ($steps as $i => $step):
                                    $cnt = $stepCounts[(int)$step['id']] ?? 0;
                                ?>
                                <tr data-id="<?php echo (int)$step['id']; ?>">
                                    <td class="text-center text-muted" style="cursor:grab" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></td>
                                    <td><?php echo $i + 1; ?></td>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($step['name']); ?></td>
                                    <td><span class="badge bg-<?php echo htmlspecialchars($step['color']); ?>"><i class="bi <?php echo htmlspecialchars($step['icon']); ?> me-1"></i><?php echo htmlspecialchars($step['abbreviation']); ?></span></td>
                                    <td><code><?php echo htmlspecialchars($step['icon']); ?></code></td>
                                    <td><span class="badge bg-<?php echo htmlspecialchars($step['color']); ?>"><?php echo htmlspecialchars($step['color']); ?></span></td>
                                    <td><code><?php echo $step['column_key'] ? htmlspecialchars($step['column_key']) : '<span class="text-muted">none</span>'; ?></code></td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $cnt > 0 ? 'bg-primary' : 'bg-light text-muted border'; ?>" title="Members who have completed this step">
                                            <?php echo $cnt; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $step['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $step['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                            onclick="openEditStep(<?php echo htmlspecialchars(json_encode($step)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php if ($step['is_active']): ?>
                                        <button class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                            onclick="openActionModal('deactivate','step',<?php echo $step['id']; ?>,'<?php echo htmlspecialchars(addslashes($step['name'])); ?>')">
                                            <i class="bi bi-pause-circle"></i>
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-sm btn-outline-success me-1" title="Activate"
                                            onclick="openActionModal('activate','step',<?php echo $step['id']; ?>,'<?php echo htmlspecialchars(addslashes($step['name'])); ?>')">
                                            <i class="bi bi-play-circle"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"
                                            onclick="openActionModal('delete','step',<?php echo $step['id']; ?>,'<?php echo htmlspecialchars(addslashes($step['name'])); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($steps)): ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">No discipleship steps found. Add one above.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Steps with a <strong>DB Column</strong> value are linked to legacy data. Deactivating a step hides it from the members form but preserves all existing member data.</small>
            </div>
            <?php endif; ?>

            <!-- VG OPTIONS TAB -->
            <?php if ($activeTab === 'vgoptions'): ?>
            <?php
            $typeLabels = $vgOptionTypes ?? [];
            $allGrouped = $vgOptions ?? [];
            foreach ($typeLabels as $typeKey => $typeLabel):
                $opts = $allGrouped[$typeKey] ?? [];
            ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-tag me-2"></i><?php echo htmlspecialchars($typeLabel); ?> <span class="badge bg-light text-dark ms-1"><?php echo count($opts); ?></span></span>
                    <button class="btn btn-sm btn-outline-light" onclick="openAddVgOption('<?php echo $typeKey; ?>', '<?php echo htmlspecialchars(addslashes($typeLabel)); ?>')">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:30px"></th>
                                <th>#</th>
                                <th>Value <span class="text-muted small">(stored in DB)</span></th>
                                <th>Label <span class="text-muted small">(displayed)</span></th>
                                <th class="text-center">Victory Groups</th>
                                <th class="text-center">Active</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="settings-sortable" data-reorder-url="index.php?action=reorderVgOptions">
                        <?php
                        $vgCountTooltips = [
                            'group_type'        => 'Victory groups belonging to this type',
                            'group_category'    => 'Victory groups belonging to this category',
                            'day_of_week'       => 'Victory groups meeting on this day',
                            'meeting_frequency' => 'Victory groups meeting at this frequency',
                        ];
                        $vgTooltip = $vgCountTooltips[$typeKey] ?? 'Victory groups using this value';
                        foreach ($opts as $i => $opt):
                            $cnt = $vgOptionCounts[(int)$opt['id']] ?? 0;
                        ?>
                            <tr data-id="<?php echo (int)$opt['id']; ?>">
                                <td class="text-center text-muted" style="cursor:grab" title="Drag to reorder"><i class="bi bi-grip-vertical"></i></td>
                                <td><?php echo $i + 1; ?></td>
                                <td><code><?php echo htmlspecialchars($opt['value']); ?></code></td>
                                <td><?php echo htmlspecialchars($opt['label']); ?></td>
                                <td class="text-center">
                                    <span class="badge <?php echo $cnt > 0 ? 'bg-primary' : 'bg-light text-muted border'; ?>" title="<?php echo htmlspecialchars($vgTooltip); ?>">
                                        <?php echo $cnt; ?>
                                    </span>
                                </td>
                                <td class="text-center"><span class="badge <?php echo $opt['is_active'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $opt['is_active'] ? 'Yes' : 'No'; ?></span></td>
                                <td class="text-center text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary me-1" title="Edit" onclick="openEditVgOption(<?php echo htmlspecialchars(json_encode($opt)); ?>)"><i class="bi bi-pencil"></i></button>
                                    <?php if ($opt['is_active']): ?>
                                    <button class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                                        onclick="openActionModal('deactivate','vgoption',<?php echo $opt['id']; ?>,'<?php echo htmlspecialchars(addslashes($opt['label'])); ?>')">
                                        <i class="bi bi-pause-circle"></i>
                                    </button>
                                    <?php else: ?>
                                    <button class="btn btn-sm btn-outline-success me-1" title="Activate"
                                        onclick="openActionModal('activate','vgoption',<?php echo $opt['id']; ?>,'<?php echo htmlspecialchars(addslashes($opt['label'])); ?>')">
                                        <i class="bi bi-play-circle"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-danger" title="Delete" onclick="openActionModal('delete','vgoption',<?php echo $opt['id']; ?>,'<?php echo htmlspecialchars(addslashes($opt['label'])); ?>')"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($opts)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-3">None yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

        </div>
    </div>

    <!-- ADD VG OPTION MODAL -->
    <div class="modal fade" id="addVgOptionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add <span id="addVgOptTitle"></span> Option</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addVgOption">
                <input type="hidden" name="option_type" id="addVgOptType">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Value <span class="text-danger">*</span></label>
                            <input type="text" name="value" class="form-control" required placeholder="e.g. single women">
                            <div class="form-text">Stored in the database.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Label <span class="text-danger">*</span></label>
                            <input type="text" name="label" class="form-control" required placeholder="e.g. Single Women">
                            <div class="form-text">Shown to users.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Sort Order</label>
                            <input type="number" name="sort_order" class="form-control" value="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT VG OPTION MODAL -->
    <div class="modal fade" id="editVgOptionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Option</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editVgOptionForm" action="">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Value <span class="text-danger">*</span></label>
                            <input type="text" name="value" id="evo_value" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Label <span class="text-danger">*</span></label>
                            <input type="text" name="label" id="evo_label" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Sort Order</label>
                            <input type="number" name="sort_order" id="evo_sort" class="form-control">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="evo_active" value="1">
                                <label class="form-check-label" for="evo_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── ADD MINISTRY MODAL ────────────────────────────────────────────── -->
    <div class="modal fade" id="addMinistryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Ministry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addMinistry">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ministry Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Music, Media, Kids">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── EDIT MINISTRY MODAL ───────────────────────────────────────────── -->
    <div class="modal fade" id="editMinistryModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Ministry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editMinistryForm" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ministry Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="em_name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── ADD SERVICE MODAL ─────────────────────────────────────────────── -->
    <div class="modal fade" id="addServiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Church Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addService" class="service-form">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Time <span class="text-danger">*</span></label>
                        <input type="time" name="svc_time" id="add_svc_time" class="form-control svc-time-input">
                        <div class="form-text">Pick a time — it's saved as <code>h:MM AM/PM</code>.</div>
                    </div>
                    <input type="hidden" name="name" class="svc-name-hidden">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── EDIT SERVICE MODAL ────────────────────────────────────────────── -->
    <div class="modal fade" id="editServiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Church Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editServiceForm" action="" class="service-form">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Time <span class="text-danger">*</span></label>
                        <input type="time" id="edit_svc_time" class="form-control svc-time-input">
                        <div class="form-text">Pick a time — it's saved as <code>h:MM AM/PM</code>.</div>
                    </div>
                    <input type="hidden" name="name" id="es_name" class="svc-name-hidden">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── ADD DISCIPLESHIP STEP MODAL ──────────────────────────────────── -->
    <div class="modal fade" id="addStepModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Discipleship Step</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="index.php?action=addDiscipleshipStep">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Step Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Victory Weekend">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Abbreviation <span class="text-danger">*</span></label>
                            <input type="text" name="abbreviation" class="form-control" required placeholder="e.g. VW" maxlength="20">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Icon <span class="text-muted small">(Bootstrap Icons class)</span></label>
                            <input type="text" name="icon" class="form-control" placeholder="e.g. bi-check-circle" list="iconList">
                            <datalist id="iconList">
                                <?php foreach ($ICONS as $ic): ?>
                                <option value="<?php echo $ic; ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Color</label>
                            <select name="color" class="form-select">
                                <?php foreach ($COLORS as $c): ?>
                                <option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── EDIT DISCIPLESHIP STEP MODAL ─────────────────────────────────── -->
    <div class="modal fade" id="editStepModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Discipleship Step</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editStepForm" action="">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Step Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="esd_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Abbreviation <span class="text-danger">*</span></label>
                            <input type="text" name="abbreviation" id="esd_abbreviation" class="form-control" required maxlength="20">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Icon <span class="text-muted small">(Bootstrap Icons class)</span></label>
                            <input type="text" name="icon" id="esd_icon" class="form-control" list="iconList">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Color</label>
                            <select name="color" id="esd_color" class="form-select">
                                <?php foreach ($COLORS as $c): ?>
                                <option value="<?php echo $c; ?>"><?php echo ucfirst($c); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update</button>
                </div>
                </form>
            </div>
        </div>
    </div>


    <!-- ── SHARED ACTION CONFIRMATION MODAL ─────────────────────────────── -->
    <div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="actionModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="actionModalBody" class="mb-1"></p>
                    <p id="actionModalNote" class="text-muted small mb-0"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <a id="actionModalBtn" href="#" class="btn btn-sm"></a>
                </div>
            </div>
        </div>
    </div>

    <script>
    // ── Shared action modal ──────────────────────────────────────────────
    function openActionModal(action, type, id, name) {
        var actionMap = {
            ministry : { deactivate: 'deactivateMinistry', activate: 'activateMinistry', delete: 'deleteMinistry' },
            service  : { deactivate: 'deactivateService',  activate: 'activateService',  delete: 'deleteService'  },
            step     : { deactivate: 'deactivateDiscipleshipStep', activate: 'activateDiscipleshipStep', delete: 'deleteDiscipleshipStep' },
            vgoption : { deactivate: 'deactivateVgOption', activate: 'activateVgOption', delete: 'deleteVgOption' },
        };
        var typeLabel = { ministry: 'Ministry', service: 'Church Service', step: 'Discipleship Step', vgoption: 'VG Option' };
        var cfg = {
            deactivate: {
                title  : '<i class="bi bi-pause-circle text-warning me-2"></i>Deactivate ' + typeLabel[type],
                body   : 'Deactivate <strong>' + name + '</strong>?',
                note   : 'It will be hidden from member forms but can be reactivated.',
                btnCls : 'btn-warning',
                btnLbl : '<i class="bi bi-pause-circle me-1"></i>Deactivate',
            },
            activate: {
                title  : '<i class="bi bi-play-circle text-success me-2"></i>Activate ' + typeLabel[type],
                body   : 'Activate <strong>' + name + '</strong>?',
                note   : 'It will appear again in member forms.',
                btnCls : 'btn-success',
                btnLbl : '<i class="bi bi-play-circle me-1"></i>Activate',
            },
            delete: {
                title  : '<i class="bi bi-trash text-danger me-2"></i>Delete ' + typeLabel[type],
                body   : 'Delete <strong>' + name + '</strong>?',
                note   : 'This cannot be undone. Existing member data is preserved.',
                btnCls : 'btn-danger',
                btnLbl : '<i class="bi bi-trash me-1"></i>Delete',
            },
        };
        var c = cfg[action];
        document.getElementById('actionModalTitle').innerHTML = c.title;
        document.getElementById('actionModalBody').innerHTML  = c.body;
        document.getElementById('actionModalNote').textContent = c.note;
        var btn = document.getElementById('actionModalBtn');
        btn.className = 'btn btn-sm ' + c.btnCls;
        btn.innerHTML = c.btnLbl;
        btn.href = 'index.php?action=' + actionMap[type][action] + '&id=' + id;
        new bootstrap.Modal(document.getElementById('actionModal')).show();
    }

    // ── Ministry modals ──────────────────────────────────────────────────
    function openEditMinistry(id, name) {
        document.getElementById('em_name').value = name;
        document.getElementById('editMinistryForm').action = 'index.php?action=updateMinistry&id=' + id;
        new bootstrap.Modal(document.getElementById('editMinistryModal')).show();
    }

    // ── Service modals ───────────────────────────────────────────────────
    // Helpers: convert "9:00 AM" ↔ "09:00" (HTML time-input format)
    function svcTo24h(name) {
        var m = String(name || '').trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/i);
        if (!m) return '';
        var h = parseInt(m[1], 10);
        var min = m[2];
        var period = (m[3] || '').toUpperCase();
        if (period === 'PM' && h !== 12) h += 12;
        if (period === 'AM' && h === 12) h = 0;
        return (h < 10 ? '0' + h : '' + h) + ':' + min;
    }
    function svcTo12h(time24) {
        if (!time24) return '';
        var parts = time24.split(':');
        if (parts.length < 2) return '';
        var h = parseInt(parts[0], 10);
        var min = parts[1];
        var period = h >= 12 ? 'PM' : 'AM';
        if (h > 12) h -= 12;
        if (h === 0) h = 12;
        return h + ':' + min + ' ' + period;
    }
    function openEditService(id, name) {
        document.getElementById('es_name').value     = name;
        document.getElementById('edit_svc_time').value = svcTo24h(name);
        document.getElementById('editServiceForm').action = 'index.php?action=updateService&id=' + id;
        new bootstrap.Modal(document.getElementById('editServiceModal')).show();
    }
    // On submit, convert the time-input value → "h:MM AM/PM" into the hidden name field.
    document.querySelectorAll('.service-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var t = form.querySelector('.svc-time-input');
            var hidden = form.querySelector('.svc-name-hidden');
            if (t && hidden) {
                if (!t.value) { e.preventDefault(); alert('Please pick a service time.'); return; }
                hidden.value = svcTo12h(t.value);
            }
        });
    });

    // ── Discipleship Step modals ─────────────────────────────────────────
    function openEditStep(step) {
        document.getElementById('esd_name').value         = step.name;
        document.getElementById('esd_abbreviation').value = step.abbreviation;
        document.getElementById('esd_icon').value         = step.icon;
        document.getElementById('esd_color').value        = step.color;
        document.getElementById('editStepForm').action    = 'index.php?action=updateDiscipleshipStep&id=' + step.id;
        new bootstrap.Modal(document.getElementById('editStepModal')).show();
    }

    // ── VG Option modals ─────────────────────────────────────────────────
    function openAddVgOption(typeKey, typeLabel) {
        document.getElementById('addVgOptType').value = typeKey;
        document.getElementById('addVgOptTitle').textContent = typeLabel;
        new bootstrap.Modal(document.getElementById('addVgOptionModal')).show();
    }
    function openEditVgOption(opt) {
        document.getElementById('evo_value').value   = opt.value;
        document.getElementById('evo_label').value   = opt.label;
        document.getElementById('evo_sort').value    = opt.sort_order;
        document.getElementById('evo_active').checked = opt.is_active == 1;
        document.getElementById('editVgOptionForm').action = 'index.php?action=updateVgOption&id=' + opt.id;
        new bootstrap.Modal(document.getElementById('editVgOptionModal')).show();
    }

    </script>

<?php include 'shared/footer.php'; ?>
<script>
// Drag-and-drop sort for Settings tables (Ministries / Services / Discipleship Steps).
// Uses SortableJS (already loaded by footer). On drop, POSTs ids[] in new order to the
// table's data-reorder-url so the server can persist sort_order.
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Sortable === 'undefined') return;
    document.querySelectorAll('tbody.settings-sortable').forEach(function(tbody) {
        Sortable.create(tbody, {
            animation: 150,
            handle: 'td:first-child',   // drag handle is the leftmost cell
            ghostClass: 'table-active',
            onEnd: function() {
                var url = tbody.getAttribute('data-reorder-url');
                if (!url) return;
                var ids = Array.prototype.map.call(tbody.querySelectorAll('tr[data-id]'), function(tr) {
                    return tr.getAttribute('data-id');
                });
                var fd = new FormData();
                ids.forEach(function(id) { fd.append('ids[]', id); });
                fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(j) {
                        // Re-number the # column visually
                        tbody.querySelectorAll('tr[data-id]').forEach(function(tr, i) {
                            var numCell = tr.querySelectorAll('td')[1]; // 2nd td = # column
                            if (numCell) numCell.textContent = (i + 1);
                        });
                    })
                    .catch(function() { /* silently ignore */ });
            }
        });
    });
});
</script>
