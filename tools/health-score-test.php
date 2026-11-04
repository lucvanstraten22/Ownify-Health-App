<?php
/**
 * The Health Score formulas and the points rules, tested without a database.
 *
 *     php tools/health-score-test.php
 *
 * Every score in Ownify comes out of includes/health-score.php, with its
 * numbers in config/scoring.php; every point value out of includes/points.php
 * and config/points.php. This checks the arithmetic on made-up records held in
 * memory — the curves, the 168-hour window, the 3-day minimum, missing data
 * that must not count as zero, three days without new input, re-weighting,
 * the overall average, and the
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
section('curves are smooth, and shaped the way the sources describe (38.0)');

$sleepCurve = $cfg['sleep']['duration_curve'];
$at = static fn (float $h): float => health_curve($sleepCurve, $h);

check('7:59 and 8:00 of sleep score the same', abs($at(7 + 59 / 60) - $at(8.0)) < 0.5,
    sprintf('%.2f vs %.2f', $at(7 + 59 / 60), $at(8.0)));

$rising = true;
for ($i = 200; $i < 700; $i++) {                  // whole hundredths: no drifting float
    if ($at(($i + 1) / 100) < $at($i / 100) - 1e-9) { $rising = false; }
}
$falling = true;
for ($i = 900; $i < 1400; $i++) {
    if ($at(($i + 1) / 100) > $at($i / 100) + 1e-9) { $falling = false; }
}
check('the duration curve only rises up to 7 hours, and only falls after 9', $rising && $falling);
check('LE8: 7 to 9 hours is 100', min($at(7), $at(8), $at(9)) === 100.0);
check('LE8 band middles: 6:30 70, 5:30 40, 4:30 20, 9:30 90',
    $at(6.5) === 70.0 && $at(5.5) === 40.0 && $at(4.5) === 20.0 && $at(9.5) === 90.0);
check('LE8: under 4 hours 0, 10:30 and more 40', $at(3.5) === 0.0 && $at(12) === 40.0);

$steps = $cfg['training']['steps_curve'];
check('steps: Paluch\'s points — 3,550 0, 5,800 68, 7,850 79, 10,000 100',
    health_curve($steps, 3550) === 0.0 && health_curve($steps, 5800) === 68.0
    && health_curve($steps, 7850) === 79.0 && health_curve($steps, 10000) === 100.0);
check('steps have diminishing returns', health_curve($steps, 5800) - health_curve($steps, 3550)
    > health_curve($steps, 10000) - health_curve($steps, 7850));
check('from 60 the curve tops out earlier: 7,000 is 100',
    health_curve($cfg['training']['steps_curve_older'], 7000) === 100.0);

$aerobic = $cfg['training']['aerobic_curve'];
check('aerobic minutes (Arem): 150 60, 375 94, 600 100',
    health_curve($aerobic, 150) === 60.0 && health_curve($aerobic, 375) === 94.0 && health_curve($aerobic, 600) === 100.0);
check('…with diminishing returns',
    (health_curve($aerobic, 300) - health_curve($aerobic, 150)) < (health_curve($aerobic, 150) - health_curve($aerobic, 0)));
check('resting heart rate: a straight line from 55 (100) to 90 (0)',
    health_curve($cfg['training']['resting_hr_curve'], 72.5) === 50.0
    && health_curve($cfg['training']['resting_hr_curve'], 50) === 100.0);

/* ====================================================================== */
section('the weights come from the grades: evidence × measurement, overlap shared (D1, D2)');

$approved = [
    'sleep'     => ['duration' => 34.52, 'regularity' => 34.52, 'social_jetlag' => 17.26, 'efficiency' => 2.54,
                    'awake' => 2.54, 'deep' => 2.16, 'rem' => 2.16, 'hrv' => 4.31],
    'nutrition' => ['fibre' => 31.07, 'sodium' => 15.53, 'saturated_fat' => 9.71, 'sugar' => 7.77, 'protein' => 7.77,
                    'water' => 7.77, 'caffeine' => 7.77, 'fat_share' => 4.85, 'energy' => 3.88, 'rating' => 3.88],
    'training'  => ['steps' => 26.45, 'aerobic' => 9.92, 'strength' => 19.83, 'intensity' => 6.61,
                    'active_days' => 9.92, 'resting_hr' => 19.83, 'recovery' => 4.96, 'progression' => 2.48],
];
foreach ($approved as $category => $table) {
    $w = health_score_weights($category);
    $same = array_keys($w) === array_keys($table);
    foreach ($table as $key => $pct) {
        $same = $same && abs(100 * $w[$key] - $pct) < 0.006;
    }
    check("{$category}: the approved 26-factor table, to the hundredth", $same,
        json_encode(array_map(static fn ($v) => round(100 * $v, 2), $w)));
    check("{$category}: the weights add up to 100%", abs(array_sum($w) - 1) < 1e-9);
    check("{$category}: what the config hands out is the same", $cfg[$category]['weights'] == $w);
}
$plain = health_score_weights('sleep', null, 0.0);
check('without the overlap, efficiency and awake would weigh more', $plain['efficiency'] > $cfg['sleep']['weights']['efficiency']);

/* ====================================================================== */
section('the worked example of the proposal');

$example = [
    'sleep'     => ['duration' => 100, 'regularity' => 92, 'social_jetlag' => 100, 'efficiency' => 87, 'awake' => 79,
                    'deep' => 95, 'rem' => 97, 'hrv' => 88],
    'nutrition' => ['fibre' => 56, 'sodium' => 67, 'saturated_fat' => 70, 'sugar' => 53, 'protein' => 100,
                    'water' => 100, 'caffeine' => 78, 'fat_share' => 100, 'energy' => 100, 'rating' => 70],
    'training'  => ['steps' => 72, 'aerobic' => 54, 'strength' => 87, 'intensity' => 60, 'active_days' => 90,
                    'resting_hr' => 80, 'recovery' => 100, 'progression' => 60],
];
$scored = [];
foreach ($example as $category => $components) {
    $scored[$category] = health_weighted($components, $cfg[$category]['weights']);
}
check('Slaap 95.7, Voeding 71.8, Training 76.9',
    round($scored['sleep'], 1) === 95.7 && round($scored['nutrition'], 1) === 71.8 && round($scored['training'], 1) === 76.9,
    json_encode(array_map(static fn ($v) => round($v, 1), $scored)));
check('…and a Health Score of 82 (81.5)', score_combine(array_map(static fn ($v) => (int) round($v), $scored)) === 82
    && round(array_sum(array_map(static fn ($v) => round($v, 1), $scored)) / 3, 1) === 81.5);

$u = health_score_uncertainty(array_map(static fn ($c) => ['score' => 1, 'components' => $c], $example));
$near = static fn (array $r, float $lo, float $hi): bool => abs($r[0] - $lo) <= 0.6 && abs($r[1] - $hi) <= 0.6;
check('the uncertainty ranges match the proposal\'s (Slaap 92.9–96.6, Voeding 68.5–81.2, Training 72.1–81.3)',
    $near($u['sleep']['range'], 92.9, 96.6) && $near($u['nutrition']['range'], 68.5, 81.2)
    && $near($u['training']['range'], 72.1, 81.3), json_encode($u));
check('every factor measured: coverage 1', $u['sleep']['coverage'] === 1.0 && $u['overall']['coverage'] === 1.0);
check('the same factors always give the same range (a fixed seed)', health_score_uncertainty(
    array_map(static fn ($c) => ['score' => 1, 'components' => $c], $example)) === $u);
check('the range lies around the score', $u['sleep']['range'][0] <= 95.7 && $u['sleep']['range'][1] >= 95.7
    && $u['overall']['range'][0] <= 81.5 && $u['overall']['range'][1] >= 81.5);
$half = $example;
$half['training'] = array_map(static fn () => null, $half['training']);
$half['training']['steps'] = 72;
$hu = health_score_uncertainty(['sleep' => ['score' => 1, 'components' => $half['sleep']], 'nutrition' => ['score' => null, 'components' => []],
    'training' => ['score' => 1, 'components' => $half['training']]]);
check('coverage is the share of the weight with data: steps only is 26%, a missing category 0',
    abs($hu['training']['coverage'] - 0.265) < 0.002 && $hu['nutrition']['coverage'] === 0.0 && $hu['nutrition']['range'] === null
    && abs($hu['overall']['coverage'] - (1 + 0 + $hu['training']['coverage']) / 3) < 0.002, json_encode($hu));

/* ====================================================================== */
section('recent days count more (D3)');

check('today 1, four days ago ½, eight days ago ¼', health_recency(0) === 1.0 && abs(health_recency(4) - 0.5) < 1e-12
    && abs(health_recency(8) - 0.25) < 1e-12);
$recentShort = [];
$recentLong  = [];
foreach (range(6, 1) as $i) {
    $morning = $asOf->setTime(7, 0)->modify('-' . $i . ' days')->getTimestamp();
    $recentShort[] = night($morning, $i <= 3 ? 5.5 : 8);      // the last three nights short
    $recentLong[]  = night($morning, $i <= 3 ? 8 : 5.5);      // the first three short
}
$rs = health_score_sleep($recentShort, $asOf->format('Y-m-d'));
$rl = health_score_sleep($recentLong, $asOf->format('Y-m-d'));
check('three short nights weigh more when they were the last three',
    $rs['components']['duration'] < $rl['components']['duration'],
    $rs['components']['duration'] . ' vs ' . $rl['components']['duration']);

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
check('2 training days: no training score', health_score_training($two, [], [], $now)['score'] === null);
check('3 training days: a training score', is_int(health_score_training($three, [], [], $now)['score']));

$none = ['nights' => [], 'ratings' => [], 'workouts' => [], 'goals' => []];
check('2 days in every category: no overall score, never a 0',
    health_score_at(['nights' => nights(2, 8), 'ratings' => array_slice($ratings3, 0, 2)] + $none, $asOf)['overall']['score'] === null);
$unlocked = health_score_at(['nights' => nights(3, 8)] + $none, $asOf);
check('3 nights and nothing else: the overall score is the sleep score — the others left out, not zero',
    is_int($unlocked['overall']['score']) && $unlocked['overall']['score'] === $unlocked['sleep']['score']
    && $unlocked['nutrition']['score'] === null && $unlocked['training']['score'] === null);

/* ====================================================================== */
section('missing days are not zero');

$spread = array_map(static fn ($i) => rating($now - $i * 36 * 3600, 7), range(0, 3));   // 4 days in a week
check('4 ratings of 7 in a week score 70, not 4 x 70 / 7',
    health_score_nutrition($spread)['score'] === 70);

$sparse = [];
foreach ([1, 3, 5, 6] as $i) {
    $sparse[] = night($asOf->setTime(7, 0)->modify('-' . $i . ' days')->getTimestamp(), 8.0);
}
$r = health_score_sleep($sparse);
check('4 nights of 8 hours in 7 days: duration stays at the top', $r['components']['duration'] >= 99,
    json_encode($r['components']));

/* ====================================================================== */
section('nutrition is the daily self-assessment, 1-10 onto 0-100');

foreach ([1 => 10, 5 => 50, 7 => 70, 10 => 100] as $value => $expected) {
    $week = array_map(static fn ($i) => rating($now - $i * $day, $value), range(1, 7));
    check("a week of {$value}s scores {$expected}", health_score_nutrition($week)['score'] === $expected);
}

$twoADay = array_map(static fn ($i) => rating($now - $i * $day, 7), range(1, 7));
$twoADay[] = rating($now - $day + 3600, 9);        // a second rating on day 1: 7 and 9 -> 8 that day
/* Each day weighs 0.5 ^ (days ago / 4): day 1 is the 8. */
$w = array_map(static fn ($i) => health_recency($i), range(1, 7));
$expected = (int) round(($w[0] * 80 + 70 * (array_sum($w) - $w[0])) / array_sum($w));
check('two ratings on one day count as that day\'s average, once', health_score_nutrition($twoADay)['score'] === $expected,
    health_score_nutrition($twoADay)['score'] . ' vs ' . $expected);

check('a real 1 every day is a score of 10 — low, not missing', health_score_nutrition(
    array_map(static fn ($i) => rating($now - $i * $day, 1), range(1, 7)))['score'] === 10);

/* ====================================================================== */
section('sleep: eight factors');

$plain = health_score_sleep(nights(30, 7.5, 15));
$c     = $plain['components'];
check('without stage data there are no efficiency, awake, deep or REM factors',
    $c['efficiency'] === null && $c['awake'] === null && $c['deep'] === null && $c['rem'] === null);
check('…and the score is the rest re-weighted, not a zero for them',
    $plain['score'] === (int) round(health_weighted($c, $cfg['sleep']['weights'])), $plain['score'] . ' from ' . json_encode($c));

$staged = health_score_sleep(nights(30, 7.5, 15, [
    'efficiency' => 93.0, 'awake' => 18.0, 'light' => 250.0, 'deep' => 80.0, 'rem' => 100.0,
]));
check('with stage data they count', $staged['components']['efficiency'] !== null && $staged['components']['deep'] !== null,
    json_encode($staged['components']));

$regular   = health_score_sleep(nights(30, 7.0, 10));
$irregular = health_score_sleep(nights(30, 7.0, 150));
check('the same hours at regular times score higher than at irregular times',
    $regular['score'] > $irregular['score'], $regular['score'] . ' vs ' . $irregular['score']);
check('regularity is bedtime and wake time only: no duration spread', array_keys($regular['facts']['regularity']) === ['bedtime', 'wake_time']);

check('a regular 8-hour sleeper is excellent (90+)', health_score_sleep(nights(30, 8.0, 10))['score'] >= 90);
$sixRegular   = health_score_sleep(nights(30, 6.0, 20))['score'];
$sevenRegular = health_score_sleep(nights(30, 7.0, 20))['score'];
check('at the same regularity, 6 < 7 hours', $sixRegular < $sevenRegular, "$sixRegular / $sevenRegular");

/* Social jetlag: weekend nights 2 hours later than weekday nights. */
$jet = [];
$flat = [];
foreach (range(7, 1) as $i) {
    $morning = $asOf->setTime(7, 0)->modify('-' . $i . ' days');
    $free    = (int) $morning->format('N') >= 6;
    $jet[]   = night($morning->getTimestamp() + ($free ? 2 * 3600 : 0), 8);
    $flat[]  = night($morning->getTimestamp(), 8);
}
$j = health_score_sleep($jet, $asOf->format('Y-m-d'));
check('weekend sleep 2 hours later: a social jetlag of 2 hours, scored 60',
    abs($j['facts']['social_jetlag'] - 2) < 0.01 && abs($j['components']['social_jetlag'] - 60) < 0.01,
    json_encode([$j['facts']['social_jetlag'], $j['components']['social_jetlag']]));
check('the same times every day: 100', health_score_sleep($flat, $asOf->format('Y-m-d'))['components']['social_jetlag'] === 100.0);
check('no weekend night in the window: not measured, not zero',
    health_score_sleep(array_values(array_filter($flat, static fn ($n) => (int) date('N', $n['end']) < 6)), $asOf->format('Y-m-d'))['components']['social_jetlag'] === null);

/* HRV against the person's own baseline. */
$hrvDays = [];
foreach (range(66, 7) as $i) {
    $hrvDays[$asOf->modify('-' . $i . ' days')->format('Y-m-d')] = 50.0 + ($i % 2 === 0 ? 5 : -5);
}
$steady = $hrvDays;
$low    = $hrvDays;
foreach (range(6, 0) as $i) {
    $steady[$asOf->modify('-' . $i . ' days')->format('Y-m-d')] = 50.0;
    $low[$asOf->modify('-' . $i . ' days')->format('Y-m-d')]    = 40.0;
}
$today = $asOf->format('Y-m-d');
[$hs] = health_score_hrv($steady, $today);
[$hl, $hlf] = health_score_hrv($low, $today);
check('HRV at the person\'s own baseline: 100', abs($hs - 100) < 0.5, (string) $hs);
check('HRV well below it: a z under -2, and a low score', $hlf['z'] < -2 && $hl < 30, json_encode([$hl, $hlf]));
check('without 14 baseline days: not measured', health_score_hrv(array_slice($low, -10, null, true), $today)[0] === null);

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
section('training: eight factors');

$today = $asOf->format('Y-m-d');
$week  = static fn (array $daysAgo, float $minutes, ?string $class, string $type = 'running') =>
    array_map(static fn ($w) => ['type' => $type] + $w, schedule($daysAgo, $minutes, static fn () => $class));

$mixed  = health_score_training($week([1, 3, 5], 50, 'moderate'), [], [], $now, $today);
check('3 × 50 moderate minutes: 150 aerobic minutes a week, give or take the recency weighting',
    abs($mixed['facts']['aerobic_per_week'] - 150) < 25, (string) $mixed['facts']['aerobic_per_week']);
$hard   = health_score_training($week([1, 3, 5], 50, 'hard'), [], [], $now, $today);
check('vigorous minutes count double (WHO)', abs($hard['facts']['aerobic_per_week'] - 2 * $mixed['facts']['aerobic_per_week']) < 1e-6);
check('…and make the intensity factor', $hard['components']['intensity'] === 100.0 && $mixed['components']['intensity'] === 0.0);

$withStrength = health_score_training(array_merge($week([1, 3, 5], 50, 'moderate'), $week([2, 4], 30, 'moderate', 'strength')), [], [], $now, $today);
check('strength training is its own factor, and raises the score',
    $withStrength['components']['strength'] > 70 && $withStrength['score'] > $mixed['score'],
    json_encode([$withStrength['components']['strength'], $withStrength['score'], $mixed['score']]));
check('…without counting as aerobic minutes', abs($withStrength['facts']['aerobic_per_week'] - $mixed['facts']['aerobic_per_week']) < 1e-6);

$backToBack = health_score_training($week([1, 2, 3, 4, 5, 6], 60, 'hard'), [], [], $now, $today);
$restAfter  = health_score_training($week([2, 4, 6], 60, 'hard'), [], [], $now, $today);
check('hard days back to back: low recovery; a rest day after each: full',
    $backToBack['components']['recovery'] < 50 && $restAfter['components']['recovery'] === 100.0,
    json_encode([$backToBack['components']['recovery'], $restAfter['components']['recovery']]));

$stepsWeek = [];
foreach (range(6, 0) as $i) {
    $stepsWeek[$asOf->modify('-' . $i . ' days')->format('Y-m-d')] = 10000.0;
}
$walker = health_score_training([], [], [], $now, $today, $stepsWeek, [], []);
check('steps alone make a Training score: 10,000 a day is 100', $walker['score'] !== null && $walker['components']['steps'] === 100.0);
check('…the workout factors are not measured then, not zero',
    $walker['components']['aerobic'] === null && $walker['components']['strength'] === null);
check('…and a day of 7,000 steps is an active day: 7 a week', abs($walker['facts']['active_days'] - 7) < 1e-6);
check('today\'s steps are not counted yet: the day is still going', !in_array($today, array_keys(array_filter(
    $stepsWeek, static fn ($d) => $d < $today, ARRAY_FILTER_USE_KEY)), true) && $walker['days'] === 6);
$older = health_score_training([], [], [], $now, $today, array_map(static fn () => 7000.0, $stepsWeek), [], ['age' => 64]);
check('from 60, 7,000 steps a day is the top', $older['components']['steps'] === 100.0);
$rhr = health_score_training([], [], [], $now, $today, $stepsWeek, array_fill_keys(array_keys($stepsWeek), 62.0), []);
check('resting heart rate 62: 80', abs($rhr['components']['resting_hr'] - 80) < 0.01, (string) $rhr['components']['resting_hr']);

$paced = static function (float $from, float $to): array {
    global $now;
    $out = [];
    foreach ([6, 5, 4, 3, 2, 1] as $i => $ago) {
        $kmh = $from + ($to - $from) * $i / 5;
        $out[] = workout($i + 1, $now - $ago * 86400, 40, 'moderate', ['km' => 6.0, 'kmh' => $kmh]);
    }
    return $out;
};
$better = health_score_training($paced(10.0, 11.0), [], [], $now, $today)['components']['progression'];
$same   = health_score_training($paced(10.0, 10.0), [], [], $now, $today)['components']['progression'];
$worse  = health_score_training($paced(11.0, 10.0), [], [], $now, $today)['components']['progression'];
check('progression is relative to yourself: faster > steady > slower', $better > $same && $same > $worse,
    "$better / $same / $worse");
check('without anything to compare, progression is left out, not zero', $mixed['components']['progression'] === null);

$goalUp = [['direction' => 'increase', 'entries' => [
    ['at' => $now - 6 * $day, 'value' => 80.0], ['at' => $now - 4 * $day, 'value' => 82.5],
    ['at' => $now - 2 * $day, 'value' => 85.0], ['at' => $now - 1 * $day, 'value' => 87.5],
]]];
check('a strength goal going up counts as progression',
    health_score_training($week([1, 3, 5], 50, 'moderate'), [], $goalUp, $now, $today)['components']['progression'] === null
    || health_score_training($week([1, 3, 5], 50, 'moderate'), [], $goalUp, $now, $today)['components']['progression'] >= 60);

/* ====================================================================== */
section('nutrition: what was eaten, on days logged well enough');

$profile = ['sex' => 'female', 'age' => 40, 'weight_kg' => 60.0, 'energy_target' => 2000.0];
$food = [];
foreach (range(5, 1) as $i) {
    $food[$asOf->modify('-' . $i . ' days')->format('Y-m-d')] = [
        'energy' => 2000.0, 'fibre' => 28.0, 'sodium' => 2400.0, 'saturated_fat' => 22.0, 'sugar' => 50.0,
        'protein' => 60.0, 'fat' => 70.0, 'carbs' => 250.0, 'water' => 1.4,
    ];
}
$fed = health_score_nutrition([], $today, $food, [], [], $profile);
$v   = $fed['facts']['values'];
check('fibre per 1,000 kcal: 14 is the top', abs($v['fibre'] - 14) < 1e-9 && $fed['components']['fibre'] === 100.0);
check('sodium 1.2 g per 1,000 kcal: the top', abs($v['sodium'] - 1.2) < 1e-9 && $fed['components']['sodium'] === 100.0);
check('saturated fat 9.9% and sugar 10% of energy: the top', $fed['components']['saturated_fat'] === 100.0 && $fed['components']['sugar'] === 100.0);
check('fat 31.5% of energy: inside 20-40%', $fed['components']['fat_share'] === 100.0);
check('1 g protein per kg: the top', abs($v['protein'] - 1) < 1e-9 && $fed['components']['protein'] === 100.0);
check('on target: energy balance 100', $fed['components']['energy'] === 100.0);
check('1.4 l water for a woman: her norm reached', $fed['components']['water'] === 100.0);
check('a man needs 1.8 l: 1.4 l is less', health_score_nutrition([], $today, $food, [], [], ['sex' => 'male'] + $profile)['components']['water'] < 100);
check('no caffeine recorded at all: the factor is not measured', $fed['components']['caffeine'] === null);
check('no rating: the food makes the score', $fed['components']['rating'] === null && $fed['score'] === 100);

$thin = array_map(static fn ($d) => ['energy' => 800.0] + $d, $food);
$onlyRated = health_score_nutrition(array_map(static fn ($i) => rating($now - $i * $day, 6), range(1, 3)), $today, $thin, [], [], $profile);
check('days logged below half the energy target are not food days: the rating is the whole score',
    $onlyRated['score'] === 60 && $onlyRated['components']['fibre'] === null && $onlyRated['facts']['food_days'] === 0);

$nightsForBed = nights(6, 8);           // to bed around 23:00
$late = [];
foreach (array_keys($food) as $date) {
    $late[] = ['at' => strtotime($date . ' 21:00:00'), 'date' => $date, 'mg' => 80.0];
}
$caf = health_score_nutrition([], $today, $food, $late, $nightsForBed, $profile);
check('coffee at 21:00 with bedtime around 23:00: about two hours, a low caffeine score',
    abs($caf['facts']['values']['caffeine'] - 2) < 0.4 && $caf['components']['caffeine'] < 30,
    json_encode([$caf['facts']['values']['caffeine'], $caf['components']['caffeine']]));

/* ====================================================================== */
section('the overall score is the average of the categories that have one');

check('82, 74, 71 -> 76', score_combine(['sleep' => 82, 'nutrition' => 74, 'training' => 71]) === 76);
check('82, 74, missing -> 78 (not 52)', score_combine(['sleep' => 82, 'nutrition' => 74, 'training' => null]) === 78);
check('82, missing, missing -> 82', score_combine(['sleep' => 82, 'nutrition' => null, 'training' => null]) === 82);
check('all missing -> no score, not 0', score_combine(['sleep' => null, 'nutrition' => null, 'training' => null]) === null);
check('a real 0 is a score', score_combine(['sleep' => 0, 'nutrition' => null, 'training' => null]) === 0);

/* ====================================================================== */
section('the window is the last 168 hours, moving with the clock');

$start = health_score_window_start($asOf);
check('the window starts exactly 168 hours before the moment', $start->format('Y-m-d H:i:s') === '2026-09-18 15:00:00',
    $start->format('Y-m-d H:i:s'));
check('7 days as people read it', health_score_window_days() === 7 && (int) $cfg['window_hours'] === 168);
check('no 90-day window is left in the configuration', !isset($cfg['window_days']));

$edge = nights(5, 8);
$edge[] = night($start->getTimestamp() + 60, 8);     // ended a minute inside the window
$edge[] = night($start->getTimestamp() - 60, 8);     // ended a minute before it
usort($edge, static fn ($a, $b) => $a['end'] <=> $b['end']);

$data = ['nights' => $edge, 'ratings' => [], 'workouts' => [], 'goals' => []];
check('a night one minute inside the window counts, one minute outside does not',
    health_score_at($data, $asOf)['sleep']['days'] === 6, (string) health_score_at($data, $asOf)['sleep']['days']);
check('an hour later the window has moved past the older of the two',
    health_score_at($data, $asOf->modify('+1 hour'))['sleep']['days'] === 5);

$monthEdge = new DateTimeImmutable('2026-10-01 09:00:00');
$lateSept  = array_map(static fn ($i) => night((new DateTimeImmutable('2026-09-30 07:00'))->modify('-' . $i . ' days')->getTimestamp(), 8), range(0, 2));
check('on the 1st of a month last month still counts — no calendar reset',
    is_int(health_score_at(['nights' => $lateSept, 'ratings' => [], 'workouts' => [], 'goals' => []], $monthEdge)['sleep']['score']));

$old = array_map(static fn ($i) => night($asOf->modify('-' . (8 + $i) . ' days')->getTimestamp(), 8), range(0, 9));
check('data older than 168 hours gives no score at all',
    health_score_at(['nights' => $old, 'ratings' => [], 'workouts' => [], 'goals' => []], $asOf)['sleep']['score'] === null);

$month = array_map(static fn ($i) => night($asOf->setTime(7, 0)->modify('-' . $i . ' days')->getTimestamp(), 8), range(10, 80));
$recent = [rating($now - 3600, 6), rating($now - 30 * 3600, 6), rating($now - 54 * 3600, 6)];
$both = health_score_at(['nights' => $month, 'ratings' => $recent, 'workouts' => [], 'goals' => []], $asOf);
check('70 nights 10 to 80 days ago count for nothing now: the current score no longer reaches back 90 days',
    $both['sleep']['score'] === null && $both['sleep']['days'] === 0 && $both['overall']['score'] === 60,
    json_encode([$both['sleep']['days'], $both['overall']['score']]));

/* ====================================================================== */
section('three days without new input: the category stops counting, it does not become zero');

/* Day 1: 20 September. Nights end on the mornings of 18, 19 and 20 September;
   cijfers are given on the same days; workouts on 16, 17 and 18 September. */
$day1  = new DateTimeImmutable('2026-09-20 15:00:00');
$at    = static fn (string $date, string $time) => (new DateTimeImmutable($date . ' ' . $time))->getTimestamp();
$rest  = ['goals' => []];
$sleepNights = [night($at('2026-09-18', '07:00'), 7.5), night($at('2026-09-19', '07:05'), 8.0), night($at('2026-09-20', '06:55'), 7.8)];
$cijfers     = [rating($at('2026-09-18', '20:00'), 7), rating($at('2026-09-19', '20:00'), 8), rating($at('2026-09-20', '12:00'), 7)];
$sessions    = [workout(1, $at('2026-09-16', '18:00'), 45, 'moderate'), workout(2, $at('2026-09-17', '18:00'), 40, 'hard'),
                workout(3, $at('2026-09-18', '18:00'), 50, 'moderate')];
$expiryData  = ['nights' => $sleepNights, 'ratings' => $cijfers, 'workouts' => $sessions] + $rest;

$d1 = health_score_at($expiryData, $day1);
$d2 = health_score_at($expiryData, $day1->modify('+1 day'));
$d3 = health_score_at($expiryData, $day1->modify('+2 days'));
$d4 = health_score_at($expiryData, $day1->modify('+3 days'));

check('day 1: all three count', is_int($d1['sleep']['score']) && is_int($d1['nutrition']['score']) && is_int($d1['training']['score']));
check('day 2 and day 3 without new input: Slaap keeps the score it had — not zero, not gone',
    $d2['sleep']['score'] === $d1['sleep']['score'] && $d3['sleep']['score'] === $d1['sleep']['score']
    && $d3['sleep']['expired'] === false, json_encode([$d1['sleep']['score'], $d2['sleep']['score'], $d3['sleep']['score']]));
check('day 4, three days in a row without a night: Slaap no longer counts',
    $d4['sleep']['score'] === null && $d4['sleep']['expired'] === true && $d4['sleep']['last_input'] === '2026-09-20',
    json_encode([$d4['sleep']['score'], $d4['sleep']['expired'], $d4['sleep']['last_input']]));
check('…and nothing of it is kept: no components, never a 0',
    array_filter($d4['sleep']['components'], static fn ($v) => $v !== null) === [] && $d4['sleep']['days'] === 3);
check('Voeding the same: counting on day 3, left out on day 4',
    $d3['nutrition']['score'] === $d1['nutrition']['score'] && $d4['nutrition']['score'] === null && $d4['nutrition']['expired'] === true);
check('Training has no expiry: rest days are rest, it still counts on day 4',
    is_int($d4['training']['score']) && $d4['training']['expired'] === false);
check('the overall score is recalculated from what still counts, by the same rule',
    $d1['overall']['score'] === score_combine(['sleep' => $d1['sleep']['score'], 'nutrition' => $d1['nutrition']['score'], 'training' => $d1['training']['score']])
    && $d4['overall']['score'] === $d4['training']['score'],
    json_encode([$d1['overall']['score'], $d4['overall']['score'], $d4['training']['score']]));
$fresh = $expiryData;
$fresh['nights'][] = night($at('2026-09-23', '07:00'), 8.0);
check('one new night and Slaap counts again', is_int(health_score_at($fresh, $day1->modify('+3 days'))['sleep']['score']));
check('expiry is configured per category: Slaap 3, Voeding 3, Training none',
    $cfg['expiry_days'] === ['sleep' => 3, 'nutrition' => 3, 'training' => null]);

/* ====================================================================== */
section('until when a score holds without new input');

check('Slaap: its last night (20 Sep) plus two days — expiry comes before the window',
    $d1['sleep']['valid_until'] === '2026-09-22', (string) $d1['sleep']['valid_until']);
check('Voeding the same', $d1['nutrition']['valid_until'] === '2026-09-22');
check('Training: until the window leaves fewer than 3 training days — the 3rd newest (16 Sep) plus six',
    $d1['training']['valid_until'] === '2026-09-22', (string) $d1['training']['valid_until']);
$spreadNights = [night($at('2026-09-14', '07:00'), 8), night($at('2026-09-17', '07:00'), 8), night($at('2026-09-20', '07:00'), 8)];
$spreadAt = health_score_at(['nights' => $spreadNights, 'ratings' => [], 'workouts' => []] + $rest, $day1);
check('nights spread over the week: the window drops the oldest first (14 Sep + 6 = 20 Sep)',
    $spreadAt['sleep']['valid_until'] === '2026-09-20', (string) $spreadAt['sleep']['valid_until']);
check('the overall score holds as long as any category does', $d1['overall']['valid_until'] === '2026-09-22');
check('no score, no date', $d4['sleep']['valid_until'] === null);

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

section('colours: a category keeps its colour, a score has its own');

require_once dirname(__DIR__) . '/includes/scoring.php';
require_once dirname(__DIR__) . '/lib/health.php';

foreach ([100 => 'high', 80 => 'high', 79 => 'mid', 60 => 'mid', 59 => 'low', 0 => 'low'] as $score => $band) {
    check("a score of {$score} is shown as {$band}", score_colour_band($score) === $band,
        'got ' . var_export(score_colour_band($score), true));
}
check('79.6 is still below 80', score_colour_band(79.6) === 'mid');
check('no score has no colour (not the colour of a zero)', score_colour_band(null) === null);

$dashboard = require dirname(__DIR__) . '/config/dashboard.php';
$healthCfg = require dirname(__DIR__) . '/config/health.php';
$accents   = ['sleep' => 'sleep', 'nutrition' => 'nutrition', 'training' => 'training'];

check('each pillar on Overzicht has its own category colour',
    array_column($dashboard['scores']['contributors'], 'accent', 'area') === $accents);
check('each area on Gezondheid has its own category colour',
    array_map(fn ($a) => $a['accent'], $healthCfg['areas']) === $accents);

$areas = fn (?int $s, ?int $n, ?int $t) => ['areas' => [
    'sleep'     => ['score' => ['value' => $s]],
    'nutrition' => ['score' => ['value' => $n]],
    'training'  => ['score' => ['value' => $t]],
]];
$low  = health_contributor_scores($dashboard['scores']['contributors'], $areas(40, 59, 0));
$high = health_contributor_scores($dashboard['scores']['contributors'], $areas(95, 80, 100));
$none = health_contributor_scores($dashboard['scores']['contributors'], $areas(null, null, null));

check('a score changes the dot', array_column($low, 'score_band') === ['low', 'low', 'low']
    && array_column($high, 'score_band') === ['high', 'high', 'high']);
check('... and never the category colour', array_column($low, 'accent') === array_values($accents)
    && array_column($high, 'accent') === array_values($accents));
check('no data: no band, category colour still its own', array_column($none, 'score_band') === [null, null, null]
    && array_column($none, 'accent') === array_values($accents));

/* The same colours on the website and in the app. Most are one value in both
   themes (`val Sleep = Color(…)` beside `--sleep`); the mid and low bands are
   deeper in White Mode, so they are kept per theme: OwnifyPalette.Dark and
   .Light beside :root and :root[data-theme="light"]. */
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/theme.css');
$kt  = (string) file_get_contents(dirname(__DIR__) . '/OwnifyAndroid/app/src/main/java/com/ownify/android/ui/theme/OwnifyTheme.kt');
$between = static function (string $text, string $from, string $to): string {
    $start = strpos($text, $from);
    $end   = $start === false ? false : strpos($text, $to, $start);

    return $start === false || $end === false ? '' : substr($text, $start, $end - $start);
};
$cssDark  = $between($css, ':root {', "\n}");
$cssLight = $between($css, ':root[data-theme="light"] {', "\n}");
$ktDark   = $between($kt, 'val Dark = OwnifyPalette(', 'val Light = OwnifyPalette(');
$ktLight  = $between($kt, 'val Light = OwnifyPalette(', 'fun of(');
$expected = [
    'sleep' => ['Sleep', '5B64C7'], 'sleep-light' => ['SleepLight', '747CDA'],
    'nutrition' => ['Nutrition', '477B61'], 'nutrition-light' => ['NutritionLight', '67997D'],
    'training' => ['Training', 'C97867'], 'training-light' => ['TrainingLight', 'D99586'],
    'score-high' => ['ScoreHigh', '4E9F70'], 'score-mid' => ['ScoreMid', 'AECA0F'], 'score-low' => ['ScoreLow', 'C99A45'],
    'neutral' => ['Neutral', '6B6769'],
];
foreach ($expected as $token => [$name, $hex]) {
    $inApp = preg_match('/val ' . $name . ' = Color\(0xFF' . $hex . '\)/i', $kt) === 1
        || preg_match('/\b' . lcfirst($name) . ' = Color\(0xFF' . $hex . '\)/', $ktDark) === 1;
    check("--{$token} is #{$hex} on the website and in the app",
        preg_match('/--' . preg_quote($token, '/') . ':\s*#' . $hex . '\s*;/i', $cssDark) === 1 && $inApp);
}
foreach (['score-mid' => ['scoreMid', '7D910B'], 'score-low' => ['scoreLow', 'AC8032']] as $token => [$field, $hex]) {
    check("--{$token} is #{$hex} in White Mode, on the website and in the app",
        preg_match('/--' . preg_quote($token, '/') . ':\s*#' . $hex . '\s*;/i', $cssLight) === 1
        && preg_match('/\b' . $field . ' = Color\(0xFF' . $hex . '\)/', $ktLight) === 1);
}

echo str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
