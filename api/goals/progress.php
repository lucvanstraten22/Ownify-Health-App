<?php
/**
 * Records what the person did today, for a goal they keep by hand.
 *
 * One row per goal per day, and the goal's type decides what a second entry
 * on the same day does (goal_record_entry):
 *
 *   Mijlpaal   a new result. The better of the day's results is kept, and a
 *              worse one never lowers the goal — its progress is the best.
 *   Optellen   an amount to add ("3 km erbij"), or, counted in days, today
 *              ticked off.
 *   Streak     today ticked off. Ticking twice is still one day.
 *
 * The answer is worked out afterwards by the same engine that draws the page,
 * so what this returns is what the page will show.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goals.php';
require_once dirname(__DIR__, 2) . '/includes/goal-progress.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$goalId = (int) ($_POST['goal_id'] ?? 0);

$goal = $goalId > 0 ? goal_get($userId, $goalId) : null;

if ($goal === null) {
    api_fail('Onbekend doel.', 404);
}

/* An automatic goal reads itself. Letting somebody type over it would leave
   two numbers claiming to be the same thing, and the next render would throw
   the typed one away — so it is refused here rather than silently lost. */
if (!goal_is_manual($goal)) {
    api_fail('Dit doel leest zijn voortgang zelf uit je gegevens.', 409);
}

if ($goal['status'] === 'completed') {
    api_fail('Dit doel is al behaald.', 409);
}

$raw   = $_POST['value'] ?? null;
$raw   = $raw === null ? '' : trim((string) $raw);
$value = null;

if ($raw !== '') {
    /* "72,5" or "72.5", "1.234" or "1,234": as the person writes it. */
    $typed = i18n_parse_number($raw);

    if ($typed === null || $typed < 0 || $typed > 1e9) {
        api_fail('Vul een geldig getal in.', 422);
    }

    $value = $typed;

    /* Typed in the goal's shown unit (`input_units`, Instellingen ›
       Eenheden): a result of 176 lb is kept as 79.8 kg, once, here. A form
       that does not say typed the stored unit, as before. */
    if (($_POST['input_units'] ?? 'metric') === 'imperial') {
        $value = goal_unit_typed($value, goal_unit_shown(goal_manual_unit($goal['target_unit'] ?? null), true));
    }
}

$written = goal_record_entry($userId, $goal, $value);

if (!$written['ok']) {
    api_fail((string) $written['error'], 422);
}

/* Recomputed, stored and — at 100% — completed, exactly as a page render
   would do it. */
$progress = goal_refresh_row($userId, $goal);
$after    = goal_get($userId, $goalId);

api_ok([
    'kind'      => $progress['kind'],
    'current'   => $progress['current'],
    'percent'   => $progress['percent'],
    'best'      => $progress['best'],
    'total'     => $progress['total'],
    'streak'    => $progress['streak'],
    'longest'   => $progress['longest'],
    'completed' => ($after['status'] ?? '') === 'completed',
]);
