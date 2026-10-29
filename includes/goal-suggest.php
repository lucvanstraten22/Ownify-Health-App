<?php
/**
 * Voorgesteld: the goals the wizard suggests to one person, and the targets
 * it suggests for them (docs/GOALS.md › Suggestions). Ordinary code: the
 * person's own last four weeks, read the way a goal will read them later
 * (goal_source_by_day(), the complete-day rule for food, the workouts as the
 * training pages count them), the approved research (config/research.php,
 * chosen by age with the lists the insights use) and their combined insights
 * (docs/CORRELATIONS.md). No AI.
 *
 *   goal_suggest_facts()   what is known about the person — database reads
 *   goal_suggest_plan()    what to suggest and which targets — pure
 *   goal_suggest_apply()   the wizard's catalogue with it — pure
 *
 * What a target is:
 *
 *   research gives a level and the person is below it: the level, or — when
 *   it is far off — a big step towards it (never just "a little more than
 *   now": +10% at the least, and up to the level). The sentence says which.
 *   the person is between the level and where research sees the benefit
 *   flatten: the start of that plateau. Within the plateau: its top, said to
 *   be optional and ambitious. Beyond it: what they already do, to keep.
 *   no data: the research level itself, said to be general advice.
 *   no age: research with an age range cannot be applied; their own data
 *   only, or nothing (the wizard's own example stays).
 *
 * Missing data is never zero: a day without a reading is absent, and a
 * measurement with too few days has no personal average.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/goal-catalogue.php';
require_once __DIR__ . '/insights/rules.php';

if (!function_exists('goal_suggest_plan')) {

    /** config/goal-suggestions.php as written. */
    function goal_suggest_config(): array
    {
        static $config = null;

        return $config ??= (array) require dirname(__DIR__) . '/config/goal-suggestions.php';
    }

    /* ==================================================================
       FACTS — the database
       ================================================================== */

    /**
     * Everything the plan reads, as of $today (whose own day is not over:
     * the window ends yesterday).
     *
     * @return array<string, mixed>
     */
    function goal_suggest_facts(int $userId, DateTimeImmutable $today): array
    {
        require_once __DIR__ . '/goal-progress.php';
        require_once __DIR__ . '/goals.php';

        $cfg     = goal_suggest_config();
        $window  = (int) $cfg['window_days'];
        $from    = $today->modify('-' . $window . ' days')->format('Y-m-d');
        $to      = $today->modify('-1 day')->format('Y-m-d');
        $account = user_account($userId) ?? [];

        $targets = null;
        try {
            $targets = nutrition_targets_for_user($userId);
        } catch (Throwable $e) {
            $targets = null;
        }
        $energy = $targets['targets']['calorie_target_kcal'] ?? null;
        $energy = $energy !== null && (float) $energy > 0 ? (float) $energy : null;
        $weight = $account['weight'] ?? null;

        $facts = [
            'age'           => isset($account['age']) && $account['age'] !== null ? (int) $account['age'] : null,
            'gender'        => (string) ($account['gender'] ?? 'undisclosed'),
            'weight_kg'     => is_array($weight) && ($weight['unit'] ?? null) === 'kg' && (float) $weight['value'] > 0 ? (float) $weight['value'] : null,
            'energy_target' => $energy,
            'imperial'      => function_exists('units_imperial') ? units_imperial() : false,
            'focus'         => function_exists('setup_state') ? (string) (setup_state($userId)['focus'] ?? 'general') : 'general',
            'daily'         => [],
            'workouts'      => goal_suggest_workouts($userId, $today, $window),
            'nutrition'     => goal_suggest_nutrition($userId, $from, $to, $energy),
            'goals'         => goal_suggest_goal_history($userId),
            'patterns'      => goal_suggest_patterns($userId),
        ];

        /* Every other measurement a goal can read, day by day, over the same
           window (a body measurement: its latest within a year). */
        foreach (goal_measurements() as $code => $m) {
            $kind = (string) ($m['source_kind'] ?? 'manual');
            if ($kind !== 'metric' && $kind !== 'measurement') {
                continue;
            }
            $start = $kind === 'measurement' ? $today->modify('-365 days')->format('Y-m-d') : $from;
            $days  = goal_source_by_day($userId, $kind, (string) $m['source_key'], $start, $to);
            /* A day's total of nothing is a day without a reading. */
            $days  = array_filter($days, static fn ($v) => $v !== null && (float) $v > 0);
            if ($days !== []) {
                $facts['daily'][$code] = goal_suggest_stats(array_values($days));
            }
        }

        return $facts;
    }

    /** Count, mean, the 90th percentile and the latest of a list of day values (oldest first). */
    function goal_suggest_stats(array $values): array
    {
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);

        return [
            'days' => $n,
            'avg'  => array_sum($values) / $n,
            'p90'  => (float) $sorted[(int) max(0, min($n - 1, (int) ceil(0.9 * $n) - 1))],
            'last' => (float) $values[$n - 1],
        ];
    }

    /** Workouts as the training pages count them: per week over the window, their minutes, how hard, how many strength days. */
    function goal_suggest_workouts(int $userId, DateTimeImmutable $today, int $window): array
    {
        $fmt   = static fn (DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
        $start = $today->setTime(0, 0);
        [$recent] = health_workouts_counted(health_workout_records($userId, $fmt($start->modify('-' . $window . ' days')), $fmt($start)));
        [$long]   = health_workouts_counted(health_workout_records($userId, $fmt($start->modify('-90 days')), $fmt($start)));

        $weeks   = $window / 7;
        $minutes = (float) array_sum(array_map(static fn ($w) => (float) $w['minutes'], $recent));
        $hrMax   = health_hr_max($userId, $long);
        $hard    = 0.0;
        $strong  = [];
        $types   = (array) insights_config()['data']['strength_types'];
        foreach ($recent as $w) {
            if ((health_workout_effort($w, $hrMax)['class'] ?? null) === 'hard') {
                $hard += (float) $w['minutes'];
            }
            if (insights_is_strength((string) $w['type'], $types)) {
                $strong[date('Y-m-d', (int) $w['start'])] = true;
            }
        }

        return [
            'recorded'      => count($long),
            'per_week'      => count($recent) / $weeks,
            'minutes_week'  => $minutes / $weeks,
            'hard_week'     => $hard / $weeks,
            'session_min'   => $recent === [] ? null : $minutes / count($recent),
            'strength_week' => count($strong) / $weeks,
        ];
    }

    /**
     * Food over the days whose log looks complete (the insights' rule: the
     * three macronutrients and an energy near the person's own target). An
     * unfinished log is not a low day. Water is its own record: every day
     * that has one.
     */
    function goal_suggest_nutrition(int $userId, string $from, string $to, ?float $target): array
    {
        $share = (array) insights_config()['data']['complete_energy_share'];
        $codes = ['carbs', 'protein', 'fat', 'saturated_fat', 'fibre', 'sodium', 'sugar'];
        $by    = [];
        foreach ($codes as $code) {
            $by[$code] = health_daily_metric_days($userId, $code, $from, $to);
        }

        $sums = array_fill_keys(['energy', 'carbs', 'fat', 'protein', 'saturated_fat', 'fibre', 'sodium', 'sugar'], []);
        foreach (array_keys($by['carbs'] + $by['protein'] + $by['fat']) as $date) {
            [$c, $p, $f] = [$by['carbs'][$date] ?? null, $by['protein'][$date] ?? null, $by['fat'][$date] ?? null];
            if ($c === null || $p === null || $f === null || $target === null) {
                continue;
            }
            $kcal = $c * 4 + $p * 4 + $f * 9;
            if ($kcal < $target * (float) $share[0] || $kcal > $target * (float) $share[1]) {
                continue;
            }
            $sums['energy'][]  = $kcal;
            $sums['carbs'][]   = $c;
            $sums['fat'][]     = $f;
            $sums['protein'][] = $p;
            foreach (['saturated_fat', 'fibre', 'sodium', 'sugar'] as $code) {
                if (isset($by[$code][$date])) {
                    $sums[$code][] = (float) $by[$code][$date];
                }
            }
        }

        $out = ['complete_days' => count($sums['energy'])];
        foreach ($sums as $code => $values) {
            $out[$code] = $values === [] ? null : ['days' => count($values), 'avg' => array_sum($values) / count($values)];
        }

        return $out;
    }

    /**
     * Which measurements have a goal running (active or paused), and the
     * latest finished goal per measurement and type: what it asked and how
     * far it got.
     */
    function goal_suggest_goal_history(int $userId): array
    {
        $active  = [];
        $history = [];
        foreach (goals_for_user($userId, null) as $row) {
            $code = (string) ($row['measurement_code'] ?? '');
            if ($code === '') {
                continue;
            }
            if (in_array($row['status'], ['active', 'paused'], true)) {
                $active[$code] = true;
                continue;
            }
            $key = $row['goal_type'] === 'streak' ? 'streak_' . ($row['streak_variant'] ?? 'daily') : (string) $row['goal_type'];
            if (isset($history[$code][$key]) || $row['target_value'] === null && $row['frequency'] === null) {
                continue;   // newest first: the first one is the latest
            }
            $history[$code][$key] = [
                'target'    => $row['goal_type'] === 'streak' && $row['streak_variant'] === 'period' ? (float) $row['frequency'] : (float) $row['target_value'],
                'direction' => (string) $row['direction'],
                'pct'       => $row['final_pct'] === null ? null : (float) $row['final_pct'],
            ];
        }

        return ['active' => array_keys($active), 'history' => $history];
    }

    /** The combined insights with a pattern (Duidelijk patroon, Patroon). */
    function goal_suggest_patterns(int $userId): array
    {
        if (!is_file(__DIR__ . '/correlations/store.php')) {
            return [];
        }
        require_once __DIR__ . '/correlations/store.php';
        if (!corr_installed()) {
            return [];
        }
        $stored = corr_stored($userId);
        $out    = [];
        foreach ((array) ($stored['pairs'] ?? []) as $code => $pair) {
            if (in_array($pair['tier'] ?? null, ['clear', 'pattern'], true)) {
                $out[(string) $code] = (string) $pair['tier'];
            }
        }

        return $out;
    }

    /* ==================================================================
       THE PLAN — pure
       ================================================================== */

    function goal_suggest_round(float $value, float $step): float
    {
        return round(round($value / $step) * $step, 6);
    }

    function goal_suggest_up(float $value, float $step): float
    {
        return round(ceil(round($value / $step, 6)) * $step, 6);
    }

    function goal_suggest_down(float $value, float $step): float
    {
        return round(floor(round($value / $step, 6)) * $step, 6);
    }

    /**
     * Below a research level: the level, or — far off — a big step towards
     * it, and never less than 10% more than now (in $step).
     */
    function goal_suggest_towards(float $now, float $level, float $bigStep, float $step): float
    {
        return max(goal_suggest_up($now * 1.1, $step), min($level, goal_suggest_up($now + $bigStep, $step)));
    }

    /** A claim for this age from a config list, or null (no age: none with an age range). */
    function goal_suggest_claim(array $entries, ?int $age, array $claims): ?array
    {
        return insights_claim_for($entries, $age, $claims);
    }

    /**
     * What to suggest, per measurement, and in which order.
     *
     * @param array $facts         goal_suggest_facts()
     * @param array $measurements  config/goals.php `measurements` (codes => …)
     * @param array $claims        research_claims()
     * @return array{entries: array<string, array>, order: list<string>}
     */
    function goal_suggest_plan(array $facts, array $measurements, array $claims): array
    {
        $cfg     = goal_suggest_config();
        $entries = [];

        $rules = [
            'sleep_duration'  => 'goal_suggest_sleep',
            'steps'           => 'goal_suggest_steps',
            'workouts'        => 'goal_suggest_workouts_plan',
            'workout_minutes' => 'goal_suggest_workout_minutes',
            'water'           => 'goal_suggest_water',
            'protein'         => 'goal_suggest_protein',
            'fibre'           => 'goal_suggest_fibre',
            'sodium'          => 'goal_suggest_sodium',
            'saturated_fat'   => 'goal_suggest_saturated_fat',
            'sugar'           => 'goal_suggest_sugar',
            'resting_hr'      => 'goal_suggest_resting_hr',
        ];

        foreach ($measurements as $code => $m) {
            $entry = isset($rules[$code]) ? $rules[$code]($facts, $claims, $m) : null;
            $entry ??= goal_suggest_personal($code, $facts, $m);
            if ($entry === null) {
                continue;
            }
            $entry = goal_suggest_history($entry, $code, $facts, $m);
            $entries[$code] = $entry + ['code' => $code, 'category' => (string) $m['category']];
        }

        /* Their combined insights and their focus make a measurement more relevant. */
        foreach ((array) ($facts['patterns'] ?? []) as $pair => $tier) {
            foreach ((array) ($cfg['patterns']['pairs'][$pair] ?? []) as $code) {
                if (isset($entries[$code]) && $entries[$code]['score'] > 0 && $entries[$code]['state'] !== 'keep') {
                    $entries[$code]['score'] += (int) ($cfg['patterns']['weights'][$tier] ?? 0);
                    $entries[$code]['patterns'][] = $pair;
                }
            }
        }
        foreach ((array) ($cfg['focus'][$facts['focus'] ?? 'general'] ?? []) as $category => $weight) {
            foreach ($entries as $code => $entry) {
                if ($entry['category'] === $category && $entry['score'] > 0) {
                    $entries[$code]['score'] += (int) $weight;
                }
            }
        }

        return ['entries' => $entries, 'order' => goal_suggest_order($entries, $facts, $cfg)];
    }

    /**
     * Voorgesteld: the highest scores, at most per_category from one
     * category and one of each overlap group, nothing a running goal
     * already covers — and, when there is one, at least one thing the
     * person already does well, to keep.
     *
     * @return list<string>
     */
    function goal_suggest_order(array $entries, array $facts, array $cfg): array
    {
        $active  = (array) ($facts['goals']['active'] ?? []);
        $groupOf = [];
        foreach ((array) $cfg['overlap'] as $i => $group) {
            foreach ($group as $code) {
                $groupOf[$code] = $i;
            }
        }
        $blocked = [];
        foreach ($active as $code) {
            $blocked[$code] = true;
            if (isset($groupOf[$code])) {
                foreach ((array) $cfg['overlap'][$groupOf[$code]] as $other) {
                    $blocked[$other] = true;
                }
            }
        }

        $fallback = array_flip((array) $cfg['fallback']);
        $pool = array_filter($entries, static fn ($e) => $e['score'] > 0 && !isset($blocked[$e['code']]));
        uasort($pool, static function (array $a, array $b) use ($fallback): int {
            return [$b['score'], $fallback[$a['code']] ?? 99] <=> [$a['score'], $fallback[$b['code']] ?? 99];
        });

        $order = [];
        $perCategory = [];
        $groups = [];
        $take = static function (array $e) use (&$order, &$perCategory, &$groups, $groupOf, $cfg): bool {
            $cat = $e['category'];
            $grp = $groupOf[$e['code']] ?? null;
            if (($perCategory[$cat] ?? 0) >= (int) $cfg['per_category'] || ($grp !== null && isset($groups[$grp]))) {
                return false;
            }
            $order[] = $e['code'];
            $perCategory[$cat] = ($perCategory[$cat] ?? 0) + 1;
            if ($grp !== null) {
                $groups[$grp] = true;
            }
            return true;
        };

        foreach ($pool as $e) {
            if (count($order) >= (int) $cfg['list']) {
                break;
            }
            $take($e);
        }

        /* Something they already do well, when there is one and none is listed. */
        $kept = array_filter($order, static fn ($c) => ($entries[$c]['state'] ?? null) === 'keep');
        if ($kept === []) {
            foreach ($pool as $e) {
                if ($e['state'] === 'keep' && !in_array($e['code'], $order, true)) {
                    if (count($order) >= (int) $cfg['list']) {
                        array_pop($order);
                    }
                    $order[] = $e['code'];
                    break;
                }
            }
        }

        /* Little known: the general order fills up, never what is running. */
        foreach ((array) $cfg['fallback'] as $code) {
            if (count($order) >= 4) {
                break;
            }
            if (!isset($blocked[$code]) && !in_array($code, $order, true) && (!isset($groupOf[$code]) || !isset($groups[$groupOf[$code]]))) {
                $order[] = $code;
            }
        }

        return $order;
    }

    /* ----------------------------------------------------- per measurement */

    /**
     * An entry: its relevance, how it was worked out, the targets per kind
     * of goal (stored units; a streak per period: times), the direction,
     * which kind fits best, and the two texts — short for the list, long
     * for under the target.
     */
    function goal_suggest_entry(int $score, string $state, string $basis, array $targets, string $primary, string $direction,
                                string $reason, string $why, ?string $claim = null): array
    {
        return compact('score', 'state', 'basis', 'targets', 'primary', 'direction', 'reason', 'why', 'claim') + ['patterns' => []];
    }

    /** A night's length as the insights write it: 6:40. */
    function goal_suggest_clock(float $minutes): string
    {
        $m = (int) round($minutes);

        return sprintf('%d:%02d', intdiv($m, 60), $m % 60);
    }

    function goal_suggest_text(float $stored, string $unitCode, ?int $decimals = null): string
    {
        $unit = goal_unit($unitCode);

        return $unit === null ? i18n_number_trim($stored, 1) : goal_value_text($stored, $unit, $decimals);
    }

    /** Sleep duration: the band for the person's age (CDC, AASM/SRS, NSF, AASM paediatric). */
    function goal_suggest_sleep(array $f, array $claims, array $m): ?array
    {
        $ins   = insights_config()['rules']['sleep.duration'];
        $age   = $f['age'];
        $claim = goal_suggest_claim($ins['claims'], $age, $claims);
        $own   = $f['daily']['sleep_duration'] ?? null;
        $have  = $own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['sleep'];
        $band  = $claim === null ? [] : insights_band_of($claim, $age)[0];
        $min   = isset($band['min']) ? (float) $band['min'] * 60 : null;
        $max   = isset($band['max']) ? (float) $band['max'] * 60 : null;
        $key   = $claim['claim_key'] ?? null;
        /* The adults' claim (AASM/SRS) names no upper end; the NSF's does. */
        if ($min !== null && $max === null && isset($claims['sleep.duration.nsf']) && research_age_fits($claims['sleep.duration.nsf'], $age)) {
            $nsf = insights_band_of($claims['sleep.duration.nsf'], $age)[0];
            $max = isset($nsf['max']) ? (float) $nsf['max'] * 60 : null;
        }

        $milestone = static fn (float $t): float => $max === null ? $t + 30 : min($max, $t + 30);

        if ($have && $min !== null) {
            $a = (float) $own['avg'];
            if ($a < $min - 1) {
                $t   = max(goal_suggest_up($a + 30, 30), min($min, goal_suggest_up($a + 90, 30)));
                $why = tf('Je sliep de afgelopen %1$d nachten gemiddeld %2$s. Voor jouw leeftijd raadt onderzoek minstens %3$s per nacht aan.',
                    (int) $own['days'], goal_suggest_clock($a), goal_suggest_text($min, 'h'));
                if ($t < $min) {
                    $why .= ' ' . t('Dit doel is een flinke eerste stap daarheen.');
                }
                $score = 80 + (int) min(15, ($min - $a) / 6);
                return goal_suggest_entry($score, 'grow', 'personal', ['streak' => $t, 'milestone' => $milestone($t)], 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', goal_suggest_clock($a), goal_suggest_text($t, 'h')), $why, $key);
            }
            if ($max !== null && $a > $max + 30) {
                return null;   // more than the band: nothing to grow, and nothing to cut on Ownify's say-so
            }
            $t = max($min, goal_suggest_down($a, 30));
            return goal_suggest_entry(30, 'keep', 'personal', ['streak' => $t, 'milestone' => $milestone($t)], 'streak', 'increase',
                tf('Gaat al goed: gemiddeld %s', goal_suggest_clock($a)),
                tf('Je sliep de afgelopen %1$d nachten gemiddeld %2$s, binnen wat voor jouw leeftijd wordt aangeraden (minstens %3$s). Dit doel houdt dat vast.',
                    (int) $own['days'], goal_suggest_clock($a), goal_suggest_text($min, 'h')), $key);
        }

        if ($min !== null) {
            return goal_suggest_entry(40, 'general', 'general', ['streak' => $min, 'milestone' => $milestone($min)], 'streak', 'increase',
                tf('Algemeen advies: %s per nacht', goal_suggest_text($min, 'h')),
                tf('Algemeen advies voor jouw leeftijd: minstens %s slaap per nacht. Ownify heeft nog te weinig nachten van je, dus dit is nog niet op jou afgestemd.',
                    goal_suggest_text($min, 'h')), $key);
        }

        return $have ? goal_suggest_own('sleep_duration', $own, $m) : null;
    }

    /** Steps: Ding's dose–response reference, then Paluch's plateau for the age. 10.000 is not a minimum. */
    function goal_suggest_steps(array $f, array $claims, array $m): ?array
    {
        $ins    = insights_config()['rules']['activity.steps'];
        $age    = $f['age'];
        $ding   = goal_suggest_claim($ins['claims'], $age, $claims);
        $paluch = goal_suggest_claim($ins['plateau'], $age, $claims);
        $own    = $f['daily']['steps'] ?? null;
        $have   = $own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['daily'];
        $ref    = $ding === null ? null : (float) ($ding['finding']['reference_steps'] ?? 0);
        $band   = $paluch === null ? [] : insights_band_of($paluch, $age)[0];
        $flat   = isset($band['plateau']) ? [(float) $band['plateau'][0], (float) $band['plateau'][1]] : null;
        $n      = static fn (float $v): string => i18n_number(goal_suggest_round($v, 100));
        $steps  = static fn (float $v): string => goal_suggest_text($v, 'steps');

        $targets = static function (float $t) use ($own): array {
            $best = $own === null ? 0.0 : (float) $own['p90'];
            return [
                'streak'     => $t,
                'milestone'  => goal_suggest_up(max($best * 1.1, $t * 1.25), 1000),
                'accumulate' => goal_suggest_up($t * 30, 10000),
            ];
        };

        if ($have && $ref) {
            $a = (float) $own['avg'];
            $days = (int) $own['days'];
            /* Within the plateau for the age (from 60 it starts below
               Ding's reference): its top, or keep. */
            if ($flat !== null && $a >= $flat[0] && $a >= $flat[1]) {
                $t = goal_suggest_down($a, 500);
                return goal_suggest_entry(30, 'keep', 'personal', $targets($t), 'streak', 'increase',
                    tf('Gaat al goed: gemiddeld %s', $n($a)),
                    tf('Je zet gemiddeld %s stappen, meer dan waar onderzoek voor jouw leeftijd nog extra winst ziet. Dit doel houdt vast wat je al doet.', $n($a)),
                    $paluch['claim_key']);
            }
            if ($flat !== null && $a >= $flat[0] && $flat[0] < $ref) {
                $t = $flat[1];
                return goal_suggest_entry(45, 'stretch', 'personal', $targets($t), 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', $n($a), $steps($t)),
                    tf('Je zet al gemiddeld %1$s stappen. Tot ongeveer %2$s stappen ziet onderzoek bij jouw leeftijd nog wat winst: een ambitieus doel, geen ondergrens.',
                        $n($a), i18n_number($flat[1])), $paluch['claim_key']);
            }
            if ($a < $ref) {
                $t   = goal_suggest_towards($a, $ref, 3000, 500);
                $why = tf('Je zette de afgelopen %1$d dagen gemiddeld %2$s stappen. Onderzoek ziet de grootste gezondheidswinst oplopen tot ongeveer %3$s stappen per dag.',
                    $days, $n($a), i18n_number($ref));
                $why .= ' ' . ($t < $ref ? t('Dit doel is een stevige stap die kant op.') : t('Daar ligt dit doel.'));
                $score = 75 + (int) min(20, ($ref - $a) / $ref * 40);
                return goal_suggest_entry($score, 'grow', 'personal', $targets($t), 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', $n($a), $steps($t)), $why, $ding['claim_key']);
            }
            if ($flat !== null && $a < $flat[0]) {
                $t = $flat[0];
                return goal_suggest_entry(60, 'toward', 'personal', $targets($t), 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', $n($a), $steps($t)),
                    tf('Je zette gemiddeld %1$s stappen, al boven de %2$s waar onderzoek de grootste winst ziet. Voor jouw leeftijd vlakt de winst af tussen %3$s en %4$s stappen; dit doel ligt aan het begin daarvan.',
                        $n($a), i18n_number($ref), i18n_number($flat[0]), i18n_number($flat[1])), $paluch['claim_key']);
            }
            if ($flat !== null && $a < $flat[1]) {
                $t = $flat[1];
                return goal_suggest_entry(45, 'stretch', 'personal', $targets($t), 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', $n($a), $steps($t)),
                    tf('Je zet al gemiddeld %1$s stappen. Tot ongeveer %2$s stappen ziet onderzoek bij jouw leeftijd nog wat winst: een ambitieus doel, geen ondergrens.',
                        $n($a), i18n_number($flat[1])), $paluch['claim_key']);
            }
            $t = goal_suggest_down($a, 500);
            return goal_suggest_entry(30, 'keep', 'personal', $targets($t), 'streak', 'increase',
                tf('Gaat al goed: gemiddeld %s', $n($a)),
                tf('Je zet gemiddeld %s stappen, meer dan waar onderzoek voor jouw leeftijd nog extra winst ziet. Dit doel houdt vast wat je al doet.', $n($a)),
                ($paluch ?? $ding)['claim_key']);
        }

        if ($ref) {
            return goal_suggest_entry(35, 'general', 'general', $targets($ref), 'streak', 'increase',
                tf('Algemeen advies: %s per dag', $steps($ref)),
                tf('Algemeen advies voor volwassenen: onderzoek ziet de grootste gezondheidswinst oplopen tot ongeveer %s stappen per dag. Ownify heeft nog te weinig stappen van je, dus dit is nog niet op jou afgestemd.',
                    i18n_number($ref)), $ding['claim_key']);
        }

        return $have ? goal_suggest_own('steps', $own, $m) : null;
    }

    /**
     * The weekly minutes the movement guideline for this age asks
     * (Beweegrichtlijnen youth: 60 a day; WHO 2020 18–64: 150–300 with a hard
     * minute counting double; 65+: 150), or null.
     *
     * @return ?array{low: float, high: ?float, double: bool, older: bool, claim_key: string}
     */
    function goal_suggest_guideline(array $f, array $claims): ?array
    {
        $ins   = insights_config()['rules']['activity.minutes'];
        $claim = goal_suggest_claim($ins['claims'], $f['age'], $claims);
        if ($claim === null) {
            return null;
        }
        $finding = (array) $claim['finding'];
        if (isset($finding['moderate_minutes_day'])) {
            return ['low' => (float) $finding['moderate_minutes_day'] * 7, 'high' => null, 'double' => false, 'older' => false,
                    'day' => (float) $finding['moderate_minutes_day'], 'claim_key' => $claim['claim_key']];
        }
        if (!isset($finding['moderate_minutes_week'])) {
            return null;
        }
        $week = $finding['moderate_minutes_week'];

        return [
            'low'    => (float) (is_array($week) ? $week[0] : $week),
            'high'   => is_array($week) ? (float) $week[1] : null,
            'double' => !empty($finding['vigorous_counts_double']),
            'older'  => ($f['age'] ?? 0) >= 65,
            'day'    => null,
            'claim_key' => $claim['claim_key'],
        ];
    }

    /** Trainingen per week: enough sessions of the person's own length to reach the guideline. */
    function goal_suggest_workouts_plan(array $f, array $claims, array $m): ?array
    {
        $w     = $f['workouts'];
        $guide = goal_suggest_guideline($f, $claims);
        $have  = $w['recorded'] >= (int) goal_suggest_config()['min_days']['workouts'];
        $words = static fn (int $n): string => tf('%1$s keer per %2$s', $n, t('week'));

        if ($have && $guide !== null) {
            $per  = (float) $w['per_week'];
            $len  = max(20.0, (float) ($w['session_min'] ?? 45));
            $eff  = (float) $w['minutes_week'] + ($guide['double'] ? (float) $w['hard_week'] : 0.0);
            $base = tf('Je trainde de afgelopen vier weken gemiddeld %1$s keer per week, samen %2$s minuten.',
                i18n_number_trim($per, 1), i18n_number(goal_suggest_round($w['minutes_week'], 5)));

            if ($eff < $guide['low']) {
                $n   = (int) max(2, min(6, max((int) ceil($per - 1e-9) + 1, (int) ceil($guide['low'] / $len))));
                $n   = $guide['older'] ? max(3, $n) : $n;
                $why = $base . ' ' . tf('De beweegrichtlijn voor jouw leeftijd is minstens %1$s minuten per week; met trainingen van ongeveer %2$s minuten haal je dat met %3$s keer.',
                    i18n_number($guide['low']), i18n_number(goal_suggest_round($len, 5)), i18n_number($n));
                $score = 75 + (int) min(15, ($guide['low'] - $eff) / $guide['low'] * 30);
                return goal_suggest_entry($score, 'grow', 'personal', ['period' => $n, 'streak' => 1, 'accumulate' => $n * 4], 'period', 'increase',
                    tf('Nu %1$s per week · voorstel %2$s', i18n_number_trim($per, 1), $words($n)), $why . goal_suggest_strength_note($f, $guide), $guide['claim_key']);
            }
            if ($guide['high'] !== null && $eff < $guide['high']) {
                $n = (int) min(6, max(1, (int) round($per)) + 1);
                return goal_suggest_entry(45, 'stretch', 'personal', ['period' => $n, 'streak' => 1, 'accumulate' => $n * 4], 'period', 'increase',
                    tf('Nu %1$s per week · voorstel %2$s', i18n_number_trim($per, 1), $words($n)),
                    $base . ' ' . tf('Je haalt de richtlijn van %1$s minuten al; tot %2$s minuten per week ziet de WHO nog extra gezondheidswinst. Een ambitieus, optioneel doel.',
                        i18n_number($guide['low']), i18n_number($guide['high'])), $guide['claim_key']);
            }
            $n = (int) max(1, min(7, (int) round($per)));
            return goal_suggest_entry(30, 'keep', 'personal', ['period' => $n, 'streak' => 1, 'accumulate' => $n * 4], 'period', 'increase',
                tf('Gaat al goed: %s per week', i18n_number_trim($per, 1)),
                $base . ' ' . t('Dat is meer dan de beweegrichtlijn vraagt. Dit doel houdt vast wat je al doet.'), $guide['claim_key']);
        }

        if ($guide !== null && $guide['day'] === null) {
            $n = 3;
            return goal_suggest_entry(35, 'general', 'general', ['period' => $n, 'streak' => 1, 'accumulate' => $n * 4], 'period', 'increase',
                tf('Algemeen advies: %s', $words($n)),
                tf('Algemeen advies voor jouw leeftijd: minstens %1$s minuten matig intensief bewegen per week, bijvoorbeeld %2$s keer %3$s minuten. Ownify heeft nog geen trainingen van je, dus dit is nog niet op jou afgestemd.',
                    i18n_number($guide['low']), i18n_number($n), i18n_number(goal_suggest_up($guide['low'] / $n, 5))), $guide['claim_key']);
        }

        return null;
    }

    /** A sentence about strength and balance, when the person does less than the guideline names. */
    function goal_suggest_strength_note(array $f, array $guide): string
    {
        $days = (float) ($f['workouts']['strength_week'] ?? 0);
        if ($guide['older'] && $days < 3) {
            return ' ' . t('Vanaf 65 raadt de WHO ook op 3 dagen per week oefeningen voor balans en kracht aan.');
        }
        if (!$guide['older'] && $days < 2) {
            return ' ' . t('De richtlijn noemt ook minstens 2 keer per week spier- en botversterkende activiteiten.');
        }

        return '';
    }

    /** Trainingsminuten: the guideline's minutes, a day's share and a month's total. */
    function goal_suggest_workout_minutes(array $f, array $claims, array $m): ?array
    {
        $w     = $f['workouts'];
        $guide = goal_suggest_guideline($f, $claims);
        if ($guide === null) {
            return null;
        }
        $have  = $w['recorded'] >= (int) goal_suggest_config()['min_days']['workouts'];
        $eff   = $have ? (float) $w['minutes_week'] + ($guide['double'] ? (float) $w['hard_week'] : 0.0) : null;
        $week  = $eff === null || $eff < $guide['low'] ? $guide['low'] : min($guide['high'] ?? $eff * 1.1, max($guide['low'], $eff * 1.1));
        $daily = $guide['day'] ?? goal_suggest_up($week / 7, 5);
        $month = goal_suggest_up($week * 30 / 7, 10);

        /* Trainingen says it better for adults; for young people the
           guideline is per day, and this is the goal for it. */
        $score = $guide['day'] !== null ? ($eff !== null && $eff >= $guide['low'] ? 30 : 60) : 20;
        $state = $eff === null ? 'general' : ($eff >= $guide['low'] ? 'keep' : 'grow');
        $why   = $guide['day'] !== null
            ? tf('De beweegrichtlijn voor jongeren is elke dag minstens %s minuten bewegen.', i18n_number($guide['day']))
            : tf('De beweegrichtlijn voor jouw leeftijd is minstens %s minuten per week; per dag is dat ongeveer %s minuten.', i18n_number($guide['low']), i18n_number($daily));
        if ($eff !== null) {
            $why = tf('Je trainde de afgelopen vier weken gemiddeld %s minuten per week.', i18n_number(goal_suggest_round((float) $w['minutes_week'], 5))) . ' ' . $why;
        } else {
            $why .= ' ' . t('Ownify heeft nog geen trainingen van je, dus dit is nog niet op jou afgestemd.');
        }

        return goal_suggest_entry($score, $state, $eff === null ? 'general' : 'personal',
            ['streak' => $daily, 'accumulate' => $month], $guide['day'] !== null ? 'streak' : 'accumulate', 'increase',
            $eff === null ? tf('Algemeen advies: %s per week', goal_suggest_text($week, 'min')) : tf('Nu %1$s per week · voorstel %2$s', goal_suggest_text((float) $w['minutes_week'], 'min'), goal_suggest_text($week, 'min')),
            $why, $guide['claim_key']);
    }

    /** Water (Voedingscentrum, 13+): 1,4 liter at the least, men 1,4–1,8, more for who trains. */
    function goal_suggest_water(array $f, array $claims, array $m): ?array
    {
        $claim = goal_suggest_claim(goal_suggest_config()['claims']['water'], $f['age'], $claims);
        if ($claim === null) {
            return goal_suggest_personal('water', $f, $m);
        }
        $finding = (array) $claim['finding'];
        $men     = (array) ($finding['men_l'] ?? [1.4, 1.8]);
        $low     = (float) ($finding['women_min_l'] ?? 1.4);
        $ref     = $f['gender'] === 'male' ? (float) $men[1] : goal_suggest_up($low, 0.5);
        $trains  = ($f['workouts']['per_week'] ?? 0) >= 2;
        if ($trains) {
            $ref += 0.2;
        }
        $ref     = min((float) ($finding['max_l'] ?? 3), $ref);
        $own     = $f['daily']['water'] ?? null;
        $have    = $own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['daily'];
        $l       = static fn (float $v): string => goal_suggest_text($v, 'l', 1);
        $advice  = $f['gender'] === 'male'
            ? tf('Het Voedingscentrum raadt mannen %1$s tot %2$s liter per dag aan', i18n_number_trim($men[0], 1), i18n_number_trim($men[1], 1))
            : tf('Het Voedingscentrum raadt minstens %s liter per dag aan', i18n_number_trim($low, 1));
        $advice .= $trains ? t(', en meer als je flink sport en zweet.') : '.';
        $targets = static fn (float $t): array => ['streak' => $t, 'accumulate' => goal_suggest_up($t * 30, 5)];

        if ($have) {
            $a = (float) $own['avg'];
            if ($a < $ref - 0.05) {
                $t = goal_suggest_towards($a, $ref, 0.6, 0.1);
                return goal_suggest_entry(55 + (int) min(15, ($ref - $a) * 20), 'grow', 'personal', $targets($t), 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', $l($a), $l($t)),
                    tf('Je dronk de afgelopen %1$d dagen gemiddeld %2$s.', (int) $own['days'], $l($a)) . ' ' . $advice, $claim['claim_key']);
            }
            $t = min((float) ($finding['max_l'] ?? 3), goal_suggest_down($a, 0.1));
            return goal_suggest_entry(25, 'keep', 'personal', $targets($t), 'streak', 'increase',
                tf('Gaat al goed: gemiddeld %s', $l($a)),
                tf('Je dronk de afgelopen %1$d dagen gemiddeld %2$s.', (int) $own['days'], $l($a)) . ' ' . $advice . ' ' . t('Dit doel houdt vast wat je al doet.'), $claim['claim_key']);
        }

        return goal_suggest_entry(30, 'general', 'general', $targets($ref), 'streak', 'increase',
            tf('Algemeen advies: %s per dag', $l($ref)),
            $advice . ' ' . t('Ownify heeft nog geen water van je, dus dit is nog niet op jou afgestemd.'), $claim['claim_key']);
    }

    /** Protein per kilogram: 0,83 g (Gezondheidsraad), 1,4 g for who trains (ISSN, 18–64), 1,0 g from 65 (PROT-AGE). */
    function goal_suggest_protein(array $f, array $claims, array $m): ?array
    {
        $cfg    = goal_suggest_config()['claims'];
        $age    = $f['age'];
        $kg     = $f['weight_kg'];
        $trains = ($f['workouts']['per_week'] ?? 0) >= 2;
        $claim  = goal_suggest_claim($cfg['protein_older'], $age, $claims)
            ?? ($trains ? goal_suggest_claim($cfg['protein_exercise'], $age, $claims) : null)
            ?? goal_suggest_claim($cfg['protein'], $age, $claims);
        if ($claim === null || $kg === null) {
            return goal_suggest_personal('protein', $f, $m, 'nutrition');
        }
        $finding = (array) $claim['finding'];
        $perKg   = (float) (is_array($finding['g_per_kg_day']) ? $finding['g_per_kg_day'][0] : $finding['g_per_kg_day']);
        if (isset($finding['active_g_per_kg_min']) && $trains) {
            $perKg = (float) $finding['active_g_per_kg_min'];
        }
        $ref    = goal_suggest_up($perKg * $kg, 5);
        $g      = static fn (float $v): string => goal_suggest_text($v, 'g');
        $advice = tf('Voor jouw gewicht (%1$s) en leeftijd komt het advies uit op ongeveer %2$s per dag (%3$s g per kilogram).',
            goal_suggest_text($kg, 'kg'), $g($ref), i18n_number_trim($perKg, 2));
        if ($claim['claim_key'] === 'nutrition.protein.exercise') {
            $advice .= ' ' . t('Dat is het advies voor wie regelmatig traint; het onderzoek erachter is beperkt.');
        }
        $own = $f['nutrition']['protein'] ?? null;
        if ($own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['nutrition']) {
            $a = (float) $own['avg'];
            $intro = tf('Op je %1$d volledig gelogde dagen at je gemiddeld %2$s eiwit.', (int) $own['days'], $g($a));
            if ($a < $ref - 2) {
                $t = goal_suggest_towards($a, $ref, 25, 5);
                return goal_suggest_entry(55 + ($trains ? 10 : 0), 'grow', 'personal', ['streak' => $t], 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', $g($a), $g($t)), $intro . ' ' . $advice, $claim['claim_key']);
            }
            return goal_suggest_entry(20, 'keep', 'personal', ['streak' => max($ref, goal_suggest_down($a, 5))], 'streak', 'increase',
                tf('Gaat al goed: gemiddeld %s', $g($a)), $intro . ' ' . $advice . ' ' . t('Dit doel houdt vast wat je al doet.'), $claim['claim_key']);
        }

        return goal_suggest_entry(25, 'general', 'general', ['streak' => $ref], 'streak', 'increase',
            tf('Algemeen advies: %s per dag', $g($ref)),
            $advice . ' ' . t('Je hebt nog te weinig volledig gelogde dagen, dus dit is nog niet op jouw eten afgestemd.'), $claim['claim_key']);
    }

    /** Fibre: 12,6–14,6 g per 1.000 kcal (Gezondheidsraad 2026), in grams for the person's own energy target. */
    function goal_suggest_fibre(array $f, array $claims, array $m): ?array
    {
        $claim = goal_suggest_claim(insights_config()['rules']['nutrition.fibre']['claims'], $f['age'], $claims);
        $kcal  = $f['energy_target'];
        if ($claim === null || $kcal === null) {
            return goal_suggest_personal('fibre', $f, $m, 'nutrition');
        }
        $per    = (array) $claim['finding']['g_per_1000_kcal'];
        $ref    = round((float) $per[0] * $kcal / 1000);
        $g      = static fn (float $v): string => goal_suggest_text($v, 'g');
        $advice = tf('De Gezondheidsraad raadt %1$s tot %2$s g vezels per 1.000 kcal aan; bij jouw energiedoel van %3$s kcal is dat minstens %4$s per dag.',
            i18n_number_trim((float) $per[0], 1), i18n_number_trim((float) $per[1], 1), i18n_number(goal_suggest_round($kcal, 10)), $g($ref));
        $own = $f['nutrition']['fibre'] ?? null;
        if ($own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['nutrition']) {
            $a = (float) $own['avg'];
            $intro = tf('Op je %1$d volledig gelogde dagen at je gemiddeld %2$s vezels.', (int) $own['days'], $g($a));
            if ($a < $ref - 1) {
                $t = goal_suggest_towards($a, $ref, 8, 1);
                return goal_suggest_entry(50, 'grow', 'personal', ['streak' => $t], 'streak', 'increase',
                    tf('Nu %1$s · voorstel %2$s', $g($a), $g($t)), $intro . ' ' . $advice, $claim['claim_key']);
            }
            return goal_suggest_entry(15, 'keep', 'personal', ['streak' => max($ref, goal_suggest_down($a, 1))], 'streak', 'increase',
                tf('Gaat al goed: gemiddeld %s', $g($a)), $intro . ' ' . $advice, $claim['claim_key']);
        }

        return goal_suggest_entry(20, 'general', 'general', ['streak' => $ref], 'streak', 'increase',
            tf('Algemeen advies: %s per dag', $g($ref)),
            $advice . ' ' . t('Je hebt nog te weinig volledig gelogde dagen, dus dit is nog niet op jouw eten afgestemd.'), $claim['claim_key']);
    }

    /** Sodium: at most 6 g salt a day (Gezondheidsraad), which is 2.400 mg sodium. */
    function goal_suggest_sodium(array $f, array $claims, array $m): ?array
    {
        $claim = goal_suggest_claim(insights_config()['rules']['nutrition.salt']['claims'], $f['age'], $claims);
        if ($claim === null) {
            return goal_suggest_personal('sodium', $f, $m, 'nutrition');
        }
        $salt   = (float) $claim['finding']['salt_g_day_max'];
        $max    = $salt * 1000 / (float) $claim['finding']['salt_per_sodium'];
        $mg     = static fn (float $v): string => goal_suggest_text($v, 'mg');
        $advice = tf('De Gezondheidsraad raadt hoogstens %1$s g zout per dag aan: ongeveer %2$s natrium.', i18n_number_trim($salt, 1), $mg($max))
            . ' ' . t('Natrium uit je eetlog is een benadering van je zout.');
        $own = $f['nutrition']['sodium'] ?? null;
        if ($own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['nutrition']) {
            $a = (float) $own['avg'];
            $intro = tf('Op je %1$d volledig gelogde dagen at je gemiddeld %2$s natrium.', (int) $own['days'], $mg($a));
            if ($a > $max + 50) {
                $t = max($max, goal_suggest_down(min($a * 0.9, $a - 600), 100));
                return goal_suggest_entry(50, 'grow', 'personal', ['streak' => $t], 'streak', 'decrease',
                    tf('Nu %1$s · voorstel hoogstens %2$s', $mg($a), $mg($t)), $intro . ' ' . $advice, $claim['claim_key']);
            }
            return goal_suggest_entry(12, 'keep', 'personal', ['streak' => $max], 'streak', 'decrease',
                tf('Gaat al goed: gemiddeld %s', $mg($a)), $intro . ' ' . $advice, $claim['claim_key']);
        }

        return goal_suggest_entry(10, 'general', 'general', ['streak' => $max], 'streak', 'decrease',
            tf('Algemeen advies: hoogstens %s', $mg($max)), $advice, $claim['claim_key']);
    }

    /** Saturated fat: below 10% of energy (Gezondheidsraad 2026), in grams for the person's energy target. */
    function goal_suggest_saturated_fat(array $f, array $claims, array $m): ?array
    {
        $claim = goal_suggest_claim(insights_config()['rules']['nutrition.saturated_fat']['claims'], $f['age'], $claims);
        $kcal  = $f['energy_target'];
        if ($claim === null || $kcal === null) {
            return goal_suggest_personal('saturated_fat', $f, $m, 'nutrition');
        }
        $pct    = (float) $claim['finding']['interim_energy_pct_max'];
        $ref    = floor($pct / 100 * $kcal / 9);
        $g      = static fn (float $v): string => goal_suggest_text($v, 'g');
        $advice = tf('De Gezondheidsraad raadt zo weinig mogelijk verzadigd vet aan, en voorlopig minder dan %1$s%% van je energie: bij jouw energiedoel hoogstens %2$s per dag.',
            i18n_number($pct), $g($ref));
        $own = $f['nutrition']['saturated_fat'] ?? null;
        if ($own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['nutrition']) {
            $a = (float) $own['avg'];
            $intro = tf('Op je %1$d volledig gelogde dagen at je gemiddeld %2$s verzadigd vet.', (int) $own['days'], $g($a));
            if ($a > $ref + 1) {
                $t = max($ref, goal_suggest_down(min($a * 0.85, $a - 3), 1));
                return goal_suggest_entry(45, 'grow', 'personal', ['streak' => $t], 'streak', 'decrease',
                    tf('Nu %1$s · voorstel hoogstens %2$s', $g($a), $g($t)), $intro . ' ' . $advice, $claim['claim_key']);
            }
            return goal_suggest_entry(10, 'keep', 'personal', ['streak' => $ref], 'streak', 'decrease',
                tf('Gaat al goed: gemiddeld %s', $g($a)), $intro . ' ' . $advice, $claim['claim_key']);
        }

        return goal_suggest_entry(8, 'general', 'general', ['streak' => $ref], 'streak', 'decrease',
            tf('Algemeen advies: hoogstens %s', $g($ref)), $advice, $claim['claim_key']);
    }

    /**
     * Sugar: the norm is about free sugars, and a food log counts all sugars
     * — so its number is a hint, said to be one, and never suggested.
     */
    function goal_suggest_sugar(array $f, array $claims, array $m): ?array
    {
        $claim = goal_suggest_claim(goal_suggest_config()['claims']['sugar'], $f['age'], $claims);
        $kcal  = $f['energy_target'];
        if ($claim === null || $kcal === null) {
            return goal_suggest_personal('sugar', $f, $m, 'nutrition');
        }
        $pct = (float) $claim['finding']['interim_energy_pct_max'];
        $ref = floor($pct / 100 * $kcal / 4);

        return goal_suggest_entry(0, 'general', 'general', ['streak' => $ref], 'streak', 'decrease', '',
            tf('De Gezondheidsraad raadt zo weinig mogelijk vrije suikers aan, voorlopig minder dan %1$s%% van je energie: bij jouw energiedoel ongeveer %2$s per dag. Je eetlog telt alle suikers, ook die in fruit en melk, dus dit is een ruwe richting.',
                i18n_number($pct), goal_suggest_text($ref, 'g')), $claim['claim_key']);
    }

    /** Resting heart rate: only above 80, where the risk in research rises clearly. */
    function goal_suggest_resting_hr(array $f, array $claims, array $m): ?array
    {
        $claim = goal_suggest_claim(goal_suggest_config()['claims']['resting_hr'], $f['age'], $claims);
        $own   = $f['daily']['resting_hr'] ?? null;
        $have  = $own !== null && $own['days'] >= (int) goal_suggest_config()['min_days']['daily'];
        if ($claim === null || !$have || (float) $own['avg'] <= 80) {
            return goal_suggest_personal('resting_hr', $f, $m);
        }
        $a = (float) $own['avg'];
        $t = goal_suggest_down(max(60.0, min(80.0, $a - 5)), 1);
        $b = static fn (float $v): string => goal_suggest_text($v, 'bpm');

        return goal_suggest_entry(30, 'grow', 'personal', ['milestone' => $t, 'streak' => goal_suggest_down($a, 1)], 'milestone', 'decrease',
            tf('Nu %1$s · voorstel %2$s', $b($a), $b($t)),
            tf('Je rusthartslag was de afgelopen %1$d dagen gemiddeld %2$s. In onderzoek gaat een rusthartslag boven de 80 samen met een duidelijk hoger risico; regelmatig bewegen kan hem verlagen.',
                (int) $own['days'], $b($a)), $claim['claim_key']);
    }

    /**
     * Any other measurement with the person's own data: targets from their
     * own average, no research level (there is none approved for it), and
     * never suggested on its own.
     */
    function goal_suggest_personal(string $code, array $f, array $m, ?string $from = null): ?array
    {
        /* A day's food counts only when its log looks complete. */
        if (in_array($code, ['energy', 'carbs', 'fat', 'protein', 'fibre', 'sodium', 'sugar', 'saturated_fat'], true)) {
            $from = 'nutrition';
        }
        $own = $from === 'nutrition' ? ($f['nutrition'][$code] ?? null) : ($f['daily'][$code] ?? null);
        if ($own === null || $own['days'] < (int) goal_suggest_config()['min_days'][$from === 'nutrition' ? 'nutrition' : 'daily']) {
            return null;
        }

        return goal_suggest_own($code, $own, $m);
    }

    /** Targets from a person's own average and best days, for any measurement. */
    function goal_suggest_own(string $code, array $own, array $m): ?array
    {
        if (($m['category'] ?? null) === 'body') {
            /* How much, and which way, is the person's own call (docs/FIRST-DAYS.md). */
            return goal_suggest_entry(0, 'general', 'personal', [], 'milestone', (string) $m['default_direction'], '',
                t('Hoeveel en welke kant op kies je zelf: Ownify stelt hier geen streefwaarde voor. Je startwaarde komt uit je laatste meting.'));
        }
        $unit   = goal_unit((string) ($m['unit_codes'][0] ?? $m['unit_code']));
        $step   = goal_suggest_step($code, $unit);
        $up     = ($m['default_direction'] ?? 'increase') !== 'decrease';
        $a      = (float) $own['avg'];
        $streak = $up ? goal_suggest_up($a * 1.1, $step) : goal_suggest_down($a * 0.9, $step);
        $best   = (float) ($own['p90'] ?? $a);
        $targets = ['streak' => $streak];
        if (in_array('milestone', (array) $m['types'], true)) {
            $targets['milestone'] = $up ? goal_suggest_up(max($best, $a * 1.15), $step) : goal_suggest_down(min($best, $a * 0.9), $step);
        }
        if (in_array('accumulate', (array) $m['types'], true)) {
            $targets['accumulate'] = goal_suggest_up($streak * 30, $step * 10);
        }
        $text = $unit === null ? i18n_number_trim($a, 1) : goal_value_text($a, $unit, in_array($code, ['sleep_efficiency', 'hrv', 'sleeping_hr', 'resting_hr'], true) ? 0 : null);

        return goal_suggest_entry(0, 'personal', 'personal', $targets, (string) ($m['types'][0] ?? 'streak'), $up ? 'increase' : 'decrease', '',
            tf('Gebaseerd op je eigen gemiddelde van %s. Voor deze meting heeft Ownify geen goedgekeurd onderzoek met een streefwaarde, dus het voorstel komt alleen uit je eigen gegevens.', $text));
    }

    /** A sensible step for a measurement's own targets, in its stored unit. */
    function goal_suggest_step(string $code, ?array $unit): float
    {
        return match ($code) {
            'sleep_duration', 'workout_minutes', 'meditation' => 5.0,
            'distance'                                        => 0.5,
            'water'                                           => 0.1,
            'energy', 'active_energy'                         => 50.0,
            'sodium'                                          => 100.0,
            'steps'                                           => 500.0,
            'nutrition_rating'                                => 0.5,
            default                                           => 1.0,
        };
    }

    /**
     * The person's own earlier goal on this measurement, of the kind the
     * plan suggests: one they reached is not asked again for less; one they
     * got far from is not asked again for more.
     */
    function goal_suggest_history(array $entry, string $code, array $f, array $m): array
    {
        $kind = $entry['primary'] === 'period' ? 'streak_period' : ($entry['primary'] === 'streak' ? 'streak_daily' : $entry['primary']);
        $prev = $f['goals']['history'][$code][$kind] ?? null;
        $key  = $entry['primary'];
        if ($prev === null || !isset($entry['targets'][$key]) || $prev['pct'] === null || $prev['direction'] !== $entry['direction']) {
            return $entry;
        }
        $t    = (float) $entry['targets'][$key];
        $p    = (float) $prev['target'];
        $up   = $entry['direction'] === 'increase';
        $text = $key === 'period'
            ? tf('%1$s keer per %2$s', i18n_number($p), t('week'))
            : goal_suggest_text($p, goal_default_unit($m, false));

        if ($prev['pct'] >= 100) {
            $entry['targets'][$key] = $up ? max($t, $p) : min($t, $p);
            $entry['why'] .= ' ' . tf('Je vorige doel van %s haalde je; dit voorstel bouwt daarop voort.', $text);
            $entry['history'] = 'reached';
        } elseif ($prev['pct'] < 70) {
            $entry['targets'][$key] = $up ? min($t, $p) : max($t, $p);
            $entry['why'] .= ' ' . tf('Je vorige doel van %1$s haalde je voor %2$s%%; dit voorstel ligt daarom niet hoger.', $text, i18n_number((int) round($prev['pct'])));
            $entry['history'] = 'short';
        }

        return $entry;
    }

    /* ==================================================================
       THE WIZARD — pure
       ================================================================== */

    /**
     * What the wizard says for a target, per kind of goal: "Elke dag
     * minstens 7.000 stappen", "3 keer per week", "Een nacht van 8 uur".
     */
    function goal_suggest_phrase(string $code, array $m, string $key, float $value, string $direction, bool $imperial): string
    {
        if ($key === 'period') {
            return tf('%1$s keer per %2$s', i18n_number((int) $value), t('week'));
        }
        $unit  = goal_default_unit($m, $imperial);
        $text  = goal_suggest_text($value, $unit);
        $night = $code === 'sleep_duration' || $code === 'sleep_efficiency' || $code === 'sleeping_hr';

        return match ($key) {
            'streak' => $direction === 'decrease'
                ? tf('Elke dag hoogstens %s', $text)
                : ($night ? tf('Elke nacht minstens %s', $text) : tf('Elke dag minstens %s', $text)),
            'milestone'  => $direction === 'decrease'
                ? tf('Naar %s', $text)
                : ($night ? tf('Een nacht van %s', $text) : tf('Een dag van %s', $text)),
            'accumulate' => tf('%s in een maand', $text),
            default      => $text,
        };
    }

    /**
     * The catalogue the wizard reads (config/goals.php in the reader's
     * language), with the plan in it: `suggested` (its place in
     * Voorgesteld) instead of `popular`, the placeholders, the examples and
     * the first name idea from the person's own targets, the direction the
     * plan chose, and `suggestion` — the short reason for the list and, per
     * kind of goal, the sentence under the target.
     */
    function goal_suggest_apply(array $goals, array $plan, bool $imperial = false): array
    {
        $rank = array_flip($plan['order']);

        foreach ($goals['measurements'] as $code => $m) {
            unset($m['popular']);
            $m['suggested'] = isset($rank[$code]) ? $rank[$code] + 1 : null;
            $entry = $plan['entries'][$code] ?? null;

            if ($entry !== null) {
                $first = goal_unit((string) ($m['unit_codes'][0] ?? $m['unit_code']));
                $notes = [];
                $phrases = [];
                foreach ($entry['targets'] as $key => $value) {
                    $type = $key === 'period' ? 'streak' : $key;
                    if (!in_array($type, (array) $m['types'], true)) {
                        continue;
                    }
                    $m['placeholder'][$key] = $key === 'period' || $first === null
                        ? (float) $value
                        : round(goal_from_stored((float) $value, $first), 2);
                    $phrase = goal_suggest_phrase($code, $m, $key, (float) $value, $entry['direction'], $imperial);
                    $phrases[$key] = $phrase;
                    $notes[$key] = tf('Voorgesteld: %s.', goal_suggest_lower($phrase)) . ' ' . $entry['why'];
                }
                /* The type cards: a streak per period speaks for the streak. */
                foreach ($phrases as $key => $phrase) {
                    $type = $key === 'period' ? 'streak' : $key;
                    if ($key === 'period' || !isset($phrases['period']) || $type !== 'streak') {
                        $m['examples'][$type] = $phrase;
                    }
                }
                $main = $phrases[$entry['primary']] ?? reset($phrases) ?: null;
                if ($main !== null) {
                    $m['names'] = array_values(array_unique(array_merge([$main], (array) ($m['names'] ?? []))));
                    $m['names'] = array_slice($m['names'], 0, 3);
                }
                if (($m['direction'] ?? null) === 'both') {
                    $m['default_direction'] = $entry['direction'];
                }
                if ($notes === [] && $entry['why'] !== '') {
                    foreach ((array) $m['types'] as $type) {
                        $notes[$type] = $entry['why'];
                    }
                }
                $m['suggestion'] = [
                    'reason' => isset($rank[$code]) ? $entry['reason'] : '',
                    'notes'  => $notes,
                    'basis'  => $entry['basis'],
                    'state'  => $entry['state'],
                    'claim'  => $entry['claim'],
                ];
            }

            $goals['measurements'][$code] = $m;
        }

        return $goals;
    }

    /** The first letter in lower case, for a phrase inside a sentence. */
    function goal_suggest_lower(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    /**
     * The wizard's catalogue for one person: facts, plan, applied. Never
     * fails a page: anything that goes wrong leaves the plain catalogue,
     * ordered by the general list.
     */
    function goal_suggest_for(array $goals, int $userId, DateTimeImmutable $today): array
    {
        try {
            $facts = goal_suggest_facts($userId, $today);
            $plan  = goal_suggest_plan($facts, goal_suggest_measurements($goals), research_claims());

            return goal_suggest_apply($goals, $plan, (bool) $facts['imperial']);
        } catch (Throwable $e) {
            error_log('[ownify] goal suggestions: ' . $e->getMessage());

            return goal_suggest_apply($goals, ['entries' => [], 'order' => array_values(array_intersect(
                (array) goal_suggest_config()['fallback'], array_keys($goals['measurements'])))]);
        }
    }

    /** The measurements of the catalogue, each with its code. */
    function goal_suggest_measurements(array $goals): array
    {
        $out = [];
        foreach ((array) $goals['measurements'] as $code => $m) {
            $out[$code] = ['code' => $code] + $m;
        }

        return $out;
    }
}
