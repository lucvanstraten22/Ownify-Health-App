<?php
/**
 * Health data access — PRIVATE.
 *
 * ---------------------------------------------------------------------------
 * THE PRIVACY RULE
 * ---------------------------------------------------------------------------
 * Every function here takes the authenticated user's id as its FIRST argument
 * and every statement filters on `user_id = ?`. There is no function that
 * reads one user's health data on behalf of another, and none should be added:
 * a friend list, a leaderboard row and the future assistant all go through
 * user_public_profile() instead.
 *
 * The database cannot enforce this — a MySQL account has access to whole
 * tables, not to rows belonging to one end user. This file is the boundary,
 * and it works only as long as callers pass the id from the SESSION rather
 * than from the request.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-totals.php';

if (!function_exists('health_source_id')) {

    /* ------------------------------------------------------- catalogues */

    function health_source_id(string $code): ?int
    {
        static $cache = [];

        if (!array_key_exists($code, $cache)) {
            $id = db_value('SELECT id FROM data_sources WHERE code = ?', [$code]);
            $cache[$code] = $id === null ? null : (int) $id;
        }

        return $cache[$code];
    }

    function health_metric_type_id(string $code): ?int
    {
        static $cache = [];

        if (!array_key_exists($code, $cache)) {
            $id = db_value('SELECT id FROM health_metric_types WHERE code = ?', [$code]);
            $cache[$code] = $id === null ? null : (int) $id;
        }

        return $cache[$code];
    }

    function health_metric_types(?string $domain = null): array
    {
        if ($domain === null) {
            return db_all('SELECT id, code, label, unit, domain, aggregation FROM health_metric_types ORDER BY domain, label');
        }

        return db_all(
            'SELECT id, code, label, unit, domain, aggregation FROM health_metric_types WHERE domain = ? ORDER BY label',
            [$domain]
        );
    }

    /* ---------------------------------------------------------- writing */

    /**
     * Records one reading.
     *
     * $context may carry 'sleep_session_id', 'workout_id' or
     * 'nutrition_entry_id' to say what the reading belongs to — that is how a
     * nutrient hangs off a meal and an overnight heart rate off a sleep
     * session, without either needing its own column.
     */
    function health_record_metric(
        int $userId,
        string $metricCode,
        float $value,
        ?string $recordedAt = null,
        string $sourceCode = 'manual',
        array $context = []
    ): ?int {
        $typeId = health_metric_type_id($metricCode);
        if ($typeId === null) {
            return null;
        }

        db_run(
            'INSERT INTO health_metrics
                (user_id, metric_type_id, source_id, value, recorded_at,
                 sleep_session_id, workout_id, nutrition_entry_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $typeId,
                health_source_id($sourceCode),
                $value,
                $recordedAt ?? date('Y-m-d H:i:s'),
                $context['sleep_session_id'] ?? null,
                $context['workout_id'] ?? null,
                $context['nutrition_entry_id'] ?? null,
            ]
        );

        return db_insert_id();
    }

    /**
     * Records a night's sleep.
     *
     * The unique key is (user_id, started_at), so re-importing the same night
     * updates it rather than adding a second copy — which is what makes a
     * repeated sync from a watch safe.
     */
    function health_record_sleep(int $userId, array $session, string $sourceCode = 'manual'): ?int
    {
        if (empty($session['started_at']) || empty($session['ended_at'])) {
            return null;
        }

        try {
            $start = new DateTimeImmutable((string) $session['started_at']);
            $end   = new DateTimeImmutable((string) $session['ended_at']);
        } catch (Exception $e) {
            return null;
        }

        if ($end <= $start) {
            return null;
        }

        /* A night is filed under the day you woke up. */
        $nightOf  = $session['night_of'] ?? $end->format('Y-m-d');
        $duration = $session['duration_minutes'] ?? (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60);

        db_run(
            'INSERT INTO sleep_sessions
                (user_id, source_id, night_of, started_at, ended_at, duration_minutes,
                 time_in_bed_minutes, efficiency_pct, awakenings, awake_minutes,
                 light_minutes, deep_minutes, rem_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                night_of = VALUES(night_of), ended_at = VALUES(ended_at),
                duration_minutes = VALUES(duration_minutes),
                time_in_bed_minutes = VALUES(time_in_bed_minutes),
                efficiency_pct = VALUES(efficiency_pct), awakenings = VALUES(awakenings),
                awake_minutes = VALUES(awake_minutes), light_minutes = VALUES(light_minutes),
                deep_minutes = VALUES(deep_minutes), rem_minutes = VALUES(rem_minutes)',
            [
                $userId, health_source_id($sourceCode), $nightOf,
                $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $duration,
                $session['time_in_bed_minutes'] ?? null, $session['efficiency_pct'] ?? null,
                $session['awakenings'] ?? null, $session['awake_minutes'] ?? null,
                $session['light_minutes'] ?? null, $session['deep_minutes'] ?? null,
                $session['rem_minutes'] ?? null,
            ]
        );

        /* Looked up rather than taken from lastInsertId, which is 0 when the
           night already existed and was updated. */
        $id = db_value(
            'SELECT id FROM sleep_sessions WHERE user_id = ? AND started_at = ?',
            [$userId, $start->format('Y-m-d H:i:s')]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Records a meal or a drink, with its rating.
     *
     * Several entries a day are expected and each is its own row: the rating
     * is what the day is scored on, and averaging two honest ratings is more
     * truthful than making the second overwrite the first.
     */
    function health_record_nutrition(int $userId, array $entry, string $sourceCode = 'manual'): ?int
    {
        $consumedAt = $entry['consumed_at'] ?? date('Y-m-d H:i:s');

        try {
            $when = new DateTimeImmutable((string) $consumedAt);
        } catch (Exception $e) {
            return null;
        }

        $mealType = $entry['meal_type'] ?? 'other';
        if (!in_array($mealType, ['breakfast', 'lunch', 'dinner', 'snack', 'drink', 'other'], true)) {
            $mealType = 'other';
        }

        db_run(
            'INSERT INTO nutrition_entries (user_id, source_id, meal_type, label, consumed_at, notes)
                  VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId, health_source_id($sourceCode), $mealType,
                $entry['label'] ?? null, $when->format('Y-m-d H:i:s'), $entry['notes'] ?? null,
            ]
        );

        $entryId = db_insert_id();

        if ($entryId === null) {
            return null;
        }

        /* The rating and any nutrients hang off the entry as metric rows, so
           adding a nutrient later needs no change to the table. */
        if (isset($entry['rating']) && $entry['rating'] !== null && $entry['rating'] !== '') {
            $rating = max(1.0, min(10.0, (float) $entry['rating']));
            health_record_metric($userId, 'nutrition_rating', $rating,
                $when->format('Y-m-d H:i:s'), $sourceCode, ['nutrition_entry_id' => $entryId]);
        }

        foreach (['water', 'energy', 'protein', 'carbs', 'fat', 'saturated_fat',
                  'fibre', 'sugar', 'sodium'] as $nutrient) {
            if (isset($entry[$nutrient]) && $entry[$nutrient] !== null && $entry[$nutrient] !== '') {
                health_record_metric($userId, $nutrient, (float) $entry[$nutrient],
                    $when->format('Y-m-d H:i:s'), $sourceCode, ['nutrition_entry_id' => $entryId]);
            }
        }

        return $entryId;
    }

    /** Records one training session. Re-syncing the same start updates it. */
    function health_record_workout(int $userId, array $workout, string $sourceCode = 'manual'): ?int
    {
        $startedAt = $workout['started_at'] ?? date('Y-m-d H:i:s');

        try {
            $start = new DateTimeImmutable((string) $startedAt);
        } catch (Exception $e) {
            return null;
        }

        $seconds = $workout['duration_seconds'] ?? null;
        $end     = null;

        if (!empty($workout['ended_at'])) {
            try {
                $end = new DateTimeImmutable((string) $workout['ended_at']);
                $seconds ??= $end->getTimestamp() - $start->getTimestamp();
            } catch (Exception $e) {
                $end = null;
            }
        }

        if ($seconds !== null && (int) $seconds <= 0) {
            return null;
        }

        db_run(
            'INSERT INTO workouts
                (user_id, source_id, activity_type, started_at, ended_at, duration_seconds,
                 distance_m, active_kcal, total_kcal, avg_hr, max_hr, avg_speed_kmh,
                 avg_cadence, elevation_gain_m, perceived_effort, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                activity_type = VALUES(activity_type), ended_at = VALUES(ended_at),
                duration_seconds = VALUES(duration_seconds), distance_m = VALUES(distance_m),
                active_kcal = VALUES(active_kcal), total_kcal = VALUES(total_kcal),
                avg_hr = VALUES(avg_hr), max_hr = VALUES(max_hr),
                avg_speed_kmh = VALUES(avg_speed_kmh), avg_cadence = VALUES(avg_cadence),
                elevation_gain_m = VALUES(elevation_gain_m),
                perceived_effort = VALUES(perceived_effort), notes = VALUES(notes)',
            [
                $userId, health_source_id($sourceCode), $workout['activity_type'] ?? 'other',
                $start->format('Y-m-d H:i:s'), $end?->format('Y-m-d H:i:s'), $seconds,
                $workout['distance_m'] ?? null, $workout['active_kcal'] ?? null,
                $workout['total_kcal'] ?? null, $workout['avg_hr'] ?? null,
                $workout['max_hr'] ?? null, $workout['avg_speed_kmh'] ?? null,
                $workout['avg_cadence'] ?? null, $workout['elevation_gain_m'] ?? null,
                $workout['perceived_effort'] ?? null, $workout['notes'] ?? null,
            ]
        );

        /* Looked up for the same reason as a night's. */
        $id = db_value(
            'SELECT id FROM workouts WHERE user_id = ? AND started_at = ?',
            [$userId, $start->format('Y-m-d H:i:s')]
        );

        return $id === null ? null : (int) $id;
    }

    /* ---------------------------------------------------------- reading */

    /**
     * One day's value for one metric, rolled up the way the catalogue says
     * (steps sum, heart rate averages, a rating is the last one entered).
     * Returns null when the user has no reading — never a stand-in number.
     */
    function health_daily_metric(int $userId, string $metricCode, string $date): ?float
    {
        $type = db_one('SELECT id, aggregation FROM health_metric_types WHERE code = ?', [$metricCode]);
        if ($type === null) {
            return null;
        }

        /* A total is THE day total, the one every page reads. */
        if ($type['aggregation'] === 'sum') {
            return health_metric_totals($userId, $metricCode, $date, $date)[$date] ?? null;
        }

        $aggregate = match ($type['aggregation']) {
            'avg' => 'AVG(value)',
            'min' => 'MIN(value)',
            'max' => 'MAX(value)',
            default => 'SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY recorded_at DESC), ",", 1)',
        };

        $value = db_value(
            'SELECT ' . $aggregate . ' FROM health_metrics
              WHERE user_id = ? AND metric_type_id = ? AND recorded_on = ?',
            [$userId, (int) $type['id'], $date]
        );

        return $value === null ? null : (float) $value;
    }

    /** A daily series for a trend chart. Days without a reading are absent. */
    function health_metric_series(int $userId, string $metricCode, string $from, string $to): array
    {
        $type = db_one('SELECT id, aggregation FROM health_metric_types WHERE code = ?', [$metricCode]);
        if ($type === null) {
            return [];
        }

        if ($type['aggregation'] === 'sum') {
            return health_metric_totals($userId, $metricCode, $from, $to);
        }

        $aggregate = match ($type['aggregation']) {
            'min' => 'MIN(value)',
            'max' => 'MAX(value)',
            default => 'AVG(value)',
        };

        $rows = db_all(
            'SELECT recorded_on AS day, ' . $aggregate . ' AS value
               FROM health_metrics
              WHERE user_id = ? AND metric_type_id = ? AND recorded_on BETWEEN ? AND ?
           GROUP BY recorded_on
           ORDER BY recorded_on',
            [$userId, (int) $type['id'], $from, $to]
        );

        $series = [];
        foreach ($rows as $row) {
            $series[$row['day']] = (float) $row['value'];
        }

        return $series;
    }

    /**
     * health_daily_metric() for each day from $from to $to (Y-m-d), in one
     * read: the same value per day, rolled up the same way. Days without a
     * reading are absent — never a 0.
     *
     * @return array<string,float> date => value, oldest first
     */
    function health_daily_metric_days(int $userId, string $metricCode, string $from, string $to): array
    {
        $type = db_one('SELECT id, aggregation FROM health_metric_types WHERE code = ?', [$metricCode]);
        if ($type === null) {
            return [];
        }

        if ($type['aggregation'] === 'sum') {
            return health_metric_totals($userId, $metricCode, $from, $to);
        }

        $aggregate = match ($type['aggregation']) {
            'avg' => 'AVG(value)',
            'min' => 'MIN(value)',
            'max' => 'MAX(value)',
            default => 'SUBSTRING_INDEX(GROUP_CONCAT(value ORDER BY recorded_at DESC), ",", 1)',
        };

        $days = [];
        foreach (db_all(
            'SELECT recorded_on AS day, ' . $aggregate . ' AS value
               FROM health_metrics
              WHERE user_id = ? AND metric_type_id = ? AND recorded_on BETWEEN ? AND ?
           GROUP BY recorded_on
           ORDER BY recorded_on',
            [$userId, (int) $type['id'], $from, $to]
        ) as $row) {
            if ($row['value'] !== null) {
                $days[(string) $row['day']] = (float) $row['value'];
            }
        }

        return $days;
    }

    /** Whether heart rate is kept minute by minute (heart_rate_minutes, migration 019). */
    function health_heart_minutes_stored(): bool
    {
        static $stored = null;

        return $stored ??= db_available() && (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'heart_rate_minutes'"
        ) > 0;
    }

    /**
     * The user's heart rate, minute by minute, from $from up to $to
     * (Y-m-d H:i:s): each minute's mean over the apps that recorded it —
     * weighted by their samples — oldest first. None before migration 019.
     *
     * @return array<string,float> "Y-m-d H:i:00" => bpm
     */
    function health_heart_minutes(int $userId, string $from, string $to): array
    {
        if (!health_heart_minutes_stored()) {
            return [];
        }

        $out = [];
        foreach (db_all(
            'SELECT minute_at, SUM(bpm * samples) / SUM(samples) AS bpm
               FROM heart_rate_minutes
              WHERE user_id = ? AND minute_at >= ? AND minute_at < ?
           GROUP BY minute_at
           ORDER BY minute_at',
            [$userId, $from, $to]
        ) as $row) {
            $out[(string) $row['minute_at']] = (float) $row['bpm'];
        }

        return $out;
    }

    /**
     * Each day's average heart rate from $from to $to (Y-m-d): the mean of
     * its five-minute means — the points the Training page draws a day with
     * (docs/TRAINING.md) — each five minutes with a heart rate weighing the
     * same, however often a device measured in it: a watch that measures
     * every second during a run and every ten minutes otherwise would
     * otherwise make every training day's average a training's.
     * Days without any are absent.
     *
     * @return array<string,float> date => bpm
     */
    function health_heart_days(int $userId, string $from, string $to, int $bucket = 5): array
    {
        if (!health_heart_minutes_stored()) {
            return [];
        }

        $out = [];
        foreach (db_all(
            'SELECT day, AVG(bpm) AS bpm FROM (
                SELECT DATE(minute_at) AS day, FLOOR((HOUR(minute_at) * 60 + MINUTE(minute_at)) / ?) AS b, AVG(bpm) AS bpm FROM (
                    SELECT minute_at, SUM(bpm * samples) / SUM(samples) AS bpm
                      FROM heart_rate_minutes
                     WHERE user_id = ? AND minute_at >= ? AND minute_at < ?
                  GROUP BY minute_at
                ) m
              GROUP BY day, b
             ) f
           GROUP BY day
           ORDER BY day',
            [max(1, $bucket), $userId, $from . ' 00:00:00', (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']
        ) as $row) {
            $out[(string) $row['day']] = (float) $row['bpm'];
        }

        return $out;
    }

    /** Whether a night's stages are kept period by period (sleep_stages, migration 018). */
    function health_sleep_stages_stored(): bool
    {
        static $stored = null;

        return $stored ??= db_available() && (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'sleep_stages'"
        ) > 0;
    }

    /**
     * The stage periods of some of the user's sleep sessions, oldest first,
     * each as recorded: its stage (Health Connect's numbering) and its start
     * and end as timestamps on the sessions' clock. None before migration 018.
     *
     * @param int[] $sessionIds
     * @return list<array{stage: int, start: int, end: int}>
     */
    function health_sleep_stage_periods(int $userId, array $sessionIds): array
    {
        $sessionIds = array_values(array_unique(array_map('intval', $sessionIds)));
        if ($sessionIds === [] || !health_sleep_stages_stored()) {
            return [];
        }

        $rows = db_all(
            'SELECT st.stage, st.started_at, st.ended_at, s.id AS session, s.started_at AS night_start, s.ended_at AS night_end
               FROM sleep_stages st
               JOIN sleep_sessions s ON s.id = st.sleep_session_id
              WHERE s.user_id = ? AND s.id IN (' . implode(',', array_fill(0, count($sessionIds), '?')) . ')
           ORDER BY s.started_at, s.id, st.id',
            [$userId, ...$sessionIds]
        );

        $nights = [];
        foreach ($rows as $row) {
            $nights[(int) $row['session']][] = $row;
        }

        $periods = [];
        foreach ($nights as $night) {
            $own = health_sleep_stage_instants($night);
            usort($own, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
            array_push($periods, ...$own);
        }

        return $periods;
    }

    /**
     * One night's stored periods as moments (47.0, docs/SLEEP.md): on the
     * night's start clock, as they are stored since 47.0.
     *
     * Before 47.0 a night across a change to or from summer time was stored
     * on two clocks: from the app, each period's start on the night's first
     * offset and its end on its last (in October a period after 03:00 then
     * ended before it began); from Google Health, each period on the local
     * clock of its own moment. Such a night — and only one whose periods,
     * read as stored, cannot be (one ending before it starts, two on top of
     * each other, one outside the night) — is read the way it was written,
     * if exactly one of those two ways makes a night that can be. Otherwise
     * it is shown as stored: nothing is guessed, and the database is not
     * changed.
     *
     * @param list<array{stage: mixed, started_at: string, ended_at: string, night_start: string, night_end: string}> $rows
     * @return list<array{stage: int, start: int, end: int}>
     */
    function health_sleep_stage_instants(array $rows): array
    {
        $clock = health_sleep_stage_clock((string) $rows[0]['night_start']);
        $at    = static function (string $wall, DateTimeZone $zone): ?int {
            try {
                return (new DateTimeImmutable($wall, $zone))->getTimestamp();
            } catch (Exception $e) {
                return null;
            }
        };

        $stored = [];
        foreach ($rows as $row) {
            $start = $at((string) $row['started_at'], $clock);
            $end   = $at((string) $row['ended_at'], $clock);
            if ($start !== null && $end !== null) {
                $stored[] = ['stage' => (int) $row['stage'], 'start' => $start, 'end' => $end];
            }
        }
        $fine = static fn (array $ps): array => array_values(array_filter($ps, static fn (array $p): bool => $p['end'] > $p['start']));

        $from  = strtotime((string) $rows[0]['night_start']);
        $until = strtotime((string) $rows[0]['night_end']);
        $first = $clock->getOffset(new DateTimeImmutable('@' . (int) $from));
        $last  = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTimeImmutable('@' . (int) $until));
        $possible = static fn (?array $ps): bool => $ps !== null && $ps !== [] && health_sleep_stages_possible($ps, (int) $from, (int) $until);
        $whole    = static fn (?array $ps): bool => $possible($ps) && abs(max(array_column($ps, 'end')) - (int) $until) <= 60;   // up to the night's end
        if ($from === false || $until === false || $first === $last || $whole($stored)) {
            return $fine($stored);
        }

        /* The app before 47.0: each end on the night's last offset. */
        $app = array_map(static fn (array $p): array => ['end' => $p['end'] + $first - $last] + $p, $stored);

        /* Google Health before 47.0: each moment on its own local clock. */
        $local = health_sleep_stage_local($rows);

        /* One way that makes the whole night, else one way that makes a
           night at all — never a choice between two. */
        foreach ([$whole, $possible] as $test) {
            if ($test === $possible && $possible($stored)) {
                break;
            }
            $can = array_values(array_filter([$app, $local], $test));
            if (count($can) === 1 || (count($can) === 2 && $can[0] === $can[1])) {
                return $can[0];
            }
            if (count($can) === 2) {
                break;
            }
        }

        return $fine($stored);
    }

    /** Whether a night's periods can be: each ends after it starts, none on top of another, all inside the night (a minute's leeway). */
    function health_sleep_stages_possible(array $periods, int $from, int $until): bool
    {
        usort($periods, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $previous = null;
        foreach ($periods as $p) {
            if ($p['end'] <= $p['start'] || $p['start'] < $from - 60 || $p['end'] > $until + 60
                || ($previous !== null && $p['start'] < $previous - 60)) {
                return false;
            }
            $previous = max($previous ?? $p['end'], $p['end']);
        }

        return true;
    }

    /**
     * A night's periods each read on the local clock of its own moment
     * (Europe/Amsterdam), in the order they were stored. In October the hour
     * from 02:00 to 03:00 comes twice: a time in it is the first unless the
     * times before it rule that out — and if a time in it could be either
     * and nothing after it shows which, the night cannot be read this way
     * (null). A time that never happened (March, 02:00 to 03:00): null too.
     *
     * @return ?list<array{stage: int, start: int, end: int}>
     */
    function health_sleep_stage_local(array $rows): ?array
    {
        $walls = [];
        foreach ($rows as $row) {
            $walls[] = (string) $row['started_at'];
            $walls[] = (string) $row['ended_at'];
        }

        $times  = [];
        $floor  = (int) strtotime((string) $rows[0]['night_start']) - 60;
        $unsure = [];
        foreach ($walls as $i => $wall) {
            $guess = strtotime($wall);
            if ($guess === false) {
                return null;
            }
            $could = array_values(array_filter([$guess - 3600, $guess, $guess + 3600], static fn (int $t): bool => date('Y-m-d H:i:s', $t) === $wall));
            $after = array_values(array_filter($could, static fn (int $t): bool => $t >= $floor));
            if ($after === []) {
                return null;
            }
            if (count($after) > 1) {
                $unsure[] = $i;
            }
            $times[$i] = $after[0];
            $floor     = $i % 2 === 0 ? $after[0] : $times[$i - 1];     // the next start: not before this one's start
        }

        /* A time that could be either is the first only when the clock is
           seen going back after it: a later time lower than it or than one
           after it — the hour came round again after it. */
        foreach ($unsure as $i) {
            $shown = false;
            $high  = $walls[$i];
            for ($j = $i + 1; $j < count($walls) && !$shown; $j++) {
                $shown = strcmp($walls[$j], $high) < 0;
                $high  = max($high, $walls[$j]);
            }
            if (!$shown) {
                return null;
            }
        }

        $out = [];
        foreach ($rows as $k => $row) {
            $out[] = ['stage' => (int) $row['stage'], 'start' => $times[2 * $k], 'end' => $times[2 * $k + 1]];
        }

        return $out;
    }

    /**
     * The clock a night's stages are kept on: the UTC offset of the moment
     * the session started, as the app's own time (Europe/Amsterdam) has it
     * then — +01:00 or +02:00, the same for the whole night (47.0).
     */
    function health_sleep_stage_clock(string $sessionStart): DateTimeZone
    {
        try {
            return new DateTimeZone((new DateTimeImmutable($sessionStart))->format('P'));
        } catch (Exception $e) {
            return new DateTimeZone(date_default_timezone_get());
        }
    }

    function health_sleep_sessions(int $userId, int $limit = 30): array
    {
        return db_all(
            'SELECT * FROM sleep_sessions WHERE user_id = ? ORDER BY night_of DESC LIMIT ' . (int) max(1, min($limit, 365)),
            [$userId]
        );
    }

    function health_workouts(int $userId, int $limit = 30): array
    {
        return db_all(
            'SELECT * FROM workouts WHERE user_id = ? ORDER BY started_at DESC LIMIT ' . (int) max(1, min($limit, 365)),
            [$userId]
        );
    }

    function health_nutrition_entries(int $userId, string $date): array
    {
        return db_all(
            'SELECT * FROM nutrition_entries
              WHERE user_id = ? AND DATE(consumed_at) = ?
              ORDER BY consumed_at',
            [$userId, $date]
        );
    }

    /**
     * Daily scores. Nothing writes these yet: the scoring formula is not
     * decided, so the table stays empty rather than holding a guess.
     */
    function health_daily_scores(int $userId, string $from, string $to): array
    {
        return db_all(
            'SELECT score_date, domain, score
               FROM daily_scores
              WHERE user_id = ? AND score_date BETWEEN ? AND ?
           ORDER BY score_date',
            [$userId, $from, $to]
        );
    }
}
