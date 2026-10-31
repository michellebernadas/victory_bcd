/**
 * Victory Bacolod — Facility Booking calendar (Outlook-style Month / Week).
 *
 * WHY THIS IS HAND-BUILT RATHER THAN A CALENDAR LIBRARY
 * The portal loads every third-party asset from a CDN in views/shared/footer.php
 * and that file is shared by every page. A calendar library (FullCalendar et al)
 * would add ~250KB to every screen in the portal for one page, and its event
 * model would still need wrapping to express the one rule that matters here —
 * that only a CONFIRMED request is an official reservation. Two grids over a
 * plain array is less code than that adapter would be, has no version to track,
 * and renders identically whether the dataset has 200 entries or zero.
 *
 * THE COLOUR RULE (policy 3.6 / 20)
 * Confirmed entries are solid, in the confirmed colour, with a filled calendar
 * icon. Everything earlier is drawn translucent with a dashed left border and a
 * hollow icon, so a Facilities person scanning the month can tell at a glance
 * what is actually reserved and what is merely in flight. Colours arrive from
 * PHP (FacilityRequest::STATUS_COLORS) so the calendar, the badges and the
 * legend can never drift apart.
 *
 * EMPTY DATASET
 * Every render path tolerates zero events: the grid is drawn from the date
 * arithmetic alone and events are merely laid into it. With no bookings the
 * month still paints and an empty-state hint appears.
 */
(function () {
    'use strict';

    var root = document.getElementById('facilityCalendar');
    if (!root) return;

    // ── Data handed over by the view ────────────────────────────────────────
    var DATA = window.FACILITY_CALENDAR_DATA || {};
    var EVENTS = Array.isArray(DATA.events) ? DATA.events : [];
    var COLORS = DATA.colors || {};
    var LABELS = DATA.labels || {};
    var CONFIRMED = DATA.confirmedStatus || 'confirmed';

    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                  'July', 'August', 'September', 'October', 'November', 'December'];

    // Week view hours. Outside this band events are still shown, clamped to the
    // edge, so a 06:00 setup call is never silently dropped off the grid.
    var DAY_START = 6, DAY_END = 23;

    var state = {
        view: (DATA.initialView === 'week') ? 'week' : 'month',
        focus: parseDate(DATA.focusDate) || new Date(),
        facilities: []   // selected facility ids as strings; empty = all
    };

    // ── Date helpers (local time only — no UTC, no timezone surprises) ──────

    function parseDate(s) {
        if (!s) return null;
        var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return null;
        return new Date(+m[1], +m[2] - 1, +m[3]);
    }
    function ymd(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function addDays(d, n) { var c = new Date(d.getTime()); c.setDate(c.getDate() + n); return c; }
    function startOfWeek(d) { return addDays(d, -d.getDay()); }
    function sameDay(a, b) { return ymd(a) === ymd(b); }
    function isToday(d) { return sameDay(d, new Date()); }

    /** "14:30:00" -> minutes from midnight. Returns null on anything unparseable. */
    function minutesOf(t) {
        if (!t) return null;
        var m = String(t).match(/^(\d{1,2}):(\d{2})/);
        return m ? (+m[1] * 60 + +m[2]) : null;
    }
    function fmtTime(t) {
        var mins = minutesOf(t);
        if (mins === null) return '';
        var h = Math.floor(mins / 60), mm = mins % 60;
        var ap = h >= 12 ? 'PM' : 'AM';
        var h12 = h % 12; if (h12 === 0) h12 = 12;
        return h12 + (mm ? ':' + pad(mm) : '') + ' ' + ap;
    }
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ── Filtering ───────────────────────────────────────────────────────────

    function visibleEvents() {
        if (!state.facilities.length) return EVENTS;
        return EVENTS.filter(function (e) {
            // A request with no facility (equipment-only) is not any room, so a
            // room filter legitimately hides it.
            return e.facility_id && state.facilities.indexOf(String(e.facility_id)) !== -1;
        });
    }

    // How many events the last render actually laid into the grid. Drives the
    // empty state, which has to distinguish "nothing anywhere" from "nothing in
    // this month" — a filtered month with the bookings one month over would
    // otherwise be a blank grid with no explanation.
    var placedCount = 0;

    /** date string -> array of events, built once per render. */
    function indexByDate(list) {
        var map = {};
        list.forEach(function (e) {
            if (!e.date) return;
            (map[e.date] = map[e.date] || []).push(e);
        });
        Object.keys(map).forEach(function (k) {
            map[k].sort(function (a, b) {
                return (minutesOf(a.start) || 0) - (minutesOf(b.start) || 0);
            });
        });
        return map;
    }

    // ── Event chip ──────────────────────────────────────────────────────────

    function chip(e, withTime) {
        var color = COLORS[e.status] || '#6c757d';
        var official = (e.status === CONFIRMED);
        var style = official
            ? 'background:' + color + ';color:#fff;border-left:4px solid ' + color + ';'
            : 'background:' + color + '1f;color:#212529;border-left:4px dashed ' + color + ';opacity:.85;';
        var icon = official ? 'bi-calendar2-check-fill' : 'bi-clock-history';
        // Hover / screen-reader text: status, what and when, and — for anything
        // not yet reserved — whose turn it is.
        var title = (LABELS[e.status] || e.status) + ': ' + (e.event || '(untitled)')
                  + (e.facility ? ' @ ' + e.facility : '')
                  + (e.when ? ' · ' + e.when : (e.start ? ' (' + fmtTime(e.start) + (e.end ? '–' + fmtTime(e.end) : '') + ')' : ''))
                  + (official ? ' · Reserved (official)'
                              : ' · Not reserved yet' + (e.waiting_for ? ' · Waiting for ' + e.waiting_for : ''));

        return '<button type="button" class="fc-chip' + (official ? ' fc-chip-official' : '') + '"'
             + ' style="' + style + '" data-id="' + e.id + '" title="' + esc(title) + '" aria-label="' + esc(title) + '">'
             + '<i class="bi ' + icon + ' me-1"></i>'
             + (withTime && e.start ? '<span class="fc-chip-time">' + esc(fmtTime(e.start)) + '</span> ' : '')
             + '<span class="fc-chip-text">' + esc(e.event || '(untitled)') + '</span>'
             + '</button>';
    }

    // ── Month view ──────────────────────────────────────────────────────────

    function renderMonth() {
        var focus = state.focus;
        var first = new Date(focus.getFullYear(), focus.getMonth(), 1);
        var gridStart = startOfWeek(first);
        var byDate = indexByDate(visibleEvents());
        placedCount = 0;

        var html = '<div class="fc-grid fc-month">';
        DOW.forEach(function (d) { html += '<div class="fc-dow">' + d + '</div>'; });

        // Always six rows: the month grid never changes height as you page
        // through, which is what makes it scan like Outlook.
        for (var i = 0; i < 42; i++) {
            var day = addDays(gridStart, i);
            var key = ymd(day);
            var out = day.getMonth() !== focus.getMonth();
            var evs = byDate[key] || [];
            placedCount += evs.length;

            html += '<div class="fc-day' + (out ? ' fc-out' : '') + (isToday(day) ? ' fc-today' : '') + '"'
                  + ' data-date="' + key + '">'
                  + '<div class="fc-day-head">'
                  +   '<span class="fc-day-num">' + day.getDate() + '</span>'
                  +   '<button type="button" class="fc-add" data-date="' + key + '"'
                  +     ' title="New request on ' + key + '"><i class="bi bi-plus-lg"></i></button>'
                  + '</div>'
                  + '<div class="fc-day-body">';

            evs.slice(0, 3).forEach(function (e) { html += chip(e, true); });
            if (evs.length > 3) {
                html += '<button type="button" class="fc-more" data-date="' + key + '">+'
                      + (evs.length - 3) + ' more</button>';
            }
            html += '</div></div>';
        }
        html += '</div>';
        return html;
    }

    // ── Week view ───────────────────────────────────────────────────────────

    function renderWeek() {
        var weekStart = startOfWeek(state.focus);
        var byDate = indexByDate(visibleEvents());
        placedCount = 0;
        var hours = DAY_END - DAY_START;
        var pxPerHour = 48;

        var html = '<div class="fc-week-wrap"><div class="fc-week-head">'
                 + '<div class="fc-gutter"></div>';
        for (var d = 0; d < 7; d++) {
            var day = addDays(weekStart, d);
            html += '<div class="fc-week-dayhead' + (isToday(day) ? ' fc-today' : '') + '">'
                  + '<div class="small text-muted">' + DOW[day.getDay()] + '</div>'
                  + '<div class="fw-bold">' + day.getDate() + '</div>'
                  + '</div>';
        }
        html += '</div><div class="fc-week-body" style="height:' + (hours * pxPerHour) + 'px">'
              + '<div class="fc-gutter">';
        for (var h = DAY_START; h < DAY_END; h++) {
            html += '<div class="fc-hour-label" style="height:' + pxPerHour + 'px">' + fmtTime(pad(h) + ':00') + '</div>';
        }
        html += '</div>';

        for (var dd = 0; dd < 7; dd++) {
            var dday = addDays(weekStart, dd);
            var dkey = ymd(dday);
            html += '<div class="fc-week-col' + (isToday(dday) ? ' fc-today' : '') + '" data-date="' + dkey + '">';
            for (var hh = DAY_START; hh < DAY_END; hh++) {
                html += '<div class="fc-slot" style="height:' + pxPerHour + 'px"'
                      + ' data-date="' + dkey + '" data-hour="' + pad(hh) + ':00"></div>';
            }

            // Timed events are positioned absolutely over the slot stack; the
            // all-day / untimed ones stack at the top so they are never lost.
            var evs = byDate[dkey] || [];
            placedCount += evs.length;
            var untimed = 0;
            evs.forEach(function (e) {
                var s = minutesOf(e.start), en = minutesOf(e.end);
                if (s === null) {
                    html += '<div class="fc-week-untimed" style="top:' + (untimed * 24) + 'px">'
                          + chip(e, false) + '</div>';
                    untimed++;
                    return;
                }
                if (en === null || en <= s) en = s + 60;
                // Clamp into the visible band rather than dropping the event.
                var top = Math.max(0, (s - DAY_START * 60) / 60 * pxPerHour);
                var bottom = Math.min(hours * pxPerHour, (en - DAY_START * 60) / 60 * pxPerHour);
                var height = Math.max(20, bottom - top);
                html += '<div class="fc-week-ev" style="top:' + top + 'px;height:' + height + 'px">'
                      + chip(e, true) + '</div>';
            });
            html += '</div>';
        }
        html += '</div></div>';
        return html;
    }

    // ── Render + title ──────────────────────────────────────────────────────

    function title() {
        if (state.view === 'month') {
            return MONTHS[state.focus.getMonth()] + ' ' + state.focus.getFullYear();
        }
        var s = startOfWeek(state.focus), e = addDays(s, 6);
        if (s.getMonth() === e.getMonth()) {
            return MONTHS[s.getMonth()] + ' ' + s.getDate() + '–' + e.getDate() + ', ' + s.getFullYear();
        }
        return MONTHS[s.getMonth()] + ' ' + s.getDate() + ' – ' + MONTHS[e.getMonth()] + ' ' + e.getDate()
             + ', ' + e.getFullYear();
    }

    function render() {
        var body = document.getElementById('fcBody');
        var head = document.getElementById('fcTitle');
        if (head) head.textContent = title();
        if (!body) return;

        body.innerHTML = state.view === 'month' ? renderMonth() : renderWeek();

        // Empty-state hint. Three distinct cases, because "there is nothing at
        // all", "your filter hides everything" and "this month happens to be
        // empty" are different problems and a blank grid explains none of them.
        var hint = document.getElementById('fcEmpty');
        if (hint) {
            var vis = visibleEvents().length;
            var period = (state.view === 'month') ? 'month' : 'week';
            if (!EVENTS.length) {
                hint.innerHTML = '<i class="bi bi-calendar-x me-2"></i>No facility requests on the calendar yet. '
                               + 'Click any date to start one.';
                hint.classList.remove('d-none');
            } else if (!vis) {
                hint.innerHTML = '<i class="bi bi-funnel me-2"></i>No bookings match the selected facilities.';
                hint.classList.remove('d-none');
            } else if (!placedCount) {
                hint.innerHTML = '<i class="bi bi-calendar3 me-2"></i>Nothing scheduled this ' + period
                               + '. Use the arrows to look at another ' + period + '.';
                hint.classList.remove('d-none');
            } else {
                hint.classList.add('d-none');
            }
        }

        document.querySelectorAll('.fc-view-btn').forEach(function (b) {
            b.classList.toggle('active', b.dataset.view === state.view);
        });
    }

    // ── Navigation ──────────────────────────────────────────────────────────

    function step(dir) {
        if (state.view === 'month') {
            // Snap to the 1st before stepping: from the 31st, "+1 month" in JS
            // lands in the month after next.
            state.focus = new Date(state.focus.getFullYear(), state.focus.getMonth() + dir, 1);
        } else {
            state.focus = addDays(state.focus, dir * 7);
        }
        render();
    }

    // ── Wiring ──────────────────────────────────────────────────────────────

    document.addEventListener('click', function (ev) {
        var viewBtn = ev.target.closest('.fc-view-btn');
        if (viewBtn) { state.view = viewBtn.dataset.view; render(); return; }

        if (ev.target.closest('#fcPrev'))  { step(-1); return; }
        if (ev.target.closest('#fcNext'))  { step(1);  return; }
        if (ev.target.closest('#fcToday')) { state.focus = new Date(); render(); return; }

        // An existing entry -> read-only detail modal.
        var c = ev.target.closest('.fc-chip');
        if (c) {
            ev.preventDefault();
            if (typeof window.openFacilityDetail === 'function') window.openFacilityDetail(c.dataset.id);
            return;
        }

        // "+N more" -> jump that day into week view, where everything fits.
        var more = ev.target.closest('.fc-more');
        if (more) {
            state.focus = parseDate(more.dataset.date) || state.focus;
            state.view = 'week';
            render();
            return;
        }

        // Clicking empty space starts a request with the date (and, in week
        // view, the hour) prefilled.
        var add = ev.target.closest('.fc-add');
        if (add) { prefill(add.dataset.date, null); return; }

        var slot = ev.target.closest('.fc-slot');
        if (slot) { prefill(slot.dataset.date, slot.dataset.hour); return; }

        var day = ev.target.closest('.fc-day');
        if (day && !ev.target.closest('.fc-chip')) { prefill(day.dataset.date, null); return; }
    });

    function prefill(date, hour) {
        if (typeof window.openFacilityRequestForm === 'function') {
            window.openFacilityRequestForm(date, hour);
        }
    }

    // Facility filter checkboxes.
    document.addEventListener('change', function (ev) {
        if (!ev.target.classList || !ev.target.classList.contains('fc-facility-filter')) return;
        state.facilities = Array.prototype.slice
            .call(document.querySelectorAll('.fc-facility-filter:checked'))
            .map(function (i) { return i.value; });
        render();
    });

    var clearBtn = document.getElementById('fcClearFilters');
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            document.querySelectorAll('.fc-facility-filter').forEach(function (i) { i.checked = false; });
            state.facilities = [];
            render();
        });
    }

    render();
})();
