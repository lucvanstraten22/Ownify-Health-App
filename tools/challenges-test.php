<?php
/**
 * Uitdagingen (docs/CHALLENGES.md), tested on the rules and over HTTP.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/challenges-test.php
 *
 * On the database named by DB_NAME (with migration 022):
 *
 *   - making one: Samen and Wedstrijd, the dates (1 week, 2 weeks, 1 month,
 *     today or next Monday), a suggested name, a target typed in miles kept
 *     in km, only friends invited, the limits
 *   - a code: five digits, found by anybody (no friendship needed), until
 *     the last day inclusive and not after; wrong codes slowed down
 *   - joining is always the person's own: an invitation joins nobody, a
 *     look at a code joins nobody, and an invitation can be turned down
 *   - what counts: synced data only (a workout or a night typed in never
 *     counts), from the day you join, steps once when two apps counted them
 *   - Samen: the stacked bar, the pace, Gehaald
 *   - Wedstrijd: places, the gap, a tie on the last day going on until a day
 *     ends with one person ahead
 *   - leaving takes your contribution with you; you can come back by code
 *   - what another person sees: names, pictures as the boards show them,
 *     totals — and somebody not in it, no totals at all
 *   - the endpoints with an account token, and the website's page
 *
 * Accounts are named `ch_…` and removed at the end, with everything hanging
 * off them. Never point it at a live database.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 022 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/user.php';
require_once $root . '/includes/challenges.php';

if (!db_available() || !challenges_available()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 022.\n");
    exit(2);
}

i18n_force('nl');

/* ======================================================================
   PLUMBING
   ====================================================================== */

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
    printf("\n== %s ==\n", $title);
}

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
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
    if (isset($o['lang'])) {
        $headers[] = 'Accept-Language: ' . $o['lang'];
    }

    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

    $raw    = (string) curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);

    $body = json_decode($raw, true);

    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw];
}

function summary(array $r): string
{
    return 'HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 300);
}

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'challenges-test-password';
$made     = [];

/** An account made in the app, its setup finished: username, token, id. */
function account(string $who): array
{
    global $run, $made, $password;

    $username = 'ch_' . $run . '_' . $who;
    $made[]   = $username;

    $r = http('/api/auth/app-register.php', ['json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => $password,
        'label' => 'Challenge test phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);

    if (($r['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($r) . "\n");
        exit(1);
    }

    $token = (string) $r['body']['token'];
    http('/api/setup/finish.php', ['bearer' => $token, 'form' => []]);

    return ['username' => $username, 'token' => $token, 'id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username])];
}

function befriend(array $a, array $b): void
{
    [$low, $high] = $a['id'] < $b['id'] ? [$a['id'], $b['id']] : [$b['id'], $a['id']];
    db_run("INSERT INTO friendships (user_low_id, user_high_id, requested_by, status, responded_at) VALUES (?, ?, ?, 'accepted', NOW())",
        [$low, $high, $a['id']]);
}

function source(string $code): int
{
    return (int) db_value('SELECT id FROM data_sources WHERE code = ?', [$code]);
}

/** A day's steps (or km, minutes) from a source, as one reading at noon. */
function metric(array $who, string $code, string $day, float $value, string $source = 'google_health_connect'): void
{
    static $n = 0;
    db_run('INSERT INTO health_metrics (user_id, metric_type_id, source_id, data_origin, external_id, value, started_at, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$who['id'], health_metric_type_id($code), source($source), 'test.app.' . $source, 'm' . (++$n) . '-' . $day,
         $value, $day . ' 11:00:00', $day . ' 12:00:00']);
}

function workout(array $who, string $day, string $source = 'polar', string $time = '18:00:00'): void
{
    db_run('INSERT INTO workouts (user_id, source_id, activity_type, started_at, ended_at, duration_seconds) VALUES (?, ?, ?, ?, ?, ?)',
        [$who['id'], source($source), 'running', $day . ' ' . $time, $day . ' ' . $time, 1800]);
}

function night(array $who, string $morning, int $minutes, string $source = 'google_health_connect'): void
{
    $end   = new DateTimeImmutable($morning . ' 07:00:00');
    $start = $end->modify('-' . $minutes . ' minutes');
    db_run('INSERT INTO sleep_sessions (user_id, source_id, night_of, started_at, ended_at, duration_minutes) VALUES (?, ?, ?, ?, ?, ?)',
        [$who['id'], source($source), $morning, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $minutes]);
}

function day(string $relative): DateTimeImmutable
{
    return (new DateTimeImmutable('today'))->modify($relative);
}

/** A challenge put on given dates, for the rules that need time to pass. */
function on_dates(int $id, string $start, string $end): void
{
    db_run('UPDATE challenges SET start_date = ?, end_date = ? WHERE id = ?', [$start, $end, $id]);
}

function joined_on(int $id, array $who, string $day): void
{
    db_run('UPDATE challenge_members SET joined_at = ? WHERE challenge_id = ? AND user_id = ?', [$day . ' 09:00:00', $id, $who['id']]);
}

function view_of(int $id, array $who, ?DateTimeImmutable $today = null): array
{
    return challenge_view(challenge_find($id), $who['id'], challenge_today($today), friend_list($who['id']));
}

function totals(array $view): array
{
    $out = [];
    foreach ($view['people'] as $p) {
        $out[$p['name']] = $p['value'];
    }
    return $out;
}

$port   = free_port();
$base   = 'http://127.0.0.1:' . $port;
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, getenv());

for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(50_000);
}

try {
    $lotte  = account('lotte');
    $daan   = account('daan');
    $mila   = account('mila');
    $vreemd = account('vreemd');   /* nobody's friend */

    befriend($lotte, $daan);
    befriend($lotte, $mila);

    /* ==================================================================
       MAKING ONE
       ================================================================== */
    section('making a challenge');

    $monday = new DateTimeImmutable('2026-10-12');   /* a Monday */
    $thursday = new DateTimeImmutable('2026-10-15');
    [$s, $e] = challenge_dates('1w', 'today', $thursday);
    check('1 week from today: seven days, today included', $s->format('Y-m-d') === '2026-10-15' && $e->format('Y-m-d') === '2026-10-21');
    [$s, $e] = challenge_dates('2w', 'monday', $thursday);
    check('2 weeks from next Monday', $s->format('Y-m-d') === '2026-10-19' && $e->format('Y-m-d') === '2026-11-01');
    [$s, $e] = challenge_dates('month', 'today', new DateTimeImmutable('2026-01-31'));
    check('1 month: to the day before the same date next month', $s->format('Y-m-d') === '2026-01-31' && $e->format('Y-m-d') === '2026-03-02');
    check('next Monday on a Monday is a week later', challenge_next_monday($monday)->format('Y-m-d') === '2026-10-19');

    $made1 = challenge_create($lotte['id'], [
        'kind' => 'together', 'metric' => 'workouts', 'target' => '30', 'length' => '1w', 'from' => 'today',
        'name' => '', 'invite' => [$daan['id'], $vreemd['id'], $lotte['id']],
    ]);
    check('made, with a five-digit code', $made1['ok'] && preg_match('/^\d{5}$/', $made1['code']) === 1, json_encode($made1));
    check('an empty name gets one', ($made1['name'] ?? '') === 'Samen 30 trainingen in een week', $made1['name'] ?? '');
    $id1 = $made1['id'];
    check('its maker is in it', challenge_member_row($id1, $lotte['id'])['status'] === 'joined');
    check('a friend is invited, not joined', challenge_member_row($id1, $daan['id'])['status'] === 'invited');
    check('somebody who is not a friend is not invited, nor is the maker twice',
        challenge_member_row($id1, $vreemd['id']) === null && $made1['invited'] === 1);

    $bad = [
        'no kind'     => ['metric' => 'steps', 'length' => '1w', 'from' => 'today'],
        'no metric'   => ['kind' => 'versus', 'length' => '1w', 'from' => 'today'],
        'odd length'  => ['kind' => 'versus', 'metric' => 'steps', 'length' => '3w', 'from' => 'today'],
        'no target'   => ['kind' => 'together', 'metric' => 'steps', 'length' => '1w', 'from' => 'today'],
        'huge target' => ['kind' => 'together', 'metric' => 'workouts', 'target' => '1000000', 'length' => '1w', 'from' => 'today'],
    ];
    foreach ($bad as $label => $input) {
        $r = challenge_create($lotte['id'], $input);
        check('refused: ' . $label, !$r['ok'] && is_string($r['error'] ?? null), json_encode($r));
    }

    i18n_force('nl', 'imperial');
    $miles = challenge_create($mila['id'], ['kind' => 'together', 'metric' => 'distance', 'target' => '100', 'target_unit' => 'mi',
        'length' => '2w', 'from' => 'monday', 'name' => '  Honderd   mijl  ']);
    $row = challenge_find($miles['id']);
    check('a target typed in miles is kept in km', abs((float) $row['target'] - 160.934) < 0.001, (string) $row['target']);
    check('and shown in miles again', challenge_amount('distance', (float) $row['target']) === '100 mi');
    check('a name is kept on one line with single spaces', $row['name'] === 'Honderd mijl');
    i18n_force('nl');
    check('in metric it is km', challenge_amount('distance', (float) $row['target']) === '161 km');
    check('a long name is cut at 60 characters', mb_strlen(challenge_clean_name(str_repeat('ë', 80))) === 60);

    /* ==================================================================
       CODES
       ================================================================== */
    section('a code');

    $look = challenge_lookup($vreemd['id'], $made1['code']);
    check('found by somebody who is nobody\'s friend', $look['ok'] && $look['status'] === 'found');
    check('looking joins nobody', challenge_member_row($id1, $vreemd['id']) === null);
    $review = challenge_review(challenge_find($id1), $vreemd['id'], challenge_today(), $made1['code']);
    check('what they see before joining: what it is, who is in it, what the others would see',
        $review['by'] === 'Gevonden met code ' . $made1['code'] && count($review['facts']) === 4
        && $review['faces']['label'] === '1 deelnemer'
        && str_contains($review['privacy'], 'je gebruikersnaam en je totaal aan trainingen'));
    check('and no totals: those are for participants', !str_contains(json_encode($review), '"value"') && !isset($review['people']));
    check('your own challenge: you are in it', challenge_lookup($lotte['id'], $made1['code'])['status'] === 'member');
    check('not five digits: refused without counting', challenge_lookup($vreemd['id'], '12a45')['status'] === 'none');

    $free = '00000';
    while (challenge_find_code($free) !== null) {
        $free = str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }
    for ($i = 0; $i < 5; $i++) {
        $wrong = challenge_lookup($mila['id'], $free);
    }
    check('a wrong code: "Geen uitdaging met deze code"', $wrong['status'] === 'none' && str_starts_with($wrong['error'], 'Geen uitdaging'));
    $slowed = challenge_lookup($mila['id'], $made1['code']);
    check('after five wrong codes in a row: wait, even for a right one', $slowed['status'] === 'wait', json_encode($slowed));
    check('joining by code is no way around it', challenge_join($mila['id'], null, $made1['code'])['status'] === 'wait');
    db_run("DELETE FROM auth_attempts WHERE action = 'challenge_code'");

    /* ==================================================================
       JOINING
       ================================================================== */
    section('joining');

    $j = challenge_join($vreemd['id'], null, $made1['code']);
    check('Meedoen with the code: in', $j['ok'] && $j['status'] === 'joined' && challenge_member_row($id1, $vreemd['id'])['status'] === 'joined');
    check('again: already in', challenge_join($vreemd['id'], null, $made1['code'])['status'] === 'member');
    check('an invitation joins nobody until accepted', challenge_member_row($id1, $daan['id'])['status'] === 'invited');
    check('somebody else\'s id is no invitation', !challenge_join($mila['id'], $id1, null)['ok']);
    check('Afwijzen', challenge_decline($daan['id'], $id1)['ok'] && challenge_member_row($id1, $daan['id'])['status'] === 'declined');
    check('a turned-down invitation cannot be accepted any more', !challenge_join($daan['id'], $id1, null)['ok']);
    check('nor sent again', !challenge_invite($lotte['id'], $id1, [$daan['id']])['ok']);
    check('but the code still works', challenge_join($daan['id'], null, $made1['code'])['status'] === 'joined');

    $inv = challenge_invite($vreemd['id'], $id1, [$lotte['id']]);
    check('only your own friends can be invited (vreemd has none)', !$inv['ok']);
    $inv = challenge_invite($lotte['id'], $id1, [$mila['id']]);
    check('a friend invited from the challenge', $inv['ok'] && $inv['message'] === 'Uitnodiging verstuurd aan ' . $mila['username']);
    check('accepted by them', challenge_join($mila['id'], $id1, null)['status'] === 'joined');

    /* ==================================================================
       WHAT COUNTS
       ================================================================== */
    section('what counts');

    $start = day('-3 days');
    on_dates($id1, $start->format('Y-m-d'), day('+3 days')->format('Y-m-d'));
    foreach ([$lotte, $daan, $mila, $vreemd] as $who) {
        joined_on($id1, $who, $start->format('Y-m-d'));
    }
    workout($lotte, $start->format('Y-m-d'));
    workout($lotte, day('-2 days')->format('Y-m-d'), 'google_health_connect');
    workout($lotte, day('-1 days')->format('Y-m-d'), 'manual');
    workout($lotte, day('-5 days')->format('Y-m-d'));          /* before the start */
    workout($daan, day('-1 days')->format('Y-m-d'));
    workout($mila, day('-2 days')->format('Y-m-d'));
    workout($mila, day('-1 days')->format('Y-m-d'));
    joined_on($id1, $mila, day('-1 days')->format('Y-m-d'));   /* joined late */

    $v = view_of($id1, $lotte);
    $t = totals($v);
    check('synced workouts count, from the start', $t[$lotte['username']] == 2.0, json_encode($t));
    check('a workout typed in by hand does not', $t[$lotte['username']] == 2.0);
    check('somebody who joined late counts from that day', $t[$mila['username']] == 1.0, json_encode($t));
    check('Samen: everybody adds up', $v['total'] == 4.0 && $v['target'] == 30.0);
    check('highest first, you as Jij', $v['people'][0]['label'] === 'Jij' && $v['people'][0]['tone'] === 0);
    check('the bar: everybody\'s piece of the target, and the rest',
        abs(array_sum(array_column($v['bar']['segments'], 'part')) + $v['bar']['rest'] - 1) < 0.001
        && $v['bar']['segments'][0]['you'] === true);
    check('the figures, written out', $v['tile']['strong'] === '13%' && $v['tile']['rest'] === '4 van 30'
        && $v['line']['rest'] === 'van 30 trainingen', json_encode([$v['tile'], $v['line']]));
    check('the pace: three days behind you of seven', $v['line']['sub'] === '9 trainingen achter op schema'
        && $v['big']['line'] === '9 trainingen achter op schema · dag 4 van 7' && $v['big']['tone'] === 'behind',
        json_encode($v['big']));
    check('Nog 3 dagen', $v['left'] === 'Nog 3 dagen' && str_ends_with($v['deadline'], 't/m ' . i18n_date(day('+3 days'), 'day_mon')));
    check('a member sees the code and can share it', $v['code'] === $made1['code'] && str_contains((string) $v['share_text'], $made1['code']));
    check('the note says what counts and who made it', $v['note'] === 'Telt mee: trainingen uit gekoppelde bronnen. Gemaakt door jou.');
    check('another member sees who made it', view_of($id1, $daan)['note'] === 'Telt mee: trainingen uit gekoppelde bronnen. Gemaakt door ' . $lotte['username'] . '.');

    $fresh = challenge_create($daan['id'], ['kind' => 'together', 'metric' => 'steps', 'target' => '100000', 'length' => '1w', 'from' => 'today']);
    check('day one: Net begonnen, never behind', view_of($fresh['id'], $daan)['line']['sub'] === 'Net begonnen');
    $later = challenge_create($daan['id'], ['kind' => 'versus', 'metric' => 'steps', 'length' => '1w', 'from' => 'monday']);
    $lv = view_of($later['id'], $daan);
    check('from next Monday: it begins then', $lv['state'] === 'upcoming' && str_starts_with($lv['left'], 'Begint '));

    $sleep = challenge_create($mila['id'], ['kind' => 'versus', 'metric' => 'sleep_nights', 'length' => '1w', 'from' => 'today']);
    on_dates($sleep['id'], day('-3 days')->format('Y-m-d'), day('+3 days')->format('Y-m-d'));
    joined_on($sleep['id'], $mila, day('-3 days')->format('Y-m-d'));
    night($mila, day('-3 days')->format('Y-m-d'), 430);
    night($mila, day('-2 days')->format('Y-m-d'), 400);           /* under 7 hours */
    night($mila, day('-1 days')->format('Y-m-d'), 480, 'manual');  /* typed in */
    night($mila, day('0 days')->format('Y-m-d'), 425);
    check('nights of 7 hours, synced only', totals(view_of($sleep['id'], $mila))[$mila['username']] == 2.0,
        json_encode(totals(view_of($sleep['id'], $mila))));

    $steps = challenge_create($lotte['id'], ['kind' => 'versus', 'metric' => 'steps', 'length' => '1w', 'from' => 'today', 'invite' => [$daan['id']]]);
    challenge_join($daan['id'], $steps['id'], null);
    challenge_join($vreemd['id'], null, $steps['code']);
    on_dates($steps['id'], day('-2 days')->format('Y-m-d'), day('+4 days')->format('Y-m-d'));
    foreach ([$lotte, $daan, $vreemd] as $who) {
        joined_on($steps['id'], $who, day('-2 days')->format('Y-m-d'));
    }
    metric($lotte, 'steps', day('-2 days')->format('Y-m-d'), 9000);
    metric($lotte, 'steps', day('-1 days')->format('Y-m-d'), 11000);
    metric($daan, 'steps', day('-1 days')->format('Y-m-d'), 25000);
    metric($vreemd, 'steps', day('-1 days')->format('Y-m-d'), 20000);
    $sv = view_of($steps['id'], $lotte);
    check('Wedstrijd: places', $sv['rank'] === 2 && $sv['tile']['strong'] === '2e' && $sv['tile']['rest'] === 'van 3 · 20.000',
        json_encode($sv['tile']));
    check('the gap to the one directly ahead', $sv['line']['sub'] === 'Nog 5.000 stappen tot ' . $daan['username'], $sv['line']['sub']);
    check('equal totals share a place', view_of($steps['id'], $vreemd)['rank'] === 2);
    check('the leader is told by how much', view_of($steps['id'], $daan)['line']['sub'] === '5.000 stappen voor op ' . $lotte['username']);
    check('the lead bar: your total against the leader\'s', abs($sv['bar']['value'] - 0.8) < 0.0001);
    i18n_force('en');
    $en = view_of($steps['id'], $lotte);
    check('in English: 2nd of 3, 20,000', $en['tile']['strong'] === '2nd' && $en['tile']['rest'] === 'of 3 · 20,000', json_encode($en['tile']));
    i18n_force('nl');

    /* ==================================================================
       A TIE ON THE LAST DAY
       ================================================================== */
    section('a tie on the last day');

    $tie = challenge_create($lotte['id'], ['kind' => 'versus', 'metric' => 'workouts', 'length' => '1w', 'from' => 'today', 'invite' => [$mila['id']]]);
    challenge_join($mila['id'], $tie['id'], null);
    on_dates($tie['id'], day('-12 days')->format('Y-m-d'), day('-6 days')->format('Y-m-d'));
    joined_on($tie['id'], $lotte, day('-12 days')->format('Y-m-d'));
    joined_on($tie['id'], $mila, day('-12 days')->format('Y-m-d'));
    /* Both have 2 by the last day (lotte's include two from above, mila two). */
    db_run('DELETE FROM workouts WHERE user_id IN (?, ?)', [$lotte['id'], $mila['id']]);
    workout($lotte, day('-10 days')->format('Y-m-d'));
    workout($lotte, day('-8 days')->format('Y-m-d'));
    workout($mila, day('-9 days')->format('Y-m-d'));
    workout($mila, day('-7 days')->format('Y-m-d'));

    $tv = view_of($tie['id'], $lotte);
    check('tied at the end, nobody since: it goes on (Verlenging)', $tv['state'] === 'overtime' && $tv['left'] === 'Verlenging'
        && $tv['line']['strong'] === 'Gelijkspel', json_encode([$tv['state'], $tv['left'], $tv['line']]));
    check('and a code no longer lets anybody in', $tv['code'] === null && challenge_lookup($vreemd['id'], $tie['code'])['status'] === 'ended');

    workout($mila, day('-3 days')->format('Y-m-d'));
    $tv = view_of($tie['id'], $lotte);
    check('the first day that ends with one ahead decides it', $tv['state'] === 'ended'
        && ($tv['winner']['name'] ?? null) === $mila['username'] && $tv['line']['strong'] === $mila['username'] . ' won na verlenging'
        && $tv['left'] === 'Afgelopen op ' . i18n_date(day('-3 days'), 'day_mon'), json_encode([$tv['state'], $tv['line'], $tv['left']]));
    check('their total includes the deciding day', totals($tv)[$mila['username']] == 3.0);
    check('later days change nothing', (function () use ($tie, $lotte, $mila) {
        workout($lotte, day('-2 days')->format('Y-m-d'), 'polar', '07:00:00');
        workout($lotte, day('-2 days')->format('Y-m-d'), 'polar', '19:00:00');
        $after = view_of($tie['id'], $lotte);
        return ($after['winner']['name'] ?? null) === $mila['username'];
    })());

    $today = day('-6 days');
    $mid   = view_of($tie['id'], $lotte, $today);
    check('on the last day itself it is just running', $mid['state'] === 'running' && $mid['left'] === 'Laatste dag');

    $alone = challenge_create($vreemd['id'], ['kind' => 'versus', 'metric' => 'steps', 'length' => '1w', 'from' => 'today']);
    on_dates($alone['id'], day('-9 days')->format('Y-m-d'), day('-3 days')->format('Y-m-d'));
    $av = view_of($alone['id'], $vreemd);
    check('alone in a Wedstrijd: you won', $av['state'] === 'ended' && $av['line']['strong'] === 'Jij won');

    $done = challenge_create($daan['id'], ['kind' => 'together', 'metric' => 'steps', 'target' => '20000', 'length' => '1w', 'from' => 'today']);
    on_dates($done['id'], day('-9 days')->format('Y-m-d'), day('-3 days')->format('Y-m-d'));
    joined_on($done['id'], $daan, day('-9 days')->format('Y-m-d'));
    metric($daan, 'steps', day('-5 days')->format('Y-m-d'), 22000);
    $dv = view_of($done['id'], $daan);
    check('Samen past its end: Gehaald, the bar full', $dv['state'] === 'ended' && $dv['line']['sub'] === 'Gehaald'
        && abs(array_sum(array_column($dv['bar']['segments'], 'part')) - 1) < 0.001 && $dv['bar']['rest'] == 0.0,
        json_encode([$dv['line'], $dv['bar']]));

    /* ==================================================================
       LEAVING
       ================================================================== */
    section('leaving');

    $before = view_of($steps['id'], $daan)['people'];
    check('Uitdaging verlaten', challenge_leave($vreemd['id'], $steps['id'])['ok']);
    $after = view_of($steps['id'], $daan);
    check('their contribution goes with them', count($after['people']) === count($before) - 1
        && !in_array($vreemd['username'], array_column($after['people'], 'name'), true));
    check('they no longer see it', !in_array($steps['id'], array_column(challenges_for($vreemd['id'])['running'], 'id'), true));
    check('the code lets them back in, counting from today', challenge_join($vreemd['id'], null, $steps['code'])['status'] === 'joined'
        && totals(view_of($steps['id'], $daan))[$vreemd['username']] == 0.0);
    check('a finished challenge cannot be left', !challenge_leave($daan['id'], $done['id'])['ok']);

    /* ==================================================================
       WHAT COMMUNITY GETS
       ================================================================== */
    section('Community\'s data');

    $invited = challenge_create($daan['id'], ['kind' => 'together', 'metric' => 'active_minutes', 'target' => '1000', 'length' => '2w', 'from' => 'today', 'invite' => [$lotte['id']]]);
    $all = challenges_for($lotte['id']);
    check('invitations, running and ended apart', in_array($invited['id'], array_column($all['invites'], 'id'), true)
        && in_array($steps['id'], array_column($all['running'], 'id'), true)
        && in_array($tie['id'], array_column($all['ended'], 'id'), true));
    $invite = $all['invites'][array_search($invited['id'], array_column($all['invites'], 'id'), true)];
    check('an invitation: who sent it, and what to review', ($invite['inviter']['name'] ?? null) === $daan['username']
        && $invite['review']['by'] === $daan['username'] . ' nodigt je uit' && $invite['review']['invited'] === true);
    check('the summary line', $all['summary'] === count($all['running']) . ' bezig · 1 uitnodiging', $all['summary']);
    check('what Nieuwe uitdaging needs: five metrics, three lengths, two starts', count($all['metrics']) === 5
        && count($all['lengths']) === 3 && count($all['from']) === 2 && $all['from'][1]['label'] !== '');
    check('friends to invite, and who of them is not in a challenge yet', count($all['friends']) === 2
        && array_column(view_of($steps['id'], $lotte)['invitable'], 'name') === [$mila['username']]);
    check('a person carries nothing but their public fields', array_keys($all['running'][0]['people'][0]) === [
        'name', 'initial', 'accent', 'avatar', 'you', 'value', 'value_text', 'tone', 'rank', 'share', 'share_text', 'label']);
    check('no other user ids in what a participant gets', !str_contains(json_encode(array_map(
        static fn (array $v): array => $v['people'], $all['running'])), '"user_id"'));

    /* ==================================================================
       OVER HTTP
       ================================================================== */
    section('the endpoints, with the app\'s account token');

    $r = http('/api/challenges/create.php', ['bearer' => $mila['token'], 'json' => [
        'kind' => 'together', 'metric' => 'distance', 'target' => 50, 'target_unit' => 'km', 'length' => '1w', 'from' => 'today',
        'name' => 'Samen 50 km', 'invite' => [$lotte['id']],
    ]]);
    check('create.php: made, with its code', $r['status'] === 200 && preg_match('/^\d{5}$/', (string) ($r['body']['code'] ?? '')) === 1, summary($r));
    $code = (string) ($r['body']['code'] ?? '');
    $httpId = (int) ($r['body']['id'] ?? 0);

    $r = http('/api/challenges/lookup.php', ['bearer' => $vreemd['token'], 'json' => ['code' => $code]]);
    check('lookup.php: found, with what to review and nothing more', $r['status'] === 200 && ($r['body']['status'] ?? null) === 'found'
        && ($r['body']['review']['name'] ?? null) === 'Samen 50 km' && !str_contains($r['raw'], '"value"'), summary($r));
    $r = http('/api/challenges/lookup.php', ['bearer' => $vreemd['token'], 'json' => ['code' => $code], 'lang' => 'en']);
    check('lookup.php in English', ($r['body']['review']['title'] ?? null) === 'Join?', summary($r));
    $r = http('/api/challenges/lookup.php', ['bearer' => $vreemd['token'], 'json' => ['code' => $free]]);
    check('lookup.php: a wrong code is 404 with its sentence', $r['status'] === 404 && ($r['body']['status'] ?? null) === 'none'
        && str_starts_with((string) ($r['body']['error'] ?? ''), 'Geen uitdaging'), summary($r));
    $r = http('/api/challenges/join.php', ['bearer' => $vreemd['token'], 'json' => ['code' => $code]]);
    check('join.php with the code', $r['status'] === 200 && ($r['body']['status'] ?? null) === 'joined', summary($r));
    $r = http('/api/challenges/join.php', ['bearer' => $lotte['token'], 'json' => ['challenge_id' => $httpId]]);
    check('join.php with an invitation', $r['status'] === 200 && ($r['body']['status'] ?? null) === 'joined', summary($r));
    $r = http('/api/challenges/join.php', ['bearer' => $daan['token'], 'json' => ['challenge_id' => $httpId]]);
    check('join.php without an invitation or code: 404', $r['status'] === 404, summary($r));
    $r = http('/api/challenges/invite.php', ['bearer' => $lotte['token'], 'json' => ['challenge_id' => $httpId, 'invite' => [$daan['id']]]]);
    check('invite.php', $r['status'] === 200 && ($r['body']['count'] ?? null) === 1, summary($r));
    $r = http('/api/challenges/respond.php', ['bearer' => $daan['token'], 'json' => ['challenge_id' => $httpId, 'action' => 'decline']]);
    check('respond.php: decline', $r['status'] === 200 && ($r['body']['message'] ?? null) === 'Uitnodiging afgewezen', summary($r));
    $r = http('/api/challenges/respond.php', ['bearer' => $vreemd['token'], 'json' => ['challenge_id' => $httpId, 'action' => 'leave']]);
    check('respond.php: leave', $r['status'] === 200, summary($r));
    $r = http('/api/challenges/create.php', ['bearer' => str_repeat('ab', 32), 'json' => ['kind' => 'versus']]);
    check('an unknown token: 401', $r['status'] === 401, summary($r));
    $r = http('/api/challenges/create.php', ['form' => ['kind' => 'versus']]);
    check('the website without its CSRF token: 419', $r['status'] === 419, summary($r));

    $state = http('/api/app/state.php', ['bearer' => $lotte['token']]);
    $data  = $state['body']['data']['challenges'] ?? null;
    check('the app\'s state carries the challenges', is_array($data) && ($data['available'] ?? null) === true
        && in_array($httpId, array_column($data['running'] ?? [], 'id'), true), summary($state));
    check('and the Ranglijst card', isset($state['body']['data']['community']['preview']['rows']));

} finally {
    foreach (array_unique($made) as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    db_run('DELETE FROM challenges WHERE creator_id IS NULL AND id NOT IN (SELECT DISTINCT challenge_id FROM challenge_members)');
    proc_terminate($server);
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
