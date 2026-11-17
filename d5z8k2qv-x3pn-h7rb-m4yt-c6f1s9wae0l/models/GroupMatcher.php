<?php
/**
 * Which groups suit which person: life stage + gender.
 *
 * Groups carry free-text categories chosen from vg_options (e.g. "single men",
 * "couples", "moms/single moms", "women: 40s-60s", "young adult", "campus"), so
 * the rules read the category text rather than a fixed list: a category an admin
 * adds later is understood if it uses the usual words, and otherwise counts as a
 * general adult group for its gender instead of vanishing.
 *
 * Used by the public join form (to narrow the group dropdown) and the Find a
 * group page (Life stage filter). Pure functions, no database.
 */
class GroupMatcher {
    const LIFE_STAGES = [
        'student'       => 'Student',
        'single'        => 'Single',
        'married'       => 'Married',
        'single_parent' => 'Single parent',
        'widowed'       => 'Widowed',
        'senior'        => 'Senior (60+)',
        'other'         => 'Other / not sure',
    ];
    const GENDERS = ['man' => 'Man', 'woman' => 'Woman'];

    /** Every real stage except 'other' (which matches anything). */
    private static function realStages() { return ['student', 'single', 'married', 'single_parent', 'widowed', 'senior']; }

    private static function haystack(array $g) {
        return strtolower(implode(' ', [
            $g['group_category'] ?? '', $g['audience'] ?? '', $g['group_type'] ?? '', $g['name'] ?? '',
        ]));
    }

    /** 'M', 'F' or 'any' — who the group is for. */
    public static function gender(array $g) {
        $t = self::haystack($g);
        $female = (bool)preg_match('/\b(women|woman|wives|wife|moms?|widows?|ladies|female)\b|vgwomen|campusf/', $t);
        $male   = (bool)preg_match('/\b(men|man|male|fathers?|dads?)\b|vgmen|campusm/', $t);
        if ($female && !$male) return 'F';
        if ($male && !$female) return 'M';
        return 'any';
    }

    /** The life stages a group is meant for. */
    public static function stages(array $g) {
        $t = self::haystack($g);
        $s = [];
        if (preg_match('/couple|wives|wife|husband/', $t))              $s[] = 'married';
        if (preg_match('/single (mom|dad|parent)|solo parent/', $t))    $s[] = 'single_parent';
        if (preg_match('/widow/', $t))                                  $s[] = 'widowed';
        if (preg_match('/\bmoms?\b/', $t) && !preg_match('/single (mom|dad|parent)/', $t)) {
            array_push($s, 'married', 'single_parent', 'widowed');
        }
        if (preg_match('/\bsingles?\b/', $t) && !preg_match('/single (mom|dad|parent)/', $t)) $s[] = 'single';
        if (preg_match('/young adult/', $t))                            array_push($s, 'single', 'student');
        if (($g['group_kind'] ?? '') === 'campus' || preg_match('/campus|student|youth/', $t)) $s[] = 'student';
        if (preg_match('/senior/', $t))                                 $s[] = 'senior';
        // Nothing specific said: a general adult group for its gender.
        if (!$s) $s = ['single', 'married', 'single_parent', 'widowed', 'senior'];
        return array_values(array_unique($s));
    }

    /** May a person of this stage and gender be offered this group? */
    public static function matches(array $g, $stage, $gender) {
        $gg = self::gender($g);
        if ($gender === 'man'   && $gg === 'F') return false;
        if ($gender === 'woman' && $gg === 'M') return false;
        if ($stage === '' || $stage === 'other' || !isset(self::LIFE_STAGES[$stage])) return true;
        return in_array($stage, self::stages($g), true);
    }
}
