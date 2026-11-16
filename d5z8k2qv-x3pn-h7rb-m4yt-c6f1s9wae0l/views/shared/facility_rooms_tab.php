<?php
/**
 * Rooms tab — add, edit, hide, reorder and delete the rooms people can book.
 * Shown only to accounts with the "Manage rooms" permission (canManageFacilityRooms).
 *
 * Hiding a room removes it from the request form but keeps it on every request
 * that already used it. Deleting is refused while upcoming requests use the
 * room; a room with history is archived rather than erased, so old requests
 * never lose their room name.
 *
 * Expects $roomRows from FacilityController::showCalendar().
 */
$roomRows  = $roomRows ?? [];
$roomTypes = Facility::TYPES;
?>
<div id="facilityRoomsTab">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-door-open me-2" aria-hidden="true"></i>Rooms &amp; resources</span>
            <button type="button" class="btn btn-sm btn-primary" onclick="openRoomForm(null)">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Add room
            </button>
        </div>
        <div class="card-body border-bottom py-2 small text-muted">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Drag <i class="bi bi-grip-vertical" aria-hidden="true"></i> to reorder the list shown in the request form.
            <strong>Special case only</strong> rooms (e.g. the Office Lobby) can still be requested, but the requestor must say why.
            <strong>Hide</strong> a room to stop new requests without losing its history.
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="roomsTable">
                <thead>
                    <tr>
                        <th style="width:32px"></th>
                        <th>Room</th>
                        <th>Type</th>
                        <th class="text-center">Capacity</th>
                        <th class="text-center">Lead time</th>
                        <th>Flags</th>
                        <th class="text-center">Requests</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roomRows as $rm):
                    $active = (int)$rm['is_active'] === 1;
                    $jsRoom = [
                        'id' => (int)$rm['id'], 'name' => $rm['name'], 'facility_type' => $rm['facility_type'],
                        'capacity' => $rm['capacity'], 'default_lead_days' => (int)$rm['default_lead_days'],
                        'is_bookable' => (int)$rm['is_bookable'], 'special_case_only' => (int)($rm['special_case_only'] ?? 0),
                        'notes' => $rm['notes'] ?? '',
                    ];
                ?>
                    <tr data-id="<?php echo (int)$rm['id']; ?>" class="<?php echo $active ? '' : 'table-light text-muted'; ?>">
                        <td class="text-muted room-grip" style="cursor:grab" title="Drag to reorder"><i class="bi bi-grip-vertical" aria-hidden="true"></i></td>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($rm['name']); ?></div>
                            <?php if (!empty($rm['notes'])): ?><div class="small text-muted"><?php echo htmlspecialchars($rm['notes']); ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?php echo htmlspecialchars($roomTypes[$rm['facility_type']] ?? $rm['facility_type']); ?></td>
                        <td class="text-center"><?php echo $rm['capacity'] !== null ? (int)$rm['capacity'] : '—'; ?></td>
                        <td class="text-center"><?php echo (int)$rm['default_lead_days']; ?> d</td>
                        <td>
                            <?php if (!empty($rm['special_case_only'])): ?>
                                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Special case only</span>
                            <?php endif; ?>
                            <?php if (!$active): ?><span class="badge bg-secondary">Hidden</span><?php endif; ?>
                            <?php if (!(int)$rm['is_bookable']): ?><span class="badge bg-light text-dark border" title="Not checked for clashes">No calendar</span><?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo (int)$rm['request_count']; ?></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" title="Edit"
                                    onclick='openRoomForm(<?php echo htmlspecialchars(json_encode($jsRoom), ENT_QUOTES); ?>)'>
                                <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edit <?php echo htmlspecialchars($rm['name']); ?></span>
                            </button>
                            <a class="btn btn-sm <?php echo $active ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                               href="index.php?action=<?php echo $active ? 'hideFacilityRoom' : 'showFacilityRoom'; ?>&id=<?php echo (int)$rm['id']; ?>"
                               title="<?php echo $active ? 'Hide from the request form' : 'Show in the request form'; ?>">
                                <i class="bi <?php echo $active ? 'bi-eye-slash' : 'bi-eye'; ?>" aria-hidden="true"></i>
                                <span class="visually-hidden"><?php echo $active ? 'Hide' : 'Show'; ?> <?php echo htmlspecialchars($rm['name']); ?></span>
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-danger" title="Delete"
                                    onclick='openRoomDelete(<?php echo (int)$rm['id']; ?>, <?php echo htmlspecialchars(json_encode($rm['name']), ENT_QUOTES); ?>)'>
                                <i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Delete <?php echo htmlspecialchars($rm['name']); ?></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$roomRows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No rooms yet. Add the first one.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / edit room -->
<div class="modal fade" id="roomModal" tabindex="-1" aria-labelledby="roomModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roomModalTitle">Add room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="roomForm" action="index.php?action=addFacilityRoom">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="room_name">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="room_name" class="form-control" required maxlength="150" placeholder="e.g. Office Lobby">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="room_type">Type</label>
                            <select name="facility_type" id="room_type" class="form-select">
                                <?php foreach ($roomTypes as $tv => $tl): ?>
                                <option value="<?php echo htmlspecialchars($tv); ?>"><?php echo htmlspecialchars($tl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold" for="room_capacity">Capacity</label>
                            <input type="number" name="capacity" id="room_capacity" class="form-control" min="0" placeholder="—">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label fw-semibold" for="room_lead">Lead days</label>
                            <input type="number" name="default_lead_days" id="room_lead" class="form-control" min="0" max="365" value="3">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="room_notes">Notes</label>
                        <input type="text" name="notes" id="room_notes" class="form-control" maxlength="255" placeholder="Shown to Facilities; e.g. shared space">
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="special_case_only" value="1" id="room_special">
                        <label class="form-check-label" for="room_special">
                            <strong>Special case only</strong>
                            <span class="d-block form-text mt-0">The request form asks the requestor to explain why this room is needed.</span>
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_bookable" value="1" id="room_bookable" checked>
                        <label class="form-check-label" for="room_bookable">
                            <strong>Checked for clashes</strong>
                            <span class="d-block form-text mt-0">Confirmed bookings block this room on the calendar. Untick for items that can be shared.</span>
                        </label>
                    </div>
                    <p class="form-text mt-3 mb-0">Lead days is the recommended notice. Short notice is a warning, never a block.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i><span id="roomSubmitLabel">Add room</span></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete room -->
<div class="modal fade" id="roomDeleteModal" tabindex="-1" aria-labelledby="roomDeleteTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roomDeleteTitle"><i class="bi bi-trash me-2" aria-hidden="true"></i>Delete room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="roomDeleteForm" action="">
                <div class="modal-body">
                    <p>Delete <strong id="roomDeleteName"></strong>?</p>
                    <p class="small text-muted mb-0">
                        Refused while upcoming requests use it. If past requests used it, the room is archived so
                        they keep its name. To just stop new requests, use <strong>Hide</strong> instead.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openRoomForm(room) {
    var f = document.getElementById('roomForm');
    document.getElementById('roomModalTitle').textContent = room ? 'Edit room' : 'Add room';
    document.getElementById('roomSubmitLabel').textContent = room ? 'Save changes' : 'Add room';
    f.action = room ? 'index.php?action=updateFacilityRoom&id=' + room.id : 'index.php?action=addFacilityRoom';
    document.getElementById('room_name').value     = room ? room.name : '';
    document.getElementById('room_type').value     = room ? room.facility_type : 'room';
    document.getElementById('room_capacity').value = room && room.capacity !== null ? room.capacity : '';
    document.getElementById('room_lead').value     = room ? room.default_lead_days : 3;
    document.getElementById('room_notes').value    = room ? (room.notes || '') : '';
    document.getElementById('room_special').checked  = room ? !!room.special_case_only : false;
    document.getElementById('room_bookable').checked = room ? !!room.is_bookable : true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('roomModal')).show();
}
function openRoomDelete(id, name) {
    document.getElementById('roomDeleteName').textContent = name;
    document.getElementById('roomDeleteForm').action = 'index.php?action=deleteFacilityRoom&id=' + id;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('roomDeleteModal')).show();
}
// Drag to reorder (native HTML5 drag on the grip, saved as one POST).
(function () {
    var body = document.querySelector('#roomsTable tbody'), dragging = null;
    if (!body) return;
    body.querySelectorAll('tr[data-id]').forEach(function (tr) {
        var grip = tr.querySelector('.room-grip');
        if (!grip) return;
        grip.addEventListener('mousedown', function () { tr.draggable = true; });
        tr.addEventListener('dragstart', function (e) { dragging = tr; e.dataTransfer.effectAllowed = 'move'; tr.classList.add('table-active'); });
        tr.addEventListener('dragend', function () {
            tr.draggable = false; tr.classList.remove('table-active'); dragging = null;
            var fd = new FormData();
            body.querySelectorAll('tr[data-id]').forEach(function (r) { fd.append('ids[]', r.dataset.id); });
            fetch('index.php?action=reorderFacilityRooms', { method: 'POST', body: fd });
        });
        tr.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!dragging || dragging === tr) return;
            var r = tr.getBoundingClientRect();
            body.insertBefore(dragging, (e.clientY - r.top) > r.height / 2 ? tr.nextSibling : tr);
        });
    });
})();
</script>
