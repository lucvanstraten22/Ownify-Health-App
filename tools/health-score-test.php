<?php
/**
 * The Health Score formulas and the points rules, tested without a database.
 *
 *     php tools/health-score-test.php
 *
 * Every score in Ownify comes out of includes/health-score.php, with its
 * numbers in config/scoring.php; every point value out of includes/points.php
 * and config/points.php. This checks the arithmetic on made-up records held in
 * memory — the curves, the 90-day window, the 3-day minimum, missing data
 * that must not count as zero, re-weighting, the overall average, and the
 * point tiers — so tuning a number and running this says at once whether the
 * behaviour still holds. Nothing here touches the database or an account.
 *
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/health-score.php';
require_once dirname(__DIR__) . '/includes/points.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        printf("  PASS  %s\n", $label);
        return;
    }

    $fail++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : "\n          " . $detail);
}

function section(string $title): void
{
    echo "== {$title} ==\n";
}

$cfg   = health_scoring_config();
$asOf  = new DateTimeImmutable('2026-09-25 15:00:00');
$now   = $asOf->getTimestamp();
$day   = 86400;

/** A night that ended $daysAgo days before $asOf, $hours long, bedtime near $bed (minutes after midnight). */
function night(int $end, float $hours, array $extra = []): array
{
    $start = $end - (int) round($hours * 3600);

    return $extra + [
        'session_id' => $end, 'date' => date('Y-m-d', $end), 'start' => $start, 'end' => $end,
        'minutes' => $hours * 60, 'in_bed' => null, 'efficiency' => null, 'awake' => null,
        'light' => null, 'deep' => null, 'rem' => null,
    ];
}

/** $n nights ending at 07:00 on consecutive mornings before $asOf, with a little variation. */
function nights(int $n, float $hours, int $jitterMinutes = 10, array $extra = []): array
{
    global $asOf;
    $out = [];

    for ($i = $n; $i >= 1; $i--) {
        $morning = $asOf->setTime(7, 0)->modify('-' . $i . ' days')->getTimestamp();
        $shift   = (($i * 37) % (2 * $jitterMinutes + 1)) - $jitterMinutes;      // deterministic spread
        $out[]   = night($morning + $shift * 60, $hours + ((($i * 53) % 21) - 10) / 600, $extra);
    }

    return $out;
}

function rating(int $at, float $value): array
{
    return ['at' => $at, 'date' => date('Y-m-d', $at), 'value' => $value];
}

function workout(int $id, int $start, float $minutes, ?string $class = null, array $extra = []): array
{
    return $extra + [
        'id' => $id, 'type' => 'running', 'start' => $start, 'end' => $start + (int) ($minutes * 60),
        'minutes' => $minutes, 'km' => null, 'kmh' => null, 'avg_hr' => null, 'max_hr' => null,
        'rpe' => null, 'zones' => [], 'effort' => ['class' => $class, 'rpe' => null, 'zone_share' => null, 'hr_pct' => null],
        'heavy' => $class === 'hard' || $minutes >= 90,
    ];
}

/** Workouts on the given days-ago, each $minutes long with the given class. */
function schedule(array $daysAgo, float $minutes, callable $class, array $extra = []): array
{
    global $now;
    $out = [];
    $id  = 1;

    foreach ($daysAgo as $ago) {
        $out[] = workout($id++, $now - $ago * 86400 - 3 * 3600, $minutes, $class($ago), $extra);
    }

    return $out;
}

function range_days(int $from, int $to, int $step = 1, array $weekdays = null): array
{
    $out = [];
    for ($d = $from; $d >= $to; $d -= $step) {
        if ($weekdays === null || in_array($d % 7, $weekdays, true)) {
            $out[] = $d;
        }
    }
    return $out;
}

/* ====================================================================== */
section('curves are smooth, and shaped the way the product describes');

$sleepCurve = $cfg['sleep']['duration_curve'];
$at = static fn (float $h): float => health_curve($sleepCurve, $h);

check('7:59 and 8:00 of sleep score practically the same', abs($at(7 + 59 / 60) - $at(8.0)) < 0.5,
    sprintf('%.2f vs %.2f', $at(7 + 59 / 60), $at(8.0)));

$rising = true;
for ($i = 200; $i < 800; $i++) {                  // whole hundredths: no drifting float
    if ($at(($i + 1) / 100) < $at($i / 100) - 1e-9) { $rising = false; }
}
$falling = true;
for ($i = 800; $i < 1400; $i++) {
    if ($at(($i + 1) / 100) > $at($i / 100) + 1e-9) { $falling = false; }
}
check('the duration curve only rises up to 8 hours, and only falls after', $rising && $falling);
check('4 hours is low (very poor band, under 30)', $at(4) < 30, (string) $at(4));
check('5 hours is below average (45-59)', $at(5) >= 45 && $at(5) < 60, (string) $at(5));
check('6 hours is decent, not optimal (60-69)', $at(6) >= 60 && $at(6) < 70, (string) $at(6));
check('7 hours is high (80+)', $at(7) >= 80, (string) $at(7));
check('7.5 to 8.5 hours is excellent (95+)', min($at(7.5), $at(8), $at(8.5)) >= 95);
check('9 hours is still very good (85+), 9.5 lower, 10 lower still',
    $at(9) >= 85 && $at(9.5) < $at(9) && $at(10) < $at(9.5));
check('outside the curve the end values hold', $at(1) === $at(2) && $at(20) === $at(14));

$volume = $cfg['training']['volume_curve'];
check('training volume has diminishing returns',
    (health_curve($volume, 300) - health_curve($volume, 150)) < (health_curve($volume, 150) - health_curve($volume, 0)));
check('extreme volume is not automatically 100', health_curve($volume, 900) < 100);

/* ====================================================================== */
section('a category needs 3 distinct days of real data');

check('the minimum is 3 days', (int) $cfg['min_days'] === 3, (string) $cfg['min_days']);

foreach ([0, 1, 2] as $n) {
    $r = health_score_sleep(nights($n, 8));
    check("{$n} nights: no sleep score, and {$n} days counted", $r['score'] === null && $r['days'] === $n);
    $ratings = $n === 0 ? [] : array_map(static fn ($i) => rating($now - $i * $day, 7), range(1, $n));
    $r = health_score_nutrition($ratings);
    check("{$n} rated days: no nutrition score, {$n} counted", $r['score'] === null && $r['days'] === $n);
}
check('3 nights: a sleep score', is_int(health_score_sleep(nights(3, 8))['score']));

$ratings3 = array_map(static fn ($i) => rating($now - $i * $day, 7), range(1, 3));
check('3 rated days: a nutrition score, the same arithmetic (7 -> 70)', health_score_nutrition($ratings3)['score'] === 70);

$twoOnOneDay = [rating($now - $day, 7), rating($now - $day + 3600, 9), rating($now - 2 * $day, 7)];
check('distinct days, not ratings: 3 ratings on 2 days are still 2 days, no score',
    ($r = health_score_nutrition($twoOnOneDay))['score'] === null && $r['days'] === 2);

$two   = schedule([1, 3], 45, static fn () => 'moderate');
$three = schedule([1, 3, 5], 45, static fn () => 'moderate');
check('2 training days: no training score', health_score_training($two, [], [], [], $now)['score'] === null);
check('3 training days: a training score', is_int(health_score_training($three, [], [], [], $now)['score']));

$none = ['nights' => [], 'ratings' => [], 'workouts' => [], 'vo2' => [], 'goals' => []];
check('2 days in every category: no overall score, never a 0',
    health_score_at(['nights' => nights(2, 8), 'ratings' => array_slice($ratings3, 0, 2)] + $none, $asOf)['overall']['score'] === null);
$unlocked = health_score_at(['nights' => nights(3, 8)] + $none, $asOf);
check('3 nights and nothing else: the overall score is the sleep score — the others left out, not zero',
    is_int($unlocked['overall']['score']) && $unlocked['overall']['score'] === $unlocked['sleep']['score']
    && $unlocked['nutrition']['score'] === null && $unlocked['training']['score'] === null);

/* ====================================================================== */
section('missing days are not zero');

$spread = array_map(static fn ($i) => rating($now - $i * 12 * $day, 7), range(0, 7));   // 8 days over 84
check('8 ratings of 7 spread over 84 days score 70, not 8 x 70 / 90',
    health_score_nutrition($spread)['score'] === 70);

$sparse = [];
foreach (range(1, 10) as $i) {
    $sparse[] = night($asOf->setTime(7, 0)->modify('-' . ($i * 8) . ' days')->getTimestamp(), 8.0);
}
$r = health_score_sleep($sparse);
check('10 nights of 8 hours in 90 days: duration stays at the top', $r['components']['duration'] >= 99,
    json_encode($r['components']));

/* ====================================================================== */
section('nutrition is the daily self-assessment, 1-10 onto 0-100');

foreach ([1 => 10, 5 => 50, 7 => 70, 10 => 100] as $value => $expected) {
    $week = array_map(static fn ($i) => rating($now - $i * $day, $value), range(1, 7));
    check("a week of {$value}s scores {$expected}", health_score_nutrition($week)['score'] === $expected);
}

$twoADay = array_map(static fn ($i) => rating($now - $i * $day, 7), range(1, 7));
$twoADay[] = rating($now - $day + 3600, 9);        // a second rating on day 1: 7 and 9 -> 8 that day
$expected = (int) round((8 * 10 + 6 * 70) / 7);
check('two ratings on one day count as that day\'s average, once', health_score_nutrition($twoADay)['score'] === $expected,
    health_score_nutrition($twoADay)['score'] . ' vs ' . $expected);

check('a real 1 every day is a score of 10 — low, not missing', health_score_nutrition(
    array_map(static fn ($i) => rating($now - $i * $day, 1), range(1, 7)))['score'] === 10);

/* ====================================================================== */
section('sleep: duration 45%, regularity 30%, quality 25%');

$plain = health_score_sleep(nights(30, 7.5, 15));
$c     = $plain['components'];
check('without stage data there is no quality component', $c['quality'] === null);
check('…and the score is duration and regularity re-weighted, not a zero for quality',
    $plain['score'] === (int) round((0.45 * $c['duration'] + 0.30 * $c['regularity']) / 0.75),
    $plain['score'] . ' from ' . json_encode($c));

$staged = health_score_sleep(nights(30, 7.5, 15, [
    'efficiency' => 93.0, 'awake' => 18.0, 'light' => 250.0, 'deep' => 80.0, 'rem' => 100.0,
]));
check('with stage data quality counts', $staged['components']['quality'] !== null,
    json_encode($staged['components']));

$regular   = health_score_sleep(nights(30, 7.0, 10));
$irregular = health_score_sleep(nights(30, 7.0, 150));
check('the same hours at regular times score higher than at irregular times',
    $regular['score'] > $irregular['score'], $regular['score'] . ' vs ' . $irregular['score']);

check('a regular 8-hour sleeper is excellent (90+)', health_score_sleep(nights(30, 8.0, 10))['score'] >= 90);
$sixRegular   = health_score_sleep(nights(30, 6.0, 20))['score'];
$sevenRegular = health_score_sleep(nights(30, 7.0, 20))['score'];
$eightRegular = health_score_sleep(nights(30, 8.0, 20))['score'];
check('regularity cannot make 6 hours very good: a perfectly regular 6-hour sleeper stays under 80',
    $sixRegular >= 60 && $sixRegular < 80, (string) $sixRegular);
check('at the same regularity, 6 < 7 < 8 hours', $sixRegular < $sevenRegular && $sevenRegular < $eightRegular,
    "$sixRegular / $sevenRegular / $eightRegular");

/* ====================================================================== */
section('a night is its main sleep, seen once');

$watch = night($now - 5 * $day, 7.6, ['session_id' => 1, 'light' => 250.0, 'deep' => 90.0, 'rem' => 116.0, 'awake' => 20.0]);
$phone = night($now - 5 * $day + 600, 8.4, ['session_id' => 2]);                  // same night, longer, no stages
$nap   = night($now - 5 * $day + 8 * 3600, 1.5, ['session_id' => 3]);            // an afternoon nap, same date
check('the same night from a watch (stages) and a phone (none): the watch counts',
    health_night_main([$phone, $watch])['session_id'] === 1);
check('a nap on the same date is not the night', health_night_main([$nap, $watch, $phone])['session_id'] === 1);
check('two recordings without stages: the longer counts',
    health_night_main([night($now, 7.0, ['session_id' => 4]), night($now + 300, 7.4, ['session_id' => 5])])['session_id'] === 5);

/* A night broken by getting up: 3:30 asleep, 40 minutes up, 3:50 asleep. */
$wake   = $now - 4 * $day;
$first  = night($wake - (int) (4.5 * 3600), 3.5, ['session_id' => 20, 'in_bed' => 215.0, 'awake' => 5.0]);
$second = night($wake, 230 / 60, ['session_id' => 21, 'in_bed' => 235.0, 'awake' => 5.0]);
$broken = health_night_main([$second, $first]);
check('a night broken by 40 minutes up is one night: 3:30 + 3:50 = 7:20',
    (int) round($broken['minutes']) === 440, (string) $broken['minutes']);
check('  from the first part\'s start to the second part\'s end',
    $broken['start'] === $first['start'] && $broken['end'] === $second['end']);
check('  named after its longest part, and its measurements added up',
    $broken['session_id'] === 21 && $broken['in_bed'] === 450.0 && $broken['awake'] === 10.0
    && abs($broken['efficiency'] - 97.78) < 0.01, json_encode($broken));
check('  a measurement one part lacks is not the night\'s',
    health_night_main([$first, night($wake, 230 / 60, ['session_id' => 22])])['in_bed'] === null);

$evening = night($first['start'] - 90 * 60, 0.5, ['session_id' => 23]);        // 90 minutes before bed
check('a nap 90 minutes before bed is not joined to the night',
    (int) round(health_night_main([$evening, $first, $second])['minutes']) === 440);
check('a night recorded whole by a watch and in two parts by a phone counts once, the watch\'s',
    health_night_main([$first, $second, night($wake, 7.9, ['session_id' => 24, 'deep' => 80.0, 'rem' => 90.0])])['session_id'] === 24);
check('an ordinary night reads exactly as its session', health_night_main([$watch]) === $watch);

/* ====================================================================== */
section('Health Connect sleep stages');

require_once dirname(__DIR__) . '/includes/health-connect-map.php';

/** A Health Connect sleep session from 23:00 to 07:00, with stages as [from, to, stage] in hours after 23:00. */
function hc_night(array $stages): array
{
    $at = static fn (float $h): string => gmdate('Y-m-d\TH:i:s\Z', (int) (strtotime('2026-09-01T21:00:00Z') + $h * 3600));

    return health_connect_sleep('hc', [
        'startTime' => $at(0),
        'endTime'   => $at(8),
        'stages'    => array_map(static fn ($s) => ['startTime' => $at($s[0]), 'endTime' => $at($s[1]), 'stage' => $s[2]], $stages),
    ])[0];
}

$detailed = hc_night([[0, 2, 4], [2, 3.5, 5], [3.5, 5, 6], [5, 5.25, 1], [5.25, 8, 4]]);
check('light, deep, REM and awake: unchanged — 7:45 asleep, 15 awake, the breakdown kept',
    $detailed['duration_minutes'] === 465 && $detailed['awake_minutes'] === 15 && $detailed['time_in_bed_minutes'] === 480
    && $detailed['light_minutes'] === 285 && $detailed['deep_minutes'] === 90 && $detailed['rem_minutes'] === 90
    && $detailed['awakenings'] === 1, json_encode($detailed));

$generic = hc_night([[0, 3.5, 2], [3.5, 3.6667, 1], [3.6667, 8, 2]]);
check('stage 2 "sleeping" is sleep: 7:50 asleep, 10 awake — not the 0:00 it was',
    $generic['duration_minutes'] === 470 && $generic['awake_minutes'] === 10 && $generic['time_in_bed_minutes'] === 480,
    json_encode($generic));
check('  and no light, deep or REM is claimed for it (unknown, not zero)',
    !isset($generic['light_minutes']) && !isset($generic['deep_minutes']) && !isset($generic['rem_minutes']));

$outOfBed = hc_night([[0, 3, 4], [3, 3.3333, 3], [3.3333, 5, 5], [5, 6.5, 6], [6.5, 8, 4]]);
check('stage 3 "out of bed" is not sleep and not time in bed: 7:40 asleep of the 8 hours',
    $outOfBed['duration_minutes'] === 460 && $outOfBed['time_in_bed_minutes'] === 460 && $outOfBed['efficiency_pct'] === 100.0,
    json_encode($outOfBed));
check('  the session keeps its own start and end', $outOfBed['started_at'] === '2026-09-01T21:00:00Z' && $outOfBed['ended_at'] === '2026-09-02T05:00:00Z');

$mixed = hc_night([[0, 2, 4], [2, 4, 2], [4, 5.5, 5], [5.5, 8, 6]]);
check('"sleeping" next to measured stages still counts as sleep: 8:00 asleep',
    $mixed['duration_minutes'] === 480 && $mixed['light_minutes'] === 120 && $mixed['deep_minutes'] === 90, json_encode($mixed));

foreach (['only out of bed' => [[0, 8, 3]], 'only awake' => [[0, 8, 1]], 'only unknown' => [[0, 8, 0]], 'none' => []] as $what => $stages) {
    $session = hc_night($stages);
    check("stages that hold no sleep ($what): the session keeps its own times, never 0:00",
        !isset($session['duration_minutes']) && !isset($session['time_in_bed_minutes']), json_encode($session));
}

/* ====================================================================== */
section('training: more is not automatically better');

$sensible = schedule(range_days(84, 0, 1, [0, 2, 4, 5]), 60,
    static fn ($d) => $d % 7 === 0 ? 'hard' : 'moderate');
$excessive = schedule(range_days(84, 0), 120, static fn () => 'hard');
$little    = schedule(range_days(84, 0, 7), 30, static fn () => 'easy');

$s = health_score_training($sensible, [], [], [], $now);
$x = health_score_training($excessive, [], [], [], $now);
$l = health_score_training($little, [], [], [], $now);

check('4 sessions a week with rest days beats 2 hours of hard training every day',
    $s['score'] > $x['score'], $s['score'] . ' vs ' . $x['score']);
check('…even though the every-day volume is higher', $x['components']['volume'] > $s['components']['volume']);
check('balance is where overtraining loses', $x['components']['balance'] < $s['components']['balance'] - 20,
    json_encode($x['components']));
check('all-hard intensity scores below a mix', $x['components']['intensity'] < $s['components']['intensity']);
check('training once a week for 30 minutes is below average', $l['score'] < 60, (string) $l['score']);

$paced = static function (float $from, float $to): array {
    global $now;
    $out = [];
    $days = range_days(84, 0, 1, [1, 3, 5]);
    foreach ($days as $i => $ago) {
        $kmh = $from + ($to - $from) * $i / max(1, count($days) - 1);
        $out[] = workout($i + 1, $now - $ago * 86400, 40, 'moderate', ['km' => 6.0, 'kmh' => $kmh]);
    }
    return $out;
};
$better = health_score_training($paced(10.0, 11.0), [], [], [], $now)['components']['progression'];
$same   = health_score_training($paced(10.0, 10.0), [], [], [], $now)['components']['progression'];
$worse  = health_score_training($paced(11.0, 10.0), [], [], [], $now)['components']['progression'];
check('progression is relative to yourself: faster > steady > slower', $better > $same && $same > $worse,
    "$better / $same / $worse");
check('without anything to compare, progression is left out, not zero',
    health_score_training($sensible, [], [], [], $now)['components']['progression'] === null);

$goalUp = [['direction' => 'increase', 'entries' => [
    ['at' => $now - 60 * $day, 'value' => 80.0], ['at' => $now - 40 * $day, 'value' => 82.5],
    ['at' => $now - 20 * $day, 'value' => 85.0], ['at' => $now - 5 * $day, 'value' => 87.5],
]]];
check('a strength goal going up counts as progression',
    health_score_training($sensible, [], [], $goalUp, $now)['components']['progression'] > 60);

/* ====================================================================== */
section('the overall score is the average of the categories that have one');

check('82, 74, 71 -> 76', score_combine(['sleep' => 82, 'nutrition' => 74, 'training' => 71]) === 76);
check('82, 74, missing -> 78 (not 52)', score_combine(['sleep' => 82, 'nutrition' => 74, 'training' => null]) === 78);
check('82, missing, missing -> 82', score_combine(['sleep' => 82, 'nutrition' => null, 'training' => null]) === 82);
check('all missing -> no score, not 0', score_combine(['sleep' => null, 'nutrition' => null, 'training' => null]) === null);
check('a real 0 is a score', score_combine(['sleep' => 0, 'nutrition' => null, 'training' => null]) === 0);

/* ====================================================================== */
section('the window is the last 90 days, moving with the clock');

$start = health_score_window_start($asOf);
check('the window starts exactly 90 days before the moment', $start->format('Y-m-d H:i:s') === '2026-06-27 15:00:00',
    $start->format('Y-m-d H:i:s'));

$edge = nights(7, 8);
$edge[] = night($start->getTimestamp() + 60, 8);     // ended a minute inside the window
$edge[] = night($start->getTimestamp() - 60, 8);     // ended a minute before it
usort($edge, static fn ($a, $b) => $a['end'] <=> $b['end']);

$data = ['nights' => $edge, 'ratings' => [], 'workouts' => [], 'vo2' => [], 'goals' => []];
check('a night one minute inside the window counts, one minute outside does not',
    health_score_at($data, $asOf)['sleep']['days'] === 8, (string) health_score_at($data, $asOf)['sleep']['days']);
check('an hour later the window has moved past the older of the two',
    health_score_at($data, $asOf->modify('+1 hour'))['sleep']['days'] === 7);

$monthEdge = new DateTimeImmutable('2026-10-01 09:00:00');
$lateSept  = array_map(static fn ($i) => night((new DateTimeImmutable('2026-09-30 07:00'))->modify('-' . $i . ' days')->getTimestamp(), 8), range(0, 6));
check('on the 1st of a month last month still counts — no calendar reset',
    is_int(health_score_at(['nights' => $lateSept, 'ratings' => [], 'workouts' => [], 'vo2' => [], 'goals' => []], $monthEdge)['sleep']['score']));

$old = array_map(static fn ($i) => night($asOf->modify('-' . (91 + $i) . ' days')->getTimestamp(), 8), range(0, 9));
check('data older than 90 days gives no score at all',
    health_score_at(['nights' => $old, 'ratings' => [], 'workouts' => [], 'vo2' => [], 'goals' => []], $asOf)['sleep']['score'] === null);

/* ====================================================================== */
section('points: one tier, never the sum of the tiers');

$tiers = points_config()['sleep']['duration_tiers'];
foreach ([[419, 0], [420, 25], [449, 25], [450, 35], [479, 35], [480, 45], [510, 45], [539, 45],
          [540, 35], [570, 35], [571, 20], [700, 20]] as [$minutes, $expected]) {
    check(sprintf('%d:%02d of sleep -> %d', intdiv($minutes, 60), $minutes % 60, $expected),
        points_tier_range($tiers, $minutes) === $expected);
}

foreach ([[1, 0], [3, 0], [4, 10], [5, 10], [6, 25], [7, 25], [8, 40], [9, 40], [10, 50]] as [$r, $expected]) {
    check("nutrition rating {$r} -> {$expected}", points_nutrition_value($r) === $expected);
}

foreach ([[4999, 0], [5000, 10], [7499, 10], [7500, 20], [10000, 35], [12499, 35], [12500, 45], [30000, 45]] as [$steps, $expected]) {
    check(number_format($steps, 0, ',', '.') . " steps -> {$expected}", points_steps_value($steps) === $expected);
}

$wt = points_config()['training']['duration_tiers'];
$workoutPoints = static function (int $minutes) use ($wt): int {
    $points = 0;
    foreach ($wt as [$from, $to, $value]) {
        if ($minutes >= $from && ($to === null || $minutes < $to)) { $points = $value; }
    }
    return $points;
};
check('a 20-minute workout is short (20), 45 minutes normal (35), 75 long (45)',
    $workoutPoints(20) === 20 && $workoutPoints(45) === 35 && $workoutPoints(75) === 45);

/* ====================================================================== */
section('points: weeks and personal records');

$sunday = (new DateTimeImmutable('2026-09-27 18:00'))->getTimestamp();
$monday = (new DateTimeImmutable('2026-09-21 08:00'))->getTimestamp();
check('a Sunday belongs to the week that started on the Monday before', points_week_start(1, $sunday) === '2026-09-21');
check('a Monday starts its own week', points_week_start(1, $monday) === '2026-09-21');

$runs = [];
foreach ([1, 2, 3] as $i) {
    $runs[] = workout($i, $now - (10 - $i) * $day, 30, null, ['km' => 5.0, 'kmh' => 10.0]);
}
$faster = workout(9, $now - $day, 27, null, ['km' => 5.0, 'kmh' => 11.0]);
$barely = workout(9, $now - $day, 30, null, ['km' => 5.0, 'kmh' => 10.05]);
$longer = workout(9, $now - $day, 60, null, ['km' => 10.0, 'kmh' => 10.0]);

check('a clearly faster 5 km after three 5 km runs is a record',
    points_workout_record($faster, array_merge($runs, [$faster])) !== null);
check('a rounding difference is not a record', points_workout_record($barely, array_merge($runs, [$barely])) === null);
check('a longest distance is a record', str_starts_with((string) points_workout_record($longer, array_merge($runs, [$longer])), 'Langste'));
check('with fewer than three earlier runs nothing is a record yet',
    points_workout_record($faster, [$runs[0], $runs[1], $faster]) === null);

echo str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
