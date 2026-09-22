<?php
/**
 * Creates a goal for the signed-in user.
 *
 * The board's limits — three active, one of them primary — are enforced in
 * includes/goals.php rather than here, so an import or a second endpoint
 * cannot get around them.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/goals.php';
require_once dirname(__DIR__, 2) . '/lib/hydrate-goals.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

$name = trim((string) ($_POST['name'] ?? ''));

if ($name === '' || mb_strlen($name) > 120) {
    api_fail('Geef je doel een naam van maximaal 120 tekens.', 422);
}

if (!goal_has_room($userId)) {
    api_fail('Je hebt al drie actieve doelen. Rond er een af of verwijder er een.', 409);
}

$config     = require dirname(__DIR__, 2) . '/config/goals.php';
$category   = (string) ($_POST['category'] ?? 'other');
$type       = (string) ($_POST['type'] ?? 'value');
$duration   = (string) ($_POST['duration'] ?? '');

if (!isset($config['categories'][$category])) {
    api_fail('Onbekende categorie.', 422);
}

if (!isset($config['types'][$type])) {
    api_fail('Onbekend doeltype.', 422);
}

/* Duration is a named period, so the end date is derived here rather than
   accepted from the browser: a client cannot set a goal to end in 1900. */
$start = new DateTimeImmutable('today');
$end   = null;

if (isset($config['durations'][$duration])) {
    $end = $start->modify('+' . (int) $config['durations'][$duration]['days'] . ' day');
}

$targetValue = $_POST['target_value'] ?? null;
$targetValue = ($targetValue === null || $targetValue === '') ? null : (float) $targetValue;

if ($targetValue !== null && ($targetValue <= 0 || $targetValue > 1e9)) {
    api_fail('Vul een geldige doelwaarde in.', 422);
}

$targetUnit = trim((string) ($_POST['target_unit'] ?? ''));
if (mb_strlen($targetUnit) > 20) {
    api_fail('Die eenheid is te lang.', 422);
}

$goalId = goal_create($userId, [
    'name'         => $name,
    'category'     => $category,
    'goal_type'    => goal_type_to_db($type),
    'target_value' => $targetValue,
    'target_unit'  => $targetUnit === '' ? null : $targetUnit,
    'direction'    => ($_POST['direction'] ?? 'increase') === 'decrease' ? 'decrease' : 'increase',
    'start_date'   => $start->format('Y-m-d'),
    'end_date'     => $end?->format('Y-m-d'),
    'priority'     => ($_POST['priority'] ?? 'secondary') === 'primary' ? 'primary' : 'secondary',
    'status'       => 'active',
]);

if ($goalId === null) {
    api_fail('Dit doel kon niet worden opgeslagen.', 500);
}

api_ok(['goal_id' => $goalId]);
