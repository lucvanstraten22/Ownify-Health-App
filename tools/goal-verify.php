<?php
/**
 * The database half of the goal test, and the only way it writes health data.
 *
 *     php tools/goal-verify.php --user=goaltest_… --ask=percent:12
 *     php tools/goal-verify.php --user=goaltest_… --cleanup
 *
 * tools/goals-test.sh drives the endpoints over HTTP. Two things it cannot do
 * from there: read a column back to prove what was stored, and put a weight or
 * a step count into the database in the first place — the app has no entry
 * screen for either yet, which is exactly why goals could not be tested before.
 *
 * Like tools/hc-verify.php, the questions are a fixed list in this file.
 * Nothing from the command line reaches a query except as a bound parameter,
 * and it refuses any account not named goaltest*.
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

[$question, $arg, $extra] = array_pad(explode(':', (string) ($options['ask'] ?? '')), 3, null);

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
$tidy = static fn (?float $v): string => $v === null ? '(none)' : rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');

switch ($question) {
    case 'source':
        $row = $goal();
        echo $row['source_kind'] . ':' . ($row['source_key'] ?? '');
        break;

    case 'mode':
        echo $goal()['tracking_mode'];
        break;

    case 'status':
        echo $goal()['status'];
        break;

    case 'completed_on':
        $at = $goal()['completed_at'];
        echo $at === null ? '(none)' : substr((string) $at, 0, 10);
        break;

    case 'daily':
        echo $tidy($goal()['daily_target'] === null ? null : (float) $goal()['daily_target']);
        break;

    case 'start':
        echo $tidy($goal()['start_value'] === null ? null : (float) $goal()['start_value']);
        break;

    case 'percent':
        echo $tidy(goal_progress_compute($userId, $goal())['percent']);
        break;

    case 'current':
        echo $tidy(goal_progress_compute($userId, $goal())['current']);
        break;

    /* How many days of a repeated goal are in each state. */
    case 'met':
    case 'missed':
    case 'unknown':
        $count = 0;
        foreach (goal_progress_compute($userId, $goal())['days'] as $day) {
            if ($day['state'] === $question) {
                $count++;
            }
        }
        echo $count;
        break;

    case 'count':
        echo (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$userId]);
        break;

    /* ------------------------------------------------ writing test data */

    /* A weight reading, as a scale or a phone would leave one. */
    case 'weigh':
        db_run(
            'INSERT INTO user_measurements (user_id, measurement_type, value, unit, measured_at)
                  VALUES (?, ?, ?, ?, NOW())',
            [$userId, 'weight', (float) $extra, 'kg']
        );
        goal_refresh($userId, (int) $arg);
        echo 'ok';
        break;

    /* Five days of step counts: two good, one short, one missing entirely,
       one good. The missing day is the point — it must not read as a failure. */
    case 'steps':
        /* The endpoint always starts a goal today, so a goal created a moment
           ago has exactly one day in it and nothing to evaluate. Backdating
           the start is the only way to test a month's worth of days without
           waiting a month — a test-only write, which is why it lives here and
           not in the app. */
        $row   = $goal();
        $start = (new DateTimeImmutable('today'))->modify('-4 day');

        db_run(
            'UPDATE goals SET start_date = ? WHERE id = ? AND user_id = ?',
            [$start->format('Y-m-d'), (int) $arg, $userId]
        );

        foreach ([12000, 10400, 3000, null, 15000] as $offset => $steps) {
            if ($steps === null) {
                continue;
            }

            health_record_metric(
                $userId,
                'steps',
                (float) $steps,
                $start->modify('+' . $offset . ' day')->format('Y-m-d'),
                'manual'
            );
        }

        goal_refresh($userId, (int) $arg);
        echo 'ok';
        break;

    default:
        fwrite(STDERR, "Unknown question: {$question}\n");
        exit(1);
}

echo "\n";
