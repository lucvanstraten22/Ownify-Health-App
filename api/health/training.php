<?php
/**
 * Records a training session.
 *
 * Nothing is overwritten: the history is the point, and the day's score is
 * read from whatever sessions the day holds.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/scoring.php';

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

$date = substr((string) $workout['started_at'], 0, 10);

api_ok([
    'workout_id' => $workoutId,
    'scores'     => score_domains($userId, $date),
    'overall'    => score_overall($userId, $date),
]);
