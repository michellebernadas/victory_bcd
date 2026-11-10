<?php
/**
 * Church Health -> Excel (.xlsx), built server-side with PhpSpreadsheet so the
 * workbook's numbers come from the same rows the dashboard reads.
 *
 * Sheets: "Sundays by service", "Monthly summary", "Prayer & Hangout".
 * Every total is computed here from the exported rows themselves, so a sheet
 * can never disagree with the sheet next to it.
 */
require_once __DIR__ . '/../models/ServiceAttendance.php';
require_once __DIR__ . '/../models/GatheringAttendance.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ChurchHealthExportController
{
    private $db;
    private $sa;
    private $ga;

    public function __construct($db)
    {
        $this->db = $db;
        $this->sa = new ServiceAttendance($db);
        $this->ga = new GatheringAttendance($db);
    }

    /**
     * Resolves ?range= (last4 | last3mo | year) or ?year=YYYY into
     * [filters, human label, filename slug]. Default: this calendar year.
     */
    public function resolveRange(array $q): array
    {
        $year = (int)($q['year'] ?? 0);
        if ($year >= 2000 && $year <= 2100) {
            return [['date_from' => "$year-01-01", 'date_to' => "$year-12-31"], "Year $year", (string)$year];
        }
        $range = in_array($q['range'] ?? '', array_keys(ServiceAttendance::RANGES), true) ? $q['range'] : 'year';
        $scope = $this->sa->rangeScope($range);
        $label = $range === 'year' ? 'This Year (' . date('Y') . ')' : $scope['label'];
        if ($range !== 'year' && !empty($scope['filters']['date_from'])) {
            $label .= ' — from ' . date('M j, Y', strtotime($scope['filters']['date_from']));
        }
        return [$scope['filters'], $label, $range === 'year' ? date('Y') : $range];
    }

    /** Streams the workbook as a download. */
    public function download(array $q, string $user): void
    {
        [$filters, $label, $slug] = $this->resolveRange($q);
        $book = $this->build($filters, $label, $user);
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="church-health-' . $slug . '-' . date('Ymd') . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new Xlsx($book))->save('php://output');
        exit();
    }

    /** Builds the three-sheet workbook (public so it can be checked in tests). */
    public function build(array $filters, string $label, string $user): Spreadsheet
    {
        $services = $this->sa->getAll($filters);
        usort($services, fn($a, $b) => [$a['service_date'], $this->order($a['service_name'])]
                                     <=> [$b['service_date'], $this->order($b['service_name'])]);
        $gatherings = array_reverse($this->ga->getAll($filters));

        $book = new Spreadsheet();
        $book->getProperties()->setCreator('Victory Bacolod Admin Portal')->setTitle('Church Health');
        $title = "Victory Bacolod — Church Health · $label · Exported " . date('M j, Y g:i A') . " by $user";

        $this->sheetSundays($book->getActiveSheet(), $services, $title);
        $this->sheetMonthly($book->createSheet(), $services, $gatherings, $title);
        $this->sheetGatherings($book->createSheet(), $gatherings, $title);
        $book->setActiveSheetIndex(0);
        return $book;
    }

    /**
     * One row per Sunday x service time, then a bold "Sunday total" row per
     * Sunday, and a grand total for the range at the bottom (the per-Sunday
     * total rows are not added into it twice).
     */
    private function sheetSundays($sheet, array $rows, string $title): void
    {
        $this->frame($sheet, 'Sundays by service', $title,
            ['Date', 'Service time', 'Sunday Service', 'Kids Church', 'Total'], [22, 16, 16, 14, 12]);
        $r = 4;
        $byDay = [];
        foreach ($rows as $row) $byDay[$row['service_date']][] = $row;
        $gSvc = 0; $gKids = 0; $gTot = 0; $gKidsAny = false;
        foreach ($byDay as $date => $list) {
            $dSvc = 0; $dKids = 0; $dTot = 0; $dKidsAny = false;
            foreach ($list as $row) {
                $this->dateCell($sheet, "A$r", $date);
                $sheet->setCellValue("B$r", $row['service_name']);
                $sheet->setCellValue("C$r", (int)$row['adults']);
                if ((int)$row['kids_recorded'] === 1) {
                    $sheet->setCellValue("D$r", (int)$row['kids']);
                    $dKids += (int)$row['kids']; $dKidsAny = true;
                }
                $sheet->setCellValue("E$r", (int)$row['grand_total']);
                $dSvc += (int)$row['adults']; $dTot += (int)$row['grand_total'];
                $r++;
            }
            $this->dateCell($sheet, "A$r", $date);
            $sheet->setCellValue("B$r", 'Sunday total');
            $sheet->setCellValue("C$r", $dSvc);
            if ($dKidsAny) $sheet->setCellValue("D$r", $dKids);
            $sheet->setCellValue("E$r", $dTot);
            $this->boldRow($sheet, $r, 5);
            $r++;
            $gSvc += $dSvc; $gKids += $dKids; $gTot += $dTot; $gKidsAny = $gKidsAny || $dKidsAny;
        }
        if (!$byDay) {
            $sheet->setCellValue("A$r", 'No Sunday attendance recorded in this range.');
            return;
        }
        $sheet->setCellValue("A$r", 'Total for range (' . count($byDay) . ' Sundays)');
        $sheet->setCellValue("C$r", $gSvc);
        if ($gKidsAny) $sheet->setCellValue("D$r", $gKids);
        $sheet->setCellValue("E$r", $gTot);
        $this->boldRow($sheet, $r, 5, 'DDE4FD');
        $sheet->setCellValue('A' . ($r + 2), 'Kids Church is blank where it was not counted (not zero).');
        $sheet->getStyle('A' . ($r + 2))->getFont()->setItalic(true);
    }

    /**
     * Per calendar month. Averages are per Sunday (all service times summed)
     * and per gathering; Kids Church averages only the Sundays it was counted
     * at (any service time), the same rule as the dashboard.
     */
    private function sheetMonthly($sheet, array $services, array $gatherings, string $title): void
    {
        $this->frame($sheet, 'Monthly summary', $title, [
            'Month', 'Sundays held', 'Sunday Service total', 'Sunday Service avg / Sunday',
            'Kids Church total', 'Sundays Kids Church counted', 'Kids Church avg / Sunday',
            'Prayer Meetings held', 'Prayer Meeting total', 'Prayer Meeting avg',
            "Students' Hangouts held", "Students' Hangout total", "Students' Hangout avg",
        ], [12, 10, 13, 14, 12, 14, 13, 12, 12, 12, 13, 13, 13]);
        $sheet->getStyle('A3:M3')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(3)->setRowHeight(45);

        $m = [];
        $blank = ['days' => [], 'kdays' => [], 'svc' => 0, 'kids' => 0,
                  'prayer_meeting' => [0, 0], 'students_hangout' => [0, 0]];
        foreach ($services as $s) {
            $k = substr($s['service_date'], 0, 7);
            $m[$k] = $m[$k] ?? $blank;
            $m[$k]['days'][$s['service_date']] = true;
            $m[$k]['svc'] += (int)$s['adults'];
            if ((int)$s['kids_recorded'] === 1) {
                $m[$k]['kids'] += (int)$s['kids'];
                $m[$k]['kdays'][$s['service_date']] = true;
            }
        }
        foreach ($gatherings as $g) {
            if (!isset($blank[$g['gathering_type']])) continue;
            $k = substr($g['event_date'], 0, 7);
            $m[$k] = $m[$k] ?? $blank;
            $m[$k][$g['gathering_type']][0]++;
            $m[$k][$g['gathering_type']][1] += (int)$g['attendance'];
        }
        ksort($m);
        $avg = fn($t, $n) => $n > 0 ? round($t / $n, 1) : null;
        $r = 4; $tot = array_fill(0, 13, 0);
        foreach ($m as $ym => $v) {
            $days = count($v['days']); $kd = count($v['kdays']);
            [$pn, $pt] = $v['prayer_meeting']; [$hn, $ht] = $v['students_hangout'];
            $this->dateCell($sheet, "A$r", "$ym-01", 'mmm yyyy');
            $sheet->fromArray([$days, $v['svc'], $avg($v['svc'], $days), $kd ? $v['kids'] : null, $kd,
                               $avg($v['kids'], $kd), $pn, $pt, $avg($pt, $pn), $hn, $ht, $avg($ht, $hn)], null, "B$r", true);
            foreach ([1 => $days, 2 => $v['svc'], 4 => $v['kids'], 5 => $kd, 7 => $pn, 8 => $pt, 10 => $hn, 11 => $ht] as $i => $n) $tot[$i] += $n;
            $r++;
        }
        if (!$m) { $sheet->setCellValue("A$r", 'Nothing recorded in this range.'); return; }
        $sheet->setCellValue("A$r", 'Total');
        $sheet->fromArray([$tot[1], $tot[2], $avg($tot[2], $tot[1]), $tot[5] ? $tot[4] : null, $tot[5], $avg($tot[4], $tot[5]),
                           $tot[7], $tot[8], $avg($tot[8], $tot[7]), $tot[10], $tot[11], $avg($tot[11], $tot[10])], null, "B$r", true);
        $this->boldRow($sheet, $r, 13, 'DDE4FD');
        $sheet->getStyle("D4:D$r")->getNumberFormat()->setFormatCode('0.0');
        foreach (['G', 'J', 'M'] as $c) $sheet->getStyle("{$c}4:{$c}$r")->getNumberFormat()->setFormatCode('0.0');
    }

    /** One row per gathering, oldest first, with a total per gathering type. */
    private function sheetGatherings($sheet, array $rows, string $title): void
    {
        $this->frame($sheet, 'Prayer & Hangout', $title, ['Date', 'Day', 'Gathering', 'Attendance', 'Notes'], [16, 12, 20, 12, 50]);
        $r = 4; $sum = [];
        foreach ($rows as $g) {
            $this->dateCell($sheet, "A$r", $g['event_date'], 'mmm d, yyyy');
            $sheet->setCellValue("B$r", date('l', strtotime($g['event_date'])));
            $sheet->setCellValue("C$r", GatheringAttendance::label($g['gathering_type']));
            $sheet->setCellValue("D$r", (int)$g['attendance']);
            $sheet->setCellValueExplicit("E$r", (string)($g['notes'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $t = $g['gathering_type'];
            $sum[$t] = [($sum[$t][0] ?? 0) + 1, ($sum[$t][1] ?? 0) + (int)$g['attendance']];
            $r++;
        }
        if (!$rows) { $sheet->setCellValue("A$r", 'No Prayer Meeting or Students\' Hangout counts in this range.'); return; }
        $r++;
        foreach (GatheringAttendance::TYPES as $t => $def) {
            [$n, $tt] = $sum[$t] ?? [0, 0];
            $sheet->setCellValue("C$r", $def['label'] . " total ($n held)");
            $sheet->setCellValue("D$r", $tt);
            $sheet->setCellValue("E$r", $n ? 'Average ' . round($tt / $n, 1) . ' per gathering' : '');
            $this->boldRow($sheet, $r, 5);
            $r++;
        }
    }

    /** Title in row 1, bold header in row 3, frozen below it, column widths. */
    private function frame($sheet, string $name, string $title, array $headers, array $widths): void
    {
        $sheet->setTitle($name);
        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->fromArray($headers, null, 'A3');
        $last = $this->col(count($headers));
        $sheet->getStyle("A3:{$last}3")->getFont()->setBold(true);
        $sheet->getStyle("A3:{$last}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDE4FD');
        $sheet->freezePane('A4');
        foreach ($widths as $i => $w) $sheet->getColumnDimension($this->col($i + 1))->setWidth($w);
    }

    private function col(int $n): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
    }

    private function boldRow($sheet, int $row, int $cols, string $rgb = 'F2F2F2'): void
    {
        $range = 'A' . $row . ':' . $this->col($cols) . $row;
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    private function dateCell($sheet, string $cell, string $ymd, string $fmt = 'ddd, mmm d, yyyy'): void
    {
        $sheet->setCellValue($cell, XlDate::PHPToExcel(new DateTime($ymd)));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($fmt);
    }

    private ?array $orderMap = null;

    /** Sort key of a service time, from services.sort_order. */
    private function order(string $name): int
    {
        if ($this->orderMap === null) {
            $this->orderMap = [];
            foreach ($this->sa->getServiceTimeOptions() as $i => $s) $this->orderMap[$s['name']] = $i;
        }
        return $this->orderMap[$name] ?? 99;
    }
}
