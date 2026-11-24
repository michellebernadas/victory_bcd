<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
if (!hasPermission('join_requests')) { header('Location: index.php'); exit(); }
include 'shared/header.php';
$requests = $requests ?? [];
$counts   = $counts   ?? [];
$status   = isset(JoinRequest::STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$search   = trim((string)($_GET['q'] ?? ''));
$total    = array_sum($counts);
$activeTab = $activeTab ?? 'list';
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-inbox me-2 text-primary"></i>Get Connected Inbox</h1>
                    <p class="text-muted mb-0">People who said they are interested in a Victory Group on the public website. Reach out and welcome them.</p>
                    <p class="small text-muted mb-0">
                        <?php $jrFirst = true; foreach (JoinRequest::STATUSES as $k => $m): ?><?php echo $jrFirst ? '' : ' <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i> '; $jrFirst = false; ?><span class="badge bg-<?php echo $m['badge']; ?>"><?php echo htmlspecialchars($m['label']); ?></span> <?php echo htmlspecialchars($m['hint']); ?><?php endforeach; ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-outline-secondary" href="../join.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open the public form</a>
                    <a class="btn btn-outline-secondary" href="../groups.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Public groups page</a>
                </div>
            </div>

            <?php if (!Mailer::configured()): ?>
            <div class="alert alert-warning d-flex gap-2"><i class="bi bi-envelope-exclamation mt-1"></i><div><strong>Email is not set up yet, so leaders are not being emailed.</strong>
                New requests still arrive here. To email leaders and staff automatically, add the <code>MAIL_*</code> settings to the server's <code>.env</code> (see <code>.env.example</code>), then press the envelope button on a request to send it again.</div></div>
            <?php endif; ?>

            <?php if (!empty($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_GET['notif']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>
            <?php if (!empty($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_GET['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <!-- Tabs (same pair as the Members page) -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item"><a class="nav-link <?php echo $activeTab === 'list' ? 'active' : ''; ?>" href="index.php?action=joinRequests"><i class="bi bi-table me-1"></i>Requests</a></li>
                <li class="nav-item"><a class="nav-link <?php echo $activeTab === 'stats' ? 'active' : ''; ?>" href="index.php?action=joinRequests&amp;tab=stats"><i class="bi bi-bar-chart-line me-1"></i>Statistics</a></li>
            </ul>

            <?php if ($activeTab === 'stats'): ?>
                <?php include 'shared/join_stats_tab.php'; ?>
            <?php else: ?>
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <ul class="nav nav-pills small">
                        <li class="nav-item"><a class="nav-link py-1 <?php echo $status === '' ? 'active' : ''; ?>" href="index.php?action=joinRequests">All <span class="badge bg-light text-dark ms-1"><?php echo (int)$total; ?></span></a></li>
                        <?php foreach (JoinRequest::STATUSES as $k => $m): ?>
                        <li class="nav-item"><a class="nav-link py-1 <?php echo $status === $k ? 'active' : ''; ?>" href="index.php?action=joinRequests&amp;status=<?php echo $k; ?>"><?php echo htmlspecialchars($m['label']); ?>
                            <span class="badge bg-<?php echo $m['badge']; ?> ms-1"><?php echo (int)($counts[$k] ?? 0); ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="get" action="index.php" class="d-flex gap-1">
                        <input type="hidden" name="action" value="joinRequests">
                        <?php if ($status): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>"><?php endif; ?>
                        <input type="search" name="q" class="form-control form-control-sm" placeholder="Search name, phone, email, area" value="<?php echo htmlspecialchars($search); ?>">
                        <button class="btn btn-sm btn-primary" aria-label="Search"><i class="bi bi-search"></i></button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Received</th><th>Who</th><th>Contact</th><th>Group they asked about</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($requests as $r):
                            $m = JoinRequest::STATUSES[$r['status']] ?? ['label' => $r['status'], 'badge' => 'secondary'];
                            $js = ['id' => (int)$r['id'], 'name' => $r['full_name'], 'status' => $r['status'], 'notes' => (string)$r['staff_notes'],
                                   'message' => (string)$r['message'], 'area' => (string)$r['area']];
                        ?>
                            <tr class="<?php echo $r['status'] === 'new' ? 'table-warning' : ''; ?>">
                                <td class="text-nowrap small"><?php echo date('M j, Y', strtotime($r['created_at'])); ?><div class="text-muted"><?php echo date('g:i A', strtotime($r['created_at'])); ?></div></td>
                                <td><div class="fw-semibold"><?php echo htmlspecialchars($r['full_name']); ?></div>
                                    <?php if ($r['life_stage'] || $r['gender']): ?><div class="mb-1"><?php if ($r['life_stage']): ?><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(GroupMatcher::LIFE_STAGES[$r['life_stage']] ?? $r['life_stage']); ?></span> <?php endif; ?><?php if ($r['gender']): ?><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(GroupMatcher::GENDERS[$r['gender']] ?? $r['gender']); ?></span><?php endif; ?></div><?php endif; ?>
                                    <?php if ($r['area']): ?><div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($r['area']); ?></div><?php endif; ?>
                                    <?php if ($r['message']): ?><div class="small fst-italic mt-1">&ldquo;<?php echo htmlspecialchars($r['message']); ?>&rdquo;</div><?php endif; ?></td>
                                <td class="small">
                                    <?php if ($r['phone']): ?><div><i class="bi bi-telephone me-1"></i><a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $r['phone'])); ?>"><?php echo htmlspecialchars($r['phone']); ?></a></div><?php endif; ?>
                                    <?php if ($r['email']): ?><div><i class="bi bi-envelope me-1"></i><a href="mailto:<?php echo htmlspecialchars($r['email']); ?>"><?php echo htmlspecialchars($r['email']); ?></a></div><?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if ($r['group_id'] && $r['group_kind']): ?>
                                        <span class="badge bg-light text-dark border"><?php echo $r['group_kind'] === 'campus' ? 'Campus' : 'Victory Group'; ?></span>
                                        <div><?php echo htmlspecialchars(trim($r['day_of_week'] . ($r['meeting_time'] ? ' ' . date('g:i A', strtotime($r['meeting_time'])) : ''))); ?></div>
                                        <?php if ($r['group_leaders']): ?><div class="text-muted"><?php echo htmlspecialchars($r['group_leaders']); ?></div><?php endif; ?>
                                        <a href="index.php?action=editGroup&amp;id=<?php echo (int)$r['group_id']; ?>">Open group</a>
                                    <?php else: ?><span class="text-muted">Not sure yet &mdash; needs help finding a group</span><?php endif; ?>
                                    <?php
                                    $nt = $r['notices']['leader'] ?? null;
                                    if ($r['group_id']) {
                                        if (!$nt) { $cls = 'text-muted'; $ic = 'bi-dash-circle'; $tx = 'Leader not notified yet'; }
                                        elseif ($nt['status'] === 'sent') { $cls = 'text-success'; $ic = 'bi-envelope-check'; $tx = 'Leader emailed ' . date('M j, g:i A', strtotime($nt['created_at'])); }
                                        elseif ($nt['status'] === 'logged') { $cls = 'text-warning-emphasis'; $ic = 'bi-envelope-exclamation'; $tx = 'Email is not set up, so the leader was NOT emailed'; }
                                        elseif ($nt['status'] === 'no_recipient') { $cls = 'text-warning-emphasis'; $ic = 'bi-envelope-exclamation'; $tx = 'No email on file for the leader. Please contact them.'; }
                                        else { $cls = 'text-danger'; $ic = 'bi-envelope-x'; $tx = 'Email to the leader failed'; }
                                        echo '<div class="small mt-1 ' . $cls . '" title="' . htmlspecialchars((string)($nt['detail'] ?? '')) . '"><i class="bi ' . $ic . ' me-1"></i>' . htmlspecialchars($tx) . '</div>';
                                    } ?>
                                </td>
                                <td><span class="badge bg-<?php echo $m['badge']; ?>"><?php echo htmlspecialchars($m['label']); ?></span>
                                    <?php if ($r['handled_by_name']): ?><div class="small text-muted">by <?php echo htmlspecialchars($r['handled_by_name']); ?></div><?php endif; ?>
                                    <?php if ($r['staff_notes']): ?><div class="small text-muted" title="<?php echo htmlspecialchars($r['staff_notes']); ?>"><i class="bi bi-sticky me-1"></i>note</div><?php endif; ?></td>
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary" title="Update status / add a note"
                                            onclick='openJr(<?php echo htmlspecialchars(json_encode($js), ENT_QUOTES); ?>)'><i class="bi bi-pencil-square"></i><span class="visually-hidden">Update</span></button>
                                    <button class="btn btn-sm btn-outline-secondary" title="Send the notification email again"
                                            onclick='resendJr(<?php echo (int)$r["id"]; ?>)'><i class="bi bi-envelope-arrow-up"></i><span class="visually-hidden">Resend</span></button>
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"
                                            onclick='delJr(<?php echo (int)$r['id']; ?>, <?php echo htmlspecialchars(json_encode($r['full_name']), ENT_QUOTES); ?>)'><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$requests): ?>
                            <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-inbox display-6 d-block mb-2"></i>No requests<?php echo ($status || $search) ? ' match this view' : ' yet'; ?>.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; // tab ?>
        </div>
    </div>

    <div class="modal fade" id="jrModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content">
        <form method="post" id="jrForm" action="">
            <div class="modal-header"><h5 class="modal-title">Update request &mdash; <span id="jrName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="_status" value="<?php echo htmlspecialchars($status); ?>">
                <div class="mb-3"><label class="form-label fw-semibold" for="jrStatus">Status</label>
                    <select class="form-select" name="status" id="jrStatus">
                        <?php foreach (JoinRequest::STATUSES as $k => $m): ?><option value="<?php echo $k; ?>"><?php echo htmlspecialchars($m['label']); ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text"><?php echo implode(' &middot; ', array_map(function ($m) { return '<strong>' . htmlspecialchars($m['label']) . ':</strong> ' . htmlspecialchars(lcfirst($m['hint'])); }, JoinRequest::STATUSES)); ?></div></div>
                <div class="mb-1"><label class="form-label fw-semibold" for="jrNotes">Staff notes <span class="text-muted fw-normal">(only staff see this)</span></label>
                    <textarea class="form-control" name="staff_notes" id="jrNotes" rows="3" maxlength="1000" placeholder="e.g. Called Nov 18, placed with the Mandalagan VG"></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save</button></div>
        </form></div></div></div>

    <div class="modal fade" id="jrDelModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <form method="post" id="jrDelForm" action="">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-trash me-2"></i>Delete request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><input type="hidden" name="_status" value="<?php echo htmlspecialchars($status); ?>">
                <p>Delete the request from <strong id="jrDelName"></strong>? This removes their personal details and cannot be undone. Once they are connected to a Victory Group, set the status to <em>Connected to a Victory Group</em> instead: that is what finishes a request.</p></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger btn-sm">Delete</button></div>
        </form></div></div></div>

    <script>
    function openJr(r) {
        document.getElementById('jrName').textContent = r.name;
        document.getElementById('jrStatus').value = r.status;
        document.getElementById('jrNotes').value = r.notes || '';
        document.getElementById('jrForm').action = 'index.php?action=updateJoinRequest&id=' + r.id;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('jrModal')).show();
    }
    function resendJr(id) {
        var f = document.createElement('form'); f.method = 'post'; f.action = 'index.php?action=resendJoinNotice&id=' + id;
        var h = document.createElement('input'); h.type = 'hidden'; h.name = '_status'; h.value = '<?php echo htmlspecialchars($status); ?>'; f.appendChild(h);
        document.body.appendChild(f); f.submit();
    }
    function delJr(id, name) {
        document.getElementById('jrDelName').textContent = name;
        document.getElementById('jrDelForm').action = 'index.php?action=deleteJoinRequest&id=' + id;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('jrDelModal')).show();
    }
    </script>
<?php include 'shared/footer.php'; ?>
