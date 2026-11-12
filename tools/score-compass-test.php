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
 * withheld, that the Mogelijk aandachtspunt is gone (38.0), empty states,
 * and that no sentence it can produce says why or
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
    'training'  => ['label' => 'Training',   'accent' => 'training',  'icon' => 'dumbbell', 'empty' => 'Koppel een bron om je trainingsscore te berekenen.',          'collecting' => 'Nog %s met een training nodig voor je trainingsscore.'],
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
check('Training stopping to count is told with its date',
    in_array('Sinds 21 september telt Training niet meer mee in je score.', $c['trend']['text'], true),
    json_encode($c['trend']['text']));

section('What moved with it');

$sleepParts = static fn (float $duration): array => result(
    (int) round(health_weighted(['duration' => $duration, 'regularity' => 90.0, 'social_jetlag' => 90.0], $cfg['sleep']['weights'])),
    40,
    ['duration' => $duration, 'regularity' => 90.0, 'social_jetlag' => 90.0]
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
   No point of attention (38.0)
   ====================================================================== */
section('No Mogelijk aandachtspunt');

$c = $compass(history(1, static fn () => [result(76, 40, ['duration' => 40.0, 'regularity' => 100.0]), result(80, 20, ['rating' => 80.0]), result(65, 30, ['steps' => 65.0])]));
check('the Scorekompas has no point of attention any more', !array_key_exists('opportunity', $c));
check('…nor its words or rules', !isset($copy['opportunity']) && !isset($copy['rules']['opportunity_min_days']) && !function_exists('score_compass_opportunity'));
check('every factor in config/scoring.php has its words', array_keys($copy['composition']['parts']['sleep']) === array_keys($cfg['sleep']['factors'])
    && array_keys($copy['composition']['parts']['nutrition']) === array_keys($cfg['nutrition']['factors'])
    && array_keys($copy['composition']['parts']['training']) === array_keys($cfg['training']['factors']));

/* ======================================================================
   What makes up the score
   ====================================================================== */
section('What the score is made of');

$c = $compass(history(1, static fn () => [
    result(84, 6, ['duration' => 76.0, 'regularity' => 92.5, 'social_jetlag' => 100.0],
        ['minutes' => [399.0], 'bedtime_sd' => 14.6, 'wake_sd' => 31.1, 'social_jetlag' => 0.4, 'quality_nights' => 0]),
    result(74, 7, ['rating' => 74.3], ['rating' => 7.43, 'food_days' => 0]),
    result(86, 6, ['steps' => 80.0, 'aerobic' => 75.8], ['steps' => 7120.0, 'aerobic_per_week' => 168.6]),
]));
$collect($c);
[$sleep, $food, $sport] = $c['composition']['categories'];
check('three categories, each counting equally, over the configured window',
    $c['composition']['note'] === 'Je Gezondheidsscore is het gemiddelde van Slaap, Voeding en Training over de afgelopen 7 dagen. Elk telt even zwaar.',
    $c['composition']['note']);
check('Slaap: its eight factors, those with data sharing the weight (34.5 : 34.5 : 17.3)',
    array_column($sleep['parts'], 'weight', 'id') === ['duration' => 40, 'regularity' => 40, 'social_jetlag' => 20, 'efficiency' => null,
        'awake' => null, 'deep' => null, 'rem' => null, 'hrv' => null], json_encode(array_column($sleep['parts'], 'weight', 'id')));
check('…their values as whole numbers, and their score bands', array_slice(array_column($sleep['parts'], 'value'), 0, 3) === [76, 93, 100]
    && array_slice(array_column($sleep['parts'], 'band'), 0, 3) === ['mid', 'high', 'high']);
check('…each with what it was worked out from', $sleep['parts'][0]['note'] === 'Gemiddeld 6:39 per nacht'
    && $sleep['parts'][1]['note'] === 'Bedtijd wisselt ±15 min, opstaan ±31 min' && $sleep['parts'][2]['note'] === 'Midden van je slaap verschuift 0:24 op vrije dagen',
    json_encode(array_column($sleep['parts'], 'note')));
check('…and the ones without data say why they do not count', str_starts_with((string) $sleep['parts'][7]['note'], 'Telt nu niet mee'));
check('Voeding on its rating alone: the sentence, and the rating carrying all of it',
    $food['summary'] === 'Je voedingsscore is je gemiddelde dagcijfer (7,4) keer tien.'
    && array_column($food['parts'], 'weight', 'id')['rating'] === 100, json_encode($food['summary']));
check('Training: steps and aerobic minutes share their weight (26.5 : 9.9)',
    array_filter(array_column($sport['parts'], 'weight', 'id')) === ['steps' => 73, 'aerobic' => 27],
    json_encode(array_column($sport['parts'], 'weight', 'id')));
check('a category\'s colour is its identity, its band its score', $sleep['accent'] === 'sleep' && $sleep['band'] === 'high' && $food['band'] === 'mid');

$c = $compass(history(1, static fn () => [result(null, 2), result(null, 0), result(null, 0)]));
check('no score yet: the factors with the weights they will have, no values',
    array_column($c['composition']['categories'][0]['parts'], 'weight') === [35, 35, 17, 3, 3, 2, 2, 4]
    && array_filter(array_column($c['composition']['categories'][0]['parts'], 'value')) === []);

/* ======================================================================
   The facts are the engine's own numbers
   ====================================================================== */
section('The engine\'s facts');

$asOf = new DateTimeImmutable('2026-09-25 15:00:00');
$today = $asOf->format('Y-m-d');
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
$s = health_score_sleep($nights, $today);
$r = $cfg['sleep']['regularity'];
$w = array_map(static fn ($n) => health_score_day_weight($n['date'], $today), $nights);
check('facts list every night', count($s['facts']['minutes']) === 20);
check('the regularity facts average to the component', round(($s['facts']['regularity']['bedtime'] + $s['facts']['regularity']['wake_time']) / 2, 1) === $s['components']['regularity'],
    json_encode([$s['facts']['regularity'], $s['components']['regularity']]));
check('…and are the curves of the spreads', abs($s['facts']['regularity']['bedtime'] - health_curve($r['timing_curve'], $s['facts']['bedtime_sd'])) < 1e-9);
check('the duration facts give the component back, recent nights counting more', round(health_weighted_mean(array_map(
    static fn ($m) => health_curve($cfg['sleep']['duration_curve'], $m / 60), $s['facts']['minutes']), $w), 1) === $s['components']['duration']);
check('quality facts per measurement, over the nights that had it',
    $s['facts']['quality']['awake']['nights'] === 20 && $s['facts']['quality']['deep'] !== null && $s['facts']['quality_nights'] === 20);
check('a night\'s quality is still its parts\' weighted average (the points read it)', abs(health_night_quality($nights[0]) - health_weighted(
    array_map(static fn ($p) => $p['score'] ?? null, health_night_quality_parts($nights[0])), $cfg['sleep']['quality']['weights'])) < 1e-9);

$n = health_score_nutrition([['at' => 1, 'date' => '2026-09-01', 'value' => 7.0], ['at' => 2, 'date' => '2026-09-02', 'value' => 7.0],
    ['at' => 3, 'date' => '2026-09-03', 'value' => 7.0]], '2026-09-03');
check('nutrition facts: the average cijfer', $n['facts']['rating'] === 7.0 && $n['components']['rating'] === 70.0);

check('facts are never stored: health_score_store writes components, range and coverage only',
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
    $periods['365']['axis'][0] === ['label' => '15 sep', 'x' => 0.0] && end($periods['365']['axis']) === ['label' => '2 okt', 'x' => 100.0],
    json_encode($periods['365']['axis']));

$c = $compass(history(365, static fn ($i) => flat_day(60 + intdiv($i, 20))));
$periods = array_column($c['trend']['periods'], null, 'key');
check('a full year: 365 days, each period the end of it',
    count($c['trend']['days']) === 365 && count($periods['365']['values']) === 365 && $periods['365']['since'] === null
    && $periods['90']['start'] === 275 && count($periods['90']['values']) === 90 && $periods['30']['start'] === 335);
check('the periods are views of the stored scores, not scores of their own: each day the same number in every period',
    array_slice($periods['365']['values'], -7) === $periods['7']['values'] && array_slice($periods['90']['values'], -30) === $periods['30']['values']);
check('a year\'s axis: five dates a quarter apart, the first with its year — it is not this October',
    array_column($periods['365']['axis'], 'label') === ['3 okt 2025', '2 jan', '3 apr', '3 jul', '2 okt']
    && array_column($periods['365']['axis'], 'x') === [0.0, 25.0, 50.0, 75.0, 100.0], json_encode($periods['365']['axis']));
check('a week: four dates two days apart, and a dot for every day',
    array_column($periods['7']['axis'], 'x') === [0.0, 33.33, 66.67, 100.0] && $periods['7']['day_dots'] === true
    && $periods['365']['day_dots'] === false && count($periods['30']['axis']) === 3, json_encode($periods['7']['axis']));
check('the reading names each category once, in the legend\'s order and colours',
    $c['trend']['readout']['categories'] === [
        ['id' => 'sleep', 'label' => 'Slaap', 'accent' => 'sleep'],
        ['id' => 'nutrition', 'label' => 'Voeding', 'accent' => 'nutrition'],
        ['id' => 'training', 'label' => 'Training', 'accent' => 'training'],
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
check('…each category with its score and band, Training without one', array_column($days[0]['categories'], 'value', 'id') === ['sleep' => 68, 'nutrition' => 77, 'training' => null]
    && $days[0]['categories'][0]['band'] === 'mid');
check('…and its parts, as the score weighed them that day',
    $days[0]['categories'][0]['parts'] === 'Slaapduur 72 · Regelmaat 60 · Kwaliteit 70' && $days[0]['categories'][1]['parts'] === 'Dagcijfer 7,7'
    && $days[0]['categories'][2]['parts'] === null, json_encode(array_column($days[0]['categories'], 'parts')));
check('a day with no score of its own says which day\'s score still held', $days[1]['note'] === 'Geen nieuwe gegevens: de score van 30 september gold nog.'
    && $days[1]['state'] === 'carried');
check('today is named by its date, never "Vandaag" (C59)', $days[2]['label'] === '2 oktober' && $days[2]['state'] === 'today' && $days[2]['note'] === null);
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
