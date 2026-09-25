<?php
/**
 * The daily nutrition self-assessment: how well did you eat today, 1 to 10.
 *
 * One rating per day. It is stored as that day's own reading — a manual
 * nutrition_rating with the fixed external id `daily-rating:<date>` — so saving
 * again replaces the day's rating instead of adding a second one, and a double
 * tap, a retry or a refresh is the same save. The day's nutrition points are
 * evaluated again from the rating as it now is, never added again.
 *
 *   rating  1-10 (whole numbers)
 *   date    optional, Y-m-d: today (the default) or one of the six days before
 *
 * The answer carries the scores as they now are and what the save earned, so
 * the page can say "+25 punten" without a second request.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/health-score.php';
require_once dirname(__DIR__, 2) . '/includes/points.php';
require_once dirname(__DIR__, 2) . '/includes/goal-progress.php';

api_require_post();
api_require_csrf();
api_require_database();

$userId = api_require_user();

$raw = trim((string) ($_POST['rating'] ?? ''));

if (!preg_match('/^(10|[1-9])$/', $raw)) {
    api_fail('Kies een cijfer van 1 tot 10.', 422);
}

$rating = (int) $raw;
$today  = new DateTimeImmutable('today');
$day    = $today;

if (($_POST['date'] ?? '') !== '') {
    $given = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $_POST['date']);

    if ($given === false || $given > $today || $given < $today->modify('-6 days')) {
        api_fail('Je kunt vandaag en de zes dagen daarvoor beoordelen.', 422);
    }

    $day = $given;
}

$date   = $day->format('Y-m-d');
$typeId = health_metric_type_id('nutrition_rating');

if ($typeId === null) {
    api_fail('Beoordelingen kunnen nog niet worden opgeslagen.', 503);
}

/* Today's rating is timed now; an earlier day's in its evening. */
$recordedAt = $date === $today->format('Y-m-d') ? date('Y-m-d H:i:s') : $date . ' 20:00:00';

db_run(
    'INSERT INTO health_metrics (user_id, metric_type_id, source_id, external_id, value, recorded_at)
          VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value), recorded_at = VALUES(recorded_at)',
    [$userId, $typeId, health_source_id('manual'), 'daily-rating:' . $date, $rating, $recordedAt]
);

/* A goal that follows the rating moves with it, as after a sync. */
goal_refresh_all($userId);

/* What the rating earned, and — separately — the Health Score over the 90
   days that now include it. */
$awards = points_process($userId, ['nutrition_days' => [$date]]);
$scores = health_score_summary(health_score_refresh($userId));

$earned = points_feedback($awards);
$lowered = array_filter($awards, static fn ($a) => $a['delta'] < 0);

$message = match (true) {
    $earned !== []  => $earned[0]['text'],
    $lowered !== [] => 'Opgeslagen. Je punten voor deze dag zijn aangepast.',
    default         => 'Opgeslagen.',
};

api_ok([
    'rating'  => $rating,
    'date'    => $date,
    'scores'  => $scores,
    'points'  => $earned,
    'message' => $message,
]);
