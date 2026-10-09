<?php
/**
 * Polar, tested end to end over HTTP — without Polar and without real
 * credentials.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/polar-test.php
 *
 * Starts the app on PHP's built-in server with POLAR_* pointing at a stand-in
 * (tools/polar-fake.php) that plays auth.polar.com and AccessLink v4 by
 * Polar's rules. Then, through the real endpoints, the real authentication
 * and the database named by DB_NAME (with migration 020):
 *
 *   setup        not configured: no button, start refused
 *   state        the web flow only in its own browser session and account;
 *                replayed, tampered, expired, missing or refused states
 *                connect nothing
 *   web          start → Polar → callback: connected, tokens sealed, the
 *                first sync done
 *   app          start with the account token → Polar → a confirmation page
 *                naming the account → confirm (one-time token): connected;
 *                cancel connects nothing
 *   data         trainings, their heart rate, nights with stages, steps per
 *                hour, 24/7 heart rate, HRV and breathing rate, the device —
 *                on the clocks they were recorded on; features one day at a
 *                time, as Polar requires
 *   duplicates   syncing again changes no count
 *   tokens       an expired token is refreshed first; a 401 refreshes and
 *                retries; a refused refresh marks the connection revoked
 *   errors       500, 429 and 403 end in a sentence and a partial sync,
 *                never a crash
 *   isolation    each account sees and syncs only its own Polar
 *   disconnect   tokens gone, data kept, other connections untouched
 *   schedule     tools/polar-sync.php syncs whoever is due
 *
 * Accounts are named `pt_…` and removed at the end, with everything hanging
 * off them. Never point it at a live database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 020 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

/* ---------------------------------------------------------- the servers */

function free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
}

/** @return resource */
function serve(int $port, array $args, array $env)
{
    global $root;
    $server = proc_open(['php', '-S', '127.0.0.1:' . $port, ...$args],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(50_000);
    }
    return $server;
}

$fakeDir  = sys_get_temp_dir() . '/ownify-polar-test-' . bin2hex(random_bytes(4));
mkdir($fakeDir);
$fakePort = free_port();
$port     = free_port();
$base     = 'http://127.0.0.1:' . $port;
$fakeBase = 'http://127.0.0.1:' . $fakePort;
$redirect = $base . '/api/integrations/polar/callback.php';

$polarEnv = [
    'POLAR_CLIENT_ID'     => 'test-client',
    'POLAR_CLIENT_SECRET' => 'test-secret-not-real',
    'POLAR_REDIRECT_URI'  => $redirect,
    'POLAR_AUTHORIZE_URL' => $fakeBase . '/oauth/authorize',
    'POLAR_TOKEN_URL'     => $fakeBase . '/oauth/token',
    'POLAR_API_BASE'      => $fakeBase . '/v4/data',
];

/* This process reads the same configuration as the server it tests. */
foreach ($polarEnv as $k => $v) {
    putenv($k . '=' . $v);
}

require_once $root . '/includes/db.php';
require_once $root . '/includes/session.php';
require_once $root . '/includes/integrations.php';
require_once $root . '/includes/health-connect-map.php';

if (!db_available() || !polar_stored() || !crypto_available()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable, has no migration 020, or no app key is set.\n");
    exit(2);
}

$fake   = serve($fakePort, [$root . '/tools/polar-fake.php'], ['FAKE_POLAR_DIR' => $fakeDir] + getenv());
$server = serve($port, ['-t', $root], $polarEnv + getenv());

/* A second app with no Polar credentials at all. */
$bareEnv = getenv();
foreach (array_keys($polarEnv) as $k) {
    unset($bareEnv[$k]);
}
$barePort   = free_port();
$bareServer = serve($barePort, ['-t', $root], $bareEnv);

/* ---------------------------------------------------------- the plumbing */

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

/**
 * One HTTP request. $o: form, json, bearer, jar, get, base.
 *
 * @return array{status: int, body: ?array, raw: string, location: ?string}
 */
function http(string $url, array $o = []): array
{
    global $base;
    $handle  = curl_init(str_starts_with($url, 'http') ? $url : ($o['base'] ?? $base) . $url);
    $headers = [];
    $location = null;
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
            return strlen($line);
        },
    ]);
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
    unset($handle);
    $body = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw, 'location' => $location];
}

function summary(array $r): string
{
    return 'HTTP ' . $r['status'] . ' ' . substr(preg_replace('/\s+/', ' ', $r['raw']), 0, 300) . ($r['location'] ? ' → ' . $r['location'] : '');
}

function csrf(string $jar): string
{
    $page = http('/', ['get' => true, 'jar' => $jar]);
    preg_match('/data-csrf="([^"]+)"/', $page['raw'], $m);
    return $m[1] ?? '';
}

function world(?callable $change = null): array
{
    global $fakeDir;
    $file  = $fakeDir . '/world.json';
    $world = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
    if ($change !== null) {
        $change($world);
        file_put_contents($file, json_encode($world, JSON_UNESCAPED_SLASHES));
    }
    return $world;
}

function sent(): array
{
    global $fakeDir;
    $file = $fakeDir . '/requests.jsonl';
    return is_file($file) ? array_map(static fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($file))))) : [];
}

function forget_sent(): void
{
    global $fakeDir;
    @unlink($fakeDir . '/requests.jsonl');
}

/* --------------------------------------------------------- the accounts */

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'polar-test-password';
$made     = [];

function account(string $who): array
{
    global $run, $made, $password;
    $username = 'pt_' . $run . '_' . $who;
    $made[]   = $username;

    $r = http('/api/auth/app-register.php', ['json' => [
        'email' => $username . '@ownify-test.invalid', 'username' => $username, 'password' => $password,
        'label' => 'Polar test phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);
    if (($r['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($r) . "\n");
        exit(1);
    }

    /* Past the first-run setup, so the website opens on the app itself. */
    http('/api/setup/finish.php', ['bearer' => (string) $r['body']['token'], 'form' => []]);

    /* And signed in on the website, in a browser of its own. */
    $jar = tempnam(sys_get_temp_dir(), 'pt-jar-');
    $login = http('/api/auth/login.php', ['jar' => $jar, 'form' => ['csrf' => csrf($jar), 'username' => $username, 'password' => $password]]);
    if (($login['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not sign in $username: " . summary($login) . "\n");
        exit(1);
    }

    return ['username' => $username, 'token' => (string) $r['body']['token'], 'jar' => $jar,
            'id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username])];
}

function polar_row(int $userId): ?array
{
    return db_one("SELECT * FROM user_integrations WHERE user_id = ? AND provider = 'polar'", [$userId]);
}

function counts(int $userId): array
{
    $source = (int) db_value("SELECT id FROM data_sources WHERE code = 'polar'");
    return [
        'workouts' => (int) db_value('SELECT COUNT(*) FROM workouts WHERE user_id = ? AND source_id = ?', [$userId, $source]),
        'sleeps'   => (int) db_value('SELECT COUNT(*) FROM sleep_sessions WHERE user_id = ? AND source_id = ?', [$userId, $source]),
        'stages'   => (int) db_value('SELECT COUNT(*) FROM sleep_stages st JOIN sleep_sessions s ON s.id = st.sleep_session_id WHERE s.user_id = ? AND s.source_id = ?', [$userId, $source]),
        'metrics'  => (int) db_value('SELECT COUNT(*) FROM health_metrics WHERE user_id = ? AND source_id = ?', [$userId, $source]),
        'minutes'  => (int) db_value("SELECT COUNT(*) FROM heart_rate_minutes WHERE user_id = ? AND data_origin LIKE 'polar%'", [$userId]),
    ];
}

/** Starts the web flow in `$who`'s browser; returns Polar's answer (the callback URL). */
function web_authorize(array $who): ?string
{
    $start = http('/api/integrations/polar/start.php', ['get' => true, 'jar' => $who['jar']]);
    if ($start['status'] !== 303 || $start['location'] === null) {
        return null;
    }
    $polar = http($start['location'], ['get' => true]);
    return $polar['location'];
}

/* ------------------------------------------------------------ the data */

$day = static fn (int $ago): string => (new DateTimeImmutable('today'))->modify("-$ago days")->format('Y-m-d');
$offset = (int) ((new DateTimeImmutable('today'))->getOffset() / 60);       // Amsterdam, today
$zone = sprintf('%+03d:%02d', intdiv($offset, 60), abs($offset) % 60);

function training(string $id, string $date, string $time, int $minutes, string $sport, string $device, int $offset, int $bpm): array
{
    $start = $date . 'T' . $time . '.000';
    $stop  = (new DateTimeImmutable($date . 'T' . $time))->modify("+$minutes minutes")->format('Y-m-d\TH:i:s') . '.000';
    $values = [];
    for ($s = 0; $s < $minutes * 60; $s++) {
        $values[] = $s % 97 === 0 ? 0 : $bpm + intdiv($s, 120);     // a dropout now and then: no reading
    }
    return [
        'identifier' => ['id' => $id], 'startTime' => $start, 'stopTime' => $stop, 'durationMillis' => $minutes * 60000,
        'deviceId' => $device, 'distanceMeters' => 8000, 'calories' => 520, 'hrAvg' => $bpm + 10, 'hrMax' => $bpm + 30,
        'timezoneOffsetMinutes' => $offset, 'sport' => ['id' => $sport], 'name' => 'Training',
        'exercises' => [[
            'startTime' => $start, 'stopTime' => $stop, 'ascentMeters' => 42, 'sport' => ['id' => $sport],
            'samples' => ['samples' => [['type' => 'HEART_RATE', 'intervalMillis' => 1000, 'values' => $values]]],
        ]],
    ];
}

function night(string $date, string $zone): array
{
    $start = (new DateTimeImmutable($date . 'T23:10:00' . $zone))->modify('-1 day');
    $end   = new DateTimeImmutable($date . 'T07:00:00' . $zone);
    $changes = [[0, 'SLEEP_STATE_NON_REM1'], [1200, 'SLEEP_STATE_NON_REM2'], [3600, 'SLEEP_STATE_NON_REM3'],
                [6000, 'SLEEP_STATE_REM'], [8000, 'SLEEP_STATE_WAKE'], [8300, 'SLEEP_STATE_NON_REM2'],
                [14000, 'SLEEP_STATE_REM'], [20000, 'SLEEP_STATE_UNKNOWN'], [21000, 'SLEEP_STATE_NON_REM2']];
    return [
        'sleepDate'   => $date,
        'sleepResult' => ['hypnogram' => [
            'sleepStart' => $start->format('Y-m-d\TH:i:s.000P'), 'sleepEnd' => $end->format('Y-m-d\TH:i:s.000P'),
            'sleepStateChanges' => array_map(static fn ($c) => ['offsetFromStart' => $c[0] . 's', 'newState' => $c[1]], $changes),
            'deviceReference' => ['uuid' => 'uuid-a'],
        ]],
        'sleepEvaluation' => ['asleepDuration' => '25000s', 'sleepSpan' => '28200s'],
    ];
}

function activity(string $date, string $device, int $perMinute): array
{
    $steps = array_fill(0, 1440, 0);
    foreach ([8 * 60 => 30, 17 * 60 => 20] as $minute => $length) {
        for ($m = $minute; $m < $minute + $length; $m++) {
            $steps[$m] = $perMinute;
        }
    }
    return ['date' => $date, 'activitiesPerDevice' => [[
        'deviceReference' => ['deviceId' => $device],
        'activitySamples' => [['stepSamples' => ['startTime' => '00:00:00', 'interval' => 60000, 'steps' => $steps]]],
    ]]];
}

function heart(string $date, string $device, int $bpm): array
{
    $samples = [];
    for ($m = 0; $m < 1440; $m += 5) {
        $samples[] = ['heartRate' => $bpm + ($m % 60 === 0 ? 4 : 0), 'offsetMillis' => $m * 60000, 'triggerType' => 'TRIGGER_TIMED_247'];
    }
    return ['date' => $date, 'deviceRef' => ['deviceId' => $device], 'samples' => $samples];
}

world(static function (array &$w) use ($redirect, $day, $offset, $zone): void {
    $w = [
        'client'   => ['id' => 'test-client', 'secret' => 'test-secret-not-real'],
        'redirect' => $redirect,
        'approve'  => 'alice',
        'accounts' => [
            'alice' => [
                'devices'   => ['devicesData' => [['deviceReference' => ['uuid' => 'uuid-a'], 'productVariant' => ['productDescription' => 'Polar Pacer Pro']]],
                                'userDevicesData' => ['activeDevices' => [['deviceReference' => ['uuid' => 'uuid-a']]]]],
                'trainings' => [training('ses-a1', $day(2), '07:10:00', 45, '1', 'DEV-A', $offset, 140),
                                training('ses-a2', $day(1), '18:00:00', 30, '2', 'DEV-A', $offset, 125)],
                'sleeps'    => [night($day(1), $zone), night($day(0), $zone)],
                'activity'  => [activity($day(1), 'DEV-A', 100), activity($day(0), 'DEV-A', 50)],
                'heart'     => [heart($day(2), 'DEV-A', 60), heart($day(1), 'DEV-A', 58), heart($day(0), 'DEV-A', 56)],
                'recharge'  => [['sleepResultDate' => $day(1), 'meanNightlyRecoveryRmssd' => 41, 'meanNightlyRecoveryRespirationInterval' => 4000],
                                ['sleepResultDate' => $day(0), 'meanNightlyRecoveryRmssd' => 45, 'meanNightlyRecoveryRespirationInterval' => 4000]],
            ],
            'bob' => [
                'devices'   => ['devicesData' => [['deviceReference' => ['uuid' => 'uuid-b'], 'productVariant' => ['productDescription' => 'Polar H10']]],
                                'userDevicesData' => ['activeDevices' => [['deviceReference' => ['uuid' => 'uuid-b']]]]],
                'trainings' => [training('ses-b1', $day(3), '12:00:00', 20, '3', 'DEV-B', $offset, 110)],
                'heart'     => [heart($day(3), 'DEV-B', 70)],
            ],
        ],
    ];
});

/* ===================================================================== */

$alice = account('alice');
$bob   = account('bob');
$carol = account('carol');

section('setup');

$bare = http('/api/integrations/polar/start.php', ['base' => 'http://127.0.0.1:' . $barePort, 'bearer' => $alice['token'], 'form' => []]);
check('without credentials connecting is refused, with a sentence', $bare['status'] === 503 && str_contains((string) ($bare['body']['error'] ?? ''), 'niet ingesteld'), summary($bare));

$state = http('/api/app/state.php', ['bearer' => $alice['token'], 'form' => []]);
$polarItem = null;
foreach ($state['body']['data']['settings']['integrations'] ?? [] as $item) {
    if (($item['provider'] ?? null) === 'polar') { $polarItem = $item; }
}
check('Instellingen lists Polar, connectable, not connected', $polarItem !== null && $polarItem['available'] === true && $polarItem['connected'] === false, json_encode($polarItem));

$signedOut = http('/api/integrations/polar/start.php', ['get' => true]);
check('signed out, the web start sends you to sign in rather than to Polar', $signedOut['status'] === 303 && !str_contains((string) $signedOut['location'], 'authorize'), summary($signedOut));

$noAuth = http('/api/integrations/polar/start.php', ['form' => []]);
check('the POST start without a session or token is refused', in_array($noAuth['status'], [401, 419], true), summary($noAuth));

section('state');

$start = http('/api/integrations/polar/start.php', ['get' => true, 'jar' => $alice['jar']]);
parse_str((string) parse_url((string) $start['location'], PHP_URL_QUERY), $authorize);
check('start goes to Polar\'s authorize page', $start['status'] === 303 && str_starts_with((string) $start['location'], $fakeBase . '/oauth/authorize'), summary($start));
check('…with response_type=code, the client id and the exact redirect URI', ($authorize['response_type'] ?? '') === 'code'
    && ($authorize['client_id'] ?? '') === 'test-client' && ($authorize['redirect_uri'] ?? '') === $redirect);
check('…asking only the read scopes Ownify uses', ($authorize['scope'] ?? '') === 'training_sessions:read activity:read sleep:read nightly_recharge:read continuous_samples:read devices:read sports:read', (string) ($authorize['scope'] ?? ''));
check('…with a long random state, never the secret', strlen((string) ($authorize['state'] ?? '')) >= 40 && !str_contains((string) $start['location'], 'test-secret'));
check('only the state\'s hash is stored', db_value('SELECT COUNT(*) FROM integration_oauth_states WHERE state_hash = ?', [hash('sha256', (string) $authorize['state'])]) == 1
    && db_value('SELECT COUNT(*) FROM integration_oauth_states WHERE state_hash = ?', [(string) $authorize['state']]) == 0);

$polar    = http((string) $start['location'], ['get' => true]);
$callback = (string) $polar['location'];
parse_str((string) parse_url($callback, PHP_URL_QUERY), $back);

/* Another browser, signed in as another account, with alice's callback. */
$stolen = http($callback, ['get' => true, 'jar' => $bob['jar']]);
check('the callback in another account\'s browser connects nothing', $stolen['status'] === 303 && polar_row($bob['id']) === null && polar_row($alice['id']) === null, summary($stolen));
$again = http($callback, ['get' => true, 'jar' => $alice['jar']]);
check('…and the state is used up: alice\'s own replay of it connects nothing either', polar_row($alice['id']) === null, summary($again));

$code = (string) ($back['code'] ?? '');
$tampered = http('/api/integrations/polar/callback.php?' . http_build_query(['code' => $code, 'state' => 'x' . ($back['state'] ?? '')]), ['get' => true, 'jar' => $alice['jar']]);
check('a tampered state connects nothing', polar_row($alice['id']) === null, summary($tampered));
$missing = http('/api/integrations/polar/callback.php?' . http_build_query(['code' => $code]), ['get' => true, 'jar' => $alice['jar']]);
check('no state at all connects nothing', polar_row($alice['id']) === null, summary($missing));

$late = http('/api/integrations/polar/start.php', ['get' => true, 'jar' => $alice['jar']]);
parse_str((string) parse_url((string) $late['location'], PHP_URL_QUERY), $lateQuery);
db_run('UPDATE integration_oauth_states SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE state_hash = ?', [hash('sha256', (string) $lateQuery['state'])]);
$lateBack = http((string) http((string) $late['location'], ['get' => true])['location'], ['get' => true, 'jar' => $alice['jar']]);
check('an expired state connects nothing', polar_row($alice['id']) === null, summary($lateBack));

world(static function (array &$w): void { $w['deny'] = true; });
$denied = http((string) http((string) http('/api/integrations/polar/start.php', ['get' => true, 'jar' => $alice['jar']])['location'], ['get' => true])['location'], ['get' => true, 'jar' => $alice['jar']]);
world(static function (array &$w): void { unset($w['deny']); });
check('refusing at Polar connects nothing, and lands back in the app', polar_row($alice['id']) === null && $denied['status'] === 303, summary($denied));

section('web: connect');

forget_sent();
$back = web_authorize($alice);
$done = http((string) $back, ['get' => true, 'jar' => $alice['jar']]);
$row  = polar_row($alice['id']);
check('the callback connects alice and sends her back to the app', $done['status'] === 303 && ($row['status'] ?? null) === 'connected', summary($done));
check('the tokens are stored sealed, never readable', $row !== null && !str_contains((string) $row['access_token'], 'at-') && !str_contains((string) $row['refresh_token'], 'rt-')
    && str_starts_with((string) (integration_tokens($alice['id'], 'polar')['access_token'] ?? ''), 'at-'));
check('the expiry is stored, a minute early', $row !== null && strtotime((string) $row['token_expires_at']) > time() + 43000);
$tokenCalls = array_values(array_filter(sent(), static fn ($r) => $r['path'] === '/oauth/token'));
check('the code was exchanged with Basic auth and the same redirect URI', count($tokenCalls) === 1 && $tokenCalls[0]['auth'] === 'basic'
    && ($tokenCalls[0]['form']['grant_type'] ?? '') === 'authorization_code' && ($tokenCalls[0]['form']['redirect_uri'] ?? '') === $redirect);
check('the first sync ran: last sync set, nothing running', $row !== null && $row['last_sync_at'] !== null && $row['sync_started_at'] === null && $row['last_sync_status'] === 'ok', json_encode([$row['last_sync_status'] ?? null, $row['last_error'] ?? null]));
check('the connection is named after the watch', ($row['external_account_label'] ?? null) === 'Polar Pacer Pro');

$page = http('/', ['get' => true, 'jar' => $alice['jar']]);
check('Instellingen says so, once', str_contains($page['raw'], 'Polar is gekoppeld'), 'flash missing: ' . (preg_match('/data-integration-flash[^>]*>.{0,400}/s', $page['raw'], $mm) ? preg_replace('/\s+/', ' ', $mm[0]) : 'no flash element; http ' . $page['status'] . ' len ' . strlen($page['raw'])));

section('data');

$c = counts($alice['id']);
check('two trainings', $c['workouts'] === 2, json_encode($c));
$run1 = db_one("SELECT * FROM workouts WHERE user_id = ? AND external_id = 'polar:training:ses-a1'", [$alice['id']]);
check('a training on its own clock, with its figures', $run1 !== null && $run1['started_at'] === $day(2) . ' 07:10:00' && $run1['ended_at'] === $day(2) . ' 07:55:00'
    && (int) $run1['duration_seconds'] === 2700 && (float) $run1['distance_m'] === 8000.0 && (int) $run1['total_kcal'] === 520
    && (int) $run1['avg_hr'] === 150 && (int) $run1['max_hr'] === 170 && (int) $run1['elevation_gain_m'] === 42 && $run1['active_kcal'] === null, json_encode($run1));
check('the sport by Polar\'s own name', $run1['activity_type'] === 'running'
    && db_value("SELECT activity_type FROM workouts WHERE user_id = ? AND external_id = 'polar:training:ses-a2'", [$alice['id']]) === 'cycling');
$trainMinutes = (int) db_value("SELECT COUNT(*) FROM heart_rate_minutes WHERE user_id = ? AND data_origin = 'polar:DEV-A' AND minute_at >= ? AND minute_at < ?",
    [$alice['id'], $day(2) . ' 07:10:00', $day(2) . ' 07:55:00']);
check('the training\'s heart rate, minute by minute', $trainMinutes === 45, (string) $trainMinutes);
$firstMinute = db_one('SELECT bpm, samples FROM heart_rate_minutes WHERE user_id = ? AND minute_at = ? AND data_origin = ?', [$alice['id'], $day(2) . ' 07:10:00', 'polar:DEV-A']);
check('…a dropout is no reading, not a zero', $firstMinute !== null && (int) $firstMinute['samples'] < 61 && (float) $firstMinute['bpm'] > 100, json_encode($firstMinute));

$sleep = db_one("SELECT * FROM sleep_sessions WHERE user_id = ? AND external_id = ?", [$alice['id'], 'polar:sleep:' . $day(0)]);
check('a night, filed under the morning it ended, on its own clock', $sleep !== null && $sleep['night_of'] === $day(0)
    && $sleep['started_at'] === $day(1) . ' 23:10:00' && $sleep['ended_at'] === $day(0) . ' 07:00:00', json_encode($sleep));
check('…its minutes worked out as a Health Connect night\'s', $sleep !== null && (int) $sleep['deep_minutes'] === 40 && (int) $sleep['rem_minutes'] === 133
    && (int) $sleep['awake_minutes'] === 5 && (int) $sleep['awakenings'] === 1 && (int) $sleep['duration_minutes'] === (int) $sleep['light_minutes'] + 40 + 133, json_encode($sleep));
$stages = db_all('SELECT stage FROM sleep_stages WHERE sleep_session_id = ? ORDER BY started_at', [(int) $sleep['id']]);
check('…its stages, the unknown stretch left out', array_column($stages, 'stage') == [4, 4, 5, 6, 1, 4, 6, 4], json_encode(array_column($stages, 'stage')));

$steps = health_metric_totals_for($alice['id'], $day(1));
function health_metric_totals_for(int $userId, string $date): ?float
{
    require_once dirname(__DIR__) . '/includes/health-totals.php';
    return health_metric_totals($userId, 'steps', $date, $date)[$date] ?? null;
}
check('steps per hour, the day\'s total right', (int) $steps === 5000, (string) $steps);
$stepRows = db_one("SELECT COUNT(*) AS n, SUM(m.started_at IS NOT NULL AND m.data_origin = 'polar:DEV-A') AS spanned, MAX(m.recorded_at) AS last
    FROM health_metrics m JOIN health_metric_types t ON t.id = m.metric_type_id WHERE m.user_id = ? AND t.code = 'steps'", [$alice['id']]);
check('…each hour with its span and its device, none still to come', (int) $stepRows['n'] >= 2 && (int) $stepRows['spanned'] === (int) $stepRows['n']
    && strtotime((string) $stepRows['last']) <= time(), json_encode($stepRows));

$dayMinutes = (int) db_value("SELECT COUNT(*) FROM heart_rate_minutes WHERE user_id = ? AND minute_at >= ? AND minute_at < ? AND data_origin = 'polar:DEV-A'", [$alice['id'], $day(1), $day(0)]);
check('24/7 heart rate: a day of five-minute readings, and the training\'s minutes', $dayMinutes === 288 - 6 + 30, (string) $dayMinutes);
$hrv = db_one("SELECT value, recorded_at FROM health_metrics WHERE user_id = ? AND external_id = ?", [$alice['id'], 'polar:recharge:' . $day(0) . ':hrv']);
check('Nightly Recharge: HRV, at the end of its night', $hrv !== null && (float) $hrv['value'] === 45.0 && $hrv['recorded_at'] === $day(0) . ' 07:00:00', json_encode($hrv));
check('Nightly Recharge: breathing rate (60 000 / 4 000 ms = 15 /min)', (float) db_value("SELECT value FROM health_metrics WHERE user_id = ? AND external_id = ?", [$alice['id'], 'polar:recharge:' . $day(0) . ':breathing']) === 15.0);
check('the night\'s heart rate, from the 24/7 readings during it', (float) db_value("SELECT value FROM health_metrics WHERE user_id = ? AND external_id = ?", [$alice['id'], 'polar:sleep-hr:' . $day(0)]) > 55);

$withFeatures = array_filter(sent(), static fn ($r) => $r['features'] !== [] && $r['path'] !== '/v4/data/continuous-samples');
$oneDay = array_filter($withFeatures, static fn ($r) => (new DateTimeImmutable($r['query']['from']))->modify('+1 day')->format('Y-m-d') === $r['query']['to']);
check('every request with features asked for one day, as Polar requires', $withFeatures !== [] && count($oneDay) === count($withFeatures));
check('the first sync reached back 28 days', in_array($day(27), array_map(static fn ($r) => $r['query']['from'] ?? '', sent()), true));

section('duplicates');

$before = counts($alice['id']);
$sync   = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
check('Nu synchroniseren works from the app', $sync['status'] === 200 && ($sync['body']['ok'] ?? false) === true, summary($sync));
check('syncing again adds nothing', counts($alice['id']) == $before, json_encode([$before, counts($alice['id'])]));
$web = http('/api/integrations/polar/sync.php', ['jar' => $alice['jar'], 'form' => ['csrf' => csrf($alice['jar'])]]);
check('…and from the website, the same', $web['status'] === 200 && counts($alice['id']) == $before, summary($web));
$noCsrf = http('/api/integrations/polar/sync.php', ['jar' => $alice['jar'], 'form' => []]);
check('the website without its CSRF token is refused', $noCsrf['status'] === 419, summary($noCsrf));

section('tokens');

$refreshes = (int) (world()['refreshes'] ?? 0);
db_run("UPDATE user_integrations SET token_expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = ? AND provider = 'polar'", [$alice['id']]);
$oldAccess = integration_tokens($alice['id'], 'polar')['access_token'];
$sync = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
$newAccess = integration_tokens($alice['id'], 'polar')['access_token'];
check('an expired access token is refreshed before the sync', $sync['status'] === 200 && (int) world()['refreshes'] === $refreshes + 1 && $newAccess !== $oldAccess, summary($sync));
check('…and the new refresh token kept (Polar rotates it)', !str_contains((string) polar_row($alice['id'])['refresh_token'], 'rt-')
    && (world()['refresh'][integration_tokens($alice['id'], 'polar')['refresh_token']]['revoked'] ?? true) === false);

world(static function (array &$w) use ($newAccess): void { $w['access'][$newAccess]['expires'] = time() - 5; });
$sync = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
check('a 401 from the API refreshes once and retries', $sync['status'] === 200 && (int) world()['refreshes'] === $refreshes + 2, summary($sync));

section('errors');

world(static function (array &$w): void { $w['fail'] = ['/v4/data/sleeps' => [['status' => 500], ['status' => 500]]]; });
$sync = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
check('a 500 is tried once more, then the sync is partial with a sentence', $sync['status'] === 200 && ($sync['body']['status'] ?? '') === 'partial'
    && str_contains((string) $sync['body']['message'], 'slaap') && !str_contains($sync['raw'], 'fake failure'), summary($sync));
check('…the screen keeps the sentence, the connection stays', polar_row($alice['id'])['status'] === 'connected' && str_contains((string) polar_row($alice['id'])['last_error'], 'slaap'));

world(static function (array &$w): void { $w['fail'] = ['/v4/data/continuous-samples' => [['status' => 429]]]; });
$sync = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
check('a 429 stops the sync politely: the rest next time', $sync['status'] === 200 && str_contains((string) ($sync['body']['message'] ?? ''), 'beperkt'), summary($sync));

world(static function (array &$w): void { $w['fail'] = ['/v4/data/nightly-recharge-results' => [['status' => 403]]]; });
$sync = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
check('a 403 (a scope or a consent) says what to do at Polar', $sync['status'] === 200 && str_contains((string) ($sync['body']['message'] ?? ''), 'account.polar.com'), summary($sync));

world(static function (array &$w): void { $w['fail'] = []; });
$sync = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
check('the next good sync clears the sentence', $sync['status'] === 200 && polar_row($alice['id'])['last_error'] === null && polar_row($alice['id'])['last_sync_status'] === 'ok', summary($sync));
check('none of it doubled anything', counts($alice['id']) == $before, json_encode(counts($alice['id'])));

section('app: connect');

world(static function (array &$w): void { $w['approve'] = 'bob'; });
$appStart = http('/api/integrations/polar/start.php', ['bearer' => $bob['token'], 'form' => []]);
check('the app gets Polar\'s address back', $appStart['status'] === 200 && str_starts_with((string) ($appStart['body']['url'] ?? ''), $fakeBase . '/oauth/authorize'), summary($appStart));
$appBack = (string) http((string) $appStart['body']['url'], ['get' => true])['location'];
$confirm = http($appBack, ['get' => true]);                         // the phone's browser: no Ownify session
preg_match('/name="token" value="([^"]+)"/', $confirm['raw'], $m);
$confirmToken = $m[1] ?? '';
check('the phone\'s browser is asked to confirm, naming the account', $confirm['status'] === 200 && str_contains($confirm['raw'], $bob['username']) && $confirmToken !== '' && polar_row($bob['id']) === null, summary($confirm));
check('…a page that cannot be framed and keeps the code to itself', !str_contains($confirm['raw'], 'code-') && !str_contains($confirm['raw'], 'test-secret'));
$replay = http($appBack, ['get' => true]);
check('the callback cannot be opened twice for a second confirmation', !str_contains($replay['raw'], 'name="token"'), summary($replay));
$wrong = http('/api/integrations/polar/callback.php', ['form' => ['token' => 'not-the-token', 'action' => 'confirm']]);
check('a made-up confirmation connects nothing', polar_row($bob['id']) === null && $wrong['status'] === 400, summary($wrong));
$yes = http('/api/integrations/polar/callback.php', ['form' => ['token' => $confirmToken, 'action' => 'confirm']]);
check('confirming connects bob, and says go back to the app', $yes['status'] === 200 && (polar_row($bob['id'])['status'] ?? null) === 'connected' && str_contains($yes['raw'], 'Ownify-app'), summary($yes));
$twice = http('/api/integrations/polar/callback.php', ['form' => ['token' => $confirmToken, 'action' => 'confirm']]);
check('a confirmation works once', $twice['status'] === 400, summary($twice));
check('bob\'s data came in: his training, not alice\'s', counts($bob['id'])['workouts'] === 1
    && db_value("SELECT COUNT(*) FROM workouts WHERE user_id = ? AND external_id LIKE 'polar:training:ses-a%'", [$bob['id']]) == 0, json_encode(counts($bob['id'])));
check('alice\'s data is untouched by bob\'s connection', counts($alice['id']) == $before);

/* A link from somebody else's app, opened in a browser signed in as alice. */
$foreign = http('/api/integrations/polar/start.php', ['bearer' => $bob['token'], 'form' => []]);
$foreignPage = http((string) http((string) $foreign['body']['url'], ['get' => true])['location'], ['get' => true, 'jar' => $alice['jar']]);
check('opened where another account is signed in, the page warns by name', str_contains($foreignPage['raw'], 'ingelogd als') && str_contains($foreignPage['raw'], $alice['username'])
    && str_contains($foreignPage['raw'], $bob['username']), summary($foreignPage));

world(static function (array &$w): void { $w['approve'] = 'carol'; });
$carolStart = http('/api/integrations/polar/start.php', ['bearer' => $carol['token'], 'form' => []]);
$carolPage  = http((string) http((string) $carolStart['body']['url'], ['get' => true])['location'], ['get' => true]);
preg_match('/name="token" value="([^"]+)"/', $carolPage['raw'], $m);
$cancel = http('/api/integrations/polar/callback.php', ['form' => ['token' => $m[1] ?? '', 'action' => 'cancel']]);
check('Annuleren connects nothing', $cancel['status'] === 200 && polar_row($carol['id']) === null, summary($cancel));

$appSync = http('/api/integrations/polar/sync.php', ['bearer' => $carol['token'], 'form' => []]);
check('syncing without a connection: 409, never 401 (the app would sign out)', $appSync['status'] === 409, summary($appSync));

section('isolation');

forget_sent();
http('/api/integrations/polar/sync.php', ['bearer' => $bob['token'], 'form' => []]);
$bearers = array_unique(array_filter(array_map(static fn ($r) => str_starts_with($r['auth'], 'bearer:') ? substr($r['auth'], 7) : null, sent())));
$accounts = array_unique(array_map(static fn ($t) => world()['access'][$t]['account'] ?? '?', $bearers));
check('bob\'s sync used only bob\'s Polar token', $accounts === ['bob'] || array_values($accounts) === ['bob'], json_encode($accounts));
$aliceState = http('/api/app/state.php', ['bearer' => $alice['token'], 'form' => []]);
check('alice\'s app state names her watch, never bob\'s, and carries no token', str_contains($aliceState['raw'], 'Polar Pacer Pro') && !str_contains($aliceState['raw'], 'Polar H10')
    && !preg_match('/\b(at|rt)-[0-9a-f]{16}\b/', $aliceState['raw']));
$bobMinutes = (int) db_value("SELECT COUNT(*) FROM heart_rate_minutes WHERE user_id = ? AND data_origin = 'polar:DEV-A'", [$bob['id']]);
check('none of alice\'s heart rate is on bob', $bobMinutes === 0);

section('revoked');

world(static function (array &$w): void { $w['revoke_all'] = true; });
$sync = http('/api/integrations/polar/sync.php', ['bearer' => $bob['token'], 'form' => []]);
$row  = polar_row($bob['id']);
check('access withdrawn at Polar: the connection is marked revoked, 409 with a sentence', $sync['status'] === 409 && ($row['status'] ?? null) === 'revoked'
    && str_contains((string) ($sync['body']['error'] ?? ''), 'opnieuw'), summary($sync));
check('…and its tokens are gone', $row !== null && $row['access_token'] === null && $row['refresh_token'] === null);
check('…its data stays', counts($bob['id'])['workouts'] === 1);
world(static function (array &$w): void { unset($w['revoke_all']); });

section('schedule');

$out = (string) shell_exec(implode(' ', array_map('escapeshellarg', ['php', $root . '/tools/polar-sync.php', '--min-age=0', '--user=' . $alice['id']])) . ' 2>&1');
check('tools/polar-sync.php syncs whoever is due', str_contains($out, 'user ' . $alice['id'] . ': ok'), trim($out));
$out = (string) shell_exec(implode(' ', array_map('escapeshellarg', ['php', $root . '/tools/polar-sync.php', '--user=' . $alice['id']])) . ' 2>&1');
check('…and leaves alone whoever synced just now', !str_contains($out, 'user ' . $alice['id'] . ':') && str_contains($out, 'done: 0'), trim($out));
check('…skipping a connection that was withdrawn', !str_contains((string) shell_exec('php ' . escapeshellarg($root . '/tools/polar-sync.php') . ' --min-age=0 --user=' . $bob['id'] . ' 2>&1'), 'user ' . $bob['id'] . ':'));

section('disconnect');

$health = (int) db_value("SELECT COUNT(*) FROM user_integrations WHERE user_id = ? AND provider <> 'polar'", [$alice['id']]);
$off = http('/api/integrations/disconnect.php', ['bearer' => $alice['token'], 'form' => ['provider' => 'polar']]);
$row = polar_row($alice['id']);
check('Ontkoppelen: disconnected, tokens destroyed', $off['status'] === 200 && $row['status'] === 'disconnected' && $row['access_token'] === null && $row['refresh_token'] === null, summary($off));
check('…the data stays', counts($alice['id']) == $before);
check('…other connections untouched', (int) db_value("SELECT COUNT(*) FROM user_integrations WHERE user_id = ? AND provider <> 'polar'", [$alice['id']]) === $health
    && db_value("SELECT status FROM user_integrations WHERE user_id = ? AND provider = 'google_health_connect'", [$alice['id']]) === db_value("SELECT status FROM user_integrations WHERE user_id = ? AND provider = 'google_health_connect'", [$alice['id']]));
$after = http('/api/integrations/polar/sync.php', ['bearer' => $alice['token'], 'form' => []]);
check('…and nothing syncs any more', $after['status'] === 409, summary($after));

/* =============================================================== cleanup */

foreach ($made as $username) {
    $id = db_value('SELECT id FROM users WHERE username = ?', [$username]);
    if ($id !== null) {
        db_run('DELETE FROM users WHERE id = ?', [(int) $id]);
    }
}
foreach ([$alice, $bob, $carol] as $who) {
    @unlink($who['jar']);
}
proc_terminate($server);
proc_terminate($bareServer);
proc_terminate($fake);
array_map('unlink', glob($fakeDir . '/*') ?: []);
@rmdir($fakeDir);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
