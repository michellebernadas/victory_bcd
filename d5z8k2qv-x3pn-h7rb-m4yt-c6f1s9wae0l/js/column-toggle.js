/**
 * Show / Hide Columns — shared widget for every major list table.
 *
 * Drop a placeholder anywhere in a table card header:
 *     <span class="col-toggle" data-table="membersTable"></span>
 * and this script renders a Bootstrap dropdown of checkboxes, one per column.
 *
 * Works two ways:
 *   • DataTables table → uses the official column().visible() API, so sorting,
 *     searching and the existing export helpers (which read the live <th>/<td>
 *     nodes) all keep working on the remaining columns.
 *   • Plain table      → falls back to toggling a `d-none` class on the matching
 *     <th> and every row's nth <td>.
 *
 * Visibility is per page load — persistence across sessions is intentionally
 * not implemented yet.
 */
(function () {
    'use strict';

    function headerLabel(th, index) {
        var txt = (th.textContent || '').replace(/\s+/g, ' ').trim();
        if (!txt) txt = th.getAttribute('title') || ('Column ' + (index + 1));
        return txt.length > 40 ? txt.slice(0, 40) + '…' : txt;
    }

    function getDataTable(tableId) {
        if (!window.jQuery || !jQuery.fn.DataTable) return null;
        if (!jQuery.fn.DataTable.isDataTable('#' + tableId)) return null;
        return jQuery('#' + tableId).DataTable();
    }

    /** Plain-table fallback: hide the nth <th> and the nth <td> of every row. */
    function setPlainColumnVisible(table, index, visible) {
        var ths = table.querySelectorAll('thead tr:first-child > th');
        if (ths[index]) ths[index].classList.toggle('d-none', !visible);
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            var tds = tr.children;
            if (tds[index]) tds[index].classList.toggle('d-none', !visible);
        });
        // Keep any tfoot summary row aligned with the body.
        table.querySelectorAll('tfoot tr').forEach(function (tr) {
            var cells = tr.children;
            if (cells[index]) cells[index].classList.toggle('d-none', !visible);
        });
    }

    function build(placeholder) {
        var tableId = placeholder.getAttribute('data-table');
        var table   = tableId && document.getElementById(tableId);
        if (!table || placeholder.dataset.ctBuilt === '1') return;

        var ths = table.querySelectorAll('thead tr:first-child > th');
        if (!ths.length) return;

        // Columns the user should not be able to hide (e.g. "#", "Actions").
        var locked = (placeholder.getAttribute('data-locked') || '')
            .split(',').map(function (s) { return parseInt(s.trim(), 10); })
            .filter(function (n) { return !isNaN(n); });

        var dt   = getDataTable(tableId);
        var id   = 'ctBtn_' + tableId;
        var html = ''
            + '<div class="dropdown d-inline-block col-toggle-dd">'
            +   '<button class="btn btn-sm btn-outline-light dropdown-toggle" type="button" id="' + id + '"'
            +           ' data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"'
            +           ' title="Show / hide columns">'
            +     '<i class="bi bi-layout-three-columns me-1"></i>Columns'
            +   '</button>'
            +   '<div class="dropdown-menu dropdown-menu-end p-2 shadow" style="max-height:340px;overflow-y:auto;min-width:230px;">'
            +     '<div class="d-flex justify-content-between align-items-center px-1 pb-2 mb-2 border-bottom">'
            +       '<span class="small fw-semibold text-muted text-uppercase" style="letter-spacing:.05em;">Show / Hide Columns</span>'
            +       '<button type="button" class="btn btn-link btn-sm p-0 ct-reset" style="font-size:11px;">Reset</button>'
            +     '</div>'
            +     '<div class="ct-list"></div>'
            +   '</div>'
            + '</div>';
        placeholder.innerHTML = html;
        placeholder.dataset.ctBuilt = '1';

        var list = placeholder.querySelector('.ct-list');
        Array.prototype.forEach.call(ths, function (th, i) {
            if (locked.indexOf(i) !== -1) return;
            var item  = document.createElement('div');
            item.className = 'form-check';
            var cbId  = 'ct_' + tableId + '_' + i;
            item.innerHTML =
                '<input class="form-check-input ct-cb" type="checkbox" checked id="' + cbId + '" data-col="' + i + '">' +
                '<label class="form-check-label small" for="' + cbId + '"></label>';
            item.querySelector('label').textContent = headerLabel(th, i);
            list.appendChild(item);
        });

        function apply(index, visible) {
            if (dt) dt.column(index).visible(visible, false);
            else    setPlainColumnVisible(table, index, visible);
        }

        placeholder.addEventListener('change', function (e) {
            var cb = e.target.closest('.ct-cb');
            if (!cb) return;
            apply(parseInt(cb.dataset.col, 10), cb.checked);
            if (dt) dt.columns.adjust().draw(false);
        });

        placeholder.querySelector('.ct-reset').addEventListener('click', function () {
            placeholder.querySelectorAll('.ct-cb').forEach(function (cb) {
                if (!cb.checked) { cb.checked = true; apply(parseInt(cb.dataset.col, 10), true); }
            });
            if (dt) dt.columns.adjust().draw(false);
        });
    }

    window.initColumnToggles = function () {
        document.querySelectorAll('.col-toggle[data-table]').forEach(build);
    };

    // Run after the page's own DataTable initialisation (footer.php loads this
    // file before the page-specific $(function(){...}) blocks, so defer a tick).
    if (window.jQuery) {
        jQuery(function () { setTimeout(window.initColumnToggles, 0); });
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(window.initColumnToggles, 0);
        });
    }
})();
