<?php
/**
 * Deletes one of the signed-in user's goals, and its progress history with it.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goals.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$goalId = (int) ($_POST['goal_id'] ?? 0);

if ($goalId <= 0 || !goal_delete($userId, $goalId)) {
    api_fail('Onbekend doel.', 404);
}

api_ok();
