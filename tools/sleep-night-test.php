<?php
/**
 * One night per date, the same for every reader — tested on the database.
 *
 *     php tools/sleep-night-test.php
 *
 * The Slaap card, the sleep score, the sleep points and sleep goals must all
 * agree on which sleep was the night of a date (health_night_main() in
 * includes/health-signals.php). This puts real nights into a throwaway
 * account — through the same Health Connect import ingest.php uses, and
 * through the same function the manual sleep form uses — and asks each of
 * those four readers what it saw:
 *
 *   1. a normal 7:15 night
 *   2. a night and a 30-minute nap
 *   3. one night recorded twice, from two sources
 *   4. Health Connect's generic "sleeping" stage (2)
 *   5. "out of bed" (3), and stages that hold no sleep at all
 *   6. a night crossing midnight
 *   7. manual and imported sleep on the same date
 *   and: a night broken by getting up, a re-sync that changes nothing, and
 *   a night stored by the old mapping that a re-sync corrects.
 *
 * The account is named sleeptest_… and deleted at the end (its rows go with
 * it). Needs a database with schema.sql and migrations up to 010 imported.
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* Under the document root on a Hestia deploy. It writes and deletes rows. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* The app's clock, as includes/bootstrap.php sets it for every request. */
date_default_timezone_set('Europe/Amsterdam');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/health-import.php';
require_once dirname(__DIR__) . '/includes/health-connect-map.php';
require_once dirname(__DIR__) . '/includes/health-score.php';
require_once dirname(__DIR__) . '/includes/points.php';
require_once dirname(__DIR__) . '/includes/goal-progress.php';
require_once dirname(__DIR__) . '/lib/hydrate-health.php';

if (!db_available()) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "\n          " . $detail) . "\n";
}

/* ------------------------------------------------------------ the account */

db_run('INSERT INTO users (username) VALUES (?)', ['sleeptest_' . bin2hex(random_bytes(4))]);
$userId = (int) db_insert_id();

/* Points only pay for what happened after the account existed; these nights
   are in the past week, so the account is from before them. */
db_run('UPDATE users SET created_at = NOW() - INTERVAL 30 DAY WHERE id = ?', [$userId]);

register_shutdown_function(static function () use ($userId): void {
    db_run('DELETE FROM point_events WHERE user_id = ?', [$userId]);
    db_run('DELETE FROM users WHERE id = ?', [$userId]);
});

/** Y-m-d, $days before today. */
function day(int $days): string
{
    return (new DateTimeImmutable('today'))->modify('-' . $days . ' days')->format('Y-m-d');
}

/** A Dutch local time on a date, as the phone sends it: "2026-09-20T23:30:00+02:00". */
function at(string $date, string $time): string
{
    return (new DateTimeImmutable($date . ' ' . $time))->format('Y-m-d\TH:i:sP');
}

/** A Health Connect sleep session; $stages as [from, to, stage] local times, $next = the times are on the next day. */
function hc_sleep(string $id, string $date, string $from, string $to, array $stages = []): array
{
    $next  = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    $clock = static fn (string $t): string => at($t < '12:00' && $from >= '12:00' ? $next : $date, $t);

    return [
        'recordType' => 'SleepSession',
        'metadata'   => ['id' => $id, 'dataOrigin' => 'com.google.android.apps.fitness'],
        'startTime'  => $clock($from),
        'endTime'    => $clock($to),
        'stages'     => array_map(
            static fn (array $s): array => ['startTime' => $clock($s[0]), 'endTime' => $clock($s[1]), 'stage' => $s[2]],
            $stages
        ),
    ];
}

function import(array $records): array
{
    global $userId;

    $mapped = health_connect_map($records);

    return health_import_records($userId, 'google_health_connect', $mapped['records']);
}

/** What each of the four readers says about the night of $date. */
function readers(string $date): array
{
    global $userId;

    $card  = hydrate_health_values($userId, $date);
    $score = null;

    $data = health_score_data($userId, new DateTimeImmutable($date . ' -3 days'), new DateTimeImmutable($date . ' +2 days'));
    foreach ($data['nights'] as $night) {
        if ($night['date'] === $date) {
            $score = $night;
        }
    }

    $points = db_value(
        'SELECT note FROM point_events WHERE user_id = ? AND award_key = ?',
        [$userId, 'sleep_duration:' . $date]
    );

    $goal = goal_sleep_by_day($userId, 'sleep_duration', $date, $date)[$date] ?? null;

    return [
        'card'   => $card['sleep_duration'] ?? null,
        'score'  => $score === null ? null : hydrate_hours((int) round($score['minutes'])),
        'points' => $points === null ? null : explode(' ', (string) $points)[0],
        'goal'   => $goal === null ? null : hydrate_hours((int) round($goal)),
        'values' => $card,
        'night'  => $score,
    ];
}

/** Every reader says $expected ("7:15"), and the points were awarded. */
function agree(string $label, string $date, string $expected): array
{
    points_process($GLOBALS['userId'], ['nights' => [$date]]);
    $r = readers($date);
    $seen = ['card' => $r['card'], 'score' => $r['score'], 'points' => $r['points'], 'goal' => $r['goal']];

    check("$label: card, score, points and goal all say $expected",
        array_unique(array_values($seen)) === [$expected], json_encode($seen));

    return $r;
}

/* ================================================================ 1 */
echo "1. a normal night\n";

$d1 = day(8);
import([hc_sleep('n1', day(9), '23:30', '07:00', [
    ['23:30', '01:00', 4], ['01:00', '02:30', 5], ['02:30', '04:00', 6], ['04:00', '04:15', 1], ['04:15', '07:00', 4],
])]);
$r = agree('7:15 asleep', $d1, '7:15');
check('  bedtime 23:30, up at 07:00, 15 minutes awake, one awakening',
    $r['values']['bedtime'] === '23:30' && $r['values']['wake_time'] === '07:00'
    && $r['values']['awake_time'] === 15 && $r['values']['awakenings'] === 1, json_encode($r['values']));
check('  the timeline: deep 20 %, REM 20 %, light 57 %, awake 3 %',
    [$r['values']['stage_deep'], $r['values']['stage_rem'], $r['values']['stage_light'], $r['values']['stage_awake']] === [20, 20, 57, 3],
    json_encode($r['values']));

/* ================================================================ 2 */
echo "2. a night and a nap\n";

$d2 = day(7);
import([
    hc_sleep('n2', day(8), '23:30', '07:00', [['23:30', '01:00', 4], ['01:00', '02:30', 5], ['02:30', '04:00', 6], ['04:00', '04:15', 1], ['04:15', '07:00', 4]]),
    hc_sleep('nap2', $d2, '14:00', '14:30'),
]);
$r = agree('the nap does not replace the night, nor add to it', $d2, '7:15');
check('  the card still shows the night: 23:30 to 07:00, with its timeline',
    $r['values']['bedtime'] === '23:30' && $r['values']['wake_time'] === '07:00' && isset($r['values']['stage_deep']),
    json_encode($r['values']));
check('  the nap is stored, not lost', (int) db_value('SELECT COUNT(*) FROM sleep_sessions WHERE user_id = ? AND night_of = ?', [$userId, $d2]) === 2);

/* ================================================================ 3 */
echo "3. one night from two sources\n";

$d3 = day(6);
import([hc_sleep('n3', day(7), '23:00', '07:00', [['23:00', '02:00', 4], ['02:00', '03:30', 5], ['03:30', '05:00', 6], ['05:00', '07:00', 4]])]);
health_record_sleep($userId, ['started_at' => day(7) . ' 22:50:00', 'ended_at' => $d3 . ' 07:05:00'], 'manual');
$r = agree('a watch night (stages) and the same night entered by hand: counted once, the one with stages', $d3, '8:00');
check('  not the 16 hours the two would add up to', $r['goal'] !== '16:15');
check('  two rows kept, one from each source',
    db_value('SELECT GROUP_CONCAT(s.code ORDER BY s.code) FROM sleep_sessions h JOIN data_sources s ON s.id = h.source_id WHERE h.user_id = ? AND h.night_of = ?', [$userId, $d3])
    === 'google_health_connect,manual');

/* ================================================================ 4 */
echo "4. the generic \"sleeping\" stage\n";

$d4 = day(5);
import([hc_sleep('n4', day(6), '23:30', '07:00', [['23:30', '03:00', 2], ['03:00', '03:10', 1], ['03:10', '07:00', 2]])]);
$r = agree('stage 2 counts as sleep: 7:20, where it used to be 0:00', $d4, '7:20');
check('  efficiency 98 %, 10 minutes awake', $r['values']['sleep_efficiency'] === 98 && $r['values']['awake_time'] === 10, json_encode($r['values']));
check('  no deep, REM or light is claimed — no timeline, and no quality score from invented zeroes',
    !isset($r['values']['stage_deep']) && $r['night']['deep'] === null && health_night_quality($r['night']) !== null,
    json_encode($r['night']));

/* ================================================================ 5 */
echo "5. out of bed\n";

$d5 = day(4);
import([hc_sleep('n5', day(5), '23:00', '07:00', [['23:00', '02:00', 4], ['02:00', '02:20', 3], ['02:20', '04:00', 5], ['04:00', '05:30', 6], ['05:30', '07:00', 4]])]);
$r = agree('20 minutes out of bed are not sleep: 7:40 of the 8 hours', $d5, '7:40');
check('  nor time in bed; the night still runs from 23:00 to 07:00',
    $r['values']['time_in_bed'] === '7:40' && $r['values']['bedtime'] === '23:00' && $r['values']['wake_time'] === '07:00',
    json_encode($r['values']));

$d5b = day(3);
import([hc_sleep('n5b', day(4), '23:15', '06:45', [['23:15', '06:45', 3]])]);
agree('stages that hold no sleep leave the session its own times, not 0:00', $d5b, '7:30');

/* ================================================================ 6 */
echo "6. across midnight\n";

$d6 = day(2);
import([hc_sleep('n6', day(3), '22:45', '06:30', [['22:45', '00:15', 4], ['00:15', '02:00', 5], ['02:00', '03:30', 6], ['03:30', '06:30', 4]])]);
$r = agree('22:45 to 06:30 is the night of the morning it ended', $d6, '7:45');
check('  filed under that morning, not the evening it began',
    db_value('SELECT night_of FROM sleep_sessions WHERE user_id = ? AND external_id = ?', [$userId, 'n6']) === $d6);
check('  stored on the Dutch clock', db_value('SELECT CONCAT(started_at, " / ", ended_at) FROM sleep_sessions WHERE user_id = ? AND night_of = ?', [$userId, $d6])
    === day(3) . ' 22:45:00 / ' . $d6 . ' 06:30:00');

/* ================================================================ 7 */
echo "7. manual and imported sleep, one rule\n";

$d7 = day(1);
health_record_sleep($userId, ['started_at' => day(2) . ' 23:00:00', 'ended_at' => $d7 . ' 07:00:00'], 'manual');
import([hc_sleep('nap7', $d7, '15:00', '15:40')]);
$r = agree('a night entered by hand and a nap from the phone: the night', $d7, '8:00');
check('  23:00 to 07:00 on the card', $r['values']['bedtime'] === '23:00' && $r['values']['wake_time'] === '07:00');

$d7b = day(10);
import([hc_sleep('n7b', day(11), '23:00', '06:30')]);
health_record_sleep($userId, ['started_at' => $d7b . ' 13:30:00', 'ended_at' => $d7b . ' 14:00:00'], 'manual');
agree('a night from the phone and a nap entered by hand: the night', $d7b, '7:30');

/* ============================================================ and more */
echo "a night broken by getting up\n";

$d8 = day(9);
import([
    hc_sleep('n8a', day(10), '23:00', '02:30', [['23:00', '02:30', 4]]),
    hc_sleep('n8b', $d8, '03:10', '07:00', [['03:10', '07:00', 4]]),
]);
agree('3:30 and 3:50 with 40 minutes up in between: one night of 7:20', $d8, '7:20');

echo "re-sync\n";

/* Every night judged once with all of them in place — a regularity bonus can
   still be earned when earlier nights arrive later, which is a new award for
   a new reason, not the same one twice. */
points_process($userId, ['nights' => array_column(db_all('SELECT DISTINCT night_of FROM sleep_sessions WHERE user_id = ?', [$userId]), 'night_of')]);

$before = db_one(
    'SELECT COUNT(*) AS n, SUM(duration_minutes) AS minutes FROM sleep_sessions WHERE user_id = ?', [$userId]
);
$pointsBefore = db_value('SELECT SUM(points) FROM point_events WHERE user_id = ?', [$userId]);
$resync = import([
    hc_sleep('n1', day(9), '23:30', '07:00', [['23:30', '01:00', 4], ['01:00', '02:30', 5], ['02:30', '04:00', 6], ['04:00', '04:15', 1], ['04:15', '07:00', 4]]),
    hc_sleep('n4', day(6), '23:30', '07:00', [['23:30', '03:00', 2], ['03:00', '03:10', 1], ['03:10', '07:00', 2]]),
]);
$awards = points_process($userId, ['nights' => [$d1, $d4]]);
$after = db_one(
    'SELECT COUNT(*) AS n, SUM(duration_minutes) AS minutes FROM sleep_sessions WHERE user_id = ?', [$userId]
);
check('the same records again: no new rows, the same minutes', $before == $after, json_encode([$before, $after]));
check("  and no points twice", array_filter($awards, static fn ($a) => $a["delta"] > 0) === []
    && db_value('SELECT SUM(points) FROM point_events WHERE user_id = ?', [$userId]) === $pointsBefore,
    json_encode(array_map(static fn ($a) => [$a['award_key'] ?? $a['key'] ?? null, $a['delta'], $a['note'] ?? null], array_merge($resync['awards'], $awards))));

echo "a night stored by the old mapping\n";

/* Before this change a stage-2 night was stored as 0 minutes with zero
   stages. The phone's next sync sends it again, and that corrects it. */
db_run(
    'UPDATE sleep_sessions SET duration_minutes = 0, time_in_bed_minutes = 10, efficiency_pct = 0,
            light_minutes = 0, deep_minutes = 0, rem_minutes = 0
      WHERE user_id = ? AND external_id = ?',
    [$userId, 'n4']
);
check('  as it was stored: no night at all', readers($d4)['card'] === null);
import([hc_sleep('n4', day(6), '23:30', '07:00', [['23:30', '03:00', 2], ['03:00', '03:10', 1], ['03:10', '07:00', 2]])]);
agree('  re-synced: 7:20 again, and its stages unknown rather than zero', $d4, '7:20');
check('  deep and REM are null in the row', db_value('SELECT CONCAT_WS(",", deep_minutes, rem_minutes, light_minutes) FROM sleep_sessions WHERE user_id = ? AND external_id = ?', [$userId, 'n4']) === '');

echo str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
