<?php
/**
 * Year choices for every class form (Attendance, Leadership 113, Spiritual Foundations).
 *
 * People finish a class long before this portal existed and often only
 * remember the year, so the list runs from this year back to CLASS_MIN_YEAR
 * rather than only the years already recorded. The selects are searchable
 * (select2), so typing "1985" jumps straight to it.
 *
 * In:  $availableYears  (optional) years already in the data
 * Out: $_yearOpts       years, newest first
 *      $_curYear        the current year (the default selection)
 */
$_curYear  = (int)date('Y');
$_minYear  = ProgramAttendance::CLASS_MIN_YEAR;
$_yearOpts = array_map('intval', $availableYears ?? []);
for ($_y = $_curYear; $_y >= $_minYear; $_y--) {
    if (!in_array($_y, $_yearOpts, true)) $_yearOpts[] = $_y;
}
rsort($_yearOpts);
