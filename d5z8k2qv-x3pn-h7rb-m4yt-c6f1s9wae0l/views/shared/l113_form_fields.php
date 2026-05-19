<?php
// Shared form fields used in both Add and Edit L113 modals.
// Requires in scope: $availableYears, $l113BatchNames
$availableYears = $availableYears ?? [];
$l113BatchNames = $l113BatchNames ?? [];

// Build year options: DB years + current and previous year, most recent first
$_yearOpts = array_map('intval', $availableYears);
$_curYear  = (int)date('Y');
foreach ([$_curYear - 1, $_curYear] as $_y) {
    if (!in_array($_y, $_yearOpts)) $_yearOpts[] = $_y;
}
rsort($_yearOpts);

$_programTypes = [
    'victory_weekend'    => 'Victory Weekend',
    'church_community'   => 'Church Community',
    'making_disciples'   => 'Making Disciples',
    'empowering_leaders' => 'Empowering Leaders',
    'leadership_113'     => 'Leadership 113',
];
?>
<div class="row g-3">
    <!-- Participant Name -->
    <div class="col-md-6">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="raw_first_name" class="form-control l113-first" required placeholder="e.g. Juan">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="raw_last_name" class="form-control l113-last" required placeholder="e.g. Dela Cruz">
    </div>

    <!-- Class / Year / Batch -->
    <div class="col-md-5">
        <label class="form-label fw-semibold">Class <span class="text-danger">*</span></label>
        <select name="program_type" class="form-select l113-program-select2" required style="width:100%">
            <?php foreach ($_programTypes as $_pt => $_label): ?>
            <option value="<?php echo $_pt; ?>" <?php echo $_pt === 'leadership_113' ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($_label); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label fw-semibold">Year <span class="text-danger">*</span></label>
        <select name="program_year" class="form-select l113-year-select2" required style="width:100%">
            <?php foreach ($_yearOpts as $_y): ?>
            <option value="<?php echo $_y; ?>" <?php echo $_y === $_curYear ? 'selected' : ''; ?>><?php echo $_y; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-5">
        <label class="form-label fw-semibold">Batch Label</label>
        <select name="l113_batch" class="form-select l113-batch-select2" style="width:100%">
            <option value=""></option>
            <?php foreach ($l113BatchNames as $bn): ?>
            <option value="<?php echo htmlspecialchars($bn); ?>"><?php echo htmlspecialchars($bn); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Contact Number -->
    <div class="col-md-6">
        <label class="form-label fw-semibold">Contact Number <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="contact_number" class="form-control l113-contact" placeholder="09XXXXXXXXX">
    </div>

    <!-- Linked Member -->
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label fw-semibold mb-0">
                Linked Member
                <span class="text-muted fw-normal small">(optional — link to an existing member record)</span>
            </label>
            <button type="button" class="btn btn-sm btn-outline-secondary l113-clear-member" style="font-size:11px;">
                <i class="bi bi-x-circle me-1"></i>Mark as Unmatched
            </button>
        </div>
        <select name="member_id" class="form-select l113-member-select2" style="width:100%">
            <option value=""></option>
        </select>
        <div class="mt-1" style="font-size:12px;">
            <i class="bi bi-info-circle me-1 text-muted"></i>
            <span class="text-muted">Leave empty (or click <strong>Mark as Unmatched</strong>) if the participant isn't a member yet — the record will show as <em>Unmatched</em>.</span>
            <br>
            <i class="bi bi-person-plus me-1 text-danger"></i>
            <span class="text-muted">Want to register them first?</span>
            <a href="index.php?action=members" target="_blank" class="fw-semibold text-danger ms-1">
                Open Member Records <i class="bi bi-box-arrow-up-right ms-1"></i>
            </a>
        </div>
    </div>

    <!-- Session Attendance -->
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label fw-semibold mb-0"><i class="bi bi-grid-3x3 me-1"></i>Session Attendance</label>
            <button type="button" class="btn btn-sm btn-outline-success" onclick="addNewSessionRow()">
                <i class="bi bi-plus me-1"></i>Add Session
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:170px">Date</th>
                        <th style="width:150px">Status</th>
                        <th style="width:50px"></th>
                    </tr>
                </thead>
                <tbody class="session-tbody">
                    <!-- rows added dynamically by JS -->
                </tbody>
            </table>
        </div>
        <div class="form-text mt-1">
            <span class="text-success fw-semibold">P</span> = Present &nbsp;|&nbsp;
            <span class="text-danger fw-semibold">A</span> = Absent &nbsp;|&nbsp;
            <span class="text-warning fw-semibold">L</span> = Late &nbsp;|&nbsp;
            <span class="text-muted fw-semibold">NO CLASS</span> = No session held &nbsp;|&nbsp;
            <span class="text-muted fw-semibold">NC</span> = Not counted
        </div>
    </div>
</div>
