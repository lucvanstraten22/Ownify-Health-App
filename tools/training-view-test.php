<?php
/**
 * Training, drawn (lib/hydrate-training.php, docs/TRAINING.md) — tested on
 * the database.
 *
 *     php tools/training-view-test.php
 *
 * Real Health Connect records go into a throwaway account through the import
 * the phone uses, and this asks what the Training page is given:
 *
 *   1. heart rate kept minute by minute (heart_rate_minutes, migration 019):
 *      each minute's mean, the night's average for the sleep card as
 *      before, the same again on a re-sync, two apps' minute weighed by
 *      their samples, an app from before 13.0 (no allSamples)
 *   2. a day's average heart rate: the mean of its five-minute means, so a
 *      workout measured every second does not outweigh a day measured every
 *      few minutes
 *   3. the sessions: Ownify's counted workouts only — a six-minute walk is
 *      none, two apps' recordings of one run are one — newest first, and
 *      the sessions per day: a 0 on a day without one once there is a
 *      history, from its first day at the left
 *   4. a day of heart rate, 00:00 to 24:00: five-minute points at their
 *      places, a line broken where twenty minutes have none, a dot where a
 *      point stands alone, the hours under it, its name; a day without any
 *      is empty
 *   5. the zones: from the heart-rate reserve with a resting heart rate,
 *      from the maximum without one, none without a maximum; each reading's
 *      zone
 *   6. the periods: 7 dagen to 1 jaar of daily averages, a point per day
 *      over 90 dagen with its dates weekly, per month over a year
 *   7. a session's own page: what was recorded during it, each moment once
 *      over two apps — a day-long reading left out — its heart rate minute
 *      by minute, no pace without its own distance
 *   8. the charts: each day's value as every page reads it; Hartbelasting
 *      and Actieve minuten, which no source sends, empty — never a 0
 *
 * The accounts are named trainingview_… and deleted at the end (their rows
 * go with them). Needs schema.sql and migrations up to 019. Exit code 0 when
 * every check passes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Amsterdam');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/health-import.php';
require_once dirname(__DIR__) . '/includes/health-connect-map.php';
require_once dirname(__DIR__) . '/lib/hydrate-training.php';

if (!db_available()) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

if (!health_heart_minutes_stored()) {
    fwrite(STDERR, "Import database/migrations/019-heart-rate-minutes.sql first.\n");
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

function section(string $title): void
{
    echo "\n== {$title} ==\n";
}

$today = date('Y-m-d');
$day   = static fn (int $ago): string => (new DateTimeImmutable($today))->modify("-{$ago} day")->format('Y-m-d');
$at    = static fn (string $date, string $time): string => (new DateTimeImmutable($date . ' ' . $time))->format(DATE_ATOM);

/** A throwaway account, born $years ago (null: no birth date). */
$users   = [];
$account = static function (?int $years) use (&$users, $today): int {
    db_run('INSERT INTO users (username) VALUES (?)', ['trainingview_' . bin2hex(random_bytes(4))]);
    $id = (int) db_insert_id();
    db_run('UPDATE users SET created_at = NOW() - INTERVAL 60 DAY WHERE id = ?', [$id]);
    if ($years !== null) {
        $born = (new DateTimeImmutable($today))->modify("-{$years} year")->modify('-10 day')->format('Y-m-d');
        db_run('INSERT INTO user_profiles (user_id, date_of_birth) VALUES (?, ?) ON DUPLICATE KEY UPDATE date_of_birth = VALUES(date_of_birth)', [$id, $born]);
    }
    $users[] = $id;

    return $id;
};

register_shutdown_function(static function () use (&$users): void {
    foreach ($users as $id) {
        db_run('DELETE FROM point_events WHERE user_id = ?', [$id]);
        db_run('DELETE FROM users WHERE id = ?', [$id]);
    }
});

$userId = $account(40);                     // Tanaka: 208 − 0,7 × 40 = 180

$import = static function (array $records, ?int $user = null) use (&$userId): array {
    return health_import_records($user ?? $userId, 'google_health_connect', health_connect_map($records)['records']);
};

$hr = static function (string $id, array $samples, ?array $all = null, string $origin = 'com.samsung.android.wear'): array {
    $every  = $all ?? $samples;
    $record = [
        'recordType' => 'HeartRate',
        'metadata'   => ['id' => $id, 'dataOrigin' => $origin],
        'startTime'  => $every[0]['time'] ?? null,
        'endTime'    => $every === [] ? null : $every[count($every) - 1]['time'],
        'samples'    => $samples,
    ];
    if ($all !== null) {
        $record['allSamples'] = $all;
    }

    return $record;
};
$s = static fn (string $date, string $time, int $bpm): array => ['time' => (new DateTimeImmutable($date . ' ' . $time))->format(DATE_ATOM), 'beatsPerMinute' => $bpm];

$minute = static fn (string $date, string $time, ?int $user = null): ?array => db_one(
    'SELECT bpm, samples FROM heart_rate_minutes WHERE user_id = ? AND minute_at = ? ORDER BY data_origin LIMIT 1',
    [$user ?? $userId, $date . ' ' . $time . ':00']
);

$config  = require dirname(__DIR__) . '/config/health.php';
$area    = $config['areas']['training'];
$periods = (require dirname(__DIR__) . '/config/compass.php')['history']['periods'];

/* =================================================================== */

section('1. Heart rate, minute by minute');

$night  = [$s($day(1), '01:00:00', 52), $s($day(1), '01:00:30', 54)];
$daytime = [$s($day(1), '10:00:00', 70), $s($day(1), '10:00:20', 74), $s($day(1), '10:05:00', 80), $s($day(1), '10:10:00', 82), $s($day(1), '10:40:00', 90)];
$import([$hr('h1', $night, array_merge($night, $daytime))]);

check('each minute the mean of its samples: 01:00 53 (2), 10:00 72 (2), 10:05 80',
    (float) $minute($day(1), '01:00')['bpm'] === 53.0 && (int) $minute($day(1), '01:00')['samples'] === 2
    && (float) $minute($day(1), '10:00')['bpm'] === 72.0 && (float) $minute($day(1), '10:05')['bpm'] === 80.0,
    json_encode([$minute($day(1), '01:00'), $minute($day(1), '10:00')]));
check('the sleep card\'s heart rate from the night\'s samples alone, as before: 53',
    (float) db_value("SELECT m.value FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id WHERE m.user_id = ? AND t.code = 'sleeping_hr'", [$userId]) === 53.0);

$rows = (int) db_value('SELECT COUNT(*) FROM heart_rate_minutes WHERE user_id = ?', [$userId]);
$import([$hr('h1', $night, array_merge($night, $daytime))]);
check('the same record again: the same minutes, never twice', (int) db_value('SELECT COUNT(*) FROM heart_rate_minutes WHERE user_id = ?', [$userId]) === $rows && $rows === 5);

$import([$hr('h2', [$s($day(1), '02:00:00', 50)])]);
check('an app from before 13.0 (no allSamples): the night\'s minutes', (float) ($minute($day(1), '02:00')['bpm'] ?? 0) === 50.0);

$import([
    $hr('h3', [], [$s($day(6), '09:00:00', 60)], 'app.a'),
    $hr('h4', [], [$s($day(6), '09:00:05', 70), $s($day(6), '09:00:25', 70), $s($day(6), '09:00:45', 70)], 'app.b'),
]);
$both = health_heart_minutes($userId, $day(6) . ' 09:00:00', $day(6) . ' 09:01:00');
check('two apps in one minute: weighed by their samples, (60 + 3 × 70) / 4 = 67,5', round($both[$day(6) . ' 09:00:00'] ?? 0, 1) === 67.5, json_encode($both));
check('a record without samples: nothing kept', health_connect_map([$hr('h5', [])])['records'] === []);

/* =================================================================== */

section('2. A day\'s average heart rate');

$dense = [];
for ($m = 0; $m < 5; $m++) {
    $dense[] = $s($day(3), sprintf('12:%02d:00', $m), 150);       // a run, every minute
}
$sparse = [$s($day(3), '00:00:00', 50), $s($day(3), '01:00:00', 50), $s($day(3), '02:00:00', 50), $s($day(3), '03:00:00', 50)];
$import([$hr('h6', [], array_merge($sparse, $dense))]);
$avg = health_heart_days($userId, $day(3), $day(3))[$day(3)] ?? null;
check('the mean of its five-minute means, (150 + 4 × 50) / 5 = 70 — not of its minutes (105,6)', $avg !== null && round($avg, 1) === 70.0, (string) $avg);

/* =================================================================== */

section('3. The sessions');

$ex = static fn (string $id, string $date, string $from, string $to, string $type, string $origin = 'com.samsung.android.wear'): array => [
    'recordType' => 'ExerciseSession', 'metadata' => ['id' => $id, 'dataOrigin' => $origin],
    'startTime' => $at($date, $from), 'endTime' => $at($date, $to), 'exerciseTypeName' => $type,
];
$import([
    $ex('w1', $day(1), '18:00', '18:40', 'running'),
    $ex('w1b', $day(1), '18:01', '18:39', 'running', 'com.google.android.apps.fitness'),   // the same run, a second app
    $ex('w2', $day(2), '12:00', '12:06', 'walking'),                                        // six minutes: no session
    $ex('w3', $day(3), '08:00', '08:30', 'biking'),
    $ex('w4', $day(5), '19:00', '19:50', 'strength_training'),
    $ex('w5', $day(8), '07:00', '07:30', 'running'),
]);

$view = hydrate_training($area, $periods, $userId, $today);
$items = $view['sessions']['items'];
check('four sessions, newest first: the run, the ride, the strength training, the older run',
    array_column($items, 'label') === ['Hardlopen', 'Fietsen', 'Krachttraining', 'Hardlopen'], json_encode(array_column($items, 'label')));
check('  the run once, though two apps recorded it; the six-minute walk not at all',
    count(array_filter($items, static fn ($i) => $i['label'] === 'Wandelen')) === 0 && count($items) === 4);
check('  each its day and time, and how long',
    $items[0]['date'] === 'Gisteren' && $items[0]['time'] === '18:00' && $items[0]['duration'] === '40 min'
    && $items[1]['date'] === score_compass_date_in($day(3), substr($today, 0, 4), true) && $items[0]['open'] === 'Open Hardlopen van gisteren',
    json_encode($items[0]));

$charts = array_column($view['charts'], null, 'id');
$week   = $charts['sessions']['periods'][0];
$month  = $charts['sessions']['periods'][1];
$byDate = [];
foreach ($month['points'] as $p) {
    $byDate[$p[0]] = $p[3];
}
$name = static fn (string $d): string => score_compass_date_in($d, substr($today, 0, 4));
check('sessions per day: 1 the day of the run (not 2), 0 the day of the walk',
    ($byDate[$name($day(1))] ?? null) === '1 training' && ($byDate[$name($day(2))] ?? null) === '0 trainingen', json_encode($byDate));
check('  over 30 dagen from its first day at the left, the rest of the month ahead',
    $month['points'][0][0] === $name($day(8)) && count($month['points']) === 9 && $month['x'][0] === $month['axis'][0]['x'],
    json_encode([$month['points'][0][0], count($month['points'])]));
check('  7 dagen rolls: today at the right', end($week['points'])[0] === $name($today) && count($week['points']) === 7);

/* =================================================================== */

section('4. A day of heart rate');

$days = $view['heart']['days'];
$d1   = $days[1];
check('today and the six days before it, named: Vandaag, Gisteren, Eergisteren, then the date',
    array_column($days, 'title') === ['Vandaag', 'Gisteren', 'Eergisteren', score_compass_date($day(3)), score_compass_date($day(4)), score_compass_date($day(5)), score_compass_date($day(6))],
    json_encode(array_column($days, 'title')));
check('00:00 to 24:00, the hours every three, never "Vandaag" under it',
    array_column($d1['axis'], 'label') === ['00:00', '03:00', '06:00', '09:00', '12:00', '15:00', '18:00', '21:00']
    && $d1['axis'][0]['x'] == 0 && $d1['axis'][4]['x'] == 50, json_encode($d1['axis']));
check('each five minutes with a heart rate a point in the middle of them: 01:00 at 4,34%, six in all',
    $d1['x'][0] == round(62.5 / 1440 * 100, 3) && count($d1['x']) === 6, json_encode($d1['x']));
check('  the reading: its time, its zone and its bpm', $d1['points'][2] === ['10:00', 'Zone 1', null, '72 bpm'], json_encode($d1['points'][2]));
check('a line through the points no more than twenty minutes apart: 10:00, 10:05, 10:10 — one curve',
    count($d1['lines'][0]['line']) === 1, json_encode($d1['lines'][0]['line']));
check('  a point standing alone a dot: 01:00, 02:00 and 10:40', $d1['lone'] === [0, 1, 5], json_encode($d1['lone']));
check('a day without heart rate: empty, said so', !$days[4]['has_data'] && $days[4]['x'] === [] && $days[4]['empty'] === $area['heart']['empty_day']);
check('one height for the whole week, from no lower than 0 and in whole steps', $d1['grid'] === $days[3]['grid'] && $d1['grid'] !== []);

/* =================================================================== */

section('5. The zones');

$import([
    ['recordType' => 'RestingHeartRate', 'metadata' => ['id' => 'r1'], 'time' => $at($day(1), '06:00'), 'beatsPerMinute' => 50],
    ['recordType' => 'RestingHeartRate', 'metadata' => ['id' => 'r2'], 'time' => $at($day(2), '06:00'), 'beatsPerMinute' => 52],
    ['recordType' => 'RestingHeartRate', 'metadata' => ['id' => 'r3'], 'time' => $at($day(3), '06:00'), 'beatsPerMinute' => 54],
]);
$z = hydrate_training_zones($area['heart']['zones'], $userId, health_workout_records($userId), $today);
check('with a resting heart rate: from the reserve, 52 + 30/40/60% of (180 − 52) = 90, 103, 129',
    $z['basis'] === 'reserve' && $z['thresholds'] === [90, 103, 129], json_encode($z));
check('  named with their ranges, and what they are based on',
    array_column($z['bands'], 'range') === ['< 90', '90–102', '103–128', '≥ 129']
    && $z['note'] === 'Op basis van je rusthartslag (52 bpm) en maximale hartslag (180 bpm).', json_encode($z['bands']));
check('  each heart rate\'s zone by its whole number: 72 → 1, 95 → 2, 110 → 3, 150 → 4, 89,6 → 2',
    [hydrate_training_zone($z, 72), hydrate_training_zone($z, 95), hydrate_training_zone($z, 110), hydrate_training_zone($z, 150), hydrate_training_zone($z, 89.6)] === [1, 2, 3, 4, 2]);

$maxOnly = $account(40);
$z2 = hydrate_training_zones($area['heart']['zones'], $maxOnly, [], $today);
check('without one: from the maximum, 57/64/77% of 180 = 103, 115, 139', $z2['basis'] === 'max' && $z2['thresholds'] === [103, 115, 139], json_encode($z2));

$none = $account(null);
$z3 = hydrate_training_zones($area['heart']['zones'], $none, [], $today);
check('without a maximum (no birth date, no training with heart rate): no zones, and why',
    $z3['thresholds'] === null && $z3['bands'] === [] && $z3['note'] === $area['heart']['zones']['none']);
check('  and the values are the same with or without them', hydrate_training_heart($area['heart'], $area['chart_copy'], $periods, $z3, $userId, $today)['days'][1]['points'][2][3] === '72 bpm');

/* =================================================================== */

section('6. The periods');

$heart = array_column($view['heart']['periods'], null, 'key');
check('7 dagen, 30 dagen, 90 dagen, 1 jaar — and Vandaag first in the switch',
    array_column($view['heart']['periods'], 'key') === ['7', '30', '90', '365'] && array_column($view['heart']['options'], 'label')[0] === 'Vandaag');
$p7 = [];
foreach ($heart['7']['points'] as $p) {
    $p7[$p[0]] = $p[3];
}
check('each day its average: ' . $name($day(3)) . ' 70 bpm', ($p7[$name($day(3))] ?? null) === '70 bpm', json_encode($p7));
check('90 dagen: a point per day, the dates every seventh day',
    $heart['90']['group'] === 'day' && count($heart['90']['points']) === count($heart['30']['points'])
    && count($heart['90']['axis']) <= 13, json_encode([$heart['90']['group'], count($heart['90']['points']), count($heart['90']['axis'])]));
check('1 jaar: a point per month, 13 month boundaries', $heart['365']['group'] === 'month' && count($heart['365']['axis']) === 13);
check('the zones at their heights on each', is_array($heart['30']['zones_y']) && count($heart['30']['zones_y']) === 3);

/* =================================================================== */

section('7. A session\'s own page');

$import([
    ['recordType' => 'Steps', 'metadata' => ['id' => 'st1', 'dataOrigin' => 'app.a'], 'startTime' => $at($day(1), '18:00'), 'endTime' => $at($day(1), '19:00'), 'count' => 6000],
    ['recordType' => 'Steps', 'metadata' => ['id' => 'st2', 'dataOrigin' => 'app.b'], 'startTime' => $at($day(1), '18:00'), 'endTime' => $at($day(1), '18:30'), 'count' => 3000],
    ['recordType' => 'ActiveCaloriesBurned', 'metadata' => ['id' => 'ac1', 'dataOrigin' => 'app.a'], 'startTime' => $at($day(1), '18:00'), 'endTime' => $at($day(1), '19:00'), 'energy' => ['kilocalories' => 300]],
    ['recordType' => 'TotalCaloriesBurned', 'metadata' => ['id' => 'tc1', 'dataOrigin' => 'app.a'], 'startTime' => $at($day(1), '00:00'), 'endTime' => $at($day(2), '00:00'), 'energy' => ['kilocalories' => 2400]],
]);
$run = [];
for ($m = 0; $m < 40; $m++) {
    $run[] = $s($day(1), sprintf('18:%02d:10', $m), 120 + $m);
}
$import([$hr('h7', [], $run)]);

$view    = hydrate_training($area, $periods, $userId, $today);
$session = $view['details'][0];
$stats   = array_column($session['stats'], null, 'key');
check('its kind, its day and times', $session['title'] === 'Hardlopen' && $session['time'] === '18:00 – 18:40'
    && str_ends_with($session['date'], score_compass_date_in($day(1), substr($today, 0, 4))), json_encode([$session['date'], $session['time']]));
check('steps during it, each moment once over two apps: two thirds of 6000 = 4.000',
    ($stats['steps']['value'] ?? null) === '4.000', json_encode($stats['steps'] ?? null));
check('  active calories the same way: 200 kcal', ($stats['active_energy']['value'] ?? null) === '200' && $stats['active_energy']['unit'] === 'kcal');
check('  a day\'s total calories spread over the day: no figure for the run', !isset($stats['total_energy']));
check('  heart rate from its minutes: 139,5 → 140 on average, 159 at most',
    ($stats['avg_hr']['value'] ?? null) === '140' && ($stats['max_hr']['value'] ?? null) === '159', json_encode([$stats['avg_hr'] ?? null, $stats['max_hr'] ?? null]));
check('  no pace or speed without its own distance', !isset($stats['pace']) && !isset($stats['speed']));
check('  how long: 40 min', ($stats['duration']['value'] ?? null) === '40' && $stats['duration']['unit'] === 'min');
check('its heart rate minute by minute, from its start at the left to its end at the right',
    count($session['heart']['x']) === 40 && $session['heart']['axis'][0]['label'] === '18:00' && end($session['heart']['axis'])['label'] === '18:40'
    && $session['heart']['points'][39][3] === '159 bpm', json_encode(array_column($session['heart']['axis'], 'label')));
check('  its zones over it: 159 bpm in zone 4', $session['heart']['points'][39][1] === 'Zone 4');

$window = health_metric_window_total($userId, 'steps', $day(1) . ' 18:00:00', $day(1) . ' 18:30:00');
check('a stretch two apps both cover: each moment once, 3000 — not 6000', $window !== null && round($window) === 3000.0, (string) $window);

/* =================================================================== */

section('8. The charts');

$charts = array_column($view['charts'], null, 'id');
check('seven charts, in their order', array_keys($charts) === ['sessions', 'steps', 'energy', 'floors', 'active_minutes', 'hrv', 'training_load']);
$steps = [];
foreach ($charts['steps']['periods'][0]['points'] as $p) {
    $steps[$p[0]] = $p[3];
}
check('a day\'s steps as every page counts them: each moment once, 6.000', ($steps[$name($day(1))] ?? null) === '6.000', json_encode($steps));
check('Actieve + Totale calorieën on one height, its levels named', $charts['energy']['periods'][1]['grid'] !== []);
check('Hartbelasting and Actieve minuten: no source sends them — empty, never a 0',
    array_filter(array_column($charts['training_load']['periods'], 'has_data')) === [] && $charts['training_load']['latest'] === null
    && array_filter(array_column($charts['active_minutes']['periods'], 'has_data')) === []);

echo "\n" . str_repeat('-', 72) . "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
