<?php
// Add / Edit Serve Team modal.
//
// Every field here is required: without a service, day, time or place nobody
// knows when or where to show up, and without a leader plus at least one member
// there is no team. The server re-checks all of it (ServeTeam::validate) — the
// `required` attributes are just the fast feedback.
//
// Requires in scope: $stModalId, $stFormId, $stAction, $stTitle,
//                    $ministries, $serveOptions, $DAYS
$stServices = $serveOptions['service'] ?? [];
$stPlaces   = $serveOptions['place']   ?? [];
?>
<div class="modal fade" id="<?php echo $stModalId; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-hand-thumbs-up me-2"></i><?php echo htmlspecialchars($stTitle); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="<?php echo $stFormId; ?>" action="<?php echo htmlspecialchars($stAction); ?>" class="st-form">
            <div class="modal-body">
                <div class="alert alert-danger py-2 small st-form-error" style="display:none"></div>

                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">Team Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control st-name" required placeholder="e.g. Ushering Team A">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Ministry <span class="text-danger">*</span></label>
                        <select name="ministry" class="form-select st-ministry" required>
                            <option value="">— Select ministry —</option>
                            <?php foreach ($ministries as $min): ?>
                            <option value="<?php echo htmlspecialchars($min['name']); ?>"><?php echo htmlspecialchars($min['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">From Settings &rsaquo; Ministries.</div>
                    </div>

                    <!-- Service drives the Day / Time defaults below. -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Service <span class="text-danger">*</span></label>
                        <select name="service_name" class="form-select st-service" required>
                            <option value="">— Select service / activity —</option>
                            <?php foreach ($stServices as $svc): ?>
                            <option value="<?php echo htmlspecialchars($svc['name']); ?>">
                                <?php echo htmlspecialchars($svc['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small st-service-note">
                            Picking a service fills in its usual day and time &mdash; you can still change them.
                        </div>
                    </div>
                    <div class="col-md-6 st-slot-wrap" style="display:none">
                        <label class="form-label fw-semibold">Service Time Slot</label>
                        <select name="service_time" class="form-select st-slot">
                            <option value="">— Any / not applicable —</option>
                        </select>
                        <div class="form-text small st-slot-hint">
                            Which run of the service this team covers.
                        </div>
                    </div>

                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Day <span class="text-danger">*</span></label>
                        <select name="day_of_week[]" class="form-select st-day" multiple required data-placeholder="Select day(s)…">
                            <?php foreach ($DAYS as $d): ?>
                            <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">Pick one or more.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Call Time <span class="text-danger">*</span></label>
                        <input type="time" name="meetup_time" class="form-control st-time" required>
                        <div class="form-text small">What time the team reports.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Place <span class="text-danger">*</span></label>
                        <select name="meeting_place" class="form-select st-place" required>
                            <option value="">— Select place —</option>
                            <?php foreach ($stPlaces as $pl): ?>
                            <option value="<?php echo htmlspecialchars($pl['name']); ?>"><?php echo htmlspecialchars($pl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Team Leader(s) <span class="text-danger">*</span></label>
                        <select name="leader_ids[]" class="form-select st-people st-leaders" multiple required style="width:100%" data-placeholder="Search or type a leader…"></select>
                        <div class="form-text small">
                            Search existing members, or type a name and press Enter for someone not yet registered.
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Team Members <span class="text-danger">*</span></label>
                        <select name="member_ids[]" class="form-select st-people st-members" multiple required style="width:100%" data-placeholder="Search or type a member…"></select>
                        <div class="form-text small">At least one member is required.</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="team_status" class="form-select st-status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control st-notes" rows="2" placeholder="Optional"></textarea>
                    </div>
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
