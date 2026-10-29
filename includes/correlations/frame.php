<?php
/**
 * The day table behind combined insights (docs/CORRELATIONS.md): one value
 * per variable per local day, read through the counting Ownify already has —
 * health_nights(), health_workouts_counted(), health_metric_totals(), the
 * complete-day rule for logged food — so a night, a workout or a day's steps
 * mean the same here as everywhere else. A day without a measurement is
 * absent, never zero.
 *
 * Dates are local Europe/Amsterdam days (Ownify's one clock). A night
 * belongs to the date it ended; its evening is the day before.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/health-signals.php';
require_once dirname(__DIR__) . '/health-data.php';
require_once dirname(__DIR__) . '/health-totals.php';
require_once dirname(__DIR__) . '/nutrition-targets.php';
require_once dirname(__DIR__) . '/user.php';

if (!function_exists('corr_frame')) {

    function corr_config(): array
    {
        static $config = null;

        return $config ??= (array) require dirname(__DIR__, 2) . '/config/correlations.php';
    }

    /** Y-m-d plus whole days. */
    function corr_day(string $date, int $days): string
    {
        return date('Y-m-d', strtotime($date . ' 12:00:00') + 86400 * $days);
    }

    /** Whether the evening of $date is a weekend evening (Friday or Saturday). */
    function corr_weekend_evening(string $date): bool
    {
        $dow = (int) date('N', strtotime($date . ' 12:00:00'));

        return $dow === 5 || $dow === 6;
    }

    /**
     * Every variable the pairs read, keyed by date, over the window that
     * ends yesterday. Never throws on missing parts: what cannot be read is
     * simply absent.
     *
     * @return array{from: string, to: string, days: int, vars: array<string, array<string, float>>, age: ?int, meta: array}
     */
    function corr_frame(int $userId, DateTimeImmutable $now): array
    {
        $cfg   = corr_config();
        $f     = (array) $cfg['frame'];
        $days  = (int) $cfg['window_days'];
        $to    = $now->modify('-1 day')->format('Y-m-d');          // today is not over
        $from  = $now->modify('-' . $days . ' days')->format('Y-m-d');
        $vars  = [];
        $meta  = [];

        $account = user_account($userId);
        $age     = isset($account['age']) && $account['age'] !== null ? (int) $account['age'] : null;
        $weight  = (is_array($account['weight'] ?? null) && ($account['weight']['unit'] ?? null) === 'kg' && (float) $account['weight']['value'] > 0)
            ? (float) $account['weight']['value'] : null;

        /* ---- nights: duration, onset, the night's heart rate and HRV. One
           day before the window, and last night (filed under today), for the
           lags. */
        $nights = health_nights_on($userId, corr_day($from, -1), corr_day($to, 1));
        $onset  = [];   // night date => minutes after noon of its evening
        foreach ($nights as $night) {
            $date = (string) $night['date'];
            $vars['sleep_min'][$date] = (float) $night['minutes'];
            $evening = corr_day($date, -1);
            $onset[$date] = ($night['start'] - strtotime($evening . ' 12:00:00')) / 60;
            $vars['onset_clock'][$date] = $onset[$date];
            $vars['weekend'][$date] = corr_weekend_evening($evening) ? 1.0 : 0.0;
        }

        /* Your usual sleep onset: the median of the same month's nights,
           weekend and weekday evenings apart (XSA-06's "habitual sleep
           onset"); the whole window's for that kind of evening when the month
           has too few. */
        $usual = corr_usual_onsets($onset);
        foreach ($onset as $date => $minutes) {
            if (isset($usual[$date])) {
                $vars['onset_dev'][$date] = $minutes - $usual[$date];
            }
        }

        /* The night's HRV (ln RMSSD) and heart rate in sleep, from readings
           taken during it or just after it ended. */
        $hrv = corr_metric_rows($userId, 'hrv', corr_day($from, -2), corr_day($to, 1));
        $shr = corr_metric_rows($userId, 'sleeping_hr', corr_day($from, -2), corr_day($to, 1));
        $before = 60 * (int) $f['hrv_window_before'];
        $after  = 60 * (int) $f['hrv_window_after'];
        foreach ($nights as $night) {
            $date = (string) $night['date'];
            $inNight = static fn (array $rows): array => array_values(array_filter($rows,
                static fn ($r) => $r[0] >= $night['start'] - $before && $r[0] <= $night['end'] + $after && $r[1] > 0));
            $h = $inNight($hrv);
            if ($h !== []) {
                $vars['hrv'][$date] = log(corr_mean(array_column($h, 1)));
            }
            $s = $inNight($shr);
            if ($s !== []) {
                $vars['sleep_hr'][$date] = corr_mean(array_column($s, 1));
            }
        }

        /* ---- activity: complete days of steps. */
        foreach (health_metric_totals($userId, 'steps', $from, $to) as $date => $value) {
            if ($value > 0) {
                $vars['steps'][$date] = (float) $value;
            }
        }

        /* ---- training: load, hard, trained, late — on days the phone or
           watch was in use (steps, or a workout), so a day without data is
           not a rest day. */
        $records = health_workout_records($userId, $from . ' 00:00:00', corr_day($to, 1) . ' 00:00:00');
        [$workouts] = health_workouts_counted($records);
        $hrMax = health_hr_max($userId, $workouts);
        $byDay = [];
        foreach ($workouts as $workout) {
            $byDay[date('Y-m-d', (int) $workout['start'])][] = $workout + ['effort' => health_workout_effort($workout, $hrMax)];
        }
        $synced = array_fill_keys(array_merge(array_keys($vars['steps'] ?? []), array_keys($byDay)), true);
        foreach (array_keys($synced) as $date) {
            if ($date < $from || $date > $to) {
                continue;
            }
            $list = $byDay[$date] ?? [];
            $load = 0.0;
            $hard = false;
            $lastEnd = null;
            foreach ($list as $w) {
                $class = $w['effort']['class'] ?? null;
                $load += (float) ($w['minutes'] ?? 0) * (float) ($f['effort_weight'][$class ?? 'unknown'] ?? 2);
                $hard = $hard || $class === 'hard';
                $lastEnd = max($lastEnd ?? 0, (int) $w['end']);
            }
            $vars['load'][$date]    = $load;
            $vars['trained'][$date] = $list === [] ? 0.0 : 1.0;
            $vars['hard'][$date]    = $hard ? 1.0 : 0.0;

            /* Late: ending less than 4 h before the usual sleep onset of the
               night after. */
            $night = corr_day($date, 1);
            $usualAbs = isset($usual[$night]) ? strtotime($date . ' 12:00:00') + 60 * $usual[$night] : corr_usual_for($usual, $onset, $date);
            if ($usualAbs !== null) {
                $vars['late_workout'][$date] = ($lastEnd !== null && $lastEnd > $usualAbs - 3600 * (float) $f['late_workout_hours']) ? 1.0 : 0.0;
            }
        }

        /* ---- food: complete logged days only (the 29.0 rule), protein per
           kg, water, the cijfer, and when the last meal was. */
        corr_frame_food($userId, $from, $to, $weight, $usual, $onset, $vars, $meta, $f);

        /* ---- weeks: training load and average sleep per ISO week, whole
           weeks inside the window. */
        $weeks = [];
        foreach ($vars['load'] ?? [] as $date => $load) {
            $weeks[date('o-\WW', strtotime($date . ' 12:00:00'))]['load'][] = $load;
        }
        foreach ($vars['sleep_min'] ?? [] as $date => $minutes) {
            if ($date >= $from && $date <= $to) {
                $weeks[date('o-\WW', strtotime($date . ' 12:00:00'))]['sleep'][] = $minutes;
            }
        }
        foreach ($weeks as $week => $w) {
            if (count($w['load'] ?? []) >= 5 && count($w['sleep'] ?? []) >= 4) {
                $monday = date('Y-m-d', strtotime(str_replace('-W', 'W', $week) . '1'));
                $vars['week_load'][$monday]  = array_sum($w['load']);
                $vars['week_sleep'][$monday] = corr_mean($w['sleep']);
            }
        }

        foreach ($vars as $name => $values) {
            ksort($vars[$name]);
        }

        return ['from' => $from, 'to' => $to, 'days' => $days, 'vars' => $vars, 'age' => $age, 'meta' => $meta];
    }

    /**
     * The usual onset per night (minutes after noon of its evening): the
     * median over the same month's nights of the same kind of evening.
     *
     * @param array<string, float> $onset
     * @return array<string, float>
     */
    function corr_usual_onsets(array $onset): array
    {
        $groups = $kinds = [];
        foreach ($onset as $date => $minutes) {
            $evening = corr_day($date, -1);
            $kind    = corr_weekend_evening($evening) ? 'w' : 'd';
            $groups[substr($evening, 0, 7) . $kind][] = $minutes;
            $kinds[$kind][] = $minutes;
        }
        $out = [];
        foreach ($onset as $date => $minutes) {
            $evening = corr_day($date, -1);
            $kind    = corr_weekend_evening($evening) ? 'w' : 'd';
            $month   = $groups[substr($evening, 0, 7) . $kind] ?? [];
            $pool    = count($month) >= 4 ? $month : ($kinds[$kind] ?? []);
            if (count($pool) >= 4) {
                $out[$date] = corr_median($pool);
            }
        }

        return $out;
    }

    /** The usual onset, as a timestamp, for the evening of $date without a night of its own. */
    function corr_usual_for(array $usual, array $onset, string $evening): ?int
    {
        $kind = corr_weekend_evening($evening) ? 'w' : 'd';
        $pool = [];
        foreach ($usual as $date => $minutes) {
            if ((corr_weekend_evening(corr_day($date, -1)) ? 'w' : 'd') === $kind) {
                $pool[] = $minutes;
            }
        }

        return count($pool) >= 4 ? strtotime($evening . ' 12:00:00') + (int) round(60 * corr_median($pool)) : null;
    }

    /** One metric's readings: [timestamp, value], oldest first. */
    function corr_metric_rows(int $userId, string $code, string $from, string $to): array
    {
        $typeId = health_metric_type_id($code);
        if ($typeId === null) {
            return [];
        }
        $out = [];
        foreach (db_all(
            'SELECT recorded_at, value FROM health_metrics
              WHERE user_id = ? AND metric_type_id = ? AND recorded_on BETWEEN ? AND ?
           ORDER BY recorded_at',
            [$userId, $typeId, $from, $to]
        ) as $row) {
            $at = strtotime((string) $row['recorded_at']);
            if ($at !== false && $row['value'] !== null) {
                $out[] = [$at, (float) $row['value']];
            }
        }

        return $out;
    }

    /** Food, into $vars: kcal, protein_kg, water, rating, late_meal, late_caffeine. */
    function corr_frame_food(int $userId, string $from, string $to, ?float $weight, array $usual, array $onset, array &$vars, array &$meta, array $f): void
    {
        $target = null;
        try {
            $targets = nutrition_targets_for_user($userId);
            $target  = $targets['targets']['calorie_target_kcal'] ?? null;
            $target  = $target !== null && (float) $target > 0 ? (float) $target : null;
        } catch (Throwable $e) {
            $target = null;
        }
        $share = (array) (require dirname(__DIR__, 2) . '/config/insights.php')['data']['complete_energy_share'];

        $carbs   = health_daily_metric_days($userId, 'carbs', $from, $to);
        $protein = health_daily_metric_days($userId, 'protein', $from, $to);
        $fat     = health_daily_metric_days($userId, 'fat', $from, $to);
        $complete = [];
        if ($target !== null) {
            foreach ($carbs as $date => $c) {
                if (!isset($protein[$date], $fat[$date])) {
                    continue;
                }
                $kcal = $c * 4 + $protein[$date] * 4 + $fat[$date] * 9;
                if ($kcal >= $target * (float) $share[0] && $kcal <= $target * (float) $share[1]) {
                    $complete[$date] = true;
                    $vars['kcal'][$date] = $kcal;
                    if ($weight !== null) {
                        $vars['protein_kg'][$date] = $protein[$date] / $weight;
                    }
                }
            }
        }

        foreach (health_daily_metric_days($userId, 'water', $from, $to) as $date => $litres) {
            if ($litres > 0) {
                $vars['water'][$date] = (float) $litres;
            }
        }
        foreach (health_daily_metric_days($userId, 'nutrition_rating', $from, $to) as $date => $rating) {
            if ($rating > 0) {
                $vars['rating'][$date] = (float) $rating;
            }
        }

        /* The last meal of a complete day, against the usual onset that
           evening: the later the meal, the higher the value. */
        foreach (db_all(
            'SELECT DATE(consumed_at) AS day, MAX(consumed_at) AS last_at FROM nutrition_entries
              WHERE user_id = ? AND consumed_at >= ? AND consumed_at < ? GROUP BY DATE(consumed_at)',
            [$userId, $from . ' 00:00:00', corr_day($to, 1) . ' 00:00:00']
        ) as $row) {
            $date = (string) $row['day'];
            if (!isset($complete[$date])) {
                continue;
            }
            $usualAbs = isset($usual[corr_day($date, 1)]) ? strtotime($date . ' 12:00:00') + (int) round(60 * $usual[corr_day($date, 1)]) : corr_usual_for($usual, $onset, $date);
            if ($usualAbs !== null) {
                $vars['late_meal'][$date] = (strtotime((string) $row['last_at']) - $usualAbs) / 60;
            }
        }

        /* Caffeine (D7): late when taken in the 8.8 h before the usual
           onset (XSN-05) — only for people who log it at all, and only on
           complete days, where its absence means none was taken. */
        $caffeine = corr_metric_rows($userId, 'caffeine', $from, $to);
        $byDay = [];
        foreach ($caffeine as [$at, $mg]) {
            if ($mg > 0) {
                $byDay[date('Y-m-d', $at)][] = $at;
            }
        }
        if (count($byDay) >= (int) $f['caffeine_min_days']) {
            foreach (array_keys($complete) as $date) {
                $usualAbs = isset($usual[corr_day($date, 1)]) ? strtotime($date . ' 12:00:00') + (int) round(60 * $usual[corr_day($date, 1)]) : corr_usual_for($usual, $onset, $date);
                if ($usualAbs === null) {
                    continue;
                }
                $late = false;
                foreach ($byDay[$date] ?? [] as $at) {
                    $late = $late || $at >= $usualAbs - (int) round(3600 * (float) $f['late_caffeine_hours']);
                }
                $vars['late_caffeine'][$date] = $late ? 1.0 : 0.0;
            }
        }

        /* Short nights (C08): 5.5 h or less, or — with too few of those —
           the person's own shortest quarter. */
        $nights = array_filter($vars['sleep_min'] ?? [], static fn ($d) => $d >= $from && $d <= $to, ARRAY_FILTER_USE_KEY);
        if ($nights !== []) {
            $limit = (float) $f['short_night_minutes'];
            if (count(array_filter($nights, static fn ($m) => $m <= $limit)) < (int) $f['short_night_min_days']) {
                $sorted = array_values($nights);
                sort($sorted);
                $limit = $sorted[(int) floor((count($sorted) - 1) * 0.25)];
            }
            foreach ($nights as $date => $minutes) {
                $vars['short_night'][$date] = $minutes <= $limit ? 1.0 : 0.0;
            }
            $meta['short_night_minutes'] = $limit;
        }
    }
}
