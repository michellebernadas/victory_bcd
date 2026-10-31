<?php
/**
 * Calendar tab — the official facility calendar (policy section 20).
 *
 * The grid itself is drawn by js/facility-calendar.js; this file supplies the
 * chrome (view switch, facility filter, legend) and the styles. Rendering is
 * entirely client-side from a JSON payload, so switching Month/Week or toggling
 * a facility filter never round-trips the server.
 */
$bookable     = $bookable     ?? [];
$statusMeta   = $statusMeta   ?? FacilityRequest::STATUSES;
$statusColors = $statusColors ?? FacilityRequest::STATUS_COLORS;
$activeTab    = $activeTab    ?? 'calendar';
?>
<div class="<?php echo $activeTab === 'calendar' ? '' : 'd-none'; ?>" id="facilityCalendarTab">
<div class="row g-3">

    <!-- Facility filter -->
    <div class="col-12 col-lg-3">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <span class="text-white fw-semibold"><i class="bi bi-funnel me-2"></i>Facilities</span>
                <button type="button" id="fcClearFilters" class="btn btn-sm btn-outline-light">Clear</button>
            </div>
            <div class="card-body py-2">
                <?php if (empty($bookable)): ?>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle me-1"></i>No bookable facilities are set up yet.
                    </p>
                <?php else: ?>
                    <p class="text-muted small mb-2">Show only these rooms. No selection shows everything.</p>
                    <?php foreach ($bookable as $f): ?>
                    <div class="form-check">
                        <input class="form-check-input fc-facility-filter" type="checkbox"
                               value="<?php echo (int)$f['id']; ?>" id="fcFac<?php echo (int)$f['id']; ?>">
                        <label class="form-check-label small" for="fcFac<?php echo (int)$f['id']; ?>">
                            <?php echo htmlspecialchars($f['name']); ?>
                            <?php if (!empty($f['default_lead_days'])): ?>
                            <span class="text-muted" style="font-size:.7rem">
                                (<?php echo (int)$f['default_lead_days']; ?>d lead)
                            </span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <hr class="my-2">
                <div class="fw-semibold small mb-1">Legend</div>
                <?php foreach (FacilityRequest::CALENDAR_STATUSES as $s):
                    $m = FacilityRequest::statusMeta($s);
                    $c = $statusColors[$s] ?? '#6c757d';
                    $official = ($s === FacilityRequest::BLOCKING_STATUS);
                ?>
                <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.78rem">
                    <span style="display:inline-block;width:14px;height:14px;border-radius:3px;
                                 background:<?php echo $official ? $c : $c . '1f'; ?>;
                                 border-left:4px <?php echo $official ? 'solid' : 'dashed'; ?> <?php echo $c; ?>;"></span>
                    <span class="<?php echo $official ? 'fw-bold' : ''; ?>">
                        <?php echo htmlspecialchars($m['label']); ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <p class="text-muted mb-0 mt-2" style="font-size:.7rem">
                    Solid = official reservation. Dashed = in the workflow, reserves nothing.
                </p>
            </div>
        </div>
    </div>

    <!-- Calendar -->
    <div class="col-12 col-lg-9">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center py-2 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <button type="button" id="fcPrev" class="btn btn-sm btn-outline-light" aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                    <button type="button" id="fcToday" class="btn btn-sm btn-outline-light">Today</button>
                    <button type="button" id="fcNext" class="btn btn-sm btn-outline-light" aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <span class="text-white fw-semibold ms-2" id="fcTitle"></span>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Calendar view">
                    <button type="button" class="btn btn-outline-light fc-view-btn active" data-view="month">
                        <i class="bi bi-calendar-month me-1"></i>Month
                    </button>
                    <button type="button" class="btn btn-outline-light fc-view-btn" data-view="week">
                        <i class="bi bi-calendar-week me-1"></i>Week
                    </button>
                </div>
            </div>
            <div class="card-body p-2">
                <div id="fcEmpty" class="alert alert-light border text-muted small d-none mb-2"></div>
                <div id="facilityCalendar">
                    <div id="fcBody"></div>
                </div>
                <p class="text-muted small mb-0 mt-2">
                    <i class="bi bi-hand-index me-1"></i>Click an entry to view it. Click an empty date
                    (or an hour in Week view) to start a request with the date and time filled in.
                </p>
            </div>
        </div>
    </div>
</div>
</div>

<style>
/* ── Facility calendar ───────────────────────────────────────────────────── */
.fc-grid.fc-month {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 1px;
    background: var(--bs-border-color, #dee2e6);
    border: 1px solid var(--bs-border-color, #dee2e6);
    border-radius: .375rem;
    overflow: hidden;
}
.fc-dow {
    background: #f8f9fa; text-align: center; font-size: .72rem; font-weight: 700;
    letter-spacing: .5px; color: #6c757d; padding: .35rem 0; text-transform: uppercase;
}
.fc-day { background: #fff; min-height: 96px; padding: 2px 3px 4px; position: relative; cursor: pointer; }
.fc-day:hover { background: #fcfaff; }
.fc-day.fc-out { background: #f8f9fa; }
.fc-day.fc-out .fc-day-num { color: #adb5bd; }
.fc-day.fc-today { box-shadow: inset 0 0 0 2px var(--vb-purple, #51154b); }
.fc-day-head { display: flex; justify-content: space-between; align-items: center; }
.fc-day-num { font-size: .78rem; font-weight: 600; padding: 0 2px; }
.fc-day.fc-today .fc-day-num {
    background: var(--vb-purple, #51154b); color: #fff; border-radius: 50%;
    width: 20px; height: 20px; display: inline-flex; align-items: center; justify-content: center;
    font-size: .7rem;
}
.fc-add {
    border: 0; background: transparent; color: #adb5bd; font-size: .7rem;
    line-height: 1; padding: 2px 3px; border-radius: 3px; opacity: 0;
}
.fc-day:hover .fc-add { opacity: 1; }
.fc-add:hover { background: var(--vb-purple, #51154b); color: #fff; }
.fc-day-body { display: flex; flex-direction: column; gap: 2px; margin-top: 2px; }

.fc-chip {
    display: block; width: 100%; text-align: left; border: 0; border-radius: 3px;
    font-size: .7rem; line-height: 1.25; padding: 2px 4px; cursor: pointer;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.fc-chip-official { font-weight: 600; }
.fc-chip-time { font-weight: 600; opacity: .85; }
.fc-chip:hover { filter: brightness(.93); }
.fc-more {
    border: 0; background: transparent; color: #6c757d; font-size: .68rem;
    text-align: left; padding: 0 4px; text-decoration: underline;
}

/* Week view */
.fc-week-wrap { border: 1px solid var(--bs-border-color, #dee2e6); border-radius: .375rem; overflow: hidden; }
.fc-week-head { display: grid; grid-template-columns: 56px repeat(7, minmax(0, 1fr)); background: #f8f9fa; }
.fc-week-dayhead { text-align: center; padding: .3rem 0; border-left: 1px solid var(--bs-border-color, #dee2e6); }
.fc-week-dayhead.fc-today { background: var(--vb-purple-soft, #f3e8f2); }
.fc-week-body {
    display: grid; grid-template-columns: 56px repeat(7, minmax(0, 1fr));
    overflow-y: auto; max-height: 560px; background: #fff;
}
.fc-week-body .fc-gutter { border-right: 1px solid var(--bs-border-color, #dee2e6); }
.fc-hour-label {
    font-size: .65rem; color: #6c757d; text-align: right; padding-right: 4px;
    border-bottom: 1px solid #f1f3f5; box-sizing: border-box;
}
.fc-week-col { position: relative; border-left: 1px solid var(--bs-border-color, #dee2e6); }
.fc-week-col.fc-today { background: #fdfbff; }
.fc-slot { border-bottom: 1px solid #f1f3f5; box-sizing: border-box; cursor: pointer; }
.fc-slot:hover { background: var(--vb-purple-soft, #f3e8f2); }
.fc-week-ev { position: absolute; left: 2px; right: 2px; overflow: hidden; }
.fc-week-ev .fc-chip { height: 100%; white-space: normal; }
.fc-week-untimed { position: absolute; left: 2px; right: 2px; z-index: 2; }

/* Confirmed must never read like "approved": heavier weight + a ring. */
.badge.fb-official { box-shadow: 0 0 0 2px rgba(25, 135, 84, .25); letter-spacing: .4px; }

@media (max-width: 767.98px) {
    .fc-day { min-height: 70px; }
    .fc-chip { font-size: .62rem; }
    .fc-week-body, .fc-week-head { grid-template-columns: 42px repeat(7, minmax(0, 1fr)); }
}
</style>
