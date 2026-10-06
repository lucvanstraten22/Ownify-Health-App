<?php
/**
 * The first days, tested without a database.
 *
 *     php tools/first-days-test.php
 *
 * includes/setup.php says how far the baseline is, reveals the first score
 * and arrives at the starting point — and must never score anything itself.
 * This walks made-up records through the engine's own functions
 * (health_score_at(), the Scorekompas's composition) day by day, exactly as
 * lib/hydrate-setup.php does with the database's, and checks which card
 * each day gets: building on days 1 to 3, the first score on the day it
 * first exists and the day after, the starting point to the end of day 5,
 * nothing after; too little data said, never filled in; the focus's order;
 * one fact a day and no advice; and the first goal it may suggest.
 *
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Amsterdam');

require_once dirname(__DIR__) . '/includes/setup.php';
require_once dirname(__DIR__) . '/includes/score-compass.php';

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

$copy      = require dirname(__DIR__) . '/config/setup.php';
$compass   = require dirname(__DIR__) . '/config/compass.php';
$cal       = $copy['calibration'];
$suggest   = $copy['setup']['steps']['goal']['suggestion'];
$areas     = [
    'sleep'     => ['label' => 'Slaap',   'accent' => 'sleep',     'icon' => 'moon',     'empty' => '', 'collecting' => ''],
    'nutrition' => ['label' => 'Voeding', 'accent' => 'nutrition', 'icon' => 'utensils', 'empty' => '', 'collecting' => ''],
    'training'  => ['label' => 'Sport',   'accent' => 'training',  'icon' => 'dumbbell', 'empty' => '', 'collecting' => ''],
];

/* The setup was finished on 1 October 2026, day 1. */
const SETUP_DAY = '2026-10-01';

/** A night that ends on $date's morning: to bed the evening before at $bed. */
function night(string $date, string $bed, int $minutes): array
{
    $day   = new DateTimeImmutable($date);
    [$h, $m] = array_map('intval', explode(':', $bed));
    $start = ($h >= 12 ? $day->modify('-1 day') : $day)->setTime($h, $m);

    return [
        'session_id' => crc32($date . $bed), 'date' => $date,
        'start' => $start->getTimestamp(), 'end' => $start->getTimestamp() + $minutes * 60,
        'minutes' => (float) $minutes, 'in_bed' => null, 'efficiency' => null, 'awakenings' => null,
        'awake' => null, 'light' => null, 'deep' => null, 'rem' => null,
    ];
}

function rating(string $date, float $value): array
{
    return ['at' => (new DateTimeImmutable($date . ' 20:00'))->getTimestamp(), 'date' => $date, 'value' => $value];
}

function workout(string $date, int $minutes, string $time = '18:00'): array
{
    $start = (new DateTimeImmutable($date . ' ' . $time))->getTimestamp();

    return [
        'id' => crc32($date . $time), 'start' => $start, 'end' => $start + $minutes * 60, 'minutes' => (float) $minutes,
        'type' => 'running', 'km' => null, 'kmh' => null, 'max_hr' => null, 'avg_hr' => null, 'zones' => [],
        'effort' => ['class' => null, 'rpe' => null, 'zone_share' => null, 'hr_pct' => null], 'heavy' => false,
    ];
}

/**
 * The card on $day (1-based) at 21:00, from $records, as
 * hydrate_calibration() builds it: the same engine calls, minus the reads.
 */
function card(int $day, array $records, string $focus = 'general', array $extra = []): ?array
{
    global $cal, $compass, $areas;

    $start = new DateTimeImmutable(SETUP_DAY);
    $now   = $start->modify('+' . ($day - 1) . ' days')->setTime(21, 0);
    $read  = ($records + ['nights' => [], 'ratings' => [], 'workouts' => []]) + ['vo2' => [], 'goals' => []];

    /* Only what had happened by then: a test walks forward in time. */
    $read['nights']   = array_values(array_filter($read['nights'], static fn ($n) => $n['end'] <= $now->getTimestamp()));
    $read['ratings']  = array_values(array_filter($read['ratings'], static fn ($r) => $r['at'] <= $now->getTimestamp()));
    $read['workouts'] = array_values(array_filter($read['workouts'], static fn ($w) => $w['start'] <= $now->getTimestamp()));

    $results = health_score_at($read, $now);
    $order   = setup_focus_order($focus);
    $legend  = [];
    foreach ($order as $id) {
        $legend[$id] = $areas[$id];
    }

    $composition = [];
    foreach (score_compass_composition($results, $compass, $legend, health_scoring_config())['categories'] as $row) {
        $composition[$row['id']] = $row;
    }

    return calibration_build([
        'day'         => $day,
        'focus'       => $focus,
        'min_days'    => (int) health_scoring_config()['min_days'],
        'today'       => $now->format('Y-m-d'),
        'first_day'   => calibration_first_day($read, $start, $day, $now),
        'results'     => $results,
        'records'     => calibration_window_records($read, $now),
        'weight'      => $extra['weight'] ?? null,
        'connected'   => $extra['connected'] ?? false,
        'areas'       => $legend,
        'composition' => $composition,
    ], $cal);
}

/** Every sentence a card can show, for the tone checks. */
function sentences(?array $card): array
{
    if ($card === null) {
        return [];
    }

    $out = [$card['title'] ?? '', $card['lede'] ?? '', $card['observation'] ?? '', $card['note'] ?? '', $card['eyebrow'] ?? ''];
    foreach ($card['progress'] as $row) {
        $out[] = $row['count'];
        $out[] = (string) $row['detail'];
        $out[] = (string) $row['how'];
    }
    foreach ($card['baseline'] as $row) {
        $out[] = (string) $row['fact'];
    }

    return array_values(array_filter($out, static fn ($s) => $s !== ''));
}

/* A person who connects Health Connect on day 1 and sleeps every night:
   the nights of 1→2, 2→3 and 3→4 October. */
$nights = [
    night('2026-10-02', '23:40', 412),
    night('2026-10-03', '23:10', 441),
    night('2026-10-04', '00:05', 389),
    night('2026-10-05', '23:30', 430),
    night('2026-10-06', '23:20', 420),
];

/* --------------------------------------------------------------------- */
section('nothing at all: days 1 to 3 build, 4 and 5 say there is no starting point, 6 has no card');

$c1 = card(1, []);
check('day 1: building', ($c1['phase'] ?? null) === 'building', json_encode($c1['phase'] ?? null));
check('day 1: "Dag 1 van 3"', ($c1['eyebrow'] ?? null) === 'Dag 1 van 3');
check('day 1: the title says the baseline is being built', ($c1['title'] ?? null) === 'Je basislijn wordt opgebouwd');
check('day 1: three progress rows at 0 van 3', count($c1['progress']) === 3
    && array_sum(array_column($c1['progress'], 'days')) === 0
    && $c1['progress'][0]['count'] === '0 van 3 nachten');
check('day 1: no observation without data', $c1['observation'] === null);
check('day 1: nothing scored, nothing revealed', $c1['first'] === null && $c1['baseline'] === []);
check('day 1: where data comes from, per category', $c1['progress'][0]['how'] === $cal['progress']['how']['source']
    && $c1['progress'][1]['how'] === $cal['progress']['how']['nutrition']);
check('day 1 connected: sleep comes in through the link', card(1, [], 'general', ['connected' => true])['progress'][0]['how'] === $cal['progress']['how']['connected']);

$c3 = card(3, []);
check('day 3, no data: still building, and says there is too little', $c3['phase'] === 'building'
    && $c3['lede'] === $cal['building']['lede'][3] && $c3['eyebrow'] === 'Dag 3 van 3');

$c4 = card(4, []);
check('day 4, no data at all: no starting point yet', $c4['phase'] === 'baseline'
    && $c4['title'] === $cal['baseline']['empty_title'] && $c4['eyebrow'] === $cal['eyebrow']
    && count($c4['progress']) === 3 && $c4['baseline'] === [] && $c4['open'] === null);
check('day 5, no data: the same', card(5, [])['title'] === $cal['baseline']['empty_title']);
check('day 6: no card', card(6, []) === null);
check('day 0 (before the setup): no card', card(0, []) === null);

/* --------------------------------------------------------------------- */
section('one night a morning: built up, then the first score, then the starting point');

$s1 = card(1, ['nights' => $nights]);
check('day 1 evening: 0 nights yet', $s1['phase'] === 'building' && $s1['progress'][0]['days'] === 0);

$s2 = card(2, ['nights' => $nights]);
check('day 2: 1 van 3 nachten', $s2['progress'][0]['count'] === '1 van 3 nachten', $s2['progress'][0]['count']);
check('day 2: the night itself, as detail', $s2['progress'][0]['detail'] === '6:52 geslapen', (string) $s2['progress'][0]['detail']);
check('day 2: one fact — the last night, with its times', $s2['observation'] === 'Je laatste nacht: 6:52 geslapen, van 23:40 tot 06:32.', (string) $s2['observation']);
check('day 2: lede of day 2', $s2['lede'] === $cal['building']['lede'][2]);

$s3 = card(3, ['nights' => $nights]);
check('day 3: 2 van 3 nachten, averaged', $s3['progress'][0]['count'] === '2 van 3 nachten'
    && $s3['progress'][0]['detail'] === 'Gemiddeld 7:07 per nacht', (string) $s3['progress'][0]['detail']);
check('day 3: the fact is the average bedtime, round midnight right', $s3['observation'] === 'Je ging de afgelopen 2 nachten gemiddeld om 23:25 naar bed.', (string) $s3['observation']);
check('day 3: not enough yet — said, not scored', $s3['phase'] === 'building' && $s3['first'] === null);

$s4 = card(4, ['nights' => $nights]);
check('day 4: the first score appears', $s4['phase'] === 'first_score' && $s4['title'] === 'Je eerste slaapscore', json_encode([$s4['phase'], $s4['title']]));
$engine = health_score_at(['nights' => array_slice($nights, 0, 3), 'ratings' => [], 'workouts' => [], 'vo2' => [], 'goals' => []],
    (new DateTimeImmutable(SETUP_DAY))->modify('+3 days')->setTime(21, 0));
check('day 4: the score is the engine\'s own, unchanged', $s4['first']['value'] === $engine['sleep']['score'] && $engine['sleep']['score'] !== null,
    json_encode([$s4['first']['value'], $engine['sleep']['score']]));
check('day 4: with its real components and weights', count($s4['first']['parts']) === 3
    && array_column($s4['first']['parts'], 'id') === ['duration', 'regularity', 'quality']
    && $s4['first']['parts'][0]['value'] !== null);
check('day 4: the overall score rests on sleep alone — and says so', $s4['note'] === 'Je gezondheidsscore rust voorlopig alleen op slaap. Voeding en sport tellen mee zodra er 3 dagen van zijn.', (string) $s4['note']);
check('day 4: the way to the Scorekompas', $s4['open'] === $cal['first']['open']);
check('day 4: "Je basislijn", no longer "Dag 4 van 3"', $s4['eyebrow'] === $cal['eyebrow']);

$s5 = card(5, ['nights' => $nights]);
check('day 5 (the day after): still the first score', $s5['phase'] === 'first_score');
check('day 6: no card', card(6, ['nights' => $nights]) === null);

/* --------------------------------------------------------------------- */
section('history imported on day 1: the score is there at once, and never held back');

$history = [
    night('2026-09-27', '23:30', 420), night('2026-09-28', '23:45', 400),
    night('2026-09-29', '23:15', 445), night('2026-09-30', '23:50', 395),
];
$h1 = card(1, ['nights' => $history]);
check('day 1: the first score, on day 1', $h1['phase'] === 'first_score' && $h1['eyebrow'] === 'Dag 1 van 3');
check('day 2: still the first score', card(2, ['nights' => $history])['phase'] === 'first_score');
/* The nights after it come in through the link, as they do. */
$synced = array_merge($history, [night('2026-10-02', '23:20', 410), night('2026-10-03', '23:35', 425)]);
$h3 = card(3, ['nights' => $synced]);
check('day 3: the starting point', $h3['phase'] === 'baseline' && $h3['title'] === $cal['baseline']['title']
    && $h3['lede'] === $cal['baseline']['lede']);
check('day 3: only the category with enough data', count($h3['baseline']) === 1 && $h3['baseline'][0]['id'] === 'sleep'
    && $h3['baseline'][0]['value'] === health_score_at(['nights' => $synced, 'ratings' => [], 'workouts' => [], 'vo2' => [], 'goals' => []],
        (new DateTimeImmutable(SETUP_DAY))->modify('+2 days')->setTime(21, 0))['sleep']['score']);
check('day 3: its fact, from the engine\'s own facts', $h3['baseline'][0]['fact'] === 'Gemiddeld 6:56 per nacht', (string) $h3['baseline'][0]['fact']);
check('day 3: what is still missing', $h3['note'] === 'Voeding en sport komen erbij zodra er 3 dagen van zijn.', (string) $h3['note']);
check('day 5: still the starting point', card(5, ['nights' => $synced])['phase'] === 'baseline');
check('day 6: no card', card(6, ['nights' => $synced]) === null);

/* --------------------------------------------------------------------- */
section('history imported, then nothing new: three days on, it no longer counts');

$stale = card(3, ['nights' => $history]);
check('day 3, last night 30 September: no score, and not "too little data"', $stale['phase'] === 'building'
    && $stale['title'] === $cal['expired']['title'] && $stale['lede'] === $cal['expired']['lede']
    && $stale['first'] === null && $stale['baseline'] === [], json_encode([$stale['title'], $stale['lede']], JSON_UNESCAPED_UNICODE));
check('  the sleep row says since when, and that it does not count', $stale['progress'][0]['detail'] === 'Laatste gegevens op 30 september, telt nu niet mee',
    (string) $stale['progress'][0]['detail']);
check('  a category that never had enough still says where its data comes from',
    $stale['progress'][1]['detail'] === null && $stale['progress'][1]['how'] === $cal['progress']['how']['nutrition']);
$lapsed = card(4, ['nights' => $history, 'workouts' => [workout('2026-09-28', 30), workout('2026-09-30', 50), workout('2026-10-01', 40)]], 'fitness');
check('a starting point without the lapsed category, and what brings it back',
    $lapsed['phase'] === 'baseline' && array_column($lapsed['baseline'], 'id') === ['training']
    && $lapsed['note'] === 'Voeding komt erbij zodra er 3 dagen van zijn. Slaap telt weer mee zodra er nieuwe gegevens zijn.',
    (string) $lapsed['note']);

/* --------------------------------------------------------------------- */
section('a daily cijfer, by hand');

$ratings = [rating('2026-10-01', 7), rating('2026-10-02', 8), rating('2026-10-03', 6)];
$r1 = card(1, ['ratings' => $ratings]);
check('day 1: 1 van 3 dagen met een cijfer', $r1['progress'][1]['count'] === '1 van 3 dagen met een cijfer' && $r1['progress'][1]['detail'] === 'Een 7');
check('day 1: the fact names the day', $r1['observation'] === 'Je gaf je voeding vandaag een 7.', (string) $r1['observation']);
$r2 = card(2, ['ratings' => $ratings]);
check('day 2: the range of the cijfers', $r2['observation'] === 'Je dagcijfers voor voeding lagen tussen 7 en 8.', (string) $r2['observation']);
check('day 2: averaged', $r2['progress'][1]['detail'] === 'Gemiddeld een 7,5');
$r3 = card(3, ['ratings' => $ratings]);
check('day 3: the first voedingsscore — on day 3', $r3['phase'] === 'first_score' && $r3['title'] === 'Je eerste voedingsscore');
check('day 3: a one-part category has its sentence, not a list', $r3['first']['parts'] === [] && $r3['first']['summary'] !== null);
$same = card(2, ['ratings' => [rating('2026-10-01', 7), rating('2026-10-02', 7)]]);
check('two equal cijfers: said once', $same['observation'] === 'Je gaf je voeding 2 dagen een 7.', (string) $same['observation']);

/* --------------------------------------------------------------------- */
section('training, and two categories at once');

$workouts = [workout('2026-09-24', 45), workout('2026-09-27', 30), workout('2026-09-30', 50), workout('2026-10-01', 40)];
$both = card(1, ['nights' => $history, 'workouts' => $workouts]);
check('both scored on day 1: the first is the focus\'s first (general: sleep)', $both['first']['id'] === 'sleep');
check('both scored: the overall score is their average, said', str_starts_with((string) $both['note'], 'Je gezondheidsscore'), (string) $both['note']);
$fit = card(1, ['nights' => $history, 'workouts' => $workouts], 'fitness');
check('fitness focus: the trainingsscore comes first', $fit['first']['id'] === 'training' && $fit['title'] === 'Je eerste trainingsscore');
$tb = card(3, ['nights' => $synced, 'workouts' => $workouts], 'fitness');
check('starting point in the focus\'s order', array_column($tb['baseline'], 'id') === ['training', 'sleep']);
check('training fact from the engine\'s balance', str_starts_with((string) $tb['baseline'][0]['fact'], 'Gemiddeld '), (string) $tb['baseline'][0]['fact']);
$w2 = card(2, ['workouts' => [workout('2026-10-01', 40), workout('2026-10-02', 25, '07:30')]], 'fitness');
check('training fact: the newest workout, with its day', $w2['observation'] === 'Je laatste training duurde 25 minuten, vandaag.', (string) $w2['observation']);
check('training detail: count and minutes', $w2['progress'][0]['detail'] === '2 trainingen, 65 min', (string) $w2['progress'][0]['detail']);

/* --------------------------------------------------------------------- */
section('the focus orders; it never hides');

foreach (['general', 'sleep', 'energy', 'fitness', 'weight'] as $focus) {
    $card = card(1, [], $focus);
    check("{$focus}: all three categories, in its order", array_column($card['progress'], 'id') === setup_focus_order($focus));
}
check('weight focus: the weight, as the first fact', card(1, [], 'weight', ['weight' => ['value' => 82.4, 'unit' => 'kg']])['observation'] === 'Je startgewicht: 82,4 kg.');
check('weight in pounds is not converted by guesswork', card(1, [], 'weight', ['weight' => ['value' => 180, 'unit' => 'lb']])['observation'] === null);
check('legend rows reorder by area, others keep their place',
    array_column(setup_order_rows([['area' => 'sleep'], ['area' => 'nutrition'], ['area' => 'training'], ['area' => 'x']], 'weight'), 'area') === ['nutrition', 'training', 'sleep', 'x']);

/* --------------------------------------------------------------------- */
section('tone: facts, not advice; nothing called good, bad or realistic');

$all = [];
foreach ([[], ['nights' => $nights], ['nights' => $history], ['ratings' => $ratings], ['nights' => $history, 'workouts' => $workouts]] as $records) {
    for ($d = 1; $d <= 6; $d++) {
        foreach (['general', 'fitness', 'weight'] as $focus) {
            $all = array_merge($all, sentences(card($d, $records, $focus, ['weight' => ['value' => 80, 'unit' => 'kg']])));
        }
    }
}
$all = array_values(array_unique($all));
$banned = '/\b(moet|moeten|probeer|zou je|beter|slechter|goed|slecht|realistisch|haalbaar|gezond|ongezond|advies|aanrader)\b/iu';
$hits = array_values(array_filter($all, static fn ($s) => preg_match($banned, $s) === 1));
check('no sentence judges or advises (' . count($all) . ' checked)', $hits === [], implode(' | ', $hits));
check('no sentence has an unfilled placeholder', array_filter($all, static fn ($s) => preg_match('/%\d?\$?[sd]/', $s) === 1) === []);

/* --------------------------------------------------------------------- */
section('a first goal from the person\'s own data');

$recent = ['nights' => [night('2026-09-28', '23:40', 395), night('2026-09-29', '23:10', 410), night('2026-09-30', '00:05', 398)]];
$plan = setup_suggestion_plan('sleep', $recent, $suggest);
check('sleep: suggested from 3 nights', $plan !== null && $plan['source'] === 'sleep');
check('sleep: the basis is the average (6:41)', $plan['basis'] === 'Je sliep de afgelopen 3 nachten gemiddeld 6:41.', (string) ($plan['basis'] ?? ''));
check('sleep: the next half hour above it — 7 uur', $plan['input']['daily_target'] === '7' && $plan['name'] === '5 nachten van minstens 7 uur', json_encode($plan['input'] ?? null));
check('sleep: the normal goal model — Optellen, days, from slaapduur, a week',
    $plan['input']['type'] === 'accumulate' && $plan['input']['measure'] === 'days'
    && $plan['input']['source_kind'] === 'metric' && $plan['input']['source_key'] === 'sleep_duration'
    && $plan['input']['duration'] === 'week' && $plan['input']['target_value'] === '5' && $plan['input']['direction'] === 'increase');
check('sleep 7:10 on average: 7,5 uur', setup_suggestion_plan('sleep', ['nights' => [night('2026-09-28', '23:00', 430), night('2026-09-29', '23:00', 430), night('2026-09-30', '23:00', 430)]], $suggest)['input']['daily_target'] === '7.5');
check('sleep 8:10 on average: nothing (the curve tops out at 8)', setup_suggestion_plan('sleep', ['nights' => [night('2026-09-28', '22:00', 490), night('2026-09-29', '22:00', 490), night('2026-09-30', '22:00', 490)]], $suggest) === null);
check('two nights: nothing — too little to work out', setup_suggestion_plan('sleep', ['nights' => array_slice($recent['nights'], 0, 2)], $suggest) === null);
check('no data at all: nothing', setup_suggestion_plan('general', [], $suggest) === null);
check('weight: never — the target is the person\'s own call', setup_suggestion_plan('weight', $recent + ['workouts' => $workouts], $suggest) === null);
$train = setup_suggestion_plan('fitness', ['workouts' => $workouts, 'nights' => $recent['nights']], $suggest);
check('fitness: training first — 4 in two weeks is 2 a week, so 3', $train !== null && $train['source'] === 'training'
    && $train['input']['target_value'] === '3' && $train['input']['source_key'] === 'sessions' && $train['name'] === '3 trainingen deze week', json_encode($train));
check('fitness without workouts: sleep instead', setup_suggestion_plan('fitness', $recent, $suggest)['source'] === 'sleep');
check('the suggestion never says realistic', preg_match($banned, implode(' ', [$plan['basis'], $plan['name'], $train['basis'], $train['name']])) === 0);

/* --------------------------------------------------------------------- */
section('words');

check('hours', setup_hours(401) === '6:41' && setup_hours(59.6) === '1:00');
check('clock', setup_clock(1420) === '23:40' && setup_clock(1445) === '00:05');
check('decimals', setup_decimal(7) === '7' && setup_decimal(7.46) === '7,5' && setup_decimal_trim(7.5) === '7,5' && setup_decimal_trim(7.0) === '7');
$o = $cal['observation'];
check('days', setup_day_ref('2026-10-03', '2026-10-03', $o) === 'vandaag' && setup_day_ref('2026-10-02', '2026-10-03', $o) === 'gisteren'
    && setup_day_ref('2026-09-28', '2026-10-03', $o) === 'op 28 september');
check('names', calibration_names(['sleep'], $cal) === 'slaap' && calibration_names(['nutrition', 'training'], $cal) === 'voeding en sport'
    && calibration_names(['sleep', 'nutrition', 'training'], $cal) === 'slaap, voeding en sport');

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
