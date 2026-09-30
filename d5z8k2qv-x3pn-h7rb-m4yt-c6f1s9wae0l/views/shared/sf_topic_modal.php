<?php
// Add / Edit Spiritual Foundations curriculum topic.
// Requires in scope: $sfTopicModalId, $sfTopicFormId, $sfTopicAction, $sfTopicTitle,
//                    $sfWeekCount, $sfHasAttendance
$nextWeek = (int)($sfWeekCount ?? 0) + 1;
?>
<div class="modal fade" id="<?php echo $sfTopicModalId; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-journal-text me-2"></i><?php echo htmlspecialchars($sfTopicTitle); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="<?php echo $sfTopicFormId; ?>" action="<?php echo htmlspecialchars($sfTopicAction); ?>">
            <div class="modal-body">
                <div class="alert alert-info py-2 small sf-usage-note" style="display:none">
                    <i class="bi bi-shield-check me-1"></i>
                    <strong>0</strong> attendance record(s) reference this topic. Renaming it is safe —
                    history is stored against the week number, not the name.
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Week / Order <span class="text-danger">*</span></label>
                        <input type="number" name="week_no" class="form-control" min="1" step="1"
                               value="<?php echo $sfTopicModalId === 'addSfTopicModal' ? $nextWeek : ''; ?>" required>
                        <div class="form-text small sf-week-lock-note" style="display:none">
                            <i class="bi bi-lock me-1"></i>Locked — attendance exists for this curriculum.
                        </div>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label fw-semibold">Topic Name <span class="text-danger">*</span></label>
                        <input type="text" name="topic" class="form-control" required
                               placeholder="e.g. Holy Spirit and Spiritual Gifts">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Subtopics
                            <span class="text-muted fw-normal small">(one per line)</span>
                        </label>
                        <textarea name="subtopics" class="form-control" rows="6"
                                  placeholder="The Holy Spirit&#10;Introduction to Spiritual Gifts&#10;Spiritual Gifts&#10;Practicing Spiritual Gifts"></textarea>
                    </div>

                    <div class="col-md-6 sf-required-wrap">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_required" value="1"
                                   id="<?php echo $sfTopicFormId; ?>_req" checked>
                            <label class="form-check-label fw-semibold" for="<?php echo $sfTopicFormId; ?>_req">
                                Required for certificate
                            </label>
                        </div>
                        <div class="form-text small">Counts towards the participant's total required topics.</div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                   id="<?php echo $sfTopicFormId; ?>_act" checked>
                            <label class="form-check-label fw-semibold" for="<?php echo $sfTopicFormId; ?>_act">
                                Active
                            </label>
                        </div>
                        <div class="form-text small">Inactive topics stay on historical records but drop off new attendance forms.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-info text-white"><i class="bi bi-save me-1"></i>Save Topic</button>
            </div>
            </form>
        </div>
    </div>
</div>
