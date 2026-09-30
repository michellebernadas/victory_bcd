<?php
// Spiritual Foundations — Curriculum tab (view + admin CRUD).
//
// Topics live in sf_topics, separate from attendance in program_attendances, and
// each saved grid records its own week_no → status map. Editing a topic name or
// deactivating a topic therefore never rewrites a historical record.
//
// Requires in scope: $sfTopics (incl. inactive), $sfWeekCount, $sfUsage,
//                    $sfHasAttendance, $sfIsAdmin
$sfUsage         = $sfUsage         ?? [];
$sfHasAttendance = $sfHasAttendance ?? false;
$sfIsAdmin       = $sfIsAdmin       ?? false;
$sfClassRows     = array_values(array_filter($sfTopics, fn($r) => (int)$r['is_class'] === 1));
$sfActiveClass   = array_filter($sfClassRows, fn($r) => (int)$r['is_active'] === 1 && (int)$r['is_deleted'] === 0);
$sfRequiredCount = count(array_filter($sfActiveClass, fn($r) => (int)$r['is_required'] === 1));
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="text-white fw-semibold">
            <i class="bi bi-journal-text me-2"></i>Spiritual Foundations Curriculum
            <span class="badge bg-white text-dark border ms-1"><?php echo count($sfActiveClass); ?> class weeks</span>
            <span class="badge bg-light text-dark border ms-1"><?php echo $sfRequiredCount; ?> required</span>
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="col-toggle" data-table="sfCurriculumTable" data-locked="0"></span>
            <?php if ($sfIsAdmin): ?>
            <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addSfTopicModal">
                <i class="bi bi-plus-lg me-1"></i>Add Topic
            </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" id="sfCurriculumTable">
                <thead class="table-light">
                    <tr>
                        <?php if ($sfIsAdmin): ?><th style="width:30px"></th><?php endif; ?>
                        <th style="width:90px">Week</th>
                        <th style="width:260px">Topic</th>
                        <th>Subtopics</th>
                        <th class="text-center" style="width:110px">Required</th>
                        <th class="text-center" style="width:100px">Status</th>
                        <th class="text-center" style="width:90px" title="Attendance records that reference this week">Used By</th>
                        <?php if ($sfIsAdmin): ?><th class="text-center" style="width:150px">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="<?php echo ($sfIsAdmin && !$sfHasAttendance) ? 'sf-curriculum-sortable' : ''; ?>"
                       data-reorder-url="index.php?action=reorderSfTopics">
                    <?php foreach ($sfTopics as $row):
                        $isClass  = (int)$row['is_class'] === 1;
                        $isActive = (int)$row['is_active'] === 1 && (int)$row['is_deleted'] === 0;
                        $subs     = SfTopic::subtopicList($row);
                        $usage    = (int)($sfUsage[(int)$row['id']] ?? 0);
                        $editJson = [
                            'id'          => (int)$row['id'],
                            'week_no'     => $row['week_no'],
                            'topic'       => $row['topic'],
                            'subtopics'   => implode("\n", $subs),
                            'is_required' => (int)$row['is_required'],
                            'is_active'   => $isActive ? 1 : 0,
                            'is_class'    => $isClass ? 1 : 0,
                            'usage'       => $usage,
                        ];
                    ?>
                    <tr data-id="<?php echo (int)$row['id']; ?>"
                        class="<?php echo $isClass ? '' : 'table-light'; ?><?php echo !$isActive ? ' opacity-75' : ''; ?>">
                        <?php if ($sfIsAdmin): ?>
                        <td class="text-center text-muted"
                            style="<?php echo (!$sfHasAttendance && $isClass) ? 'cursor:grab' : ''; ?>"
                            title="<?php echo $sfHasAttendance
                                ? 'Reordering is locked while attendance exists — week numbers are how history is stored.'
                                : 'Drag to reorder'; ?>">
                            <?php if ($isClass): ?>
                            <i class="bi bi-grip-vertical <?php echo $sfHasAttendance ? 'opacity-25' : ''; ?>"></i>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-center">
                            <?php if ($isClass && $row['week_no'] !== null): ?>
                            <span class="badge bg-info">Week <?php echo (int)$row['week_no']; ?></span>
                            <?php else: ?>
                            <span class="badge bg-secondary" title="Class/session-level break — not a numbered topic">Break</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold<?php echo $isClass ? '' : ' text-muted fst-italic'; ?>">
                            <?php echo htmlspecialchars($row['topic']); ?>
                            <?php if (!$isActive): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:9px;">
                                <?php echo (int)$row['is_deleted'] === 1 ? 'Archived' : 'Inactive'; ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($subs): ?>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($subs as $s): ?>
                                <li><?php echo htmlspecialchars($s); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if (!$isClass): ?>
                            <span class="badge bg-light text-muted border"
                                  title="No class was held, so this row is excluded from attendance, completion and the certificate.">No Class</span>
                            <?php elseif ((int)$row['is_required']): ?>
                            <span class="badge bg-success">Required</span>
                            <?php else: ?>
                            <span class="badge bg-light text-dark border">Optional</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $isActive ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php if (!$isClass): ?>
                            <span class="text-muted">—</span>
                            <?php elseif ($usage > 0): ?>
                            <span class="badge bg-primary" title="<?php echo $usage; ?> attendance record(s) reference this week — it can only be archived, never deleted."><?php echo $usage; ?></span>
                            <?php else: ?>
                            <span class="badge bg-light text-muted border" title="Never used — safe to delete outright">0</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($sfIsAdmin): ?>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary me-1" title="Edit"
                                onclick="openEditSfTopic(<?php echo htmlspecialchars(json_encode($editJson)); ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ((int)$row['is_deleted'] !== 1): ?>
                                <?php if ($isActive): ?>
                                <a class="btn btn-sm btn-outline-warning me-1" title="Deactivate (keeps history)"
                                   href="index.php?action=deactivateSfTopic&id=<?php echo (int)$row['id']; ?>"
                                   onclick="return confirm('Deactivate this topic? It stays on historical records but is dropped from new attendance forms and from the required-topic count.')">
                                    <i class="bi bi-pause-circle"></i>
                                </a>
                                <?php else: ?>
                                <a class="btn btn-sm btn-outline-success me-1" title="Activate"
                                   href="index.php?action=activateSfTopic&id=<?php echo (int)$row['id']; ?>">
                                    <i class="bi bi-play-circle"></i>
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($usage > 0): ?>
                            <a class="btn btn-sm btn-outline-secondary" title="Archive — this topic has attendance history and cannot be deleted"
                               href="index.php?action=deleteSfTopic&id=<?php echo (int)$row['id']; ?>"
                               onclick="return confirm('This topic is referenced by <?php echo $usage; ?> attendance record(s), so it will be ARCHIVED, not deleted. History is preserved. Continue?')">
                                <i class="bi bi-archive"></i>
                            </a>
                            <?php else: ?>
                            <a class="btn btn-sm btn-outline-danger" title="Delete (never used)"
                               href="index.php?action=deleteSfTopic&id=<?php echo (int)$row['id']; ?>"
                               onclick="return confirm('Delete this topic? It has no attendance history, so it will be removed permanently.')">
                                <i class="bi bi-trash"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent small text-muted">
        <i class="bi bi-shield-check me-1"></i>
        Curriculum rows live in <code>sf_topics</code>, and every saved attendance grid stores its own
        week&nbsp;→&nbsp;status map — so renaming, deactivating or archiving a topic never alters a
        historical record. A topic with attendance can only be <strong>archived</strong>; only a
        never-used topic can be deleted.
        <?php if ($sfHasAttendance): ?>
        <br><i class="bi bi-lock me-1"></i>Week renumbering and drag-reordering are locked because
        attendance already exists.
        <?php endif; ?>
        <?php if (!$sfIsAdmin): ?>
        <br><i class="bi bi-info-circle me-1"></i>Only administrators can change the curriculum.
        <?php endif; ?>
    </div>
</div>

<?php if ($sfIsAdmin):
    // Add + Edit share one field partial.
    $sfTopicModalId = 'addSfTopicModal';  $sfTopicFormId = 'addSfTopicForm';
    $sfTopicAction  = 'index.php?action=addSfTopic';
    $sfTopicTitle   = 'Add Curriculum Topic';
    include __DIR__ . '/sf_topic_modal.php';

    $sfTopicModalId = 'editSfTopicModal'; $sfTopicFormId = 'editSfTopicForm';
    $sfTopicAction  = '';
    $sfTopicTitle   = 'Edit Curriculum Topic';
    include __DIR__ . '/sf_topic_modal.php';
?>
<script>
// Drag-reorder — only wired when no attendance exists (server enforces this too).
document.addEventListener('DOMContentLoaded', function () {
    var body = document.querySelector('.sf-curriculum-sortable');
    if (!body || typeof Sortable === 'undefined') return;
    Sortable.create(body, {
        handle: '.bi-grip-vertical',
        animation: 150,
        onEnd: function () {
            var ids = Array.from(body.querySelectorAll('tr[data-id]'))
                .filter(function (tr) { return tr.querySelector('.badge.bg-info'); })   // class weeks only
                .map(function (tr) { return tr.dataset.id; });
            var params = new URLSearchParams();
            ids.forEach(function (id) { params.append('ids[]', id); });
            fetch(body.dataset.reorderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(function (r) { return r.json(); })
              .then(function (d) {
                  if (d && d.ok) { window.location.reload(); }
                  else { alert((d && d.error) || 'Could not reorder the curriculum.'); window.location.reload(); }
              })
              .catch(function () { window.location.reload(); });
        }
    });
});

function openEditSfTopic(t) {
    var form = document.getElementById('editSfTopicForm');
    if (!form) return;
    form.action = 'index.php?action=updateSfTopic&id=' + t.id;
    form.querySelector('[name="topic"]').value     = t.topic || '';
    form.querySelector('[name="subtopics"]').value = t.subtopics || '';
    var wk = form.querySelector('[name="week_no"]');
    if (wk) {
        wk.value    = t.week_no || '';
        // A break row has no week number; and renumbering is locked once history exists.
        wk.disabled = !t.is_class || <?php echo $sfHasAttendance ? 'true' : 'false'; ?>;
    }
    var note = form.querySelector('.sf-week-lock-note');
    if (note) note.style.display = wk && wk.disabled ? '' : 'none';
    form.querySelector('[name="is_required"]').checked = !!t.is_required;
    form.querySelector('[name="is_active"]').checked   = !!t.is_active;
    var reqWrap = form.querySelector('.sf-required-wrap');
    if (reqWrap) reqWrap.style.display = t.is_class ? '' : 'none';
    var usage = form.querySelector('.sf-usage-note');
    if (usage) {
        usage.style.display = t.usage > 0 ? '' : 'none';
        usage.querySelector('strong').textContent = t.usage;
    }
    var el = document.getElementById('editSfTopicModal');
    (bootstrap.Modal.getInstance(el) || new bootstrap.Modal(el)).show();
}
</script>
<?php endif; ?>
