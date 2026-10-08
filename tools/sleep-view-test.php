<?php
/**
 * Slaap, drawn (lib/hydrate-sleep.php, docs/SLEEP.md) — tested on the
 * database.
 *
 *     php tools/sleep-view-test.php
 *
 * Real nights go into a throwaway account through the Health Connect import
 * the phone uses, and this asks what the Slaap page is given:
 *
 *   1. the stages are kept period by period (sleep_stages, migration 018),
 *      replaced on a re-sync — and the minutes per stage stay what they were
 *   2. the night: bedtime at the left, wake time at the right, each period
 *      on its row with its own times, "asleep, kind unknown" on none, each
 *      row's time over the night
 *   3. a night without stages, and no night at all
 *   4. the charts on Ownify's time axis: a young history from its first day
 *      at the left, a day without a value a gap — never a 0 — a week as the
 *      mean of its days, Regelmaat as the score recorded it (carried as the
 *      score is), and the value a small chart names
 *
 * The account is named sleepview_… and deleted at the end (its rows go with
 * it). Needs schema.sql and migrations up to 018. Exit code 0 when every
 * check passes.
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
require_once dirname(__DIR__) . '/lib/hydrate-sleep.php';

if (!db_available()) {
    fwrite(STDERR, "No database connection.\n");
    exit(1);
}

if (!health_sleep_stages_stored()) {
    fwrite(STDERR, "Import database/migrations/018-sleep-stages.sql first.\n");
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

db_run('INSERT INTO users (username) VALUES (?)', ['sleepview_' . bin2hex(random_bytes(4))]);
$userId = (int) db_insert_id();
db_run('UPDATE users SET created_at = NOW() - INTERVAL 30 DAY WHERE id = ?', [$userId]);

register_shutdown_function(static function () use ($userId): void {
    db_run('DELETE FROM point_events WHERE user_id = ?', [$userId]);
    db_run('DELETE FROM users WHERE id = ?', [$userId]);
});

$today = date('Y-m-d');
$day   = static fn (int $ago): string => (new DateTimeImmutable($today))->modify("-{$ago} day")->format('Y-m-d');
$at    = static fn (string $date, string $time): string => (new DateTimeImmutable($date . ' ' . $time))->format(DATE_ATOM);

/** A night that ends on $date's morning, its stages [from, to, kind] on the clock. */
$night = static function (string $id, string $date, string $from, string $to, array $stages) use ($at): array {
    $before = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
    $clock  = static fn (string $t): string => $at($t >= '12:00' ? $before : $date, $t);

    return [
        'recordType' => 'SleepSession',
        'metadata'   => ['id' => $id, 'dataOrigin' => 'com.google.android.apps.fitness'],
        'startTime'  => $clock($from),
        'endTime'    => $clock($to),
        'stages'     => array_map(static fn (array $s): array => ['startTime' => $clock($s[0]), 'endTime' => $clock($s[1]), 'stage' => $s[2]], $stages),
    ];
};

$import = static function (array $records) use ($userId): array {
    return health_import_records($userId, 'google_health_connect', health_connect_map($records)['records']);
};

$copy    = require dirname(__DIR__) . '/config/health.php';
$sleep   = $copy['areas']['sleep'];
$periods = (require dirname(__DIR__) . '/config/compass.php')['history']['periods'];

/* =================================================================== */

section('1. The stages, kept period by period');

$stages = [
    ['23:00', '23:10', 1],     // awake, falling asleep
    ['23:10', '00:00', 4],     // light
    ['00:00', '01:00', 5],     // deep
    ['01:00', '01:05', 7],     // awake in bed: Rusteloosheid
    ['01:05', '01:30', 6],     // REM
    ['01:30', '02:00', 2],     // asleep, kind unknown: on no row
    ['02:00', '07:00', 4],     // light
];
$import([$night('v1', $today, '23:00', '07:00', $stages)]);
$session = (int) db_value('SELECT id FROM sleep_sessions WHERE user_id = ? AND external_id = ?', [$userId, 'v1']);

check('every period stored, on the session\'s clock',
    (int) db_value('SELECT COUNT(*) FROM sleep_stages WHERE sleep_session_id = ?', [$session]) === 7
    && db_value('SELECT MIN(started_at) FROM sleep_stages WHERE sleep_session_id = ?', [$session]) === $day(1) . ' 23:00:00');
$row = db_one('SELECT light_minutes, deep_minutes, rem_minutes, awake_minutes, duration_minutes FROM sleep_sessions WHERE id = ?', [$session]);
check('  the minutes per stage as before: light 350, deep 60, REM 25, awake 15, asleep 465',
    [(int) $row['light_minutes'], (int) $row['deep_minutes'], (int) $row['rem_minutes'], (int) $row['awake_minutes'], (int) $row['duration_minutes']] === [350, 60, 25, 15, 465],
    json_encode($row));

$import([$night('v1', $today, '23:00', '07:00', $stages)]);
check('the same night again: replaced, never twice', (int) db_value('SELECT COUNT(*) FROM sleep_stages WHERE sleep_session_id = ?', [$session]) === 7);

$import([$night('v1', $today, '23:00', '07:00', [['23:00', '03:00', 4], ['03:00', '07:00', 5]])]);
check('  synced with other stages: those, and only those', (int) db_value('SELECT COUNT(*) FROM sleep_stages WHERE sleep_session_id = ?', [$session]) === 2);
$import([$night('v1', $today, '23:00', '07:00', $stages)]);

/* =================================================================== */

section('2. The night');

$n = hydrate_sleep_night($sleep['night'], $userId, $today);

check('bedtime at the left, wake time at the right', $n['start'] === '23:00' && $n['end'] === '07:00'
    && $n['ticks'][0] === ['x' => 0.0, 'label' => '23:00'] && end($n['ticks']) === ['x' => 100.0, 'label' => '07:00'], json_encode($n['ticks']));
check('  the hours between them, none crowding either end',
    array_column(array_slice($n['ticks'], 1, -1), 'label') === ['02:00', '04:00'], json_encode($n['ticks']));
check('five rows, top to bottom: Wakker, Rusteloosheid, REM, Licht, Diep',
    array_column($n['rows'], 'label') === ['Wakker', 'Rusteloosheid', 'REM', 'Licht', 'Diep']);
check('  each with its time over the night',
    array_column($n['rows'], 'total') === ['10 min', '5 min', '25 min', '350 min', '60 min'], json_encode(array_column($n['rows'], 'total')));
check('each period on its row, with its own times',
    $n['blocks'][0] === [0, 0.0, 2.08, '23:00', '23:10'] && $n['blocks'][3] === [1, 25.0, 26.04, '01:00', '01:05'] && $n['blocks'][4][0] === 2,
    json_encode(array_slice($n['blocks'], 0, 5)));
check('  "asleep, kind unknown" on no row: a gap from 01:30 to 02:00',
    $n['blocks'][4][4] === '01:30' && $n['blocks'][5][3] === '02:00' && $n['blocks'][5][1] === 37.5, json_encode(array_slice($n['blocks'], 4, 2)));
check('  the last period ends at the right edge', end($n['blocks'])[2] === 100.0 && end($n['blocks'])[4] === '07:00');
check('the night named, its sleep and efficiency', $n['date'] === sprintf('Nacht van %d op %s', (int) substr($day(1), 8, 2), score_compass_date($today, true))
    && $n['asleep'] === '7:45' && $n['efficiency'] === 97 && $n['staged'] && $n['note'] === null,
    json_encode([$n['date'], $n['asleep'], $n['efficiency']]));

/* =================================================================== */

section('3. Without stages, and without a night');

db_run('DELETE FROM sleep_stages WHERE sleep_session_id = ?', [$session]);
$n = hydrate_sleep_night($sleep['night'], $userId, $today);
check('a night without its periods: its times, empty rows, and it says so',
    $n['start'] === '23:00' && $n['blocks'] === [] && !$n['staged'] && $n['note'] === $sleep['night']['unstaged']
    && array_filter(array_column($n['rows'], 'total')) === []);
$import([$night('v1', $today, '23:00', '07:00', $stages)]);

$other = hydrate_sleep_night($sleep['night'], $userId, $day(30));
check('no night in the week: no times, no blocks, the empty line',
    !$other['has_night'] && $other['start'] === null && $other['blocks'] === [] && $other['note'] === $sleep['night']['empty']);

/* =================================================================== */

section('4. The charts');

/* A second night two days back; SpO2 that night and last night, none between. */
$import([
    $night('v0', $day(2), '22:30', '06:30', [['22:30', '06:30', 4]]),
    ['recordType' => 'OxygenSaturation', 'metadata' => ['id' => 'o0'], 'time' => $at($day(2), '03:00'), 'percentage' => 95.2],
    ['recordType' => 'OxygenSaturation', 'metadata' => ['id' => 'o1'], 'time' => $at($today, '03:00'), 'percentage' => 96.4],
]);

/* Regelmaat as the score recorded it: a day stored, a day carried. */
$history = [
    $day(2)  => ['date' => $day(2), 'state' => 'stored', 'from' => null, 'sleep' => ['score' => 80, 'components' => ['regularity' => 71.6]]],
    $day(1)  => ['date' => $day(1), 'state' => 'carried', 'from' => $day(2), 'sleep' => ['score' => 80, 'components' => ['regularity' => 71.6]]],
    $today   => ['date' => $today, 'state' => 'today', 'from' => null, 'sleep' => ['score' => 82, 'components' => ['regularity' => 75.2]]],
];

$charts = array_column(hydrate_sleep_charts($sleep['charts'], $sleep['chart_copy'], $periods, $userId, $history, $today), null, 'id');
$bed    = $charts['bed']['periods'][0];
$spo2   = $charts['spo2']['periods'][0];

check('four charts, in their order', array_keys($charts) === ['bed', 'spo2', 'skin_temp', 'hrv']);
check('7 dagen of a young history: from its first day at the left, the rest ahead',
    $bed['axis'][0]['label'] === score_compass_date($day(2), true) && count($bed['axis']) === 7
    && count($bed['points']) === 3 && $bed['x'][0] === $bed['axis'][0]['x'],
    json_encode([array_column($bed['axis'], 'label'), count($bed['points'])]));
check('  its dates in two rows for the small chart', $bed['axis_rows'][0]['day'] === (string) (int) substr($day(2), 8, 2) && $bed['axis_rows'][0]['month'] !== null);
check('Tijd in bed each night, nothing for the night without one',
    array_column($bed['points'], 3) === ['8:00 u', null, '8:00 u'], json_encode(array_column($bed['points'], 3)));
check('  the bars: a top for each night, null — never 0 — between',
    $bed['bars'][0]['top'][1] === null && $bed['bars'][0]['top'][0] !== null);
check('Regelmaat as the score recorded it, the carried day said so',
    array_column($bed['points'], 4) === ['72', '72', '75'] && str_contains((string) $bed['points'][1][2], 'gold nog') && $bed['carried'] === [1],
    json_encode($bed['points']));
check('  beside bars, no level named', $bed['grid'] === [] && count($bed['lines']) === 1);
check('SpO₂ on the one day it was measured, a gap on the others',
    array_column($spo2['points'], 3) === ['95%', null, '96%'] && $spo2['lines'][0]['y'][1] === null, json_encode($spo2['points']));
check('  a line alone: its levels named, whole percents', $spo2['grid'] !== [] && !array_filter(array_column($spo2['grid'], 'label'), static fn ($l) => str_contains($l, ',')));
check('the small chart names the latest value, today\'s without its date',
    $charts['spo2']['latest'] === ['texts' => ['96%'], 'date' => null] && $charts['bed']['latest']['texts'] === ['8:00 u', '75']);
check('no value at all: an empty chart, no latest', !$charts['hrv']['periods'][0]['has_data'] && $charts['hrv']['latest'] === null);

$weeks = $charts['bed']['periods'][2];
check('90 dagen: a week as one point — its days\' mean, said so',
    $weeks['group'] === 'week' && $weeks['points'][0][3] === '8:00 u' && $weeks['points'][0][1] === 'weekgemiddelde' && $weeks['points'][0][4] === '73',
    json_encode($weeks['points']));

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
