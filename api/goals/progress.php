<?php
/**
 * Records where a goal stands today.
 *
 * One row per goal per day: confirming twice corrects the day rather than
 * adding a second entry, which is what the unique key on (goal_id,
 * recorded_on) is for.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goals.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();
$goalId = (int) ($_POST['goal_id'] ?? 0);

$goal = $goalId > 0 ? goal_get($userId, $goalId) : null;

if ($goal === null) {
    api_fail('Onbekend doel.', 404);
}

$value = $_POST['value'] ?? null;
$value = ($value === null || $value === '') ? null : (float) $value;

/* A habit or a streak is confirmed rather than measured: one tick today is
   one more day, so the value is counted rather than supplied. */
if ($value === null && in_array($goal['goal_type'], ['habit', 'streak'], true)) {
    $days = (int) db_value(
        'SELECT COUNT(*) FROM goal_progress WHERE goal_id = ? AND recorded_on < CURDATE()',
        [$goalId]
    );
    $value = (float) ($days + 1);
}

if ($value === null) {
    api_fail('Vul een waarde in.', 422);
}

$percent = goal_percent($goal, $value);

if (!goal_record_progress($userId, $goalId, $value, $percent)) {
    api_fail('Dit kon niet worden opgeslagen.', 500);
}

/* Reaching the target finishes the goal, rather than leaving it at 100%. */
if ($percent !== null && $percent >= 100.0 && $goal['status'] === 'active') {
    goal_set_status($userId, $goalId, 'completed');
}

api_ok(['value' => $value, 'percent' => $percent]);
