<?php
/**
 * The Health Score's stored history, against a real database: what
 * daily_scores keeps each day and what health_score_history() and the
 * Scorekompas read back from it.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/score-history-test.php
 *
 * Needs migration 017 (daily_scores.valid_until). It checks:
 *
 *   - one snapshot per person per day per category: calculating again the
 *     same day updates that day's rows, never adds rows (the primary key)
 *   - a day that has passed is never written again, whatever comes in later,
 *     and the history shows it as it was recorded, not recalculated
 *   - a day without a snapshot keeps the last one only while it holds
 *     (valid_until), each category on its own, the overall score combined
 *     again from the categories that still hold; after that it has none
 *   - rows from before this version (no valid_until) are shown as they were
 *     and never carried
 *   - missing input is not zero: no new cijfer for two days keeps the score;
 *     a third day without one leaves Voeding out, stored as null, not 0
 *   - a new account shows its real days only; at most a year is read
 *
 * Accounts are named `shtest_…` and removed at the end, with everything
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
require_once dirname(__DIR__) . '/includes/score-compass.php';

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

/** A new account, made $age days ago. */
function account(int $age = 400): int
{
    global $accounts;

    db_run('INSERT INTO users (username) VALUES (?)', ['shtest_' . bin2hex(random_bytes(4))]);
    $id = (int) db_insert_id();
    db_run('UPDATE users SET created_at = NOW() - INTERVAL ? DAY WHERE id = ?', [$age, $id]);
    $accounts[] = $id;

    return $id;
}

/** Y-m-d, $days before today (after it, when negative). */
function day(int $days): string
{
    return (new DateTimeImmutable('today'))->modify(sprintf('%+d days', -$days))->format('Y-m-d');
}

/** A Voeding cijfer as api/health/rating.php saves it: today's timed now, an earlier day's in its evening. */
function rating(int $userId, int $ago, int $value): void
{
    $date = day($ago);
    db_run(
        'INSERT INTO health_metrics (user_id, metric_type_id, source_id, external_id, value, recorded_at)
              VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), recorded_at = VALUES(recorded_at)',
        [$userId, health_metric_type_id('nutrition_rating'), health_source_id('manual'), 'daily-rating:' . $date,
         $value, $ago === 0 ? date('Y-m-d H:i:s') : $date . ' 20:00:00']
    );
}

/**
 * A day's snapshot as it was written on that day: $scores domain =>
 * [score, valid_until], an absent domain without a score.
 */
function snapshot(int $userId, int $ago, array $scores, string $version = HEALTH_SCORE_VERSION): void
{
    foreach (['overall', 'sleep', 'nutrition', 'training'] as $domain) {
        [$score, $until] = $scores[$domain] ?? [null, null];
        db_run(
            'INSERT INTO daily_scores (user_id, score_date, domain, score, data_days, inputs, valid_until, algorithm_version)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, day($ago), $domain, $score, $domain === 'overall' || $score === null ? null : 3,
             $domain === 'sleep' && $score !== null ? json_encode(['duration' => 72.0, 'regularity' => 60.0, 'quality' => null]) : null,
             $until === null ? null : day($until), $version]
        );
    }
}

/** daily_scores of one day: domain => score. */
function stored(int $userId, int $ago): array
{
    $rows = [];
    foreach (db_all('SELECT domain, score FROM daily_scores WHERE user_id = ? AND score_date = ?', [$userId, day($ago)]) as $row) {
        $rows[(string) $row['domain']] = $row['score'] === null ? null : (int) $row['score'];
    }
    ksort($rows);

    return $rows;
}

$compassCopy = require dirname(__DIR__) . '/config/compass.php';
$areas = [
    'sleep'     => ['label' => 'Slaap',   'accent' => 'sleep',     'icon' => 'moon',     'empty' => '', 'collecting' => ''],
    'nutrition' => ['label' => 'Voeding', 'accent' => 'nutrition', 'icon' => 'utensils', 'empty' => '', 'collecting' => ''],
    'training'  => ['label' => 'Sport',   'accent' => 'training',  'icon' => 'dumbbell', 'empty' => '', 'collecting' => ''],
];

/* ======================================================================
   ONE SNAPSHOT A DAY
   ====================================================================== */

section('One snapshot per day: calculating again updates it');

$u = account();
rating($u, 2, 8);
rating($u, 1, 8);
rating($u, 0, 8);

$first = health_score_refresh($u);
$rows  = stored($u, 0);
check('today is recorded: one row per category and the overall score', array_keys($rows) === ['nutrition', 'overall', 'sleep', 'training'],
    json_encode($rows));
check('  Voeding from three cijfers; Slaap and Sport without data are null, not 0',
    $rows['nutrition'] === $first['nutrition']['score'] && $rows['nutrition'] !== null
    && $rows['sleep'] === null && $rows['training'] === null);
check('  the overall score is Voeding alone', $rows['overall'] === $rows['nutrition']);
check('  with the day it holds until without new input',
    db_value('SELECT valid_until FROM daily_scores WHERE user_id = ? AND score_date = ? AND domain = ?', [$u, day(0), 'nutrition'])
    === $first['nutrition']['valid_until'] && $first['nutrition']['valid_until'] !== null);
check('  and the version that calculated it',
    db_value('SELECT COUNT(*) FROM daily_scores WHERE user_id = ? AND algorithm_version = ?', [$u, HEALTH_SCORE_VERSION]) == 4);

health_score_refresh($u);
health_score_refresh($u);
check('calculating again, unchanged: still four rows', (int) db_value('SELECT COUNT(*) FROM daily_scores WHERE user_id = ?', [$u]) === 4);

rating($u, 0, 3);
$second = health_score_refresh($u);
$rows   = stored($u, 0);
check('a lower cijfer today: the same rows, updated',
    (int) db_value('SELECT COUNT(*) FROM daily_scores WHERE user_id = ?', [$u]) === 4
    && $rows['nutrition'] === $second['nutrition']['score'] && $second['nutrition']['score'] < $first['nutrition']['score'],
    json_encode([$first['nutrition']['score'], $second['nutrition']['score'], $rows]));

$duplicate = false;
try {
    db_run('INSERT INTO daily_scores (user_id, score_date, domain, score) VALUES (?, ?, ?, ?)', [$u, day(0), 'overall', 50]);
} catch (PDOException) {
    $duplicate = true;
}
check('a second row for the same day and category is refused by the table itself', $duplicate);

/* ======================================================================
   A DAY THAT HAS PASSED STAYS AS IT WAS
   ====================================================================== */

section('Immutable: a past day is never scored again');

$u = account();
/* Yesterday it was recorded at 64 for Voeding, holding until tomorrow. */
snapshot($u, 1, ['overall' => [64, -1], 'nutrition' => [64, -1]]);
rating($u, 3, 9);
rating($u, 2, 9);
rating($u, 1, 9);

$yesterday = health_score_now($u, new DateTimeImmutable(day(1) . ' 22:00:00'));
check('yesterday, calculated again from today\'s records, would be different', $yesterday['nutrition']['score'] !== 64,
    (string) $yesterday['nutrition']['score']);

health_score_store($u, $yesterday);
check('  storing it changes nothing: a passed day is not written',
    stored($u, 1) === ['nutrition' => 64, 'overall' => 64, 'sleep' => null, 'training' => null], json_encode(stored($u, 1)));

$history = health_score_history($u, 7);
check('  the history shows yesterday as recorded',
    $history[day(1)]['state'] === 'stored' && $history[day(1)]['nutrition']['score'] === 64 && $history[day(1)]['overall']['score'] === 64);
check('  and today as it stands now', $history[day(0)]['state'] === 'today'
    && $history[day(0)]['nutrition']['score'] === health_score_now($u)['nutrition']['score']);
check('  asked for that day by date, it is the recorded score too', score_domains($u, day(1))['nutrition'] === 64
    && score_overall($u, day(1)) === 64);
check('  the days before any record have none, never a 0',
    $history[day(6)]['state'] === 'none' && $history[day(6)]['overall']['score'] === null);

/* ======================================================================
   CARRIED WHILE IT HOLDS, THEN GONE
   ====================================================================== */

section('Days without a snapshot: carried only while it holds');

$u = account();
/* Ten days ago: Slaap until 8 days ago, Voeding until 9, Sport until 6. */
snapshot($u, 10, [
    'overall'   => [70, 6],
    'sleep'     => [70, 8],
    'nutrition' => [60, 9],
    'training'  => [80, 6],
]);

$history = health_score_history($u, 14);
$at      = static fn (int $ago): array => $history[day($ago)];

check('the recorded day', $at(10)['state'] === 'stored' && $at(10)['overall']['score'] === 70);
check('the day after: all three still hold, the same score',
    $at(9)['state'] === 'carried' && $at(9)['from'] === day(10) && $at(9)['overall']['score'] === 70);
check('Voeding stops after its day: the overall is Slaap and Sport again (75), not a 0 for Voeding',
    $at(8)['nutrition']['score'] === null && $at(8)['sleep']['score'] === 70 && $at(8)['overall']['score'] === 75);
check('then Sport alone (80)', $at(7)['overall']['score'] === 80 && $at(7)['sleep']['score'] === null
    && $at(6)['overall']['score'] === 80);
check('after the last one stops: no score at all', $at(5)['state'] === 'none' && $at(5)['overall']['score'] === null
    && $at(1)['overall']['score'] === null);
check('a carried category keeps its parts', $at(9)['sleep']['components'] == ['duration' => 72, 'regularity' => 60, 'quality' => null]);

/* A new snapshot takes over from the old one. */
snapshot($u, 4, ['overall' => [55, 2], 'nutrition' => [55, 2]]);
$history = health_score_history($u, 14);
check('a newer snapshot is carried instead, by its own days',
    $history[day(3)]['from'] === day(4) && $history[day(3)]['overall']['score'] === 55
    && $history[day(2)]['overall']['score'] === 55 && $history[day(1)]['state'] === 'none');

section('Rows from before this version are kept, never carried');

$u = account();
snapshot($u, 6, ['overall' => [71, null], 'sleep' => [71, null]], 'rolling90-v1');
$history = health_score_history($u, 7);
check('the old day is shown as it was recorded', $history[day(6)]['state'] === 'stored' && $history[day(6)]['overall']['score'] === 71);
check('  and not carried: nothing says how long it held', $history[day(5)]['state'] === 'none' && $history[day(5)]['overall']['score'] === null);

/* ======================================================================
   MISSING INPUT IS NOT ZERO; THREE DAYS WITHOUT IT, LEFT OUT
   ====================================================================== */

section('No new cijfer: the score holds two days, then stops counting');

$u = account();
rating($u, 4, 7);
rating($u, 3, 7);
rating($u, 2, 7);
$now = health_score_refresh($u);
check('last cijfer two days ago: Voeding still counts', $now['nutrition']['score'] !== null && !$now['nutrition']['expired']
    && $now['overall']['score'] === $now['nutrition']['score']);

$u = account();
rating($u, 5, 7);
rating($u, 4, 7);
rating($u, 3, 7);
$now  = health_score_refresh($u);
$rows = stored($u, 0);
check('last cijfer three days ago: Voeding no longer counts', $now['nutrition']['score'] === null && $now['nutrition']['expired']
    && $now['nutrition']['last_input'] === day(3));
check('  stored as no score — null, never 0 — and the overall with it', $rows['nutrition'] === null && $rows['overall'] === null,
    json_encode($rows));

rating($u, 0, 7);
$now = health_score_refresh($u);
check('a new cijfer today brings it back at once', $now['nutrition']['score'] !== null && stored($u, 0)['nutrition'] === $now['nutrition']['score']);

/* ======================================================================
   A NEW ACCOUNT, AND A YEAR AT MOST
   ====================================================================== */

section('A new account shows its real days only');

$u = account(18);
for ($ago = 17; $ago >= 1; $ago--) {
    snapshot($u, $ago, ['overall' => [60 + $ago % 5, $ago - 1], 'nutrition' => [60 + $ago % 5, $ago - 1]]);
}
rating($u, 2, 7);
rating($u, 1, 7);
rating($u, 0, 7);

$history = health_score_history($u, 365);
check('a year asked: 365 days read, today last', count($history) === 365 && array_key_last($history) === day(0));
check('  the days before the account have none', $history[day(18)]['overall']['score'] === null && $history[day(200)]['state'] === 'none');

$compass = score_compass($history, $compassCopy, $areas);
$periods = array_column($compass['trend']['periods'], null, 'days');
check('the Scorekompas lists 18 days, not 365', count($compass['trend']['days']) === 18 && $compass['trend']['days'][0]['date'] === day(17),
    (string) count($compass['trend']['days']));
check('  the year\'s line is those 18 days, with where the history begins',
    count($periods[365]['values']) === 18 && $periods[365]['since'] !== null && !in_array(0, $periods[365]['values'], true));
check('  the week is the last 7, no note needed', count($periods[7]['values']) === 7 && $periods[7]['since'] === null
    && $periods[7]['start'] === 11);

section('At most a year is read');

$u = account();
snapshot($u, 400, ['overall' => [90, 399], 'sleep' => [90, 399]]);
snapshot($u, 364, ['overall' => [50, 364], 'sleep' => [50, 364]]);
$history = health_score_history($u, 365);
check('a snapshot of 400 days ago is not in the year', !isset($history[day(400)]) && array_key_first($history) === day(364));
check('  the year starts with what was recorded 364 days ago', $history[day(364)]['overall']['score'] === 50);
check('  and nothing is deleted', (int) db_value('SELECT COUNT(*) FROM daily_scores WHERE user_id = ? AND score_date = ?', [$u, day(400)]) === 4);

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
