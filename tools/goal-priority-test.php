<?php
/**
 * Aanpassen's Primair | Secundair, end to end over HTTP, on a real database.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/goal-priority-test.php
 *
 * Starts the app on PHP's built-in server, makes one account with three goals
 * kept by hand, gives them their percentages through the real progress
 * endpoint, and changes priorities through api/goals/update.php — as the
 * website does (session + CSRF) and as the app does (account token). After
 * every change it reads back what each place shows, and they must agree:
 *
 *   - the database: exactly one primary goal;
 *   - the Doelen board on the website: the primary slot and the order of
 *     Secundaire doelen;
 *   - every goal page: its Primair | Secundair selector;
 *   - Overzicht's goal card on the website;
 *   - the app's state (api/app/state.php): the board and Overzicht's card.
 *
 * And the goal page's layout: the goal, Zelf bijhouden, Verloop, Aanpassen —
 * with Periode in one row and no Wat telt mee or Recent.
 *
 * Accounts are named `gp_…` and removed at the end. Never point it at a live
 * database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/goals.php';

if (!db_available()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable.\n");
    exit(2);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : "\n          " . $detail);
}

function section(string $title): void
{
    printf("\n== %s ==\n", $title);
}

/** @return array{status: int, body: ?array, raw: string} */
function http(string $path, array $o = []): array
{
    global $base;

    $handle  = curl_init($base . $path);
    $headers = [];
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);

    if (($o['get'] ?? false) !== true) {
        curl_setopt($handle, CURLOPT_POST, true);
        if (isset($o['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($o['json']));
        } else {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($o['form'] ?? []));
        }
    }
    if (isset($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (isset($o['jar'])) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $o['jar']);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $o['jar']);
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

    $raw    = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $body   = json_decode($raw, true);

    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw];
}

function csrf(string $jar): string
{
    preg_match('/data-csrf="([^"]+)"/', http('/', ['get' => true, 'jar' => $jar])['raw'], $m);

    return $m[1] ?? '';
}

$socket = stream_socket_server('tcp://127.0.0.1:0');
$port   = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
fclose($socket);

$base   = 'http://127.0.0.1:' . $port;
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, getenv());

for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'goal-priority-test-password';
$made     = [];
$jar      = tempnam(sys_get_temp_dir(), 'gp');

/** One account: its id, its app token, and a website session in $jar. */
function account(string $who): array
{
    global $run, $made, $password, $jar;

    $username = 'gp_' . $run . '_' . $who;
    $made[]   = $username;

    $r = http('/api/auth/app-register.php', ['json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => $password,
        'label' => 'Priority test phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);
    $token = (string) ($r['body']['token'] ?? '');
    http('/api/setup/finish.php', ['bearer' => $token, 'form' => []]);

    file_put_contents($jar, '');
    http('/api/auth/login.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar), 'username' => $username, 'password' => $password]]);

    return ['id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username]), 'token' => $token];
}

/** A goal kept by hand, 0 → 100, at $percent through the real progress endpoint. */
function goal(array $who, string $name, ?int $percent, ?string $end, bool $primary = false): string
{
    $id = goal_create($who['id'], [
        'name' => $name, 'category' => 'other', 'kind' => 'milestone', 'source_kind' => 'manual',
        'target_value' => 100, 'start_value' => 0, 'target_unit' => 'punten', 'direction' => 'increase',
        'start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => $end, 'priority' => $primary ? 'primary' : 'secondary',
    ]);
    if ($percent !== null) {
        http('/api/goals/progress.php', ['bearer' => $who['token'], 'form' => ['goal_id' => $id, 'value' => $percent]]);
    }

    return (string) $id;
}

$names = [];

/** Every place's view, as names: [db, web board, web details, web overview, app board, app overview]. */
function views(array $who): array
{
    global $jar, $names;

    $db = array_map(static fn ($id) => $names[(string) $id] ?? "#$id",
        array_column(db_all("SELECT id FROM goals WHERE user_id = ? AND priority = 'primary' AND status IN ('active','paused')", [$who['id']]), 'id'));

    $page = http('/', ['get' => true, 'jar' => $jar])['raw'];

    $slot = static function (string $which) use ($page, $names): array {
        $start = strpos($page, 'data-goal-slot="' . $which . '"');
        if ($start === false) {
            return [];
        }
        $end  = $which === 'primary' ? strpos($page, 'data-goal-slot="secondary"', $start) : strpos($page, 'data-goal-panel="completed"', $start);
        $part = substr($page, $start, ($end === false ? strlen($page) : $end) - $start);
        preg_match_all('/data-goal-card="(\d+)"/', $part, $m);

        return array_map(static fn ($id) => $names[$id] ?? "#$id", array_values(array_unique($m[1])));
    };

    $details = [];
    preg_match_all('/data-goal-detail="(\d+)".*?data-goal-priority="(\w+)"/s', $page, $m, PREG_SET_ORDER);
    foreach ($m as [, $id, $priority]) {
        $article = substr($page, strpos($page, 'data-goal-detail="' . $id . '"'));
        $article = substr($article, 0, (int) strpos($article, '</article>'));
        preg_match('/data-goal-action="set-primary"\s+aria-pressed="(\w+)"/', $article, $p);
        preg_match('/data-goal-action="set-secondary"\s+aria-pressed="(\w+)"/', $article, $s);
        $details[$names[$id] ?? $id] = $priority . ($p[1] ?? '?') === $priority . 'true' ? 'Primair' : (($s[1] ?? '') === 'true' ? 'Secundair' : '?');
    }

    preg_match('/class="card card--goal [^"]*".*?class="goal__name">([^<]*)</s', $page, $o);

    $state = http('/api/app/state.php', ['bearer' => $who['token']])['body']['data'] ?? [];

    return [
        'db'           => implode(',', $db),
        'web primary'  => implode(',', $slot('primary')),
        'web second'   => implode(', ', $slot('secondary')),
        'web details'  => $details,
        'web overview' => html_entity_decode(trim($o[1] ?? '')),
        'app primary'  => $state['goals']['primary']['name'] ?? '',
        'app second'   => implode(', ', array_map(static fn ($g) => $g['name'], $state['goals']['secondary'] ?? [])),
        'app overview' => (string) ($state['goal']['name'] ?? ''),
    ];
}

/** After a change: every place shows $primary first and $secondary in this order. */
function agree(array $who, string $label, string $primary, string $secondary): void
{
    $v = views($who);
    $selectors = true;
    foreach ($v['web details'] as $name => $chosen) {
        $selectors = $selectors && $chosen === ($name === $primary ? 'Primair' : 'Secundair');
    }

    check("$label: one primary goal stored, $primary", $v['db'] === $primary, 'db: ' . $v['db']);
    check('  …the Doelen board: ' . $primary . ' | ' . $secondary,
        $v['web primary'] === $primary && $v['web second'] === $secondary, $v['web primary'] . ' | ' . $v['web second']);
    check('  …every goal page\'s selector', $selectors && count($v['web details']) > 0, json_encode($v['web details']));
    check('  …Overzicht\'s goal card', $v['web overview'] === $primary, $v['web overview']);
    check('  …the app\'s board and Overzicht', $v['app primary'] === $primary && $v['app second'] === $secondary && $v['app overview'] === $primary,
        $v['app primary'] . ' | ' . $v['app second'] . ' | overview ' . $v['app overview']);
}

function change(array $who, string $goalId, string $action, bool $app = false, ?string $successor = null): array
{
    global $jar;

    $fields = ['goal_id' => $goalId, 'action' => $action] + ($successor === null ? [] : ['successor' => $successor]);

    return $app
        ? http('/api/goals/update.php', ['bearer' => $who['token'], 'form' => $fields])
        : http('/api/goals/update.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar)] + $fields]);
}

try {
    /* ============================================================ 8 & 9 */
    section('Secundair → Primair, and Primair → Secundair when another goal is further along');
    $me = account('one');
    $in = static fn (int $days): string => date('Y-m-d', strtotime("+$days days"));
    $A = goal($me, 'A', 50, $in(20), true);
    $B = goal($me, 'B', 82, $in(20));
    $C = goal($me, 'C', 64, $in(20));
    $names = [$A => 'A', $B => 'B', $C => 'C'];
    agree($me, 'to begin with', 'A', 'B, C');

    $r = change($me, $B, 'primary');
    check('B → Primair: stored, the answer names B', ($r['body']['primary'] ?? null) === $B, json_encode($r['body']));
    agree($me, 'B → Primair', 'B', 'C, A');

    change($me, $A, 'primary', true);
    agree($me, 'A → Primair again, from the app', 'A', 'B, C');

    $r = change($me, $A, 'secondary');
    check('A → Secundair: B (82%) is the one the answer names', ($r['body']['primary'] ?? null) === $B, json_encode($r['body']));
    agree($me, 'A (50%) → Secundair', 'B', 'C, A');

    /* =============================================================== 10 */
    section('Primair → Secundair when the primary goal is the furthest along');
    $two = account('two');
    $A2 = goal($two, 'A', 82, $in(20), true);
    $B2 = goal($two, 'B', 64, $in(20));
    $C2 = goal($two, 'C', 41, $in(20));
    $names = [$A2 => 'A', $B2 => 'B', $C2 => 'C'];
    change($two, $A2, 'secondary', true);
    agree($two, 'A (82%) → Secundair, from the app', 'B', 'A, C');

    /* ========================================= equal, no data, end dates */
    section('Equal percentages, no data, end dates');
    $three = account('three');
    $A3 = goal($three, 'A', 60, $in(5), true);
    $B3 = goal($three, 'B', 60, $in(20));
    $C3 = goal($three, 'C', 60, $in(3));
    $D3 = goal($three, 'D', null, $in(1));
    $names = [$A3 => 'A', $B3 => 'B', $C3 => 'C', $D3 => 'D'];
    change($three, $A3, 'secondary');
    agree($three, 'all at 60%: C (ends first) takes it; A (ends in 5) before B; D, no data, last', 'C', 'A, B, D');

    $four = account('four');
    $A4 = goal($four, 'A', 40, null, true);
    $B4 = goal($four, 'B', null, $in(30));
    $C4 = goal($four, 'C', null, null);
    $D4 = goal($four, 'D', null, $in(10));
    $names = [$A4 => 'A', $B4 => 'B', $C4 => 'C', $D4 => 'D'];
    change($four, $A4, 'secondary');
    agree($four, 'only goals without data left: D (ends soonest) takes it; A (40%), B, C (no end date)', 'D', 'A, B, C');

    /* ==================================================== nothing to hand */
    section('One goal on its own');
    $five = account('five');
    $A5 = goal($five, 'A', 30, $in(20), true);
    $names = [$A5 => 'A'];
    $r = change($five, $A5, 'secondary');
    check('Secundair is refused (409) and says why', $r['status'] === 409 && str_contains((string) ($r['body']['error'] ?? ''), 'ander doel'), $r['raw']);
    check('…and it stays the primary goal', views($five)['db'] === 'A');
    $page = http('/', ['get' => true, 'jar' => $jar])['raw'];
    check('…its page offers Secundair disabled, and says why', (bool) preg_match('/data-goal-action="set-secondary"[^>]*disabled/', $page)
        && str_contains($page, 'Je enige doel is altijd je primaire doel.'));

    /* ================================================== the page's layout */
    section('The goal page: the goal, Zelf bijhouden, Verloop, Aanpassen');
    $page    = http('/', ['get' => true, 'jar' => $jar])['raw'];
    $article = substr($page, (int) strpos($page, 'data-goal-detail="' . $A5 . '"'));
    $article = substr($article, 0, (int) strpos($article, '</article>'));
    $at      = static fn (string $needle): int => ($p = strpos($article, $needle)) === false ? -1 : $p;
    $order   = [$at('card--goal-hero'), $at('data-goal-manual='), $at('id="goal-history-'), $at('card--manage')];
    check('in this order: the goal, Zelf bijhouden, Verloop, Aanpassen', min($order) >= 0 && $order === array_values(array_unique($order)) && $order == (function ($o) { sort($o); return $o; })($order), json_encode($order));
    check('Periode in one row: Gestart and Eindigt together', (bool) preg_match('/data-goal-period.*?Periode.*?Gestart: .*?Eindigt: /s', $article));
    check('no row of its own for Gestart or Eindigt', !preg_match('/metric-row__label">(Gestart|Eindigt)</', $article));
    check('no Wat telt mee, no Recent', !str_contains($article, '>Wat telt mee<') && !str_contains($article, '>Recent<'));
    check('Aanpassen, not Beheer', str_contains($article, '>Aanpassen<') && !str_contains($article, '>Beheer<'));
    check('what fed it is in the Verloop: the source and how the goal moves',
        (bool) preg_match('/data-goal-source.*?Zelf bijgehouden|data-goal-source.*?zelf invult/s', $article));
    preg_match('/data-points="([^"]*)"/', $article, $points);
    $points = json_decode(html_entity_decode($points[1] ?? '[]'), true) ?: [];
    check('each point says how far the goal was that day: "30% van je doel"',
        $points !== [] && str_contains((string) end($points)['n'], '30% van je doel'), json_encode($points));
} finally {
    foreach ($made as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    @unlink($jar);
    proc_terminate($server);
}

printf("\n------------------------------------------------------------------------\n  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
