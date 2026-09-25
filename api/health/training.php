<?php
/**
 * Records a training session.
 *
 * Nothing is overwritten: the history is the point. The same start time sent
 * again is the same workout, so its points are evaluated again, never added
 * again — and a third workout in a week pays the weekly bonus at once.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/health-score.php';
require_once dirname(__DIR__, 2) . '/includes/points.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

$workout = [
    'activity_type' => mb_substr((string) ($_POST['activity_type'] ?? 'other'), 0, 40),
    'started_at'    => ($_POST['started_at'] ?? '') === '' ? date('Y-m-d H:i:s') : (string) $_POST['started_at'],
];

if (isset($_POST['ended_at']) && $_POST['ended_at'] !== '') {
    $workout['ended_at'] = (string) $_POST['ended_at'];
}

if (isset($_POST['duration_minutes']) && $_POST['duration_minutes'] !== '') {
    $minutes = (int) $_POST['duration_minutes'];

    if ($minutes <= 0 || $minutes > 1440) {
        api_fail('Vul een duur tussen 1 en 1440 minuten in.', 422);
    }

    $workout['duration_seconds'] = $minutes * 60;
}

foreach (['distance_m', 'active_kcal', 'total_kcal', 'avg_hr', 'max_hr',
          'avg_cadence', 'elevation_gain_m', 'perceived_effort'] as $key) {
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        $workout[$key] = (float) str_replace(',', '.', (string) $_POST[$key]);
    }
}

$workoutId = health_record_workout($userId, $workout);

if ($workoutId === null) {
    api_fail('Deze training kon niet worden opgeslagen.', 422);
}

$awards = points_process($userId, ['workouts' => [$workoutId]]);
$scores = health_score_summary(health_score_refresh($userId));

api_ok([
    'workout_id' => $workoutId,
    'scores'     => array_diff_key($scores, ['overall' => true]),
    'overall'    => $scores['overall'],
    'points'     => points_feedback($awards),
]);
