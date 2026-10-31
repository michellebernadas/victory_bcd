<?php
/**
 * Facility Booking modals.
 *
 *   #facilityRequestModal   — the policy section 7 A–F request form (step 1)
 *   #facilitiesReviewModal  — policy section 8  (step 2)
 *   #ministerDecisionModal  — policy section 9  (step 3)
 *   #confirmRequestModal    — policy section 5 step 4 / 20 (step 4, official)
 *   #cancelRequestModal     — policy section 18
 *   #facilityDetailModal    — read-only, opened from a calendar entry
 *   #facilityHistoryModal   — AJAX append-only approval trail
 *
 * Each workflow modal is rendered only for an account that may actually
 * perform that step (config/permissions.php — the same helpers that gate the
 * route, the controller and the row buttons). Withholding the markup, not just
 * the button, means the form a user may not submit never reaches their browser
 * at all. The request form, the read-only detail and the history trail are
 * rendered for everyone who can see the page.
 */
$facilities  = $facilities  ?? [];
$ministries  = $ministries  ?? [];
$allMembers  = $allMembers  ?? [];
$requestTypes = $requestTypes ?? FacilityRequest::REQUEST_TYPES;
$currentUserType = $currentUserType ?? ($_SESSION['user']['accounttype'] ?? null);
?>

<!-- ── Step 1: Request form (policy section 7 A–F) ────────────────────────── -->
<div class="modal fade" id="facilityRequestModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" action="index.php?action=submitFacilityRequest" id="frForm">
                <div class="modal-header bg-purple text-white">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-2"></i>Facilities, Equipment &amp; Resource Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <div class="alert alert-light border small py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        Submitting this form does not reserve anything. It goes to Facilities Personnel for
                        review, then to the Minister for approval, and only becomes an official reservation
                        once Facilities confirms it.
                    </div>

                    <!-- A. Requestor information -->
                    <h6 class="fw-bold text-purple border-bottom pb-1">A. Requestor Information</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Linked Member <span class="text-muted">(optional)</span></label>
                            <select name="requestor_member_id" id="fr_member" class="form-select modal-select2">
                                <option value="">Not a member record</option>
                                <?php foreach ($allMembers as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($m['full_name']); ?>"
                                        data-ministry="<?php echo htmlspecialchars($m['ministry'] ?? ''); ?>"
                                        data-contact="<?php echo htmlspecialchars($m['contact_number'] ?? ''); ?>"
                                        data-email="<?php echo htmlspecialchars($m['email'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($m['full_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size:.7rem">
                                Staff and volunteers are not always member records, so this is never required.
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Name <span class="text-danger">*</span></label>
                            <input type="text" name="requestor_name" id="fr_name" class="form-control" required maxlength="255">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Ministry / Department</label>
                            <input type="text" name="ministry" id="fr_ministry" class="form-control"
                                   list="fbMinistryList" maxlength="100">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Ministry Leader</label>
                            <input type="text" name="ministry_leader" class="form-control" maxlength="255">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Contact Number</label>
                            <input type="text" name="contact_number" id="fr_contact" class="form-control" maxlength="100">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Email</label>
                            <input type="email" name="email" id="fr_email" class="form-control" maxlength="255">
                        </div>
                    </div>

                    <!-- B. Request details -->
                    <h6 class="fw-bold text-purple border-bottom pb-1">B. Request Details</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-12">
                            <label class="form-label small mb-1">Type of Request</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach ($requestTypes as $key => $label): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="request_type[]"
                                           value="<?php echo htmlspecialchars($key); ?>"
                                           id="fr_type_<?php echo htmlspecialchars($key); ?>"
                                           <?php echo $key === 'facility_room' ? 'checked' : ''; ?>>
                                    <label class="form-check-label small" for="fr_type_<?php echo htmlspecialchars($key); ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text" style="font-size:.7rem">
                                A request may fall under more than one category (policy section 4).
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Event / Activity <span class="text-danger">*</span></label>
                            <input type="text" name="event_activity" class="form-control" required maxlength="255">
                        </div>
                        <div class="col-12">
                            <label class="form-label small mb-1">Purpose of Request</label>
                            <textarea name="purpose" class="form-control" rows="2"
                                      placeholder="Identify the primary purpose in relation to the 4Es of Discipleship — e.g. Establish — ..."></textarea>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Date Needed</label>
                            <input type="date" name="date_needed" id="fr_date" class="form-control">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Start Time</label>
                            <input type="time" name="start_time" id="fr_start" class="form-control">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">End Time</label>
                            <input type="time" name="end_time" id="fr_end" class="form-control">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small mb-1">Expected Attendance</label>
                            <input type="number" name="expected_attendance" class="form-control" min="0">
                        </div>
                    </div>

                    <!-- C. Facility / room -->
                    <h6 class="fw-bold text-purple border-bottom pb-1">C. Facility / Room</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Requested Facility / Room</label>
                            <select name="facility_id" id="fr_facility" class="form-select">
                                <option value="">No specific room (equipment / resource only)</option>
                                <?php foreach ($facilities as $f): ?>
                                <option value="<?php echo (int)$f['id']; ?>"
                                        data-lead="<?php echo (int)$f['default_lead_days']; ?>">
                                    <?php echo htmlspecialchars($f['name']); ?>
                                    (<?php echo (int)$f['default_lead_days']; ?>d lead)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" style="font-size:.7rem">
                                Leave blank for an equipment-only request — nothing is occupied, so no room conflict applies.
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Preferred Setup</label>
                            <input type="text" name="preferred_setup" class="form-control" maxlength="255"
                                   placeholder="e.g. Classroom arrangement">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Number of Chairs</label>
                            <input type="number" name="num_chairs" class="form-control" min="0">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Number of Tables</label>
                            <input type="number" name="num_tables" class="form-control" min="0">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Other Requirements</label>
                            <input type="text" name="other_requirements" class="form-control">
                        </div>
                    </div>

                    <!-- Live availability / lead-time advisory. Never blocks submit. -->
                    <div id="fr_advisory" class="mb-3"></div>

                    <!-- D. Equipment -->
                    <h6 class="fw-bold text-purple border-bottom pb-1">D. Equipment</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small mb-1">Equipment Requested</label>
                            <textarea name="equipment_requested" class="form-control" rows="2"
                                      placeholder="e.g. Microphone and speaker"></textarea>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Quantity</label>
                            <input type="text" name="equipment_quantity" class="form-control" maxlength="100"
                                   placeholder="e.g. 1 pc. each">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1">Person Responsible</label>
                            <input type="text" name="person_responsible" class="form-control" maxlength="255">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small mb-1">Technical Assistance Required?</label>
                            <select name="technical_assistance" class="form-select">
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label small mb-1">Details</label>
                            <input type="text" name="technical_assistance_details" class="form-control">
                        </div>
                    </div>

                    <!-- E. Additional requirements -->
                    <h6 class="fw-bold text-purple border-bottom pb-1">E. Additional Requirements</h6>
                    <div class="mb-3">
                        <div class="d-flex flex-wrap gap-3 mb-2">
                            <?php
                            $extras = [
                                'needs_setup'     => 'Setup assistance',
                                'needs_technical' => 'Technical assistance',
                                'needs_security'  => 'Security / access',
                                'needs_cleaning'  => 'Cleaning / special preparation',
                                'needs_other'     => 'Other',
                            ];
                            foreach ($extras as $key => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="<?php echo $key; ?>" value="1"
                                       id="fr_<?php echo $key; ?>">
                                <label class="form-check-label small" for="fr_<?php echo $key; ?>">
                                    <?php echo htmlspecialchars($label); ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <label class="form-label small mb-1">Details</label>
                        <textarea name="additional_details" class="form-control" rows="2"></textarea>
                    </div>

                    <!-- F. Requestor's acknowledgment -->
                    <h6 class="fw-bold text-purple border-bottom pb-1">F. Requestor's Acknowledgment</h6>
                    <div class="mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="acknowledged" value="1"
                                   id="fr_ack" required>
                            <label class="form-check-label small" for="fr_ack">
                                I acknowledge that approval is subject to the availability of the requested facility
                                or resource and the approval of the Minister. I agree to use church facilities and
                                equipment responsibly, follow the instructions of Facilities Personnel, report any
                                damage or malfunction, and return the facility or equipment in the appropriate condition.
                                <span class="text-danger">*</span>
                            </label>
                        </div>
                        <div class="mt-2" style="max-width:360px">
                            <label class="form-label small mb-1">Requestor (signature name)</label>
                            <input type="text" name="acknowledged_name" id="fr_ack_name" class="form-control" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Step 2: Facilities review (policy section 8) ───────────────────────── -->
<?php if (canFacilitiesReview($currentUserType)): ?>
<div class="modal fade" id="facilitiesReviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="fvForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i>Facilities Review — Step 2 of 4</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Request:</strong> <span id="fv_event"></span></p>
                    <p class="small text-muted" id="fv_meta"></p>

                    <div id="fv_conflicts"></div>

                    <div class="alert alert-light border small py-2">
                        <i class="bi bi-info-circle me-1"></i>
                        This review is <strong>logistical only</strong>. Facilities Personnel do not give ministry
                        approval (policy section 5 step 2) — saving this forwards the request to the Minister.
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold mb-1">Facility / Equipment Available</label>
                            <select name="fac_available" class="form-select">
                                <option value="yes">Yes</option>
                                <option value="no">No</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold mb-1">Existing Schedule Conflict</label>
                            <select name="fac_schedule_conflict" id="fv_conflict" class="form-select">
                                <option value="none">None</option>
                                <option value="yes">Yes</option>
                            </select>
                            <div class="form-text" style="font-size:.7rem">Pre-filled from the live calendar check.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold mb-1">Setup Required</label>
                            <select name="fac_setup_required" class="form-select">
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold mb-1">Technical Support Required</label>
                            <select name="fac_technical_required" class="form-select">
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1">Other Considerations</label>
                            <textarea name="fac_other_considerations" class="form-control" rows="2"
                                      placeholder="Staffing, access, impact on other ministries…"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold mb-1">Facilities Recommendation</label>
                            <select name="fac_recommendation" class="form-select">
                                <option value="no_concern">No logistical concern</option>
                                <option value="requires_adjustment">Requires adjustment</option>
                                <option value="requires_discussion">Requires discussion with Minister</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-arrow-right-circle me-1"></i>Save &amp; Forward to Minister
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ── Step 3: Minister decision (policy section 9) ───────────────────────── -->
<?php if (canMinisterApprove($currentUserType)): ?>
<div class="modal fade" id="ministerDecisionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="fmForm">
                <div class="modal-header bg-purple text-white">
                    <h5 class="modal-title"><i class="bi bi-person-check me-2"></i>Minister Approval — Step 3 of 4</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Request:</strong> <span id="fm_event"></span></p>
                    <p class="small text-muted" id="fm_meta"></p>

                    <div id="fm_conflicts"></div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Decision <span class="text-danger">*</span></label>
                        <select name="decision" class="form-select" required>
                            <option value="approved">APPROVED — proceeds to Facilities confirmation</option>
                            <option value="declined">DECLINED — the request is not approved</option>
                            <option value="for_clarification">FOR CLARIFICATION — more information needed</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small fw-semibold mb-1">Comments / Conditions</label>
                        <textarea name="minister_comments" class="form-control" rows="3" maxlength="500"></textarea>
                        <div class="form-text" style="font-size:.7rem">
                            Recorded permanently in the approval history. Nothing here is ever overwritten.
                        </div>
                    </div>

                    <div class="alert alert-warning small py-2 mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Approving does <strong>not</strong> reserve the room. Facilities must still confirm it
                        (step 4) before it becomes an official reservation.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-purple">
                        <i class="bi bi-check2-circle me-1"></i>Record Decision
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ── Step 4: Facilities confirmation — THE official reservation ─────────── -->
<?php if (canFacilitiesConfirm($currentUserType)): ?>
<div class="modal fade" id="confirmRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="fcfForm"
                  onsubmit="return confirm('Confirm this reservation? It will become an official calendar entry and will block the room.');">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-calendar2-check me-2"></i>Facilities Confirmation — Step 4 of 4</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Request:</strong> <span id="fcf_event"></span></p>
                    <p class="small text-muted" id="fcf_meta"></p>

                    <div id="fcf_conflicts"></div>

                    <div class="alert alert-success small py-2">
                        <i class="bi bi-check-circle-fill me-1"></i>
                        This is the step that makes the booking an <strong>official reservation</strong>
                        (policy section 20). Once confirmed it occupies the calendar and blocks the room
                        against other requests.
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold mb-1">Confirmation Notes</label>
                        <input type="text" name="notes" class="form-control" maxlength="500"
                               placeholder="e.g. Confirmed with requestor by email; setup at 8:00 AM">
                    </div>

                    <!-- Only rendered by JS when a confirmed reservation already overlaps. -->
                    <div id="fcf_force_wrap" class="d-none">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="force" value="1" id="fcf_force">
                            <label class="form-check-label small text-danger fw-semibold" for="fcf_force">
                                Confirm anyway, over the existing confirmed reservation. This override is recorded
                                in the approval history.
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-calendar2-check me-1"></i>Confirm Reservation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ── Cancel (policy section 18) ─────────────────────────────────────────── -->
<?php if (canCancelFacilityRequest($currentUserType)): ?>
<div class="modal fade" id="cancelRequestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="fxForm"
                  onsubmit="return confirm('Cancel this request? The record and its history are kept.');">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Cancel Request?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-1"><strong>Request:</strong> <span id="fx_event"></span></p>
                    <p class="small text-muted" id="fx_meta"></p>
                    <p class="small">
                        Cancelling frees the slot for other ministries. The request and its full approval
                        history are kept — nothing is deleted.
                    </p>
                    <label class="form-label small fw-semibold mb-1">Reason</label>
                    <input type="text" name="notes" class="form-control" maxlength="500"
                           placeholder="Why is this being cancelled?">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Request</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Cancel Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ── Read-only detail (opened from a calendar entry) ────────────────────── -->
<!-- No role gate: anyone who can see the page can read a request and its trail. -->
<div class="modal fade" id="facilityDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Request Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="fd_loading" class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Loading…</div>
                <div id="fd_body" class="d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="fd_history_btn">
                    <i class="bi bi-clock-history me-1"></i>Approval History
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Append-only approval / status history ──────────────────────────────── -->
<div class="modal fade" id="facilityHistoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Approval History — <span id="fh_title"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">
                    Append-only. Every transition is recorded; no decision is ever overwritten or removed.
                </p>
                <div id="fh_loading" class="text-muted small"><i class="bi bi-hourglass-split me-1"></i>Loading…</div>
                <ul id="fh_list" class="list-group list-group-flush d-none"></ul>
                <div id="fh_empty" class="text-muted small d-none">No history recorded for this request yet.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<datalist id="fbMinistryList">
    <?php foreach ($ministries as $m): ?>
    <option value="<?php echo htmlspecialchars($m['name']); ?>">
    <?php endforeach; ?>
</datalist>

<?php include 'facility_modals_js.php'; ?>
