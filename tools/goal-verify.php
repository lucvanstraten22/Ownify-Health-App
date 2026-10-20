<?php
/**
 * The database half of the goal test, and the only way it writes health data.
 *
 *     php tools/goal-verify.php --user=goaltest_… --ask=percent:12
 *     php tools/goal-verify.php --user=goaltest_… --cleanup
 *
 * tools/goals-test.sh drives the endpoints over HTTP. Two things it cannot do
 * from there: read a column back to prove what was stored, and put a weight,
 * a step count or a workout into the database in the first place — the app
 * has no entry screen for those (they come from Health Connect and Polar).
 *
 * The questions are a fixed list in this file. Nothing from the command line
 * reaches a query except as a bound parameter, and it refuses any account not
 * named goaltest*.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/goal-progress.php';

const GOAL_TEST_PREFIX = 'goaltest';

$options = getopt('', ['user:', 'ask:', 'cleanup', 'help']);

if ($options === false || $options === [] || isset($options['help']) || !isset($options['user'])) {
    fwrite(STDERR, "Usage: php tools/goal-verify.php --user=<name> --ask=<question>|--cleanup\n");
    exit(1);
}

$username = (string) $options['user'];

if (!str_starts_with($username, GOAL_TEST_PREFIX)) {
    fwrite(STDERR, "Refusing: '{$username}' is not a test account.\n");
    exit(1);
}

if (!db_available()) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

$userId = db_value('SELECT id FROM users WHERE username = ?', [$username]);

if ($userId === null) {
    fwrite(STDERR, "No such account: {$username}\n");
    exit(1);
}

$userId = (int) $userId;

if (isset($options['cleanup'])) {
    db_run('DELETE FROM users WHERE id = ? AND username = ?', [$userId, $username]);
    echo "Removed {$username}.\n";
    exit(0);
}

[$question, $arg, $extra] = array_pad(explode(':', (string) ($options['ask'] ?? ''), 3), 3, null);

/** The goal, owner-scoped, or exit. */
$goal = static function () use ($userId, $arg): array {
    $row = goal_get($userId, (int) $arg);

    if ($row === null) {
        fwrite(STDERR, "No such goal for this user.\n");
        exit(1);
    }

    return $row;
};

/** Trailing zeroes make string comparison in a shell script miserable. */
$tidy = static fn (int|float|string|null $v): string => $v === null ? '(none)' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');

$day = static fn (int $daysAgo): string => (new DateTimeImmutable('today'))->modify('-' . $daysAgo . ' day')->format('Y-m-d');

switch ($question) {

    /* ---------------------------------------------- what was stored */

    case 'status':
        echo $goal()['status'];
        break;

    case 'tracking':
        echo $goal()['tracking'];
        break;

    case 'unit':
        echo $goal()['unit_code'] ?? ('custom:' . $goal()['custom_unit']);
        break;

    case 'target':
        echo $tidy($goal()['target_value']);
        break;

    case 'baseline':
        echo $tidy($goal()['baseline_value']) . ' ' . ($goal()['baseline_source'] ?? '(none)');
        break;

    /* The stored percentage: progress_pct while running, final_pct once finished. */
    case 'percent':
        $row = $goal();
        echo $tidy(goal_is_finished($row) ? $row['final_pct'] : $row['progress_pct']);
        break;

    case 'final':
        echo $tidy($goal()['final_pct']);
        break;

    /* A daily streak's days as stored, oldest first: met,missed,unknown,pending. */
    case 'days':
        echo implode(',', array_column(db_all('SELECT state FROM goal_day_results WHERE goal_id = ? ORDER BY day', [(int) $goal()['id']]), 'state'));
        break;

    /* A per-period streak's periods as stored: "met 2/2,pending 1/2". */
    case 'periods':
        echo implode(',', array_map(
            static fn (array $p): string => $p['state'] . ' ' . $p['achieved'] . '/' . $p['required'],
            db_all('SELECT state, achieved, required FROM goal_period_results WHERE goal_id = ? ORDER BY period_index', [(int) $goal()['id']])
        ));
        break;

    case 'count':
        echo (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$userId]);
        break;

    /* The goal stored as primary — what a reload, the website and the app read. */
    case 'primary':
        $primary = db_value("SELECT id FROM goals WHERE user_id = ? AND priority = 'primary' AND status IN ('active','paused')", [$userId]);
        echo $primary === null ? '(none)' : (int) $primary;
        break;

    /* ------------------------------------------------ writing test data */

    /* A weigh-in, days ago: weigh:<days ago>:<kg>. */
    case 'weigh':
        db_run(
            'INSERT INTO user_measurements (user_id, measurement_type, value, unit, measured_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, 'weight', (float) $extra, 'kg', $day((int) $arg) . ' 08:00:00']
        );
        goal_refresh_all($userId);
        echo 'ok';
        break;

    /* One day's figure for a metric: metric:<days ago>:<code>,<value>. */
    case 'metric':
        [$code, $value] = explode(',', (string) $extra) + [1 => '0'];
        health_record_metric($userId, $code, (float) $value, $day((int) $arg), 'manual');
        goal_refresh_all($userId);
        echo 'ok';
        break;

    /* A workout, days ago: workout:<days ago>:<hh:mm>. */
    case 'workout':
        db_run(
            'INSERT INTO workouts (user_id, activity_type, started_at, duration_seconds) VALUES (?, ?, ?, ?)',
            [$userId, 'strength', $day((int) $arg) . ' ' . ($extra ?: '18:00') . ':00', 2700]
        );
        goal_refresh_all($userId);
        echo 'ok';
        break;

    /* Works the goal out as on a later day: refresh:<id>:<days ahead> — its end date passing. */
    case 'refresh':
        $later = (new DateTimeImmutable('today'))->modify('+' . (int) $extra . ' day');
        goal_refresh($userId, (int) $goal()['id'], $later);
        echo 'ok';
        break;

    default:
        fwrite(STDERR, "Unknown question: {$question}\n");
        exit(1);
}

echo "\n";
