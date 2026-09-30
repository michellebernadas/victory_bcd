<?php
// Shared form fields used in both Add and Edit Spiritual Foundations modals.
// Requires in scope: $availableYears, $scBatchNames, $sfWeeks (week_no => sf_topics row)
$availableYears = $availableYears ?? [];
$scBatchNames   = $scBatchNames   ?? [];
$sfWeeks        = $sfWeeks        ?? [];
$sfRequiredWeeks = $sfRequiredWeeks ?? $sfWeeks;

// Year options: DB years + current and previous year, most recent first.
$_yearOpts = array_map('intval', $availableYears);
$_curYear  = (int)date('Y');
foreach ([$_curYear - 1, $_curYear] as $_y) {
    if (!in_array($_y, $_yearOpts)) $_yearOpts[] = $_y;
}
rsort($_yearOpts);
?>
<div class="row g-3">
    <!-- Participant Name -->
    <div class="col-md-6">
        <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
        <input type="text" name="raw_first_name" class="form-control sf-first" required placeholder="e.g. Juan">
    </div>
    <div class="col-md-6">
        <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
        <input type="text" name="raw_last_name" class="form-control sf-last" required placeholder="e.g. Dela Cruz">
    </div>

    <!-- Year / Batch -->
    <input type="hidden" name="program_type" value="spiritual_foundations">
    <div class="col-md-3">
        <label class="form-label fw-semibold">Year <span class="text-danger">*</span></label>
        <select name="program_year" class="form-select sf-year-select2" required style="width:100%">
            <?php foreach ($_yearOpts as $_y): ?>
            <option value="<?php echo $_y; ?>" <?php echo $_y === $_curYear ? 'selected' : ''; ?>><?php echo $_y; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-5">
        <label class="form-label fw-semibold">Batch Label</label>
        <select name="sc_batch" class="form-select sf-batch-select2" style="width:100%">
            <option value=""></option>
            <?php
            // Always offer "Batch 1" even before any record exists.
            $_batchOpts = $scBatchNames;
            if (!in_array('Batch 1', $_batchOpts, true)) array_unshift($_batchOpts, 'Batch 1');
            foreach ($_batchOpts as $bn): ?>
            <option value="<?php echo htmlspecialchars($bn); ?>"><?php echo htmlspecialchars($bn); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label fw-semibold">Contact Number <span class="text-muted fw-normal small">(optional)</span></label>
        <input type="text" name="contact_number" class="form-control sf-contact" placeholder="09XXXXXXXXX">
    </div>

    <!-- Linked Member -->
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label fw-semibold mb-0">
                Linked Member
                <span class="text-muted fw-normal small">(optional — link to an existing member record)</span>
            </label>
            <button type="button" class="btn btn-sm btn-outline-secondary sf-clear-member" style="font-size:11px;">
                <i class="bi bi-x-circle me-1"></i>Mark as Unmatched
            </button>
        </div>
        <select name="member_id" class="form-select sf-member-select2" style="width:100%">
            <option value=""></option>
        </select>
        <div class="mt-1" style="font-size:12px;">
            <i class="bi bi-info-circle me-1 text-muted"></i>
            <span class="text-muted">Leave empty (or click <strong>Mark as Unmatched</strong>) if the participant isn't a member yet — the record will show as <em>Unmatched</em>.</span>
        </div>
    </div>

    <!-- Session / Week Attendance — one row per curriculum week -->
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label fw-semibold mb-0">
                <i class="bi bi-grid-3x3 me-1"></i>Session Attendance
                <span class="text-muted fw-normal small">(<?php echo count($sfWeeks); ?> curriculum weeks)</span>
            </label>
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary sf-autofill-dates" title="Fill week dates weekly from the Week 1 date">
                    <i class="bi bi-calendar-week me-1"></i>Auto-fill Dates
                </button>
                <button type="button" class="btn btn-sm btn-outline-success sf-mark-all-present">
                    <i class="bi bi-check2-all me-1"></i>Mark All Present
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary sf-mark-all-nc"
                        title="Mark every topic NC, then set the ones this participant is catching up on">
                    <i class="bi bi-dash-circle me-1"></i>Mark All NC
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:70px">Week</th>
                        <th>Topic</th>
                        <th style="width:170px">Date</th>
                        <th style="width:150px">Status</th>
                    </tr>
                </thead>
                <tbody class="sf-session-tbody">
                    <?php foreach ($sfWeeks as $wk => $row):
                        $subs = SfTopic::subtopicList($row);
                    ?>
                    <tr data-week="<?php echo (int)$wk; ?>">
                        <td class="text-center">
                            <span class="badge bg-info"><?php echo (int)$wk; ?></span>
                            <?php // Explicit week number travels with the record, so a later
                                  // curriculum edit can never re-point this row at another topic. ?>
                            <input type="hidden" name="session_weeks[]" value="<?php echo (int)$wk; ?>">
                        </td>
                        <td>
                            <div class="fw-semibold small"><?php echo htmlspecialchars($row['topic']); ?></div>
                            <?php if ($subs): ?>
                            <div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars(implode(' · ', $subs)); ?></div>
                            <?php endif; ?>
                            <?php if (!(int)$row['is_required']): ?>
                            <span class="badge bg-light text-muted border" style="font-size:9px;">Optional</span>
                            <?php endif; ?>
                        </td>
                        <td><input type="date" name="session_dates[]" class="form-control form-control-sm sf-date" style="min-width:140px"></td>
                        <td><input type="text" name="session_statuses[]" list="sfStatusOpts" class="form-control form-control-sm sf-status" value="P" placeholder="Status…" autocomplete="off"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-text mt-1">
            <?php $legendSize = 11; $legendShow = ['attended', 'late', 'absent', 'not_required'];
                  include __DIR__ . '/session_status_legend.php'; ?>
            <div class="mt-1">
                <i class="bi bi-info-circle me-1 text-muted"></i>
                <span class="text-muted">
                    Use <strong>NC</strong> when a topic is not required of this participant — e.g. they already
                    completed it in an earlier batch and joined this one to catch up. NC gives no credit and
                    never reduces the <?php echo count($sfRequiredWeeks ?? $sfWeeks); ?>-topic requirement.
                </span>
            </div>
            <div>
                <i class="bi bi-slash-circle me-1 text-muted"></i>
                <span class="text-muted">
                    <strong>No Class</strong> is a session-level state, not a participant status — a cancelled week
                    applies to the whole batch, so it belongs in the
                    <a href="index.php?action=spiritualFoundations&tab=curriculum" target="_blank">curriculum</a>
                    as the No Class break row.
                </span>
            </div>
            <div>
                <i class="bi bi-award me-1 text-info"></i>
                <span class="text-muted">The <strong>Certificate</strong> unlocks once the participant has a
                    Present/Late for all <?php echo count($sfRequiredWeeks ?? $sfWeeks); ?> required topics
                    <strong>across any of their batches</strong> — topics already completed earlier don't need repeating.
                </span>
            </div>
        </div>
    </div>
</div>
