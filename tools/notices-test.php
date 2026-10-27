<?php
/**
 * The red dots (lib/notices.php, docs/NOTICES.md), tested on their rules.
 *
 *     php tools/notices-test.php
 *
 *   - a goal waits only when it is kept by hand, counts days, runs and is
 *     not ticked today
 *   - every key notice_on() knows, and each parent on while a child waits
 *   - the Voeding dot only from its hour
 *   - notice_dot(): the same element shown or hidden, on a corner or beside
 *
 * Needs no database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Europe/Amsterdam');
require_once dirname(__DIR__) . '/lib/notices.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : '  — ' . $detail) . "\n";
}

$now = new DateTimeImmutable('2026-10-21 21:00');
$goal = [
    'id' => '7', 'is_manual' => true, 'is_completed' => false, 'status' => 'active', 'counts_days' => true,
    'start_date' => '2026-10-16', 'entry' => ['kind' => 'tick', 'done' => false],
];

echo "Goals\n";
check('a daily goal kept by hand, not ticked today, waits', notices_goal_waits($goal, $now));
check('ticked today: no dot', !notices_goal_waits(['entry' => ['kind' => 'tick', 'done' => true]] + $goal, $now));
check('paused: no dot', !notices_goal_waits(['status' => 'paused'] + $goal, $now));
check('finished: no dot', !notices_goal_waits(['is_completed' => true] + $goal, $now));
check('not started yet: no dot', !notices_goal_waits(['start_date' => '2026-10-22'] + $goal, $now));
check('read by itself: no dot', !notices_goal_waits(['is_manual' => false] + $goal, $now));
check('a goal per week: no dot', !notices_goal_waits(['counts_days' => false] + $goal, $now));
check('a Mijlpaal: no dot', !notices_goal_waits(['entry' => ['kind' => 'value']] + $goal, $now));

echo "Keys\n";
$waiting = [
    'rating'  => ['waiting' => true, 'hour' => 20, 'due' => true],
    'goals'   => ['7'],
    'friends' => [261],
    'invites' => [73],
    'sources' => ['polar'],
];
foreach (['rating', 'goal:7', 'friend:261', 'invite:73', 'source:polar', 'goals', 'sources', 'account',
          'tab:health', 'tab:goals', 'tab:community', 'tab:settings'] as $key) {
    check("$key shows", notice_on($key, $waiting));
}
foreach (['goal:8', 'friend:1', 'invite:1', 'source:google_health', 'tab:overview', 'nonsense'] as $key) {
    check("$key does not", !notice_on($key, $waiting));
}
$none = notices_current();
foreach (['rating', 'goals', 'account', 'sources', 'tab:health', 'tab:goals', 'tab:community', 'tab:settings'] as $key) {
    check("nothing waiting: no $key", !notice_on($key, $none));
}

echo "The Voeding dot's hour\n";
$early = ['rating' => ['waiting' => true, 'hour' => 20, 'due' => false]] + $waiting;
check('before its hour: not on Voeding', !notice_on('rating', $early));
check('before its hour: not on Gezondheid', !notice_on('tab:health', $early));

echo "The element\n";
notices_current($waiting);
$on  = notice_dot('goal:7');
$off = notice_dot('goal:8');
$tab = notice_dot('tab:goals', 'beside');
check('shown: no hidden', !str_contains($on, ' hidden>') && str_contains($on, 'notice-dot--corner'), $on);
check('waiting for nothing: hidden, not left out', str_contains($off, ' hidden'), $off);
check('beside a line icon', str_contains($tab, 'notice-dot--beside'), $tab);
check('its key for notices.js', str_contains($on, 'data-notice="goal:7"'), $on);
check('never read aloud', str_contains($on, 'aria-hidden="true"'), $on);

echo "\n  $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
