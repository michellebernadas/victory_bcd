/**
 * Linked Member -> First / Last Name auto-population.
 *
 * Wherever a form has "Linked Member: [Select member]" next to First Name and
 * Last Name fields, picking a member fills those fields in. The admin never
 * retypes a name the member record already holds, and the typed name can no
 * longer silently disagree with the member it is linked to.
 *
 * Applies to the three class workflows that share this shape:
 *   Attendance Records (VW / CC / MD / EL), Leadership 1-1-3, Spiritual Foundations.
 *
 * BEHAVIOUR
 *   - Member selected   -> name fields are filled, locked read-only, and
 *                          visually marked as coming from the member record.
 *   - Member cleared    -> fields unlock and keep their values, so an unlinked
 *                          participant can still be typed in by hand. Several
 *                          workflows legitimately record people who are not yet
 *                          members, so this must never become mandatory.
 *
 * Locking rather than merely pre-filling is what prevents the disagreement:
 * with the field editable, an admin could link member A and type name B, and
 * the record would then claim both.
 *
 * Registration is data-driven so a new form only needs matching selectors, not
 * another copy of this logic.
 */
(function () {
    'use strict';
    if (!window.jQuery) return;
    var $ = window.jQuery;

    // [member select, first-name input, last-name input] per workflow.
    var FORMS = [
        { member: '.at-member-select2',   first: '.at-first',   last: '.at-last'   },
        { member: '.l113-member-select2', first: '.l113-first', last: '.l113-last' },
        { member: '.sf-member-select2',   first: '.sf-first',   last: '.sf-last'   }
    ];

    var HINT_CLASS = 'linked-member-hint';

    function hintFor($input) {
        var $hint = $input.next('.' + HINT_CLASS);
        if (!$hint.length) {
            $hint = $('<div class="' + HINT_CLASS + ' form-text small text-primary" style="display:none">' +
                      '<i class="bi bi-link-45deg me-1"></i>From the linked member record.</div>');
            $input.after($hint);
        }
        return $hint;
    }

    function lock($input, value) {
        if (!$input.length) return;
        $input.val(value)
              .prop('readonly', true)
              .addClass('bg-light')
              // A readonly input still submits; a disabled one would not, and the
              // controller needs these values.
              .attr('title', 'Filled from the linked member. Clear the Linked Member to edit.');
        hintFor($input).show();
    }

    function unlock($input) {
        if (!$input.length) return;
        $input.prop('readonly', false).removeClass('bg-light').removeAttr('title');
        hintFor($input).hide();
    }

    /**
     * Select2's AJAX payload carries first_name / last_name. When a selection is
     * restored from a plain <option> (edit forms), fall back to splitting the
     * canonical "Last, First" label.
     */
    function namesFrom(data, $sel) {
        if (data && (data.first_name || data.last_name)) {
            return { first: data.first_name || '', last: data.last_name || '' };
        }
        var text = (data && data.text) || $sel.find('option:selected').text() || '';
        text = text.split('—')[0].trim();          // drop the " — MINISTRY" suffix
        if (text.indexOf(',') !== -1) {
            var parts = text.split(',');
            return { last: parts[0].trim(), first: parts.slice(1).join(',').trim() };
        }
        return null;                                // unparseable: leave fields alone
    }

    FORMS.forEach(function (map) {
        $(document).on('select2:select', map.member, function (e) {
            var $form  = $(this).closest('form');
            var $first = $form.find(map.first);
            var $last  = $form.find(map.last);
            if (!$first.length && !$last.length) return;

            var data = e.params && e.params.data;
            // The "Unmatched" sentinel is an explicit "not a member" choice.
            if (data && (data.id === '' || data.id === '__unmatched__')) {
                unlock($first); unlock($last);
                return;
            }
            var names = namesFrom(data, $(this));
            if (!names) return;
            lock($first, names.first);
            lock($last,  names.last);
        });

        // Clearing the link hands the fields back to the admin, values intact.
        $(document).on('select2:clear select2:unselect', map.member, function () {
            var $form = $(this).closest('form');
            unlock($form.find(map.first));
            unlock($form.find(map.last));
        });

        // Opening a modal with a member already linked (edit) must lock too,
        // otherwise the edit form would allow the drift the add form prevents.
        $(document).on('shown.bs.modal', function (ev) {
            var $sel = $(ev.target).find(map.member);
            if (!$sel.length || !$sel.val()) return;
            var $form  = $sel.closest('form');
            var names  = namesFrom(null, $sel);
            if (!names) return;
            lock($form.find(map.first), names.first);
            lock($form.find(map.last),  names.last);
        });
    });
})();
