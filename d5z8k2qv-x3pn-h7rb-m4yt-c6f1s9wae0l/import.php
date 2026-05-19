<?php
/**
 * Victory Bacolod - Full Excel Importer (All 14 Sheets)
 * DELETE this file after import is complete.
 */
require_once 'config/config.php';
require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// ─── Helpers ────────────────────────────────────────────────
function gUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}
function clean(mixed $v): string { return trim(preg_replace('/\s+/',' ',(string)$v)); }
function yn(mixed $v): int { return in_array(strtolower(trim((string)$v)),['yes','1','true','y','done','p']) ? 1 : 0; }
function toStatus(mixed $v): string {
    $v = strtolower(clean($v));
    return in_array($v,['inactive','alumni','transferred','left','moved','inactive ']) ? 'inactive' : 'active';
}
function toTime(mixed $v): ?string {
    if (empty($v)) return null;
    $v = trim((string)$v);
    if (is_numeric($v)) {
        $s = round((float)$v * 86400);
        return sprintf('%02d:%02d:00', intdiv($s,3600), intdiv($s%3600,60));
    }
    $t = date_parse($v);
    if ($t && $t['hour'] !== false) return sprintf('%02d:%02d:00',$t['hour'],$t['minute']);
    return null;
}
function combineName(string $last, string $first): string {
    $last = clean($last); $first = clean($first);
    if (empty($last) && empty($first)) return '';
    if (empty($first)) return $last;
    if (empty($last)) return $first;
    // Clean trailing comma that sometimes appears (e.g. "Adolfo, ")
    $last = rtrim($last, ', ');
    return "$last, $first";
}

function findMemberId(PDO $db, string $last, string $first): ?int {
    if (empty($last)) return null;
    $combined = combineName($last, $first);
    if (empty($combined)) return null;
    // Try exact match "Last, First"
    $s = $db->prepare("SELECT id FROM members WHERE full_name = ? LIMIT 1");
    $s->execute([$combined]);
    if ($r = $s->fetch()) return (int)$r['id'];
    // Try "Last," prefix only
    $s = $db->prepare("SELECT id FROM members WHERE full_name LIKE ? LIMIT 1");
    $s->execute([rtrim($last,', ') . ', %']);
    if ($r = $s->fetch()) return (int)$r['id'];
    // Try partial: both parts somewhere in name
    $s = $db->prepare("SELECT id FROM members WHERE full_name LIKE ? AND full_name LIKE ? LIMIT 1");
    $s->execute(['%'.rtrim($last,', ').'%', '%'.$first.'%']);
    if ($r = $s->fetch()) return (int)$r['id'];
    return null;
}

// ─── Parse Members ──────────────────────────────────────────
function parseMembers(): array {
    $path = __DIR__ . '/files/DISCIPLESHIP FILE EST 2023.xlsx';
    $wb = IOFactory::load($path);
    $members = [];

    // --- Sheet: DISCIPLESHIP JOURNEY (primary, most complete) ---
    $ws = $wb->getSheetByName('DISCIPLESHIP JOURNEY');
    if ($ws) {
        $rows = $ws->toArray(null,true,true,false);
        // Find header row
        $hi = -1;
        foreach ($rows as $i => $r) {
            if (isset($r[0]) && strtoupper(clean((string)$r[0])) === 'NAME') { $hi = $i; break; }
        }
        if ($hi >= 0) {
            $SKIP = ['name','(family name, first name)','a-z','means completed classes',
                     'means not in victory bacolod','legend',''];
            for ($i = $hi+1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $name = clean($row[0] ?? '');
                if (empty($name) || strlen($name)<3) continue;
                if (in_array(strtolower($name),$SKIP)) continue;
                if (stripos($name,'means ')===0 || stripos($name,'(family')===0) continue;
                $members[$name] = [
                    'full_name'             => $name,
                    'civil_status'          => clean($row[1] ?? ''),
                    'victory_weekend'       => yn($row[2] ?? ''),
                    'church_community'      => yn($row[3] ?? ''),
                    'making_disciples'      => yn($row[4] ?? ''),
                    'empowering_leaders'    => yn($row[5] ?? ''),
                    'leadership_113'        => yn($row[6] ?? ''),
                    'purple_book_class'     => yn($row[7] ?? ''),
                    'spiritual_foundations' => yn($row[11] ?? ''),
                    'ministry'              => clean($row[8] ?? ''),
                    'volunteer_status'      => clean($row[9] ?? ''),
                    'member_status'         => toStatus($row[9] ?? 'active'),
                    'service_attending'     => clean($row[10] ?? ''),
                    'contact_number'        => clean($row[12] ?? ''),
                ];
            }
        }
    }

    // --- Sheet: VOLUNTEER'S DISCIPLESHIP JOURNE (supplement, col 0=name) ---
    // Sheet name uses a Unicode curly apostrophe (U+2019), so we search by keywords
    $ws2 = null;
    foreach ($wb->getSheetNames() as $_sn) {
        if (stripos($_sn, 'VOLUNTEER') !== false && stripos($_sn, 'DISCIPLESHIP') !== false) {
            $ws2 = $wb->getSheetByName($_sn); break;
        }
    }
    if ($ws2) {
        $rows2 = $ws2->toArray(null,true,true,false);
        for ($i = 1; $i < count($rows2); $i++) {
            $row = $rows2[$i];
            $name = clean($row[0] ?? '');
            if (empty($name) || strlen($name)<3) continue;
            if (isset($members[$name])) continue; // already captured
            $members[$name] = [
                'full_name'             => $name,
                'civil_status'          => clean($row[1] ?? ''),
                'victory_weekend'       => yn($row[2] ?? ''),
                'church_community'      => yn($row[3] ?? ''),
                'making_disciples'      => yn($row[4] ?? ''),
                'empowering_leaders'    => yn($row[5] ?? ''),
                'leadership_113'        => yn($row[6] ?? ''),
                'purple_book_class'     => 0,
                'spiritual_foundations' => 0,
                'ministry'              => clean($row[8] ?? ''),
                'volunteer_status'      => 'ACTIVE',
                'member_status'         => 'active',
                'service_attending'     => clean($row[7] ?? ''),
                'contact_number'        => '',
            ];
        }
    }
    return array_values($members);
}

// ─── Parse VG/LG Groups ─────────────────────────────────────
function parseGroups(): array {
    $path = __DIR__ . '/files/NEW VG & LG Schedules est 2025.xlsx';
    $wb = IOFactory::load($path);
    $ws = $wb->getSheetByName('Sheet1') ?? $wb->getActiveSheet();
    $rows = $ws->toArray(null,true,true,false);

    // Find header row: contains VG/LG and VICTORY GROUP LEADER
    $hi = -1;
    foreach ($rows as $i => $row) {
        $found = 0;
        foreach ($row as $cell) {
            $c = strtoupper(clean((string)$cell));
            if ($c === 'VG/LG' || str_contains($c,'VICTORY GROUP LEADER') || $c === 'DAY') $found++;
        }
        if ($found >= 2) { $hi = $i; break; }
    }
    if ($hi < 0) $hi = 1;

    // Map column indices from header
    $hrow = array_map(fn($h) => strtoupper(clean((string)$h)), $rows[$hi]);
    $col = ['type'=>-1,'day'=>-1,'time'=>-1,'location'=>-1,'leader'=>-1,
            'lg'=>-1,'intern'=>-1,'ig'=>-1,'attendees'=>-1,'category'=>-1,'freq'=>-1];
    foreach ($hrow as $ci => $h) {
        if ($h === 'VG/LG')                                      $col['type']      = $ci;
        elseif ($h === 'DAY')                                    $col['day']       = $ci;
        elseif ($h === 'TIME')                                   $col['time']      = $ci;
        elseif ($h === 'LOCATION')                               $col['location']  = $ci;
        elseif (str_contains($h,'VICTORY GROUP LEADER'))         $col['leader']    = $ci;
        elseif ($h === 'VGL(M/F)' || $h === 'VGL')              $col['lg']        = $ci;
        elseif ($h === 'INTERN' && $col['intern'] < 0)           $col['intern']    = $ci;
        elseif ($h === 'INTERN(M/F)')                            $col['ig']        = $ci;
        elseif (str_contains($h,'ATTENDEES'))                    $col['attendees'] = $ci;
        elseif ($h === 'GROUP CATEGORY')                         $col['category']  = $ci;
        elseif (str_contains($h,'FREQUENCY'))                    $col['freq']      = $ci;
    }

    // Build groups; handle multi-row structure where continuation rows
    // (empty type/leader) carry additional leaders, interns, or attendees.
    $groups = [];
    $cur = null;

    for ($i = $hi+1; $i < count($rows); $i++) {
        $row    = $rows[$i];
        $type   = $col['type']   >= 0 ? clean($row[$col['type']]   ?? '') : '';
        $leader = $col['leader'] >= 0 ? clean($row[$col['leader']] ?? '') : '';

        if (!empty($type) && !empty($leader)) {
            // New group row
            if ($cur !== null) $groups[] = $cur;
            $cur = [
                'group_type'        => $type,
                'group_category'    => strtolower(clean($col['category']>=0 ? ($row[$col['category']]??'') : '')),
                'day_of_week'       => clean($col['day']>=0 ? ($row[$col['day']]??'') : ''),
                'meeting_time'      => toTime($col['time']>=0 ? ($row[$col['time']]??'') : ''),
                'location'          => clean($col['location']>=0 ? ($row[$col['location']]??'') : ''),
                'meeting_frequency' => strtolower(clean($col['freq']>=0 ? ($row[$col['freq']]??'weekly') : 'weekly')),
                'group_status'      => 'active',
                'leaders'           => [],
                'interns'           => [],
                'attendees'         => [],
            ];
            // Leader from this row
            $cur['leaders'][] = [
                'name'   => $leader,
                'gender' => strtoupper(clean($col['lg']>=0 ? ($row[$col['lg']]??'') : '')),
            ];
        } elseif ($cur !== null) {
            // Continuation row — may have additional leader
            if (!empty($leader)) {
                $cur['leaders'][] = [
                    'name'   => $leader,
                    'gender' => strtoupper(clean($col['lg']>=0 ? ($row[$col['lg']]??'') : '')),
                ];
            }
        } else {
            // No current group yet and no type — skip
            continue;
        }

        // Intern on this row (works for both new-group and continuation rows)
        $internName = $col['intern'] >= 0 ? clean($row[$col['intern']] ?? '') : '';
        if (!empty($internName)) {
            $cur['interns'][] = [
                'name'   => $internName,
                'gender' => strtoupper(clean($col['ig']>=0 ? ($row[$col['ig']]??'') : '')),
            ];
        }

        // Attendee on this row
        $attendeeName = $col['attendees'] >= 0 ? clean($row[$col['attendees']] ?? '') : '';
        if (!empty($attendeeName)) {
            $cur['attendees'][] = ['name' => $attendeeName, 'gender' => ''];
        }
    }
    if ($cur !== null) $groups[] = $cur;

    return $groups;
}

// ─── Parse ALL Attendance Sheets ────────────────────────────
function parseAttendances(): array {
    $path = __DIR__ . '/files/DISCIPLESHIP FILE EST 2023.xlsx';
    $wb = IOFactory::load($path);
    $all = [];

    // ── Attendance sheet definitions ──
    // Each entry: [sheetName, type, year, ds, lastCol, firstCol, contactCol,
    //              counselorLastCol, counselorFirstCol, counselorContactCol,
    //              waterBaptismCol, ccFollowupCol, mdFollowupCol, elFollowupCol, label]
    $VW_SHEETS = [
        ['2023 VICTORY WEEKEND ',  'victory_weekend', 2023, 3, 1, 2, 5,  8, 9,  10, 11, 12, 13, 14, 'VICTORY WEEKEND MARCH 3-4, 2023'],
        ['2024 VICTORY WEEKEND',   'victory_weekend', 2024, 3, 1, 2, 3,  5, 6,  7,  8,  9,  10, 11, 'VICTORY WEEKEND MARCH 15 & 16, 2024'],
        ['2025 Victory Weekend',   'victory_weekend', 2025, 3, 2, 3, 4,  6, 7,  8,  9,  10, 11, 12, 'VICTORY WEEKEND FEB 28 & MARCH 1, 2025'],
    ];
    foreach ($VW_SHEETS as [$sheetName, $type, $year, $ds, $lc, $fc, $cc, $clc, $cfc, $ccc, $wbc, $ccCol, $mdCol, $elCol, $label]) {
        $ws = $wb->getSheetByName($sheetName);
        if (!$ws) {
            foreach ($wb->getSheetNames() as $sn) {
                if (strtoupper(trim($sn)) === strtoupper(trim($sheetName))) { $ws = $wb->getSheetByName($sn); break; }
            }
        }
        if (!$ws) continue;
        $rows = $ws->toArray(null,true,true,false);
        for ($i = $ds; $i < count($rows); $i++) {
            $row = $rows[$i];
            $last  = clean($row[$lc] ?? '');
            $first = clean($row[$fc] ?? '');
            if (empty($last) && empty($first)) continue;
            $name = combineName($last, $first);
            if (empty($name)) continue;
            $counselor = '';
            if ($clc >= 0 && $cfc >= 0) {
                $cl = clean($row[$clc] ?? ''); $cf = clean($row[$cfc] ?? '');
                if (!empty($cl)) $counselor = combineName($cl, $cf);
            }
            // Capture counselor contact only — CC/MD/EL follow-up columns were removed.
            $counselorContact = $ccc >= 0 ? clean($row[$ccc] ?? '') : '';
            $extra = $counselorContact ? json_encode(['counselor_contact' => $counselorContact]) : null;
            $all[] = [
                'raw_last_name'     => $last,
                'raw_first_name'    => $first,
                'full_name_display' => $name,
                'program_type'      => $type,
                'program_year'      => $year,
                'program_label'     => $label,
                'counselor_name'    => $counselor,
                'water_baptism'     => $wbc >= 0 ? yn($row[$wbc] ?? '') : 0,
                'contact_number'    => $cc >= 0 ? clean($row[$cc] ?? '') : '',
                'extra_data'        => $extra,
            ];
        }
    }

    // ── CC / MD / EL sheets (Last Name col 1, First Name col 2, Contact col 3) ──
    $SIMPLE_SHEETS = [
        [' 2023 CHURCH COMMUNITY',  'church_community',   2023, 2, 1, 2, 3, 'CHURCH COMMUNITY MAY 20, 2023'],
        ['2024 CHURCH COMMUNITY',   'church_community',   2024, 2, 1, 2, 3, 'CHURCH COMMUNITY APRIL 20, 2024'],
        ['2025 CHURCH COMMUNITY',   'church_community',   2025, 2, 1, 2, 3, 'CHURCH COMMUNITY MARCH 22, 2025'],
        ['2023 MAKING DISCIPLES',   'making_disciples',   2023, 4, 1, 2, 3, 'MAKING DISCIPLES JUNE 17, 2023'],
        ['2024 MAKING DISCIPLES',   'making_disciples',   2024, 4, 1, 2, 3, 'MAKING DISCIPLES JUNE 15, 2024'],
        ['EMPOWERING LEADERS 2023', 'empowering_leaders', 2023, 4, 1, 2, 3, 'EMPOWERING LEADERS OCTOBER 14, 2023'],
        ['EMPOWERING LEADERS 2024', 'empowering_leaders', 2024, 4, 1, 2, 3, 'EMPOWERING LEADERS NOVEMBER 9, 2024'],
    ];
    foreach ($SIMPLE_SHEETS as [$sheetName, $type, $year, $ds, $lc, $fc, $cc, $label]) {
        $ws = null;
        foreach ($wb->getSheetNames() as $sn) {
            if (strtoupper(trim($sn)) === strtoupper(trim($sheetName))) { $ws = $wb->getSheetByName($sn); break; }
        }
        if (!$ws) continue;
        $rows = $ws->toArray(null,true,true,false);
        for ($i = $ds; $i < count($rows); $i++) {
            $row = $rows[$i];
            $last  = clean($row[$lc] ?? '');
            $first = clean($row[$fc] ?? '');
            if (empty($last) && empty($first)) continue;
            $name = combineName($last, $first);
            if (empty($name)) continue;
            $all[] = [
                'raw_last_name'     => $last,
                'raw_first_name'    => $first,
                'full_name_display' => $name,
                'program_type'      => $type,
                'program_year'      => $year,
                'program_label'     => $label,
                'counselor_name'    => '',
                'water_baptism'     => 0,
                'contact_number'    => clean($row[$cc] ?? ''),
                'extra_data'        => null,
            ];
        }
    }

    // ── 2025 Making Disciples (has Part 1 / Part 2 columns) ──
    $ws = null;
    foreach ($wb->getSheetNames() as $sn) {
        if (stripos(trim($sn),'2025 MAKING DISCIPLES') !== false) { $ws = $wb->getSheetByName($sn); break; }
    }
    if ($ws) {
        $rows = $ws->toArray(null,true,true,false);
        for ($i = 3; $i < count($rows); $i++) {
            $row = $rows[$i];
            $last  = clean($row[1] ?? ''); $first = clean($row[2] ?? '');
            if (empty($last) && empty($first)) continue;
            $name = combineName($last, $first);
            if (empty($name)) continue;
            $extra = json_encode([
                'part1_april12' => yn($row[3] ?? ''),
                'part2_april26' => yn($row[4] ?? ''),
                'part2_1_july12'=> yn($row[5] ?? ''),
            ]);
            $all[] = [
                'raw_last_name'     => $last,
                'raw_first_name'    => $first,
                'full_name_display' => $name,
                'program_type'      => 'making_disciples',
                'program_year'      => 2025,
                'program_label'     => 'MAKING DISCIPLES APRIL 12 & 26, 2025',
                'counselor_name'    => '',
                'water_baptism'     => 0,
                'contact_number'    => '',
                'extra_data'        => $extra,
            ];
        }
    }

    // ── Leadership 113 Batch 5 2023 ──
    $ws = null;
    foreach ($wb->getSheetNames() as $sn) {
        if (stripos(trim($sn),'LEADERSHIP 113') !== false) { $ws = $wb->getSheetByName($sn); break; }
    }
    if ($ws) {
        $rows = $ws->toArray(null,true,true,false);
        // Row 0: session dates from col 4 onwards
        // Row 1: "Last Name", "First Name", "Remarks", labels...
        // Data starts row 2
        $dateRow = $rows[0] ?? [];
        $labelRow = $rows[1] ?? [];
        $sessionDates = [];
        for ($ci = 4; $ci < count($dateRow); $ci++) {
            $date = clean($dateRow[$ci] ?? '');
            if (!empty($date)) $sessionDates[$ci] = $date;
            elseif (!empty(clean($labelRow[$ci] ?? ''))) $sessionDates[$ci] = clean($labelRow[$ci] ?? '');
        }

        for ($i = 2; $i < count($rows); $i++) {
            $row = $rows[$i];
            $last  = clean($row[0] ?? ''); $first = clean($row[1] ?? '');
            if (empty($last) && empty($first)) continue;
            $name = combineName($last, $first);
            if (empty($name)) continue;
            // Build session attendance
            $sessions = [];
            foreach ($sessionDates as $ci => $date) {
                $val = clean($row[$ci] ?? '');
                if (!empty($val)) $sessions[$date] = $val;
            }
            $attended = count(array_filter($sessions, fn($v) => strtoupper($v) === 'P'));
            $total    = count($sessions);
            $extra = json_encode([
                'batch'        => 'Batch 5 2023',
                'sessions'     => $sessions,
                'attended'     => $attended,
                'total_sessions'=> $total,
                'remarks'      => clean($row[2] ?? ''),
            ]);
            $all[] = [
                'raw_last_name'     => $last,
                'raw_first_name'    => $first,
                'full_name_display' => $name,
                'program_type'      => 'leadership_113',
                'program_year'      => 2023,
                'program_label'     => 'LEADERSHIP 113 BATCH 5 2023',
                'counselor_name'    => '',
                'water_baptism'     => 0,
                'contact_number'    => '',
                'extra_data'        => $extra,
            ];
        }
    }

    return $all;
}

// ─── DB Imports ──────────────────────────────────────────────
function importMembers(PDO $db, array $members): array {
    $ins = 0; $skip = 0; $errors = [];
    // Auto-migrate: add spiritual_foundations if it doesn't exist yet
    try { $db->exec("ALTER TABLE members ADD COLUMN spiritual_foundations TINYINT(1) NOT NULL DEFAULT 0 AFTER purple_book_class"); } catch (PDOException $e) {}
    $stmt = $db->prepare("
        INSERT INTO members (uuid,full_name,civil_status,ministry,service_attending,
            contact_number,victory_weekend,church_community,making_disciples,
            empowering_leaders,leadership_113,purple_book_class,spiritual_foundations,
            member_status,volunteer_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            civil_status=VALUES(civil_status), ministry=VALUES(ministry),
            service_attending=VALUES(service_attending), contact_number=VALUES(contact_number),
            victory_weekend=VALUES(victory_weekend), church_community=VALUES(church_community),
            making_disciples=VALUES(making_disciples), empowering_leaders=VALUES(empowering_leaders),
            leadership_113=VALUES(leadership_113), purple_book_class=VALUES(purple_book_class),
            spiritual_foundations=VALUES(spiritual_foundations),
            member_status=VALUES(member_status), volunteer_status=VALUES(volunteer_status)
    ");
    foreach ($members as $m) {
        try {
            $stmt->execute([gUUID(),$m['full_name'],$m['civil_status'],$m['ministry'],
                $m['service_attending'],$m['contact_number'],$m['victory_weekend'],
                $m['church_community'],$m['making_disciples'],$m['empowering_leaders'],
                $m['leadership_113'],$m['purple_book_class'],$m['spiritual_foundations'] ?? 0,
                $m['member_status'],$m['volunteer_status']]);
            $ins++;
        } catch (PDOException $e) { $errors[] = $m['full_name'].': '.$e->getMessage(); $skip++; }
    }
    return compact('ins','skip','errors');
}

function importGroups(PDO $db, array $groups): array {
    $ins = 0; $skip = 0; $errors = [];
    // Clear existing data to avoid duplicates on re-import
    $db->exec("DELETE FROM vg_members");
    $db->exec("DELETE FROM victory_groups");

    $gStmt = $db->prepare("
        INSERT INTO victory_groups (uuid, group_type, group_category, day_of_week, meeting_time,
            location, meeting_frequency, group_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $mStmt = $db->prepare(
        "INSERT INTO vg_members (group_id, name, gender, role, sort_order) VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($groups as $g) {
        $firstLeader = !empty($g['leaders']) ? $g['leaders'][0]['name'] : 'unknown';
        try {
            $gStmt->execute([
                gUUID(), $g['group_type'], $g['group_category'], $g['day_of_week'],
                $g['meeting_time'], $g['location'], $g['meeting_frequency'], $g['group_status'],
            ]);
            $groupId = $db->lastInsertId();

            foreach ($g['leaders']   as $i => $m) { $mStmt->execute([$groupId, $m['name'], $m['gender'], 'leader',   $i]); }
            foreach ($g['interns']   as $i => $m) { $mStmt->execute([$groupId, $m['name'], $m['gender'], 'intern',   $i]); }
            foreach ($g['attendees'] as $i => $m) { $mStmt->execute([$groupId, $m['name'], '',           'attendee', $i]); }

            $ins++;
        } catch (PDOException $e) {
            $errors[] = $firstLeader . ': ' . $e->getMessage();
            $skip++;
        }
    }
    return compact('ins', 'skip', 'errors');
}

function importAttendances(PDO $db, array $attendances): array {
    $ins = 0; $skip = 0; $matched = 0; $errors = [];
    // Clear existing to avoid duplicates
    $db->exec("DELETE FROM program_attendances");
    $stmt = $db->prepare("
        INSERT INTO program_attendances
            (member_id,raw_last_name,raw_first_name,full_name_display,
             program_type,program_year,program_label,counselor_name,
             water_baptism,contact_number,extra_data)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    foreach ($attendances as $a) {
        $memberId = findMemberId($db, $a['raw_last_name'], $a['raw_first_name']);
        if ($memberId) $matched++;
        try {
            $stmt->execute([$memberId,$a['raw_last_name'],$a['raw_first_name'],
                $a['full_name_display'],$a['program_type'],$a['program_year'],
                $a['program_label'],$a['counselor_name'],$a['water_baptism'],
                $a['contact_number'],$a['extra_data']]);
            $ins++;
        } catch (PDOException $e) { $errors[] = $a['full_name_display'].': '.$e->getMessage(); $skip++; }
    }
    return compact('ins','skip','matched','errors');
}

// ─── Main Logic ──────────────────────────────────────────────
$do = $_GET['do'] ?? 'preview';
$db = getDB();
$parseError = '';
$members = $groups = $attendances = [];
$mRes = $gRes = $aRes = null;

if ($do === 'clear') {
    $db->exec("DELETE FROM program_attendances");
    $db->exec("DELETE FROM victory_groups");
    $db->exec("DELETE FROM members");
    header('Location: import.php'); exit();
}

try {
    $members     = parseMembers();
    $groups      = parseGroups();
    $attendances = parseAttendances();
} catch (Throwable $e) {
    $parseError = $e->getMessage();
}

if ($do === 'import' && !$parseError) {
    $mRes = importMembers($db, $members);
    $gRes = importGroups($db, $groups);
    $aRes = importAttendances($db, $attendances);
}

// Attendance breakdown per program/year for preview
$attSummary = [];
foreach ($attendances as $a) {
    $attSummary[$a['program_type']][$a['program_year']] = ($attSummary[$a['program_type']][$a['program_year']] ?? 0) + 1;
}

$PROG_LABELS = [
    'victory_weekend'=>'Victory Weekend','church_community'=>'Church Community',
    'making_disciples'=>'Making Disciples','empowering_leaders'=>'Empowering Leaders','leadership_113'=>'Leadership 113',
];
$PROG_COLORS = ['victory_weekend'=>'primary','church_community'=>'secondary','making_disciples'=>'success','empowering_leaders'=>'warning','leadership_113'=>'danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Victory Bacolod - Excel Importer</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body { background:#f0f2f8; font-family:'Segoe UI',sans-serif; }
.imp-header { background:linear-gradient(135deg,#1742f5,#070d63); }
.prog-row:hover { background:#f8f9ff; }
.session-grid { font-size:11px; }
.session-p  { background:#198754; color:white; padding:2px 4px; border-radius:3px; }
.session-a  { background:#dc3545; color:white; padding:2px 4px; border-radius:3px; }
.session-l  { background:#ffc107; color:#000; padding:2px 4px; border-radius:3px; }
.session-x  { background:#6c757d; color:white; padding:2px 4px; border-radius:3px; }
</style>
</head>
<body>
<div class="imp-header text-white py-3 mb-4">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <img src="images/victory-logo.png" style="height:48px;filter:brightness(0) invert(1);" alt="">
            <div>
                <h5 class="mb-0">Excel Data Importer — All Sheets</h5>
                <small class="text-white-50">Victory Bacolod Admin Portal &bull; Delete this file after import</small>
            </div>
        </div>
    </div>
</div>
<div class="container pb-5">

<?php if ($parseError): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><strong>Error:</strong> <?php echo htmlspecialchars($parseError); ?></div>
<?php endif; ?>

<?php if ($do === 'import' && $mRes): ?>
<!-- ── Import Results ── -->
<div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-2"></i>
    <strong>Import complete!</strong>
    <a href="index.php" class="btn btn-sm btn-success ms-3"><i class="bi bi-house me-1"></i>Go to Dashboard</a>
    <a href="import.php?do=clear" class="btn btn-sm btn-outline-danger ms-2"
       onclick="return confirm('Clear ALL data and re-import?')"><i class="bi bi-trash me-1"></i>Clear & Re-import</a>
</div>
<div class="row g-3 mb-4">
    <?php foreach ([['Members',$mRes,'primary','people'],['VG/LG Groups',$gRes,'info','diagram-3'],['Attendance Records',$aRes,'success','calendar-check']] as [$label,$res,$color,$icon]): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-<?php echo $color; ?> text-white"><i class="bi bi-<?php echo $icon; ?> me-2"></i><?php echo $label; ?></div>
            <div class="card-body text-center">
                <div class="h2 text-<?php echo $color; ?> mb-0"><?php echo $res['ins']; ?></div>
                <small class="text-muted">Imported</small>
                <?php if ($label==='Attendance Records'): ?>
                <div class="mt-1"><small class="text-success"><?php echo $res['matched']??0; ?> matched to members</small></div>
                <?php endif; ?>
                <?php if ($res['skip']>0): ?><div class="mt-1"><small class="text-danger"><?php echo $res['skip']; ?> errors</small></div><?php endif; ?>
                <?php if (!empty($res['errors'])): ?>
                <details class="mt-2"><summary class="text-danger small">Show errors</summary>
                <small><?php echo implode('<br>',array_map('htmlspecialchars',array_slice($res['errors'],0,10))); ?></small></details>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$parseError): ?>
<!-- ── Summary Stats ── -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <i class="bi bi-people display-4 text-primary"></i>
            <div class="h2 fw-bold"><?php echo count($members); ?></div>
            <small class="text-muted">Members to import</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <i class="bi bi-diagram-3 display-4 text-info"></i>
            <div class="h2 fw-bold"><?php echo count($groups); ?></div>
            <small class="text-muted">VG/LG groups to import</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center p-3">
            <i class="bi bi-calendar-check display-4 text-success"></i>
            <div class="h2 fw-bold"><?php echo count($attendances); ?></div>
            <small class="text-muted">Attendance records (all programs)</small>
        </div>
    </div>
</div>

<!-- ── Attendance Breakdown ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header" style="background:linear-gradient(to right,#1742f5,#070d63);color:white;">
        <i class="bi bi-bar-chart me-2"></i>Attendance Records by Program &amp; Year
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>Program</th>
                    <?php $years = [2023,2024,2025]; foreach($years as $y): ?><th class="text-center"><?php echo $y; ?></th><?php endforeach; ?>
                    <th class="text-center fw-bold">Total</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_keys($PROG_LABELS) as $pt): ?>
                <tr class="prog-row">
                    <td><span class="badge bg-<?php echo $PROG_COLORS[$pt]; ?> me-2">&nbsp;</span><?php echo $PROG_LABELS[$pt]; ?></td>
                    <?php $tot=0; foreach($years as $y): $c=$attSummary[$pt][$y]??0; $tot+=$c; ?>
                    <td class="text-center"><?php echo $c>0?"<strong>$c</strong>":'<span class="text-muted">—</span>'; ?></td>
                    <?php endforeach; ?>
                    <td class="text-center fw-bold text-primary"><?php echo $tot; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Members Preview ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header" style="background:linear-gradient(to right,#1742f5,#070d63);color:white;">
        <i class="bi bi-people me-2"></i>Members Preview (first 50 of <?php echo count($members); ?>)
    </div>
    <div class="card-body p-0">
        <div style="max-height:350px;overflow-y:auto;">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
                <tr>
                    <th>#</th><th>Full Name</th><th>Life Stage</th><th>Ministry</th>
                    <th>Vol. Status</th><th>Service Attending</th><th>Contact #</th>
                    <th>VW</th><th>CC</th><th>MD</th><th>EL</th><th>L113</th><th>PB</th><th>SF</th>
                    <th>Completion</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach(array_slice($members,0,50) as $i=>$m):
                $steps = ['victory_weekend','church_community','making_disciples','empowering_leaders','leadership_113'];
                $done = array_sum(array_map(fn($k)=>$m[$k], $steps));
            ?>
            <tr>
                <td><?php echo $i+1;?></td>
                <td class="fw-semibold"><?php echo htmlspecialchars($m['full_name']);?></td>
                <td><?php echo htmlspecialchars($m['civil_status']);?></td>
                <td><?php echo htmlspecialchars($m['ministry']);?></td>
                <td><span class="badge <?php echo $m['member_status']==='active'?'bg-success':'bg-secondary';?>"><?php echo htmlspecialchars($m['volunteer_status']?:ucfirst($m['member_status']));?></span></td>
                <td><?php echo htmlspecialchars($m['service_attending']) ?: '<span class="text-muted">—</span>'; ?></td>
                <td><?php echo htmlspecialchars($m['contact_number']) ?: '<span class="text-muted">—</span>'; ?></td>
                <?php foreach(['victory_weekend','church_community','making_disciples','empowering_leaders','leadership_113','purple_book_class','spiritual_foundations'] as $k): ?>
                <td class="text-center"><?php echo $m[$k]?'<i class="bi bi-check-circle-fill text-success"></i>':'<i class="bi bi-dash text-muted"></i>';?></td>
                <?php endforeach;?>
                <td class="text-center">
                    <?php if($done===5): ?>
                    <span class="badge bg-success">✓ Complete</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><?php echo $done;?>/5</span>
                    <?php endif;?>
                </td>
            </tr>
            <?php endforeach;?>
            <?php if(count($members)>50): ?><tr><td colspan="15" class="text-center text-muted">…and <?php echo count($members)-50;?> more</td></tr><?php endif;?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ── VG/LG Preview ── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header" style="background:linear-gradient(to right,#1742f5,#070d63);color:white;">
        <i class="bi bi-diagram-3 me-2"></i>VG/LG Groups Preview (first 20 of <?php echo count($groups);?>)
    </div>
    <div class="card-body p-0">
        <div style="max-height:250px;overflow-y:auto;">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light sticky-top">
                <tr><th>#</th><th>VG/LG</th><th>Category</th><th>Day</th><th>Time</th><th>Location</th><th>Leader(s)</th><th>Intern(s)</th><th>Attendees</th><th>Frequency</th></tr>
            </thead>
            <tbody>
            <?php foreach(array_slice($groups,0,20) as $i=>$g): ?>
            <tr>
                <td><?php echo $i+1;?></td>
                <td><span class="badge <?php echo str_contains($g['group_type'],'VG')?'bg-primary':'bg-info';?>"><?php echo htmlspecialchars($g['group_type']);?></span></td>
                <td><?php echo ucfirst(htmlspecialchars($g['group_category']));?></td>
                <td><?php echo htmlspecialchars($g['day_of_week']);?></td>
                <td><?php echo $g['meeting_time']?date('g:i A',strtotime($g['meeting_time'])):'—';?></td>
                <td><?php echo htmlspecialchars($g['location']);?></td>
                <td><?php foreach($g['leaders'] as $m): ?><?php echo htmlspecialchars($m['name']); if($m['gender']) echo ' <small>('.$m['gender'].')</small>'; ?><br><?php endforeach; ?></td>
                <td><?php foreach($g['interns'] as $m): ?><?php echo htmlspecialchars($m['name']); if($m['gender']) echo ' <small>('.$m['gender'].')</small>'; ?><br><?php endforeach; if(empty($g['interns'])) echo '—'; ?></td>
                <td><span class="badge bg-secondary"><?php echo count($g['attendees']);?></span> <?php echo implode(', ', array_map(fn($a)=>htmlspecialchars($a['name']), array_slice($g['attendees'],0,3))); if(count($g['attendees'])>3) echo '...'; ?></td>
                <td><?php echo ucfirst(htmlspecialchars($g['meeting_frequency']));?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php if($do !== 'import'): ?>
<!-- ── Import Button ── -->
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-4">
        <h5>Ready to Import All Data</h5>
        <p class="text-muted">
            <strong><?php echo count($members);?> members</strong> &bull;
            <strong><?php echo count($groups);?> VG/LG groups</strong> &bull;
            <strong><?php echo count($attendances);?> attendance records</strong> from all 14 sheets
        </p>
        <div class="d-flex justify-content-center gap-3">
            <a href="import.php?do=import" class="btn btn-primary btn-lg px-5"
               onclick="return confirm('Import <?php echo count($members)+count($groups)+count($attendances);?> total records? This will clear existing attendance & group data.')">
                <i class="bi bi-cloud-upload me-2"></i>Import All Data
            </a>
            <a href="import.php?do=clear" class="btn btn-outline-danger"
               onclick="return confirm('DELETE all existing members, groups, and attendance data?')">
                <i class="bi bi-trash me-1"></i>Clear First
            </a>
        </div>
        <div class="mt-3"><a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a></div>
    </div>
</div>
<?php endif;?>
<?php endif;?>
</div>
</body>
</html>
