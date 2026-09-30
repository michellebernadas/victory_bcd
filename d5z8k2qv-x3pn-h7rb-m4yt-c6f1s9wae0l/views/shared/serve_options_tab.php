<?php
// Serve Teams — Service & Place dropdown management (admin only).
//
// Same safety rule the SF curriculum uses: a value still referenced by a team
// is ARCHIVED rather than deleted, so those teams keep a meaningful Service /
// Place. Renaming a value propagates to the teams using it.
//
// Requires in scope: $serveOptionsAll, $optionUsage, $isAdmin, $DAYS
$soUsage = $optionUsage ?? [];
?>
<?php if (!$isAdmin): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>Only administrators can manage the Service and Place lists.
</div>
<?php else: ?>

<?php foreach (ServeOption::TYPES as $soType => $soLabel):
    $soRows   = $serveOptionsAll[$soType] ?? [];
    $isSvc    = $soType === 'service';
    $soIcon   = $isSvc ? 'bi-calendar-event' : 'bi-geo-alt';
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-white fw-semibold">
            <i class="bi <?php echo $soIcon; ?> me-2"></i><?php echo htmlspecialchars($soLabel); ?>
            <span class="badge bg-white text-dark border ms-1"><?php echo count($soRows); ?></span>
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="col-toggle" data-table="serveOptTable_<?php echo $soType; ?>" data-locked="0"></span>
            <button class="btn btn-sm btn-light"
                    onclick="openAddServeOption('<?php echo $soType; ?>', '<?php echo htmlspecialchars(addslashes($soLabel)); ?>')">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle" id="serveOptTable_<?php echo $soType; ?>">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <?php if ($isSvc): ?>
                        <th>Usual Day</th>
                        <th>Usual Time</th>
                        <th>Time Slots</th>
                        <?php endif; ?>
                        <th>Notes</th>
                        <th class="text-center">Used By</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($soRows)): ?>
                    <tr><td colspan="<?php echo $isSvc ? 9 : 6; ?>" class="text-center text-muted py-4">
                        No values yet. Click <strong>Add</strong> to create the first one.
                    </td></tr>
                    <?php endif; ?>
                    <?php foreach ($soRows as $soI => $so):
                        $soActive = (int)$so['is_active'] === 1 && (int)$so['is_deleted'] === 0;
                        $soUsed   = (int)($soUsage[(int)$so['id']] ?? 0);
                        $soJson   = [
                            'id'           => (int)$so['id'],
                            'option_type'  => $so['option_type'],
                            'name'         => $so['name'],
                            'default_day'  => $so['default_day'] ?? '',
                            'default_time' => $so['default_time'] ? substr((string)$so['default_time'], 0, 5) : '',
                            'time_options' => $so['time_options'] ?? '',
                            'notes'        => $so['notes'] ?? '',
                            'is_active'    => $soActive ? 1 : 0,
                            'label'        => $soLabel,
                        ];
                    ?>
                    <tr class="<?php echo $soActive ? '' : 'opacity-75'; ?>">
                        <td class="text-muted small"><?php echo $soI + 1; ?></td>
                        <td class="fw-semibold">
                            <?php echo htmlspecialchars($so['name']); ?>
                            <?php if ((int)$so['is_deleted'] === 1): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:9px;">Archived</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($isSvc): ?>
                        <td class="small"><?php echo $so['default_day'] ? htmlspecialchars($so['default_day']) : '<span class="text-muted">—</span>'; ?></td>
                        <td class="small"><?php echo $so['default_time'] ? date('g:i A', strtotime($so['default_time'])) : '<span class="text-muted">—</span>'; ?></td>
                        <td class="small">
                            <?php
                            $soSlots = array_values(array_filter(array_map('trim', explode(',', (string)$so['time_options'])), 'strlen'));
                            if ($soSlots):
                                foreach ($soSlots as $slot): ?>
                                <span class="badge bg-light text-dark border" style="font-size:10px;"><?php echo date('g:i A', strtotime($slot)); ?></span>
                                <?php endforeach;
                            else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="small text-muted"><?php echo $so['notes'] ? htmlspecialchars($so['notes']) : '—'; ?></td>
                        <td class="text-center">
                            <?php if ($soUsed > 0): ?>
                            <span class="badge bg-primary" title="<?php echo $soUsed; ?> team(s) use this — it can only be archived, not deleted."><?php echo $soUsed; ?></span>
                            <?php else: ?>
                            <span class="badge bg-light text-muted border" title="Not used by any team — safe to delete">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $soActive ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $soActive ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                onclick="openEditServeOption(<?php echo htmlspecialchars(json_encode($soJson)); ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ((int)$so['is_deleted'] !== 1): ?>
                                <?php if ($soActive): ?>
                                <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate (hides it from new teams)"
                                   href="index.php?action=deactivateServeOption&id=<?php echo (int)$so['id']; ?>"
                                   onclick="return confirm('Deactivate this value? Existing teams keep it, but it disappears from the dropdown for new teams.')">
                                    <i class="bi bi-pause-circle"></i>
                                </a>
                                <?php else: ?>
                                <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                   href="index.php?action=activateServeOption&id=<?php echo (int)$so['id']; ?>">
                                    <i class="bi bi-play-circle"></i>
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($soUsed > 0): ?>
                            <a class="btn btn-sm btn-outline-secondary" title="Archive — teams still use this value"
                               href="index.php?action=deleteServeOption&id=<?php echo (int)$so['id']; ?>"
                               onclick="return confirm('<?php echo $soUsed; ?> team(s) use this value, so it will be ARCHIVED rather than deleted. Those teams keep it. Continue?')">
                                <i class="bi bi-archive"></i>
                            </a>
                            <?php else: ?>
                            <a class="btn btn-sm btn-outline-danger" title="Delete (unused)"
                               href="index.php?action=deleteServeOption&id=<?php echo (int)$so['id']; ?>"
                               onclick="return confirm('Delete this value permanently? No team uses it.')">
                                <i class="bi bi-trash"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($isSvc): ?>
    <div class="card-footer bg-transparent small text-muted">
        <i class="bi bi-magic me-1"></i>
        <strong>Usual Day / Time</strong> pre-fill the team form when the service is selected &mdash; the user can
        still change them. <strong>Time Slots</strong> populates the team form's <em>Service Time Slot</em> dropdown:
        list several times for a service that runs more than once (e.g. Sunday Worship at 8:30, 11:00, 2:00 and 4:30),
        or leave it empty and the Usual Time is offered as the single slot.
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- Add / Edit value -->
<div class="modal fade" id="serveOptionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-sliders me-2"></i><span id="serveOptionModalTitle">Add Value</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="serveOptionForm" action="">
                <input type="hidden" name="option_type" id="so_type">
                <div class="modal-body">
                    <div class="alert alert-info py-2 small" id="so_usage_note" style="display:none">
                        <i class="bi bi-shield-check me-1"></i>
                        <strong>0</strong> team(s) use this value. Renaming it updates those teams automatically.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="so_name" class="form-control" required
                               placeholder="e.g. Prayer Meeting">
                    </div>
                    <div class="row g-3 so-service-only">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Usual Day</label>
                            <select name="default_day" id="so_day" class="form-select">
                                <option value="">— None —</option>
                                <?php foreach ($DAYS as $d): ?>
                                <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Usual Time</label>
                            <input type="time" name="default_time" id="so_time" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Time Slots</label>
                            <input type="text" name="time_options" id="so_slots" class="form-control"
                                   placeholder="08:30, 11:00, 14:00, 16:30">
                            <div class="form-text small">
                                Comma-separated 24-hour times, for a service that runs several times a day.
                                Leave empty and the Usual Time above becomes the single slot.
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" name="notes" id="so_notes" class="form-control" placeholder="Optional">
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="so_active" checked>
                        <label class="form-check-label fw-semibold" for="so_active">Active</label>
                        <div class="form-text small">Inactive values stay on existing teams but drop off the dropdown.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
