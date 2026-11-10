<?php
/**
 * "Add Class Record" modal, opened from the Member Profile.
 *
 * Replaces the old behaviour of sending the admin to the Attendance Records
 * page in a NEW BROWSER TAB, where they then had to find and re-select the
 * member they had just been looking at.
 *
 * Scope is deliberately narrow — only the four simple classes:
 *     Victory Weekend, Church Community, Making Disciples, Empowering Leaders
 * Leadership 1-1-3 and Spiritual Foundations are driven by a per-session grid
 * that cannot be captured in a small modal, so those navigate in the SAME tab
 * and carry a "Back to Member" action instead.
 *
 * This posts to the SAME addAttendance route the main workflow uses, so the
 * discipleship source of truth is untouched: an attendance record is created,
 * and DiscipleshipProgressService derives completion from it. There is no
 * manual "mark complete" control here, and there must never be one.
 *
 * Requires in scope: $member
 */
$mcMemberId = (int)$member['id'];

// The four classes the generic attendance form owns. Keys match
// program_attendances.program_type; labels/icons mirror ProgramAttendance.
$mcClasses = [
    'victory_weekend'    => ['label' => 'Victory Weekend',    'icon' => 'bi-sun',         'color' => 'primary'],
    'church_community'   => ['label' => 'Church Community',   'icon' => 'bi-building',    'color' => 'secondary'],
    'making_disciples'   => ['label' => 'Making Disciples',   'icon' => 'bi-person-plus', 'color' => 'success'],
    'empowering_leaders' => ['label' => 'Empowering Leaders', 'icon' => 'bi-star',        'color' => 'warning'],
];

$mcCurYear = (int)date('Y');
$mcYears   = range($mcCurYear, $mcCurYear - 5);
?>
<div class="modal fade" id="addClassRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mcTitle">
                    <i class="bi bi-journal-plus me-2"></i><span id="mcTitleText">Add Class Record</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <form method="POST" action="index.php?action=addAttendance" id="addClassRecordForm">
                <div class="modal-body">

                    <!-- Who this is for. Fixed: the modal is opened from their profile. -->
                    <div class="alert alert-light border d-flex align-items-center gap-2 py-2 mb-3">
                        <i class="bi bi-person-circle fs-5 text-primary"></i>
                        <div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($member['full_name']); ?></div>
                            <div class="text-muted" style="font-size:11px;">
                                <span id="mcWhoText">This record will be added to this member.</span>
                            </div>
                        </div>
                    </div>

                    <!-- The member is already known, so the name fields are filled from
                         their record and never retyped. The controller needs them
                         because program_attendances stores the display name too. -->
                    <input type="hidden" name="member_id"      value="<?php echo $mcMemberId; ?>">
                    <input type="hidden" name="raw_first_name" value="<?php echo htmlspecialchars($member['first_name'] ?? ''); ?>">
                    <input type="hidden" name="raw_last_name"  value="<?php echo htmlspecialchars($member['last_name'] ?? ''); ?>">
                    <!-- Returns to this profile after saving instead of the records list. -->
                    <input type="hidden" name="return_to" value="profile">
                    <!-- Edit only: fields this small form doesn't show, carried through
                         unchanged so ProgramAttendance::update() never blanks them. -->
                    <input type="hidden" name="counselor_contact" id="mcCounselorContact" value="" disabled>
                    <input type="hidden" name="contact_number"    id="mcContactNumber"    value="" disabled>
                    <input type="hidden" name="md_part1"          id="mcMdPart1"          value="" disabled>
                    <input type="hidden" name="md_part2"          id="mcMdPart2"          value="" disabled>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Class <span class="text-danger">*</span>
                            </label>
                            <select name="program_type" id="mcProgramType" class="form-select" required>
                                <option value="">— Select a class —</option>
                                <?php foreach ($mcClasses as $mcKey => $mcDef): ?>
                                <option value="<?php echo $mcKey; ?>"><?php echo htmlspecialchars($mcDef['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small">
                                <i class="bi bi-info-circle me-1"></i>
                                Saving marks this class as completed on the member's journey.
                            </div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Year <span class="text-danger">*</span></label>
                            <select name="program_year" id="mcYear" class="form-select" required>
                                <?php foreach ($mcYears as $mcY): ?>
                                <option value="<?php echo $mcY; ?>"><?php echo $mcY; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">
                                Date <span class="text-muted fw-normal small">(optional)</span>
                            </label>
                            <input type="date" name="event_date" id="mcDate" class="form-control">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Batch / Event Label <span class="text-muted fw-normal small">(optional)</span>
                            </label>
                            <input type="text" name="batch_label" id="mcBatch" class="form-control"
                                   placeholder='e.g. "Batch 1"'>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Counselor <span class="text-muted fw-normal small">(optional)</span>
                            </label>
                            <input type="text" name="counselor_name" id="mcCounselor" class="form-control"
                                   placeholder="Who walked them through this class">
                        </div>

                        <!-- Water baptism belongs to Victory Weekend only. -->
                        <div class="col-md-6" id="mcBaptismField" style="display:none">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-droplet-fill text-primary me-1"></i>Underwent Water Baptism?
                            </label>
                            <select name="water_baptism" class="form-select" id="mcBaptism">
                                <option value="0">No</option>
                                <option value="1">Yes</option>
                            </select>
                        </div>

                        <div class="col-12" id="mcBaptismRemarks" style="display:none">
                            <label class="form-label fw-semibold">
                                Water Baptism Remarks <span class="text-muted fw-normal small">(optional)</span>
                            </label>
                            <textarea name="water_baptism_remarks" id="mcBapRemarks" class="form-control" rows="2"
                                      placeholder="e.g. Sea baptism at Punta Taytay"></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Notes <span class="text-muted fw-normal small">(optional)</span>
                            </label>
                            <textarea name="notes" id="mcNotes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <!-- Warns about an existing record for the same class + year before
                         the admin saves a second one. Advisory, not a block: a genuine
                         repeat of a class in the same year is possible. -->
                    <div id="mcDuplicateWarning" class="alert alert-warning py-2 small mt-3 mb-0" style="display:none">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <span id="mcDuplicateText"></span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><span id="mcSaveText">Save Record</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Victory Weekend is the only class that asks about water baptism, and the
// remarks box only matters once the answer is Yes.
(function () {
    var typeSel   = document.getElementById('mcProgramType');
    var bapField  = document.getElementById('mcBaptismField');
    var bapSel    = document.getElementById('mcBaptism');
    var bapNotes  = document.getElementById('mcBaptismRemarks');
    var dupBox    = document.getElementById('mcDuplicateWarning');
    var dupText   = document.getElementById('mcDuplicateText');
    var form      = document.getElementById('addClassRecordForm');
    if (!typeSel || !form) return;

    function syncBaptism() {
        var isVw = typeSel.value === 'victory_weekend';
        bapField.style.display = isVw ? '' : 'none';
        bapNotes.style.display = (isVw && bapSel.value === '1') ? '' : 'none';
        if (!isVw) bapSel.value = '0';
    }
    typeSel.addEventListener('change', syncBaptism);
    bapSel.addEventListener('change', syncBaptism);

    // Reuses the duplicate-check endpoint the Attendance Records page already uses.
    function checkDuplicate() {
        var pt   = typeSel.value;
        var yr   = form.querySelector('[name="program_year"]').value;
        var mid  = form.querySelector('[name="member_id"]').value;
        if (!pt || !yr || !mid) { dupBox.style.display = 'none'; return; }
        fetch('index.php?action=ajaxCheckAttendanceDuplicate&member_id=' + encodeURIComponent(mid) +
              '&program_type=' + encodeURIComponent(pt) + '&program_year=' + encodeURIComponent(yr))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.exists) {
                    dupText.textContent = 'This member already has a record for this class in ' + yr +
                                          '. Saving will add a second one.';
                    dupBox.style.display = '';
                } else {
                    dupBox.style.display = 'none';
                }
            })
            .catch(function () { dupBox.style.display = 'none'; });
    }
    typeSel.addEventListener('change', checkDuplicate);
    form.querySelector('[name="program_year"]').addEventListener('change', checkDuplicate);

    // ── Edit mode: the same modal, prefilled, posting to updateAttendance ──
    var keepIds = ['mcCounselorContact', 'mcContactNumber', 'mcMdPart1', 'mcMdPart2'];
    function setVal(id, v) { var el = document.getElementById(id); if (el) el.value = (v === null || v === undefined) ? '' : v; }
    function setMode(rec) {
        var edit = !!rec;
        form.action = edit ? 'index.php?action=updateAttendance&id=' + encodeURIComponent(rec.id) : 'index.php?action=addAttendance';
        document.getElementById('mcTitleText').textContent = edit ? 'Edit Class Record' : 'Add Class Record';
        document.getElementById('mcSaveText').textContent  = edit ? 'Save Changes' : 'Save Record';
        document.getElementById('mcWhoText').textContent   = edit ? 'You are editing this member\'s record.' : 'This record will be added to this member.';
        keepIds.forEach(function (id) { document.getElementById(id).disabled = !edit; });
        if (!edit) { form.reset(); syncBaptism(); dupBox.style.display = 'none'; return; }
        typeSel.value = rec.program_type || '';
        var yr = document.getElementById('mcYear');
        if (rec.program_year && !Array.prototype.some.call(yr.options, function (o) { return o.value == rec.program_year; })) {
            yr.add(new Option(rec.program_year, rec.program_year));
        }
        setVal('mcYear', rec.program_year);
        setVal('mcDate', rec.event_date);
        setVal('mcBatch', rec.batch_label);
        setVal('mcCounselor', rec.counselor_name);
        setVal('mcNotes', rec.notes);
        bapSel.value = String(rec.water_baptism) === '1' ? '1' : '0';
        setVal('mcBapRemarks', rec.water_baptism_remarks);
        setVal('mcCounselorContact', rec.counselor_contact);
        setVal('mcContactNumber', rec.contact_number);
        setVal('mcMdPart1', String(rec.md_part1) === '1' ? '1' : '');
        setVal('mcMdPart2', String(rec.md_part2) === '1' ? '1' : '');
        syncBaptism();
        dupBox.style.display = 'none';
    }
    // Any "Add" opener resets to add mode; an [data-class-edit] opener fills it.
    document.getElementById('addClassRecordModal').addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        var rec = null;
        if (btn && btn.dataset && btn.dataset.classEdit) { try { rec = JSON.parse(btn.dataset.classEdit); } catch (x) {} }
        setMode(rec);
        if (!rec && btn && btn.dataset && btn.dataset.preselect) { typeSel.value = btn.dataset.preselect; syncBaptism(); checkDuplicate(); }
    });
})();
</script>
