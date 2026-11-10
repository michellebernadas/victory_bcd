<?php
/**
 * Edit Member modal + openEditModal(), shared by the Members list and the
 * Member Profile so there is ONE edit form. The controller (MemberController::
 * updateMember) owns normalisation and validation; a hidden return_to=profile
 * sends the save back to the profile it came from.
 *
 * Requires in scope: $ministries, $services. Optional: $memberEditReturn ('profile').
 */
$CIVIL_STATUSES   = $CIVIL_STATUSES ?? ['Single', 'Student', 'Married', 'Widowed', 'Separated'];
$memberEditReturn = $memberEditReturn ?? '';
?>
    <!-- Edit Member Modal -->
    <div class="modal fade" id="editMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editMemberForm" action="" class="member-form">
                <?php if ($memberEditReturn === 'profile'): ?>
                <input type="hidden" name="return_to" value="profile">
                <?php endif; ?>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Life Stage <span class="text-danger">*</span></label>
                            <select name="civil_status" id="edit_civil_status" class="form-select member-select2" required>
                                <option value="">— Select —</option>
                                <?php foreach ($CIVIL_STATUSES as $cs): ?>
                                <option value="<?php echo $cs; ?>"><?php echo $cs; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Member Status <span class="text-danger">*</span></label>
                            <select name="member_status" id="edit_member_status" class="form-select member-select2" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ministry <span class="text-danger">*</span> <span class="text-muted small">(select one or more)</span></label>
                            <select name="ministry[]" id="edit_ministry" class="form-select member-select2-multi" multiple required>
                                <option value="No Ministry">No Ministry</option>
                                <?php foreach ($ministries as $min): if (strcasecmp($min['name'], 'No Ministry') === 0) continue; ?>
                                <option value="<?php echo htmlspecialchars($min['name']); ?>"><?php echo htmlspecialchars($min['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Service Attending <span class="text-danger">*</span> <span class="text-muted small">(select one or more)</span></label>
                            <select name="service_attending[]" id="edit_service_attending" class="form-select member-select2-multi" multiple required>
                                <?php foreach ($services as $svc): ?>
                                <option value="<?php echo htmlspecialchars($svc['name']); ?>"><?php echo htmlspecialchars($svc['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number" id="edit_contact_number" placeholder="09XXXXXXXXX">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Email <span class="text-muted small fw-normal">(optional)</span></label>
                            <input type="email" class="form-control" name="email" id="edit_email" placeholder="name@example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Facebook Profile <span class="text-muted small fw-normal">(optional)</span></label>
                            <input type="url" class="form-control" name="facebook_url" id="edit_facebook_url" placeholder="https://facebook.com/username">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold d-block">Discipleship Journey</label>
                            <?php // Read-only: completion is derived by DiscipleshipProgressService
                                  // from attendance evidence plus admin-approved historical records.
                                  // No editable control here can set it. ?>
                            <div class="alert alert-info py-2 mb-0 small">
                                <i class="bi bi-shield-check me-1"></i>
                                Discipleship Journey is managed automatically from attendance and class
                                completion records. Historical completions can be managed by an administrator
                                under <strong>Settings &rarr; Historical Completions</strong>.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Update Member</button>
                </div>
                </form>
            </div>
        </div>
    </div>
<script>
    function openEditModal(member) {
        document.getElementById('edit_last_name').value          = member.last_name  || '';
        document.getElementById('edit_first_name').value         = member.first_name || '';
        document.getElementById('edit_civil_status').value       = member.civil_status || '';
        document.getElementById('edit_member_status').value      = member.member_status;
        document.getElementById('edit_contact_number').value     = member.contact_number || '';
        document.getElementById('edit_email').value              = member.email || '';
        document.getElementById('edit_facebook_url').value       = member.facebook_url || '';
        document.getElementById('edit_notes').value              = member.notes || '';

        // Ministry & service are stored as comma-separated strings (or legacy single value).
        // Split and set as multi-select values; trigger Select2 update.
        function setMulti($sel, csv) {
            var vals = (csv || '').split(',').map(function(s){return s.trim();}).filter(Boolean);
            vals.forEach(function(v) {
                var exists = false;
                $sel.find('option').each(function() { if (this.value === v) exists = true; });
                if (!exists) $sel.append(new Option(v + '  —  (inactive)', v));
            });
            $sel.val(vals).trigger('change');
        }
        setMulti($('#edit_ministry'),          member.ministry);
        setMulti($('#edit_service_attending'), member.service_attending);

        // Single-select dropdowns now wrapped in Select2 — also need change.select2 trigger
        $('#edit_civil_status, #edit_member_status').trigger('change.select2');

        // No discipleship controls to populate here any more — completion is
        // derived, so the form shows a read-only note instead. The member's
        // actual progress (and its source) lives on their profile page.

        document.getElementById('editMemberForm').action = 'index.php?action=updateMember&id=' + member.id;
        var modal = new bootstrap.Modal(document.getElementById('editMemberModal'));
        modal.show();
    }
</script>
<script>
// Select2 for the edit form, wherever the modal is included. Bound once, and
// only after jQuery/Select2 (footer) are loaded.
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !jQuery.fn.select2 || window.__memberEditSelect2) return;
    window.__memberEditSelect2 = true;
    jQuery(document).on('shown.bs.modal', '#editMemberModal', function () {
        var $m = jQuery(this);
        $m.find('.member-select2:not(.select2-hidden-accessible)').select2({ dropdownParent: $m, width: '100%' });
        $m.find('.member-select2-multi:not(.select2-hidden-accessible)').select2({
            dropdownParent: $m, width: '100%', closeOnSelect: false, placeholder: '— Select one or more —'
        });
    });
});
</script>
