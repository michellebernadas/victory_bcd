<?php
/**
 * "Link to X?" suggestions for the Needs Link screen.
 *
 * SUGGESTIONS ONLY. Nothing in this class writes anything: it ranks existing
 * member records against a name as written on the schedule, and the admin
 * clicks a chip to confirm. A couple ("Amy & Carl") is split HERE, in memory,
 * purely to guess who each half might be — the stored display_name is never
 * split or rewritten, and no member is ever created.
 *
 * Scoring (highest wins, max 3 per slot):
 *   100  same first + last name
 *    80  same surname, first names start with the same 3+ letters
 *    60  same surname, same first initial
 *    40  same surname, when no first name was written to compare
 *        (a written first name that matches neither way is not suggested)
 *    50  mononym ("Derek") whose first name matches exactly
 *    35  mononym whose first name starts with the same 3+ letters
 */
class LinkSuggester
{
    private $bySurname = [];
    private $byFirstTok = [];
    private $members = [];

    public function __construct(array $members)
    {
        foreach ($members as $m) {
            $id    = (int)$m['id'];
            $last  = self::norm($m['last_name'] ?? '');
            $first = self::norm($m['first_name'] ?? '');
            if ($last === '' && $first === '' && strpos((string)$m['full_name'], ',') !== false) {
                [$last, $first] = array_map([self::class, 'norm'], explode(',', $m['full_name'], 2));
            }
            $this->members[$id] = ['id' => $id, 'full_name' => (string)$m['full_name'], 'first' => $first, 'last' => $last];
            if ($last !== '')  $this->bySurname[$last][] = $id;
            $tok = strtok($first, ' ');
            if ($tok !== false && $tok !== '') $this->byFirstTok[$tok][] = $id;
        }
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['ñ' => 'n', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', '.' => '']);
        return preg_replace('/\s+/', ' ', $s);
    }

    /** "Apostol, John" / "John Apostol" / "Derek" -> [first, last]. */
    public static function parse(string $name): array
    {
        $name = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $name));
        if (strpos($name, ',') !== false) {
            [$last, $first] = explode(',', $name, 2);
            return [self::norm($first), self::norm($last)];
        }
        $parts = preg_split('/\s+/', self::norm($name), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) <= 1) return [$parts[0] ?? '', ''];
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    /**
     * The name each slot of a couple string most likely refers to, for matching
     * only. "Hazel Lyn and Emmanuel Gabrido" -> Person 1 "Hazel Lyn Gabrido"
     * (the shared surname is borrowed for the guess), Person 2 "Emmanuel Gabrido".
     */
    public static function coupleHalves(string $written): array
    {
        $parts = preg_split('/\s*(?:&|\+|\band\b)\s*/iu', $written, 2);
        $a = trim($parts[0] ?? '');
        $b = trim($parts[1] ?? '');
        [$af, $al] = self::parse($a);
        [$bf, $bl] = self::parse($b);
        if ($al === '' && $bl !== '') $al = $bl;
        if ($bl === '' && $al !== '') $bl = $al;
        return [1 => [$af, $al], 2 => [$bf, $bl]];
    }

    /**
     * Up to $max suggestions for one slot: [['id'=>..,'full_name'=>..,'score'=>..], ...]
     *
     * @param int[] $excludeIds members already linked to the other half of this row
     */
    public function suggest(string $first, string $last, array $excludeIds = [], int $max = 3): array
    {
        $first = self::norm($first);
        $last  = self::norm($last);
        if ($first === '' && $last === '') return [];

        $scores = [];
        if ($last !== '') {
            foreach ($this->bySurname[$last] ?? [] as $id) {
                $m = $this->members[$id];
                $s = 40;
                if ($first !== '') {
                    // A first name was written, so a surname alone is not enough:
                    // "Ian Sy" must not suggest every other Sy in the church.
                    if ($m['first'] === $first) $s = 100;
                    elseif ($m['first'] !== '' && self::sharedPrefix($m['first'], $first) >= 3) $s = 80;
                    elseif ($m['first'] !== '' && $m['first'][0] === $first[0]) $s = 60;
                    else continue;
                }
                $scores[$id] = $s;
            }
        } else {
            $tok = strtok($first, ' ');
            foreach ($this->byFirstTok as $mt => $ids) {
                $exact = ($mt === $tok);
                if (!$exact && self::sharedPrefix($mt, $tok) < 3) continue;
                foreach ($ids as $id) $scores[$id] = max($scores[$id] ?? 0, $exact ? 50 : 35);
            }
        }
        foreach ($excludeIds as $x) unset($scores[(int)$x]);
        if (!$scores) return [];

        $ids = array_keys($scores);
        usort($ids, function ($a, $b) use ($scores) {
            return [$scores[$b], $this->members[$a]['full_name']] <=> [$scores[$a], $this->members[$b]['full_name']];
        });
        $out = [];
        foreach (array_slice($ids, 0, $max) as $id) {
            $out[] = ['id' => $id, 'full_name' => $this->members[$id]['full_name'], 'score' => $scores[$id]];
        }
        return $out;
    }

    /** Suggestions for both slots of one Needs Link row, keyed by slot. */
    public function suggestForRow(array $row): array
    {
        $written = (string)($row['written_name'] ?? '');
        if (!empty($row['is_couple'])) {
            $halves = self::coupleHalves($written);
            $m1 = (int)($row['member_id'] ?? 0);
            $m2 = (int)($row['member_id_2'] ?? 0);
            return [
                1 => $this->suggest($halves[1][0], $halves[1][1], $m2 ? [$m2] : []),
                2 => $this->suggest($halves[2][0], $halves[2][1], $m1 ? [$m1] : []),
            ];
        }
        [$f, $l] = self::parse($written);
        return [1 => $this->suggest($f, $l)];
    }

    private static function sharedPrefix(string $a, string $b): int
    {
        $n = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $n && $a[$i] === $b[$i]) $i++;
        return $i;
    }
}
