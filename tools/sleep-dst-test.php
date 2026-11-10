<?php
/**
 * 47.0: a night across a change to or from summer time (docs/SLEEP.md) —
 * Europe/Amsterdam, both changes of 2026, on the database:
 *
 *   - 25 October, 03:00 CEST → 02:00 CET (the hour 02:00–03:00 twice) and
 *     29 March, 02:00 CET → 03:00 CEST (no 02:00–03:00 at all)
 *   - the stages as the app sent them before 47.0 (each start on the night's
 *     first offset, each end on its last), as it sends them now (each its
 *     own), and in UTC: stored the same, on the night's start clock, in order
 *   - the Slaap timeline: each period at its own moment, its own local
 *     times, the night's real length; the minutes, the morning it is filed
 *     under, Rusteloosheid
 *   - nights stored before 47.0 (the app's, Google Health's): read the way
 *     they were written where exactly one way can be; a night that could be
 *     read two ways is left as stored; an ordinary night is never touched
 *
 *     DB_NAME=ownify_e2e php tools/sleep-dst-test.php
 *
 * The account is named sleepdst_… and deleted at the end. Exit code 0 when
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
require_once dirname(__DIR__) . '/lib/hydrate-sleep.php';

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

db_run('INSERT INTO users (username) VALUES (?)', ['sleepdst_' . bin2hex(random_bytes(4))]);
$userId = (int) db_insert_id();
register_shutdown_function(static function () use ($userId): void {
    db_run('DELETE FROM point_events WHERE user_id = ?', [$userId]);
    db_run('DELETE FROM users WHERE id = ?', [$userId]);
});

$copy  = (require dirname(__DIR__) . '/config/health.php')['areas']['sleep'];
$utc   = static fn (string $t): int => (new DateTimeImmutable($t, new DateTimeZone('UTC')))->getTimestamp();
$ams   = new DateTimeZone('Europe/Amsterdam');
$iso   = static fn (int $t, string $offset): string => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone($offset))->format(DATE_ATOM);
$own   = static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone($ams)->format(DATE_ATOM);

/** A night in UTC, its stages [from, to, kind] in UTC, as a payload of $style. */
$payload = static function (string $id, string $from, string $to, array $stages, string $style) use ($utc, $iso, $own): array {
    $a = $utc($from);
    $b = $utc($to);
    $first = (new DateTimeImmutable('@' . $a))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('P');
    $last  = (new DateTimeImmutable('@' . $b))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('P');
    $put = static fn (int $t, string $offset): string => match ($style) {
        'before', 'first' => $iso($t, $offset),
        'own'   => $own($t),
        'utc'   => gmdate('Y-m-d\TH:i:s\Z', $t),
    };

    return [
        'recordType' => 'SleepSession',
        'metadata'   => ['id' => $id, 'dataOrigin' => 'com.google.android.apps.fitness'],
        'startTime'  => $iso($a, $first),
        'endTime'    => $iso($b, $last),
        'stages'     => array_map(static fn (array $s): array => [
            'startTime' => $put($utc($s[0]), $first),
            'endTime'   => $put($utc($s[1]), $style === 'before' ? $last : $first),
            'stage'     => $s[2],
        ], $stages),
    ];
};

$import  = static fn (array $record): array => health_import_records($userId, 'google_health_connect', health_connect_map([$record])['records']);
$session = static fn (string $id): array => db_one('SELECT * FROM sleep_sessions WHERE user_id = ? AND external_id = ?', [$userId, $id]);
$rows    = static fn (int $sid): array => db_all('SELECT stage, started_at, ended_at FROM sleep_stages WHERE sleep_session_id = ? ORDER BY id', [$sid]);
$moments = static fn (int $sid): array => array_map(static fn (array $p): array => [$p['stage'], gmdate('H:i', $p['start']), gmdate('H:i', $p['end'])],
    health_sleep_stage_periods($userId, [$sid]));
/* As it happened; a brief awakening between sleep is Rusteloosheid (8, 46.0). */
$truth   = static fn (array $stages): array => array_map(static fn (array $s): array => [$s[2] === 1 && $s[1] === '2026-10-25 01:53' ? 8 : $s[2], substr($s[0], 11, 5), substr($s[1], 11, 5)], $stages);
$timeline = static function (array $row) use ($copy, $userId): array {
    return hydrate_sleep_night_one($copy['night'], $userId, [
        'start' => strtotime((string) $row['started_at']), 'end' => strtotime((string) $row['ended_at']),
        'minutes' => $row['duration_minutes'], 'efficiency' => $row['efficiency_pct'], 'date' => $row['night_of'],
        'session_id' => (int) $row['id'], 'parts' => [(int) $row['id']],
    ]);
};

/* =================================================================== */

/* 25 October 2026: 23:00 CEST to 07:00 CET, nine hours. The change is at
   01:00 UTC; on the clock 02:10, 02:40 and 02:20, 02:50 each come once in
   each of the two hours. */
$autumn = [
    ['2026-10-24 21:00', '2026-10-24 22:00', 4],
    ['2026-10-24 22:00', '2026-10-24 23:30', 5],
    ['2026-10-24 23:30', '2026-10-25 00:10', 6],     // to 02:10 CEST
    ['2026-10-25 00:10', '2026-10-25 00:40', 4],     // 02:10–02:40 CEST
    ['2026-10-25 00:40', '2026-10-25 01:20', 5],     // 02:40 CEST – 02:20 CET, across the change
    ['2026-10-25 01:20', '2026-10-25 01:50', 6],     // 02:20–02:50 CET
    ['2026-10-25 01:50', '2026-10-25 01:53', 1],     // a brief awakening: Rusteloosheid
    ['2026-10-25 01:53', '2026-10-25 06:00', 4],     // to 07:00 CET
];

/* 29 March 2026: 23:00 CET to 07:00 CEST, seven hours. At 01:00 UTC the
   clock goes from 02:00 to 03:00. */
$spring = [
    ['2026-03-28 22:00', '2026-03-29 00:30', 4],
    ['2026-03-29 00:30', '2026-03-29 01:30', 5],     // 01:30 CET – 03:30 CEST, across the change
    ['2026-03-29 01:30', '2026-03-29 02:00', 6],
    ['2026-03-29 02:00', '2026-03-29 05:00', 4],
];

foreach ([['25 October', $autumn, '2026-10-24 21:00', '2026-10-25 06:00', 540, '2026-10-25'],
          ['29 March', $spring, '2026-03-28 22:00', '2026-03-29 05:00', 420, '2026-03-29']] as [$name, $stages, $from, $to, $length, $morning]) {
    section($name . ': as the app sends it, before and since 47.0, and in UTC');

    $stored = [];
    foreach (['before', 'own', 'utc'] as $style) {
        $id = 'dst-' . $morning . '-' . $style;
        $import($payload($id, $from, $to, $stages, $style));
        $row = $session($id);
        $stored[$style] = $rows((int) $row['id']);
        if ($style !== 'before') {
            continue;
        }

        check('the night: ' . $length . ' minutes in bed, on the morning of ' . $morning,
            (int) $row['time_in_bed_minutes'] === $length && $row['night_of'] === $morning, json_encode($row));
        $walls = array_merge(...array_map(static fn (array $r): array => [$r['started_at'], $r['ended_at']], $stored[$style]));
        $sorted = $walls;
        sort($sorted);
        check('  every period stored, on one clock, in order — never one ending before it starts', $walls === $sorted && count($stored[$style]) === count($stages), json_encode($stored[$style]));
        check('  read back: each period at its own moment', $moments((int) $row['id']) === $truth($stages), json_encode($moments((int) $row['id'])));

        $n = $timeline($row);
        $xs = array_merge(...array_map(static fn (array $b): array => [$b[1], $b[2]], $n['blocks']));
        $inOrder = $xs;
        sort($inOrder);
        check('  the timeline: bedtime 23:00 at the left, 07:00 at the right, every block where it happened, in order',
            $n['start'] === '23:00' && $n['end'] === '07:00' && $xs === $inOrder && $n['blocks'][0][1] == 0 && end($n['blocks'])[2] == 100,
            json_encode($n['blocks']));
        if ($name === '25 October') {
            $labels = array_map(static fn (array $b): string => $b[3] . '–' . $b[4], $n['blocks']);
            check('  the hour that comes twice: 02:10–02:40, then 02:40–02:20 across the change, then 02:20–02:50',
                in_array('02:10–02:40', $labels, true) && in_array('02:40–02:20', $labels, true) && in_array('02:20–02:50', $labels, true), json_encode($labels));
            check('  the block across it is 40 minutes wide on a 9-hour night, not -20',
                abs(($n['blocks'][4][2] - $n['blocks'][4][1]) - round(40 / 540 * 100, 2)) < 0.02, json_encode($n['blocks'][4]));
            $restless = (int) db_value('SELECT SUM(TIMESTAMPDIFF(SECOND, started_at, ended_at)) FROM sleep_stages WHERE sleep_session_id = ? AND stage = 8', [(int) $row['id']]);
            check('  Rusteloosheid as long as it was: 3 minutes', $restless === 180, (string) $restless);
            check('  asleep 540 minutes, awake 0 (the awakening of 3 minutes is restlessness)',
                (int) $row['duration_minutes'] === 540 && (int) $row['awake_minutes'] === 0, json_encode($row));
        } else {
            $labels = array_map(static fn (array $b): string => $b[3] . '–' . $b[4], $n['blocks']);
            check('  the hour that never was: 01:30–03:30 is one hour of deep sleep',
                $labels[1] === '01:30–03:30' && abs(($n['blocks'][1][2] - $n['blocks'][1][1]) - round(60 / 420 * 100, 2)) < 0.02, json_encode($n['blocks']));
            $ticks = array_column($n['ticks'], 'label');
            check('  the clock under it skips 02:00', !in_array('02:00', $ticks, true), json_encode($ticks));
        }
    }
    check('the same night from the app since 47.0 and in UTC: stored exactly the same',
        $stored['before'] === $stored['own'] && $stored['own'] === $stored['utc'], json_encode($stored));
}

/* =================================================================== */

section('Nights stored before 47.0, read the way they were written');

/** Stores a night with its stages exactly as given, as an older version did. */
$legacy = static function (string $id, string $from, string $to, array $walls) use ($userId): int {
    db_run('DELETE FROM sleep_stages WHERE sleep_session_id IN (SELECT id FROM sleep_sessions WHERE user_id = ? AND started_at = ?)', [$userId, $from]);
    $source = (int) db_value("SELECT id FROM data_sources WHERE code = 'google_health_connect'");
    db_run('DELETE FROM sleep_sessions WHERE user_id = ? AND started_at = ?', [$userId, $from]);     // one night per bedtime
    db_run('INSERT INTO sleep_sessions (user_id, source_id, external_id, night_of, started_at, ended_at, duration_minutes) VALUES (?, ?, ?, ?, ?, ?, 0)',
        [$userId, $source, $id, substr($to, 0, 10), $from, $to]);
    $sid = (int) db_insert_id();
    foreach ($walls as [$a, $b, $kind]) {
        db_run('INSERT INTO sleep_stages (sleep_session_id, stage, started_at, ended_at) VALUES (?, ?, ?, ?)', [$sid, $kind, $a, $b]);
    }

    return $sid;
};
$wall = static fn (int $t, string $offset): string => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone($offset))->format('Y-m-d H:i:s');
$local = static fn (int $t): string => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d H:i:s');

foreach ([['25 October', $autumn, '2026-10-24 23:00:00', '2026-10-25 07:00:00', '+02:00', '+01:00'],
          ['29 March', $spring, '2026-03-28 23:00:00', '2026-03-29 07:00:00', '+01:00', '+02:00']] as [$name, $stages, $from, $to, $first, $last]) {
    $app = $legacy('old-app-' . $name, $from, $to, array_map(static fn (array $s): array => [$wall($utc($s[0]), $first), $wall($utc($s[1]), $last), $s[2]], $stages));
    $raw = array_map(static fn (array $s): array => [$s[2], substr($s[0], 11, 5), substr($s[1], 11, 5)], $stages);     // stored as they came, before 46.0's Rusteloosheid
    check($name . ', from the app: each end on the night\'s last offset — read as it happened', $moments($app) === $raw, json_encode($moments($app)));

    $google = $legacy('old-gh-' . $name, $from, $to, array_map(static fn (array $s): array => [$local($utc($s[0])), $local($utc($s[1])), $s[2]], $stages));
    check($name . ', from Google Health: each on its own local clock — read as it happened', $moments($google) === $raw, json_encode($moments($google)));
}

/* Two periods, the change inside the first, the one time between them in
   the hour that comes twice: 02:10 CEST or 02:10 CET cannot be told. */
$unclear = [['2026-10-24 23:00:00', '2026-10-25 02:10:00', 4], ['2026-10-25 02:10:00', '2026-10-25 07:00:00', 5]];
$sid = $legacy('old-unclear', '2026-10-24 23:00:00', '2026-10-25 07:00:00', $unclear);
check('a night that could be read two ways: left as stored, nothing guessed',
    $moments($sid) === [[4, '21:00', '00:10'], [5, '00:10', '05:00']], json_encode($moments($sid)));

$plain = $legacy('old-plain', '2026-11-07 23:00:00', '2026-11-08 07:00:00', [['2026-11-07 23:00:00', '2026-11-08 03:00:00', 4], ['2026-11-08 03:00:00', '2026-11-08 07:00:00', 5]]);
check('an ordinary night: exactly as stored', $moments($plain) === [[4, '22:00', '02:00'], [5, '02:00', '06:00']], json_encode($moments($plain)));

echo "\n" . str_repeat('-', 72) . "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
