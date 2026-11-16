<?php
/**
 * Dashboard filter bar — every dashboard filter in one place.
 *
 * Before: five separate filters, each tucked inside its own card and each one
 * reloading the page and losing the others' position. Now one bar, one Apply.
 * It writes the SAME query parameters the cards already read, so nothing below
 * had to change and every old link, bookmark and the Excel export still work:
 *
 *   sunday_range  Last 4 Sundays | Last 3 Months | This Year   (Church Health)
 *   health_year   the year for the month-by-month bars
 *   dash_class    class filter                                  (class attendance)
 *   dash_year     year filter for that card
 *   dash_bucket   weekly / monthly / quarterly / annually trend
 *
 * Requires in scope: $sundayRange, $healthYear, $healthYears, $dashClass,
 *                    $dashYear, $dashBucket, $dashYearOptions
 */
$fbBuckets = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annually' => 'Annually'];

// What differs from the defaults, shown as removable chips.
$fbDefaultYear = (int)($healthYears[0] ?? date('Y'));
$fbChips = [];
$fbWithout = function (array $drop) {
    $q = array_diff_key($_GET, array_flip($drop));
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
};
if ($sundayRange !== ServiceAttendance::DEFAULT_RANGE) {
    $fbChips[] = ['Sundays: ' . (ServiceAttendance::RANGES[$sundayRange] ?? $sundayRange), $fbWithout(['sunday_range'])];
}
if ($healthYear && $healthYear !== $fbDefaultYear) {
    $fbChips[] = ['Months: ' . $healthYear, $fbWithout(['health_year'])];
}
if ($dashClass !== '') {
    $fbChips[] = ['Class: ' . (ProgramAttendance::PROGRAM_LABELS[$dashClass] ?? $dashClass), $fbWithout(['dash_class'])];
}
if ($dashYear) {
    $fbChips[] = ['Class year: ' . $dashYear, $fbWithout(['dash_year'])];
}
if ($dashBucket !== 'monthly') {
    $fbChips[] = ['Trend: ' . ($fbBuckets[$dashBucket] ?? $dashBucket), $fbWithout(['dash_bucket'])];
}
?>
<form method="GET" action="index.php" class="db-filterbar db-noprint mb-4" id="dashFilterBar" aria-label="Dashboard filters">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3 col-xl-2">
            <label for="dfSunday">Sunday &amp; gatherings</label>
            <select name="sunday_range" id="dfSunday" class="form-select form-select-sm">
                <?php foreach (ServiceAttendance::RANGES as $k => $lbl): ?>
                <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $sundayRange === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="dfHealthYear">Month-by-month year</label>
            <select name="health_year" id="dfHealthYear" class="form-select form-select-sm">
                <?php foreach ($healthYears as $y): ?>
                <option value="<?php echo (int)$y; ?>" <?php echo (int)$y === (int)$healthYear ? 'selected' : ''; ?>><?php echo (int)$y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="dfClass">Class</label>
            <select name="dash_class" id="dfClass" class="form-select form-select-sm">
                <option value="">All classes</option>
                <?php foreach (ProgramAttendance::PROGRAM_LABELS as $pt => $lbl): ?>
                <option value="<?php echo htmlspecialchars($pt); ?>" <?php echo $dashClass === $pt ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="dfClassYear">Class year</label>
            <select name="dash_year" id="dfClassYear" class="form-select form-select-sm">
                <option value="">Current year</option>
                <?php foreach ($dashYearOptions as $y): ?>
                <option value="<?php echo (int)$y; ?>" <?php echo (int)$dashYear === (int)$y ? 'selected' : ''; ?>><?php echo (int)$y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="dfBucket">Class trend</label>
            <select name="dash_bucket" id="dfBucket" class="form-select form-select-sm">
                <?php foreach ($fbBuckets as $k => $lbl): ?>
                <option value="<?php echo $k; ?>" <?php echo $dashBucket === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3 col-xl-2 d-flex gap-1">
            <button type="submit" class="btn btn-sm btn-primary flex-fill"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply</button>
            <?php if ($fbChips): ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Reset every filter"><i class="bi bi-x-circle" aria-hidden="true"></i><span class="visually-hidden">Reset filters</span></a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($fbChips): ?>
    <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
        <span class="small text-muted me-1">Showing:</span>
        <?php foreach ($fbChips as [$text, $url]): ?>
        <a href="<?php echo htmlspecialchars($url); ?>" class="db-chip" title="Remove this filter"><?php echo htmlspecialchars($text); ?> <i class="bi bi-x" aria-hidden="true"></i></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</form>
