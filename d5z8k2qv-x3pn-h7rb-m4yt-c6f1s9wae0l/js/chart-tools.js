/**
 * Chart tools — one shared toolbar for every Chart.js canvas on a page.
 *
 * Add class "vb-chart-tools" to any element that contains canvases (or put
 * data-chart-tools on a single canvas's .chart-box). Each canvas gets a small
 * toolbar: PNG, CSV, Print. Nothing needs wiring per chart, so a
 * chart added later is covered the moment it exists.
 *
 *   PNG    the chart on a white card with its title and a dated footer
 *   CSV    the data behind the chart, read from the live Chart.js instance
 *   Print  the same image, in the browser's print dialog
 *
 * The title comes from the nearest heading-like label above the canvas; set
 * data-chart-title on the canvas or its .chart-box to override it.
 */
(function () {
    'use strict';

    var FOOTER = 'Victory Bacolod';

    function today() { return new Date().toISOString().slice(0, 10); }

    function slug(s) {
        return String(s || 'chart').toLowerCase().replace(/&[a-z]+;/g, ' ').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'chart';
    }

    /** The chart's title: explicit attribute, else the closest small heading above it. */
    function titleFor(canvas) {
        var box = canvas.closest('.chart-box') || canvas;
        var explicit = canvas.getAttribute('data-chart-title') || box.getAttribute('data-chart-title');
        if (explicit) return explicit;
        var n = box.previousElementSibling;
        for (var i = 0; n && i < 4; i++, n = n.previousElementSibling) {
            var t = (n.textContent || '').replace(/\s+/g, ' ').trim();
            if (t && t.length < 120 && n.querySelector && (n.matches('.small, .fw-bold, h5, h6, .card-header') || n.querySelector('.fw-bold'))) return t;
        }
        var card = canvas.closest('.card');
        var head = card && card.querySelector('.card-header');
        if (head) return head.textContent.replace(/\s+/g, ' ').trim();
        return canvas.getAttribute('aria-label') || 'Chart';
    }

    /** The chart rendered onto a white canvas with a title and footer. Returns a canvas. */
    function compose(canvas, title) {
        var pad = 24, titleH = 44, footH = 34;
        var w = canvas.width, h = canvas.height;
        var out = document.createElement('canvas');
        out.width = w + pad * 2;
        out.height = h + titleH + footH + pad;
        var c = out.getContext('2d');
        c.fillStyle = '#ffffff';
        c.fillRect(0, 0, out.width, out.height);
        c.fillStyle = '#1d2433';
        c.font = '600 ' + Math.max(16, Math.round(w / 38)) + 'px system-ui, -apple-system, "Segoe UI", sans-serif';
        c.textBaseline = 'middle';
        c.fillText(title, pad, titleH / 2 + 8, w);
        c.drawImage(canvas, pad, titleH);
        c.fillStyle = '#6c757d';
        c.font = '12px system-ui, -apple-system, "Segoe UI", sans-serif';
        c.fillText(FOOTER + '  ·  exported ' + new Date().toLocaleString(), pad, out.height - footH / 2 - 4);
        return out;
    }

    function download(href, name) {
        var a = document.createElement('a');
        a.href = href; a.download = name; document.body.appendChild(a); a.click(); a.remove();
    }

    function flash(btn, text, ok) {
        var old = btn.getAttribute('data-label') || btn.innerHTML;
        btn.setAttribute('data-label', old);
        btn.innerHTML = '<i class="bi ' + (ok === false ? 'bi-x-circle' : 'bi-check2') + ' me-1" aria-hidden="true"></i>' + text;
        setTimeout(function () { btn.innerHTML = old; }, 1600);
    }

    function csvFor(chart) {
        var q = function (v) { return '"' + String(v === null || v === undefined ? '' : v).replace(/"/g, '""') + '"'; };
        var labels = chart.data.labels || [];
        var sets = chart.data.datasets || [];
        var rows = [['Label'].concat(sets.map(function (d) { return d.label || 'Value'; }))];
        labels.forEach(function (l, i) {
            rows.push([l].concat(sets.map(function (d) { var v = d.data[i]; return (v && typeof v === 'object') ? (v.y !== undefined ? v.y : '') : v; })));
        });
        return '﻿' + rows.map(function (r) { return r.map(q).join(','); }).join('\r\n');
    }

    var actions = {
        png: function (canvas, title, btn) {
            download(compose(canvas, title).toDataURL('image/png'), slug(title) + '_' + today() + '.png');
            flash(btn, 'Saved');
        },
        csv: function (canvas, title, btn) {
            var chart = window.Chart && Chart.getChart(canvas);
            if (!chart) { flash(btn, 'No data', false); return; }
            var url = URL.createObjectURL(new Blob([csvFor(chart)], { type: 'text/csv;charset=utf-8;' }));
            download(url, slug(title) + '_' + today() + '.csv');
            setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
            flash(btn, 'Saved');
        },
        print: function (canvas, title) {
            var src = compose(canvas, title).toDataURL('image/png');
            var w = window.open('', '_blank');
            if (!w) return;
            w.document.write('<!doctype html><title>' + title.replace(/</g, '&lt;') + '</title><style>body{margin:0}img{max-width:100%}</style><img src="' + src + '">');
            w.document.close();
            w.onload = function () { w.focus(); w.print(); };
        }
    };

    var BUTTONS = [
        ['png',   'bi-image',     'PNG',   'Download as an image'],
        ['csv',   'bi-filetype-csv', 'CSV', 'Download the data behind this chart'],
        ['print', 'bi-printer',   'Print', 'Print this chart']
    ];

    function attach(canvas) {
        if (canvas.__vbTools) return;
        canvas.__vbTools = true;
        var box = canvas.closest('.chart-box') || canvas.parentElement;
        var bar = document.createElement('div');
        bar.className = 'vb-chart-bar';
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', 'Chart export options');
        BUTTONS.forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary vb-chart-btn';
            btn.title = b[3];
            btn.setAttribute('aria-label', b[3]);
            btn.innerHTML = '<i class="bi ' + b[1] + ' me-1" aria-hidden="true"></i>' + b[2];
            btn.addEventListener('click', function () { actions[b[0]](canvas, titleFor(canvas), btn); });
            bar.appendChild(btn);
        });
        box.parentNode.insertBefore(bar, box.nextSibling);
    }

    function init(root) {
        (root || document).querySelectorAll('.vb-chart-tools canvas, canvas[data-chart-tools]').forEach(function (cv) {
            // Only canvases that actually drew a chart (an empty one has nothing to export).
            if (window.Chart && Chart.getChart(cv)) attach(cv);
        });
    }

    // ── Whole-card image export (HTML sections, not canvases) ───────────────
    // Every .card with a .card-header inside a .vb-chart-tools region gets a
    // "Save image" button in its header: the card as shown, on white, with a
    // dated footer. Uses html2canvas (already loaded by the footer). Buttons,
    // chart toolbars and the column-toggle are hidden in the capture.
    function cardTitle(card) {
        var h = card.querySelector('.card-header h6, .card-header h5, .card-header .h6');
        var t = h ? h.textContent : '';
        return t.replace(/\s+/g, ' ').replace(/\d+ filters?$/i, '').trim() || 'Dashboard';
    }

    function saveCard(card, btn) {
        if (typeof html2canvas === 'undefined') { flash(btn, 'Not loaded', false); return; }
        card.classList.add('vb-capturing');
        html2canvas(card, {
            backgroundColor: '#ffffff', scale: 2, useCORS: true, logging: false,
            ignoreElements: function (el) { return el.classList && (el.classList.contains('vb-card-btn') || el.classList.contains('vb-chart-bar') || el.classList.contains('col-toggle')); }
        }).then(function (cv) {
            var pad = 16, foot = 30;
            var out = document.createElement('canvas');
            out.width = cv.width + pad * 2; out.height = cv.height + pad + foot;
            var c = out.getContext('2d');
            c.fillStyle = '#ffffff'; c.fillRect(0, 0, out.width, out.height);
            c.drawImage(cv, pad, pad);
            c.fillStyle = '#6c757d'; c.font = '22px system-ui, -apple-system, "Segoe UI", sans-serif'; c.textBaseline = 'middle';
            c.fillText(FOOTER + '  ·  exported ' + new Date().toLocaleString(), pad, out.height - foot / 2);
            download(out.toDataURL('image/png'), slug(cardTitle(card)) + '_' + today() + '.png');
            flash(btn, 'Saved');
        }).catch(function () { flash(btn, 'Failed', false); })
          .then(function () { card.classList.remove('vb-capturing'); });
    }

    function attachCard(card) {
        if (card.__vbCard) return;
        var head = card.querySelector(':scope > .card-header');
        if (!head) return;
        card.__vbCard = true;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-sm btn-outline-light vb-card-btn ms-auto';
        btn.title = 'Save this section as an image';
        btn.setAttribute('aria-label', 'Save this section as an image');
        btn.innerHTML = '<i class="bi bi-image me-1" aria-hidden="true"></i>Image';
        btn.addEventListener('click', function () { saveCard(card, btn); });
        head.appendChild(btn);
        // Header is a flex row on most cards; make sure it can hold one more item.
        if (getComputedStyle(head).display !== 'flex') head.classList.add('d-flex', 'align-items-center', 'flex-wrap', 'gap-2');
    }

    // Not reports: the checklist, shortcuts, and system info. Opt a card out with
    // data-no-export, or opt a small tile in with data-export-card.
    var SKIP_TITLES = /^(quick actions|system info|getting started)/i;

    function initCards(root) {
        (root || document).querySelectorAll('.vb-chart-tools .card').forEach(function (card) {
            if (card.hasAttribute('data-no-export') || card.closest('#gettingStartedCard, [data-no-export]')) return;
            if (!card.hasAttribute('data-export-card')) {
                // Small stat tiles / nested tiles are not sections: only top-level cards with a real heading.
                if (card.parentElement && card.parentElement.closest('.card')) return;
                if (!card.querySelector(':scope > .card-header h6, :scope > .card-header h5, :scope > .card-header .h6')) return;
                if (SKIP_TITLES.test(cardTitle(card))) return;
            }
            attachCard(card);
        });
    }

    // Charts are created by inline scripts after this file loads, so wait for
    // the page, then once more shortly after for any that draw late.
    document.addEventListener('DOMContentLoaded', function () { init(); initCards(); setTimeout(function () { init(); initCards(); }, 400); });
    window.VBChartTools = { init: init, initCards: initCards };
})();
