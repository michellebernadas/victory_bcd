<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
require_once 'models/ServiceAttendance.php';

// Defaults so the page renders standalone and, more importantly, renders
// cleanly against a completely empty service_attendances table.
$records       = $records       ?? [];
$saStats       = $saStats       ?? ['records'=>0,'sundays'=>0,'adults'=>0,'kids'=>0,'total'=>0,'average'=>0,'first_date'=>null,'last_date'=>null];
$saLatest      = $saLatest      ?? ['date'=>null,'adults'=>0,'kids'=>0,'total'=>0,'services'=>0,'average'=>0];
$saByService   = $saByService   ?? [];
$serviceTimes  = $serviceTimes  ?? [];
$recordedTimes = $recordedTimes ?? [];
$activeFilters = $activeFilters ?? [];
$saEditId      = (int)($saEditId ?? 0);
$saFilterCount = count(array_filter($activeFilters, fn($v) => $v !== '' && $v !== null));
$saHasServices = !empty($serviceTimes);

include 'shared/header.php';
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-heart-pulse me-2 text-primary"></i>Sunday Service Attendance</h1>
                    <p class="text-muted mb-0">Head counts per service &mdash; Adults + Kids = Grand Total</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="index.php#churchHealth" class="btn btn-outline-secondary">
                        <i class="bi bi-graph-up me-1"></i>Church Health
                    </a>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addSaModal"
                            <?php echo $saHasServices ? '' : 'disabled title="No service times are configured yet"'; ?>>
                        <i class="bi bi-plus-circle me-1"></i>Add Entry
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#daySaModal" id="saDayOpenBtn"
                            <?php echo $saHasServices ? '' : 'disabled title="No service times are configured yet"'; ?>>
                        <i class="bi bi-calendar-week me-1"></i>Enter a Sunday
                    </button>
                </div>
            </div>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php $saMsgs = [
                    'add'    => 'Attendance entry has been added successfully.',
                    'update' => 'Attendance entry has been updated successfully.',
                    'delete' => 'Attendance entry has been deleted successfully.',
                ];
                // "Enter a Sunday" reports its own summary sentence.
                echo ($_GET['notif'] === 'day')
                    ? htmlspecialchars($_GET['msg'] ?? 'Saved.')
                    : ($saMsgs[$_GET['notif']] ?? 'Done.'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($_GET['msg'] ?? 'An error occurred.'); ?>
                <?php if ($saEditId > 0): ?>
                <button type="button" class="btn btn-sm btn-warning ms-2" onclick="saOpenEditById(<?php echo $saEditId; ?>)">
                    <i class="bi bi-pencil me-1"></i>Open the existing entry
                </button>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!$saHasServices): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                No service times are configured, so there is nothing to record against. Add them under
                <a href="index.php?action=settings&tab=services" class="alert-link">Settings &rsaquo; Church Services</a>.
            </div>
            <?php endif; ?>

            <!-- Summary — every figure computed from the filtered rows, never stored. -->
            <div class="row mb-3 g-2">
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100 border-primary border-2"><div class="card-body py-2">
                        <div class="h4 fw-bold text-primary mb-0"><?php echo $saLatest['date'] ? (int)$saLatest['total'] : '—'; ?></div>
                        <div style="font-size:11px;" class="text-muted">
                            Latest Service Day<?php echo $saLatest['date'] ? ' &middot; ' . date('M j, Y', strtotime($saLatest['date'])) : ''; ?>
                        </div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100"><div class="card-body py-2">
                        <div class="h4 fw-bold text-success mb-0"><?php echo (int)$saStats['adults']; ?></div>
                        <div style="font-size:11px;" class="text-muted">Adults (filtered)</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100"><div class="card-body py-2">
                        <div class="h4 fw-bold text-info mb-0"><?php echo (int)$saStats['kids']; ?></div>
                        <div style="font-size:11px;" class="text-muted">Kids (filtered)</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center h-100"><div class="card-body py-2">
                        <div class="h4 fw-bold text-warning mb-0"><?php echo $saStats['records'] > 0 ? (int)$saStats['average'] : '—'; ?></div>
                        <div style="font-size:11px;" class="text-muted"
                             title="AVG(grand_total) across the <?php echo (int)$saStats['records']; ?> filtered entries — calculated, never stored.">
                            Average per Service
                        </div>
                    </div></div>
                </div>
            </div>

            <?php if ($saByService): ?>
            <div class="card mb-3">
                <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-bold text-muted text-uppercase me-1" style="letter-spacing:.05em">
                        <i class="bi bi-clock me-1"></i>Average by service time
                    </span>
                    <?php foreach ($saByService as $sv): ?>
                    <span class="badge bg-light text-dark border">
                        <?php echo htmlspecialchars($sv['service_name']); ?>:
                        <strong><?php echo (int)$sv['average']; ?></strong>
                        <span class="text-muted">avg &middot; <?php echo (int)$sv['records']; ?> entries</span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center py-2">
                    <span class="text-white fw-semibold">
                        <i class="bi bi-funnel me-2"></i>Search &amp; Filter
                        <?php if ($saFilterCount): ?>
                        <span class="badge bg-white text-primary ms-1"><?php echo $saFilterCount; ?> active</span>
                        <?php endif; ?>
                    </span>
                    <a href="index.php?action=serviceAttendance" class="btn btn-sm btn-outline-light"><i class="bi bi-x-circle me-1"></i>Clear</a>
                </div>
                <div class="card-body py-3">
                    <form method="GET" action="index.php" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="serviceAttendance">
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Date from</label>
                            <input type="date" name="date_from" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($activeFilters['date_from'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Date to</label>
                            <input type="date" name="date_to" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($activeFilters['date_to'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Service Time</label>
                            <select name="service_name" class="form-select form-select-sm">
                                <option value="">All service times</option>
                                <?php
                                // Union of configured times and times that already have records,
                                // so a renamed/retired service still filters its own history.
                                $saFilterNames = array_values(array_unique(array_merge(
                                    array_column($serviceTimes, 'name'), $recordedTimes
                                )));
                                sort($saFilterNames);
                                foreach ($saFilterNames as $svcName): ?>
                                <option value="<?php echo htmlspecialchars($svcName); ?>"
                                    <?php echo ($activeFilters['service_name'] ?? '') === $svcName ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($svcName); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Search</label>
                            <input type="text" id="saFilterSearch" class="form-control form-control-sm" placeholder="Notes, date…">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-funnel"></i></button>
                        </div>
                    </form>
                </div>
            </div>
            <!-- Records table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-white fw-semibold d-flex align-items-center flex-wrap gap-2">
                        <i class="bi bi-table me-2"></i>Attendance Entries
                        <span class="badge bg-white text-dark border" id="saRecordCount"><?php echo count($records); ?></span>
                    </span>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2 me-3">
                            <label class="text-white small mb-0">Show</label>
                            <select id="saPerPage" class="form-select form-select-sm" style="width:auto;">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="-1">All</option>
                            </select>
                            <label class="text-white small mb-0">per page</label>
                        </div>
                        <?php // 8 columns: # (0) … Actions (7) — both stay locked. ?>
                        <span class="col-toggle" data-table="saTable" data-locked="0,7"></span>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSaCSV()" title="Export CSV"><i class="bi bi-filetype-csv me-1"></i>CSV</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSaExcel()" title="Export Excel"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="exportSaPDF()" title="Export PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                            <button type="button" class="btn btn-sm btn-outline-light" onclick="printSa()" title="Print"><i class="bi bi-printer me-1"></i>Print</button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($records)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-calendar-x display-4 mb-3 d-block"></i>
                        No attendance entries<?php echo $saFilterCount ? ' match these filters' : ' yet'; ?>.<br>
                        <small>
                            <?php if ($saFilterCount): ?>
                            Try adjusting or <a href="index.php?action=serviceAttendance">clearing the filters</a>.
                            <?php elseif ($saHasServices): ?>
                            Use <strong>Add Entry</strong> above to record the first Sunday head count.
                            <?php else: ?>
                            Configure your service times first.
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="width:100%" id="saTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Service Time</th>
                                    <th class="text-end">Adults</th>
                                    <th class="text-end">Kids</th>
                                    <th class="text-end" title="Adults + Kids — a generated column in the database, never typed and never written by the app.">Grand Total</th>
                                    <th>Notes</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $i => $r):
                                    $saRowJson = [
                                        'id'           => (int)$r['id'],
                                        'service_date' => $r['service_date'],
                                        'service_name' => $r['service_name'],
                                        'adults'       => (int)$r['adults'],
                                        'kids'         => (int)$r['kids'],
                                        'notes'        => (string)($r['notes'] ?? ''),
                                    ];
                                ?>
                                <tr data-sa-id="<?php echo (int)$r['id']; ?>">
                                    <td class="text-muted small sa-row-num"><?php echo $i + 1; ?></td>
                                    <td class="text-nowrap">
                                        <span class="fw-semibold"><?php echo htmlspecialchars(date('M j, Y', strtotime($r['service_date']))); ?></span>
                                        <div class="text-muted" style="font-size:10px;"><?php echo htmlspecialchars(date('l', strtotime($r['service_date']))); ?></div>
                                    </td>
                                    <td><span class="badge bg-info text-dark"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($r['service_name']); ?></span></td>
                                    <td class="text-end"><?php echo (int)$r['adults']; ?></td>
                                    <td class="text-end"><?php echo (int)$r['kids']; ?></td>
                                    <td class="text-end fw-bold text-primary"><?php echo (int)$r['grand_total']; ?></td>
                                    <td class="small">
                                        <?php echo !empty($r['notes']) ? htmlspecialchars($r['notes']) : '<span class="text-muted">—</span>'; ?>
                                        <?php if (!empty($r['recorded_by_name'])): ?>
                                        <div class="text-muted" style="font-size:10px;"><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($r['recorded_by_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                                onclick='saOpenEditModal(<?php echo htmlspecialchars(json_encode($saRowJson), ENT_QUOTES); ?>)'>
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a class="btn btn-sm btn-outline-danger" title="Delete"
                                           href="index.php?action=deleteServiceAttendance&id=<?php echo (int)$r['id']; ?>"
                                           data-confirm="Delete the <?php echo htmlspecialchars($r['service_name']); ?> entry for <?php echo date('M j, Y', strtotime($r['service_date'])); ?>? This cannot be undone.">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Totals (filtered)</td>
                                    <td class="text-end"><?php echo (int)$saStats['adults']; ?></td>
                                    <td class="text-end"><?php echo (int)$saStats['kids']; ?></td>
                                    <td class="text-end text-primary"><?php echo (int)$saStats['total']; ?></td>
                                    <td colspan="2" class="small fw-normal text-muted">
                                        Average <strong><?php echo (int)$saStats['average']; ?></strong> per entry
                                        across <?php echo (int)$saStats['records']; ?> entries
                                        / <?php echo (int)$saStats['sundays']; ?> service day<?php echo (int)$saStats['sundays'] === 1 ? '' : 's'; ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php
            // Add + Edit share one field block. Grand Total is rendered as a
            // read-only display, never an input: the number the admin sees
            // while typing is computed in JS purely for immediate feedback,
            // and the number that is stored is the database's generated column.
            $saModals = [
                ['id' => 'addSaModal',  'form' => 'addSaForm',  'title' => 'Add Attendance Entry',  'action' => 'index.php?action=addServiceAttendance', 'prefix' => 'sa_add'],
                ['id' => 'editSaModal', 'form' => 'editSaForm', 'title' => 'Edit Attendance Entry', 'action' => '', 'prefix' => 'sa_edit'],
            ];
            foreach ($saModals as $m):
                $p = $m['prefix'];
            ?>
            <div class="modal fade" id="<?php echo $m['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST" action="<?php echo $m['action']; ?>" id="<?php echo $m['form']; ?>" class="sa-form">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title"><i class="bi bi-heart-pulse me-2"></i><?php echo $m['title']; ?></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                                        <input type="date" name="service_date" id="<?php echo $p; ?>_date" class="form-control" required
                                               max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold">Service Time <span class="text-danger">*</span></label>
                                        <select name="service_name" id="<?php echo $p; ?>_service" class="form-select" required>
                                            <option value="">Select a service time…</option>
                                            <?php foreach ($serviceTimes as $st): ?>
                                            <option value="<?php echo htmlspecialchars($st['name']); ?>">
                                                <?php echo htmlspecialchars($st['name']); ?><?php echo !empty($st['service_period']) ? ' (' . htmlspecialchars($st['service_period']) . ')' : ''; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text" style="font-size:11px;">
                                            From <a href="index.php?action=settings&tab=services">Settings &rsaquo; Church Services</a>.
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">Adults <span class="text-danger">*</span></label>
                                        <input type="number" name="adults" id="<?php echo $p; ?>_adults" class="form-control sa-count"
                                               min="0" step="1" value="0" required>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-semibold">Kids <span class="text-danger">*</span></label>
                                        <input type="number" name="kids" id="<?php echo $p; ?>_kids" class="form-control sa-count"
                                               min="0" step="1" value="0" required>
                                    </div>
                                    <div class="col-12">
                                        <div class="border rounded p-2 d-flex justify-content-between align-items-center bg-light">
                                            <span class="small fw-bold text-muted text-uppercase" style="letter-spacing:.05em">
                                                <i class="bi bi-calculator me-1"></i>Grand Total
                                            </span>
                                            <span class="h4 fw-bold text-primary mb-0" id="<?php echo $p; ?>_total">0</span>
                                        </div>
                                        <div class="form-text" style="font-size:11px;">
                                            Adults + Kids. Calculated automatically &mdash; it is never typed and is stored as a generated column.
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-semibold">Notes</label>
                                        <input type="text" name="notes" id="<?php echo $p; ?>_notes" class="form-control"
                                               maxlength="255" placeholder="Optional — e.g. Baptism Sunday, typhoon">
                                    </div>
                                </div>
                                <div class="alert alert-info py-2 small mt-3 mb-0">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Only one entry is allowed per Date + Service Time. Recording the same slot twice
                                    will offer to open the existing entry instead.
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
            <?php endforeach; ?>

            <!-- Enter a Sunday: one row per configured service time, saved together.
                 Picking a date that already has entries prefills them, so the same
                 form edits them. Grand totals here are display-only, as above. -->
            <?php $saDayQuery = http_build_query(array_filter($activeFilters)); ?>
            <div class="modal fade" id="daySaModal" tabindex="-1" aria-labelledby="daySaTitle">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <form method="POST" id="daySaForm" novalidate
                              action="index.php?action=saveServiceDay<?php echo $saDayQuery ? '&amp;' . htmlspecialchars($saDayQuery) : ''; ?>">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title" id="daySaTitle"><i class="bi bi-calendar-week me-2"></i>Enter a Sunday</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-2 align-items-end mb-2">
                                    <div class="col-sm-5">
                                        <label for="sa_day_date" class="form-label fw-semibold mb-1">Date <span class="text-danger">*</span></label>
                                        <?php // Most recent Sunday (today, if today is Sunday) by the SERVER clock, so it always agrees with max. ?>
                                        <input type="date" name="service_date" id="sa_day_date" class="form-control" required max="<?php echo date('Y-m-d'); ?>"
                                               data-default="<?php echo date('Y-m-d', strtotime('-' . date('w') . ' days')); ?>">
                                    </div>
                                    <div class="col-sm-7">
                                        <div id="sa_day_hint" class="small text-muted" aria-live="polite"></div>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-1" id="saDayTable">
                                        <thead class="table-light">
                                            <tr><th>Service</th><th style="width:22%">Adults</th><th style="width:22%">Kids</th><th class="text-end" style="width:16%">Total</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($serviceTimes as $i => $st): $sn = (string)$st['name']; ?>
                                            <tr class="sa-day-row" data-name="<?php echo htmlspecialchars($sn); ?>" data-i="<?php echo (int)$i; ?>">
                                                <td>
                                                    <input type="hidden" name="svc[<?php echo (int)$i; ?>][name]" value="<?php echo htmlspecialchars($sn); ?>">
                                                    <span class="fw-semibold"><?php echo htmlspecialchars($sn); ?></span>
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1 d-none sa-day-saved">saved</span>
                                                </td>
                                                <td><input type="number" class="form-control form-control-sm sa-day-in" name="svc[<?php echo (int)$i; ?>][adults]"
                                                           min="0" step="1" inputmode="numeric" placeholder="—" aria-label="Adults at <?php echo htmlspecialchars($sn); ?>"></td>
                                                <td><input type="number" class="form-control form-control-sm sa-day-in" name="svc[<?php echo (int)$i; ?>][kids]"
                                                           min="0" step="1" inputmode="numeric" placeholder="—" aria-label="Kids at <?php echo htmlspecialchars($sn); ?>"></td>
                                                <td class="text-end fw-bold text-primary sa-day-total">—</td>
                                            </tr>
                                            <tr class="sa-day-del d-none">
                                                <td colspan="4" class="pt-0">
                                                    <div class="alert alert-warning py-1 px-2 mb-1 small">
                                                        <i class="bi bi-exclamation-triangle me-1"></i>
                                                        <?php echo htmlspecialchars($sn); ?> already has a saved entry (<span class="sa-day-was"></span>). Clearing the boxes keeps it as it is unless you tick:
                                                        <div class="form-check mt-1 mb-0">
                                                            <input class="form-check-input" type="checkbox" value="1" name="svc[<?php echo (int)$i; ?>][delete]" id="sa_day_del_<?php echo (int)$i; ?>">
                                                            <label class="form-check-label fw-semibold" for="sa_day_del_<?php echo (int)$i; ?>">Delete the saved <?php echo htmlspecialchars($sn); ?> entry</label>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-light">
                                                <td colspan="3" class="text-end fw-semibold">Day total</td>
                                                <td class="text-end h5 fw-bold text-primary mb-0" id="sa_day_total">0</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <div id="sa_day_other" class="small text-muted d-none"></div>
                                <div id="sa_day_error" class="alert alert-danger py-2 small d-none mb-0 mt-2" role="alert"></div>
                                <div class="form-text">Leave a service blank to skip it &mdash; blanks are not saved as zeros. If only one box is filled, the other counts as 0.</div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary" id="sa_day_submit"><i class="bi bi-save me-1"></i>Save Sunday</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

<?php include 'shared/footer.php'; ?>
<script>
// ── Live Grand Total ────────────────────────────────────────────────────────
// Feedback only. The stored total is the database's generated column, so this
// never posts a value — there is no grand_total input to post.
function saRecalcTotal(prefix) {
    var a = parseInt((document.getElementById(prefix + '_adults') || {}).value, 10);
    var k = parseInt((document.getElementById(prefix + '_kids')   || {}).value, 10);
    var out = document.getElementById(prefix + '_total');
    if (!out) return;
    out.textContent = (isNaN(a) || a < 0 ? 0 : a) + (isNaN(k) || k < 0 ? 0 : k);
}
['sa_add', 'sa_edit'].forEach(function (p) {
    ['_adults', '_kids'].forEach(function (suffix) {
        var el = document.getElementById(p + suffix);
        if (el) el.addEventListener('input', function () { saRecalcTotal(p); });
    });
});

// ── Add modal: default the date to the most recent Sunday ───────────────────
var saAddModal = document.getElementById('addSaModal');
if (saAddModal) {
    saAddModal.addEventListener('show.bs.modal', function () {
        var d = document.getElementById('sa_add_date');
        if (d && !d.value) {
            var t = new Date();
            t.setDate(t.getDate() - t.getDay()); // back up to Sunday
            d.value = t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0')
                    + '-' + String(t.getDate()).padStart(2, '0');
        }
        saRecalcTotal('sa_add');
    });
}

// ── Edit ────────────────────────────────────────────────────────────────────
function saOpenEditModal(row) {
    var f = document.getElementById('editSaForm');
    if (!f) return;
    f.action = 'index.php?action=updateServiceAttendance&id=' + row.id
             + '<?php echo htmlspecialchars(str_replace('&', '&amp;', http_build_query(array_filter($activeFilters)) ? '&' . http_build_query(array_filter($activeFilters)) : ''), ENT_QUOTES); ?>';
    document.getElementById('sa_edit_date').value    = row.service_date || '';
    document.getElementById('sa_edit_adults').value  = row.adults;
    document.getElementById('sa_edit_kids').value    = row.kids;
    document.getElementById('sa_edit_notes').value   = row.notes || '';
    var sel = document.getElementById('sa_edit_service');
    sel.value = row.service_name || '';
    // A record entered under a service that has since been renamed or retired
    // would otherwise reset the dropdown to blank and silently change on save.
    if (sel.value !== (row.service_name || '') && row.service_name) {
        sel.add(new Option(row.service_name + ' (no longer configured)', row.service_name, true, true));
    }
    saRecalcTotal('sa_edit');
    new bootstrap.Modal(document.getElementById('editSaModal')).show();
}

// Opens the Edit modal for a given id — used by the duplicate-entry message,
// which hands back the row already holding that Date + Service Time.
function saOpenEditById(id) {
    var tr = document.querySelector('tr[data-sa-id="' + id + '"]');
    var btn = tr ? tr.querySelector('button[onclick^="saOpenEditModal"]') : null;
    if (btn) { btn.click(); return; }
    // The clashing row is outside the current filter — clear filters and retry.
    window.location.href = 'index.php?action=serviceAttendance&edit=' + id;
}

// Guard against a double-submit creating the duplicate the UNIQUE key would
// then have to reject.
document.querySelectorAll('form.sa-form').forEach(function (f) {
    f.addEventListener('submit', function () {
        var b = f.querySelector('button[type="submit"]');
        if (b) { b.disabled = true; b.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving…'; }
    });
});

// ── Enter a Sunday ──────────────────────────────────────────────────────────
(function () {
    var modal = document.getElementById('daySaModal');
    if (!modal) return;
    var form = document.getElementById('daySaForm'), dateEl = document.getElementById('sa_day_date');
    var hint = document.getElementById('sa_day_hint'), errBox = document.getElementById('sa_day_error');
    var rows = Array.prototype.slice.call(form.querySelectorAll('tr.sa-day-row'));
    var saved = {};   // service_name -> saved entry for the loaded date
    var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    function ymd(t) {
        return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0');
    }
    function lastSunday() {
        if (dateEl.dataset.default) return dateEl.dataset.default;
        var t = new Date(); t.setDate(t.getDate() - t.getDay()); return ymd(t);
    }
    function isWhole(v) { return v === '' || /^\d+$/.test(v); }
    // A number input reports '' for text it cannot parse; badInput tells the two apart.
    function val(i) { return (i.validity && i.validity.badInput) ? '?' : i.value.trim(); }

    function recalc() {
        var day = 0;
        rows.forEach(function (tr) {
            var ins = tr.querySelectorAll('input.sa-day-in'), a = val(ins[0]), k = val(ins[1]);
            var ok = isWhole(a) && isWhole(k);
            ins[0].classList.toggle('is-invalid', !isWhole(a));
            ins[1].classList.toggle('is-invalid', !isWhole(k));
            var blank = a === '' && k === '';
            var tot = (parseInt(a, 10) || 0) + (parseInt(k, 10) || 0);
            tr.querySelector('.sa-day-total').textContent = blank ? '—' : (ok ? tot : '?');
            if (!blank && ok) day += tot;
            // Cleared a row that has a saved entry: ask, in the form, what to do.
            var del = tr.nextElementSibling, was = saved[tr.dataset.name];
            var showDel = blank && !!was;
            del.classList.toggle('d-none', !showDel);
            if (!showDel) del.querySelector('input[type=checkbox]').checked = false;
        });
        document.getElementById('sa_day_total').textContent = day.toLocaleString();
    }

    function loadDay() {
        var date = dateEl.value;
        errBox.classList.add('d-none');
        saved = {};
        rows.forEach(function (tr) {
            tr.querySelectorAll('input.sa-day-in').forEach(function (i) { i.value = ''; });
            tr.querySelector('.sa-day-saved').classList.add('d-none');
        });
        recalc();
        if (!date) { hint.textContent = ''; return; }
        var dow = new Date(date + 'T00:00:00').getDay();
        var dayNote = dow === 0 ? '' : 'Note: that date is a ' + DAYS[dow] + '. ';
        hint.textContent = dayNote + 'Loading saved entries…';
        fetch('index.php?action=ajaxServiceDay&date=' + encodeURIComponent(date), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (dateEl.value !== date) return;          // the date changed while loading
                saved = d.entries || {};
                var names = Object.keys(saved), configured = 0;
                rows.forEach(function (tr) {
                    var e = saved[tr.dataset.name];
                    if (!e) return;
                    configured++;
                    var ins = tr.querySelectorAll('input.sa-day-in');
                    ins[0].value = e.adults; ins[1].value = e.kids;
                    tr.querySelector('.sa-day-saved').classList.remove('d-none');
                    tr.nextElementSibling.querySelector('.sa-day-was').textContent =
                        e.adults + ' adults + ' + e.kids + ' kids';
                });
                hint.innerHTML = '';
                hint.appendChild(document.createTextNode(dayNote + (configured
                    ? 'This date already has ' + configured + ' saved entr' + (configured === 1 ? 'y' : 'ies') + ' — saving updates them.'
                    : 'Nothing saved for this date yet.')));
                // Entries under a service time that is no longer configured stay
                // untouched by this form; point at the table to edit them.
                var other = names.filter(function (n) { return !rows.some(function (tr) { return tr.dataset.name === n; }); });
                var otherEl = document.getElementById('sa_day_other');
                otherEl.classList.toggle('d-none', !other.length);
                otherEl.textContent = other.length
                    ? 'Also recorded that day under a retired service time: ' + other.join(', ') + '. Edit it from the table.'
                    : '';
                recalc();
            })
            .catch(function () { hint.textContent = dayNote + 'Could not load saved entries for this date.'; });
    }

    modal.addEventListener('show.bs.modal', function () {
        if (!dateEl.value) dateEl.value = lastSunday();
        loadDay();
    });
    dateEl.addEventListener('change', loadDay);
    form.addEventListener('input', function (e) { if (e.target.classList.contains('sa-day-in')) recalc(); });

    form.addEventListener('submit', function (e) {
        recalc();
        var problems = [], filled = 0, deleting = 0;
        if (!dateEl.value) problems.push('Choose a date.');
        rows.forEach(function (tr) {
            var ins = tr.querySelectorAll('input.sa-day-in'), a = val(ins[0]), k = val(ins[1]);
            if (!isWhole(a) || !isWhole(k)) problems.push(tr.dataset.name + ': Adults and Kids must be whole numbers of zero or more.');
            if (a !== '' || k !== '') filled++;
            if (tr.nextElementSibling.querySelector('input[type=checkbox]').checked) deleting++;
        });
        if (!problems.length && !filled && !deleting) problems.push('Enter Adults or Kids for at least one service.');
        if (problems.length) {
            e.preventDefault();
            errBox.textContent = problems.join(' ');
            errBox.classList.remove('d-none');
            return;
        }
        var b = document.getElementById('sa_day_submit');
        b.disabled = true; b.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving…';
    });

    // ?enterDay=YYYY-MM-DD (a bounced save, or a link from the dashboard) opens
    // the form straight away; any other non-empty value means "last Sunday".
    <?php $saEnterDay = (string)($_GET['enterDay'] ?? ''); if ($saEnterDay !== '' && $saHasServices): ?>
    window.addEventListener('DOMContentLoaded', function () {
        var v = <?php echo json_encode($saEnterDay); ?>;
        if (/^\d{4}-\d{2}-\d{2}$/.test(v)) dateEl.value = v;
        new bootstrap.Modal(modal).show();
    });
    <?php endif; ?>
})();

// ── Table: DataTable, per-page, search, exports ─────────────────────────────
$(function () {
    if (!$('#saTable').length) return;

    window.saTable = $('#saTable').DataTable({
        responsive: true,
        pageLength: 25,
        order: [],                 // keep the server's date-descending order
        searching: true,
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
        },
        dom: 'rt<"d-flex justify-content-between align-items-center mt-2 px-2"ip>'
    });

    window.saTable.on('draw', function () {
        var info = window.saTable.page.info();
        var badge = document.getElementById('saRecordCount');
        if (badge) badge.textContent = info.recordsDisplay;
        var start = info.start;
        window.saTable.rows({ page: 'current' }).nodes().each(function (row, i) {
            var cell = row.querySelector('td.sa-row-num');
            if (cell) cell.textContent = start + i + 1;
        });
    });

    $('#saPerPage').on('change', function () {
        window.saTable.page.len(parseInt($(this).val(), 10)).draw();
    });

    var saSearchTimer;
    $('#saFilterSearch').on('input', function () {
        var val = $(this).val();
        clearTimeout(saSearchTimer);
        saSearchTimer = setTimeout(function () { window.saTable.search(val).draw(); }, 250);
    });

    // A duplicate save bounces back with &edit=<id>; open that row straight away.
    <?php if ($saEditId > 0): ?>
    saOpenEditById(<?php echo $saEditId; ?>);
    <?php endif; ?>
});

// Exports mirror the Attendance Records page: headers and rows are read from
// the live table, so hidden columns and the active search are both respected.
function getSaExportHeaders() {
    var ths = document.querySelectorAll('#saTable thead th'), out = [];
    for (var i = 0; i < ths.length - 1; i++) out.push(ths[i].textContent.trim());
    return out;
}
function getSaExportRows() {
    var rows = [];
    if (!window.saTable) return rows;
    var totalCols = document.querySelectorAll('#saTable thead th').length;
    window.saTable.rows({ search: 'applied' }).every(function () {
        var tds = this.node().querySelectorAll('td'), row = [];
        for (var i = 0; i < totalCols - 1; i++) {
            if (!tds[i]) { row.push(''); continue; }
            var c = tds[i].cloneNode(true);
            c.querySelectorAll('.d-none, style, button').forEach(function (el) { el.remove(); });
            row.push(c.textContent.replace(/\s+/g, ' ').trim());
        }
        rows.push(row);
    });
    return rows;
}
function saFileName(ext) {
    return 'sunday_service_attendance_' + new Date().toISOString().slice(0, 10) + '.' + ext;
}
function exportSaCSV() {
    var headers = getSaExportHeaders(), rows = getSaExportRows();
    var q = function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; };
    var csv = [headers.map(q).join(',')];
    rows.forEach(function (r) { csv.push(r.map(q).join(',')); });
    var blob = new Blob(['﻿' + csv.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = saFileName('csv');
    a.click();
}
function exportSaExcel() {
    if (typeof XLSX === 'undefined') { alert('Excel library not loaded.'); return; }
    var headers = getSaExportHeaders(), rows = getSaExportRows();
    var wb = XLSX.utils.book_new();
    var ws = XLSX.utils.aoa_to_sheet([headers].concat(rows));
    ws['!cols'] = headers.map(function (h, i) {
        var max = h.length;
        rows.forEach(function (r) { if (r[i] && r[i].length > max) max = r[i].length; });
        return { wch: Math.min(max + 2, 50) };
    });
    XLSX.utils.book_append_sheet(wb, ws, 'Sunday Attendance');
    XLSX.writeFile(wb, saFileName('xlsx'));
}
function exportSaPDF() {
    if (!window.jspdf) { alert('PDF library not loaded.'); return; }
    var headers = getSaExportHeaders(), rows = getSaExportRows();
    var doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    doc.setFontSize(13);
    doc.text('Sunday Service Attendance', 40, 36);
    doc.setFontSize(9); doc.setTextColor(120);
    doc.text('Exported: ' + new Date().toLocaleDateString() + '  |  ' + rows.length + ' entries', 40, 52);
    doc.autoTable({
        head: [headers], body: rows, startY: 62,
        styles: { fontSize: 8, cellPadding: 3 },
        headStyles: { fillColor: [25, 135, 84], textColor: 255, fontStyle: 'bold' },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        margin: { left: 40, right: 40 }
    });
    doc.save(saFileName('pdf'));
}
function printSa() {
    var headers = getSaExportHeaders(), rows = getSaExportRows();
    var thead = '<tr>' + headers.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr>';
    var tbody = rows.map(function (r) {
        return '<tr>' + r.map(function (v) { return '<td>' + (v || '—') + '</td>'; }).join('') + '</tr>';
    }).join('');
    var html = '<!DOCTYPE html><html><head><title>Sunday Service Attendance</title>'
        + '<style>body{font-family:sans-serif;font-size:11px;padding:20px}'
        + 'h2{margin-bottom:4px}p{color:#666;margin:0 0 12px}'
        + 'table{border-collapse:collapse;width:100%}'
        + 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}'
        + 'th{background:#198754;color:#fff}tr:nth-child(even){background:#f5f7fa}'
        + '@media print{@page{size:landscape}}</style></head><body>'
        + '<h2>Sunday Service Attendance</h2>'
        + '<p>Printed: ' + new Date().toLocaleDateString() + ' &nbsp;|&nbsp; ' + rows.length + ' entries</p>'
        + '<table><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></body></html>';
    var w = window.open('', '_blank');
    w.document.write(html); w.document.close(); w.focus();
    setTimeout(function () { w.print(); }, 500);
}
</script>
