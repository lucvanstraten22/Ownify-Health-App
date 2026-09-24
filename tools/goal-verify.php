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
 * screen for either, which is exactly why goals could not be tested before.
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

[$question, $arg, $extra, $more] = array_pad(explode(':', (string) ($options['ask'] ?? '')), 4, null);

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
$tidy = static fn (int|float|null $v): string => $v === null ? '(none)' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');

$progress = static fn (): array => goal_progress_compute($userId, $goal());

/**
 * Moves a goal's start back, so a test can have a week of days in it without
 * waiting a week. The endpoint always starts a goal today; this is a
 * test-only write, which is why it lives here and not in the app.
 */
$backdate = static function (int $days) use ($userId, $arg): DateTimeImmutable {
    $start = (new DateTimeImmutable('today'))->modify('-' . $days . ' day');

    db_run(
        'UPDATE goals SET start_date = ? WHERE id = ? AND user_id = ?',
        [$start->format('Y-m-d'), (int) $arg, $userId]
    );

    return $start;
};

switch ($question) {
    /* ------------------------------------------------ reading it back */

    case 'source':
        $row = $goal();
        echo $row['source_kind'] . ':' . ($row['source_key'] ?? '');
        break;

    case 'mode':
        echo $goal()['tracking_mode'];
        break;

    /* What the column holds — the new name after 007, an old one before. */
    case 'type':
        echo $goal()['goal_type'];
        break;

    /* What the engine reads it as, whichever the column holds. */
    case 'kind':
        echo goal_kind($goal());
        break;

    case 'direction':
        echo $goal()['direction'];
        break;

    case 'unit':
        echo $goal()['target_unit'] ?? '(none)';
        break;

    case 'target':
        echo $tidy($goal()['target_value'] === null ? null : (float) $goal()['target_value']);
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
    case 'current':
    case 'best':
    case 'latest':
    case 'total':
    case 'streak':
    case 'longest':
        echo $tidy($progress()[$question]);
        break;

    /* The figures stored on the row itself (migration 007), exactly as
       phpMyAdmin would show them. */
    case 'stored':
        if (!goal_progress_stored()) {
            echo '(no columns)';
            break;
        }
        $row = $goal();
        echo implode(' ', [
            'best=' . $tidy($row['best_value'] === null ? null : (float) $row['best_value']),
            'total=' . $tidy($row['total_value'] === null ? null : (float) $row['total_value']),
            'streak=' . $tidy($row['streak_current'] === null ? null : (float) $row['streak_current']),
            'longest=' . $tidy($row['streak_best'] === null ? null : (float) $row['streak_best']),
            'pct=' . $tidy($row['progress_pct'] === null ? null : (float) $row['progress_pct']),
        ]);
        break;

    /* How many calendar days of a day-counting goal are in each state. */
    case 'met':
    case 'missed':
    case 'unknown':
    case 'pending':
        $count = 0;
        foreach ($progress()['days'] as $day) {
            if ($day['state'] === $question) {
                $count++;
            }
        }
        echo $count;
        break;

    case 'rows':
        echo (int) db_value(
            'SELECT COUNT(*) FROM goal_progress p JOIN goals g ON g.id = p.goal_id WHERE p.goal_id = ? AND g.user_id = ?',
            [(int) $arg, $userId]
        );
        break;

    /* What the chart would draw: "date=value" pairs, oldest first. */
    case 'series':
        $series = goal_series($userId, $goal());
        echo $series['mode'] . ' ' . implode(',', array_map(
            static fn (array $p): string => $tidy($p['value']),
            $series['points']
        ));
        break;

    case 'count':
        echo (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$userId]);
        break;

    /* ------------------------------------------------ writing test data */

    /* A weight reading now, as a scale or a phone would leave one. */
    case 'weigh':
        db_run(
            'INSERT INTO user_measurements (user_id, measurement_type, value, unit, measured_at)
                  VALUES (?, ?, ?, ?, NOW())',
            [$userId, 'weight', (float) $extra, 'kg']
        );
        goal_refresh($userId, (int) $arg);
        echo 'ok';
        break;

    /*
     * One figure per day for a metric, ending today, with the goal's start
     * moved back to the first of them:
     *
     *     steps:12:8000,11000,-,9000
     *
     * is 8000 three days ago, 11000 two days ago, nothing yesterday — not a
     * zero, nothing — and 9000 today.
     */
    case 'metric':
        $code   = (string) $extra;
        $values = explode(',', (string) $more);
        $start  = $backdate(count($values) - 1);

        foreach ($values as $offset => $value) {
            if ($value === '-' || $value === '') {
                continue;
            }

            health_record_metric($userId, $code, (float) $value, $start->modify('+' . $offset . ' day')->format('Y-m-d'), 'manual');
        }

        goal_refresh($userId, (int) $arg);
        echo 'ok';
        break;

    /* More of the same metric on one day, days ago, without moving the start. */
    case 'add_metric':
        [$code, $daysAgo] = explode(',', (string) $extra) + [1 => '0'];
        health_record_metric(
            $userId,
            $code,
            (float) $more,
            (new DateTimeImmutable('today'))->modify('-' . (int) $daysAgo . ' day')->format('Y-m-d'),
            'manual'
        );
        goal_refresh($userId, (int) $arg);
        echo 'ok';
        break;

    /*
     * A hand-kept entry on an earlier day, exactly as the endpoint would have
     * written it that day:
     *
     *     entry:12:3:60     60, three days ago
     *     entry:12:2:-      a tick, two days ago
     */
    case 'entry':
        $row = $goal();
        if ((new DateTimeImmutable((string) $row['start_date'])) > (new DateTimeImmutable('today'))->modify('-' . (int) $extra . ' day')) {
            $backdate((int) $extra);
            $row = $goal();
        }
        $written = goal_record_entry(
            $userId,
            $row,
            $more === '-' || $more === null ? null : (float) $more,
            (new DateTimeImmutable('today'))->modify('-' . (int) $extra . ' day')
        );
        goal_refresh($userId, (int) $arg);
        echo $written['ok'] ? 'ok' : (string) $written['error'];
        break;

    case 'backdate':
        $backdate((int) $extra);
        goal_refresh($userId, (int) $arg);
        echo 'ok';
        break;

    default:
        fwrite(STDERR, "Unknown question: {$question}\n");
        exit(1);
}

echo "\n";
