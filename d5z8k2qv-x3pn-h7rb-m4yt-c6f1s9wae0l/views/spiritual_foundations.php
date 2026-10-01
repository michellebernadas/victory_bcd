<?php
if (!isset($_SESSION['user'])) { header('Location: index.php?action=login'); exit(); }
require_once 'models/SfTopic.php';
require_once 'models/SfProgress.php';
include 'shared/header.php';

$records         = $records         ?? [];
$availableYears  = $availableYears  ?? [];
$scBatchNames    = $scBatchNames    ?? [];
$scStatuses      = $scStatuses      ?? [];
$sfTopics        = $sfTopics        ?? [];
$sfWeeks         = $sfWeeks         ?? [];
$sfRequiredWeeks = $sfRequiredWeeks ?? $sfWeeks;
$sfWeekCount     = $sfWeekCount     ?? count($sfWeeks);
$sfProgress      = $sfProgress      ?? [];
$sfUsage         = $sfUsage         ?? [];
$sfHasAttendance = $sfHasAttendance ?? false;
$sfIsAdmin       = $sfIsAdmin       ?? (($_SESSION['user']['accounttype'] ?? '') === 'admin');
$scExpectedCount = $scExpectedCount ?? count($sfRequiredWeeks);
$paStats         = $paStats         ?? [];
$activeYear      = $activeYear      ?? '';
$activeBatch     = $activeBatch     ?? '';
$activeSearch    = $activeSearch    ?? '';
$activeDateFrom  = $activeDateFrom  ?? '';
$activeDateTo    = $activeDateTo    ?? '';
$activeSession   = $activeSession   ?? '';
$activeMatch     = $activeMatch     ?? 'all';
$activeTab       = $_GET['tab']     ?? 'records';

$requiredWeekNos = array_keys($sfRequiredWeeks);
$allWeekNos      = array_keys($sfWeeks);
sort($requiredWeekNos); sort($allWeekNos);

// Batch filter (the server-side list is already class-scoped).
if ($activeBatch !== '') {
    $records = array_values(array_filter($records, function ($rec) use ($activeBatch) {
        return trim($rec['batch_label'] ?? '') === $activeBatch || $rec['program_label'] === $activeBatch;
    }));
}

/**
 * Per-row roll-up. Two distinct numbers, deliberately kept apart:
 *   batch   — what this participant did in THIS batch
 *   overall — cumulative across every SF batch they appear in (drives the certificate)
 */
$rows = [];
$seenParticipants = [];
$totalEligible = 0; $totalNotEligible = 0; $totalMembers = 0;
foreach ($records as $rec) {
    $weekStatuses = SfProgress::weekStatuses($rec['extra_data'], $allWeekNos);
    $batch        = SfProgress::batchStats($weekStatuses, $requiredWeekNos);
    $pKey         = SfProgress::participantKey($rec);
    $overall      = $sfProgress[$pKey] ?? [
        'completed' => $batch['completed'], 'required' => count($requiredWeekNos),
        'missing' => $batch['missed'], 'percent' => $batch['percent'], 'eligible' => false,
        'weeks' => [], 'batches' => [],
    ];
    $rows[] = ['rec' => $rec, 'batch' => $batch, 'overall' => $overall, 'weeks' => $weekStatuses, 'pkey' => $pKey];

    if ($rec['member_id']) $totalMembers++;
    // Eligibility is a property of the PERSON, so count each participant once.
    if (!isset($seenParticipants[$pKey])) {
        $seenParticipants[$pKey] = true;
        if (!empty($overall['eligible'])) $totalEligible++; else $totalNotEligible++;
    }
}
$totalRecords      = count($rows);
$totalParticipants = count($seenParticipants);

// Batch summary — completion measured within each batch, plus how many of those
// people are complete overall (they may have finished in a different batch).
$batchStats = [];
foreach ($rows as $r) {
    $key = (trim($r['rec']['batch_label'] ?? '') ?: ($r['rec']['program_label'] ?? '')) . '|' . $r['rec']['program_year'];
    if (!isset($batchStats[$key])) {
        $batchStats[$key] = [
            'batch' => trim($r['rec']['batch_label'] ?? '') ?: ($r['rec']['program_label'] ?? ''),
            'year'  => $r['rec']['program_year'],
            'total' => 0, 'completed' => 0, 'notCompleted' => 0, 'overallComplete' => 0,
        ];
    }
    $batchStats[$key]['total']++;
    $batchDone = ($r['batch']['required'] > 0 && empty($r['batch']['missed']));
    if ($batchDone) $batchStats[$key]['completed']++; else $batchStats[$key]['notCompleted']++;
    if (!empty($r['overall']['eligible'])) $batchStats[$key]['overallComplete']++;
}

// Batches available for the selected year.
$batchesForYear = [];
foreach ($records as $rec) {
    if ($activeYear && (string)$rec['program_year'] !== (string)$activeYear) continue;
    $b = trim($rec['batch_label'] ?? '');
    if ($b && !in_array($b, $batchesForYear, true)) $batchesForYear[] = $b;
}

$sfFilterCount = count(array_filter([$activeYear, $activeBatch, $activeSearch, $activeDateFrom, $activeDateTo, $activeSession]));
$sfClearUrl    = 'index.php?action=spiritualFoundations';

// Participant status datalist: P / A / L / NC. Class-level cancellations
// (NO CLASS / Holy Week / DC) describe the session, not a person, so they are
// filtered out even if they exist in the stored data.
$statusMerged = ['P', 'A', 'L', 'NC'];
foreach ($scStatuses as $s) {
    $s = trim($s);
    if ($s === '' || in_array($s, $statusMerged, true)) continue;
    if (ProgramAttendance::isClassCancelledStatus($s)) continue;
    $statusMerged[] = $s;
}
?>
<body>
    <datalist id="sfStatusOpts">
        <?php foreach ($statusMerged as $s): ?>
        <option value="<?php echo htmlspecialchars($s); ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <?php include 'shared/menu.php'; ?>
    <div class="main-content">
        <div class="container-fluid">

            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0"><i class="bi bi-shield me-2 text-purple"></i>Spiritual Foundations</h1>
                    <p class="text-muted mb-0">
                        Session attendance for the Spiritual Foundations class
                        &mdash; <?php echo count($requiredWeekNos); ?> required topics, completed across any batch
                    </p>
                </div>
                <?php if ($activeTab === 'records'): ?>
                <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#addSfModal">
                    <i class="bi bi-plus-circle me-1"></i>Add Spiritual Foundations Record
                </button>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'records' ? 'active' : ''; ?>"
                       href="index.php?action=spiritualFoundations&tab=records<?php echo $activeYear ? '&year='.urlencode($activeYear) : ''; ?><?php echo $activeBatch ? '&batch='.urlencode($activeBatch) : ''; ?>">
                        <i class="bi bi-table me-1"></i>Records
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'curriculum' ? 'active' : ''; ?>"
                       href="index.php?action=spiritualFoundations&tab=curriculum">
                        <i class="bi bi-journal-text me-1"></i>Curriculum
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'stats' ? 'active' : ''; ?>"
                       href="index.php?action=spiritualFoundations&tab=stats<?php echo $activeYear ? '&year='.urlencode($activeYear) : ''; ?>">
                        <i class="bi bi-bar-chart-line me-1"></i>Statistics
                    </a>
                </li>
            </ul>

            <!-- Notifications -->
            <?php if (isset($_GET['notif'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php $msgs = [
                    'add'              => 'Spiritual Foundations record has been added successfully.',
                    'update'           => 'Spiritual Foundations record has been updated successfully.',
                    'deactivate'       => 'Spiritual Foundations record has been deactivated successfully.',
                    'activate'         => 'Spiritual Foundations record has been reactivated successfully.',
                    'delete'           => 'Spiritual Foundations record has been deleted successfully.',
                    'topic_add'        => 'Curriculum topic has been added successfully.',
                    'topic_update'     => 'Curriculum topic has been updated successfully.',
                    'topic_activate'   => 'Curriculum topic has been reactivated.',
                    'topic_deactivate' => 'Curriculum topic has been deactivated. Historical attendance is unchanged.',
                    'topic_delete'     => 'Curriculum topic was never used, so it has been deleted permanently.',
                    'topic_archived'   => 'Curriculum topic has attendance history, so it was archived instead of deleted. All records are preserved.',
                ]; echo $msgs[$_GET['notif']] ?? 'Done.'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($_GET['msg'] ?? 'An error occurred.'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($activeTab === 'records'): ?>
            <?php include 'shared/sf_records_tab.php'; ?>
            <?php elseif ($activeTab === 'curriculum'): ?>
            <?php include 'shared/sf_curriculum_tab.php'; ?>
            <?php else: ?>
            <?php include 'shared/sf_stats_tab.php'; ?>
            <?php endif; ?>

        </div>
    </div>

<?php include 'shared/footer.php'; ?>
<script src="<?php echo asset('js/spiritual-foundations.js'); ?>"></script>
<script>
window.SF_CONFIG = {
    weeks:         <?php echo json_encode(array_map(fn($w) => ['week' => (int)$w['week_no'], 'topic' => $w['topic']], array_values($sfWeeks))); ?>,
    expectedCount: <?php echo count($requiredWeekNos); ?>,
    activeSession: <?php echo json_encode($activeSession); ?>,
    activeMatch:   <?php echo json_encode($activeMatch); ?>,
    serverFilters: <?php echo (int)$sfFilterCount; ?>,
    serverSearch:  <?php echo json_encode($activeSearch); ?>
};
if (window.initSpiritualFoundationsPage) window.initSpiritualFoundationsPage();
</script>
