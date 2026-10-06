<?php
/**
 * The Scorekompas, tested without a database.
 *
 *     php tools/score-compass-test.php
 *
 * includes/score-compass.php explains the Health Score from the engine's own
 * results, and must never score anything itself. This feeds it made-up daily
 * results — the shape health_score_history() returns — and checks what it
 * says: the direction and its sentences, recovery and weeks in a row, a
 * category starting or stopping to count, the averages and when they are
 * withheld, the biggest opportunity (weight times room, not the lowest
 * category), empty states, and that no sentence it can produce says why or
 * what to do. It also checks that the facts the engine now hands along are
 * the very numbers its components were calculated from.
 *
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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

$copy  = require dirname(__DIR__) . '/config/compass.php';
$cfg   = health_scoring_config();
$areas = [
    'sleep'     => ['label' => 'Slaap',   'accent' => 'sleep',     'icon' => 'moon',     'empty' => 'Koppel een bron om je slaapscore te berekenen.',              'collecting' => 'Nog %s met slaapgegevens nodig voor je slaapscore.'],
    'nutrition' => ['label' => 'Voeding', 'accent' => 'nutrition', 'icon' => 'utensils', 'empty' => 'Geef je voeding een dagcijfer om je voedingsscore te berekenen.', 'collecting' => 'Nog %s met een dagcijfer nodig voor je voedingsscore.'],
    'training'  => ['label' => 'Sport',   'accent' => 'training',  'icon' => 'dumbbell', 'empty' => 'Koppel een bron om je trainingsscore te berekenen.',          'collecting' => 'Nog %s met een training nodig voor je trainingsscore.'],
];

/** A category result, as health_score_result() builds it. */
function result(?int $score, int $days = 30, array $components = [], array $facts = []): array
{
    return ['score' => $score, 'days' => $days, 'components' => $components, 'facts' => $facts];
}

/**
 * $n days ending 2026-10-02, each as health_score_at() returns it. $make($i)
 * gives day $i (0 = oldest) its [sleep, nutrition, training] results.
 */
function history(int $n, callable $make): array
{
    $out  = [];
    $last = new DateTimeImmutable('2026-10-02');

    for ($i = 0; $i < $n; $i++) {
        [$sleep, $nutrition, $training] = $make($i);
        $date = $last->modify('-' . ($n - 1 - $i) . ' days')->format('Y-m-d');
        $out[$date] = [
            'sleep'     => $sleep,
            'nutrition' => $nutrition,
            'training'  => $training,
            'overall'   => ['score' => score_combine([
                'sleep'     => $sleep['score'],
                'nutrition' => $nutrition['score'],
                'training'  => $training['score'],
            ])],
        ];
    }

    return $out;
}

/** Every category at the same score: the overall is that score. */
function flat_day(?int $score): array
{
    return [result($score), result($score), result($score)];
}

$compass = static fn (array $history): array => score_compass($history, $copy, $areas);

/* Every sentence the compass produced, for the wording checks. */
$said = [];
$collect = static function (array $c) use (&$said): void {
    array_walk_recursive($c, static function ($v) use (&$said) {
        if (is_string($v)) {
            $said[] = $v;
        }
    });
};

/* ======================================================================
   Nothing yet
   ====================================================================== */
section('No data at all');

$c = $compass([]);
$collect($c);
check('no score, no band', $c['score']['value'] === null && $c['score']['band'] === null);
check('no direction on Overzicht or here', $c['direction'] === null && $c['trend']['direction'] === null);
check('the trend is empty, with its own words', $c['trend']['state'] === 'empty' && $c['trend']['empty'] === 'Nog niet genoeg gegevens.');
check('every comparison is withheld, none is a zero',
    array_column($c['comparison']['rows'], 'value') === [null, null, null, null]
    && count(array_filter(array_column($c['comparison']['rows'], 'note'), static fn ($n) => $n === 'Nog niet genoeg gegevens')) === 4
    && $c['comparison']['delta'] === null);
check('no opportunity, and it says why', $c['opportunity']['state'] === 'empty'
    && $c['opportunity']['empty'] === 'Nog niet genoeg gegevens om een kans aan te wijzen.');
check('a category without days says what to connect', $c['composition']['categories'][0]['meta'] === $areas['sleep']['empty']);

section('A few days: collecting');

$c = $compass(history(30, static fn ($i) => $i < 20 ? flat_day(null) : flat_day(80)));
$collect($c);
check('10 days with a score: a line, but no direction yet', $c['trend']['state'] === 'collecting' && $c['direction'] === null);
check('…and the neutral sentence', $c['trend']['text'] === ['Meer gegevens maken je trend duidelijker.']);
check('the line has the days that exist and gaps for the rest',
    count($c['trend']['values']) === 30 && $c['trend']['values'][0] === null && $c['trend']['values'][29] === 80);
check('a category with 2 of 3 days says how many more',
    $compass(history(30, static fn ($i) => [result(null, 2), result(null, 0), result(null, 0)]))['composition']['categories'][0]['meta']
    === 'Nog 1 dag met slaapgegevens nodig voor je slaapscore.');

/* ======================================================================
   The direction
   ====================================================================== */
section('Rising, falling, stable');

$rising = history(60, static fn ($i) => flat_day($i < 30 ? 70 : 70 + intdiv(($i - 30) * 10, 29)));
$c = $compass($rising);
$collect($c);
check('a climb of 10 points is Stijgend', ($c['direction']['key'] ?? null) === 'up' && $c['direction']['label'] === 'Stijgend',
    json_encode($c['direction']));
check('…said with both weeks and the first week\'s date',
    str_starts_with($c['trend']['text'][0], 'Je score steeg van gemiddeld 71 in de week van 3 september naar 78 in de afgelopen week.'),
    $c['trend']['text'][0]);
check('…three weeks in a row, because every week was higher', in_array('Hij steeg 3 weken op rij.', $c['trend']['text'], true),
    json_encode($c['trend']['text']));

$falling = history(30, static fn ($i) => flat_day(85 - intdiv($i * 9, 29)));
$c = $compass($falling);
$collect($c);
check('a fall of 9 points is Dalend', ($c['direction']['key'] ?? null) === 'down' && $c['direction']['label'] === 'Dalend');
check('…with its own sentence', str_starts_with($c['trend']['text'][0], 'Je score daalde van gemiddeld'), $c['trend']['text'][0]);

$steady = history(30, static fn ($i) => flat_day(80 + ($i % 3) - 1));
$c = $compass($steady);
$collect($c);
check('±1 around 80 is Stabiel', ($c['direction']['key'] ?? null) === 'flat' && $c['direction']['label'] === 'Stabiel');
check('…and says the range it stayed in', $c['trend']['text'][0] === 'Je score bleef sinds 3 september tussen 79 en 81.', $c['trend']['text'][0]);
check('…without weeks in a row or a biggest day', count($c['trend']['text']) === 1, json_encode($c['trend']['text']));

$c = $compass(history(30, static fn ($i) => flat_day($i < 15 ? 80 : 81)));
check('a 1-point difference is not a direction', ($c['direction']['key'] ?? null) === 'flat');

$c = $compass(history(30, static fn ($i) => flat_day(80)));
check('exactly level says so', $c['trend']['text'][0] === 'Je score bleef sinds 3 september op 80.', $c['trend']['text'][0]);

$c = $compass(history(30, static fn ($i) => flat_day($i < 22 ? 80 : 84)));
check('a 2-point rise in the last week is Stijgend (the threshold is inclusive)', ($c['direction']['key'] ?? null) === 'up',
    json_encode($c['trend']['text']));
check('…its biggest day is named with its date', in_array('De grootste verandering in één dag was op 25 september: van 80 naar 84.', $c['trend']['text'], true),
    json_encode($c['trend']['text']));
check('…and no weeks in a row: only one week moved', !in_array('Hij steeg 3 weken op rij.', $c['trend']['text'], true));

$young = history(30, static fn ($i) => flat_day($i === 0 ? 78 : 84 + intdiv($i * 5, 29)));
$c = $compass($young);
check('a jump in the first week of a young score is not named: it explains nothing about the period',
    ($c['direction']['key'] ?? null) === 'up'
    && array_filter($c['trend']['text'], static fn ($t) => str_contains($t, 'grootste verandering')) === [],
    json_encode($c['trend']['text']));

section('Recovery');

$dip = static function (int $i): int {
    return match (true) {
        $i < 8  => 85,
        $i < 20 => 75,
        default => 86,
    };
};
$c = $compass(history(30, static fn ($i) => flat_day($dip($i))));
$collect($c);
check('a dip of 10 and back is told as a recovery',
    str_starts_with($c['trend']['text'][0], 'Je score zakte van gemiddeld 85 tot 75 rond '),
    $c['trend']['text'][0]);
check('…ending where it came back to', str_ends_with($c['trend']['text'][0], ' en steeg daarna weer tot 86.'), $c['trend']['text'][0]);
check('…and the label is the period\'s own: Stabiel (85 → 86)', ($c['direction']['key'] ?? null) === 'flat');

$c = $compass(history(30, static fn ($i) => flat_day($i < 8 ? 85 : ($i < 20 ? 83 : 85))));
check('a dip of 2 is not called a recovery', !str_contains($c['trend']['text'][0], 'zakte'), $c['trend']['text'][0]);

section('A category starts or stops counting');

$joins = history(30, static fn ($i) => [result(86), $i >= 20 ? result(70, 3, ['rating' => 70.0]) : result(null, 2), result(86)]);
$c = $compass($joins);
$collect($c);
check('Voeding joining at 70 lowers the average: Dalend', ($c['direction']['key'] ?? null) === 'down');
check('…and the day is told with both numbers, never as a cause',
    in_array('Op 23 september ging je score van 86 naar 81; die dag ging Voeding meetellen, met 70.', $c['trend']['text'], true),
    json_encode($c['trend']['text']));
check('…without a separate biggest-day sentence for the same day',
    count(array_filter($c['trend']['text'], static fn ($t) => str_contains($t, 'grootste verandering'))) === 0);

$again = history(60, static fn ($i) => [result(86), ($i < 10 || $i >= 50) ? result(70) : result(null, 2), result(86)]);
$c = $compass($again);
check('counting again after a gap says "weer"', (bool) array_filter($c['trend']['text'], static fn ($t) => str_contains($t, 'ging Voeding weer meetellen')),
    json_encode($c['trend']['text']));

$leaves = history(30, static fn ($i) => [result(80), result(80), $i < 18 ? result(60) : result(null, 2)]);
$c = $compass($leaves);
$collect($c);
check('Sport stopping to count is told with its date',
    in_array('Sinds 21 september telt Sport niet meer mee in je score.', $c['trend']['text'], true),
    json_encode($c['trend']['text']));

section('What moved with it');

$sleepParts = static fn (float $duration): array => result(
    (int) round(0.45 * $duration + 0.30 * 90 + 0.25 * 90),
    40,
    ['duration' => $duration, 'regularity' => 90.0, 'quality' => 90.0]
);
$c = $compass(history(30, static fn ($i) => [$sleepParts($i < 15 ? 90.0 : 60.0), result(80), result(80)]));
$collect($c);
check('the component that moved most is named, as averages',
    in_array('In dezelfde periode ging je score voor slaapduur van gemiddeld 90 naar 60.', $c['trend']['text'], true),
    json_encode($c['trend']['text']));

$c = $compass(history(30, static fn ($i) => [result(80), result($i < 15 ? 80 : 60, 30, ['rating' => $i < 15 ? 80.0 : 60.0]), result(80)]));
check('Voeding is named itself, on its own scale — not as a dagcijfer of 60',
    in_array('In dezelfde periode ging je score voor Voeding van gemiddeld 80 naar 60.', $c['trend']['text'], true),
    json_encode($c['trend']['text']));

/* ======================================================================
   Compared with yourself
   ====================================================================== */
section('Compared with yourself');

$two = history(60, static fn ($i) => flat_day($i < 30 ? 75 : 80));
$c = $compass($two);
$collect($c);
$rows = array_column($c['comparison']['rows'], 'value', 'id');
check('now, 7 days, 30 days and the 30 before', $rows === ['now' => 80, 'week' => 80, 'period' => 80, 'previous' => 75], json_encode($rows));
check('the difference, from the numbers shown', $c['comparison']['delta']['text'] === '+5 ten opzichte van de 30 dagen daarvoor');
check('"now" says the window it is calculated over, from config/scoring.php: 7 days',
    $c['comparison']['rows'][0]['note'] === 'Over de afgelopen ' . health_score_window_days() . ' dagen'
    && health_score_window_days() === 7);

$c = $compass(history(60, static fn ($i) => flat_day($i < 30 ? 82 : 79)));
check('a fall is written with a minus', $c['comparison']['delta']['text'] === '−3 ten opzichte van de 30 dagen daarvoor');
$c = $compass(history(60, static fn ($i) => flat_day(80)));
check('no change says so', $c['comparison']['delta']['text'] === 'Gelijk aan de 30 dagen daarvoor');

$sparse = history(60, static fn ($i) => flat_day(($i < 30 && $i % 3 !== 0) ? null : 80));
$c = $compass($sparse);
check('10 days in the earlier 30: no average for them, and no difference',
    $c['comparison']['rows'][3]['value'] === null && $c['comparison']['delta'] === null);

$gaps = history(60, static fn ($i) => flat_day($i >= 53 ? ($i % 2 === 1 ? 90 : null) : 70));
$c = $compass($gaps);
check('a missing day is left out, never a zero: 4 days of 90 in the last 7 average 90',
    $c['comparison']['rows'][1]['value'] === 90, json_encode($c['comparison']['rows'][1]));
$gaps = history(60, static fn ($i) => flat_day($i >= 53 ? (in_array($i, [53, 55, 57], true) ? 90 : null) : 70));
check('…and 3 days in the last 7 is too few for an average',
    $compass($gaps)['comparison']['rows'][1]['value'] === null);

/* ======================================================================
   The biggest opportunity
   ====================================================================== */
section('The biggest opportunity');

/* Sport is the lowest category (65), but Slaap's duration has the most room:
   0.45 of Slaap x 60 points / 3 categories = 9 against Sport's best, balance:
   0.35 x 35 / 3 = 4.1. */
$c = $compass(history(1, static fn () => [
    result(73, 40, ['duration' => 40.0, 'regularity' => 100.0, 'quality' => 100.0],
        ['minutes' => array_fill(0, 40, 300.0), 'bedtime_sd' => 10.0, 'wake_sd' => 10.0, 'duration_sd' => 10.0,
         'regularity' => ['bedtime' => 100.0, 'wake_time' => 100.0, 'duration' => 100.0], 'quality_nights' => 40, 'quality' => []]),
    result(80, 20, ['rating' => 80.0], ['rating' => 8.0]),
    result(65, 30, ['volume' => 65.0, 'intensity' => 65.0, 'progression' => 65.0, 'balance' => 65.0]),
]));
$collect($c);
check('not simply the lowest category: Slaapduur, not Sport', $c['opportunity']['category'] === 'sleep' && $c['opportunity']['part'] === 'duration',
    json_encode([$c['opportunity']['category'], $c['opportunity']['part']]));
check('…its room on the Health Score: 9 points', $c['opportunity']['gain'] === 9 && $c['opportunity']['gain_text'] === 'Op 100 zou dit onderdeel je Gezondheidsscore met zo\'n 9 punten verhogen.');
check('…what was measured, in the person\'s own nights', $c['opportunity']['fact'] === '40 van je 40 nachten duurden korter dan 7:30.', $c['opportunity']['fact']);
check('…and what a higher score would go together with, from the curve\'s top in config/scoring.php',
    $c['opportunity']['relation'] === 'Meer nachten tussen 7:30 en 8:30 zouden samengaan met een hogere duurscore.', $c['opportunity']['relation']);
check('…in the category\'s colour, with the component\'s score band', $c['opportunity']['accent'] === 'sleep' && $c['opportunity']['band'] === 'low');

$c = $compass(history(1, static fn () => [
    result(73, 2, ['duration' => 40.0, 'regularity' => 100.0, 'quality' => 100.0]),
    result(95, 7, ['rating' => 95.0], ['rating' => 9.5]),
    result(65, 4, ['volume' => 65.0, 'intensity' => 65.0, 'progression' => 65.0, 'balance' => 65.0]),
]));
check('a category with fewer than 3 days is not pointed at, however much room', $c['opportunity']['category'] === 'training',
    json_encode($c['opportunity']['category']));
check('…Sport\'s balance instead, with 4 of its 7 days: the most weight', $c['opportunity']['part'] === 'balance');
check('the minimum is the score\'s own: 3 days of its 7', (int) $copy['rules']['opportunity_min_days'] === 3);

$c = $compass(history(1, static fn () => [
    result(98, 40, ['duration' => 98.0, 'regularity' => 98.0, 'quality' => 98.0]),
    result(97, 20, ['rating' => 97.0], ['rating' => 9.7]),
    result(98, 30, ['volume' => 98.0, 'intensity' => null, 'progression' => null, 'balance' => 98.0]),
]));
check('little room anywhere: no opportunity, and it says so', $c['opportunity']['state'] === 'empty'
    && $c['opportunity']['empty'] === 'Geen onderdeel springt eruit: overal zit weinig ruimte.');

$c = $compass(history(1, static fn () => [
    result(null, 2), result(null, 0),
    result(70, 20, ['volume' => 50.0, 'intensity' => null, 'progression' => null, 'balance' => 80.0],
        ['minutes_per_week' => 60.0, 'balance' => ['frequency' => ['value' => 2.0, 'score' => 70.0], 'rest' => ['value' => 2, 'score' => 100.0],
         'spikes' => null, 'hard_days' => null, 'sleep' => null]]),
]));
$collect($c);
check('one category counting: all of the score is its, a component without data takes no share',
    $c['opportunity']['part'] === 'volume' && $c['opportunity']['gain'] === (int) round(0.20 / 0.55 * 50),
    json_encode([$c['opportunity']['part'], $c['opportunity']['gain']]));
check('…minutes a week, and "more" below the curve\'s top', $c['opportunity']['fact'] === 'Je traint gemiddeld 60 minuten per week.'
    && $c['opportunity']['relation'] === 'Meer trainingsminuten per week zouden samengaan met een hogere volumescore.');
check('…and the note says only Sport counts', str_starts_with($c['composition']['note'], 'Je Gezondheidsscore is nu je score voor Sport'),
    $c['composition']['note']);

section('Each explanation, from the facts');

$explain = static fn (string $cat, string $key, array $result) => score_compass_explain($cat, $key, $result, $copy['composition']['parts'][$cat][$key]['title'], $copy, $cfg);

[$f, $r] = $explain('sleep', 'regularity', result(80, 30, ['regularity' => 60.0],
    ['bedtime_sd' => 52.4, 'wake_sd' => 20.0, 'duration_sd' => 25.0, 'regularity' => ['bedtime' => 61.0, 'wake_time' => 95.0, 'duration' => 94.0]]));
check('regularity: the bedtime, said as the user would (the brief\'s own example)',
    $f === 'Je bedtijd wisselt doorgaans zo\'n 52 minuten.' && $r === 'Een regelmatiger bedtijd zou samengaan met een hogere regelmaatscore.', "$f / $r");

[$f, $r] = $explain('sleep', 'duration', result(80, 30, ['duration' => 80.0], ['minutes' => [600.0, 610.0, 620.0, 470.0]]));
check('duration: long nights are named as long', $f === '3 van je 4 nachten duurden langer dan 8:30.', $f);

[$f, $r] = $explain('sleep', 'quality', result(80, 30, ['quality' => 70.0], ['quality' => [
    'efficiency' => ['value' => 91.0, 'score' => 93.0, 'nights' => 20], 'awake' => ['value' => 48.0, 'score' => 66.0, 'nights' => 20],
    'deep' => ['value' => 18.0, 'score' => 100.0, 'nights' => 20], 'rem' => ['value' => 22.0, 'score' => 100.0, 'nights' => 20]]]));
check('quality: the weakest measurement', $f === 'Je bent gemiddeld 48 minuten per nacht wakker.' && $r === 'Minder tijd wakker zou samengaan met een hogere kwaliteitsscore.', "$f / $r");

[$f, $r] = $explain('sleep', 'quality', result(80, 30, ['quality' => 70.0], ['quality' => [
    'efficiency' => null, 'awake' => null, 'deep' => ['value' => 7.0, 'score' => 55.0, 'nights' => 20], 'rem' => ['value' => 22.0, 'score' => 100.0, 'nights' => 20]]]));
check('quality: too little deep sleep is "groter aandeel"', $r === 'Een groter aandeel diepe slaap zou samengaan met een hogere kwaliteitsscore.', $r);

[$f, $r] = $explain('nutrition', 'rating', result(74, 7, ['rating' => 74.3], ['rating' => 7.43]));
check('nutrition: the average cijfer with a comma', $f === 'Je gemiddelde dagcijfer voor voeding is 7,4, over 7 dagen.', $f);

[$f, $r] = $explain('training', 'intensity', result(70, 20, ['intensity' => 68.0], ['hard' => 14, 'known' => 20]));
check('intensity: mostly hard is "kleiner aandeel"', $f === '14 van je 20 trainingen met gemeten inspanning waren zwaar.'
    && $r === 'Een kleiner aandeel zware trainingen zou samengaan met een hogere intensiteitsscore.', "$f / $r");

[$f, $r] = $explain('training', 'intensity', result(70, 20, ['intensity' => 60.0], ['hard' => 1, 'known' => 20]));
check('intensity: all easy is "groter aandeel"', $r === 'Een groter aandeel zware trainingen zou samengaan met een hogere intensiteitsscore.', $r);

[$f, $r] = $explain('training', 'progression', result(70, 20, ['progression' => 52.0],
    ['progression' => ['signals' => ['pace' => 1, 'vo2' => 1, 'goals' => 0], 'change' => -0.021]]));
check('progression: what was compared and how it went', $f === 'Je recente resultaten voor tempo en VO2max zijn gemiddeld 2% minder goed dan je eerdere.', $f);

$balance = static fn (array $parts) => $explain('training', 'balance', result(70, 20, ['balance' => 60.0], ['balance' => $parts + [
    'frequency' => ['value' => 4.0, 'score' => 100.0], 'rest' => ['value' => 2, 'score' => 100.0],
    'spikes' => null, 'hard_days' => null, 'sleep' => null]]));
[$f] = $balance(['frequency' => ['value' => 1.4, 'score' => 50.0]]);
check('balance: few training days', $f === 'Je traint gemiddeld op 1,4 dagen per week.', $f);
[$f, $r] = $balance(['rest' => ['value' => 9, 'score' => 50.0]]);
check('balance: a long run without rest', $f === 'Je langste reeks trainingsdagen zonder rustdag was 9 dagen.', $f);
[$f] = $balance(['spikes' => ['value' => 3, 'of' => 8, 'score' => 50.0]]);
check('balance: load spikes', $f === 'In 3 van 8 weken lag je trainingstijd veel hoger dan in de weken ervoor.', $f);
[$f] = $balance(['hard_days' => ['value' => 4, 'of' => 10, 'score' => 50.0]]);
check('balance: hard days back to back', $f === '4 van je 10 zware trainingsdagen volgden direct op een andere zware dag.', $f);
[$f, $r] = $balance(['sleep' => ['value' => 380.0, 'of' => 12, 'score' => 50.0]]);
check('balance: short nights after training', $f === 'Na trainingsdagen sliep je gemiddeld 6:20.'
    && $r === 'Langere nachten na trainingsdagen zouden samengaan met een hogere balansscore.', "$f / $r");

[$f, $r] = $explain('training', 'progression', result(70, 20, ['progression' => 52.0], []));
check('without facts: what it scores, never a made-up number', $f === 'Je score voor progressie staat op 52 van 100.', $f);

/* ======================================================================
   What makes up the score
   ====================================================================== */
section('What the score is made of');

$c = $compass(history(1, static fn () => [
    result(84, 36, ['duration' => 76.0, 'regularity' => 92.5, 'quality' => 88.6],
        ['minutes' => [399.0], 'bedtime_sd' => 14.6, 'wake_sd' => 31.1, 'quality_nights' => 36]),
    result(74, 7, ['rating' => 74.3], ['rating' => 7.43]),
    result(86, 18, ['volume' => 75.8, 'intensity' => null, 'progression' => null, 'balance' => 91.6],
        ['minutes_per_week' => 168.6, 'balance' => ['frequency' => ['value' => 3.25, 'score' => 95.0]]]),
]));
$collect($c);
[$sleep, $food, $sport] = $c['composition']['categories'];
check('three categories, each counting equally, over the configured window',
    $c['composition']['note'] === 'Je Gezondheidsscore is het gemiddelde van Slaap, Voeding en Sport over de afgelopen 7 dagen. Elk telt even zwaar.',
    $c['composition']['note']);
check('Slaap: its three components with the weights config/scoring.php gives them',
    array_column($sleep['parts'], 'weight', 'id') === ['duration' => 45, 'regularity' => 30, 'quality' => 25]);
check('…their values as whole numbers, and their score bands', array_column($sleep['parts'], 'value') === [76, 93, 89]
    && array_column($sleep['parts'], 'band') === ['mid', 'high', 'high']);
check('…each with what it was worked out from', $sleep['parts'][0]['note'] === 'Gemiddeld 6:39 per nacht'
    && $sleep['parts'][1]['note'] === 'Bedtijd wisselt ±15 min, opstaan ±31 min' && $sleep['parts'][2]['note'] === 'Gemeten in 36 nachten');
check('Voeding: one part, so a sentence instead of a list', $food['parts'] === [] && $food['summary'] === 'Je voedingsscore is je gemiddelde dagcijfer (7,4) keer tien.');
check('Sport: missing components do not count, the others share their weight (20:35 of 55)',
    array_column($sport['parts'], 'weight', 'id') === ['volume' => 36, 'intensity' => null, 'progression' => null, 'balance' => 64],
    json_encode(array_column($sport['parts'], 'weight', 'id')));
check('…and say why they do not count', str_starts_with((string) $sport['parts'][1]['note'], 'Telt nu niet mee'));
check('a category\'s colour is its identity, its band its score', $sleep['accent'] === 'sleep' && $sleep['band'] === 'high' && $food['band'] === 'mid');

$c = $compass(history(1, static fn () => [result(null, 2), result(null, 0), result(null, 0)]));
check('no score yet: the components with the weights they will have, no values',
    array_column($c['composition']['categories'][0]['parts'], 'weight') === [45, 30, 25]
    && array_column($c['composition']['categories'][0]['parts'], 'value') === [null, null, null]);

/* ======================================================================
   The facts are the engine's own numbers
   ====================================================================== */
section('The engine\'s facts');

$asOf = new DateTimeImmutable('2026-09-25 15:00:00');
$nights = [];
for ($i = 20; $i >= 1; $i--) {
    $end = $asOf->setTime(7, 0)->modify('-' . $i . ' days')->getTimestamp() + (($i * 37) % 61 - 30) * 60;
    $hours = 7 + (($i * 53) % 21 - 10) / 20;
    $nights[] = [
        'session_id' => $end, 'date' => date('Y-m-d', $end), 'start' => $end - (int) round($hours * 3600), 'end' => $end,
        'minutes' => $hours * 60, 'in_bed' => $hours * 60 + 25, 'efficiency' => null, 'awake' => 20 + $i,
        'light' => 200, 'deep' => 60 + $i, 'rem' => 90,
    ];
}
$s = health_score_sleep($nights);
$r = $cfg['sleep']['regularity'];
check('facts list every night', count($s['facts']['minutes']) === 20);
check('the regularity facts weigh up to the component', round(health_weighted($s['facts']['regularity'], $r['weights']), 1) === $s['components']['regularity'],
    json_encode([$s['facts']['regularity'], $s['components']['regularity']]));
check('…and are the curves of the spreads', abs($s['facts']['regularity']['bedtime'] - health_curve($r['timing_curve'], $s['facts']['bedtime_sd'])) < 1e-9);
check('the duration facts give the component back', round(health_mean(array_map(
    static fn ($m) => health_curve($cfg['sleep']['duration_curve'], $m / 60), $s['facts']['minutes'])), 1) === $s['components']['duration']);
check('quality facts per measurement, over the nights that had it',
    $s['facts']['quality']['awake']['nights'] === 20 && $s['facts']['quality']['deep'] !== null && $s['facts']['quality_nights'] === 20);
check('a night\'s quality is still its parts\' weighted average', abs(health_night_quality($nights[0]) - health_weighted(
    array_map(static fn ($p) => $p['score'] ?? null, health_night_quality_parts($nights[0])), $cfg['sleep']['quality']['weights'])) < 1e-9);

$n = health_score_nutrition([['at' => 1, 'date' => '2026-09-01', 'value' => 7.0], ['at' => 2, 'date' => '2026-09-02', 'value' => 8.0],
    ['at' => 3, 'date' => '2026-09-03', 'value' => 6.0]]);
check('nutrition facts: the average cijfer', $n['facts']['rating'] === 7.0 && $n['components']['rating'] === 70.0);

check('facts are never stored: health_score_store writes components only',
    !str_contains((string) file_get_contents(dirname(__DIR__) . '/includes/health-score.php'), "json_encode(\$result['facts']"));

/* ======================================================================
   Words: when, never why; what goes with, never what to do
   ====================================================================== */
section('Wording');

array_walk_recursive($copy, static function ($v) use (&$said) {
    if (is_string($v)) {
        $said[] = $v;
    }
});
$text = mb_strtolower(implode("\n", $said));

foreach ([
    'omdat', 'doordat', 'vanwege', 'dankzij', 'waardoor', 'veroorzaak', 'zorgt ervoor', 'leidt tot', 'komt door', 'het gevolg',
] as $word) {
    check("never says why: no \"{$word}\"", !str_contains($text, $word));
}
foreach (['je moet', 'moet je', 'ga om', 'probeer', 'zorg dat', 'zorg voor', 'advies', 'je zou moeten'] as $word) {
    check("never says what to do: no \"{$word}\"", !str_contains($text, $word));
}
foreach (['anderen', 'andere gebruikers', 'vrienden', 'nederland', 'gemiddelde nederlander', 'leeftijdsgenoten', 'ranglijst'] as $word) {
    check("never compares with others: no \"{$word}\"", !str_contains($text, $word));
}
check('the opportunity sentences say what would go together, every one of them',
    array_filter($copy['opportunity']['texts'], static fn ($t) => isset($t['relation']) && !str_contains($t['relation'], 'zou') && !str_contains($t['relation'], 'zouden')) === []);
check('no window is written into the words: it is filled in from config/scoring.php',
    !preg_match('/afgelopen \d+ dagen/', implode("\n", array_filter(array_map(
        static fn ($v) => is_string($v) ? $v : null,
        [$copy['composition']['note'], $copy['composition']['note_one'], $copy['composition']['none'], $copy['comparison']['rows']['now']['note']]
    )))));

/* ======================================================================
   Pure
   ====================================================================== */
section('History: one score, seen over 7, 30, 90 and 365 days');

/* A year of history, of which only the last 18 days have a score: an account
   that started on 15 September. */
$young = history(365, static fn ($i) => $i < 347 ? flat_day(null) : flat_day(70 + ($i % 5)));
$c = $compass($young);
$collect($c);
$periods = array_column($c['trend']['periods'], null, 'key');
check('four periods, the score\'s own week first', array_column($c['trend']['periods'], 'key') === ['7', '30', '90', '365'] && $c['trend']['default'] === '7'
    && array_column($c['trend']['periods'], 'label') === ['7 dagen', '30 dagen', '90 dagen', '1 jaar']);
check('a new account: the days start at its first score — 18 days, no placeholders before them',
    count($c['trend']['days']) === 18 && $c['trend']['days'][0]['date'] === '2026-09-15'
    && array_filter($c['trend']['days'], static fn ($d) => $d['value'] === null) === []);
check('…a year shows those 18 days, and says when the history begins',
    count($periods['365']['values']) === 18 && $periods['365']['start'] === 0
    && $periods['365']['since'] === 'Je geschiedenis begint op 15 september.', json_encode($periods['365']['since']));
check('…30 and 90 days the same 18; 7 days its last week, with nothing to explain',
    count($periods['30']['values']) === 18 && count($periods['90']['values']) === 18
    && count($periods['7']['values']) === 7 && $periods['7']['start'] === 11 && $periods['7']['since'] === null);
check('…its year\'s dates are where those 18 days are, never before them',
    $periods['365']['axis'][0] === ['label' => '15 sep', 'x' => 0.0] && end($periods['365']['axis']) === ['label' => 'Vandaag', 'x' => 100.0],
    json_encode($periods['365']['axis']));

$c = $compass(history(365, static fn ($i) => flat_day(60 + intdiv($i, 20))));
$periods = array_column($c['trend']['periods'], null, 'key');
check('a full year: 365 days, each period the end of it',
    count($c['trend']['days']) === 365 && count($periods['365']['values']) === 365 && $periods['365']['since'] === null
    && $periods['90']['start'] === 275 && count($periods['90']['values']) === 90 && $periods['30']['start'] === 335);
check('the periods are views of the stored scores, not scores of their own: each day the same number in every period',
    array_slice($periods['365']['values'], -7) === $periods['7']['values'] && array_slice($periods['90']['values'], -30) === $periods['30']['values']);
check('a year\'s axis: five dates a quarter apart, the first with its year — it is not this October',
    array_column($periods['365']['axis'], 'label') === ['3 okt 2025', '2 jan', '3 apr', '3 jul', 'Vandaag']
    && array_column($periods['365']['axis'], 'x') === [0.0, 25.0, 50.0, 75.0, 100.0], json_encode($periods['365']['axis']));
check('a week: four dates two days apart, and a dot for every day',
    array_column($periods['7']['axis'], 'x') === [0.0, 33.33, 66.67, 100.0] && $periods['7']['day_dots'] === true
    && $periods['365']['day_dots'] === false && count($periods['30']['axis']) === 3, json_encode($periods['7']['axis']));
check('the reading names each category once, in the legend\'s order and colours',
    $c['trend']['readout']['categories'] === [
        ['id' => 'sleep', 'label' => 'Slaap', 'accent' => 'sleep'],
        ['id' => 'nutrition', 'label' => 'Voeding', 'accent' => 'nutrition'],
        ['id' => 'training', 'label' => 'Sport', 'accent' => 'training'],
    ] && array_column($c['trend']['days'][0]['categories'], 'id') === ['sleep', 'nutrition', 'training']);
check('the spoken label says the period in words', $periods['365']['aria'] === 'Je Gezondheidsscore per dag, het afgelopen jaar: stijgend',
    $periods['365']['aria']);
check('the direction under the score is still the one over 30 days',
    $c['direction'] === $periods['30']['direction'] && $periods['30']['text'] === $c['trend']['text']);
check('a year has a direction and sentences of its own', $periods['365']['direction']['key'] === 'up'
    && str_contains($periods['365']['text'][0], 'in de week van 3 oktober'), json_encode($periods['365']['text']));

$c = $compass(history(30, static fn ($i) => flat_day($i < 23 ? 70 : [74, 79, 76, 75, 77, 78, 76][$i - 23])));
$week = array_column($c['trend']['periods'], null, 'key')['7'];
check('7 days: too short to compare two weeks, so it says what the week held', $week['text'] === ['De afgelopen 7 dagen lag je score tussen 74 en 79.']
    && $week['direction'] === null && $week['state'] === 'filled', json_encode($week['text']));
$c = $compass(history(30, static fn ($i) => flat_day(76)));
check('…or that it stayed put', array_column($c['trend']['periods'], null, 'key')['7']['text'] === ['De afgelopen 7 dagen stond je score op 76.']);
check('no score at all: no days, every period empty',
    ($e = $compass(history(365, static fn () => flat_day(null)))['trend']['days']) === []
    && array_unique(array_column($compass(history(365, static fn () => flat_day(null)))['trend']['periods'], 'state')) === ['empty']);

/* One day read closely: its categories and their parts, as recorded. */
$closer = history(3, static fn ($i) => [
    result(68, 3, ['duration' => 72.4, 'regularity' => 60.0, 'quality' => 70.2]),
    result(77, 3, ['rating' => 77.0]),
    result(null, 1, ['volume' => null, 'intensity' => null, 'progression' => null, 'balance' => null]),
]);
$closer['2026-10-01']['state'] = 'carried';
$closer['2026-10-01']['from']  = '2026-09-30';
$closer['2026-10-02']['state'] = 'today';
$c    = $compass($closer);
$days = $c['trend']['days'];
check('a day: its date, its Health Score and its band', $days[0]['label'] === '30 september' && $days[0]['value'] === 73 && $days[0]['band'] === 'mid');
check('…each category with its score and band, Sport without one', array_column($days[0]['categories'], 'value', 'id') === ['sleep' => 68, 'nutrition' => 77, 'training' => null]
    && $days[0]['categories'][0]['band'] === 'mid');
check('…and its parts, as the score weighed them that day',
    $days[0]['categories'][0]['parts'] === 'Slaapduur 72 · Regelmaat 60 · Kwaliteit 70' && $days[0]['categories'][1]['parts'] === 'Dagcijfer 7,7'
    && $days[0]['categories'][2]['parts'] === null, json_encode(array_column($days[0]['categories'], 'parts')));
check('a day with no score of its own says which day\'s score still held', $days[1]['note'] === 'Geen nieuwe gegevens: de score van 30 september gold nog.'
    && $days[1]['state'] === 'carried');
check('today is called today', $days[2]['label'] === 'Vandaag' && $days[2]['note'] === null);
$gap = history(3, static fn ($i) => $i === 1 ? flat_day(null) : flat_day(70));
check('a day without any score says so — never a 0', $compass($gap)['trend']['days'][1]['value'] === null
    && $compass($gap)['trend']['days'][1]['note'] === 'Geen score op deze dag.');

/* ====================================================================== */
section('It only reads');

$before = serialize($two);
$compass($two);
check('the history it is given is left exactly as it was', serialize($two) === $before);
check('the score shown is the engine\'s, as of the last day', $compass($two)['score']['value'] === $two[array_key_last($two)]['overall']['score']);
$code = implode('', array_map(
    static fn ($token) => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token,
    token_get_all((string) file_get_contents(dirname(__DIR__) . '/includes/score-compass.php'))
));
check('no scoring call in the compass: it never combines, stores or recalculates',
    !preg_match('/score_combine\(|health_score_store\(|health_score_at\(|health_score_now\(|health_weighted\(|health_score_(sleep|nutrition|training)\(/', $code));

echo str_repeat('-', 72), "\n";
printf("  %d passed, %d failed\n\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
