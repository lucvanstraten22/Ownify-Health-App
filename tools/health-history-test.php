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
 *     categories' own colours, the same windows over the same days
 *   - every point is that category's recorded score that day — nothing
 *     scored again — and a real 0 is a point at the bottom
 *   - a day without new input carries a category's last score for as long
 *     as it held (valid_until): Slaap 82, 82, (nothing new), 82 is one
 *     unbroken line at 82; after that it has none — a gap, never a 0
 *   - a category without a score on a day the others have one is a gap in
 *     its own line only
 *   - a week names every day, today by its date; longer periods are dated
 *     as the Scorekompas dates them; every day of a week and a month is a dot
 *   - the Scorekompas's own history is the same with or without it
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

/** A category's score as its point says it: the 0-100 scale of the 300 × 120 box. */
function score_at(?float $y): ?int
{
    return $y === null ? null : (int) round(100 - ($y - 10) / 0.8);
}

/** One category's points in a period, by date: date => score read off the line. */
function line(array $pages, string $period, string $id): array
{
    foreach ($pages['history']['periods'] as $p) {
        if ($p['key'] !== $period) {
            continue;
        }
        $line  = array_column($p['chart']['lines'], null, 'id')[$id];
        $dates = array_column(array_slice($pages['compass']['trend']['days'], $p['start'], count($p['chart']['x'])), 'date');

        return array_combine($dates, array_map('score_at', $line['y']));
    }

    return [];
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
check('  over the same windows: each starts where the Scorekompas\'s does',
    array_column($history['periods'], 'start') === array_column($trend['periods'], 'start'));
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
check('  longer periods are dated as the Scorekompas dates them',
    array_column(array_slice($history['periods'], 1), 'axis') === array_column(array_slice($trend['periods'], 1), 'axis'));
check('  every day of a week and a month is a dot; 90 days and a year only a day on its own',
    array_column($history['periods'], 'dots') === ['every', 'every', 'alone', 'alone']);
check('  spoken: the three, per day, over the period',
    $week['aria'] === 'Slaap, Voeding en Training per dag, de afgelopen 7 dagen');

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
    foreach ($p['chart']['lines'] as $l) {
        foreach ($l['y'] as $i => $y) {
            $day   = $pages['compass']['trend']['days'][$p['start'] + $i];
            $value = array_column($day['categories'], 'value', 'id')[$l['id']] ?? null;
            if (($value === null) !== ($y === null) || ($value !== null && score_at($y) !== $value)) { $zeros++; }
        }
    }
}
check('no missing day is drawn — every point is a score, every gap a day without one', $zeros === 0, "$zeros wrong points");

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
