<?php
/**
 * Ownify's time axis (lib/time-axis.php, docs/CHARTS.md), without a
 * database: the window, the dates under it and the points on it, for every
 * standard period, for a new history and a long one.
 *
 *     php tools/time-axis-test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/time-axis.php';

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

$labels = static fn (array $axis): array => array_column($axis['ticks'], 'label');
$xs     = static fn (array $axis): array => array_column($axis['ticks'], 'x');
$froms  = static fn (array $axis): array => array_column($axis['slots'], 'from');

/* =================================================================== */

section('A new history: it starts at the left, the rest stays empty');

$a = time_axis(7, '2026-10-08', '2026-10-09');
check('7 dagen of two days: 8 to 14 oktober, every day named, no Vandaag',
    $labels($a) === ['8 okt', '9 okt', '10 okt', '11 okt', '12 okt', '13 okt', '14 okt'], implode(' ', $labels($a)));
check('  the window runs ahead of today', $a['start'] === '2026-10-08' && $a['end'] === '2026-10-14' && !$a['rolling']);
check('  two points, at the left — not stretched, not moved right',
    $froms($a) === ['2026-10-08', '2026-10-09'] && array_column($a['slots'], 'x') === [0.0, 16.67]);
check('  each date under its day', $xs($a) === [0.0, 16.67, 33.33, 50.0, 66.67, 83.33, 100.0]);

$a = time_axis(30, '2026-10-08', '2026-10-09');
check('30 dagen: every third day, 10 dates, into November',
    $labels($a) === ['8 okt', '11', '14', '17', '20', '23', '26', '29', '1 nov', '4'], implode(' ', $labels($a)));
check('  over the full 30 days, its two points at the left',
    $a['end'] === '2026-11-06' && array_column($a['slots'], 'x') === [0.0, 3.45]);

$a = time_axis(90, '2026-10-08', '2026-10-20');
check('90 dagen: a date a week, from the first day',
    array_slice($labels($a), 0, 5) === ['8 okt', '15', '22', '29', '5 nov'] && count($a['ticks']) === 13, implode(' ', $labels($a)));
check('  a point for each week begun: 8 and 15 oktober, nothing ahead',
    $froms($a) === ['2026-10-08', '2026-10-15'] && $a['slots'][0]['to'] === '2026-10-14');

$a = time_axis(365, '2026-10-08', '2026-10-21');
check('1 jaar: 13 month boundaries, okt to okt, no year',
    $labels($a) === ['okt', 'nov', 'dec', 'jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt'], implode(' ', $labels($a)));
check('  from the first day to a year on, the last boundary at the right edge',
    $a['ticks'][0]['date'] === '2026-10-08' && $a['ticks'][12]['date'] === '2027-10-08' && $a['ticks'][12]['x'] === 100.0);
check('  one point so far: the month that has begun', count($a['slots']) === 1 && $a['slots'][0]['to'] === '2026-11-07');

$a = time_axis(7, null, '2026-10-08');
check('no history: the period from today, no points', $a['start'] === '2026-10-08' && $a['slots'] === []);

section('A full history: the window rolls, today at the right');

$a = time_axis(7, '2025-01-01', '2026-10-08');
check('7 dagen: 2 to 8 oktober, today the last date — by its date',
    $a['rolling'] && $labels($a)[0] === '2 okt' && $labels($a)[6] === '8 okt' && end($a['slots'])['x'] === 100.0);

$a = time_axis(30, '2025-01-01', '2026-10-08');
check('30 dagen: 9 september to today, a point a day, the newest at the right',
    $a['start'] === '2026-09-09' && count($a['slots']) === 30 && end($a['slots'])['from'] === '2026-10-08' && end($a['slots'])['x'] === 100.0);

$a = time_axis(90, '2025-01-01', '2026-10-08');
check('90 dagen: 13 weeks, the last one ending today',
    count($a['slots']) === 13 && end($a['slots'])['to'] === '2026-10-08' && $a['slots'][0]['from'] === '2026-07-11');

$a = time_axis(365, '2024-01-01', '2026-10-08');
check('1 jaar: 12 months — never a 13th — the last one ending today',
    count($a['slots']) === 12 && count($a['ticks']) === 13 && end($a['slots'])['to'] === '2026-10-08');
check('  okt at both ends: the oldest boundary and the newest', $labels($a)[0] === 'okt' && $labels($a)[12] === 'okt');
check('  each month starts where its date stands',
    array_column($a['slots'], 'x') === array_slice($xs($a), 0, 12));

$a = time_axis(30, '2026-09-24', '2026-10-08');
check('half a period of history: still from its first day', $a['start'] === '2026-09-24' && !$a['rolling'] && count($a['slots']) === 15);

$a = time_axis(30, '2026-09-09', '2026-10-08');
check('exactly a period: full, and rolling from here', $a['start'] === '2026-09-09' && $a['rolling']);

section('The calendar');

check('31 january + 1 month: 28 february, never 3 march', time_axis_add_months(new DateTimeImmutable('2027-01-31'), 1)->format('Y-m-d') === '2027-02-28');
check('  in a leap year: 29 february', time_axis_add_months(new DateTimeImmutable('2028-01-31'), 1)->format('Y-m-d') === '2028-02-29');
check('  each month from the first day, not from the last: 31 jan + 2 = 31 mrt',
    time_axis_add_months(new DateTimeImmutable('2027-01-31'), 2)->format('Y-m-d') === '2027-03-31');
$a = time_axis(365, '2027-01-31', '2027-02-10');
check('a year from 31 january: jan, feb, mrt … jan', $labels($a) === ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec', 'jan']);
$a = time_axis(30, '2026-12-20', '2026-12-22');
check('30 dagen over the new year: "1 jan", no year', in_array('1 jan', $labels($a), true) || in_array('2 jan', $labels($a), true), implode(' ', $labels($a)));
$a = time_axis(365, '2025-03-01', '2028-03-01');
check('a rolling year over a leap day: still 12 months, the last ending today',
    count($a['slots']) === 12 && end($a['slots'])['to'] === '2028-03-01' && $a['start'] === '2027-03-03');

section('Dates in two rows');

$rows = time_axis_rows(time_axis(7, '2026-09-28', '2026-10-02')['ticks'], static fn (float $x): float => 5 + $x * 0.9);
check('a week for a narrow chart: the day over its month, the month at the first date and where it changes',
    array_column($rows, 'day') === ['28', '29', '30', '1', '2', '3', '4'] && array_column($rows, 'month') === ['sep', null, null, 'okt', null, null, null],
    json_encode($rows));
check('  the same dates at the same places, moved as the chart insets its plot', $rows[0]['x'] === 5.0 && $rows[6]['x'] === 95.0);

section('A chart without a period switch');

check('3 days of history: 7 dagen', time_axis_fit('2026-10-06', '2026-10-08') === 7);
check('8 days: 30 dagen', time_axis_fit('2026-10-01', '2026-10-08') === 30);
check('31 days: 90 dagen', time_axis_fit('2026-09-08', '2026-10-08') === 90);
check('91 days: a year', time_axis_fit('2026-07-09', '2026-10-08') === 365);
check('two years: a year, rolling', time_axis_fit('2024-10-08', '2026-10-08') === 365);

section('A point per day over 90 dagen');

$daily = time_axis(90, '2026-07-01', '2026-10-08', 'day');
$weekly = time_axis(90, '2026-07-01', '2026-10-08');
check('the heart rate\'s averages: a point each day, 90 of them, the window rolling',
    $daily['grain'] === 'day' && count($daily['slots']) === 90 && $daily['slots'][1]['from'] === '2026-07-12' && $daily['rolling'],
    json_encode([count($daily['slots']), $daily['slots'][1] ?? null]));
check('  the dates the period\'s own: every seventh day, as with a point per week',
    array_column($daily['ticks'], 'label') === array_column($weekly['ticks'], 'label') && count($weekly['slots']) === 13);
$threw = false;
try {
    time_axis(365, '2026-01-01', '2026-10-08', 'day');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('  only over 90 dagen: a year of days is no standard', $threw);

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
