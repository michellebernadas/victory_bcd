<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
require_once 'views/shared/needs_link_row.php';

// Defaults so the page renders cleanly against empty tables.
$nlRows     = $nlRows     ?? [];
$nlSuggest  = $nlSuggest  ?? [];
$nlProgress = $nlProgress ?? ['total' => 0, 'linked' => 0, 'not_a_member' => 0, 'to_go' => 0];
$nlCouples  = count(array_filter($nlRows, fn($r) => !empty($r['is_couple'])));

include 'shared/header.php';
?>
<body>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-link-45deg me-2 text-warning"></i>Needs Link</h1>
                    <p class="text-muted mb-0">Match each imported name to its member record. Names are never changed, and nothing links on its own.</p>
                </div>
                <a href="index.php?action=victoryGroups" class="btn btn-outline-secondary">
                    <i class="bi bi-diagram-3 me-1"></i>Victory Groups
                </a>
            </div>

            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($_GET['msg'] ?? 'Saved.'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($_GET['msg'] ?? 'Something went wrong.'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php
            $pTotal = max(1, (int)$nlProgress['total']);
            $pLinkedPct = (int)floor($nlProgress['linked'] / $pTotal * 100);
            $pNamPct    = (int)floor($nlProgress['not_a_member'] / $pTotal * 100);
            ?>
            <!-- Progress -->
            <div class="card mb-3">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
                        <div class="fw-semibold" id="nlProgressText" aria-live="polite">
                            <?php echo (int)$nlProgress['linked']; ?> of <?php echo (int)$nlProgress['total']; ?> linked
                            &middot; <?php echo (int)$nlProgress['to_go']; ?> to go<?php if ($nlProgress['not_a_member'] > 0): ?>
                            &middot; <?php echo (int)$nlProgress['not_a_member']; ?> <?php echo (int)$nlProgress['not_a_member'] === 1 ? 'not a church member' : 'not church members'; ?><?php endif; ?>
                        </div>
                        <small class="text-muted">A couple counts as two people. Each spouse is linked separately.</small>
                    </div>
                    <div class="progress" style="height:10px" role="progressbar" aria-label="Linking progress">
                        <div class="progress-bar bg-success" id="nlBarLinked" style="width:<?php echo $pLinkedPct; ?>%"></div>
                        <div class="progress-bar bg-secondary" id="nlBarNam" style="width:<?php echo $pNamPct; ?>%"></div>
                    </div>
                </div>
            </div>

            <?php if (empty($nlRows)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-emoji-smile display-4 text-success d-block mb-3"></i>
                    <h2 class="h5 mb-1">Everyone is linked</h2>
                    <p class="text-muted mb-3">
                        <?php if ((int)$nlProgress['total'] === 0): ?>
                        There are no imported names yet. Import the Staff VG Schedule and any names that need a member will appear here.
                        <?php else: ?>
                        Every name on the group schedules is linked to a member or marked as not a church member.
                        <?php endif; ?>
                    </p>
                    <a href="index.php?action=victoryGroups" class="btn btn-sm btn-primary"><i class="bi bi-diagram-3 me-1"></i>Back to Victory Groups</a>
                    <?php if ((int)$nlProgress['total'] === 0): ?>
                    <a href="index.php?action=groupImport" class="btn btn-sm btn-outline-primary ms-1"><i class="bi bi-upload me-1"></i>Import VG Schedule</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>

            <!-- Filters (client-side, mirrored into the URL so a reload keeps your place) -->
            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-4">
                            <label for="nlQ" class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Search</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="search" id="nlQ" class="form-control" placeholder="Name, group, leader, day…">
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label for="nlKind" class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Group kind</label>
                            <select id="nlKind" class="form-select form-select-sm">
                                <option value="">All kinds</option>
                                <option value="victory_group">Victory Group</option>
                                <option value="leadership_group">Leadership Group</option>
                                <option value="campus">Campus</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <label for="nlRole" class="fw-semibold small text-uppercase text-muted mb-1" style="letter-spacing:.05em">Role</label>
                            <select id="nlRole" class="form-select form-select-sm">
                                <option value="">All roles</option>
                                <option value="leader">Leader</option>
                                <option value="intern">Intern</option>
                                <option value="attendee">Member</option>
                                <option value="lg_leader">LG Leader</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="nlCouples">
                                <label class="form-check-label small" for="nlCouples">Couples only (<?php echo $nlCouples; ?>)</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-2 text-md-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="nlClear"><i class="bi bi-x-circle me-1"></i>Clear</button>
                        </div>
                    </div>
                    <div class="small text-muted mt-2" id="nlShowing"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 nl-table" id="nlTable">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:18%">Name as written</th>
                                    <th style="width:24%">Group</th>
                                    <th style="width:8%">Role</th>
                                    <th>Link to a member</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($nlRows as $r) echo nl_render_row($r, $nlSuggest[(int)$r['vg_member_id']] ?? []); ?>
                                <tr id="nlNoMatch" class="d-none"><td colspan="4" class="text-center text-muted py-4">No names match these filters.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Result of the last action (AJAX). -->
    <div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
        <div id="nlToast" class="toast align-items-center border-0" role="status" aria-live="polite" aria-atomic="true">
            <div class="d-flex"><div class="toast-body" id="nlToastBody"></div>
                <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>
        </div>
    </div>

    <style>
        .nl-table td { vertical-align: top; }
        .nl-slot + .nl-slot { border-top: 1px dashed #dee2e6; margin-top: .5rem; padding-top: .5rem; }
        .nl-row.nl-done { background: #f3fbf6; }
        .nl-row.nl-done .nl-name, .nl-row.nl-done .nl-group { opacity: .75; }
        .nl-chip { font-size: .75rem; padding: .1rem .45rem; }
        .nl-row.nl-busy { opacity: .55; pointer-events: none; }
        @media (max-width: 991.98px) {
            .nl-table thead { display: none; }
            .nl-table tr.nl-row { display: block; border-bottom: 1px solid #dee2e6; padding: .5rem .75rem; }
            .nl-table tr.nl-row > td { display: block; border: 0; padding: .2rem 0; width: auto !important; }
            .nl-table td.nl-role { display: inline-block; }
        }
    </style>

<?php include 'shared/footer.php'; ?>
<script>
(function () {
    var table = document.getElementById('nlTable');
    if (!table) return;
    var $ = window.jQuery;
    var q = document.getElementById('nlQ'), kind = document.getElementById('nlKind'),
        role = document.getElementById('nlRole'), couples = document.getElementById('nlCouples');

    // ── Filters, restored from and mirrored into the URL ────────────────────
    var params = new URLSearchParams(window.location.search);
    q.value = params.get('q') || '';
    kind.value = params.get('kind') || '';
    role.value = params.get('role') || '';
    couples.checked = params.get('couples') === '1';

    function applyFilters() {
        var term = q.value.trim().toLowerCase(), shown = 0, total = 0;
        table.querySelectorAll('tr.nl-row').forEach(function (tr) {
            total++;
            var ok = (!kind.value || tr.dataset.kind === kind.value)
                  && (!role.value || tr.dataset.role === role.value)
                  && (!couples.checked || tr.dataset.couple === '1')
                  && (!term || tr.dataset.search.indexOf(term) !== -1);
            tr.classList.toggle('d-none', !ok);
            if (ok) shown++;
        });
        document.getElementById('nlNoMatch').classList.toggle('d-none', shown > 0);
        document.getElementById('nlShowing').textContent = shown === total
            ? 'Showing all ' + total + ' names'
            : 'Showing ' + shown + ' of ' + total + ' names';
        var p = new URLSearchParams(window.location.search);
        [['q', q.value.trim()], ['kind', kind.value], ['role', role.value], ['couples', couples.checked ? '1' : '']]
            .forEach(function (kv) { if (kv[1]) p.set(kv[0], kv[1]); else p.delete(kv[0]); });
        p.delete('notif'); p.delete('error'); p.delete('msg');
        history.replaceState(null, '', 'index.php?' + p.toString() + window.location.hash);
    }
    var t;
    q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(applyFilters, 150); });
    [kind, role, couples].forEach(function (el) { el.addEventListener('change', applyFilters); });
    document.getElementById('nlClear').addEventListener('click', function () {
        q.value = ''; kind.value = ''; role.value = ''; couples.checked = false; applyFilters();
    });
    applyFilters();

    // ── Member search (Select2 over ajaxSearchMembers) ──────────────────────
    function initSearch(scope) {
        if (!$ || !$.fn.select2) return;
        $(scope).find('select.nl-member-search').each(function () {
            if ($(this).hasClass('select2-hidden-accessible')) return;
            $(this).select2({
                width: '100%', placeholder: 'Search members…', allowClear: true, minimumInputLength: 1,
                ajax: {
                    url: 'index.php', dataType: 'json', delay: 250,
                    data: function (p) { return { action: 'ajaxSearchMembers', q: p.term || '' }; },
                    processResults: function (d) { return { results: (d && d.results) || [] }; }
                }
            });
        });
    }
    // After the footer's ready handler: it runs $('.select2').select2() globally, and a
    // Select2 container span carries class "select2", so initialising earlier gave every
    // search box a second, empty twin.
    if ($) $(function () { initSearch(table); });

    // ── Every action posts in the background and redraws just its row ───────
    var toastEl = document.getElementById('nlToast');
    function toast(ok, msg) {
        toastEl.classList.remove('text-bg-success', 'text-bg-danger');
        toastEl.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
        document.getElementById('nlToastBody').textContent = msg;
        if (window.bootstrap) bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3500 }).show();
    }
    function renderProgress(p) {
        var txt = p.linked + ' of ' + p.total + ' linked · ' + p.to_go + ' to go'
                + (p.not_a_member > 0 ? ' · ' + p.not_a_member + (p.not_a_member === 1 ? ' not a church member' : ' not church members') : '');
        document.getElementById('nlProgressText').textContent = txt;
        var tot = Math.max(1, p.total);
        document.getElementById('nlBarLinked').style.width = Math.floor(p.linked / tot * 100) + '%';
        document.getElementById('nlBarNam').style.width = Math.floor(p.not_a_member / tot * 100) + '%';
        var badge = document.getElementById('nlMenuBadge');
        if (badge) { badge.textContent = p.to_go; badge.classList.toggle('d-none', p.to_go === 0); }
    }

    table.addEventListener('submit', function (e) {
        var form = e.target.closest('form.nl-form');
        if (!form || !window.fetch) return;   // no fetch: the normal POST + redirect still works
        e.preventDefault();
        var tr = form.closest('tr.nl-row');
        var fd = new FormData(form);
        if (fd.get('op') === 'link' && !fd.get('member_id')) { toast(false, 'Choose a member first.'); return; }
        fd.append('ajax', '1');
        tr.classList.add('nl-busy');
        fetch('index.php?action=needsLinkSave', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                toast(d.ok, d.msg);
                if (d.progress) renderProgress(d.progress);
                if (d.html) {
                    var tmp = document.createElement('tbody');
                    tmp.innerHTML = d.html;
                    var fresh = tmp.firstElementChild;
                    if (tr.classList.contains('d-none')) fresh.classList.add('d-none');
                    tr.replaceWith(fresh);
                    initSearch(fresh);
                } else {
                    tr.classList.remove('nl-busy');
                }
            })
            .catch(function () {
                tr.classList.remove('nl-busy');
                toast(false, 'That could not be saved. Check your connection and try again.');
            });
    });

    // Returning from a no-JS post: bring the row back into view.
    if (window.location.hash.indexOf('#nl-row-') === 0) {
        var target = document.querySelector(window.location.hash);
        if (target) target.scrollIntoView({ block: 'center' });
    }
})();
</script>
