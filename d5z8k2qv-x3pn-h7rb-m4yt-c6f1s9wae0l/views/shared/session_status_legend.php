<?php
/**
 * Shared status legend for session-based classes (Leadership 1-1-3 and
 * Spiritual Foundations). Colours come from ProgramAttendance::statusStyle(),
 * so the legend can never drift from the badges it describes.
 *
 * Optional in scope:
 *   $legendSize   — font-size in px (default 10)
 *   $legendShow   — subset of kinds to render (default all)
 */
$legendSize = $legendSize ?? 10;
$legendShow = $legendShow ?? ['attended', 'late', 'absent', 'no_class', 'not_required'];

// The badge already shows the code, so the text must not repeat it.
$legendItems = [
    'attended'     => ['sample' => 'P',        'text' => 'Present / Summit',  'hint' => 'Counts as completed'],
    'late'         => ['sample' => 'L',        'text' => 'Late',              'hint' => 'Counts as completed'],
    'absent'       => ['sample' => 'A',        'text' => 'Absent',            'hint' => 'Not completed'],
    'no_class'     => ['sample' => 'NO CLASS', 'text' => 'No class held',     'hint' => 'No class was held for the whole batch (NO CLASS / Holy Week / DC) — never counted'],
    'not_required' => ['sample' => 'NC',       'text' => 'Not required',      'hint' => 'Not required of this participant, e.g. already completed in an earlier batch — gives no credit'],
];
?>
<div class="d-flex flex-wrap gap-2 align-items-center session-status-legend" style="font-size:<?php echo (int)$legendSize; ?>px;">
    <?php foreach ($legendShow as $kind):
        if (!isset($legendItems[$kind])) continue;
        $item  = $legendItems[$kind];
        $style = ProgramAttendance::statusStyle($item['sample']);
    ?>
    <span class="d-inline-flex align-items-center gap-1" title="<?php echo htmlspecialchars($item['hint']); ?>">
        <span class="badge <?php echo $style['badge']; ?>" style="font-size:<?php echo (int)$legendSize - 1; ?>px;">
            <?php echo htmlspecialchars($style['label']); ?>
        </span>
        <span class="text-muted"><?php echo htmlspecialchars($item['text']); ?></span>
    </span>
    <?php endforeach; ?>
</div>
