<?php
/**
 * A day's steps, distance and calories, with two apps' records of the same
 * time counted once — tested on the rule itself and on the database.
 *
 *     php tools/metric-totals-test.php
 *
 * The phone and a watch both write their steps to Health Connect. JoLu has to
 * count a moment they both recorded once (health_metric_totals() in
 * includes/health-totals.php; the rule is in config/health-sources.php), and
 * every reader has to say the same number: the Training card, goal progress,
 * the steps points and the trend series. This checks the rule on its own
 * first, then puts real records into a throwaway account — through the same
 * Health Connect import ingest.php uses — and asks each reader what it saw:
 *
 *    1. one app, one interval
 *    2. one app, several intervals that do not overlap
 *    3. the same record sent twice, and the same walk written twice
 *    4. two apps, the same interval: 5.000 + 4.900 steps
 *    5. two apps, partly overlapping
 *    6. two apps, separate intervals
 *    7. a re-sync of everything
 *    8. records crossing midnight, over several days
 *    9. three apps that each covered a different part of the day
 *   10. a reading typed in by hand, next to Health Connect's
 *   and: rows imported before migration 012, the raw rows left as they
 *   arrived, a metric that is not reconciled (water) left a plain sum, and
 *   the kept day totals — used while the readings are unchanged, worked out
 *   again when any of them changes, however it changed.
 *
 * The account is named totalstest_… and deleted at the end (its rows go with
 * it). Needs a database with schema.sql and migrations up to 012 imported.
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
require_once dirname(__DIR__) . '/includes/points.php';
require_once dirname(__DIR__) . '/includes/goals.php';
require_once dirname(__DIR__) . '/includes/goal-progress.php';
require_once dirname(__DIR__) . '/lib/hydrate-health.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "\n          " . $detail) . "\n";
}

/* ======================================================= THE RULE ALONE */

echo "The rule on its own (health_reconcile_days)\n";

/** A stored row, as health_metric_totals() reads it. */
function row(int $id, float $value, ?string $from, string $to, ?string $origin, string $created = '2026-09-26 08:00:00'): array
{
    return ['id' => $id, 'value' => $value, 'started_at' => $from, 'recorded_at' => $to,
            'data_origin' => $origin, 'source_id' => 7, 'created_at' => $created];
}

$D = '2026-09-25';
$N = '2026-09-26';

$same = [row(1, 5000, "$D 10:00:00", "$D 11:00:00", 'phone'), row(2, 4900, "$D 10:00:00", "$D 11:00:00", 'watch')];
check('5.000 (phone) and 4.900 (watch) over the same hour: 5.000, not 9.900',
    health_reconcile_days($same, $D, $D) === [$D => 5000.0], json_encode(health_reconcile_days($same, $D, $D)));
check('  the same in the other row order',
    health_reconcile_days(array_reverse($same), $D, $D) === [$D => 5000.0]);
check('  with the watch listed first under priority: 4.900',
    health_reconcile_days($same, $D, $D, ['watch']) === [$D => 4900.0]);
check('  with the phone listed first: 5.000',
    health_reconcile_days($same, $D, $D, ['phone', 'watch']) === [$D => 5000.0]);

$partial = [row(1, 3600, "$D 10:00:00", "$D 11:00:00", 'a'), row(2, 1800, "$D 10:30:00", "$D 11:30:00", 'b')];
check('partial overlap, 3.600 over 10-11 and 1.800 over 10:30-11:30: 3.600 + the half hour after 11:00 (900) = 4.500',
    health_reconcile_days($partial, $D, $D) === [$D => 4500.0], json_encode(health_reconcile_days($partial, $D, $D)));

$gap = [row(1, 3000, "$D 09:00:00", "$D 10:00:00", 'watch'), row(2, 4000, "$D 09:00:00", "$D 11:00:00", 'phone')];
check('where the first app has nothing, the next fills in: watch 3.000 (9-10) + phone\'s 10-11 share (2.000) = 5.000',
    health_reconcile_days($gap, $D, $D, ['watch']) === [$D => 5000.0], json_encode(health_reconcile_days($gap, $D, $D, ['watch'])));

/* A phone that wrote the whole day as one record, and a watch that wrote six
   separate hours: the phone covers more of the day and wins; filling the
   watch's gaps from the phone's all-day average would invent 5.250 steps. */
$coarse = [row(1, 7000, "$D 00:00:00", "$N 00:00:00", 'phone')];
foreach ([8, 10, 12, 14, 16, 18] as $i => $h) {
    $coarse[] = row(10 + $i, 8000 / 6, sprintf('%s %02d:00:00', $D, $h), sprintf('%s %02d:00:00', $D, $h + 1), 'watch');
}
check('one all-day record (7.000) against six watch hours (8.000): the one covering the day counts, 7.000',
    health_reconcile_days($coarse, $D, $D) === [$D => 7000.0], json_encode(health_reconcile_days($coarse, $D, $D)));
check('  listing the watch first gives what Health Connect would: 8.000 + 18 hours of the phone\'s average = 13.250',
    health_reconcile_days($coarse, $D, $D, ['watch']) === [$D => 13250.0], json_encode(health_reconcile_days($coarse, $D, $D, ['watch'])));

$ownOverlap = [row(1, 3000, "$D 10:00:00", "$D 11:00:00", 'samsung', '2026-09-25 12:00:00'),
               row(2, 1500, "$D 10:30:00", "$D 11:30:00", 'samsung', '2026-09-25 13:00:00')];
check('one app writing two overlapping records (phone and watch under one app): the overlap once, 1.500 + 1.500 = 3.000',
    health_reconcile_days($ownOverlap, $D, $D) === [$D => 3000.0], json_encode(health_reconcile_days($ownOverlap, $D, $D)));

check('a record over midnight is split by time: 1.200 over 23:00-01:00 is 600 + 600',
    health_reconcile_days([row(1, 1200, "$D 23:00:00", "$N 01:00:00", 'a')], $D, $N) === [$D => 600.0, $N => 600.0]);
check('  and only the days asked for come back',
    health_reconcile_days([row(1, 1200, "$D 23:00:00", "$N 01:00:00", 'a')], $N, $N) === [$N => 600.0]);

check('a reading without a span counts whole on its date, next to reconciled spans: 2.000 + 5.000',
    health_reconcile_days([row(1, 2000, null, "$D 20:00:00", null), ...$same], $D, $D) === [$D => 7000.0]);
check('a span of no length counts whole, as a reading',
    health_reconcile_days([row(1, 250, "$D 12:00:00", "$D 12:00:00", 'a')], $D, $D) === [$D => 250.0]);
check('a span longer than a day counts whole on the date it ended',
    health_reconcile_days([row(1, 30000, "$D 06:00:00", "2026-09-27 06:00:00", 'a')], $D, '2026-09-27') === ['2026-09-27' => 30000.0]);
check('a whole-day record on the night the clocks go back stays on its own day',
    health_reconcile_days([row(1, 10000, '2026-10-25 00:00:00', '2026-10-26 00:00:00', 'a')], '2026-10-25', '2026-10-26') === ['2026-10-25' => 10000.0]);

$many = [...$partial, ...$same, ...$gap, row(40, 700, "$D 21:00:00", "$D 21:30:00", 'b'), row(41, 900, null, "$D 22:00:00", null)];
$first = health_reconcile_days($many, $D, $D);
$stable = true;
for ($i = 0; $i < 25; $i++) {
    shuffle($many);
    $stable = $stable && health_reconcile_days($many, $D, $D) === $first;
}
check('the answer does not depend on the order the rows come back in (25 shuffles)', $stable, json_encode($first));

/* ================================================== ON THE DATABASE */

if (!db_available()) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

if (!health_metric_intervals_available()) {
    fwrite(STDERR, "Import database/migrations/012-metric-intervals.sql first.\n");
    exit(1);
}

db_run('INSERT INTO users (username) VALUES (?)', ['totalstest_' . bin2hex(random_bytes(4))]);
$userId = (int) db_insert_id();

/* Points only pay for what happened after the account existed. */
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

/** "Y-m-d H:i" in Dutch local time, as the phone sends it: "2026-09-20T10:00:00+02:00". */
function iso(string $local): string
{
    return (new DateTimeImmutable($local))->format('Y-m-d\TH:i:sP');
}

const PHONE = 'com.google.android.apps.fitness';
const WATCH = 'com.fitbit.FitbitMobile';
const SAMSUNG = 'com.sec.android.app.shealth';

/** A Health Connect record as the Android app sends it; $from and $to "Y-m-d H:i". */
function hc(string $type, string $id, string $origin, string $from, string $to, float $value): array
{
    return [
        'recordType' => $type,
        'metadata'   => ['id' => $id, 'dataOrigin' => $origin],
        'startTime'  => iso($from),
        'endTime'    => iso($to),
    ] + match ($type) {
        'Steps'                => ['count' => $value],
        'Distance'             => ['distance' => ['meters' => $value]],
        'ActiveCaloriesBurned' => ['energy' => ['kilocalories' => $value]],
        'Hydration'            => ['volume' => ['liters' => $value]],
    };
}

$sent = [];     // every record sent, for the re-sync and the raw-row checks

function import(array $records): array
{
    global $userId, $sent;

    foreach ($records as $record) {
        $sent[$record['metadata']['id']] = $record;
    }

    $mapped = health_connect_map($records);

    return health_import_records($userId, 'google_health_connect', $mapped['records']);
}

/* The goals that read steps: Optellen adds every day up, and a Streak with a
   daily target of 4.500 marks each day met or missed. */
$goalFrom = day(20);
$sumGoal = goal_create($userId, [
    'name' => 'Stappen optellen', 'category' => 'activity', 'kind' => 'accumulate',
    'source_kind' => 'metric', 'source_key' => 'steps', 'target_value' => 1000000,
    'start_date' => $goalFrom,
]);
$dayGoal = goal_create($userId, [
    'name' => 'Elke dag 4.500 stappen', 'category' => 'activity', 'kind' => 'streak',
    'source_kind' => 'metric', 'source_key' => 'steps', 'daily_target' => 4500, 'target_value' => 30,
    'start_date' => $goalFrom,
]);
check('two step goals made (Optellen, and a Streak of 4.500 a day)', $sumGoal !== null && $dayGoal !== null);

/** What each reader says about $date's steps. */
function readers(string $date): array
{
    global $userId, $dayGoal;

    $card   = hydrate_health_values($userId, $date);
    $goal   = goal_source_by_day($userId, 'metric', 'steps', $date, $date)[$date] ?? null;
    $series = health_metric_series($userId, 'steps', $date, $date)[$date] ?? null;
    $note   = db_value('SELECT note FROM point_events WHERE user_id = ? AND award_key = ?', [$userId, 'steps:' . $date]);
    $state  = null;

    foreach (goal_progress_compute($userId, goal_get($userId, (int) $dayGoal))['days'] as $d) {
        if ($d['date'] === $date) {
            $state = $d['state'];
        }
    }

    return [
        'card'   => $card['steps'] ?? null,
        'goal'   => $goal === null ? null : (int) round($goal),
        'series' => $series === null ? null : (int) round($series),
        'points' => $note === null ? null : (int) str_replace('.', '', explode(' ', (string) $note)[0]),
        'state'  => $state,
        'values' => $card,
    ];
}

/**
 * Every reader says $expected steps: the card, the goal and the series that
 * number, the points ledger an award for it — or, under the lowest tier
 * (5.000), no award at all. The 4.500 goal day is met or missed by it.
 */
function agree(string $label, string $date, int $expected): array
{
    $r = readers($date);
    $seen = ['card' => $r['card'], 'goal' => $r['goal'], 'series' => $r['series']];
    $paid = points_steps_value($expected) > 0 ? $expected : null;

    check("$label: card, goal and series say " . number_format($expected, 0, ',', '.')
        . ($paid === null ? ', and no points (under 5.000)' : ', and the points are for it'),
        array_unique(array_values($seen)) === [$expected] && $r['points'] === $paid,
        json_encode($seen + ['points' => $r['points']]));
    check('  the 4.500-a-day goal calls the day ' . ($expected >= 4500 ? 'met' : 'missed'),
        $r['state'] === ($expected >= 4500 ? 'met' : 'missed'), (string) $r['state']);

    return $r;
}

/** The plain sum per day: what every reader showed before this rule. */
function plain_sum(string $code, string $date): float
{
    global $userId;

    return (float) db_value(
        'SELECT COALESCE(SUM(m.value), 0) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id
          WHERE m.user_id = ? AND t.code = ? AND m.recorded_on = ?',
        [$userId, $code, $date]
    );
}

function points_for(string $date): ?int
{
    global $userId;

    $p = db_value('SELECT points FROM point_events WHERE user_id = ? AND award_key = ?', [$userId, 'steps:' . $date]);

    return $p === null ? null : (int) $p;
}

/* ================================================================ 1 */
echo "1. one app, one interval\n";

$d1 = day(12);
import([hc('Steps', 's1', PHONE, "$d1 10:00", "$d1 11:00", 5000)]);
agree('5.000 steps from 10:00 to 11:00', $d1, 5000);
check('  stored with its start and the app that wrote it',
    db_one('SELECT started_at, data_origin FROM health_metrics WHERE user_id = ? AND external_id = ?', [$userId, 's1'])
        === ['started_at' => "$d1 10:00:00", 'data_origin' => PHONE]);

/* ================================================================ 2 */
echo "2. one app, several intervals\n";

$d2 = day(11);
import([
    hc('Steps', 's2a', PHONE, "$d2 08:00", "$d2 09:00", 1200),
    hc('Steps', 's2b', PHONE, "$d2 12:00", "$d2 13:00", 2300),
    hc('Steps', 's2c', PHONE, "$d2 18:00", "$d2 19:00", 3100),
]);
agree('1.200 + 2.300 + 3.100, none overlapping: all of it', $d2, 6600);

/* ================================================================ 3 */
echo "3. the same record twice, the same walk twice\n";

$d3 = day(10);
$walk = hc('Steps', 's3', WATCH, "$d3 17:00", "$d3 17:45", 4000);
import([$walk, $walk]);
import([$walk]);
agree('one record sent three times, in two syncs', $d3, 4000);
check('  stored once', (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE user_id = ? AND external_id = ?', [$userId, 's3']) === 1);

import([hc('Steps', 's3-copy', WATCH, "$d3 17:00", "$d3 17:45", 4000)]);
agree('the same walk written again by the same app under a new id: counted once', $d3, 4000);
check('  both copies kept as they arrived',
    (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE user_id = ? AND external_id IN (?, ?)', [$userId, 's3', 's3-copy']) === 2);

/* ================================================================ 4 */
echo "4. two apps, the same interval\n";

$d4 = day(9);
import([
    hc('Steps', 's4p', PHONE, "$d4 10:00", "$d4 11:00", 5000),
    hc('Steps', 's4w', WATCH, "$d4 10:00", "$d4 11:00", 4900),
    hc('Distance', 'd4p', PHONE, "$d4 10:00", "$d4 11:00", 3800),
    hc('Distance', 'd4w', WATCH, "$d4 10:00", "$d4 11:00", 3700),
    hc('ActiveCaloriesBurned', 'c4p', PHONE, "$d4 10:00", "$d4 11:00", 250),
    hc('ActiveCaloriesBurned', 'c4w', WATCH, "$d4 10:00", "$d4 11:00", 240),
]);
$r = agree('phone 5.000 and watch 4.900 over the same hour', $d4, 5000);
check('  the plain sum was 9.900 — what JoLu showed before', plain_sum('steps', $d4) === 9900.0);
check('  10 points (the 5.000 tier), not the 20 that 9.900 would have paid', points_for($d4) === 10, (string) points_for($d4));
check('  distance on the card: 3,8 km, not 7,5', ($r['values']['distance'] ?? null) === 3.8, json_encode($r['values']['distance'] ?? null));
check('  active calories on the card: 250, not 490', ($r['values']['active_energy'] ?? null) === 250, json_encode($r['values']['active_energy'] ?? null));

/* ================================================================ 5 */
echo "5. two apps, partly overlapping\n";

$d5 = day(8);
import([
    hc('Steps', 's5a', PHONE, "$d5 10:00", "$d5 11:00", 3600),
    hc('Steps', 's5b', WATCH, "$d5 10:30", "$d5 11:30", 1800),
]);
agree('3.600 over 10-11 and 1.800 over 10:30-11:30: the overlap once', $d5, 4500);
check('  the plain sum was 5.400, which would have paid 10 points', plain_sum('steps', $d5) === 5400.0 && points_for($d5) === null);

/* ================================================================ 6 */
echo "6. two apps, separate intervals\n";

$d6 = day(7);
import([
    hc('Steps', 's6a', PHONE, "$d6 08:00", "$d6 09:00", 2000),
    hc('Steps', 's6b', WATCH, "$d6 14:00", "$d6 15:00", 3000),
]);
agree('2.000 in the morning from one app, 3.000 in the afternoon from another: both', $d6, 5000);

/* ================================================================ 8 */
echo "8. over midnight, over several days\n";

$d8a = day(6);
$d8b = day(5);
$d8c = day(4);
import([
    hc('Steps', 's8a', PHONE, "$d8a 12:00", "$d8a 13:00", 4000),
    hc('Steps', 's8x', PHONE, "$d8a 23:00", "$d8b 01:00", 1200),     // 600 + 600
    hc('Steps', 's8b', PHONE, "$d8b 07:00", "$d8b 08:00", 4000),
    hc('Steps', 's8y', WATCH, "$d8b 23:30", "$d8c 00:30", 1000),     // 500 + 500
    hc('Steps', 's8z', PHONE, "$d8b 23:30", "$d8c 00:30", 800),      // the same hour, from the phone: 400 + 400
    hc('Steps', 's8c', WATCH, "$d8c 09:00", "$d8c 10:00", 4200),
]);
/* Each day ranks its own apps. On the second the phone covered most (two
   and a half hours to the watch's half), so the phone's 400 counts for the
   last half hour; on the third the watch did, so its 500 counts for the
   first. The shared hour counts once either way, never 900 + 900. */
agree('the first day: 4.000 + the 600 before midnight', $d8a, 4600);
agree('the second day: 600 after midnight + 4.000 + the phone\'s 400 before midnight', $d8b, 5000);
agree('the third day: the watch\'s 500 after midnight + 4.200', $d8c, 4700);

/* ================================================================ 9 */
echo "9. three apps, each covering a different part of the day\n";

$d9 = day(3);
import([
    hc('Steps', 's9w', WATCH, "$d9 09:00", "$d9 12:00", 6000),      // 3 hours
    hc('Steps', 's9p', PHONE, "$d9 11:00", "$d9 13:00", 2000),      // 2 hours
    hc('Steps', 's9s', SAMSUNG, "$d9 12:30", "$d9 13:30", 1000),    // 1 hour
]);
/* The watch covers most and counts from 9 to 12 (6.000); the phone fills
   12-13 (1.000); Samsung 13-13:30 (500). */
agree('watch, phone and Samsung Health: every moment once', $d9, 7500);
check('  the plain sum was 9.000', plain_sum('steps', $d9) === 9000.0);

/* =============================================================== 10 */
echo "10. a reading typed in by hand, next to Health Connect's\n";

$d10 = day(2);
import([
    hc('Steps', 's10p', PHONE, "$d10 10:00", "$d10 11:00", 5000),
    hc('Steps', 's10w', WATCH, "$d10 10:00", "$d10 11:00", 4900),
]);
health_record_metric($userId, 'steps', 1500, "$d10 20:00:00", 'manual');
points_process($userId, ['step_days' => [$d10]]);     // the manual path has no sync to trigger it
agree('1.500 by hand (no time span: counts as it is) + the Health Connect hour once', $d10, 6500);
check('  the hand-typed row has no start and no app',
    db_one('SELECT started_at, data_origin FROM health_metrics m JOIN data_sources s ON s.id = m.source_id
             WHERE m.user_id = ? AND s.code = ?', [$userId, 'manual']) === ['started_at' => null, 'data_origin' => null]);

/* ================================================================ 7 */
echo "7. a re-sync of everything\n";

$dates  = [$d1, $d2, $d3, $d4, $d5, $d6, $d8a, $d8b, $d8c, $d9, $d10];
$before = [];
foreach ($dates as $date) {
    $before[$date] = readers($date);
}
$rowsBefore   = (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE user_id = ?', [$userId]);
$ledgerBefore = db_all('SELECT award_key, points, note FROM point_events WHERE user_id = ? ORDER BY award_key', [$userId]);

$result = import(array_values($sent));
check('the phone sends all ' . count($sent) . ' records again: all written, nothing refused',
    $result['written'] === count($sent) && $result['skipped'] === 0, json_encode(['written' => $result['written'], 'skipped' => $result['skipped']]));
check('  no new rows', (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE user_id = ?', [$userId]) === $rowsBefore);
check('  no new or changed awards',
    db_all('SELECT award_key, points, note FROM point_events WHERE user_id = ? ORDER BY award_key', [$userId]) === $ledgerBefore
        && $result['awards'] === [], json_encode($result['awards']));

$same = true;
foreach ($dates as $date) {
    $now = readers($date);
    unset($now['values'], $before[$date]['values']);
    $same = $same && $now === $before[$date];
}
check('  every day reads exactly as before, on every reader', $same);

/* ============================================== the goals, over it all */
echo "The goals, over every day\n";

$expected = [$d1 => 5000, $d2 => 6600, $d3 => 4000, $d4 => 5000, $d5 => 4500, $d6 => 5000,
             $d8a => 4600, $d8b => 5000, $d8c => 4700, $d9 => 7500, $d10 => 6500];
$sumProgress = goal_progress_compute($userId, goal_get($userId, (int) $sumGoal));
check('Optellen adds up the day totals: ' . number_format(array_sum($expected), 0, ',', '.'),
    (int) round((float) $sumProgress['total']) === array_sum($expected), json_encode($sumProgress['total']));
check('  not the ' . number_format((int) db_value('SELECT SUM(value) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id
                                   WHERE m.user_id = ? AND t.code = ?', [$userId, 'steps']), 0, ',', '.') . ' the rows add up to',
    (int) round((float) $sumProgress['total']) < (int) db_value('SELECT SUM(value) FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id
                                   WHERE m.user_id = ? AND t.code = ?', [$userId, 'steps']));
check('  the stored progress matches (goal_refresh_all)',
    (int) round((float) db_value('SELECT total_value FROM goals WHERE id = ?', [$sumGoal])) === array_sum($expected),
    (string) db_value('SELECT total_value FROM goals WHERE id = ?', [$sumGoal]));

/* ===================================== rows from before migration 012 */
echo "Rows imported before migration 012\n";

/* What the old importer wrote for the phone and the watch: an end time,
   no start, no app. */
$d11 = day(1);
$hcSource = health_source_id('google_health_connect');
$steps = health_metric_type_id('steps');
foreach ([['old-p', 5000], ['old-w', 4900]] as [$id, $value]) {
    db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, external_id, value, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?)', [$userId, $steps, $hcSource, $id, $value, "$d11 11:00:00"]);
}
check('without a span they cannot be compared, so they count as they always did: 9.900',
    (health_metric_totals($userId, 'steps', $d11, $d11)[$d11] ?? null) === 9900.0);

import([hc('Steps', 'old-p', PHONE, "$d11 10:00", "$d11 11:00", 5000), hc('Steps', 'old-w', WATCH, "$d11 10:00", "$d11 11:00", 4900)]);
agree('the next sync fills in what they lacked, and the hour counts once', $d11, 5000);
check('  the same two rows, updated in place',
    (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE user_id = ? AND external_id IN (?, ?) AND started_at IS NOT NULL',
        [$userId, 'old-p', 'old-w']) === 2);

/* ======================================================= the raw rows */
echo "The raw rows\n";

$stored = [];
foreach (db_all('SELECT external_id, value FROM health_metrics WHERE user_id = ? AND external_id IS NOT NULL', [$userId]) as $row) {
    $stored[$row['external_id']] = (float) $row['value'];
}
$intact = true;
foreach ($sent as $id => $record) {
    $value = $record['count'] ?? (isset($record['distance']) ? $record['distance']['meters'] / 1000 : ($record['energy']['kilocalories'] ?? null));
    $intact = $intact && isset($stored[$id]) && abs($stored[$id] - (float) $value) < 0.0001;
}
check('every record sent is stored, with the value it arrived with — the ones that did not count included', $intact);
check('  the watch\'s 4.900 of scenario 4 among them', ($stored['s4w'] ?? null) === 4900.0);

/* ============================================ a metric not reconciled */
echo "A metric that is not in the list\n";

import([
    hc('Hydration', 'w1', PHONE, "$d11 08:00", "$d11 08:05", 0.5),
    hc('Hydration', 'w2', WATCH, "$d11 08:00", "$d11 08:05", 0.25),
]);
check('two glasses of water logged at the same moment in two apps are two glasses: 0,75 l',
    health_daily_metric($userId, 'water', $d11) === 0.75, json_encode(health_daily_metric($userId, 'water', $d11)));

/* ======================================================= the kept totals */
echo "The kept day totals (health_metric_day_totals)\n";

$stepsType = health_metric_type_id('steps');
$kept = static fn (string $date): ?array => db_one(
    'SELECT total, readings_hash FROM health_metric_day_totals WHERE user_id = ? AND metric_type_id = ? AND day = ?',
    [$userId, $stepsType, $date]
);
$total = static fn (string $date): ?float => health_metric_totals($userId, 'steps', $date, $date)[$date] ?? null;

check('every day that was read is kept, with its total and a fingerprint',
    ($kept($d4)['total'] ?? null) === '5000.0000' && strlen((string) ($kept($d4)['readings_hash'] ?? '')) === 32,
    json_encode($kept($d4)));

db_run('UPDATE health_metric_day_totals SET total = 1 WHERE user_id = ? AND metric_type_id = ? AND day = ?', [$userId, $stepsType, $d4]);
check('while the readings are unchanged the kept total is what is read — nothing is worked out again', $total($d4) === 1.0);
db_run('DELETE FROM health_metric_day_totals WHERE user_id = ?', [$userId]);
check('  and emptying the table loses nothing: the day is worked out again, the same', $total($d4) === 5000.0 && $kept($d4) !== null);

db_run('UPDATE health_metrics SET value = 5200 WHERE user_id = ? AND external_id = ?', [$userId, 's1']);
check('a reading changed behind the app\'s back (no importer, no hook) shows on the next read: 5.200',
    $total($d1) === 5200.0 && (hydrate_health_values($userId, $d1)['steps'] ?? null) === 5200);
db_run('UPDATE health_metrics SET value = 5000 WHERE user_id = ? AND external_id = ?', [$userId, 's1']);
check('  and changed back: 5.000', $total($d1) === 5000.0);

db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, data_origin, external_id, value, started_at, recorded_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$userId, $stepsType, $hcSource, PHONE, 'direct-1', 1000, "$d2 20:00:00", "$d2 21:00:00"]);
check('a reading added straight into the table shows: 6.600 + 1.000', $total($d2) === 7600.0);
db_run('DELETE FROM health_metrics WHERE user_id = ? AND external_id = ?', [$userId, 'direct-1']);
check('  and deleted again: 6.600', $total($d2) === 6600.0);

/* A walk from 22:00 to 00:30 is stored under the day it ended, but a third
   of it is on the day before — whose kept total has to notice. Samsung
   covers 22:00-24:00 there, as long as the phone (12-13, 23-24), with less
   of its own, so the phone keeps 23-24 and Samsung adds 22-23: 200. */
import([hc('Steps', 's8late', SAMSUNG, "$d8a 22:00", "$d8b 00:30", 500)]);
check('a record that ends the next day changes the kept total of the day it started on: 4.600 + 200',
    $total($d8a) === 4800.0 && (hydrate_health_values($userId, $d8a)['steps'] ?? null) === 4800, json_encode($total($d8a)));
check('  and the day it ended on, where the phone covered more, keeps 5.000', $total($d8b) === 5000.0, json_encode($total($d8b)));

echo "\n" . $pass . ' passed, ' . $fail . " failed\n";

exit($fail === 0 ? 0 : 1);
