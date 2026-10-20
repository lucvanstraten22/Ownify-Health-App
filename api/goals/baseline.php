<?php
/**
 * A Mijlpaal's starting point, before it is made — for the wizard's third
 * step on the website and in the app: the measurement on the start date, or
 * else the most recent one before it (goal_baseline_for()), in its stored
 * unit. `baseline` null: there is none, and the person types it.
 *
 *   measurement   a code from config/goals.php `measurements`
 *   start_date    Y-m-d (default today)
 *
 * Also says whether the measurement is read automatically for this person
 * (`auto`): only then is a measured baseline used.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goal-create.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$input  = api_json_body();

$code        = is_string($input['measurement'] ?? null) ? $input['measurement'] : '';
$measurement = $code === '' || $code === 'custom' ? null : goal_measurement($code);

if ($measurement === null) {
    api_fail('Onbekende meting.', 404);
}

$today = goal_today();
$start = goal_parse_date(is_string($input['start_date'] ?? null) ? $input['start_date'] : '') ?? $today;

$auto     = goal_user_has_data($userId, $measurement, $today);
$baseline = $auto ? goal_baseline_for($userId, $measurement, $start, $today) : null;

api_ok([
    'auto'     => $auto,
    'baseline' => $baseline === null ? null : [
        'value'  => $baseline['value'],
        'on'     => $baseline['on'],
        'source' => $baseline['source'],
        'label'  => i18n_date($baseline['on'], 'day_mon'),
    ],
]);
