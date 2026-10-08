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
            'SELECT st.stage, st.started_at, st.ended_at
               FROM sleep_stages st
               JOIN sleep_sessions s ON s.id = st.sleep_session_id
              WHERE s.user_id = ? AND s.id IN (' . implode(',', array_fill(0, count($sessionIds), '?')) . ')
           ORDER BY st.started_at, st.id',
            [$userId, ...$sessionIds]
        );

        $periods = [];
        foreach ($rows as $row) {
            $start = strtotime((string) $row['started_at']);
            $end   = strtotime((string) $row['ended_at']);
            if ($start !== false && $end !== false && $end > $start) {
                $periods[] = ['stage' => (int) $row['stage'], 'start' => $start, 'end' => $end];
            }
        }

        return $periods;
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
