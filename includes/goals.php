<?php
/**
 * Goals — PRIVATE, scoped to the owner like health data.
 *
 * A goal that names a metric_type can have its progress read from health data;
 * one that does not keeps dated snapshots in goal_progress. The percentage is
 * calculated here rather than stored twice.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';

if (!function_exists('goals_for_user')) {

    function goals_for_user(int $userId, ?string $status = 'active'): array
    {
        if ($status === null) {
            return db_all('SELECT * FROM goals WHERE user_id = ? ORDER BY start_date DESC', [$userId]);
        }

        return db_all(
            'SELECT * FROM goals WHERE user_id = ? AND status = ? ORDER BY start_date DESC',
            [$userId, $status]
        );
    }

    function goal_get(int $userId, int $goalId): ?array
    {
        return db_one('SELECT * FROM goals WHERE id = ? AND user_id = ?', [$goalId, $userId]);
    }

    function goal_create(int $userId, array $goal): ?int
    {
        db_run(
            'INSERT INTO goals
                (user_id, name, category, goal_type, metric_type_id, target_value,
                 target_unit, direction, start_date, end_date, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $goal['name'],
                $goal['category'] ?? 'general',
                $goal['goal_type'] ?? 'target_value',
                isset($goal['metric_code']) ? health_metric_type_id($goal['metric_code']) : null,
                $goal['target_value'] ?? null,
                $goal['target_unit'] ?? null,
                $goal['direction'] ?? 'increase',
                $goal['start_date'] ?? date('Y-m-d'),
                $goal['end_date'] ?? null,
                $goal['status'] ?? 'active',
            ]
        );

        return db_insert_id();
    }

    /** History for the progress chart, and the value for goals without a metric. */
    function goal_record_progress(int $userId, int $goalId, ?float $currentValue, ?float $percent = null): bool
    {
        if (goal_get($userId, $goalId) === null) {
            return false;   // not this user's goal
        }

        db_run(
            'INSERT INTO goal_progress (goal_id, recorded_on, current_value, percent_complete)
                  VALUES (?, CURDATE(), ?, ?)
             ON DUPLICATE KEY UPDATE current_value = VALUES(current_value),
                                     percent_complete = VALUES(percent_complete),
                                     computed_at = NOW()',
            [$goalId, $currentValue, $percent]
        );

        return true;
    }

    function goal_latest_progress(int $userId, int $goalId): ?array
    {
        return db_one(
            'SELECT p.recorded_on, p.current_value, p.percent_complete
               FROM goal_progress p
               JOIN goals g ON g.id = p.goal_id
              WHERE p.goal_id = ? AND g.user_id = ?
           ORDER BY p.recorded_on DESC
              LIMIT 1',
            [$goalId, $userId]
        );
    }

    /**
     * Percentage towards the target. Calculated, not stored, so it can never
     * disagree with the underlying value.
     */
    function goal_percent(array $goal, ?float $currentValue): ?float
    {
        $target = $goal['target_value'] === null ? null : (float) $goal['target_value'];

        if ($target === null || $currentValue === null || $target == 0.0) {
            return null;
        }

        $percent = match ($goal['direction']) {
            'decrease' => $target / max($currentValue, 0.0001) * 100,
            default    => $currentValue / $target * 100,
        };

        return round(max(0.0, min(100.0, $percent)), 2);
    }
}
