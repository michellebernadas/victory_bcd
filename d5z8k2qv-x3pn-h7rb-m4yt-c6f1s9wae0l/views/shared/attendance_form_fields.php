<?php
// Shared form fields for Add and Edit Attendance modals.
// Requires in scope: $PROGRAM_DEFS, $allCounselors, $availableYears
$allCounselors  = $allCounselors  ?? [];
// Build year options: DB years + current and previous year, most recent first
$_yearOpts = array_map('intval', $availableYears ?? []);
$_curYear  = (int)date('Y');
foreach ([$_curYear - 1, $_curYear] as $_y) {
    if (!in_array($_y, $_yearOpts)) $_yearOpts[] = $_y;
}
rsort($_yearOpts);
?>
<div class="row g-3">
    <!-- Name -->
    <div class="col-md-6">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="raw_first_name" class="form-control at-first" required placeholder="e.g. Juan">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="raw_last_name" class="form-control at-last" required placeholder="e.g. Dela Cruz">
    </div>

    <!-- Class / Year / Event Date -->
    <div class="col-md-5">
        <label class="form-label fw-semibold">Class <span class="text-danger">*</span>
            <span class="text-muted fw-normal small">(this is the discipleship step being marked)</span>
        </label>
        <select name="program_type" class="form-select at-program" required style="width:100%">
            <option value="">— Select Class —</option>
            <?php foreach ($PROGRAM_DEFS as $pt => $pd): ?>
            <option value="<?php echo $pt; ?>"><?php echo htmlspecialchars($pd['label']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text small">
            <i class="bi bi-info-circle me-1"></i>
            Saving this record automatically marks the linked member's <strong>selected class</strong> as completed on their profile.
        </div>
    </div>
    <div class="col-md-2">
        <label class="form-label fw-semibold">Year <span class="text-danger">*</span></label>
        <select name="program_year" class="form-select at-year" required style="width:100%">
            <?php foreach ($_yearOpts as $_y): ?>
            <option value="<?php echo $_y; ?>" <?php echo $_y === $_curYear ? 'selected' : ''; ?>><?php echo $_y; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label fw-semibold">Event Date</label>
        <input type="date" name="event_date" class="form-control at-event-date"
               title="Specific date of the class/event">
    </div>

    <!-- Batch Label -->
    <div class="col-12">
        <label class="form-label fw-semibold">Batch Label
            <span class="text-muted fw-normal small">(optional — e.g. "Batch 1", "Batch 2")</span>
        </label>
        <select name="batch_label" class="form-select at-batch-select2" style="width:100%">
            <option value=""></option>
        </select>
    </div>

    <!-- Linked Member -->
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label fw-semibold mb-0">
                Linked Member
                <span class="text-muted fw-normal small">(optional — link to an existing member record)</span>
            </label>
            <button type="button" class="btn btn-sm btn-outline-secondary at-clear-member" style="font-size:11px;">
                <i class="bi bi-x-circle me-1"></i>Mark as Unmatched
            </button>
        </div>
        <select name="member_id" class="form-select at-member-select2" style="width:100%">
            <option value=""></option>
        </select>
        <div class="mt-1" style="font-size:12px;">
            <i class="bi bi-info-circle me-1 text-muted"></i>
            <span class="text-muted">Leave empty (or click <strong>Mark as Unmatched</strong>) if the participant isn't a member yet — the record will show as <em>Unmatched</em>.</span>
            <br>
            <i class="bi bi-person-plus me-1 text-primary"></i>
            <span class="text-muted">Want to register them first?</span>
            <a href="index.php?action=members" target="_blank" class="fw-semibold text-primary ms-1">
                Open Member Records <i class="bi bi-box-arrow-up-right ms-1"></i>
            </a>
        </div>
    </div>

    <!-- Contact & Water Baptism -->
    <div class="col-md-5">
        <label class="form-label fw-semibold">Contact Number</label>
        <input type="text" name="contact_number" class="form-control at-contact" placeholder="09XXXXXXXXX">
    </div>
    <div class="col-md-4 at-field-wbap">
        <label class="form-label fw-semibold">
            <i class="bi bi-droplet-fill text-primary me-1"></i>Underwent Water Baptism? <span class="text-danger">*</span>
        </label>
        <select name="water_baptism" class="form-select at-wbap" style="width:100%">
            <option value="0">No</option>
            <option value="1">Yes</option>
        </select>
    </div>

    <!-- Counselor -->
    <div class="col-md-5 at-field-counselor">
        <label class="form-label fw-semibold">Counselor Name</label>
        <select name="counselor_name" class="form-select at-counselor-select2" style="width:100%">
            <option value=""></option>
            <?php foreach ($allCounselors as $cn): ?>
            <option value="<?php echo htmlspecialchars($cn); ?>"><?php echo htmlspecialchars($cn); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 at-field-couns-contact">
        <label class="form-label fw-semibold">Counselor Contact #</label>
        <input type="text" name="counselor_contact" class="form-control at-counselor-contact" placeholder="09XXXXXXXXX">
    </div>

    <!-- ── Notes (free-text, shown under the participant name on the records table) ── -->
    <div class="col-12">
        <label class="form-label fw-semibold">Notes
            <span class="text-muted fw-normal small">(optional — e.g. other dates attended, follow-up info)</span>
        </label>
        <textarea name="notes" class="form-control at-notes" rows="2" placeholder="e.g. Also attended Sep 16, 2023"></textarea>
    </div>

    <!-- ── Making Disciples extra fields ────────────────────────────── -->
    <!-- 2023 and 2024 had a SINGLE Making Disciples session. 2025 split into Part 1 + Part 2 on different dates.
         The form supports both: fill Part 1 only for single-session years, or fill both for two-part years. -->
    <div class="col-12 at-extra-md" style="display:none">
        <div class="card border-success">
            <div class="card-header py-2 bg-success text-white small fw-semibold">
                <i class="bi bi-person-plus me-1"></i>Making Disciples — Session Attendance
            </div>
            <div class="card-body py-2">
                <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Older years had a single MD session — fill Part 1 only. From 2025 onward MD has two parts on different dates — fill both.</p>
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Part 1 Attended?</label>
                        <select name="md_part1" class="form-select form-select-sm at-md-p1" style="width:100%">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Part 1 Date <span class="text-muted fw-normal">(main event date)</span></label>
                        <input type="date" name="md_part1_date" class="form-control form-control-sm at-md-p1-date">
                        <div class="form-text small">Saved to the main Event Date field.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Part 2 Attended?</label>
                        <select name="md_part2" class="form-select form-select-sm at-md-p2" style="width:100%">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold mb-1">Part 2 Date <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="date" name="md_part2_date" class="form-control form-control-sm at-md-p2-date">
                        <div class="form-text small">Only set this if MD has 2 parts on different dates.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Leadership 113 — basic save here, sessions managed on the L113 page ── -->
    <div class="col-12 at-extra-l113" style="display:none">
        <div class="card border-danger">
            <div class="card-header py-2 bg-danger text-white small fw-semibold d-flex align-items-center">
                <i class="bi bi-trophy me-1"></i>Leadership 1-1-3 — Session Attendance
            </div>
            <div class="card-body py-2 small">
                <p class="text-muted mb-2"><i class="bi bi-info-circle me-1"></i>
                L113 has many sessions (P/A/L marks per date), so the full session grid lives on the dedicated L113 page.
                <strong>You can still save the basic record (name, year, batch, etc.) here</strong> — it'll appear on the L113 page where you can add the session details.
                </p>
                <a href="index.php?action=leadership113" target="_blank" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Leadership 1-1-3 page
                </a>
            </div>
        </div>
    </div>
</div>
