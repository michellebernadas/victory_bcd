<?php
// Settings — Historical Discipleship Completions (admin only).
//
// The attendance records are the source of truth, but some members completed a
// step years ago and no record survives. Approving a historical completion here
// keeps them complete WITHOUT pretending attendance exists: recalculation reads
// these rows and never deletes them.
//
// If attendance later appears for the same member+step, the row is upgraded to
// `attendance` automatically — evidence outranks a manual note.
//
// Requires in scope: $historicalRows, $historicalStepList, $historicalCounts
$historicalRows     = $historicalRows     ?? [];
$historicalStepList = $historicalStepList ?? [];
$historicalCounts   = $historicalCounts   ?? ['pending' => 0, 'verified' => 0, 'rejected' => 0, 'total' => 0];
$histBySource = [];
foreach ($historicalRows as $hr) {
    if (($hr['historical_verification_status'] ?? '') === 'rejected') continue; // not an active historical completion
    $histBySource[$hr['step_name']] = ($histBySource[$hr['step_name']] ?? 0) + 1;
}
$VERIF_BADGE = ['pending' => 'warning', 'verified' => 'success', 'rejected' => 'secondary'];
$VERIF_LABEL = ['pending' => 'Needs Verification', 'verified' => 'Verified', 'rejected' => 'Rejected'];
?>
<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="card border-warning h-100">
            <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">Needs Verification</div>
                    <div class="h4 mb-0 text-warning"><?php echo (int)$historicalCounts['pending']; ?></div>
                </div>
                <i class="bi bi-exclamation-circle text-warning fs-3"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-success h-100">
            <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">Verified</div>
                    <div class="h4 mb-0 text-success"><?php echo (int)$historicalCounts['verified']; ?></div>
                </div>
                <i class="bi bi-check-circle text-success fs-3"></i>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-secondary h-100">
            <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small">Total Historical</div>
                    <div class="h4 mb-0"><?php echo (int)$historicalCounts['total']; ?></div>
                </div>
                <i class="bi bi-clock-history text-secondary fs-3"></i>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-white fw-semibold">
            <i class="bi bi-clock-history me-2"></i>Historical Completions
            <span class="badge bg-white text-dark border ms-1"><?php echo count($historicalRows); ?></span>
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="col-toggle" data-table="historicalTable" data-locked="0"></span>
            <button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="modal" data-bs-target="#recalcConfirmModal"
               title="Recalculate every member's Discipleship Journey from the current source-of-truth evidence: attendance records, Leadership 1-1-3 completion, Spiritual Foundations completion, and approved historical completions.">
                <i class="bi bi-arrow-repeat me-1"></i>Recalculate All Progress
            </button>
            <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addHistoricalModal">
                <i class="bi bi-plus-lg me-1"></i>Add
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="alert alert-info m-3 mb-0 py-2 small">
            <i class="bi bi-info-circle me-1"></i>
            Historical completions are legacy records preserved because the member was previously marked
            complete but sufficient attendance/class evidence is unavailable. Review these records and
            confirm whether the completion is valid.
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center m-3 mb-0">
            <select id="histFilterStatus" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Statuses</option>
                <option value="Needs Verification">Needs Verification</option>
                <option value="Verified">Verified</option>
                <option value="Rejected">Rejected</option>
            </select>
            <select id="histFilterStep" class="form-select form-select-sm" style="width:auto;">
                <option value="">All Steps</option>
                <?php foreach ($historicalStepList as $hs): ?>
                <option value="<?php echo htmlspecialchars($hs['name']); ?>"><?php echo htmlspecialchars($hs['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" id="histFilterMember" class="form-control form-control-sm" style="width:220px;" placeholder="Search member…">
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle" id="historicalTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Member</th>
                        <th>Step</th>
                        <th>Completed</th>
                        <th>Verification Status</th>
                        <th>Notes</th>
                        <th>Approved By</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historicalRows as $hi => $hr):
                        $vstatus = $hr['historical_verification_status'] ?? 'pending';
                        $vbadge  = $VERIF_BADGE[$vstatus] ?? 'secondary';
                        $vlabel  = $VERIF_LABEL[$vstatus] ?? ucfirst($vstatus);
                    ?>
                    <tr>
                        <td class="text-muted small"><?php echo $hi + 1; ?></td>
                        <td>
                            <a href="index.php?action=memberProfile&id=<?php echo (int)$hr['member_id']; ?>"
                               class="fw-semibold text-decoration-none"><?php echo htmlspecialchars($hr['full_name']); ?></a>
                            <?php if (($hr['member_status'] ?? '') !== 'active'): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:9px;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <span class="badge bg-<?php echo htmlspecialchars($hr['step_color'] ?: 'secondary'); ?>"><?php echo htmlspecialchars($hr['abbreviation']); ?></span>
                            <?php echo htmlspecialchars($hr['step_name']); ?>
                        </td>
                        <td class="small">
                            <?php if (!empty($hr['completed_at'])): ?>
                            <?php echo date('M j, Y', strtotime($hr['completed_at'])); ?>
                            <?php else: ?>
                            <span class="text-muted" title="Date unknown — deliberately not invented">Unknown</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <span class="badge bg-<?php echo $vbadge; ?>"><?php echo htmlspecialchars($vlabel); ?></span>
                        </td>
                        <td class="small text-muted" style="max-width:280px;">
                            <?php echo $hr['notes'] ? htmlspecialchars($hr['notes']) : '—'; ?>
                        </td>
                        <td class="small">
                            <?php if (!empty($hr['verified_by_name'])): ?>
                            <?php echo htmlspecialchars($hr['verified_by_name']); ?>
                            <div class="text-muted" style="font-size:10px;">
                                <?php echo !empty($hr['verified_at']) ? date('M j, Y', strtotime($hr['verified_at'])) : ''; ?>
                            </div>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <?php
                            $actionData = json_encode([
                                'member_id'   => (int)$hr['member_id'],
                                'step_id'     => (int)$hr['step_id'],
                                'member_name' => $hr['full_name'],
                                'step_name'   => $hr['step_name'],
                                'abbreviation'=> $hr['abbreviation'],
                                'step_color'  => $hr['step_color'] ?: 'secondary',
                                'has_other_evidence' => !empty($hr['has_other_evidence']),
                            ]);
                            ?>
                            <?php if ($vstatus === 'pending'): ?>
                            <button type="button" class="btn btn-sm btn-outline-success"
                               title="Verify Completion"
                               onclick="openVerifyHistoricalModal(<?php echo htmlspecialchars($actionData); ?>)">
                                <i class="bi bi-check-lg me-1"></i>Verify Completion
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                               title="Reject Historical Completion"
                               onclick="openRejectPendingModal(<?php echo htmlspecialchars($actionData); ?>)">
                                <i class="bi bi-x-lg me-1"></i>Reject Historical Completion
                            </button>
                            <?php elseif ($vstatus === 'verified'): ?>
                            <button type="button" class="btn btn-sm btn-outline-warning"
                               title="Reopen for Review"
                               onclick="openReopenModal(<?php echo htmlspecialchars($actionData); ?>)">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen for Review
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger"
                               title="Reject Completion"
                               onclick="openRejectVerifiedModal(<?php echo htmlspecialchars($actionData); ?>)">
                                <i class="bi bi-x-lg me-1"></i>Reject Completion
                            </button>
                            <?php elseif ($vstatus === 'rejected'): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary"
                               title="Restore for Review"
                               onclick="openRestoreModal(<?php echo htmlspecialchars($actionData); ?>)">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Restore for Review
                            </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                               title="Review History"
                               onclick="openReviewHistoryModal(<?php echo htmlspecialchars($actionData); ?>)">
                                <i class="bi bi-clock-history"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($histBySource): ?>
    <div class="card-footer bg-transparent small text-muted">
        <i class="bi bi-bar-chart me-1"></i>By step:
        <?php $parts = []; foreach ($histBySource as $sn => $c) $parts[] = htmlspecialchars($sn) . ' (' . $c . ')';
              echo implode(' &middot; ', $parts); ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add historical completion -->
<div class="modal fade" id="addHistoricalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Add Historical Completion</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="index.php?action=addHistoricalCompletion">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
                        <select name="member_id" class="form-select hist-member-select2" required style="width:100%">
                            <option value=""></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Discipleship Step <span class="text-danger">*</span></label>
                        <select name="step_id" class="form-select" required>
                            <option value="">— Select step —</option>
                            <?php foreach ($historicalStepList as $hs): ?>
                            <option value="<?php echo (int)$hs['id']; ?>">
                                <?php echo htmlspecialchars($hs['name']); ?>
                                <?php echo (int)$hs['is_active'] ? '' : ' (inactive step)'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">
                            One entry per member + step — re-adding the same pair updates it rather than duplicating.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Completed Date
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <input type="date" name="completed_at" class="form-control">
                        <div class="form-text small">Leave empty if unknown — a date is never invented.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Notes
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <input type="text" name="notes" class="form-control" maxlength="255"
                               placeholder="e.g. From 2018 paper records, confirmed by Ptr. Juan">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Approve Completion</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject historical completion (from Needs Verification) — confirmation modal -->
<div class="modal fade" id="removeHistoricalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?action=rejectHistoricalCompletion" id="rh_form">
                <input type="hidden" name="member_id" id="rh_member_id">
                <input type="hidden" name="step_id" id="rh_step_id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Reject Historical Completion?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Member:</strong> <span id="rh_member_name"></span></p>
                    <p class="mb-3"><strong>Step:</strong>
                        <span class="badge" id="rh_step_badge"></span>
                        <span id="rh_step_name"></span>
                    </p>
                    <p class="small">
                        This will reject the historical completion record for this discipleship step. The
                        record is kept — not deleted — so who rejected it, when, and why stays on file.
                    </p>
                    <p class="small">
                        The member record and all other attendance/class records will remain unchanged.
                    </p>
                    <p class="small mb-3">
                        After rejection, the system will recalculate this discipleship step from the available evidence.
                    </p>
                    <div id="rh_evidence_note" class="alert small mb-3"></div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Notes
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <input type="text" name="notes" class="form-control" maxlength="255"
                               placeholder="Reason for rejecting this historical completion">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="rh_confirm_btn" class="btn btn-danger">
                        <i class="bi bi-x-lg me-1"></i>Reject Historical Completion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject a previously VERIFIED completion — confirmation modal -->
<div class="modal fade" id="rejectVerifiedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?action=rejectHistoricalCompletion" id="rv_form">
                <input type="hidden" name="member_id" id="rv_member_id">
                <input type="hidden" name="step_id" id="rv_step_id">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Reject Verified Historical Completion?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Member:</strong> <span id="rv_member_name"></span></p>
                    <p class="mb-3"><strong>Step:</strong>
                        <span class="badge" id="rv_step_badge"></span>
                        <span id="rv_step_name"></span>
                    </p>
                    <p class="small">This completion was previously verified.</p>
                    <p class="small">
                        Rejecting it will remove the historical completion as valid evidence and recalculate
                        the member's discipleship progress.
                    </p>
                    <p class="small mb-3">
                        If no other qualifying attendance or class evidence exists, this step will become incomplete.
                    </p>
                    <div id="rv_evidence_note" class="alert small mb-3"></div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                        <input type="text" name="notes" id="rv_notes" class="form-control" maxlength="255" required
                               placeholder="Why is this previously verified completion being rejected?">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg me-1"></i>Reject Completion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reopen a VERIFIED completion for review — confirmation modal -->
<div class="modal fade" id="reopenHistoricalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?action=reopenHistoricalCompletion" id="ro_form">
                <input type="hidden" name="member_id" id="ro_member_id">
                <input type="hidden" name="step_id" id="ro_step_id">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Reopen Historical Completion for Review?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Member:</strong> <span id="ro_member_name"></span></p>
                    <p class="mb-3"><strong>Step:</strong>
                        <span class="badge" id="ro_step_badge"></span>
                        <span id="ro_step_name"></span>
                    </p>
                    <p class="small">This completion is currently verified.</p>
                    <p class="small">
                        Reopening it will change its verification status to Needs Verification so it can be
                        reviewed again.
                    </p>
                    <p class="small mb-3">
                        The historical completion will continue to count as completed while it is pending review.
                    </p>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Notes
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <input type="text" name="notes" class="form-control" maxlength="255"
                               placeholder="Why is this being reopened for review?">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen for Review
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Restore a REJECTED completion for review — confirmation modal -->
<div class="modal fade" id="restoreHistoricalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?action=restoreHistoricalCompletion" id="rs_form">
                <input type="hidden" name="member_id" id="rs_member_id">
                <input type="hidden" name="step_id" id="rs_step_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-arrow-counterclockwise me-2"></i>Restore Historical Completion for Review?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Member:</strong> <span id="rs_member_name"></span></p>
                    <p class="mb-3"><strong>Step:</strong>
                        <span class="badge" id="rs_step_badge"></span>
                        <span id="rs_step_name"></span>
                    </p>
                    <p class="small">This historical completion was previously rejected.</p>
                    <p class="small">Restoring it will return the record to Needs Verification.</p>
                    <p class="small mb-3">
                        While pending review, it will again count as an approved historical completion until
                        an admin verifies or rejects it.
                    </p>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Notes
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <input type="text" name="notes" class="form-control" maxlength="255"
                               placeholder="Why is this being restored for review?">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restore for Review
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Review History — read-only, admin-only audit trail -->
<div class="modal fade" id="reviewHistoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Review History — <span id="hh_title"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="hh_loading" class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Loading…</div>
                <ul id="hh_list" class="list-group list-group-flush d-none"></ul>
                <div id="hh_empty" class="text-muted small d-none">No review history recorded for this completion yet.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Verify historical completion — confirmation modal -->
<div class="modal fade" id="verifyHistoricalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="index.php?action=verifyHistoricalCompletion" id="vh_form">
                <input type="hidden" name="member_id" id="vh_member_id">
                <input type="hidden" name="step_id" id="vh_step_id">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Verify Historical Completion?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Member:</strong> <span id="vh_member_name"></span></p>
                    <p class="mb-3"><strong>Step:</strong>
                        <span class="badge" id="vh_step_badge"></span>
                        <span id="vh_step_name"></span>
                    </p>
                    <p class="small">
                        You are confirming that this member completed this discipleship step even though
                        the portal does not have sufficient attendance/class evidence for it.
                    </p>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">Notes
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <input type="text" name="notes" class="form-control" maxlength="255"
                               placeholder="e.g. Confirmed by Ptr. Juan, 2018 paper records">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Verify Completion
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Recalculate All Progress — confirmation modal -->
<div class="modal fade" id="recalcConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-2"></i>Recalculate All Discipleship Progress?</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small">
                    This will recalculate the Discipleship Journey for all members using the current
                    source-of-truth evidence.
                </p>
                <p class="small mb-1">The system will check:</p>
                <ul class="small">
                    <li>Attendance Records</li>
                    <li>Leadership 1-1-3 completion</li>
                    <li>Spiritual Foundations completion</li>
                    <li>Approved Historical Completions</li>
                </ul>
                <p class="small">
                    It will then synchronize each member's derived discipleship status.
                    No attendance, class, member, or historical records will be deleted.
                </p>
                <p class="small text-muted mb-1">
                    This is mainly a maintenance tool. Normal attendance and class updates already
                    recalculate member progress automatically.
                </p>
                <p class="small text-muted mb-0">
                    <i class="bi bi-clock me-1"></i>This may take several seconds to complete.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a id="recalcConfirmBtn" href="index.php?action=recalculateProgress" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-1"></i>Recalculate All Progress
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Member picker reuses the existing AJAX member search.
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || typeof jQuery.fn.select2 === 'undefined') return;
    jQuery('.hist-member-select2').each(function () {
        jQuery(this).select2({
            dropdownParent: jQuery(this).closest('.modal'),
            placeholder: 'Search members…',
            allowClear: true,
            minimumInputLength: 0,
            ajax: {
                url: 'index.php?action=ajaxSearchMembers',
                dataType: 'json',
                delay: 200,
                data: function (p) { return { q: p.term || '' }; },
                processResults: function (d) { return { results: (d && d.results) ? d.results : [] }; },
                cache: true
            }
        });
    });
});

// Reject Historical Completion (from Needs Verification) — populate + show the confirmation modal.
function openRejectPendingModal(d) {
    document.getElementById('rh_member_name').textContent = d.member_name;
    document.getElementById('rh_step_name').textContent   = d.step_name;
    var badge = document.getElementById('rh_step_badge');
    badge.className   = 'badge bg-' + d.step_color;
    badge.textContent = d.abbreviation;

    var note = document.getElementById('rh_evidence_note');
    if (d.has_other_evidence) {
        note.className = 'alert alert-success small mb-3';
        note.innerHTML = '<i class="bi bi-check-circle me-1"></i>Valid attendance/class completion evidence '
            + 'still exists, so the member will remain completed (Source: Attendance) after this historical '
            + 'completion is rejected.';
    } else {
        note.className = 'alert alert-warning small mb-3';
        note.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Since no other attendance or class '
            + 'completion evidence exists for this step, the member will be marked as incomplete after this '
            + 'historical completion is rejected.';
    }

    document.getElementById('rh_member_id').value = d.member_id;
    document.getElementById('rh_step_id').value   = d.step_id;

    new bootstrap.Modal(document.getElementById('removeHistoricalModal')).show();
}

// Verify Historical Completion — populate + show the confirmation modal.
function openVerifyHistoricalModal(d) {
    document.getElementById('vh_member_name').textContent = d.member_name;
    document.getElementById('vh_step_name').textContent   = d.step_name;
    var badge = document.getElementById('vh_step_badge');
    badge.className   = 'badge bg-' + d.step_color;
    badge.textContent = d.abbreviation;

    document.getElementById('vh_member_id').value = d.member_id;
    document.getElementById('vh_step_id').value   = d.step_id;

    new bootstrap.Modal(document.getElementById('verifyHistoricalModal')).show();
}

function fillBadge(prefix, d) {
    document.getElementById(prefix + '_member_name').textContent = d.member_name;
    document.getElementById(prefix + '_step_name').textContent   = d.step_name;
    var badge = document.getElementById(prefix + '_step_badge');
    badge.className   = 'badge bg-' + d.step_color;
    badge.textContent = d.abbreviation;
    document.getElementById(prefix + '_member_id').value = d.member_id;
    document.getElementById(prefix + '_step_id').value   = d.step_id;
}

// Reject a previously VERIFIED completion — populate + show the confirmation modal.
function openRejectVerifiedModal(d) {
    fillBadge('rv', d);
    document.getElementById('rv_notes').value = '';

    var note = document.getElementById('rv_evidence_note');
    if (d.has_other_evidence) {
        note.className = 'alert alert-success small mb-3';
        note.innerHTML = '<i class="bi bi-check-circle me-1"></i>Valid attendance/class completion evidence '
            + 'still exists, so the member will remain completed (Source: Attendance) after this verified '
            + 'completion is rejected.';
    } else {
        note.className = 'alert alert-warning small mb-3';
        note.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Since no other attendance or class '
            + 'completion evidence exists for this step, the member will be marked as incomplete after this '
            + 'verified completion is rejected.';
    }

    new bootstrap.Modal(document.getElementById('rejectVerifiedModal')).show();
}

// Reopen a VERIFIED completion for review — populate + show the confirmation modal.
function openReopenModal(d) {
    fillBadge('ro', d);
    new bootstrap.Modal(document.getElementById('reopenHistoricalModal')).show();
}

// Restore a REJECTED completion for review — populate + show the confirmation modal.
function openRestoreModal(d) {
    fillBadge('rs', d);
    new bootstrap.Modal(document.getElementById('restoreHistoricalModal')).show();
}

// Review History — fetch and render the audit trail for one member+step.
function openReviewHistoryModal(d) {
    document.getElementById('hh_title').textContent = d.member_name + ' / ' + d.step_name;
    var loading = document.getElementById('hh_loading');
    var list    = document.getElementById('hh_list');
    var empty   = document.getElementById('hh_empty');
    loading.classList.remove('d-none');
    list.classList.add('d-none');
    empty.classList.add('d-none');
    list.innerHTML = '';

    var ACTION_LABEL = { verified: 'Verified', rejected: 'Rejected', reopened: 'Reopened', restored: 'Restored for Review' };
    var ACTION_ICON  = { verified: 'bi-check-circle text-success', rejected: 'bi-x-circle text-danger',
                         reopened: 'bi-arrow-counterclockwise text-warning', restored: 'bi-arrow-counterclockwise text-primary' };

    fetch('index.php?action=ajaxHistoricalReviewHistory&member_id=' + encodeURIComponent(d.member_id)
        + '&step_id=' + encodeURIComponent(d.step_id))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            loading.classList.add('d-none');
            var entries = (data && data.entries) ? data.entries : [];
            if (!entries.length) {
                empty.classList.remove('d-none');
                return;
            }
            entries.forEach(function (e) {
                var li = document.createElement('li');
                li.className = 'list-group-item';
                var when = e.performed_at ? new Date(e.performed_at.replace(' ', 'T')).toLocaleDateString('en-US',
                    { year: 'numeric', month: 'short', day: 'numeric' }) : '';
                var who = e.performed_by_name || 'Unknown';
                var icon = ACTION_ICON[e.action] || 'bi-clock-history text-secondary';
                var label = ACTION_LABEL[e.action] || e.action;
                li.innerHTML = '<div class="small text-muted">' + when + '</div>'
                    + '<div><i class="bi ' + icon + ' me-1"></i><strong>' + label + '</strong> by '
                    + (who ? who.replace(/</g, '&lt;') : 'Unknown') + '</div>'
                    + (e.notes ? '<div class="small fst-italic mt-1">"' + e.notes.replace(/</g, '&lt;') + '"</div>' : '');
                list.appendChild(li);
            });
            list.classList.remove('d-none');
        })
        .catch(function () {
            loading.classList.add('d-none');
            empty.classList.remove('d-none');
            empty.textContent = 'Could not load review history.';
        });

    new bootstrap.Modal(document.getElementById('reviewHistoryModal')).show();
}

// Recalculate All Progress — disable + spin the confirm button so a double click
// can't fire the (potentially 20-30s) recalculation twice. The page navigates away
// on success, so there is nothing to "reset" the button back to.
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('recalcConfirmBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (btn.dataset.busy === '1') return;
        btn.dataset.busy = '1';
        btn.classList.add('disabled');
        btn.setAttribute('aria-disabled', 'true');
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Recalculating…';
    });
});

// Historical Completions table — DataTables pagination (50 rows/page), consistent
// with the rest of the portal. Columns toggle (col-toggle) keeps working against
// the same DataTable instance via column().visible().
document.addEventListener('DOMContentLoaded', function () {
    if (typeof $ === 'undefined' || typeof $.fn.DataTable === 'undefined' || !$('#historicalTable').length) return;
    window.historicalTable = $('#historicalTable').DataTable({
        responsive: true,
        pageLength: 50,
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
        // No initial sort — preserves the server's "pending first" ordering so
        // unreviewed records surface by default. Columns stay clickable to re-sort.
        order: [],
        columnDefs: [
            { targets: 0, orderable: false, searchable: false },
            { targets: -1, orderable: false, searchable: false }
        ],
        language: {
            search: 'Search:',
            lengthMenu: 'Show _MENU_ entries per page',
            emptyTable: 'No historical completions recorded. Everything is backed by attendance.'
        },
        drawCallback: function () {
            // column(0,{page:'current'}) indexes from 0 WITHIN the current page, so
            // without the page offset every page after the first renders 1, 2, 3…
            // instead of continuing from the previous page (51, 52, 53…).
            var start = this.api().page.info().start;
            this.api().column(0, { page: 'current' }).nodes().each(function (cell, i) {
                cell.innerHTML = (start + i + 1);
            });
        }
    });

    // Verification Status / Step / Member filters compose with the existing
    // search box — all route through DataTables' own column search so paging
    // and the columns-toggle widget keep working unchanged.
    $('#histFilterStatus').on('change', function () {
        historicalTable.column(4).search(this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) + '$' : '', true, false).draw();
    });
    $('#histFilterStep').on('change', function () {
        historicalTable.column(2).search(this.value ? $.fn.dataTable.util.escapeRegex(this.value) : '', true, false).draw();
    });
    $('#histFilterMember').on('keyup', function () {
        historicalTable.column(1).search(this.value).draw();
    });
});
</script>
