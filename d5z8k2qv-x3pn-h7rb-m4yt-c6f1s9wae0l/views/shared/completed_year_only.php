<?php
/**
 * "Completed — I only know the year" tick box, shared by the Leadership 113 and
 * Spiritual Foundations forms (add and edit).
 *
 * Those two classes are normally proven by a session grid. Someone who finished
 * long ago (1985, say) has no sessions to enter, so ticking this saves the
 * record as completed with just the year. It counts as completed on the
 * member's profile; it does NOT unlock the certificate, which still needs real
 * session attendance. Stored in program_attendances.completed_manual.
 */
?>
<div class="col-12">
    <div class="form-check border rounded p-2 ps-5 bg-light">
        <input class="form-check-input" type="checkbox" name="completed_manual" value="1">
        <label class="form-check-label">
            <strong>Completed &mdash; I only know the year</strong>
            <span class="d-block form-text mt-0">For someone who finished this class and doesn&rsquo;t remember the dates. Choose the
                <strong>year</strong> above and leave the sessions empty. It counts as completed on their profile; the certificate
                still needs real session attendance.</span>
        </label>
    </div>
</div>
