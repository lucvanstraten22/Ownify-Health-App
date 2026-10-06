<?php
/**
 * Records a night's sleep.
 *
 * Re-sending the same start time corrects that night rather than adding a
 * second one, so a watch that syncs twice does not double the week — and the
 * night's points are the night's, whatever it is sent: they are evaluated
 * again, never added again.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/health-score.php';
require_once dirname(__DIR__, 2) . '/includes/points.php';

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

/* A night is filed under the morning it ended. Its points first, then the
   Health Score over the 168 hours that now include it — separately. */
$night  = (new DateTimeImmutable($session['ended_at']))->format('Y-m-d');
$awards = points_process($userId, ['nights' => [$night]]);
$scores = health_score_summary(health_score_refresh($userId));

api_ok([
    'sleep_id' => $sleepId,
    'scores'   => array_diff_key($scores, ['overall' => true]),
    'overall'  => $scores['overall'],
    'points'   => points_feedback($awards),
]);
