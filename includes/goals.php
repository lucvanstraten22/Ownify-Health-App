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

require_once __DIR__ . '/i18n.php';   // language and units (docs/I18N.md)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/health-data.php';

/**
 * Active goals at most, paused ones included: one primary, the rest
 * secondary. The number is config/goals.php's ('limits' → 'active') — the one
 * the Doelen page and the app show — so changing it there changes it here.
 */
if (!defined('GOAL_MAX_ACTIVE')) {
    define('GOAL_MAX_ACTIVE', max(1, (int) (((array) require dirname(__DIR__) . '/config/goals.php')['limits']['active'])));
}

if (!function_exists('goals_for_user')) {

    function goals_for_user(int $userId, ?string $status = 'active'): array
    {
        if ($status === null) {
            return db_all('SELECT * FROM goals WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$userId]);
        }

        return db_all(
            'SELECT * FROM goals WHERE user_id = ? AND status = ? ORDER BY created_at DESC, id DESC',
            [$userId, $status]
        );
    }

    function goal_get(int $userId, int $goalId): ?array
    {
        return db_one('SELECT * FROM goals WHERE id = ? AND user_id = ?', [$goalId, $userId]);
    }

    /**
     * Stores a goal that includes/goal-create.php has checked, subject to
     * the board's limits. Returns the new id, or null when the board is full
     * or it could not be stored.
     *
     * The first goal on the board is the primary goal, every later one
     * secondary — nobody is asked. Which is which can be changed on the
     * goal's own page (goal_set_primary(), goal_set_secondary()).
     */
    function goal_create(int $userId, array $goal): ?int
    {
        if (!goal_has_room($userId)) {
            return null;
        }

        $statement = db_run(
            'INSERT INTO goals
                (user_id, name, category_code, measurement_code, goal_type, streak_variant, tracking,
                 unit_code, custom_unit, direction, target_value, frequency, period_unit,
                 baseline_value, baseline_on, baseline_source, start_date, end_date,
                 status, priority, create_token)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', \'secondary\', ?)',
            [
                $userId,
                $goal['name'],
                $goal['category_code'],
                $goal['measurement_code'] ?? null,
                $goal['goal_type'],
                $goal['streak_variant'] ?? null,
                $goal['tracking'] ?? 'manual',
                $goal['unit_code'] ?? null,
                $goal['custom_unit'] ?? null,
                $goal['direction'] ?? 'increase',
                $goal['target_value'] ?? null,
                $goal['frequency'] ?? null,
                $goal['period_unit'] ?? null,
                $goal['baseline_value'] ?? null,
                $goal['baseline_on'] ?? null,
                $goal['baseline_source'] ?? null,
                $goal['start_date'],
                $goal['end_date'],
                $goal['create_token'] ?? null,
            ]
        );

        if ($statement === null) {
            return null;
        }

        $goalId = db_insert_id();

        if ($goalId === null) {
            return null;
        }

        /* A board without a primary goal gets this one: the first goal made
           is primary, the next ones are secondary. */
        goal_ensure_primary($userId, $goalId);

        return $goalId;
    }

    /** The goal a wizard already made with this one-time key, if it did. */
    function goal_by_token(int $userId, string $token): ?int
    {
        $id = db_value('SELECT id FROM goals WHERE user_id = ? AND create_token = ?', [$userId, $token]);

        return $id === null ? null : (int) $id;
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
     * exist. A delete and a completion name the goal that inherits it
     * ($successor: the first of Secundaire doelen — goals_successor() in
     * lib/goals.php, goal_ensure_primary_after_completion()); otherwise the
     * oldest active goal does.
     */
    function goal_ensure_primary(int $userId, ?int $successor = null): void
    {
        $hasPrimary = db_value(
            "SELECT id FROM goals WHERE user_id = ? AND priority = 'primary' AND status IN ('active','paused')",
            [$userId]
        );

        if ($hasPrimary !== null) {
            return;
        }

        if ($successor !== null) {
            $statement = db_run(
                "UPDATE goals SET priority = 'primary'
                  WHERE id = ? AND user_id = ? AND status IN ('active','paused')",
                [$successor, $userId]
            );

            if ($statement !== null && $statement->rowCount() > 0) {
                return;
            }
        }

        $next = db_value(
            "SELECT id FROM goals WHERE user_id = ? AND status IN ('active','paused')
              ORDER BY created_at, id LIMIT 1",
            [$userId]
        );

        if ($next !== null) {
            db_run("UPDATE goals SET priority = 'primary' WHERE id = ? AND user_id = ?", [(int) $next, $userId]);
        }
    }

    /**
     * Pauses or resumes a goal that is still running. Finishing is the
     * engine's (goal_finish() in includes/goal-progress.php): a finished goal
     * keeps its final percentage and is never moved back.
     *
     * The user id is part of the WHERE clause, not a check before it: a goal
     * id that belongs to someone else simply matches no row.
     */
    function goal_set_status(int $userId, int $goalId, string $status): bool
    {
        if (!in_array($status, ['active', 'paused'], true)) {
            return false;
        }

        $statement = db_run(
            "UPDATE goals SET status = ? WHERE id = ? AND user_id = ? AND status IN ('active','paused')",
            [$status, $goalId, $userId]
        );

        if ($statement === null) {
            return false;
        }

        if ($statement->rowCount() === 0) {
            /* Already so — or not a running goal of this user's. */
            return db_value("SELECT status FROM goals WHERE id = ? AND user_id = ?", [$goalId, $userId]) === $status;
        }

        goal_ensure_primary($userId);

        return true;
    }

    /**
     * After a goal was completed: when it was the primary goal, the first of
     * Secundaire doelen takes its place — the goal a delete would hand it to
     * (goals_successor() in lib/goals.php). The board is built the way the
     * pages build it, so the order is this moment's, and goals_prepare() puts
     * that goal in the empty slot; it is stored here. While that board is
     * being built, a goal it finds finished leaves the choice to the build.
     * No goal left to be primary: none is.
     */
    function goal_ensure_primary_after_completion(int $userId): void
    {
        static $building = false;

        $hasPrimary = db_value(
            "SELECT id FROM goals WHERE user_id = ? AND priority = 'primary' AND status IN ('active','paused')",
            [$userId]
        );

        if ($building || $hasPrimary !== null) {
            return;
        }

        $root = dirname(__DIR__);
        require_once $root . '/lib/render.php';
        require_once $root . '/lib/goals.php';
        require_once $root . '/lib/hydrate-goals.php';

        $building = true;
        try {
            $board = goals_prepare(hydrate_goals(config_copy('goals'), $userId));
        } finally {
            $building = false;
        }

        $heir = $board['primary']['id'] ?? null;
        goal_ensure_primary($userId, $heir === null ? null : (int) $heir);
    }

    /**
     * Makes the primary goal secondary. The primary slot does not stay empty:
     * the goal a delete or a completion would hand it to takes it — the first
     * of Secundaire doelen on the board as it is now (goals_successor() in
     * lib/goals.php, with $shown the goal the website or the app moved up) —
     * and the goal that was primary falls in under Secundaire doelen by the
     * board's own order. So when it has the highest percentage itself, the
     * next highest becomes primary and it goes first among the secondary goals.
     *
     * True when stored, or when the goal was secondary already. False when it
     * is not this user's goal, or when no other goal is left to be primary:
     * one goal on its own stays the primary goal.
     */
    function goal_set_secondary(int $userId, int $goalId, ?string $shown = null): bool
    {
        $goal = goal_get($userId, $goalId);

        if ($goal === null) {
            return false;
        }

        if ($goal['priority'] !== 'primary') {
            return true;
        }

        $root = dirname(__DIR__);
        require_once $root . '/lib/render.php';
        require_once $root . '/lib/goals.php';
        require_once $root . '/lib/hydrate-goals.php';

        $board = goals_prepare(hydrate_goals(config_copy('goals'), $userId));
        $heir  = goals_successor($board, (string) $goalId, $shown);

        return $heir !== null && goal_set_primary($userId, (int) $heir);
    }

    /**
     * Deleting is real: the progress history goes with it, by cascade. When it
     * was the primary goal, $successor takes its place (api/goals/delete.php
     * works out which: the first of Secundaire doelen).
     */
    function goal_delete(int $userId, int $goalId, ?int $successor = null): bool
    {
        $statement = db_run('DELETE FROM goals WHERE id = ? AND user_id = ?', [$goalId, $userId]);

        if ($statement === null || $statement->rowCount() === 0) {
            return false;
        }

        goal_ensure_primary($userId, $successor);

        return true;
    }
}
