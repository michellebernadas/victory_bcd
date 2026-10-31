<?php
require_once 'models/FacilityRequestImport.php';
require_once 'models/Facility.php';
require_once 'models/Ministry.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The downloadable one-row-per-request import template, built at request
 * time so its dropdowns carry the LIVE room and ministry lists.
 *
 *   Instructions — plain-language steps, every column explained, accepted
 *                  date / time formats, and the rule that importing only ever
 *                  creates SUBMITTED requests.
 *   Requests     — section row (A–F) + header row (the church's form wording),
 *                  frozen; one marked SAMPLE row the importer skips; date /
 *                  time formats and dropdowns on 500 rows.
 *   Lists        — hidden; the dropdown sources (rooms, ministries, types,
 *                  Yes/No), so a long room list never hits Excel's 255-char
 *                  inline-list limit.
 *
 * The columns come from FacilityRequestImport::TEMPLATE_COLUMNS — the same
 * definition the importer reads them back with. There are no review or
 * approval columns, so nothing can be imported as reviewed or approved.
 */
class FacilityImportTemplate {
    private $db;
    const DATA_ROWS = 500;            // rows that get formats + dropdowns
    const HEADER_ROW = 2;
    const SAMPLE_ROW = 3;

    public function __construct($db) { $this->db = $db; }

    public function build(): Spreadsheet {
        $rooms = [];
        foreach ((new Facility($this->db))->getAllFacilities() ?: [] as $f) $rooms[] = $f['name'];
        $ministries = [];
        foreach ((new Ministry($this->db))->getAllMinistries() ?: [] as $m) $ministries[] = $m['name'];
        $types = array_values(FacilityRequest::REQUEST_TYPES);

        $book = new Spreadsheet();
        $book->getProperties()->setTitle('Victory Bacolod — Facility Requests import template')
             ->setCreator('Victory Bacolod Admin Portal');

        $instr = $book->getActiveSheet();
        $instr->setTitle('Instructions');
        $req = $book->createSheet();
        $req->setTitle('Requests');
        $lists = $book->createSheet();
        $lists->setTitle('Lists');

        $this->writeLists($lists, $rooms, $ministries, $types);
        $this->writeRequests($req, $rooms, $ministries, $types);
        $this->writeInstructions($instr, $rooms, $ministries);

        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $book->setActiveSheetIndex(0);
        return $book;
    }

    private static function col(int $i): string { return Coordinate::stringFromColumnIndex($i); }

    private function writeLists(Worksheet $s, array $rooms, array $ministries, array $types): void {
        $cols = ['Rooms' => $rooms, 'Ministries' => $ministries, 'Types' => $types, 'YesNo' => ['Yes', 'No']];
        $c = 1;
        foreach ($cols as $head => $vals) {
            $s->setCellValue(self::col($c) . '1', $head);
            foreach (array_values($vals) as $i => $v) $s->setCellValueExplicit(self::col($c) . ($i + 2), $v,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $c++;
        }
    }

    /** "=Lists!$A$2:$A$8" for one list column, at least one row long. */
    private static function listRef(int $col, int $count): string {
        $L = self::col($col);
        return 'Lists!$' . $L . '$2:$' . $L . '$' . max(2, $count + 1);
    }

    private function writeRequests(Worksheet $s, array $rooms, array $ministries, array $types): void {
        $cols = FacilityRequestImport::TEMPLATE_COLUMNS;
        $last = self::col(count($cols) + 1);
        $first = self::HEADER_ROW + 1;
        $end   = self::HEADER_ROW + self::DATA_ROWS;

        // Column A: the note column. Its "SAMPLE" marker is what makes the
        // importer skip the example row.
        $s->setCellValue('A1', '');
        $s->setCellValue('A' . self::HEADER_ROW, FacilityRequestImport::TEMPLATE_NOTE_HEADER);
        $s->getColumnDimension('A')->setWidth(26);

        // Row 1: the form's sections A–F, merged over their columns.
        $sectionStart = null; $sectionName = null;
        foreach ($cols as $i => $c) {
            $L = self::col($i + 2);
            $s->setCellValue($L . self::HEADER_ROW, $c[1] . ($c[3] ? ' *' : ''));
            $s->getColumnDimension($L)->setWidth(in_array($c[4], ['date', 'time', 'number', 'yesno'], true) ? 15 : 24);
            if ($c[0] !== $sectionName) {
                if ($sectionName !== null) $s->mergeCells($sectionStart . '1:' . self::col($i + 1) . '1');
                $sectionName = $c[0]; $sectionStart = $L;
                $s->setCellValue($L . '1', $c[0]);
            }
            if ($c[5] !== '') {
                $s->getComment($L . self::HEADER_ROW)->getText()->createTextRun($c[5]);
            }
        }
        $s->mergeCells($sectionStart . '1:' . $last . '1');

        $s->getStyle('A1:' . $last . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '51154B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $s->getStyle('A' . self::HEADER_ROW . ':' . $last . self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE3EC']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['rgb' => '51154B']]],
        ]);
        $s->getRowDimension(self::HEADER_ROW)->setRowHeight(32);
        $s->freezePane('B' . (self::HEADER_ROW + 1));
        $s->setAutoFilter('A' . self::HEADER_ROW . ':' . $last . self::HEADER_ROW);

        // Formats + dropdowns on every data row.
        foreach ($cols as $i => $c) {
            $L = self::col($i + 2);
            $range = $L . $first . ':' . $L . $end;
            switch ($c[4]) {
                case 'date':   $s->getStyle($range)->getNumberFormat()->setFormatCode('mm/dd/yyyy'); break;
                case 'time':   $s->getStyle($range)->getNumberFormat()->setFormatCode('h:mm AM/PM'); break;
                case 'number': $s->getStyle($range)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER); break;
            }
            $list = null; $strict = false;
            if ($c[4] === 'room')     { $list = self::listRef(1, count($rooms));      $strict = true; }
            if ($c[4] === 'ministry') { $list = self::listRef(2, count($ministries)); }
            if ($c[4] === 'type')     { $list = self::listRef(3, count($types)); }
            if ($c[4] === 'yesno')    { $list = self::listRef(4, 2);                  $strict = true; }
            if ($list) {
                // A standalone object: getCell()->getDataValidation() would also
                // register a second, single-cell validation on the first row,
                // overlapping the range — which Excel reports as a corrupt file.
                $dv = new DataValidation();
                $dv->setType(DataValidation::TYPE_LIST)
                   ->setAllowBlank(true)
                   ->setShowDropDown(true)        // PhpSpreadsheet: true = show the in-cell arrow
                   ->setShowErrorMessage(true)
                   // Rooms and Yes/No must match exactly; ministry and type may
                   // be typed (a new ministry; "Facility / Room, Equipment").
                   ->setErrorStyle($strict ? DataValidation::STYLE_STOP : DataValidation::STYLE_INFORMATION)
                   ->setErrorTitle('Not in the list')
                   ->setError($strict ? 'Please pick a value from the list.' : 'Not in the list — it will be kept as typed.')
                   ->setFormula1($list);
                $s->setDataValidation($range, $dv);
            }
        }

        // The sample row — clearly marked, and skipped by the importer.
        $sat = strtotime('next saturday', strtotime('+14 days'));
        $sample = [
            'requestor_name' => 'Juan Dela Cruz', 'ministry' => $ministries[0] ?? 'ADMIN',
            'ministry_leader' => 'Maria Santos', 'contact_number' => '0917 000 0000', 'email' => 'juan@example.com',
            'request_type_raw' => 'Facility / Room', 'event_activity' => 'SAMPLE — Youth Fellowship Night',
            'purpose' => 'Engage — fellowship for new believers', 'expected_attendance' => 40,
            'facility_raw' => $rooms[0] ?? '', 'preferred_setup' => 'Classroom arrangement',
            'num_tables' => 4, 'num_chairs' => 40, 'equipment_requested' => 'Microphone and speaker',
            'equipment_quantity' => '1 pc. each', 'person_responsible' => 'Juan Dela Cruz',
            'technical_assistance_raw' => 'Yes', 'technical_assistance_details' => 'Sound check at 5:30 PM',
            'needs_setup_raw' => 'Yes', 'needs_technical_raw' => 'Yes', 'needs_security_raw' => 'No',
            'needs_cleaning_raw' => 'No', 'needs_other_raw' => 'No',
            'acknowledged_name' => 'Juan Dela Cruz', 'acknowledged_raw' => 'Yes',
        ];
        $r = self::SAMPLE_ROW;
        $s->setCellValue('A' . $r, 'SAMPLE — skipped on import. Replace or leave it.');
        foreach ($cols as $i => $c) {
            $L = self::col($i + 2) . $r;
            if ($c[2] === 'date_needed_raw') { $s->setCellValue($L, XlDate::PHPToExcel(date('Y-m-d', $sat))); continue; }
            if ($c[2] === 'start_time_raw')  { $s->setCellValue($L, 18 / 24); continue; }
            if ($c[2] === 'end_time_raw')    { $s->setCellValue($L, 21 / 24); continue; }
            if (isset($sample[$c[2]]))       $s->setCellValue($L, $sample[$c[2]]);
        }
        $s->getStyle('A' . $r . ':' . $last . $r)->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '6C757D']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']],
        ]);
    }

    private function writeInstructions(Worksheet $s, array $rooms, array $ministries): void {
        $s->getColumnDimension('A')->setWidth(34);
        $s->getColumnDimension('B')->setWidth(12);
        $s->getColumnDimension('C')->setWidth(90);
        $lines = [
            ['Victory Bacolod — Facility Requests import template', 'title'],
            ['', ''],
            ['IMPORTING ONLY CREATES SUBMITTED REQUESTS.', 'warn'],
            ['Every row becomes a request with the status Submitted. Nothing is reviewed, approved or confirmed by importing, and no room is reserved. Each request still goes through Facilities Review → Minister Approval → Facilities Confirmation in the portal. This template has no review or approval columns on purpose.', 'wrap'],
            ['', ''],
            ['How to use it', 'h'],
            ['1. Go to the Requests sheet. Row 1 shows the request-form sections A–F; row 2 names each column.', 'wrap'],
            ['2. Type one request per row, starting on row 4. Row 3 is a SAMPLE — it is skipped on import, so you may leave it or overwrite it.', 'wrap'],
            ['3. Columns marked * are required. A Facility / Room request also needs a room, a start time and an end time.', 'wrap'],
            ['4. Use the dropdowns for the room, type of request, ministry and Yes/No columns.', 'wrap'],
            ['5. Save the file (.xlsx) and upload it on the portal: Facility Booking → Import Request Forms.', 'wrap'],
            ['6. Check the preview. Rows with a problem are listed with their row number (e.g. "Row 7: end time is before start time") and are not imported until fixed. Nothing is saved until you press Confirm Import.', 'wrap'],
            ['', ''],
            ['Dates and times', 'h'],
            ['Dates: an Excel date (the column is already formatted), or typed as 11/15/2026 (month/day/year) or 2026-11-15. A day name after the date ("11/15/2026, Sunday") is fine.', 'wrap'],
            ['Times: an Excel time, or typed as 6:00 PM, 6PM, 18:00 or 12 NN. The end time must be after the start time.', 'wrap'],
            ['', ''],
            ['Columns', 'h'],
        ];
        $row = 1;
        foreach ($lines as [$text, $kind]) {
            $s->setCellValue('A' . $row, $text);
            if ($kind === 'title') $s->getStyle('A' . $row)->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('51154B');
            if ($kind === 'h')     $s->getStyle('A' . $row)->getFont()->setBold(true)->setSize(12);
            if ($kind === 'warn')  {
                $s->getStyle('A' . $row . ':C' . $row)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '842029']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8D7DA']],
                ]);
            }
            if ($kind === 'wrap' || $kind === 'warn') {
                $s->mergeCells('A' . $row . ':C' . $row);
                $s->getStyle('A' . $row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
                $s->getRowDimension($row)->setRowHeight(strlen($text) > 120 ? 45 : 30);
            }
            $row++;
        }
        $s->fromArray(['Column', 'Required', 'What it means'], null, 'A' . $row);
        $s->getStyle('A' . $row . ':C' . $row)->getFont()->setBold(true);
        $s->getStyle('A' . $row . ':C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDE3EC');
        $row++;
        $s->fromArray([FacilityRequestImport::TEMPLATE_NOTE_HEADER, '', 'Not imported. "SAMPLE" here marks the example row, which is skipped.'], null, 'A' . $row++);
        $section = null;
        foreach (FacilityRequestImport::TEMPLATE_COLUMNS as $c) {
            if ($c[0] !== $section) {
                $section = $c[0];
                $s->setCellValue('A' . $row, $section);
                $s->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setRGB('51154B');
                $row++;
            }
            $s->fromArray([$c[1], $c[3] ? 'Yes' : '', $c[5]], null, 'A' . $row);
            $s->getStyle('C' . $row)->getAlignment()->setWrapText(true);
            $row++;
        }
        $row++;
        $s->setCellValue('A' . $row, 'Rooms in the dropdown (live from the portal): ' . ($rooms ? implode(', ', $rooms) : 'none set up yet'));
        $s->mergeCells('A' . $row . ':C' . $row);
        $s->getStyle('A' . $row)->getAlignment()->setWrapText(true);
        $s->getRowDimension($row)->setRowHeight(30);
        $row++;
        $s->setCellValue('A' . $row, 'Ministries in the dropdown: ' . ($ministries ? implode(', ', $ministries) : 'none set up yet'));
        $s->mergeCells('A' . $row . ':C' . $row);
        $s->getStyle('A' . $row)->getAlignment()->setWrapText(true);
        $row++;
        $s->setCellValue('A' . $row, 'Generated ' . date('M j, Y g:i A') . '. Download a fresh copy if rooms or ministries change.');
        $s->getStyle('A' . $row)->getFont()->setItalic(true)->getColor()->setRGB('6C757D');
    }
}
