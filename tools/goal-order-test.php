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
expect('no goal marked primary (it was completed): the first of Secundaire doelen takes the slot, as after a delete',
    [goal('eerste', 10, 30), goal('verder', 90, 30), goal('midden', 50, 30)],
    'verder', 'midden, eerste');

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

echo "\nCompleting the primary goal: the same goal takes its place as after a delete\n";

/**
 * One board, twice: the primary goal deleted (goals_successor() on the board
 * before) and completed (the board after, as goals_prepare() fills the empty
 * slot — the goal goal_ensure_primary_after_completion() stores).
 */
function both_ways(array $secondary): array
{
    $main    = goal('Hoofddoel', 50, 20, 'primary');
    $deleted = successor(array_merge([$main], $secondary));
    $done    = ['status' => 'completed', 'completed_days_ago' => 0, 'percent' => 100] + $main;
    $after   = board(array_merge([$done], $secondary));

    return [$deleted, $after['primary']['name'] ?? '(none)', names($after['completed'])];
}

foreach ([
    '82%, 64%, 41% (41% the oldest)'          => [[goal('41%', 41, 10), goal('64%', 64, 10), goal('82%', 82, 10)], '82%'],
    'equal percentages: the one ending sooner' => [[goal('60% tot 25 okt', 60, 24), goal('60% tot 12 okt', 60, 11)], '60% tot 12 okt'],
    'only goals without data: soonest first'   => [[goal('geen data, 30 okt', null, 29), goal('geen data, 12 okt', null, 11)], 'geen data, 12 okt'],
    'a real 0% before no data'                 => [[goal('geen data', null, 1), goal('0%', 0, 60)], '0%'],
    'a running goal before a paused one'       => [[goal('gepauzeerd 90%', 90, 10, 'secondary', 'paused'), goal('20%', 20, 10)], '20%'],
    'only paused goals: the first of them'     => [[goal('gepauzeerd A', 90, 10, 'secondary', 'paused'), goal('gepauzeerd B', 10, 5, 'secondary', 'paused')], 'gepauzeerd A'],
    'one secondary goal'                       => [[goal('enige', null, 30)], 'enige'],
    'no secondary goal: none'                  => [[], '(none)'],
] as $label => [$secondary, $expected]) {
    [$deleted, $completed, $history] = both_ways($secondary);
    check("$label: $expected, deleted or completed", $deleted === $expected && $completed === $expected,
        "deleted → $deleted, completed → $completed");
    check('  and the completed goal is under Behaald', $history === 'Hoofddoel', $history);
}

echo "\nAanpassen — Primair | Secundair\n";

/** goal_set_primary(): the goal takes the primary slot and the goal that had it becomes secondary; the board orders the rest. */
function made_primary(array $goals, string $name): array
{
    return array_map(static function (array $g) use ($name): array {
        if ($g['name'] === $name) {
            return ['priority' => 'primary'] + $g;
        }

        return $g['priority'] === 'primary' ? ['priority' => 'secondary'] + $g : $g;
    }, $goals);
}

/**
 * goal_set_secondary(): the goal a delete would hand the slot to
 * (goals_successor() on the board as it is) is made primary, and with that the
 * old primary goal is secondary. Null when no goal is left to take the slot.
 */
function made_secondary(array $goals, string $name, ?string $shown = null): ?array
{
    $b = board($goals);
    if ((string) ($b['primary']['id'] ?? '') !== id_of($goals, $name)) {
        return $goals;                          // secondary already: nothing changes
    }

    $heir = goals_successor($b, id_of($goals, $name), $shown);
    foreach ($goals as $g) {
        if ($g['id'] === $heir) {
            return made_primary($goals, $g['name']);
        }
    }

    return null;
}

$A = static fn (?int $p, ?int $ends = 20, string $prio = 'secondary', string $status = 'active'): array => goal('A', $p, $ends, $prio, $status);

/* 8 — secondary → Primair: B takes the slot, A falls in by the order. (The
   brief's example lists "A — 50%, C — 64%"; the order it asks to keep puts
   64% first, so that is what is checked.) */
$g = [$A(50, 20, 'primary'), goal('B', 82, 20), goal('C', 64, 20)];
expect('B (82%) → Primair: B primary, A (50%) under C (64%)', made_primary($g, 'B'), 'B', 'C, A');

/* 9 — primary → Secundair, another goal further along. */
expect('A (50%) → Secundair: B (82%) primary, then C (64%), A (50%)', made_secondary($g, 'A'), 'B', 'C, A');

/* 10 — primary → Secundair while it is the furthest along itself. */
$g = [$A(82, 20, 'primary'), goal('B', 64, 20), goal('C', 41, 20)];
expect('A (82%, the highest) → Secundair: B (64%) primary, A first of the secondary goals', made_secondary($g, 'A'), 'B', 'A, C');

/* Equal percentages: the one that ends sooner takes the slot. */
$g = [$A(70, 20, 'primary'), goal('B', 60, 30), goal('C', 60, 10)];
expect('equal 60%: C (ends sooner) primary; A (70%) first, then B', made_secondary($g, 'A'), 'C', 'A, B');

/* Goals without a percentage come after every goal with one. */
$g = [$A(50, 20, 'primary'), goal('B', null, 5), goal('C', 10, 40)];
expect('no-data B ends sooner, but C has 10%: C primary; A, then B', made_secondary($g, 'A'), 'C', 'A, B');
$g = [$A(40, 20, 'primary'), goal('B', null, 30), goal('C', null, null), goal('D', null, 10)];
expect('only no-data goals left: D (ends soonest) primary; A (40%), B, then C (no end date)', made_secondary($g, 'A'), 'D', 'A, B, C');

/* The old primary goal placed by the end-date rules among equals. */
$g = [$A(60, 5, 'primary'), goal('B', 60, 20), goal('C', 60, 3)];
expect('all 60%: C (ends in 3) primary; A (ends in 5) before B (20)', made_secondary($g, 'A'), 'C', 'A, B');
$g = [$A(60, null, 'primary'), goal('B', 60, 20), goal('C', 60, 3)];
expect('all 60%, A without an end date: C primary; B, then A last', made_secondary($g, 'A'), 'C', 'B, A');

/* A real 0% is a percentage. */
$g = [$A(30, 20, 'primary'), goal('nul', 0, 90), goal('leeg', null, 1)];
expect('0% before no data: "nul" primary', made_secondary($g, 'A'), 'nul', 'A, leeg');

/* Paused goals: a running one first, as a delete chooses. */
$g = [$A(50, 20, 'primary'), goal('gepauzeerd', 90, 10, 'secondary', 'paused'), goal('loopt', 20, 10)];
expect('a running goal before a paused one: "loopt" primary', made_secondary($g, 'A'), 'loopt', 'A, gepauzeerd');

/* The goal moved up on the page is the one kept, as with a delete. */
$g = [$A(50, 20, 'primary'), goal('B', 82, 20), goal('C', 64, 20)];
expect('the page moved C up (a page from before a sync): C is kept', made_secondary($g, 'A', id_of($g, 'C')), 'C', 'B, A');

/* The same goal as a delete or a completion would choose. */
foreach ([
    [[$A(50, 20, 'primary'), goal('B', 82, 20), goal('C', 64, 20)], 'B'],
    [[$A(82, 20, 'primary'), goal('B', 64, 20), goal('C', 41, 20)], 'B'],
    [[$A(40, 20, 'primary'), goal('B', null, 30), goal('D', null, 10)], 'D'],
] as [$g, $heir]) {
    $deleted = successor($g);
    $done    = board(array_map(static fn (array $x): array => $x['name'] === 'A'
        ? ['status' => 'completed', 'completed_days_ago' => 0] + $x : $x, $g))['primary']['name'] ?? '';
    $demoted = board(made_secondary($g, 'A'))['primary']['name'] ?? '';
    check("Secundair, delete and completion hand the slot to the same goal ($heir)",
        $demoted === $heir && $deleted === $heir && $done === $heir, "secundair $demoted, delete $deleted, completed $done");
}

/* Nothing to hand it to. */
check('the only goal → Secundair: refused, it stays primary', made_secondary([$A(50, 20, 'primary')], 'A') === null);
$g = [$A(50, 20, 'primary'), goal('B', 82, 20)];
expect('a secondary goal → Secundair: nothing changes', made_secondary($g, 'B'), 'A', 'B');
expect('the primary goal → Primair: nothing changes', made_primary($g, 'A'), 'A', 'B');

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
