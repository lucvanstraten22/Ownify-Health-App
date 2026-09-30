<?php
/**
 * Creates a goal for the signed-in user.
 *
 * The rules — what makes a goal measurable the way its type measures things,
 * and the board's limits — live in includes/goal-create.php, which the
 * assistant's proposals go through too: one set of rules, whoever asks.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goal-create.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

$result = goal_create_from_input($userId, $_POST);

if (!$result['ok']) {
    api_fail((string) $result['error'], $result['status']);
}

api_ok(['goal_id' => $result['goal_id']]);
