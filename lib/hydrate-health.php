<?php
/**
 * Fills the Gezondheid page from the signed-in user's own records.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE IS FOR
 * ---------------------------------------------------------------------------
 * config/health.php describes the SHAPE of the page: which areas exist, which
 * metrics belong to which group, what each one is called and in what unit.
 * That is interface definition, and it stays in config. What it does not hold
 * any more is values — those come from here, per user, per day.
 *
 * The contract is simple and it is the whole point: a key this file cannot
 * fill stays null, and the components already render null as an empty state.
 * Nothing invents a reading, nothing substitutes a plausible number, and a
 * user who has recorded nothing sees the empty design rather than someone
 * else's idea of a normal day.
 *
 * Every read is scoped to the id passed in, which callers take from the
 * session and never from the request.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/scoring.php';

if (!function_exists('hydrate_health')) {

    /**
     * @param array    $health config/health.php, already shape-only
     * @param int|null $userId the authenticated user, or null when signed out
     */
    function hydrate_health(array $health, ?int $userId, ?string $date = null): array
    {
        if ($userId === null || !db_available()) {
            return $health;     // signed out: the shape renders its empty states
        }

        $date   = $date ?? date('Y-m-d');
        $values = hydrate_health_values($userId, $date);

        /* Area scores come from the scoring engine, never from the values map:
           one place decides what a score is. */
        $scores = score_domains($userId, $date);

        foreach ($health['areas'] as $areaKey => $area) {
            if (array_key_exists($areaKey, $scores)) {
                $health['areas'][$areaKey]['score']['value'] = $scores[$areaKey];
            }
        }

        $health['areas'] = hydrate_health_fill($health['areas'], $values);
        $health['trend'] = hydrate_health_trend($health['trend'] ?? [], $userId, $date);

        return $health;
    }

    /**
     * Walks the area tree and writes any value whose `key` we have.
     * A key that is absent from the map is left exactly as it was — null.
     */
    function hydrate_health_fill(array $node, array $values): array
    {
        if (isset($node['key']) && is_string($node['key']) && array_key_exists($node['key'], $values)) {
            foreach (['value', 'share'] as $slot) {
                if (array_key_exists($slot, $node)) {
                    $node[$slot] = $values[$node['key']];
                }
            }
        }

        foreach ($node as $key => $child) {
            if (is_array($child)) {
                $node[$key] = hydrate_health_fill($child, $values);
            }
        }

        return $node;
    }

    /**
     * One day's readings, keyed the way config/health.php keys them.
     *
     * Only keys with real data appear. The three sources are the session
     * tables (a night, a workout, a meal), the metric catalogue (anything
     * scalar) and a small number of figures derived from those.
     *
     * @return array<string,mixed>
     */
    function hydrate_health_values(int $userId, string $date): array
    {
        $out = [];

        $put = static function (string $key, $value) use (&$out): void {
            if ($value !== null) {
                $out[$key] = $value;
            }
        };

        /* ---------------------------------------------------------- sleep */

        $night = db_one(
            'SELECT started_at, ended_at, duration_minutes, time_in_bed_minutes,
                    efficiency_pct, awakenings, awake_minutes,
                    light_minutes, deep_minutes, rem_minutes
               FROM sleep_sessions
              WHERE user_id = ? AND night_of = ?
           ORDER BY started_at DESC
              LIMIT 1',
            [$userId, $date]
        );

        if ($night !== null) {
            $put('sleep_duration',   hydrate_hours($night['duration_minutes']));
            $put('time_in_bed',      hydrate_hours($night['time_in_bed_minutes']));
            $put('bedtime',          hydrate_clock($night['started_at']));
            $put('wake_time',        hydrate_clock($night['ended_at']));
            $put('sleep_efficiency', hydrate_round($night['efficiency_pct']));
            $put('awakenings',       hydrate_int($night['awakenings']));
            $put('awake_time',       hydrate_int($night['awake_minutes']));

            /* The timeline wants each stage as a share of the night, so it is
               only meaningful once the stages add up to something. */
            $stages = [
                'stage_deep'  => $night['deep_minutes'],
                'stage_rem'   => $night['rem_minutes'],
                'stage_light' => $night['light_minutes'],
                'stage_awake' => $night['awake_minutes'],
            ];

            $total = 0;
            foreach ($stages as $minutes) {
                $total += (int) ($minutes ?? 0);
            }

            if ($total > 0) {
                foreach ($stages as $key => $minutes) {
                    $put($key, (int) round((int) ($minutes ?? 0) / $total * 100));
                }
            }
        }

        /* ------------------------------------------------------ nutrition */

        $meals = db_one(
            'SELECT COUNT(*) AS n, MIN(consumed_at) AS first_at, MAX(consumed_at) AS last_at
               FROM nutrition_entries
              WHERE user_id = ? AND DATE(consumed_at) = ?',
            [$userId, $date]
        );

        if ($meals !== null && (int) $meals['n'] > 0) {
            $put('meals', (int) $meals['n']);
            $put('meal_window', hydrate_clock($meals['first_at']) . ' – ' . hydrate_clock($meals['last_at']));
        }

        /* -------------------------------------------------------- training */

        $training = db_one(
            'SELECT COUNT(*) AS n, SUM(duration_seconds) AS seconds,
                    AVG(avg_hr) AS avg_hr, MAX(max_hr) AS max_hr,
                    AVG(avg_cadence) AS cadence, SUM(elevation_gain_m) AS elevation
               FROM workouts
              WHERE user_id = ? AND DATE(started_at) = ?',
            [$userId, $date]
        );

        if ($training !== null && (int) $training['n'] > 0) {
            $put('sessions',         (int) $training['n']);
            $put('session_duration', $training['seconds'] === null ? null : (int) round($training['seconds'] / 60));
            $put('avg_hr',           hydrate_round($training['avg_hr']));
            $put('max_hr',           hydrate_int($training['max_hr']));
            $put('cadence',          hydrate_round($training['cadence']));
            $put('elevation',        hydrate_int($training['elevation']));
            $put('hr_zones',         hydrate_hr_zones($userId, $date));
        }

        /* --------------------------------------------- catalogue metrics */

        /* config key => health_metric_types.code. Where the two already agree
           the pair is still written out, so this map is the single statement
           of what the page reads. */
        $metrics = [
            'sleep_regularity' => 'sleep_regularity',
            'sleeping_hr'      => 'sleeping_hr',
            'resting_hr'       => 'resting_hr',
            'hrv'              => 'hrv',
            'respiratory_rate' => 'respiratory_rate',
            'skin_temp'        => 'skin_temp',
            'spo2'             => 'spo2',
            'water'            => 'water',
            'energy'           => 'energy',
            'protein'          => 'protein',
            'carbs'            => 'carbs',
            'fat'              => 'fat',
            'saturated_fat'    => 'saturated_fat',
            'fibre'            => 'fibre',
            'sugar'            => 'sugar',
            'sodium'           => 'sodium',
            'self_rating'      => 'nutrition_rating',
            'steps'            => 'steps',
            'distance'         => 'distance',
            'active_energy'    => 'active_energy',
            'total_energy'     => 'total_energy',
            'active_minutes'   => 'active_minutes',
            'floors'           => 'floors',
            'vo2max'           => 'vo2max',
            'readiness'        => 'readiness',
            'training_load'    => 'training_load',
        ];

        /* Decimals only where the unit has them; a step count of 9420.0 would
           render as a fraction of a step. */
        $decimals = ['water' => 1, 'distance' => 1, 'skin_temp' => 1,
                     'respiratory_rate' => 1, 'self_rating' => 1];

        foreach ($metrics as $key => $code) {
            $value = health_daily_metric($userId, $code, $date);

            if ($value === null) {
                continue;
            }

            $out[$key] = isset($decimals[$key])
                ? round($value, $decimals[$key])
                : (int) round($value);
        }

        /* A session table can supply what the catalogue did not. */
        if (!isset($out['session_duration']) && isset($out['active_minutes'])) {
            $out['session_duration'] = $out['active_minutes'];
        }

        return $out;
    }

    /**
     * The trend chart: one score per day for each pillar.
     *
     * Days without a score stay null, which is what the chart already draws as
     * a gap rather than as a drop to zero.
     */
    function hydrate_health_trend(array $trend, int $userId, string $date): array
    {
        if ($trend === [] || !isset($trend['series'])) {
            return $trend;
        }

        $today = new DateTimeImmutable($date);

        foreach ($trend['series'] as $domain => $ranges) {
            foreach ($ranges as $rangeKey => $range) {
                $slots = count($range['values'] ?? []);
                if ($slots === 0) {
                    continue;
                }

                $values = [];

                if ($rangeKey === 'week') {
                    /* The labels run Monday..Sunday, so the window is this
                       week rather than the last seven days. */
                    $monday = $today->modify('monday this week');
                    for ($i = 0; $i < $slots; $i++) {
                        $day = $monday->modify('+' . $i . ' day');
                        $values[] = $day > $today
                            ? null                      // the future is not a gap in the data
                            : score_domain($userId, $day->format('Y-m-d'), $domain);
                    }
                } else {
                    /* Month: each slot is a week, oldest first, averaged over
                       the days in it that have a score. */
                    for ($i = $slots - 1; $i >= 0; $i--) {
                        $end   = $today->modify('-' . (7 * $i) . ' day');
                        $start = $end->modify('-6 day');
                        $week  = score_series($userId, $domain, $start->format('Y-m-d'), $end->format('Y-m-d'));
                        $values[] = $week === [] ? null : (int) round(array_sum($week) / count($week));
                    }
                }

                $trend['series'][$domain][$rangeKey]['values'] = $values;
            }
        }

        return $trend;
    }

    /* ------------------------------------------------------- formatting */

    /** Minutes as the app writes durations: 444 -> "7:24". */
    function hydrate_hours($minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        $minutes = (int) $minutes;

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** A DATETIME as a wall clock: "2026-09-17 23:10:00" -> "23:10". */
    function hydrate_clock(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($timestamp))->format('H:i');
        } catch (Exception $e) {
            return null;
        }
    }

    function hydrate_int($value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    function hydrate_round($value): ?int
    {
        return $value === null ? null : (int) round((float) $value);
    }

    /** "Z2 · Z3" — the zones the day was actually spent in. */
    function hydrate_hr_zones(int $userId, string $date): ?string
    {
        $rows = db_all(
            'SELECT z.zone, SUM(z.seconds) AS seconds
               FROM workout_hr_zones z
               JOIN workouts w ON w.id = z.workout_id
              WHERE w.user_id = ? AND DATE(w.started_at) = ?
           GROUP BY z.zone
             HAVING seconds > 0
           ORDER BY seconds DESC
              LIMIT 2',
            [$userId, $date]
        );

        if ($rows === []) {
            return null;
        }

        $zones = array_map(static fn (array $r): string => 'Z' . (int) $r['zone'], $rows);
        sort($zones);

        return implode(' · ', $zones);
    }
}
