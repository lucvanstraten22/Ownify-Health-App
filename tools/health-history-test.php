<?php
/**
 * Gezondheid's Verloop, against a real database: Slaap, Voeding and Training
 * as daily_scores recorded them, over the Scorekompas's periods
 * (hydrate_health_history(), lib/hydrate-compass.php) — built the way a page
 * builds it, from health_score_history() through hydrate_compass().
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/health-history-test.php
 *
 * Needs migration 017 (daily_scores.valid_until). It checks:
 *
 *   - four periods, the Scorekompas's (7 dagen first), three lines in the
 *     categories' own colours; 7 and 30 days the same windows over the same
 *     days
 *   - every point of a day is that category's recorded score that day —
 *     nothing scored again — and a real 0 is a point at the bottom
 *   - 90 days: a point a week, each the mean of its seven days; a year: twelve
 *     months over 365 days, or — younger than half a year — twelve half
 *     months from the first day, none yet where they lie ahead; each where it
 *     falls in time, a young history never stretched over the width
 *   - the height is the period's own range in round tens, at least 30: 73 to
 *     80 is a clear rise, one point a small one; the levels are named; every
 *     curve stays between the points it joins
 *   - a day without new input carries a category's last score for as long
 *     as it held (valid_until): Slaap 82, 82, (nothing new), 82 is one
 *     unbroken line at 82; after that it has none — a gap, never a 0
 *   - a category without a score on a day the others have one is a gap in
 *     its own line only
 *   - a week names every day, today by its date; a month is dated as the
 *     Scorekompas dates it; every point is a dot
 *   - the Scorekompas's own history is the same with or without it; its
 *     line is the Health Score drawn the same way — weeks, months, its own
 *     height — and everything else of it as it was
 *   - a new account has nothing to draw and says so
 *
 * Accounts are named `hhtest_…` and removed at the end, with everything
 * hanging off them. Never point it at a live database.
 */

declare(strict_types=1);

/* Under the document root on a Hestia deploy. It writes and deletes rows. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 017 (never a live one).\n");
    exit(2);
}

/* The app's clock, as includes/bootstrap.php sets it for every request. */
date_default_timezone_set('Europe/Amsterdam');

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/health-data.php';
require_once dirname(__DIR__) . '/includes/health-score.php';
require_once dirname(__DIR__) . '/includes/scoring.php';
require_once dirname(__DIR__) . '/lib/render.php';
require_once dirname(__DIR__) . '/lib/hydrate-compass.php';

if (!db_available() || !health_score_store_rich() || !health_score_store_until()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 017.\n");
    exit(2);
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

/* ------------------------------------------------------------ plumbing */

$accounts = [];

register_shutdown_function(static function () use (&$accounts): void {
    foreach ($accounts as $id) {
        db_run('DELETE FROM users WHERE id = ?', [$id]);
    }
});

$compassCopy = require dirname(__DIR__) . '/config/compass.php';
$healthCopy  = require dirname(__DIR__) . '/config/health.php';

/** A new account, made $age days ago. */
function account(int $age = 400): int
{
    global $accounts;

    db_run('INSERT INTO users (username) VALUES (?)', ['hhtest_' . bin2hex(random_bytes(4))]);
    $id = (int) db_insert_id();
    db_run('UPDATE users SET created_at = NOW() - INTERVAL ? DAY WHERE id = ?', [$age, $id]);
    $accounts[] = $id;

    return $id;
}

/** Y-m-d, $days before today. */
function day(int $days): string
{
    return (new DateTimeImmutable('today'))->modify(sprintf('%+d days', -$days))->format('Y-m-d');
}

/**
 * A day's snapshot as it was written on that day: category => [score, days
 * it holds after], an absent category without a score; the overall score is
 * combined from them, as health_score_store() writes it.
 */
function snapshot(int $userId, int $ago, array $scores): void
{
    $overall = score_combine(array_map(static fn ($s) => $s[0], $scores));
    $holds   = $scores === [] ? null : max(array_map(static fn ($s) => $s[1], $scores));
    foreach (['sleep', 'nutrition', 'training'] as $domain) {
        [$score, $hold] = $scores[$domain] ?? [null, null];
        db_run(
            'INSERT INTO daily_scores (user_id, score_date, domain, score, data_days, valid_until, algorithm_version) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, day($ago), $domain, $score, $score === null ? null : 3, $hold === null ? null : day($ago - $hold), HEALTH_SCORE_VERSION]
        );
    }
    db_run(
        'INSERT INTO daily_scores (user_id, score_date, domain, score, valid_until, algorithm_version) VALUES (?, ?, ?, ?, ?, ?)',
        [$userId, day($ago), 'overall', $overall, $holds === null ? null : day($ago - $holds), HEALTH_SCORE_VERSION]
    );
}

/** The page's view: the Scorekompas and the Verloop built from it, as app_page_data() builds them. */
function pages(int $userId): array
{
    global $compassCopy, $healthCopy;

    $areas = $healthCopy['areas'];
    $data  = [
        'scores' => ['contributors' => array_map(
            static fn ($id) => ['area' => $id, 'label' => $areas[$id]['label'], 'accent' => $areas[$id]['accent']],
            array_keys($areas)
        )],
        'health' => ['areas' => $areas],
    ];

    $compass = hydrate_compass($compassCopy, $data, $userId);
    $before  = json_encode($compass);
    $history = hydrate_health_history($compass['trend'], $compassCopy, $healthCopy['history'], $areas);

    return ['compass' => $compass, 'history' => $history, 'untouched' => json_encode($compass) === $before];
}

/** A category's score as its point says it: the period's own height in the 300 × 160 box, 12 inside top and bottom. */
function score_at(array $period, ?float $y): ?int
{
    $c = $period['chart'];

    return $y === null ? null : (int) round($c['low'] + (1 - ($y / 100 * $c['height'] - 12) / ($c['height'] - 24)) * ($c['high'] - $c['low']));
}

function period(array $pages, string $key): array
{
    return array_column($pages['history']['periods'], null, 'key')[$key];
}

/** One category's points in a period of days, by date: date => score read off the line. */
function line(array $pages, string $period, string $id): array
{
    $p     = period($pages, $period);
    $line  = array_column($p['chart']['lines'], null, 'id')[$id];
    $dates = array_column(array_slice($pages['compass']['trend']['days'], $p['start'], count($p['chart']['x'])), 'date');

    return array_combine($dates, array_map(static fn ($y) => score_at($p, $y), $line['y']));
}

/** Each segment of a path keeps its control points between its ends: a curve that never bends past a point. */
function within(string $path): bool
{
    preg_match_all('/-?[\d.]+/', $path, $m);
    $n = array_map('floatval', $m[0]);
    for ($i = 2; $i + 5 < count($n); $i += 6) {
        $from = $n[$i - 1];
        $to   = $n[$i + 5];
        foreach ([$n[$i + 1], $n[$i + 3]] as $c) {
            if ($c < min($from, $to) - 0.01 || $c > max($from, $to) + 0.01) {
                return false;
            }
        }
    }

    return true;
}

/** The mean of a category's scores over the days $from to $to ago, rounded as a score is. */
function mean_of(callable $score, int $from, int $to): ?int
{
    $values = array_values(array_filter(array_map($score, range($from, $to)), static fn ($v) => $v !== null));

    return $values === [] ? null : (int) round(array_sum($values) / count($values));
}

/* ===================================================================== */

section('Four periods, three lines, the Scorekompas\'s days');

$u = account();
for ($ago = 40; $ago >= 1; $ago--) {
    snapshot($u, $ago, [
        'sleep'     => [60 + $ago % 15, 2],
        'nutrition' => [70 + $ago % 9, 2],
        'training'  => [50 + $ago % 20, 2],
    ]);
}
$pages   = pages($u);
$history = $pages['history'];
$trend   = $pages['compass']['trend'];

check('the periods are the Scorekompas\'s: 7 dagen, 30 dagen, 90 dagen, 1 jaar',
    array_column($history['periods'], 'label') === ['7 dagen', '30 dagen', '90 dagen', '1 jaar']
    && array_column($history['periods'], 'key') === array_column($trend['periods'], 'key'));
check('  it opens on 7 dagen', $history['default'] === '7');
check('  a day, a day, a week, half a month (40 days of history)',
    array_column($history['periods'], 'group') === ['day', 'day', 'week', 'half']);
check('  7 and 30 days over the same windows: each starts where the Scorekompas\'s does',
    array_column(array_slice($history['periods'], 0, 2), 'start') === array_column(array_slice($trend['periods'], 0, 2), 'start'));
check('three lines — Slaap, Voeding, Training — in the categories\' own colours',
    array_column($history['categories'], 'label') === ['Slaap', 'Voeding', 'Training']
    && array_column($history['periods'][0]['chart']['lines'], 'accent') === ['sleep', 'nutrition', 'training']);
check('  the legend names them in that order', array_column($history['categories'], 'accent') === ['sleep', 'nutrition', 'training']);

$ok = true;
foreach (['sleep' => fn ($a) => 60 + $a % 15, 'nutrition' => fn ($a) => 70 + $a % 9, 'training' => fn ($a) => 50 + $a % 20] as $id => $score) {
    foreach (line($pages, '30', $id) as $date => $value) {
        $ago = (int) (new DateTimeImmutable($date))->diff(new DateTimeImmutable('today'))->days;
        if ($ago >= 1 && $value !== $score($ago)) { $ok = false; }
    }
}
check('every point is that category\'s recorded score that day', $ok);
check('  the Scorekompas\'s own history is not touched by it', $pages['untouched']);

$week = $history['periods'][0];
check('a week names every day — today by its date', count($week['axis']) === 7 && $week['every']
    && array_column($week['axis'], 'x') === $week['chart']['x']
    && $week['axis'][6]['label'] === score_compass_date(day(0), true));
check('  a month is dated as the Scorekompas dates it', $history['periods'][1]['axis'] === $trend['periods'][1]['axis']);
check('  every point is a dot', array_column($history['periods'], 'dots') === ['every', 'every', 'every', 'every']);
check('  spoken: the three, per day or per week, over the period',
    $week['aria'] === 'Slaap, Voeding en Training per dag, de afgelopen 7 dagen'
    && period($pages, '90')['aria'] === 'Slaap, Voeding en Training per week, de afgelopen 90 dagen');

section('A day without new input: the last score, as long as it held');

$u = account();
/* The example: Slaap 82, 82, nothing new, 82. */
snapshot($u, 6, ['sleep' => [82, 2], 'nutrition' => [70, 2]]);
snapshot($u, 5, ['sleep' => [82, 2], 'nutrition' => [71, 2]]);
/* 4 days ago: no snapshot at all — carried from the day before. */
snapshot($u, 3, ['sleep' => [82, 2], 'nutrition' => [72, 2], 'training' => [0, 2]]);
/* 2 days ago: Training had no score; 1 day ago: nothing, and nothing held long enough. */
snapshot($u, 2, ['sleep' => [80, 0], 'nutrition' => [74, 0]]);
$pages = pages($u);
$sleep = line($pages, '7', 'sleep');
$nutri = line($pages, '7', 'nutrition');
$train = line($pages, '7', 'training');

check('Slaap 82, 82, (nothing new), 82: one unbroken line at 82',
    [$sleep[day(6)], $sleep[day(5)], $sleep[day(4)], $sleep[day(3)]] === [82, 82, 82, 82],
    json_encode($sleep));
check('  the day without input is carried, from the day before',
    $pages['compass']['trend']['days'][array_search(day(4), array_column($pages['compass']['trend']['days'], 'date'), true)]['state'] === 'carried');
check('  Voeding carried the same way, its own last score', $nutri[day(4)] === 71);
check('a real 0 is a point, at the bottom', $train[day(3)] === 0);
check('a category without a score on a day the others had one: a gap in its line only',
    $train[day(2)] === null && $sleep[day(2)] === 80 && $nutri[day(2)] === 74);
check('nothing held into yesterday: a gap in every line, not a 0',
    $sleep[day(1)] === null && $nutri[day(1)] === null && $train[day(1)] === null);

$zeros = 0;
foreach ($pages['history']['periods'] as $p) {
    foreach ($p['chart']['lines'] as $k => $l) {
        foreach ($l['y'] as $i => $y) {
            $value = $p['points'][$i]['values'][$k];
            if ($p['group'] === 'day') {
                $day = $pages['compass']['trend']['days'][$p['start'] + $i];
                if ($value !== (array_column($day['categories'], 'value', 'id')[$l['id']] ?? null)) { $zeros++; }
            }
            if (($value === null) !== ($y === null) || ($value !== null && score_at($p, $y) !== $value)) { $zeros++; }
        }
    }
}
check('no missing day is drawn — every point is a score, every gap a day without one', $zeros === 0, "$zeros wrong points");

section('90 days: a week a point; a year: a month a point');

$u = account();
$scores = [
    'sleep'     => static fn (int $a): ?int => $a >= 1 ? 60 + $a % 15 : null,
    'nutrition' => static fn (int $a): ?int => $a >= 1 ? 70 + $a % 9 : null,
    'training'  => static fn (int $a): ?int => $a >= 1 && $a <= 200 ? 50 + $a % 20 : null,
];
for ($ago = 380; $ago >= 1; $ago--) {
    snapshot($u, $ago, array_filter(array_map(static fn ($f) => ($v = $f($ago)) === null ? null : [$v, 0], $scores)));
}
$pages = pages($u);
$weeks = period($pages, '90');
$year  = period($pages, '365');

check('90 days: 13 weeks, the last ending today, the first the 6 days before them',
    $weeks['group'] === 'week' && count($weeks['points']) === 13
    && $weeks['points'][12]['label'] === hydrate_health_history_range(day(6), day(0), substr(day(0), 0, 4), $healthCopy['history'])
    && $weeks['points'][0]['label'] === hydrate_health_history_range(day(89), day(84), substr(day(0), 0, 4), $healthCopy['history']),
    json_encode(array_column($weeks['points'], 'label')));
$ok = true;
foreach (range(0, 11) as $w) {
    $to = 7 * (11 - $w);   // days ago at the week's end: the last week ends today
    foreach (array_keys($scores) as $k => $id) {
        if ($weeks['points'][$w + 1]['values'][$k] !== mean_of($scores[$id], $to, $to + 6)) { $ok = false; }
    }
}
check('  each week the mean of its seven days\' scores, rounded', $ok);
check('  that it is a mean, in its reading', $weeks['points'][5]['detail'] === 'weekgemiddelde');
check('  each in the middle of its days, in time: a week apart, 7/89 of the width',
    abs($weeks['chart']['x'][6] - $weeks['chart']['x'][5] - 700 / 89) < 0.02 && abs($weeks['chart']['x'][12] - (86 / 89 * 100)) < 0.02);
check('  no day of the Scorekompas\'s list: an older app reads none', $weeks['start'] === count($pages['compass']['trend']['days']));
check('  dated over its 90 days, today by name', end($weeks['axis'])['label'] === 'Vandaag' && $weeks['axis'][0]['label'] === score_compass_date(day(89), true));

check('a year of history: twelve months over 365 days', $year['group'] === 'month' && count($year['points']) === 12);
$ok = true;
foreach (range(0, 11) as $m) {
    [$from, $to] = [(int) round($m * 365 / 12), (int) round(($m + 1) * 365 / 12) - 1];
    foreach (array_keys($scores) as $k => $id) {
        if ($year['points'][$m]['values'][$k] !== mean_of($scores[$id], 364 - $to, 364 - $from)) { $ok = false; }
    }
}
check('  each the mean of its days\' scores — today\'s none yet among them', $ok);
check('  Training only where it was recorded: none in its first months, never a 0',
    $year['points'][0]['values'][2] === null && $year['points'][11]['values'][2] !== null);

$u = account();
for ($ago = 100; $ago >= 1; $ago--) {
    snapshot($u, $ago, array_filter(array_map(static fn ($f) => ($v = $f($ago)) === null ? null : [$v, 0], $scores)));
}
$pages = pages($u);
$half  = period($pages, '365');
$list  = $pages['compass']['trend']['days'];
check('younger than half a year: twelve half months from its first day, filled as far as today',
    $half['group'] === 'half' && count($half['points']) === 7 && $half['axis'][0]['label'] === score_compass_date($list[0]['date'], true),
    count($half['points']) . ' ' . json_encode($half['axis']));
check('  nothing ahead of today: the last point is today\'s half month, before the middle of the width',
    end($half['chart']['x']) < 56 && end($half['chart']['x']) > 50, (string) end($half['chart']['x']));
check('  the timeline is the half year, its far end a date to come',
    end($half['axis'])['label'] === score_compass_date_in(
        (new DateTimeImmutable($list[0]['date']))->modify('+182 days')->format('Y-m-d'), substr(day(0), 0, 4), true));

$u = account();
for ($ago = 20; $ago >= 1; $ago--) {
    snapshot($u, $ago, ['sleep' => [70, 0], 'nutrition' => [72, 0]]);
}
$pages = pages($u);
check('20 days in 90: three weeks at the right, where they fall — never stretched over the width',
    count(period($pages, '90')['points']) === 3 && period($pages, '90')['chart']['x'][0] > 75);

section('The Scorekompas\'s line: the Health Score, drawn the same way');

/** The Scorekompas as score_compass() leaves it: the sentences, before any line is drawn. */
function bare_compass(int $userId): array
{
    global $compassCopy, $healthCopy;

    $areas = $healthCopy['areas'];
    $data  = ['scores' => ['contributors' => array_map(
        static fn ($id) => ['area' => $id, 'label' => $areas[$id]['label'], 'accent' => $areas[$id]['accent']], array_keys($areas)
    )], 'health' => ['areas' => $areas]];

    return score_compass(health_score_history($userId, 365), $compassCopy, hydrate_compass_areas($data));
}

$u = account();
for ($ago = 380; $ago >= 1; $ago--) {
    snapshot($u, $ago, array_filter(array_map(static fn ($f) => ($v = $f($ago)) === null ? null : [$v, 0], $scores)));
}
$pages   = pages($u);
$cp      = array_column($pages['compass']['trend']['periods'], null, 'key');
$list    = $pages['compass']['trend']['days'];
$bare    = array_column(bare_compass($u)['trend']['periods'], null, 'key');
$verloop = array_column($pages['history']['periods'], null, 'key');

check('a day, a day, a week, a month — as the Verloop has them',
    array_column($cp, 'group') === ['day', 'day', 'week', 'month'] && array_column($cp, 'group') === array_column($verloop, 'group'));
check('  the same places in time, and the same dates under them',
    array_map(static fn ($p) => array_column($p['chart']['at'], 0), array_values($cp)) === array_column(array_column($verloop, 'chart'), 'x')
    && array_column(array_slice($cp, 2), 'axis') === array_column(array_slice($verloop, 2), 'axis'));
$ok = true;
foreach ($cp['90']['points'] as $w => $point) {
    [$from, $to] = [max(0, 89 - 7 * (12 - $w) - 6), 89 - 7 * (12 - $w)];
    $values = array_values(array_filter(array_map(
        static fn ($d) => $list[count($list) - 90 + $d]['value'] ?? null, range($from, $to)
    ), static fn ($v) => $v !== null));
    $mean = $values === [] ? null : (int) round(array_sum($values) / count($values));
    if ($point['value'] !== $mean || score_at($cp['90'], $cp['90']['chart']['at'][$w][1]) !== $mean) { $ok = false; }
    if (array_column($point['categories'], 'value') !== $verloop['90']['points'][$w]['values']) { $ok = false; }
}
check('  each week the mean of its days\' Health Scores, on the line; its categories the Verloop\'s means', $ok);
check('  its reading: its days, that it is a mean, no parts',
    $cp['90']['points'][12]['label'] === $verloop['90']['points'][12]['label'] && $cp['90']['points'][12]['detail'] === 'weekgemiddelde'
    && array_filter(array_column($cp['90']['points'][12]['categories'], 'parts')) === []);
check('  7 and 30 days: the Scorekompas\'s own days, as before',
    $cp['30']['start'] === $bare['30']['start'] && array_column($cp['30']['points'], 'date') === array_column(array_slice($list, $bare['30']['start'], 30), 'date'));
check('  weeks and months point past the list: an older app reads no day there',
    $cp['90']['start'] === count($list) && $cp['365']['start'] === count($list));
check('  the dots: every day of a week, every week and month — a month\'s days as before',
    array_column($cp, 'day_dots') === [true, false, true, true]);
check('  spoken per week and per month, with its direction',
    str_starts_with($cp['90']['aria'], 'Je Gezondheidsscore per week, de afgelopen 90 dagen')
    && str_starts_with($cp['365']['aria'], 'Je Gezondheidsscore per maand, het afgelopen jaar'));
check('  no wash under a line that does not start at 0', array_merge(...array_column(array_column($cp, 'chart'), 'area')) === []);
$ok = true;
foreach ($cp as $p) {
    foreach ($p['chart']['line'] as $path) { if (!within($path)) { $ok = false; } }
}
check('  a monotone curve: no peak that is not there', $ok);
$same = true;
foreach ($bare as $key => $p) {
    foreach (['key', 'label', 'days', 'state', 'direction', 'text', 'empty', 'since', 'values'] as $field) {
        if ($cp[$key][$field] !== $p[$field]) { $same = false; }
    }
}
check('everything else as it was: sentences, direction, since, the score\'s values', $same);
check('  the 30 days an older app draws: still 0–100, a point a day',
    count($pages['compass']['trend']['chart']['at']) === count($pages['compass']['trend']['values']));

$u = account();
for ($ago = 20; $ago >= 1; $ago--) {
    snapshot($u, $ago, ['sleep' => [70, 0], 'nutrition' => [72, 0]]);
}
$cp = array_column(pages($u)['compass']['trend']['periods'], null, 'key');
check('20 days: three weeks at the right, and a half year from the first day',
    count($cp['90']['points']) === 3 && $cp['90']['chart']['at'][0][0] > 75 && $cp['365']['group'] === 'half' && count($cp['365']['points']) === 2);

section('The height: honest, and readable');

check('73 and 80: 60–90, a rise of 7 is 23% of the height', hydrate_health_history_range_of([73, 80]) === [60, 90]);
check('76 and 77: still 30 points tall, one point stays small', hydrate_health_history_range_of([76, 77]) === [60, 90]);
check('40 to 95: what it spans, in tens', hydrate_health_history_range_of([40, 95]) === [30, 100]);
check('a 0 and a 100: the whole scale', hydrate_health_history_range_of([0, 100]) === [0, 100]);
check('near the top: never past 100', hydrate_health_history_range_of([97, 99]) === [70, 100]);
check('near the bottom: never under 0', hydrate_health_history_range_of([2, 3]) === [0, 30]);
check('no score: 0–100', hydrate_health_history_range_of([]) === [0, 100]);

$chart = hydrate_health_history_chart([0.0, 50.0, 100.0], [['values' => [73]], ['values' => [80]], ['values' => [76]]], ['sleep' => 'sleep']);
$rise  = ($chart['lines'][0]['y'][0] - $chart['lines'][0]['y'][1]) / 100 * $chart['height'];
check('  73 → 80 rises ' . round($rise) . ' of 160 in the box', $rise > 25 && $rise < 35);
check('  its levels named, in tens: 70, 80', array_column($chart['grid'], 'label') === ['70', '80']);

$ok = true;
foreach ([...$pages['history']['periods'], ...pages($accounts[0])['history']['periods']] as $p) {
    foreach ($p['chart']['lines'] as $l) {
        foreach ($l['line'] as $path) {
            if (!within($path)) { $ok = false; }
        }
    }
}
check('every curve stays between the points it joins: no peak that is not there', $ok);

section('A new account');

$u = account(2);
$pages = pages($u);
check('nothing to draw in any period', array_filter(array_column(array_column($pages['history']['periods'], 'chart'), 'has_data')) === []);
check('  and it says why', $pages['history']['empty'] === 'Zodra er meetmomenten zijn, verschijnt hier je verloop.');

section('A deploy that brought the code before the copy');

$bare = hydrate_health_history($pages['compass']['trend'], $compassCopy, [], $healthCopy['areas']);
check('no copy yet: the history is still built, without words', count($bare['periods']) === 4 && $bare['title'] === '');

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
