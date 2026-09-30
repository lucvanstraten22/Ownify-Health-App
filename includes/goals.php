<?php
/**
 * Goals — PRIVATE, scoped to the owner like health data.
 *
 * The rows and the board's rules: creating, the limit on active goals, the
 * one primary, pausing, deleting. Where a goal stands — its best result, its
 * streak, its total, its percentage — is worked out in one place only,
 * includes/goal-progress.php, so nothing here calculates a percentage.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';

/**
 * Active goals at most, paused ones included: one primary, the rest
 * secondary. The number is config/goals.php's ('limits' → 'active') — the one
 * the Doelen page and the app show — so changing it there changes it here.
 */
if (!defined('GOAL_MAX_ACTIVE')) {
    define('GOAL_MAX_ACTIVE', max(1, (int) (((array) require dirname(__DIR__) . '/config/goals.php')['limits']['active'] ?? 3)));
}

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

    /**
     * Creates a goal, subject to the board's limits.
     * Returns the new id, or null when the board is full.
     */
    function goal_create(int $userId, array $goal): ?int
    {
        if (!goal_has_room($userId)) {
            return null;
        }

        $wantsPrimary = ($goal['priority'] ?? 'secondary') === 'primary';

        require_once __DIR__ . '/goal-progress.php';

        $sourceKind = $goal['source_kind'] ?? 'manual';
        $sourceKey  = $goal['source_key'] ?? null;
        $isAuto     = $sourceKind !== 'manual' && goal_source_find($sourceKind, $sourceKey) !== null;

        /* Mijlpaal, Streak or Optellen, written in whichever vocabulary the
           column has — before migration 007 it only knows the old four. */
        $kind     = $goal['kind'] ?? 'milestone';
        $goalType = goal_kind_to_db($kind);

        /* A metric source also fills metric_type_id, so everything written
           before source_kind existed keeps reading the column it expects. */
        $metricTypeId = $isAuto && $sourceKind === 'metric'
            ? health_metric_type_id((string) $sourceKey)
            : (isset($goal['metric_code']) ? health_metric_type_id($goal['metric_code']) : null);

        /* The baseline, read now rather than later — for a Mijlpaal, the one
           type measured from somewhere. A goal to get down to 80 kg cannot be
           measured against where you started unless somebody wrote down where
           you started, and the only moment that is knowable is this one.
           Null when there is no reading yet, and null stays null: a baseline
           we invent is a percentage we invent. A Streak and an Optellen goal
           start from nothing by definition, so they have none. */
        $startValue = $kind === 'milestone' ? ($goal['start_value'] ?? null) : null;

        if ($isAuto && $kind === 'milestone' && $startValue === null) {
            $startValue = goal_source_latest($userId, (string) $sourceKind, (string) $sourceKey);
        }

        if (!goal_sources_available()) {
            /* Migration 006 is not in yet. Write what the old schema holds, so
               creating a goal keeps working exactly as it did. */
            db_run(
                'INSERT INTO goals
                    (user_id, name, category, goal_type, metric_type_id, target_value,
                     target_unit, direction, start_date, end_date, status, priority)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $goal['name'],
                    $goal['category'] ?? 'general',
                    $goalType,
                    $metricTypeId,
                    $goal['target_value'] ?? null,
                    $goal['target_unit'] ?? null,
                    $goal['direction'] ?? 'increase',
                    $goal['start_date'] ?? date('Y-m-d'),
                    $goal['end_date'] ?? null,
                    $goal['status'] ?? 'active',
                    'secondary',
                ]
            );
        } else {
            db_run(
                'INSERT INTO goals
                    (user_id, name, category, goal_type, tracking_mode, source_kind, source_key,
                     metric_type_id, target_value, start_value, daily_target,
                     target_unit, direction, start_date, end_date, status, priority)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $goal['name'],
                    $goal['category'] ?? 'general',
                    $goalType,
                    $isAuto ? 'auto' : 'manual',
                    $isAuto ? $sourceKind : 'manual',
                    $isAuto ? $sourceKey : null,
                    $metricTypeId,
                    $goal['target_value'] ?? null,
                    $startValue,
                    $goal['daily_target'] ?? null,
                    $goal['target_unit'] ?? null,
                    $goal['direction'] ?? 'increase',
                    $goal['start_date'] ?? date('Y-m-d'),
                    $goal['end_date'] ?? null,
                    $goal['status'] ?? 'active',
                    'secondary',
                ]
            );
        }

        $goalId = db_insert_id();

        if ($goalId === null) {
            return null;
        }

        /* Promotion is a swap, so it runs through the one function that knows
           how to do it. A first goal becomes primary either way. */
        if ($wantsPrimary) {
            goal_set_primary($userId, $goalId);
        } else {
            goal_ensure_primary($userId);
        }

        return $goalId;
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

    /* ====================================================================
       THE BOARD'S RULES
       --------------------------------------------------------------------
       GOAL_MAX_ACTIVE active goals, exactly one of them primary. These are enforced
       here, on write, because a rule that only the interface knows is not a
       rule: the endpoints and any future import both come through this file.
       ==================================================================== */

    /** Active means it counts against the limit; paused still does. */
    function goal_active_count(int $userId): int
    {
        return (int) db_value(
            "SELECT COUNT(*) FROM goals WHERE user_id = ? AND status IN ('active','paused')",
            [$userId]
        );
    }

    function goal_has_room(int $userId): bool
    {
        return goal_active_count($userId) < GOAL_MAX_ACTIVE;
    }

    /**
     * Makes one goal the primary, in a transaction with demoting the old one.
     *
     * Promotion is a swap rather than an addition: the board has exactly one
     * headline slot, so the goal that held it becomes secondary in the same
     * breath. Doing it in two statements outside a transaction is how a board
     * ends up with two primaries or none.
     */
    function goal_set_primary(int $userId, int $goalId): bool
    {
        if (goal_get($userId, $goalId) === null) {
            return false;
        }

        $pdo = db();
        if ($pdo === null) {
            return false;
        }

        try {
            $pdo->beginTransaction();
            db_run("UPDATE goals SET priority = 'secondary' WHERE user_id = ? AND priority = 'primary'", [$userId]);
            db_run("UPDATE goals SET priority = 'primary' WHERE id = ? AND user_id = ?", [$goalId, $userId]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }

        return true;
    }

    /**
     * Keeps the promise that there is always exactly one primary.
     *
     * Called after anything that can remove the primary — a delete, a
     * completion — so the headline slot never stands empty while active goals
     * exist. The oldest active goal inherits it.
     */
    function goal_ensure_primary(int $userId): void
    {
        $hasPrimary = db_value(
            "SELECT id FROM goals WHERE user_id = ? AND priority = 'primary' AND status IN ('active','paused')",
            [$userId]
        );

        if ($hasPrimary !== null) {
            return;
        }

        $next = db_value(
            "SELECT id FROM goals WHERE user_id = ? AND status IN ('active','paused')
              ORDER BY start_date, id LIMIT 1",
            [$userId]
        );

        if ($next !== null) {
            db_run("UPDATE goals SET priority = 'primary' WHERE id = ? AND user_id = ?", [(int) $next, $userId]);
        }
    }

    /**
     * Moves a goal between statuses.
     *
     * The user id is part of the WHERE clause, not a check before it: a goal
     * id that belongs to someone else simply matches no row.
     */
    function goal_set_status(int $userId, int $goalId, string $status): bool
    {
        if (!in_array($status, ['active', 'paused', 'completed', 'abandoned'], true)) {
            return false;
        }

        $statement = db_run(
            'UPDATE goals SET status = ? WHERE id = ? AND user_id = ?',
            [$status, $goalId, $userId]
        );

        if ($statement === null || $statement->rowCount() === 0) {
            return false;
        }

        goal_ensure_primary($userId);

        return true;
    }

    /** Deleting is real: the progress history goes with it, by cascade. */
    function goal_delete(int $userId, int $goalId): bool
    {
        $statement = db_run('DELETE FROM goals WHERE id = ? AND user_id = ?', [$goalId, $userId]);

        if ($statement === null || $statement->rowCount() === 0) {
            return false;
        }

        goal_ensure_primary($userId);

        return true;
    }
}
