<?php
/**
 * Pause, resume, complete or re-prioritise one of the signed-in user's goals.
 *
 * The goal id arrives from the browser, which is why every statement behind
 * this carries `AND user_id = ?`: an id belonging to someone else matches no
 * row rather than being checked and then trusted.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goals.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();
$goalId = (int) ($_POST['goal_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if ($goalId <= 0) {
    api_fail('Onbekend doel.', 400);
}

/* Confirms ownership before reporting anything back, so a wrong id cannot be
   used to learn whether a goal exists. */
if (goal_get($userId, $goalId) === null) {
    api_fail('Onbekend doel.', 404);
}

$ok = match ($action) {
    'pause'     => goal_set_status($userId, $goalId, 'paused'),
    'resume'    => goal_set_status($userId, $goalId, 'active'),
    'complete'  => goal_set_status($userId, $goalId, 'completed'),
    'primary'   => goal_set_primary($userId, $goalId),
    'secondary' => (static function () use ($userId, $goalId): bool {
        db_run("UPDATE goals SET priority = 'secondary' WHERE id = ? AND user_id = ?", [$goalId, $userId]);
        goal_ensure_primary($userId);
        return true;
    })(),
    default     => null,
};

if ($ok === null) {
    api_fail('Onbekende actie.', 400);
}

if ($ok === false) {
    api_fail('Deze wijziging kon niet worden opgeslagen.', 500);
}

api_ok();
