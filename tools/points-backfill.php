<?php
/**
 * Awards the leaderboard points for what is already in the database.
 *
 *     php tools/points-backfill.php              every account
 *     php tools/points-backfill.php --user=12    one account, by id
 *
 * Points are awarded the moment a night, workout, rating or step count is
 * saved or synced. What was saved before migration 010 was imported never went
 * through that, so it has no points yet. This runs each of those records
 * through exactly the same rules as a live save (includes/points.php): nothing
 * from before the account existed, nothing dated in the future, each event
 * once.
 *
 * Safe to run as often as you like. Every award is keyed on the event it is
 * for, so a second run finds each one already right and changes nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/points.php';

$options = getopt('', ['user:', 'help']);

if ($options === false || isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/points-backfill.php [--user=<id>]\n");
    exit(0);
}

if (!db_available()) {
    fwrite(STDERR, "No database connection — run php tools/check-config.php.\n");
    exit(1);
}

if (!points_available()) {
    fwrite(STDERR, "point_events has no award_key yet: import database/migrations/010-health-score-and-points.sql first.\n");
    exit(1);
}

set_time_limit(0);

$users = isset($options['user'])
    ? db_all('SELECT id FROM users WHERE id = ?', [(int) $options['user']])
    : db_all('SELECT id FROM users ORDER BY id');

if ($users === []) {
    fwrite(STDERR, "No such account.\n");
    exit(1);
}

$rating = health_metric_type_id('nutrition_rating');
$steps  = health_metric_type_id('steps');

/* The dates a metric has readings on, for one person. */
$days = static function (int $userId, ?int $type): array {
    if ($type === null) {
        return [];
    }

    return array_column(db_all(
        'SELECT DISTINCT recorded_on FROM health_metrics WHERE user_id = ? AND metric_type_id = ?',
        [$userId, $type]
    ), 'recorded_on');
};

echo "JoLu points backfill\n", str_repeat('-', 72), "\n";

$accounts = 0;
$changed  = 0;

foreach ($users as $user) {
    $userId = (int) $user['id'];

    $touched = [
        'nights'         => array_column(db_all('SELECT DISTINCT night_of FROM sleep_sessions WHERE user_id = ?', [$userId]), 'night_of'),
        'workouts'       => array_map('intval', array_column(db_all('SELECT id FROM workouts WHERE user_id = ?', [$userId]), 'id')),
        'nutrition_days' => $days($userId, $rating),
        'step_days'      => $days($userId, $steps),
    ];

    if (array_sum(array_map('count', $touched)) === 0) {
        continue;
    }

    $changes = points_process($userId, $touched);
    $delta   = array_sum(array_column($changes, 'delta'));
    $total   = (int) db_value('SELECT COALESCE(SUM(points), 0) FROM point_events WHERE user_id = ?', [$userId]);

    printf(
        "account %-6d %4d nights, %4d workouts, %4d rated days, %4d step days -> %3d award(s) changed, %+d points (total %d)\n",
        $userId,
        count($touched['nights']),
        count($touched['workouts']),
        count($touched['nutrition_days']),
        count($touched['step_days']),
        count($changes),
        $delta,
        $total
    );

    $accounts++;
    $changed += count($changes);
}

echo str_repeat('-', 72), "\n";
printf("%d account(s) with records, %d award(s) changed.%s\n", $accounts, $changed,
    $changed === 0 ? ' Everything was already right.' : ' Running it again changes nothing.');
