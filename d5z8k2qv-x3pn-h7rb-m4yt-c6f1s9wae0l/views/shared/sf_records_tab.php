<?php
// Spiritual Foundations — Records tab.
// Requires in scope: $rows, $totalRecords, $totalParticipants, $totalEligible,
//                    $totalNotEligible, $totalMembers, $sfWeeks, $sfRequiredWeeks,
//                    $requiredWeekNos, $activeYear, $activeBatch, …
?>
<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-info border-opacity-50">
            <div class="card-body py-3">
                <div class="h3 fw-bold text-info mb-0"><?php echo $totalRecords; ?></div>
                <div class="text-muted small">Batch Records</div>
                <div class="text-muted" style="font-size:10px;"><?php echo $totalParticipants; ?> distinct participants</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-success border-opacity-50">
            <div class="card-body py-3">
                <div class="h3 fw-bold text-success mb-0"><?php echo $totalEligible; ?></div>
                <div class="text-muted small"><i class="bi bi-award me-1"></i>Complete Overall</div>
                <div class="text-muted" style="font-size:10px;">all <?php echo count($requiredWeekNos); ?> topics, any batch</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-warning border-opacity-50">
            <div class="card-body py-3">
                <div class="h3 fw-bold text-warning mb-0"><?php echo $totalNotEligible; ?></div>
                <div class="text-muted small">Incomplete Overall</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-secondary border-opacity-50">
            <div class="card-body py-3">
                <div class="h3 fw-bold text-secondary mb-0"><?php echo $totalMembers; ?></div>
                <div class="text-muted small">Linked to Members</div>
            </div>
        </div>
    </div>
</div>

<?php if ($totalParticipants > 0):
    $overallPct = (int)round($totalEligible / $totalParticipants * 100);
    $barColor   = $overallPct >= 80 ? 'success' : ($overallPct >= 50 ? 'warning' : 'danger');
?>
<div class="card mb-4">
    <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold">Overall Completion &mdash; cumulative across all batches</span>
            <span class="badge bg-<?php echo $barColor; ?>"><?php echo $overallPct; ?>%</span>
        </div>
        <div class="progress" style="height:10px;">
            <div class="progress-bar bg-<?php echo $barColor; ?>" style="width:<?php echo $overallPct; ?>%"></div>
        </div>
        <div class="d-flex justify-content-between mt-1" style="font-size:11px;">
            <span class="text-success"><i class="bi bi-award me-1"></i><?php echo $totalEligible; ?> complete</span>
            <span class="text-muted"><?php echo $totalParticipants; ?> participants</span>
            <span class="text-warning"><i class="bi bi-hourglass-split me-1"></i><?php echo $totalNotEligible; ?> still catching up</span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/sf_filters.php'; ?>

<!-- Records Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-white fw-semibold d-flex align-items-center flex-wrap gap-2">
            <i class="bi bi-table me-2"></i>Spiritual Foundations Records
            <span class="badge bg-white text-dark border" id="sfCountBadge"><?php echo $totalRecords; ?></span>
            <?php if ($activeYear): ?>
            <span class="badge bg-light text-dark border"><i class="bi bi-calendar me-1"></i>Year: <?php echo htmlspecialchars($activeYear); ?></span>
            <?php endif; ?>
            <?php if ($activeBatch): ?>
            <span class="badge bg-light text-dark border"><i class="bi bi-collection me-1"></i>Batch: <?php echo htmlspecialchars($activeBatch); ?></span>
            <?php endif; ?>
            <span class="badge bg-light text-dark border" id="sfTopicBadge" style="display:none"></span>
        </span>
        <div class="d-flex align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2 me-2">
                <label class="text-white small mb-0">Show</label>
                <select id="sfPerPage" class="form-select form-select-sm" style="width:auto;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="-1">All</option>
                </select>
                <label class="text-white small mb-0">per page</label>
            </div>
            <span class="col-toggle" data-table="sfTable" data-locked="0,9"></span>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSfCsv()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSfExcel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSfPdf()" title="Export PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                <button type="button" class="btn btn-sm btn-outline-light" onclick="printSf()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
            </div>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-shield display-4 mb-3 d-block"></i>
            No Spiritual Foundations records found.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="sfTable" style="width:100%">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Participant</th>
                        <th>Year</th>
                        <th>Batch</th>
                        <th>Contact #</th>
                        <th title="Week-by-week status for this batch. Colour = status; click Details for the full topic list.">Topics</th>
                        <th title="Topics completed in THIS batch only">Batch Progress</th>
                        <th title="Topics completed across ALL of this participant's batches — this is what the certificate uses">Overall Progress</th>
                        <th>Matched Member</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r):
                        $rec      = $r['rec'];
                        $batch    = $r['batch'];
                        $overall  = $r['overall'];
                        $weeks    = $r['weeks'];
                        $recStatus = $rec['status'] ?? 'active';

                        $bPct = $batch['required']   > 0 ? (int)round($batch['completed']   / $batch['required']   * 100) : 0;
                        $oPct = $overall['required'] > 0 ? (int)round($overall['completed'] / $overall['required'] * 100) : 0;

                        // week => status, for the client-side topic filter.
                        $weekMap = [];
                        foreach ($weeks as $wkNo => $w) $weekMap[$wkNo] = $w['status'];

                        // Which weeks this person completed elsewhere (shown as a hint).
                        $elsewhere = [];
                        foreach (($overall['weeks'] ?? []) as $wkNo => $w) {
                            if (!$w['completed']) continue;
                            $here = $weekMap[$wkNo] ?? '';
                            if (ProgramAttendance::isAttendedStatus($here)) continue;
                            $elsewhere[] = $wkNo;
                        }

                        $editJson = [
                            'id'             => (int)$rec['id'],
                            'raw_first_name' => $rec['raw_first_name'],
                            'raw_last_name'  => $rec['raw_last_name'],
                            'program_year'   => $rec['program_year'],
                            'contact_number' => $rec['contact_number'],
                            'member_id'      => $rec['member_id'],
                            'member_name'    => $rec['member_name'] ?? '',
                            'member_ministry'=> $rec['member_ministry'] ?? '',
                            'sc_batch'       => trim($rec['batch_label'] ?? ''),
                            'weeks'          => $weekMap,
                            // Dates keyed in grid order, so the modal can restore the date column.
                            'sessions'       => array_column(
                                array_map(fn($wk, $w) => [$w['key'], $w['status']], array_keys($weeks), $weeks),
                                1, 0
                            ),
                        ];
                    ?>
                    <tr data-matched="<?php echo $rec['member_id'] ? '1' : '0'; ?>"
                        data-cert="<?php echo !empty($overall['eligible']) ? '1' : '0'; ?>"
                        data-weeks="<?php echo htmlspecialchars(json_encode($weekMap), ENT_QUOTES); ?>">
                        <td class="text-muted small"><?php echo $i + 1; ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($rec['full_name_display']); ?></td>
                        <td class="small text-nowrap">
                            <?php if (!empty($rec['program_year'])): ?>
                            <i class="bi bi-calendar3 text-muted me-1"></i><?php echo (int)$rec['program_year']; ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php $bd = trim($rec['batch_label'] ?? ''); if ($bd !== ''): ?>
                            <i class="bi bi-collection text-muted me-1"></i><?php echo htmlspecialchars($bd); ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($rec['contact_number']): ?>
                            <i class="bi bi-telephone text-muted me-1"></i><?php echo htmlspecialchars($rec['contact_number']); ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>

                        <!-- Compact per-topic strip. Just the week badges (scannable at a
                             glance, colour = status); the full topic/date/status breakdown
                             opens in a modal so the row stays one line tall. -->
                        <td style="min-width:190px; max-width:240px;">
                            <?php if (!empty($weeks)):
                                // Tally for the one-line summary under the strip.
                                $cDone = 0; $cAbs = 0; $cNc = 0;
                                foreach ($weeks as $w) {
                                    $k = ProgramAttendance::statusStyle($w['status'])['kind'];
                                    if ($k === 'attended' || $k === 'late') $cDone++;
                                    elseif ($k === 'absent')                $cAbs++;
                                    elseif ($k === 'not_required')          $cNc++;
                                }
                                // Payload for the detail modal.
                                $detail = ['name' => $rec['full_name_display'],
                                           'batch' => trim($rec['batch_label'] ?? ''),
                                           'year'  => (int)$rec['program_year'],
                                           'rows'  => []];
                                foreach ($weeks as $wkNo => $w) {
                                    $st = ProgramAttendance::statusStyle($w['status']);
                                    $detail['rows'][] = [
                                        'week'   => (int)$wkNo,
                                        'topic'  => $sfWeeks[$wkNo]['topic'] ?? '',
                                        'date'   => $w['key'],
                                        'badge'  => $st['badge'],
                                        'label'  => $st['label'],
                                        // Short cell text; the badge already shows the code.
                                        'status' => $st['text'],
                                        'hint'   => $st['title'],
                                    ];
                                }
                            ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($weeks as $wkNo => $w):
                                    $st    = ProgramAttendance::statusStyle($w['status']);
                                    $topic = $sfWeeks[$wkNo]['topic'] ?? '';
                                ?>
                                <span class="badge <?php echo $st['badge']; ?>" style="font-size:9px;"
                                      title="Week <?php echo (int)$wkNo; ?><?php echo $topic ? ' — ' . htmlspecialchars($topic) : ''; ?> · <?php echo htmlspecialchars($st['title']); ?>">
                                    W<?php echo (int)$wkNo; ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-1" style="font-size:10px;">
                                <span class="text-success"><?php echo $cDone; ?> done</span>
                                <?php if ($cAbs): ?><span class="text-danger ms-2"><?php echo $cAbs; ?> absent</span><?php endif; ?>
                                <?php if ($cNc): ?><span class="text-muted ms-2"><?php echo $cNc; ?> NC</span><?php endif; ?>
                                <button type="button" class="btn btn-link btn-sm p-0 ms-2 align-baseline" style="font-size:10px;"
                                        onclick="openSfTopicsModal(<?php echo htmlspecialchars(json_encode($detail)); ?>)">
                                    Details
                                </button>
                            </div>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Batch Progress: this batch only -->
                        <td>
                            <div class="d-flex align-items-center gap-1">
                                <div class="progress flex-grow-1" style="height:5px; min-width:50px;">
                                    <div class="progress-bar bg-<?php echo $bPct >= 100 ? 'success' : ($bPct >= 50 ? 'warning' : 'danger'); ?>"
                                         style="width:<?php echo $bPct; ?>%"></div>
                                </div>
                                <span class="small text-muted"><?php echo $batch['completed']; ?>/<?php echo $batch['required']; ?></span>
                            </div>
                            <?php if ($batch['not_required'] > 0): ?>
                            <div class="text-muted" style="font-size:10px;">
                                <?php echo $batch['not_required']; ?> not required (NC)
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Overall Progress: cumulative, drives the certificate -->
                        <td>
                            <?php if (!empty($overall['eligible'])): ?>
                            <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Complete</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i><?php echo count($overall['missing'] ?? []); ?> to go</span>
                            <?php endif; ?>
                            <div class="d-flex align-items-center gap-1 mt-1">
                                <div class="progress flex-grow-1" style="height:5px; min-width:50px;">
                                    <div class="progress-bar bg-<?php echo $oPct >= 100 ? 'success' : ($oPct >= 50 ? 'info' : 'danger'); ?>"
                                         style="width:<?php echo $oPct; ?>%"></div>
                                </div>
                                <span class="small fw-semibold"><?php echo $overall['completed']; ?>/<?php echo $overall['required']; ?></span>
                            </div>
                            <?php if (count($overall['batches'] ?? []) > 1): ?>
                            <div class="text-muted" style="font-size:10px;">
                                <i class="bi bi-layers me-1"></i>across <?php echo count($overall['batches']); ?> batches
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($elsewhere)): ?>
                            <div class="text-success" style="font-size:10px;"
                                 title="Completed in another batch: weeks <?php echo implode(', ', $elsewhere); ?>">
                                <i class="bi bi-check2-circle me-1"></i><?php echo count($elsewhere); ?> done in another batch
                            </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($rec['member_id'] && $rec['member_name']): ?>
                            <a href="index.php?action=memberProfile&id=<?php echo (int)$rec['member_id']; ?>" class="text-decoration-none small">
                                <i class="bi bi-person-fill me-1 text-success"></i><?php echo htmlspecialchars($rec['member_name']); ?>
                                <?php if ($rec['member_ministry']): ?>
                                <div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars(strtoupper($rec['member_ministry'])); ?></div>
                                <?php endif; ?>
                            </a>
                            <?php else: ?>
                            <span class="text-muted small"><i class="bi bi-question-circle me-1"></i>Unmatched</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-center text-nowrap">
                            <?php
                            // Certificate reflects OVERALL cumulative completion, never one batch.
                            $lockReason = !empty($overall['eligible']) ? '' : (function () use ($overall, $sfWeeks) {
                                if (empty($overall['missing'])) return 'No Spiritual Foundations attendance recorded yet.';
                                $names = [];
                                foreach ($overall['missing'] as $wk) {
                                    $names[] = 'Week ' . $wk . (isset($sfWeeks[$wk]) ? ' (' . $sfWeeks[$wk]['topic'] . ')' : '');
                                }
                                return count($overall['missing']) . ' of ' . $overall['required']
                                     . ' topics still to complete: ' . implode(', ', $names);
                            })();
                            ?>
                            <?php if (!empty($overall['eligible'])): ?>
                            <button type="button" class="btn btn-sm btn-success me-1 sf-cert-btn"
                                    title="Certificate available — all <?php echo (int)$overall['required']; ?> topics completed across their SF history"
                                    data-name="<?php echo htmlspecialchars($rec['full_name_display']); ?>">
                                <i class="bi bi-award"></i>
                            </button>
                            <?php else: ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1" disabled
                                    title="Certificate locked — <?php echo htmlspecialchars($lockReason); ?>">
                                <i class="bi bi-award"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                onclick="openEditSfModal(<?php echo htmlspecialchars(json_encode($editJson)); ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($recStatus === 'active'): ?>
                            <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate"
                               href="index.php?action=deactivateSfRecord&id=<?php echo (int)$rec['id']; ?>"
                               onclick="return confirm('Deactivate this record?')"><i class="bi bi-pause-circle"></i></a>
                            <?php else: ?>
                            <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                               href="index.php?action=activateSfRecord&id=<?php echo (int)$rec['id']; ?>"><i class="bi bi-play-circle"></i></a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-danger" title="Delete"
                               href="index.php?action=deleteSfRecord&id=<?php echo (int)$rec['id']; ?>"
                               onclick="return confirm('Delete this record? This cannot be undone.');"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Status legend — belongs under the table, not in the header. -->
    <?php if (!empty($rows)): ?>
    <div class="card-footer bg-transparent py-2">
        <?php $legendSize = 11; include __DIR__ . '/session_status_legend.php'; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Per-record topic breakdown (keeps the table row one line tall) -->
<div class="modal fade" id="sfTopicsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-grid-3x3 me-2"></i>Topic Attendance
                    <span class="small fw-normal ms-1" id="sfTopicsModalSub"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px">Week</th>
                            <th>Topic</th>
                            <th style="width:120px">Date</th>
                            <th style="width:230px">Status</th>
                        </tr>
                    </thead>
                    <tbody id="sfTopicsModalBody"></tbody>
                </table>
            </div>
            <div class="modal-footer justify-content-start py-2">
                <?php $legendSize = 11; include __DIR__ . '/session_status_legend.php'; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addSfModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Spiritual Foundations Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="index.php?action=addSfRecord" id="addSfForm">
            <div class="modal-body"><?php include __DIR__ . '/sf_form_fields.php'; ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-info text-white"><i class="bi bi-save me-1"></i>Save Record</button>
            </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editSfModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Spiritual Foundations Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editSfForm" action="">
            <div class="modal-body"><?php include __DIR__ . '/sf_form_fields.php'; ?></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
            </div>
            </form>
        </div>
    </div>
</div>
