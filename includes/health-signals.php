<?php
/**
 * What the health records mean — PRIVATE, scoped to one user like the rest.
 *
 * ---------------------------------------------------------------------------
 * ONE READING OF THE DATA, FOR TWO SYSTEMS THAT MUST NOT MIX
 * ---------------------------------------------------------------------------
 * The Health Score (includes/health-score.php) and the leaderboard points
 * (includes/points.php) are separate on purpose: one measures a 90-day
 * pattern, the other pays for what was done. They must still agree on what
 * the data says — which night was the main sleep, which workouts count, how
 * hard a workout was — or the same workout could be one thing on the Health
 * page and another on the board. So that reading lives here, once, and both
 * depend on this file rather than on each other.
 *
 * Nothing here scores a person and nothing here awards a point.
 *
 * ---------------------------------------------------------------------------
 * DUPLICATES ARE ONE THING
 * ---------------------------------------------------------------------------
 * Health Connect often holds the same session twice — a watch app and a phone
 * app both write it. Two workouts that overlap by more than half the shorter
 * one are one workout (the longer recording counts), and a night is its
 * longest sleep: a duplicate or a nap on the same date does not add to it.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';
require_once __DIR__ . '/user.php';

if (!function_exists('health_scoring_config')) {

    function health_scoring_config(): array
    {
        static $config = null;

        return $config ??= (array) require dirname(__DIR__) . '/config/scoring.php';
    }

    /* ==================================================================
       CURVES
       ================================================================== */

    /**
     * A smooth curve through [x, y] points: monotone cubic interpolation
     * (Fritsch–Carlson), so it never overshoots between two points and never
     * has a step. Flat beyond the first and the last point.
     *
     * @param array<int,array{0:float|int,1:float|int}> $points ascending by x
     */
    function health_curve(array $points, float $x): float
    {
        $n = count($points);

        if ($n === 0) {
            return 0.0;
        }

        if ($x <= $points[0][0]) {
            return (float) $points[0][1];
        }

        if ($x >= $points[$n - 1][0]) {
            return (float) $points[$n - 1][1];
        }

        $xs = array_map(static fn ($p) => (float) $p[0], $points);
        $ys = array_map(static fn ($p) => (float) $p[1], $points);

        /* Secants, then tangents that respect monotonicity. */
        $d = [];
        for ($k = 0; $k < $n - 1; $k++) {
            $d[$k] = ($ys[$k + 1] - $ys[$k]) / ($xs[$k + 1] - $xs[$k]);
        }

        $m = [];
        $m[0]      = $d[0];
        $m[$n - 1] = $d[$n - 2];

        for ($k = 1; $k < $n - 1; $k++) {
            $m[$k] = ($d[$k - 1] * $d[$k] <= 0) ? 0.0 : ($d[$k - 1] + $d[$k]) / 2;
        }

        for ($k = 0; $k < $n - 1; $k++) {
            if ($d[$k] == 0.0) {
                $m[$k] = 0.0;
                $m[$k + 1] = 0.0;
                continue;
            }

            $a = $m[$k] / $d[$k];
            $b = $m[$k + 1] / $d[$k];
            $s = $a * $a + $b * $b;

            if ($s > 9) {
                $t = 3 / sqrt($s);
                $m[$k]     = $t * $a * $d[$k];
                $m[$k + 1] = $t * $b * $d[$k];
            }
        }

        /* The segment x falls in, and the Hermite polynomial on it. */
        $k = 0;
        while ($k < $n - 2 && $x >= $xs[$k + 1]) {
            $k++;
        }

        $h  = $xs[$k + 1] - $xs[$k];
        $t  = ($x - $xs[$k]) / $h;
        $t2 = $t * $t;
        $t3 = $t2 * $t;

        return (2 * $t3 - 3 * $t2 + 1) * $ys[$k]
            + ($t3 - 2 * $t2 + $t) * $h * $m[$k]
            + (-2 * $t3 + 3 * $t2) * $ys[$k + 1]
            + ($t3 - $t2) * $h * $m[$k + 1];
    }

    /**
     * Weighted average of the parts that exist. A part that is null had no
     * data and is left out; the other weights scale up to fill its place.
     *
     * @param array<string,float|null> $parts
     * @param array<string,float|int>  $weights
     */
    function health_weighted(array $parts, array $weights): ?float
    {
        $sum = 0.0;
        $div = 0.0;

        foreach ($parts as $name => $value) {
            $weight = (float) ($weights[$name] ?? 0);

            if ($value === null || $weight <= 0) {
                continue;
            }

            $sum += $weight * $value;
            $div += $weight;
        }

        return $div > 0 ? $sum / $div : null;
    }

    /* ==================================================================
       SMALL STATISTICS
       ================================================================== */

    /** @param float[] $values */
    function health_mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** @param float[] $values */
    function health_median(array $values): ?float
    {
        $n = count($values);

        if ($n === 0) {
            return null;
        }

        sort($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Population standard deviation. @param float[] $values */
    function health_sd(array $values): ?float
    {
        $n = count($values);

        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        $sum  = 0.0;

        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / $n);
    }

    /**
     * Clock times, in minutes after midnight, go round: 23:30 and 00:30 are an
     * hour apart, not 23. These treat them as angles on a 24-hour dial.
     *
     * @param float[] $minutes
     * @return array{0:float,1:float} [mean x, mean y] of the unit vectors
     */
    function health_clock_vector(array $minutes): array
    {
        $x = 0.0;
        $y = 0.0;

        foreach ($minutes as $minute) {
            $angle = 2 * M_PI * $minute / 1440;
            $x += cos($angle);
            $y += sin($angle);
        }

        $n = max(1, count($minutes));

        return [$x / $n, $y / $n];
    }

    /** Circular standard deviation of clock times, in minutes. */
    function health_clock_sd(array $minutes): ?float
    {
        if (count($minutes) < 2) {
            return null;
        }

        [$x, $y] = health_clock_vector($minutes);
        $r = min(1.0, sqrt($x * $x + $y * $y));

        if ($r <= 1e-9) {
            return 720.0;                          // spread evenly round the clock
        }

        return sqrt(-2 * log($r)) * 1440 / (2 * M_PI);
    }

    /** Circular mean of clock times, in minutes after midnight. */
    function health_clock_mean(array $minutes): ?float
    {
        if ($minutes === []) {
            return null;
        }

        [$x, $y] = health_clock_vector($minutes);
        $minute = atan2($y, $x) * 1440 / (2 * M_PI);

        return $minute < 0 ? $minute + 1440 : $minute;
    }

    /** The shortest distance between two clock times, in minutes (0-720). */
    function health_clock_distance(float $a, float $b): float
    {
        $diff = fmod(abs($a - $b), 1440.0);

        return $diff > 720 ? 1440 - $diff : $diff;
    }

    /** Minutes after midnight of a timestamp, as the stored wall clock says. */
    function health_clock_minutes(int $timestamp): float
    {
        return (int) date('G', $timestamp) * 60 + (int) date('i', $timestamp);
    }

    /* ==================================================================
       NIGHTS
       ================================================================== */

    /**
     * THE MAIN SLEEP OF A DATE — one rule, for every reader.
     *
     * The Slaap card, the sleep score, the sleep points and sleep goals all
     * ask "what was the night of this date?", and they must get one answer.
     * They get it here:
     *
     *   1. A night belongs to the date its sleep ENDED (night_of — the morning
     *      you woke up), as every writer already files it.
     *   2. Recordings that overlap by more than `overlap` of the shorter one
     *      are the same sleep seen twice (a watch and a phone): the one with
     *      sleep stages is believed, otherwise the longer. They never add up.
     *   3. What remains, in time order, is joined into one sleep where the
     *      gap between two parts is at most `merge_gap_minutes`: a night that
     *      was broken by getting up is still one night.
     *   4. The longest of those sleeps is the night. A nap is a separate,
     *      shorter sleep on the same date and is never the night, nor part
     *      of it.
     *
     * Imported and manually entered nights go through the same rule: both
     * live in sleep_sessions, with the same columns, and nothing here asks
     * where a row came from.
     */

    /**
     * Nights whose sleep ended in ($from, $to], one per date, oldest first.
     * The score and the points read their windows this way.
     *
     * @return array<int,array<string,mixed>>
     */
    function health_nights(int $userId, string $from, string $to): array
    {
        return health_nights_from_rows(db_all(
            'SELECT id, night_of, started_at, ended_at, duration_minutes, time_in_bed_minutes,
                    efficiency_pct, awakenings, awake_minutes, light_minutes, deep_minutes, rem_minutes
               FROM sleep_sessions
              WHERE user_id = ? AND ended_at > ? AND ended_at <= ?
           ORDER BY night_of, started_at',
            [$userId, $from, $to]
        ));
    }

    /**
     * The nights filed under the dates $fromDate..$toDate (Y-m-d), keyed by
     * date. The Slaap card and sleep goals read their dates this way.
     *
     * @return array<string,array<string,mixed>>
     */
    function health_nights_on(int $userId, string $fromDate, string $toDate): array
    {
        $nights = health_nights_from_rows(db_all(
            'SELECT id, night_of, started_at, ended_at, duration_minutes, time_in_bed_minutes,
                    efficiency_pct, awakenings, awake_minutes, light_minutes, deep_minutes, rem_minutes
               FROM sleep_sessions
              WHERE user_id = ? AND night_of BETWEEN ? AND ?
           ORDER BY night_of, started_at',
            [$userId, $fromDate, $toDate]
        ));

        return array_column($nights, null, 'date');
    }

    /** The night of one date (Y-m-d), or null when nothing was slept that counts. */
    function health_night_on(int $userId, string $date): ?array
    {
        return health_nights_on($userId, $date, $date)[$date] ?? null;
    }

    /**
     * sleep_sessions rows -> one night per date. A row with no sleep time
     * (a zero or negative duration, or times the wrong way round) is no
     * night and is left out.
     */
    function health_nights_from_rows(array $rows): array
    {
        $byDate = [];

        foreach ($rows as $row) {
            $start = strtotime((string) $row['started_at']);
            $end   = strtotime((string) $row['ended_at']);

            if ($start === false || $end === false || $end <= $start) {
                continue;
            }

            $minutes = $row['duration_minutes'] !== null
                ? (float) $row['duration_minutes']
                : ($end - $start) / 60;

            if ($minutes <= 0) {
                continue;
            }

            $date = (string) $row['night_of'];
            $number = static fn ($value): ?float => $value === null ? null : (float) $value;

            $byDate[$date][] = [
                'session_id' => (int) $row['id'],
                'date'       => $date,
                'start'      => $start,
                'end'        => $end,
                'minutes'    => $minutes,
                'in_bed'     => $number($row['time_in_bed_minutes']),
                'efficiency' => $number($row['efficiency_pct']),
                'awakenings' => $number($row['awakenings'] ?? null),
                'awake'      => $number($row['awake_minutes']),
                'light'      => $number($row['light_minutes']),
                'deep'       => $number($row['deep_minutes']),
                'rem'        => $number($row['rem_minutes']),
            ];
        }

        ksort($byDate);

        return array_values(array_map('health_night_main', $byDate));
    }

    /**
     * The one night that stands for a date, from its sessions — steps 2-4 of
     * the rule above.
     */
    function health_night_main(array $sessions): array
    {
        $sleep   = health_scoring_config()['sleep'];
        $staged  = static fn (array $s): bool => $s['deep'] !== null && $s['rem'] !== null;
        $overlap = (float) $sleep['overlap'];
        /* A default only for the moment a deploy has uploaded this file but
           not yet config/scoring.php; the value lives in the config. */
        $gap     = 60 * (int) ($sleep['merge_gap_minutes'] ?? 60);

        /* 2. Recordings of the same sleep: overlapping by more than `overlap`
              of the shorter one. */
        $groups = [];

        foreach ($sessions as $session) {
            foreach ($groups as $g => $group) {
                foreach ($group as $other) {
                    $shared  = min($session['end'], $other['end']) - max($session['start'], $other['start']);
                    $shorter = min($session['end'] - $session['start'], $other['end'] - $other['start']);

                    if ($shorter > 0 && $shared > $overlap * $shorter) {
                        $groups[$g][] = $session;
                        continue 3;
                    }
                }
            }

            $groups[] = [$session];
        }

        /* Of each sleep, the best recording: stages first, then the longer.
           The sleep is weighed by its longest recording, as it always was. */
        $sleeps = [];

        foreach ($groups as $group) {
            usort($group, static fn ($a, $b) => [$staged($b), $b['minutes']] <=> [$staged($a), $a['minutes']]);
            $sleeps[] = ['best' => $group[0], 'weight' => max(array_column($group, 'minutes'))];
        }

        /* 3. Parts close together in time are one sleep. */
        usort($sleeps, static fn ($a, $b) => [$a['best']['start'], $a['best']['session_id']] <=> [$b['best']['start'], $b['best']['session_id']]);

        $episodes = [];

        foreach ($sleeps as $part) {
            $last = count($episodes) - 1;

            if ($last >= 0 && $part['best']['start'] - max(array_column(array_column($episodes[$last], 'best'), 'end')) <= $gap) {
                $episodes[$last][] = $part;
            } else {
                $episodes[] = [$part];
            }
        }

        /* 4. The longest sleep is the night. */
        usort($episodes, static fn ($a, $b) => array_sum(array_column($b, 'weight')) <=> array_sum(array_column($a, 'weight')));

        return health_night_join(array_column($episodes[0], 'best'));
    }

    /**
     * Parts of one sleep as one night. A single part is returned exactly as
     * it is, so an ordinary night reads the same as its row. Several parts
     * add up; a measurement counts only if every part has it, because half a
     * night's deep sleep is not the night's deep sleep.
     */
    function health_night_join(array $parts): array
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        usort($parts, static fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        $sum = static function (string $key) use ($parts): ?float {
            $values = array_column($parts, $key);

            return count(array_filter($values, static fn ($v) => $v === null)) > 0 || count($values) < count($parts)
                ? null
                : (float) array_sum($values);
        };

        $minutes = (float) array_sum(array_column($parts, 'minutes'));
        $inBed   = $sum('in_bed');

        return [
            'session_id' => $parts[0]['session_id'],          // the longest part
            'date'       => $parts[0]['date'],
            'start'      => min(array_column($parts, 'start')),
            'end'        => max(array_column($parts, 'end')),
            'minutes'    => $minutes,
            'in_bed'     => $inBed,
            'efficiency' => $inBed !== null && $inBed > 0 ? round(min(100.0, $minutes / $inBed * 100), 2) : null,
            'awakenings' => $sum('awakenings'),
            'awake'      => $sum('awake'),
            'light'      => $sum('light'),
            'deep'       => $sum('deep'),
            'rem'        => $sum('rem'),
        ];
    }

    /**
     * How a night went, 0-100, from whatever the device measured — or null
     * when it measured nothing beyond the times. Each measurement present is
     * scored on its curve in config/scoring.php and the night is their
     * weighted average.
     */
    function health_night_quality(array $night): ?float
    {
        $cfg = health_scoring_config()['sleep']['quality'];

        return health_weighted(
            array_map(static fn ($part) => $part === null ? null : $part['score'], health_night_quality_parts($night)),
            $cfg['weights']
        );
    }

    /**
     * health_night_quality(), measurement by measurement: each one the
     * device took — efficiency and deep and REM sleep in %, time awake in
     * minutes — with its score on its curve; null where it took none.
     *
     * @return array{efficiency: ?array{value: float, score: float}, awake: ?array{value: float, score: float},
     *               deep: ?array{value: float, score: float}, rem: ?array{value: float, score: float}}
     */
    function health_night_quality_parts(array $night): array
    {
        $cfg = health_scoring_config()['sleep']['quality'];

        $parts = ['efficiency' => null, 'awake' => null, 'deep' => null, 'rem' => null];

        $efficiency = $night['efficiency'];
        if ($efficiency === null && $night['in_bed'] !== null && $night['in_bed'] > 0) {
            $efficiency = min(100.0, $night['minutes'] / $night['in_bed'] * 100);
        }
        if ($efficiency !== null) {
            $parts['efficiency'] = ['value' => (float) $efficiency, 'score' => health_curve($cfg['efficiency_curve'], $efficiency)];
        }

        if ($night['awake'] !== null) {
            $parts['awake'] = ['value' => (float) $night['awake'], 'score' => health_curve($cfg['awake_curve'], $night['awake'])];
        }

        /* Stage shares only mean something when the stages were measured. */
        $asleep = ($night['light'] ?? 0) + ($night['deep'] ?? 0) + ($night['rem'] ?? 0);
        if ($asleep > 0 && $night['deep'] !== null && $night['rem'] !== null) {
            $deep = $night['deep'] / $asleep * 100;
            $rem  = $night['rem'] / $asleep * 100;
            $parts['deep'] = ['value' => (float) $deep, 'score' => health_curve($cfg['deep_curve'], $deep)];
            $parts['rem']  = ['value' => (float) $rem, 'score' => health_curve($cfg['rem_curve'], $rem)];
        }

        return $parts;
    }

    /* ==================================================================
       WORKOUTS
       ================================================================== */

    /**
     * Workouts that started in [$from, $to), with what is known about each:
     * minutes, distance, speed, heart rate, perceived effort and time per
     * heart-rate zone. Every workout, qualifying or not — see
     * health_workouts_counted() for the ones that count.
     *
     * @return array<int,array<string,mixed>> oldest first
     */
    function health_workout_records(int $userId, ?string $from = null, ?string $to = null): array
    {
        $where  = 'w.user_id = ?';
        $params = [$userId];

        if ($from !== null) {
            $where   .= ' AND w.started_at >= ?';
            $params[] = $from;
        }

        if ($to !== null) {
            $where   .= ' AND w.started_at < ?';
            $params[] = $to;
        }

        $rows = db_all(
            'SELECT w.id, w.activity_type, w.started_at, w.ended_at, w.duration_seconds,
                    w.distance_m, w.avg_speed_kmh, w.avg_hr, w.max_hr, w.perceived_effort
               FROM workouts w
              WHERE ' . $where . '
           ORDER BY w.started_at, w.id',
            $params
        );

        if ($rows === []) {
            return [];
        }

        /* Zones for all of them in one query. */
        $zones = [];
        $ids   = array_map(static fn ($r) => (int) $r['id'], $rows);

        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));

            foreach (db_all('SELECT workout_id, zone, seconds FROM workout_hr_zones WHERE workout_id IN (' . $marks . ')', $chunk) as $z) {
                $zones[(int) $z['workout_id']][(int) $z['zone']] = (int) $z['seconds'];
            }
        }

        $out = [];

        foreach ($rows as $row) {
            $start = strtotime((string) $row['started_at']);

            if ($start === false) {
                continue;
            }

            $end = $row['ended_at'] !== null ? strtotime((string) $row['ended_at']) : false;

            $minutes = null;
            if ($row['duration_seconds'] !== null && (int) $row['duration_seconds'] > 0) {
                $minutes = (int) $row['duration_seconds'] / 60;
            } elseif ($end !== false && $end > $start) {
                $minutes = ($end - $start) / 60;
            }

            $km  = $row['distance_m'] !== null && (float) $row['distance_m'] > 0 ? (float) $row['distance_m'] / 1000 : null;
            $kmh = $row['avg_speed_kmh'] !== null && (float) $row['avg_speed_kmh'] > 0 ? (float) $row['avg_speed_kmh'] : null;

            if ($kmh === null && $km !== null && $minutes !== null && $minutes > 0) {
                $kmh = $km / ($minutes / 60);
            }

            $id = (int) $row['id'];

            $out[] = [
                'id'      => $id,
                'type'    => mb_strtolower(trim((string) $row['activity_type'])) ?: 'other',
                'start'   => $start,
                /* The recorded end where there is one: two recordings of one
                   session are compared on the time they span. */
                'end'     => ($end !== false && $end > $start)
                    ? $end
                    : ($minutes !== null ? $start + (int) round($minutes * 60) : $start),
                'minutes' => $minutes,
                'km'      => $km,
                'kmh'     => $kmh,
                'avg_hr'  => $row['avg_hr'] === null ? null : (float) $row['avg_hr'],
                'max_hr'  => $row['max_hr'] === null ? null : (float) $row['max_hr'],
                'rpe'     => $row['perceived_effort'] === null ? null : (float) $row['perceived_effort'],
                'zones'   => $zones[$id] ?? [],
            ];
        }

        return $out;
    }

    /** Whether a recorded workout is a real, complete one that counts. */
    function health_workout_qualifies(array $workout): bool
    {
        $cfg = health_scoring_config()['workouts'];

        return $workout['minutes'] !== null
            && $workout['minutes'] >= $cfg['min_minutes']
            && $workout['minutes'] <= $cfg['max_minutes'];
    }

    /**
     * The workouts that count: qualifying, and each real session once. Of two
     * recordings that overlap by more than half the shorter one, the longer
     * is kept (on a tie, the one recorded first).
     *
     * @return array{0: array<int,array>, 1: array<int,int>} [kept oldest first, ids of the dropped duplicates]
     */
    function health_workouts_counted(array $workouts): array
    {
        $overlap = (float) health_scoring_config()['workouts']['overlap'];

        $candidates = array_values(array_filter($workouts, 'health_workout_qualifies'));

        usort($candidates, static fn ($a, $b) => [$b['minutes'], $a['id']] <=> [$a['minutes'], $b['id']]);

        $kept    = [];
        $dropped = [];

        foreach ($candidates as $workout) {
            foreach ($kept as $other) {
                $shared  = min($workout['end'], $other['end']) - max($workout['start'], $other['start']);
                $shorter = min($workout['end'] - $workout['start'], $other['end'] - $other['start']);

                if ($shorter > 0 && $shared > $overlap * $shorter) {
                    $dropped[] = $workout['id'];
                    continue 2;
                }
            }

            $kept[] = $workout;
        }

        usort($kept, static fn ($a, $b) => [$a['start'], $a['id']] <=> [$b['start'], $b['id']]);

        return [$kept, $dropped];
    }

    /**
     * The highest heart rate that can be assumed for this person: the highest
     * they recorded, or 208 - 0.7 x age (Tanaka) if that is higher. Null when
     * neither is known.
     */
    function health_hr_max(int $userId, array $workouts): ?float
    {
        $observed = null;

        foreach ($workouts as $workout) {
            if ($workout['max_hr'] !== null && $workout['max_hr'] > 0) {
                $observed = max($observed ?? 0.0, $workout['max_hr']);
            }
        }

        $age = null;
        $dob = db_value('SELECT date_of_birth FROM user_profiles WHERE user_id = ?', [$userId]);

        if (is_string($dob) && $dob !== '') {
            $age = user_age($dob);
        }

        $formula  = health_scoring_config()['training']['intensity']['hr_max_estimate'];
        $estimate = $age !== null && $age > 0 ? $formula['base'] - $formula['per_year'] * $age : null;

        if ($observed === null) {
            return $estimate;
        }

        return $estimate === null ? $observed : max($observed, $estimate);
    }

    /**
     * How hard a workout was, from the best measurement it has: perceived
     * effort, then heart-rate zones, then average heart rate against the
     * maximum. `class` is easy, moderate, hard, or null when nothing was
     * measured; the raw measures ride along for the points engine.
     *
     * @return array{class: ?string, rpe: ?float, zone_share: ?float, hr_pct: ?float}
     */
    function health_workout_effort(array $workout, ?float $hrMax): array
    {
        $cfg = health_scoring_config()['training']['intensity'];

        $zoneShare = null;
        $upperShare = null;
        $total = array_sum($workout['zones']);

        if ($total > 0) {
            $hard = 0;
            $upper = 0;

            foreach ($workout['zones'] as $zone => $seconds) {
                if ($zone >= $cfg['hr_zones']['hard_zone']) {
                    $hard += $seconds;
                }
                if ($zone >= $cfg['hr_zones']['hard_zone'] - 1) {
                    $upper += $seconds;
                }
            }

            $zoneShare  = $hard / $total;
            $upperShare = $upper / $total;
        }

        $hrPct = ($workout['avg_hr'] !== null && $hrMax !== null && $hrMax > 0)
            ? $workout['avg_hr'] / $hrMax
            : null;

        $class = null;

        if ($workout['rpe'] !== null) {
            $class = $workout['rpe'] >= $cfg['rpe']['hard'] ? 'hard'
                : ($workout['rpe'] >= $cfg['rpe']['moderate'] ? 'moderate' : 'easy');
        } elseif ($zoneShare !== null) {
            $class = $zoneShare >= $cfg['hr_zones']['hard_share'] ? 'hard'
                : ($upperShare >= $cfg['hr_zones']['moderate_share'] ? 'moderate' : 'easy');
        } elseif ($hrPct !== null) {
            $class = $hrPct >= $cfg['hr_max_pct']['hard'] ? 'hard'
                : ($hrPct >= $cfg['hr_max_pct']['moderate'] ? 'moderate' : 'easy');
        }

        return ['class' => $class, 'rpe' => $workout['rpe'], 'zone_share' => $zoneShare, 'hr_pct' => $hrPct];
    }

    /** Whether a workout was heavy: hard, or simply long. */
    function health_workout_heavy(array $workout, array $effort): bool
    {
        return $effort['class'] === 'hard'
            || ($workout['minutes'] ?? 0) >= health_scoring_config()['workouts']['heavy_minutes'];
    }

    /** What to call an activity type in Dutch, for points history and feedback. */
    function health_activity_label(string $type): string
    {
        $type = mb_strtolower($type);

        $names = [
            'running' => 'hardlopen', 'run' => 'hardlopen', 'treadmill' => 'hardlopen',
            'walking' => 'wandelen', 'walk' => 'wandelen', 'hiking' => 'wandeltocht',
            'cycling' => 'fietsen', 'biking' => 'fietsen', 'bike' => 'fietsen', 'ride' => 'fietsen',
            'swimming' => 'zwemmen', 'swim' => 'zwemmen',
            'strength' => 'krachttraining', 'strength_training' => 'krachttraining',
            'weightlifting' => 'krachttraining', 'weight_training' => 'krachttraining',
            'yoga' => 'yoga', 'pilates' => 'pilates', 'rowing' => 'roeien',
            'elliptical' => 'crosstrainer', 'dancing' => 'dansen', 'football' => 'voetbal',
            'soccer' => 'voetbal', 'tennis' => 'tennis', 'hiit' => 'HIIT',
        ];

        foreach ($names as $needle => $label) {
            if ($type === $needle || str_contains($type, $needle)) {
                return $label;
            }
        }

        return 'training';
    }
}
