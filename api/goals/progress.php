<?php
/**
 * Logs progress on a goal the person keeps by hand — only ever from the
 * goal's own page, on the website or in the app (docs/GOALS.md):
 *
 *   Mijlpaal   `value`: a new measurement in the goal's unit; the latest counts
 *   Optellen   `value`: an amount to add, in the goal's unit
 *   Streak     `done`: 1 ticks the day off, 0 un-ticks it; once a day
 *
 *   date       Y-m-d, the day it is for (default today): within the goal's
 *              period, not after today
 *
 * The figure is typed in the goal's own unit and stored in its stored unit
 * (goal_to_stored()). The goal is then worked out again by the same engine
 * that draws the page, and the answer is what the page will show.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goal-create.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$input  = api_json_body();
$goalId = is_scalar($input['goal_id'] ?? null) ? (int) $input['goal_id'] : 0;

$goal = $goalId > 0 ? goal_get($userId, $goalId) : null;

if ($goal === null) {
    api_fail('Onbekend doel.', 404);
}

$today = goal_today();
$on    = goal_parse_date(is_string($input['date'] ?? null) ? $input['date'] : '') ?? $today;

$value = null;
$done  = null;

if ($goal['goal_type'] === 'streak') {
    $done = in_array((string) ($input['done'] ?? '1'), ['1', 'true'], true);
} else {
    $raw   = is_scalar($input['value'] ?? null) ? trim((string) $input['value']) : '';
    $typed = $raw === '' ? null : goal_parse_number($raw);

    if ($typed === null) {
        api_fail('Vul een geldig getal in.', 422);
    }

    $value = goal_to_stored($typed, goal_unit_of($goal));
}

$written = goal_record_entry($userId, $goal, $value, $done, $on, $today);

if (!$written['ok']) {
    api_fail((string) $written['error'], 422);
}

$progress = goal_refresh_row($userId, $goal, $today);
$after    = goal_get($userId, $goalId) ?? $goal;
$finished = goal_is_finished($after);

api_ok([
    'percent'  => (int) floor($finished ? (float) $after['final_pct'] : $progress['percent']),
    'status'   => (string) $after['status'],
    'finished' => $finished,
]);
