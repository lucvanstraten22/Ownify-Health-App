<?php
/**
 * Deletes one of the signed-in user's goals, and its progress history with it.
 *
 * Deleting the primary goal hands its place to the first goal under
 * Secundaire doelen, in the order the board shows them — worked out from the
 * board as it stands before the goal goes (goals_prepare(), goals_successor()
 * in lib/goals.php). The website and the app send `successor`: the card they
 * moved up. The answer's `primary` is the goal that is primary now, as
 * stored (null when no goal is left to be).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goals.php';
require_once dirname(__DIR__, 2) . '/lib/render.php';
require_once dirname(__DIR__, 2) . '/lib/goals.php';
require_once dirname(__DIR__, 2) . '/lib/hydrate-goals.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$goalId = (int) ($_POST['goal_id'] ?? 0);
$shown  = trim((string) ($_POST['successor'] ?? ''));

$successor = null;
if ($goalId > 0) {
    $board     = goals_prepare(hydrate_goals(require dirname(__DIR__, 2) . '/config/goals.php', $userId));
    $successor = goals_successor($board, (string) $goalId, $shown === '' ? null : $shown);
}

if ($goalId <= 0 || !goal_delete($userId, $goalId, $successor === null ? null : (int) $successor)) {
    api_fail('Onbekend doel.', 404);
}

$primary = db_value(
    "SELECT id FROM goals WHERE user_id = ? AND priority = 'primary' AND status IN ('active','paused')",
    [$userId]
);

api_ok(['primary' => $primary === null ? null : (string) $primary]);
