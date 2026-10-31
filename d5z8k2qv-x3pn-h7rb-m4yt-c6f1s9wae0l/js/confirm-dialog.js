/**
 * One in-page confirmation window for the whole portal, replacing the
 * browser's confirm() pop-ups so every screen asks the same way.
 *
 * Usage: put data-confirm="Question?" on a link or a submit button.
 *   <a href="index.php?action=deleteX&id=5" data-confirm="Delete this record?">…</a>
 *   <button type="submit" data-confirm="Add these members now?">…</button>
 *
 * Optional:
 *   data-confirm-ok="Delete"           text of the confirm button
 *   data-confirm-variant="danger"      danger | warning | primary | success
 *   data-confirm-title="Delete record" window title
 * Without them, the wording of the question picks sensible defaults
 * (e.g. "permanently"/"cannot be undone" -> red "Delete" button).
 *
 * The message is inserted as text, never HTML, so a member's name in it can't
 * inject markup. Links navigate after confirming; submit buttons submit their
 * form with their own name/value, exactly as a normal click would.
 */
(function () {
    'use strict';

    var modalEl, modal, okBtn, cancelBtn, msgEl, titleEl, iconEl, pending = null;

    var VARIANTS = {
        danger:  { btn: 'btn-danger',  icon: 'bi-exclamation-octagon-fill text-danger' },
        warning: { btn: 'btn-warning', icon: 'bi-exclamation-triangle-fill text-warning' },
        primary: { btn: 'btn-primary', icon: 'bi-question-circle-fill text-primary' },
        success: { btn: 'btn-success', icon: 'bi-check-circle-fill text-success' }
    };

    function guess(msg) {
        var m = msg.toLowerCase();
        // Before "delete": "it will be ARCHIVED rather than deleted" is an archive.
        if (/archive/.test(m))    return { variant: 'warning', ok: 'Archive',    title: 'Please confirm' };
        if (/permanent|cannot be undone|delete|discard/.test(m)) {
            return { variant: 'danger', ok: /discard/.test(m) ? 'Discard' : 'Delete', title: 'Please confirm' };
        }
        if (/deactivate/.test(m)) return { variant: 'warning', ok: 'Deactivate', title: 'Please confirm' };
        if (/unlink/.test(m))     return { variant: 'warning', ok: 'Unlink',     title: 'Please confirm' };
        if (/^activate/.test(m))  return { variant: 'success', ok: 'Activate',   title: 'Please confirm' };
        return { variant: 'primary', ok: 'Continue', title: 'Please confirm' };
    }

    function build() {
        if (modalEl) return true;
        if (!window.bootstrap || !bootstrap.Modal) return false;
        modalEl = document.createElement('div');
        modalEl.className = 'modal fade';
        modalEl.id = 'appConfirmModal';
        modalEl.tabIndex = -1;
        modalEl.setAttribute('aria-labelledby', 'appConfirmTitle');
        modalEl.setAttribute('aria-describedby', 'appConfirmMessage');
        modalEl.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
              '<div class="modal-content">' +
                '<div class="modal-header">' +
                  '<h5 class="modal-title" id="appConfirmTitle"></h5>' +
                  '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
                '</div>' +
                '<div class="modal-body d-flex gap-3 align-items-start">' +
                  '<i class="bi fs-3 lh-1" aria-hidden="true"></i>' +
                  '<p class="mb-0" id="appConfirmMessage"></p>' +
                '</div>' +
                '<div class="modal-footer">' +
                  '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>' +
                  '<button type="button" class="btn" data-app-confirm-ok></button>' +
                '</div>' +
              '</div>' +
            '</div>';
        document.body.appendChild(modalEl);
        titleEl   = modalEl.querySelector('#appConfirmTitle');
        msgEl     = modalEl.querySelector('#appConfirmMessage');
        iconEl    = modalEl.querySelector('.modal-body > i');
        okBtn     = modalEl.querySelector('[data-app-confirm-ok]');
        cancelBtn = modalEl.querySelector('.modal-footer .btn-secondary');
        modal     = new bootstrap.Modal(modalEl);

        okBtn.addEventListener('click', function () {
            var el = pending;
            pending = null;
            modal.hide();
            if (!el) return;
            if (el.tagName === 'A' && el.getAttribute('href')) {
                window.location.href = el.href;
            } else {
                // Re-click with a one-shot pass, so a submit button still sends
                // its own name/value and any other click handlers still run.
                el.dataset.confirmPassed = '1';
                el.click();
            }
        });
        // Destructive questions start on Cancel; the rest start on the action.
        modalEl.addEventListener('shown.bs.modal', function () {
            (modalEl.dataset.variant === 'danger' ? cancelBtn : okBtn).focus();
        });
        modalEl.addEventListener('hidden.bs.modal', function () { pending = null; });
        return true;
    }

    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('[data-confirm]') : null;
        if (!el) return;
        if (el.dataset.confirmPassed === '1') { delete el.dataset.confirmPassed; return; }

        var msg = el.getAttribute('data-confirm') || 'Are you sure?';
        e.preventDefault();
        e.stopImmediatePropagation();

        if (!build()) {
            // Bootstrap failed to load: fall back rather than lose the safeguard.
            if (window.confirm(msg)) { el.dataset.confirmPassed = '1'; el.click(); }
            return;
        }
        var d = guess(msg);
        var variant = el.getAttribute('data-confirm-variant') || d.variant;
        var v = VARIANTS[variant] || VARIANTS.primary;
        modalEl.dataset.variant = variant;
        titleEl.textContent = el.getAttribute('data-confirm-title') || d.title;
        msgEl.textContent   = msg;
        iconEl.className    = 'bi fs-3 lh-1 ' + v.icon;
        okBtn.className     = 'btn ' + v.btn;
        okBtn.textContent   = el.getAttribute('data-confirm-ok') || d.ok;
        pending = el;

        // Opened from inside another window: sit above it rather than behind.
        var parentModal = el.closest('.modal.show');
        modalEl.style.zIndex = parentModal ? 1065 : '';
        modal.show();
        if (parentModal) {
            var backs = document.querySelectorAll('.modal-backdrop');
            if (backs.length) backs[backs.length - 1].style.zIndex = 1062;
        }
    }, true);
})();
