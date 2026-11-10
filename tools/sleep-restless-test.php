<?php
/**
 * 46.0: restlessness is not time awake (docs/SLEEP.md) — a Health Connect
 * night as Fitbit writes it, through health_connect_map(): its short
 * awakenings (awake stages of a minute or two between sleep) are
 * Rusteloosheid, part of the sleep; only real awake periods are Wakker; the
 * minutes are added up in seconds, so dozens of short periods do not round
 * up to many minutes; Polar's own wakes stay awake.
 *
 *     php tools/sleep-restless-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/health-connect-map.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " — $detail") . "\n";
}

$t = static fn (string $hm, int $s = 0): string => '2026-11-08T' . $hm . ':' . sprintf('%02d', $s) . '+01:00';

/* 23:00 awake 5 min (falling asleep) — then light until 01:00 with three short
   awakenings of 80 s each in it, deep, an awakening of 10 minutes, REM, light,
   and awake 4 minutes at the end (lying awake). */
$stages = [
    ['startTime' => '2026-11-07T23:00:00+01:00', 'endTime' => '2026-11-07T23:05:00+01:00', 'stage' => 1],
    ['startTime' => '2026-11-07T23:05:00+01:00', 'endTime' => $t('00:10'), 'stage' => 4],
];
$at = strtotime($t('00:10'));
for ($i = 0; $i < 3; $i++) {
    $stages[] = ['startTime' => date(DATE_ATOM, $at), 'endTime' => date(DATE_ATOM, $at + 80), 'stage' => 1];
    $stages[] = ['startTime' => date(DATE_ATOM, $at + 80), 'endTime' => date(DATE_ATOM, $at + 980), 'stage' => 4];
    $at += 980;
}
$stages[] = ['startTime' => date(DATE_ATOM, $at), 'endTime' => $t('02:00'), 'stage' => 5];
$stages[] = ['startTime' => $t('02:00'), 'endTime' => $t('02:10'), 'stage' => 1];
$stages[] = ['startTime' => $t('02:10'), 'endTime' => $t('04:00'), 'stage' => 6];
$stages[] = ['startTime' => $t('04:00'), 'endTime' => $t('06:56'), 'stage' => 4];
$stages[] = ['startTime' => $t('06:56'), 'endTime' => $t('07:00'), 'stage' => 1];

$night = health_connect_map([['recordType' => 'SleepSession', 'metadata' => ['id' => 'hc-night', 'dataOrigin' => 'com.fitbit.FitbitMobile'],
    'startTime' => '2026-11-07T23:00:00+01:00', 'endTime' => $t('07:00'), 'stages' => $stages]])['records'][0] ?? [];

$kinds = array_count_values(array_column($night['stages'] ?? [], 'stage'));
check('the three short awakenings are Rusteloosheid (stage 8)', ($kinds[8] ?? 0) === 3, json_encode($kinds));
check('the awakening of 10 minutes, and lying awake at either end, stay awake', ($kinds[1] ?? 0) === 3, json_encode($kinds));
check('awake time is the real awake periods only: 5 + 10 + 4 = 19 minutes', ($night['awake_minutes'] ?? null) === 19, json_encode($night));
check('restlessness is part of the sleep: 480 − 19 = 461 minutes asleep', ($night['duration_minutes'] ?? null) === 461);
check('time in bed is the night, not more: 480 minutes', ($night['time_in_bed_minutes'] ?? null) === 480);
check('efficiency 461 / 480', abs((float) ($night['efficiency_pct'] ?? 0) - 96.04) < 0.01, (string) ($night['efficiency_pct'] ?? ''));
check('awakenings: the three real ones, not the restless ones', ($night['awakenings'] ?? null) === 3);
check('light, deep and REM as measured (the restless minutes are not light)', ($night['light_minutes'] ?? null) === 286
    && ($night['deep_minutes'] ?? null) === 61 && ($night['rem_minutes'] ?? null) === 110, json_encode([$night['light_minutes'] ?? null, $night['deep_minutes'] ?? null, $night['rem_minutes'] ?? null]));

/* Dozens of 80-second awakenings, each rounded to 1 minute before, added up
   to more than they were: now seconds first. */
$many = [];
$at = strtotime('2026-11-07T23:00:00+01:00');
for ($i = 0; $i < 30; $i++) {
    $many[] = ['startTime' => date(DATE_ATOM, $at), 'endTime' => date(DATE_ATOM, $at + 600), 'stage' => 4];
    $many[] = ['startTime' => date(DATE_ATOM, $at + 600), 'endTime' => date(DATE_ATOM, $at + 690), 'stage' => 1];
    $at += 690;
}
$many[] = ['startTime' => date(DATE_ATOM, $at), 'endTime' => date(DATE_ATOM, $at + 600), 'stage' => 4];
$long = health_connect_sleep('x', ['startTime' => '2026-11-07T23:00:00+01:00', 'endTime' => date(DATE_ATOM, $at + 600), 'stages' => $many])[0];
check('thirty 90-second awakenings: no time awake, their 45 minutes in the sleep, nothing rounded up', ($long['awake_minutes'] ?? null) === 0
    && ($long['duration_minutes'] ?? null) === (int) round((31 * 600 + 30 * 90) / 60) && ($long['time_in_bed_minutes'] ?? null) === $long['duration_minutes'], json_encode($long));

/* Polar: its own interruptions, kept as awake. */
$polar = health_connect_sleep('p', ['startTime' => '2026-11-07T23:00:00+01:00', 'endTime' => $t('07:00'), 'stages' => $stages], false)[0];
check('Polar: a short wake is Polar\'s interruption — kept as awake', ($polar['awake_minutes'] ?? null) === 23 && !in_array(8, array_column($polar['stages'], 'stage'), true), json_encode($polar['awake_minutes'] ?? null));

/* Google's RESTLESS arrives as stage 8 (google_health_sleep_stage) and counts as sleep. */
$g = health_connect_sleep('g', ['startTime' => '2026-11-07T23:00:00+01:00', 'endTime' => $t('01:00'), 'stages' => [
    ['startTime' => '2026-11-07T23:00:00+01:00', 'endTime' => $t('00:00'), 'stage' => 2],
    ['startTime' => $t('00:00'), 'endTime' => $t('00:20'), 'stage' => 8],
    ['startTime' => $t('00:20'), 'endTime' => $t('01:00'), 'stage' => 2],
]])[0];
check('Google\'s RESTLESS: 20 minutes in the sleep, none awake', ($g['awake_minutes'] ?? null) === 0 && ($g['duration_minutes'] ?? null) === 120);

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
