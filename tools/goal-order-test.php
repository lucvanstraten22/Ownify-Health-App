<?php
/**
 * The order of the goals board, tested without a database.
 *
 *     php tools/goal-order-test.php
 *
 * goals_prepare() in lib/goals.php decides the order for the website and the
 * app alike (the app shows the order api/app/state.php sends). The primary
 * goal keeps its slot; the running secondary goals — Secundaire doelen — go
 * highest percentage first, goals without a percentage after them, ties to
 * the soonest end date, and anything still equal keeps the order it came in.
 * Paused goals stay after the running ones, as before. The goals here are
 * made up in memory.
 *
 * Exit code 0 when every check passes.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/lib/render.php';
require_once dirname(__DIR__) . '/lib/goals.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? '  — ' . $detail : '') . "\n";
}

/** A goal as lib/hydrate-goals.php hands it over: only what the order reads, and a name to recognise it by. */
function goal(string $name, ?int $percent, ?int $endsInDays, string $priority = 'secondary', string $status = 'active'): array
{
    static $id = 0;

    return [
        'id'               => (string) ++$id,
        'name'             => $name,
        'category'         => 'activity',
        'type'             => 'milestone',
        'priority'         => $priority,
        'status'           => $status,
        'percent'          => $percent,
        'ends_in_days'     => $endsInDays,
        'started_days_ago' => 10,
    ];
}

/** The board for these goals, in the order the database returned them. */
function board(array $goals): array
{
    $config          = require dirname(__DIR__) . '/config/goals.php';
    $config['goals'] = $goals;

    return goals_prepare($config, new DateTimeImmutable('2026-10-01'));
}

function names(array $goals): string
{
    return implode(', ', array_map(static fn (array $g): string => $g['name'], $goals));
}

function expect(string $label, array $goals, string $primary, string $secondary): void
{
    $b = board($goals);
    $gotPrimary   = $b['primary']['name'] ?? '';
    $gotSecondary = names($b['secondary']);
    check($label, $gotPrimary === $primary && $gotSecondary === $secondary,
        "primary [$gotPrimary], secondary [$gotSecondary]" . ($gotSecondary === $secondary ? '' : " — expected [$secondary]"));
}

$main = static fn (): array => goal('Hoofddoel', 50, 20, 'primary');

echo "The section\n";
$config = require dirname(__DIR__) . '/config/goals.php';
check('the section is called "Secundaire doelen"', $config['labels']['secondary'] === 'Secundaire doelen', $config['labels']['secondary']);

echo "\nRule 1 — the highest percentage first\n";
expect('82%, 64%, 41%, 18%, whatever order they arrive in',
    [goal('18', 18, 10), goal('82', 82, 40), $main(), goal('41', 41, 5), goal('64', 64, 30)],
    'Hoofddoel', '82, 64, 41, 18');

echo "\nRule 3 — equal percentages: the one that ends soonest first\n";
/* 1 October + 11, 24 and 40 days: 12 October, 25 October, 10 November. */
expect('60% to 10 November, 12 October, 25 October',
    [$main(), goal('10 nov', 60, 40), goal('12 okt', 60, 11), goal('25 okt', 60, 24)],
    'Hoofddoel', '12 okt, 25 okt, 10 nov');
expect('an equal percentage without an end date comes after those with one',
    [$main(), goal('geen einddatum', 60, null), goal('12 okt', 60, 11)],
    'Hoofddoel', '12 okt, geen einddatum');

echo "\nRule 2 — no percentage goes below every percentage, and 0% is a percentage\n";
expect('a real 0% comes before a goal with no percentage',
    [$main(), goal('geen data', null, 1), goal('0%', 0, 30)],
    'Hoofddoel', '0%, geen data');
expect('no percentage is not read as 0%: it stays below 0% even when it ends sooner',
    [$main(), goal('geen data', null, 2), goal('0% later', 0, 60), goal('3%', 3, 90)],
    'Hoofddoel', '3%, 0% later, geen data');

echo "\nRule 4 — several without a percentage: the one that ends soonest first\n";
/* 12 October, 30 October, 15 November. */
expect('no data to 30 October, 15 November, 12 October',
    [$main(), goal('30 okt', null, 29), goal('15 nov', null, 45), goal('12 okt', null, 11)],
    'Hoofddoel', '12 okt, 30 okt, 15 nov');

echo "\nAll rules together\n";
expect('mixed percentages and no percentages',
    [goal('geen data, 5d', null, 5), goal('10%', 10, 40), $main(), goal('geen data, 2d', null, 2),
     goal('90%', 90, 50), goal('0%', 0, 1), goal('10%, eerder', 10, 7)],
    'Hoofddoel', '90%, 10%, eerder, 10%, 0%, geen data, 2d, geen data, 5d');

echo "\nThe primary goal keeps its slot\n";
expect('a primary at 5% stays primary above secondaries at 90% and 70%',
    [goal('70%', 70, 10), goal('Hoofddoel 5%', 5, 30, 'primary'), goal('90%', 90, 10)],
    'Hoofddoel 5%', '90%, 70%');
expect('a primary with no percentage stays primary',
    [goal('40%', 40, 10), goal('Hoofddoel zonder data', null, 30, 'primary')],
    'Hoofddoel zonder data', '40%');
expect('a paused primary keeps its slot too',
    [goal('40%', 40, 10), goal('Hoofddoel gepauzeerd', 80, 30, 'primary', 'paused')],
    'Hoofddoel gepauzeerd', '40%');
expect('no goal marked primary: the first goal takes the slot as before, not the one furthest along',
    [goal('eerste', 10, 30), goal('verder', 90, 30), goal('midden', 50, 30)],
    'eerste', 'verder, midden');

echo "\nStable: still equal keeps the order it came in\n";
expect('same percentage and same end date: the order they arrived in',
    [$main(), goal('A', 50, 14), goal('B', 50, 14), goal('C', 50, 14)],
    'Hoofddoel', 'A, B, C');
expect('and reversed when they arrive reversed — nothing else decides',
    [$main(), goal('C', 50, 14), goal('B', 50, 14), goal('A', 50, 14)],
    'Hoofddoel', 'C, B, A');
expect('no percentage and the same end date: the order they arrived in',
    [$main(), goal('X', null, 14), goal('Y', null, 14)],
    'Hoofddoel', 'X, Y');
$same  = [$main(), goal('P', 50, 14), goal('Q', 70, 9), goal('R', 50, 14), goal('S', null, 3), goal('T', 50, 14)];
$first = names(board($same)['secondary']);
$again = true;
for ($i = 0; $i < 20; $i++) {
    $again = $again && names(board($same)['secondary']) === $first;
}
check('twenty renders of the same goals: the same order every time', $again, $first);

echo "\nWhat is not sorted\n";
expect('paused secondary goals stay after the running ones, in the order they came in',
    [$main(), goal('gepauzeerd 90%', 90, 10, 'secondary', 'paused'), goal('20%', 20, 10),
     goal('gepauzeerd 10%', 10, 5, 'secondary', 'paused'), goal('60%', 60, 10)],
    'Hoofddoel', '60%, 20%, gepauzeerd 90%, gepauzeerd 10%');
$b = board([$main(), goal('30%', 30, 10), goal('80%', 80, 10)]);
check('the full active list is the primary, then the secondary goals in this order',
    names($b['active']) === 'Hoofddoel, 80%, 30%', names($b['active']));
$done = [
    goal('klaar 3 dagen', 100, null, 'secondary', 'completed') + ['completed_days_ago' => 3],
    goal('klaar gisteren', 100, null, 'secondary', 'completed') + ['completed_days_ago' => 1],
];
check('completed goals keep their own order: most recently finished first',
    names(board(array_merge([$main()], $done))['completed']) === 'klaar gisteren, klaar 3 dagen',
    names(board(array_merge([$main()], $done))['completed']));

echo "\nDeleting the primary goal: the first of Secundaire doelen takes its place\n";

/** goals_successor() on this board, as names: which goal moves up when the primary goal is deleted. */
function successor(array $goals, ?string $shown = null, ?string $deleting = null): string
{
    $b  = board($goals);
    $id = goals_successor($b, $deleting ?? (string) ($b['primary']['id'] ?? ''), $shown);
    foreach ($b['all'] as $g) {
        if ($g['id'] === $id) {
            return $g['name'];
        }
    }

    return $id === null ? '(none)' : "(id $id)";
}

function id_of(array $goals, string $name): string
{
    foreach ($goals as $g) {
        if ($g['name'] === $name) {
            return $g['id'];
        }
    }

    return '';
}

/* The order the goals are created in: the oldest first. The server used to
   hand the place to the oldest goal; it has to be the first one shown. */
$g = [$main(), goal('41%', 41, 10), goal('64%', 64, 10), goal('82%', 82, 10)];
check('82%, 64%, 41% (41% the oldest): 82% becomes primary', successor($g) === '82%', successor($g));
$g = [$main(), goal('60% tot 25 okt', 60, 24), goal('60% tot 12 okt', 60, 11)];
check('equal percentages: the one that ends sooner', successor($g) === '60% tot 12 okt', successor($g));
$g = [$main(), goal('geen data, 30 okt', null, 29), goal('geen data, 15 nov', null, 45), goal('geen data, 12 okt', null, 11)];
check('only goals without a percentage: the one that ends soonest', successor($g) === 'geen data, 12 okt', successor($g));
$g = [$main(), goal('geen data, 1 dag', null, 1), goal('0%', 0, 60)];
check('a real 0% before a goal with no percentage, even one ending sooner', successor($g) === '0%', successor($g));
$g = [$main(), goal('X', 50, 14), goal('Y', 50, 14)];
check('still equal: the one already first', successor($g) === 'X', successor($g));
$g = [$main(), goal('enige', null, 30)];
check('one secondary goal: that one', successor($g) === 'enige', successor($g));
check('no secondary goal: none — no goal is invented', successor([$main()]) === '(none)', successor([$main()]));
$g = [$main(), goal('gepauzeerd 90%', 90, 10, 'secondary', 'paused'), goal('20%', 20, 10)];
check('a running goal before a paused one, even a paused one further along', successor($g) === '20%', successor($g));
$g = [$main(), goal('gepauzeerd A', 90, 10, 'secondary', 'paused'), goal('gepauzeerd B', 10, 5, 'secondary', 'paused')];
check('only paused goals left: the first of them, as the board shows them', successor($g) === 'gepauzeerd A', successor($g));
$g = [$main(), goal('70%', 70, 10), goal('30%', 30, 10)];
check('deleting a secondary goal: nothing moves up', successor($g, null, id_of($g, '30%')) === '(none)', successor($g, null, id_of($g, '30%')));

echo "\n  …and the goal the website or the app moved up is the one kept\n";
$g = [$main(), goal('70%', 70, 10), goal('30%', 30, 10)];
check('the card they showed first, still on the board: kept',
    successor($g, id_of($g, '70%')) === '70%', successor($g, id_of($g, '70%')));
check('a page from before a sync showed 30% first: 30% is kept, as the person saw it',
    successor($g, id_of($g, '30%')) === '30%', successor($g, id_of($g, '30%')));
$g = [$main(), goal('70%', 70, 10), goal('gepauzeerd', 90, 10, 'secondary', 'paused')];
check('a paused goal sent while a running one is left: the running one',
    successor($g, id_of($g, 'gepauzeerd')) === '70%', successor($g, id_of($g, 'gepauzeerd')));
$g = [$main(), goal('70%', 70, 10)];
check('a goal that is not on the board (gone, someone else\'s): the board\'s first',
    successor($g, '999999') === '70%', successor($g, '999999'));
check('the deleted goal itself: the board\'s first',
    successor($g, (string) board($g)['primary']['id']) === '70%', successor($g, (string) board($g)['primary']['id']));
$done = goal('klaar', 100, null, 'secondary', 'completed') + ['completed_days_ago' => 1];
$g = [$main(), goal('70%', 70, 10), $done];
check('a completed goal: the board\'s first', successor($g, $done['id']) === '70%', successor($g, $done['id']));

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
