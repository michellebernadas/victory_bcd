<?php
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/GroupMatcher.php';

/**
 * Tells the right people when someone is interested in joining a group.
 *
 *   leader  the group's notify_email addresses, plus the email of any leader
 *           linked to a member record. Gets the visitor's details and can reach
 *           out straight away.
 *   staff   JOIN_NOTIFY_EMAIL from .env (comma separated), a copy of every request
 *           so nothing depends on one leader reading email.
 *
 * Every attempt is written to join_request_notices, so the admin inbox can show
 * whether the leader was really told — and say plainly when there was no email
 * to send to, instead of implying someone was notified.
 * Never throws: a mail problem must not lose a request or break the form.
 */
class JoinNotifier {
    private static function emails($raw) {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = $e;
        }
        return $out;
    }

    private static function log(PDO $db, $requestId, $kind, $recipient, $status, $detail) {
        try {
            $db->prepare("INSERT INTO join_request_notices (request_id, kind, recipient, status, detail) VALUES (?,?,?,?,?)")
               ->execute([$requestId, $kind, $recipient ? mb_substr($recipient, 0, 255) : null, $status, $detail ? mb_substr($detail, 0, 500) : null]);
        } catch (PDOException $e) { error_log('JoinNotifier log: ' . $e->getMessage()); }
    }

    /** @return array summary: ['leader' => status|null, 'staff' => status|null] */
    public static function run(PDO $db, $requestId) {
        $summary = ['leader' => null, 'staff' => null];
        try {
            $st = $db->prepare("SELECT jr.*, vg.group_kind, vg.group_type, vg.group_category, vg.day_of_week, vg.meeting_time,
                                       vg.location, vg.notify_email
                                  FROM join_requests jr LEFT JOIN victory_groups vg ON vg.id = jr.group_id WHERE jr.id = ?");
            $st->execute([(int)$requestId]);
            $r = $st->fetch();
            if (!$r) return $summary;

            // Leaders: names for the greeting, emails from linked member records.
            $leaderNames = []; $leaderEmails = self::emails($r['notify_email'] ?? '');
            if ($r['group_id']) {
                $ls = $db->prepare("SELECT COALESCE(NULLIF(vm.display_name,''), vm.name) AS nm, m1.email AS e1, m2.email AS e2
                                      FROM vg_members vm
                                      LEFT JOIN members m1 ON m1.id = vm.member_id
                                      LEFT JOIN members m2 ON m2.id = vm.member_id_2
                                     WHERE vm.group_id = ? AND vm.role = 'leader' ORDER BY vm.sort_order, vm.id");
                $ls->execute([(int)$r['group_id']]);
                foreach ($ls->fetchAll() as $l) {
                    if (trim($l['nm']) !== '') $leaderNames[] = trim($l['nm']);
                    foreach ([$l['e1'], $l['e2']] as $e) foreach (self::emails($e) as $k => $v) $leaderEmails[$k] = $v;
                }
            }

            $stage  = GroupMatcher::LIFE_STAGES[$r['life_stage']] ?? ($r['life_stage'] ?: '—');
            $gender = GroupMatcher::GENDERS[$r['gender']] ?? ($r['gender'] ?: '—');
            $when   = trim(($r['day_of_week'] ?: '') . ($r['meeting_time'] ? ' at ' . date('g:i A', strtotime($r['meeting_time'])) : ''));
            $kind   = ($r['group_kind'] ?? '') === 'campus' ? 'Campus group' : 'Victory Group';
            $details = [
                'Name'        => $r['full_name'],
                'Mobile'      => $r['phone'] ?: '—',
                'Email'       => $r['email'] ?: '—',
                'Life stage'  => $stage . ' · ' . $gender,
                'Lives in'    => $r['area'] ?: '—',
                'Message'     => $r['message'] ?: '—',
            ];
            $groupLine = $r['group_id'] ? ($kind . ($when !== '' ? ' — ' . $when : '') . ($r['location'] ? ' — ' . $r['location'] : '')) : 'Not sure yet — needs help finding a group';

            $mk = function ($greeting, $intro) use ($details, $groupLine) {
                $text = $greeting . "\n\n" . $intro . "\n\nGroup: " . $groupLine . "\n\n";
                $html = '<p>' . htmlspecialchars($greeting) . '</p><p>' . htmlspecialchars($intro) . '</p><p><strong>Group:</strong> ' . htmlspecialchars($groupLine) . '</p><table cellpadding="6" style="border-collapse:collapse">';
                foreach ($details as $k => $v) {
                    $text .= str_pad($k . ':', 12) . $v . "\n";
                    $html .= '<tr><td style="color:#555">' . htmlspecialchars($k) . '</td><td><strong>' . nl2br(htmlspecialchars($v)) . '</strong></td></tr>';
                }
                $html .= '</table>';
                $text .= "\nPlease reach out as soon as you can — they are hoping to hear from you.\n\nVictory Bacolod";
                $html .= '<p>Please reach out as soon as you can &mdash; they are hoping to hear from you.</p><p>Victory Bacolod</p>';
                return [$text, $html];
            };
            $replyTo = filter_var($r['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $r['email'] : null;

            // ── Leader ──
            if ($r['group_id']) {
                if ($leaderEmails) {
                    $hi = 'Hi ' . ($leaderNames ? implode(' & ', array_slice($leaderNames, 0, 3)) : 'there') . ',';
                    list($t, $h) = $mk($hi, $r['full_name'] . ' is interested in joining your group.');
                    $res = Mailer::send(array_values($leaderEmails), 'Someone is interested in joining your group: ' . $r['full_name'], $t, $h, $replyTo);
                    self::log($db, (int)$r['id'], 'leader', implode(', ', $leaderEmails), $res['status'], $res['detail']);
                    $summary['leader'] = $res['status'];
                } else {
                    self::log($db, (int)$r['id'], 'leader', null, 'no_recipient',
                        'No email on file for ' . ($leaderNames ? implode(', ', $leaderNames) : 'this group\'s leaders') . '. Add one on the group, or contact them directly.');
                    $summary['leader'] = 'no_recipient';
                }
            }

            // ── Staff copy ──
            $staff = self::emails($_ENV['JOIN_NOTIFY_EMAIL'] ?? '');
            if ($staff) {
                list($t, $h) = $mk('Hello,', 'A new person is interested in joining a Victory Group.');
                $res = Mailer::send(array_values($staff), 'New interested person: ' . $r['full_name'], $t, $h, $replyTo);
                self::log($db, (int)$r['id'], 'staff', implode(', ', $staff), $res['status'], $res['detail']);
                $summary['staff'] = $res['status'];
            }
        } catch (Throwable $e) {
            error_log('JoinNotifier: ' . $e->getMessage());
        }
        return $summary;
    }
}
