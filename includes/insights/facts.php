<?php
/**
 * The facts an insight may be written from (docs/INSIGHTS.md): derived
 * numbers about one person, read with the same functions the Health Score,
 * the points and the Voeding page use, so every record is counted once the
 * way it is everywhere else:
 *
 *   nights     health_nights(): one night per date, two recordings of one
 *              sleep counted once
 *   workouts   health_workouts_counted(): qualifying sessions, duplicates
 *              dropped
 *   steps      health_metric_totals(): each moment once over the apps
 *   meals      health_daily_metric_days(): each meal once over the apps
 *   profile    user_account(): age from the birth date, newest height and
 *              weight; nutrition_targets_for_user(): the energy target
 *
 * Missing is never zero: a value nothing measured is null, a count of days
 * is how many days actually had data. Nothing here judges anything.
 *
 * Every function takes the signed-in user's id as its first argument.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/health-signals.php';
require_once dirname(__DIR__) . '/health-data.php';
require_once dirname(__DIR__) . '/health-totals.php';
require_once dirname(__DIR__) . '/user.php';
require_once dirname(__DIR__) . '/nutrition-targets.php';

if (!function_exists('insights_facts')) {

    /** config/insights.php as written (Dutch, untranslated). */
    function insights_config(): array
    {
        static $config = null;

        return $config ??= (array) require dirname(__DIR__, 2) . '/config/insights.php';
    }

    /**
     * Whether Gemini may reword insights at all (config/insights.php
     * `gemini.enabled`). OWNIFY_TEST_INSIGHTS_GEMINI=1 in the environment of a
     * LOCAL test run (tools/insights-test.php) turns it on to test the wording;
     * never set it on a server people use.
     */
    function insights_gemini_enabled(): bool
    {
        return !empty(insights_config()['gemini']['enabled']) || getenv('OWNIFY_TEST_INSIGHTS_GEMINI') === '1';
    }

    /**
     * Everything the rules read, as of $now.
     *
     * @return array<string, mixed>
     */
    function insights_facts(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $d     = (array) insights_config()['data'];
        $today = $now->format('Y-m-d');

        $account = user_account($userId);
        $targets = null;
        try {
            $targets = nutrition_targets_for_user($userId);
        } catch (Throwable $e) {
            error_log('[ownify] insights: no energy target: ' . $e->getMessage());
        }

        return [
            'date'      => $today,
            'profile'   => insights_facts_profile($account, $targets, $now),
            'sleep'     => insights_facts_sleep($userId, $now, $d),
            'activity'  => insights_facts_activity($userId, $now, $d),
            'nutrition' => insights_facts_nutrition($userId, $now, $d, $targets),
        ];
    }

    /** Age, the newest height and weight with how old the weight is, the energy target. */
    function insights_facts_profile(?array $account, ?array $targets, DateTimeImmutable $now): array
    {
        $height = $account['height'] ?? null;
        $weight = $account['weight'] ?? null;

        $weightDays = null;
        if (is_array($weight) && !empty($weight['measured_at'])) {
            $at = strtotime((string) $weight['measured_at']);
            if ($at !== false) {
                $weightDays = max(0, (int) floor(($now->getTimestamp() - $at) / 86400));
            }
        }

        $energy = $targets['targets']['calorie_target_kcal'] ?? null;

        return [
            'age'            => isset($account['age']) && $account['age'] !== null ? (int) $account['age'] : null,
            'activity_level' => $account['activity_level'] ?? null,
            /* Stored in cm and kg; anything else is not converted by guesswork. */
            'height_cm'      => is_array($height) && ($height['unit'] ?? null) === 'cm' && (float) $height['value'] > 0 ? (float) $height['value'] : null,
            'weight_kg'      => is_array($weight) && ($weight['unit'] ?? null) === 'kg' && (float) $weight['value'] > 0 ? (float) $weight['value'] : null,
            'weight_days'    => $weightDays,
            'energy_target'  => $energy !== null && (float) $energy > 0 ? (float) $energy : null,
        ];
    }

    /**
     * The last 7 days of nights (the Health Score's window), and the three
     * weeks before them for a change in regularity.
     */
    function insights_facts_sleep(int $userId, DateTimeImmutable $now, array $d): array
    {
        $hours   = (int) $d['sleep_window_hours'];
        $to      = $now->getTimestamp();
        $from    = $to - 3600 * $hours;
        $before  = $from - 86400 * (int) $d['sleep_previous_days'];
        $fmt     = static fn (int $t): string => date('Y-m-d H:i:s', $t);

        $nights   = health_nights($userId, $fmt($from), $fmt($to));
        $previous = health_nights($userId, $fmt($before), $fmt($from));

        $spread = static function (array $nights): array {
            if (count($nights) < 2) {
                return [null, null];
            }

            return [
                health_clock_sd(array_map(static fn ($n) => health_clock_minutes($n['start']), $nights)),
                health_clock_sd(array_map(static fn ($n) => health_clock_minutes($n['end']), $nights)),
            ];
        };

        [$bedSd, $wakeSd]         = $spread($nights);
        [$prevBedSd, $prevWakeSd] = $spread($previous);

        $last = $nights === [] ? null : max(array_column($nights, 'date'));

        return [
            'nights'          => count($nights),
            'avg_minutes'     => $nights === [] ? null : health_mean(array_map(static fn ($n) => (float) $n['minutes'], $nights)),
            'bedtime_sd'      => $bedSd,
            'wake_sd'         => $wakeSd,
            'last_night_days' => $last === null ? null : insights_days_between($last, $now->format('Y-m-d')),
            'prev_nights'     => count($previous),
            'prev_bedtime_sd' => $prevBedSd,
            'prev_wake_sd'    => $prevWakeSd,
        ];
    }

    /**
     * Steps over the last complete days (yesterday back), recorded workouts
     * over the last 7 days, and whether the person records workouts at all.
     */
    function insights_facts_activity(int $userId, DateTimeImmutable $now, array $d): array
    {
        $today     = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $firstDay  = $now->modify('-' . (int) $d['steps_days'] . ' days')->format('Y-m-d');

        /* Steps: complete days only, today is not over yet. A day without a
           reading is absent, never 0. */
        $steps = array_filter(health_metric_totals($userId, 'steps', $firstDay, $yesterday), static fn ($v) => $v > 0);

        /* Fresh: a steps reading in the last few days, so the phone's sync
           is alive and a quiet week is a quiet week, not a missing one. */
        $freshFrom = $now->modify('-' . (int) $d['activity_fresh_days'] . ' days')->format('Y-m-d');
        $recent    = array_filter(health_metric_totals($userId, 'steps', $freshFrom, $today), static fn ($v) => $v > 0);

        $to       = $now->getTimestamp();
        $fmt      = static fn (int $t): string => date('Y-m-d H:i:s', $t);
        [$week]   = health_workouts_counted(health_workout_records($userId, $fmt($to - 7 * 86400), $fmt($to)));
        [$month]  = health_workouts_counted(health_workout_records($userId, $fmt($to - 86400 * (int) $d['workout_history_days']), $fmt($to)));

        $strengthDays = [];
        foreach ($week as $workout) {
            if (insights_is_strength((string) $workout['type'], (array) $d['strength_types'])) {
                $strengthDays[date('Y-m-d', (int) $workout['start'])] = true;
            }
        }

        return [
            'fresh'           => $recent !== [],
            'step_days'       => count($steps),
            'steps_avg'       => $steps === [] ? null : array_sum($steps) / count($steps),
            'workouts_7'      => count($week),
            'minutes_7'       => (float) array_sum(array_map(static fn ($w) => (float) $w['minutes'], $week)),
            'strength_days_7' => count($strengthDays),
            'workouts_28'     => count($month),
        ];
    }

    /** Whether a workout type is strength training (config data.strength_types). */
    function insights_is_strength(string $type, array $types): bool
    {
        $type = mb_strtolower($type);
        foreach ($types as $needle) {
            if ($type === $needle || str_contains($type, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last complete days of logged meals (yesterday back). A day whose
     * log looks complete has all three macronutrients and an energy, worked
     * out from them as the Voeding page does, near the person's own target
     * (config data.complete_energy_share). Without a target nothing can be
     * called complete, and nothing is said about food.
     */
    function insights_facts_nutrition(int $userId, DateTimeImmutable $now, array $d, ?array $targets): array
    {
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $firstDay  = $now->modify('-' . (int) $d['nutrition_days'] . ' days')->format('Y-m-d');
        $target    = $targets['targets']['calorie_target_kcal'] ?? null;
        $target    = $target !== null && (float) $target > 0 ? (float) $target : null;

        $codes  = ['carbs', 'protein', 'fat', 'saturated_fat', 'fibre', 'sodium'];
        $totals = [];
        foreach ($codes as $code) {
            $totals[$code] = health_daily_metric_days($userId, $code, $firstDay, $yesterday);
        }

        $days = [];
        foreach (array_keys($totals['carbs'] + $totals['protein'] + $totals['fat']) as $date) {
            $carbs   = $totals['carbs'][$date] ?? null;
            $protein = $totals['protein'][$date] ?? null;
            $fat     = $totals['fat'][$date] ?? null;
            if ($carbs === null || $protein === null || $fat === null) {
                continue;
            }
            $kcal = $carbs * 4 + $protein * 4 + $fat * 9;
            $days[$date] = [
                'kcal'      => $kcal,
                'protein_g' => $protein,
                'fat_g'     => $fat,
                'sat_g'     => $totals['saturated_fat'][$date] ?? null,
                'fibre_g'   => $totals['fibre'][$date] ?? null,
                'sodium_mg' => $totals['sodium'][$date] ?? null,
                'complete'  => $target !== null && $kcal >= $target * (float) $d['complete_energy_share'][0]
                                               && $kcal <= $target * (float) $d['complete_energy_share'][1],
            ];
        }

        $complete = array_filter($days, static fn ($day) => $day['complete']);
        $mean     = static fn (array $values): ?float => $values === [] ? null : array_sum($values) / count($values);

        $fatShares = $satShares = $fibre = $protein = $sodium = [];
        foreach ($complete as $day) {
            $fatShares[] = $day['fat_g'] * 9 / $day['kcal'] * 100;
            $protein[]   = $day['protein_g'];
            if ($day['sat_g'] !== null) {
                $satShares[] = $day['sat_g'] * 9 / $day['kcal'] * 100;
            }
            if ($day['fibre_g'] !== null) {
                $fibre[] = $day['fibre_g'] / $day['kcal'] * 1000;
            }
            if ($day['sodium_mg'] !== null) {
                $sodium[] = $day['sodium_mg'];
            }
        }

        return [
            'target_known'   => $target !== null,
            'logged_days'    => count($days),
            'complete_days'  => count($complete),
            'fat_share'      => $mean($fatShares),
            'sat_share'      => $mean($satShares),
            'sat_days'       => count($satShares),
            'fibre_per_1000' => $mean($fibre),
            'fibre_days'     => count($fibre),
            'protein_g'      => $mean($protein),
            'sodium_mg'      => $mean($sodium),
            'sodium_days'    => count($sodium),
        ];
    }

    /** Whole calendar days from one Y-m-d to another. */
    function insights_days_between(string $from, string $to): int
    {
        return (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
    }
}
