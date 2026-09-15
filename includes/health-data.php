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

        $aggregate = match ($type['aggregation']) {
            'sum' => 'SUM(value)',
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

        $aggregate = match ($type['aggregation']) {
            'sum' => 'SUM(value)',
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
