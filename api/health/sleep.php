<?php
/**
 * Records a night's sleep.
 *
 * Re-sending the same start time corrects that night rather than adding a
 * second one, so a watch that syncs twice does not double the week.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/scoring.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

$session = ['started_at' => (string) ($_POST['started_at'] ?? ''), 'ended_at' => (string) ($_POST['ended_at'] ?? '')];

foreach (['time_in_bed_minutes', 'awakenings', 'awake_minutes',
          'light_minutes', 'deep_minutes', 'rem_minutes'] as $key) {
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        $session[$key] = (int) $_POST[$key];
    }
}

if (isset($_POST['efficiency_pct']) && $_POST['efficiency_pct'] !== '') {
    $efficiency = (float) $_POST['efficiency_pct'];

    if ($efficiency < 0 || $efficiency > 100) {
        api_fail('Efficiëntie loopt van 0 tot 100.', 422);
    }

    $session['efficiency_pct'] = $efficiency;
}

$sleepId = health_record_sleep($userId, $session);

if ($sleepId === null) {
    api_fail('Vul een geldige begin- en eindtijd in.', 422);
}

$date = (new DateTimeImmutable($session['ended_at']))->format('Y-m-d');

api_ok([
    'sleep_id' => $sleepId,
    'scores'   => score_domains($userId, $date),
    'overall'  => score_overall($userId, $date),
]);
