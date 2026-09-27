<?php
/**
 * The app's account sign-in, tested end to end over HTTP.
 *
 *     DB_NAME=jolu_dev DB_USER=root php tools/app-auth-test.php
 *     PRE13_DB=jolu_pre13 DB_NAME=… php tools/app-auth-test.php   # also the before-013 checks
 *
 * Starts the app on PHP's built-in server (tools/app-auth-router.php), and a
 * second one standing in for Google's key endpoint with keys made here, then
 * makes the requests the website and the JoLu app make: registering, signing
 * in, pairing, syncing, changing a goal, signing out — through the real
 * endpoints, with the real authentication, on the database named by DB_NAME.
 * Nothing is stubbed except Google itself, whose ID tokens this signs with
 * its own key.
 *
 * DB_NAME must be named explicitly and must have migration 013: the test
 * makes accounts (names starting `at_`) and removes them again at the end,
 * with everything hanging off them. Never point it at a live database.
 *
 * PRE13_DB, when set, names a database WITHOUT migration 013 (schema.sql,
 * then `ALTER TABLE user_devices DROP COLUMN scope; DROP TABLE auth_attempts;`)
 * to check that everything that worked before still does.
 *
 * Exit code 0 when every check passes. The password below is a test password
 * for throwaway accounts, in the repository on purpose; the Google client ids
 * are made up and only ever meet this test's own stand-in.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 013 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/devices.php';
require_once $root . '/includes/goals.php';
require_once $root . '/includes/google-signin.php';

if (!db_available() || !devices_scoped()) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 013.\n");
    exit(2);
}

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

/** Starts a built-in server; returns its process. */
function serve(int $port, string $docroot, ?string $router, array $env)
{
    $command = ['php', '-S', '127.0.0.1:' . $port, '-t', $docroot];
    if ($router !== null) {
        $command[] = $router;
    }

    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $docroot, $env + getenv());

    for ($i = 0; $i < 100; $i++) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket !== false) {
            fclose($socket);
            return $process;
        }
        usleep(50_000);
    }

    fwrite(STDERR, "A test server on port $port did not start.\n");
    exit(2);
}

/**
 * One HTTP request. $o: json (array), form (array), raw (string), bearer,
 * authorization (a whole header value), jar (cookie file), from (local
 * address to send from), base.
 *
 * @return array{status: int, body: ?array, raw: string, headers: string}
 */
function http(string $path, array $o = []): array
{
    global $base;

    $handle  = curl_init(($o['base'] ?? $base) . $path);
    $headers = [];

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if (($o['get'] ?? false) !== true) {
        curl_setopt($handle, CURLOPT_POST, true);

        if (isset($o['json'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($o['json']));
        } elseif (isset($o['form'])) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($o['form']));
        } else {
            /* A raw body is JSON, as the app sends it. */
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, $o['raw'] ?? '');
        }
    }

    if (isset($o['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    }
    if (isset($o['authorization'])) {
        $headers[] = 'Authorization: ' . $o['authorization'];
    }
    if (isset($o['jar'])) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $o['jar']);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $o['jar']);
    }
    if (isset($o['from'])) {
        curl_setopt($handle, CURLOPT_INTERFACE, $o['from']);
    }

    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

    $response = (string) curl_exec($handle);
    $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $split    = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    unset($handle);

    $raw  = substr($response, $split);
    $body = json_decode($raw, true);

    return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw, 'headers' => substr($response, 0, $split)];
}

function summary(array $r): string
{
    return 'HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 300);
}

/* ---------------------------------------------------------- a browser */

function browser(): array
{
    return ['jar' => tempnam(sys_get_temp_dir(), 'jar')];
}

/** The CSRF token the page hands the browser. */
function csrf(array $browser, ?string $baseUrl = null): string
{
    $page = http('/', ['get' => true, 'jar' => $browser['jar']] + ($baseUrl === null ? [] : ['base' => $baseUrl]));
    preg_match('/data-csrf="([^"]+)"/', $page['raw'], $m);

    return $m[1] ?? '';
}

/** A form post from the page, CSRF token included. */
function web(array $browser, string $path, array $fields = [], ?string $baseUrl = null): array
{
    return http($path, [
        'form' => $fields + ['csrf' => csrf($browser, $baseUrl)],
        'jar'  => $browser['jar'],
    ] + ($baseUrl === null ? [] : ['base' => $baseUrl]));
}

/* --------------------------------------------------------- the accounts */

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'app-auth-test-password';
$made     = [];

function account_name(string $who): string
{
    global $run, $made;

    $name   = 'at_' . $run . '_' . $who;
    $made[] = $name;

    return $name;
}

function user_id_of(string $username): int
{
    return (int) db_value('SELECT id FROM users WHERE username = ?', [$username]);
}

/** Registers on the website and returns the signed-in browser. */
function web_account(string $who, ?string $baseUrl = null): array
{
    global $password;

    $browser  = browser();
    $username = account_name($who);
    $result   = web($browser, '/api/auth/register.php', [
        'username' => $username,
        'email'    => $username . '@jolu-test.invalid',
        'password' => $password,
    ], $baseUrl);

    if (($result['body']['ok'] ?? false) !== true) {
        fwrite(STDERR, "Could not create $username: " . summary($result) . "\n");
        exit(1);
    }

    return $browser + ['username' => $username, 'email' => $username . '@jolu-test.invalid'];
}

/** Pairs a phone the way the website and the app do; returns the sync token. */
function pair(array $browser, string $label = 'Test phone', ?string $baseUrl = null): string
{
    $code = web($browser, '/api/integrations/pairing-code.php', ['provider' => 'google_health_connect'], $baseUrl);
    $pair = http('/api/integrations/pair.php', [
        'json' => ['code' => $code['body']['code'] ?? '', 'label' => $label, 'platform' => 'android', 'app_version' => 'test'],
    ] + ($baseUrl === null ? [] : ['base' => $baseUrl]));

    return (string) ($pair['body']['token'] ?? '');
}

function row_of(string $token): ?array
{
    return db_one('SELECT * FROM user_devices WHERE token_hash = ?', [device_hash($token)]);
}

/** A goal to act on, made the way the wizard's endpoint makes one. */
function goal_for(int $userId, string $name, string $priority): int
{
    return (int) goal_create($userId, [
        'name'         => $name,
        'category'     => 'habit',
        'kind'         => 'accumulate',
        'target_value' => 10,
        'target_unit'  => 'keer',
        'start_date'   => date('Y-m-d'),
        'end_date'     => date('Y-m-d', strtotime('+30 days')),
        'priority'     => $priority,
    ]);
}

function goal_state(int $goalId): string
{
    $goal = db_one('SELECT status, priority FROM goals WHERE id = ?', [$goalId]);

    return $goal === null ? 'gone' : $goal['status'] . '/' . $goal['priority'];
}

/* ---------------------------------------------------- Google, stood in */

$webClient     = '1111-web.apps.googleusercontent.com';
$androidClient = '2222-android.apps.googleusercontent.com';
$googleKey     = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$googleDetails = openssl_pkey_get_details($googleKey);
$impostorKey   = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

$keysDir = sys_get_temp_dir() . '/jolu-app-auth-' . $run;
mkdir($keysDir);
file_put_contents($keysDir . '/jwks.json', json_encode(['keys' => [[
    'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
    'n'   => google_signin_b64url_encode($googleDetails['rsa']['n']),
    'e'   => google_signin_b64url_encode($googleDetails['rsa']['e']),
]]]));

/** An ID token as Credential Manager would hand the app, unless told otherwise. */
function id_token(array $claims, $key = null): string
{
    global $googleKey, $webClient, $androidClient;

    $claims += [
        'iss'            => 'https://accounts.google.com',
        'aud'            => $webClient,
        'azp'            => $androidClient,
        'iat'            => time(),
        'exp'            => time() + 3600,
        'email_verified' => true,
    ];

    $input = google_signin_b64url_encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key', 'typ' => 'JWT']))
        . '.' . google_signin_b64url_encode(json_encode($claims));

    openssl_sign($input, $signature, $key ?? $googleKey, OPENSSL_ALGO_SHA256);

    return $input . '.' . google_signin_b64url_encode($signature);
}

/** A fresh app conversation with its nonce. @return array{jar: string, nonce: string} */
function google_start(): array
{
    $app   = browser();
    $nonce = http('/api/auth/app-google.php', ['json' => ['action' => 'nonce'], 'jar' => $app['jar']]);

    return $app + ['nonce' => (string) ($nonce['body']['nonce'] ?? '')];
}

/* ------------------------------------------------------------- servers */

$googlePort = free_port();
$appPort    = free_port();
$base       = 'http://127.0.0.1:' . $appPort;

$googleServer = serve($googlePort, $keysDir, null, []);
$appEnv       = [
    'JOLU_TEST_GOOGLE_JWKS_URL'        => 'http://127.0.0.1:' . $googlePort . '/jwks.json',
    'GOOGLE_SIGNIN_CLIENT_ID'          => $webClient,
    'GOOGLE_SIGNIN_CLIENT_SECRET'      => 'not-a-secret-test-value',
    'GOOGLE_SIGNIN_REDIRECT_URI'       => $base . '/api/auth/google-callback.php',
    'GOOGLE_SIGNIN_ANDROID_CLIENT_IDS' => $androidClient,
    'PHP_CLI_SERVER_WORKERS'           => '4',
];
$appServer = serve($appPort, $root, $root . '/tools/app-auth-router.php', $appEnv);

$servers = [$googleServer, $appServer];

echo "\nThe app's account sign-in\n";
echo '  database ' . getenv('DB_NAME') . ", run $run, app on $base\n";
echo str_repeat('-', 72) . "\n";

try {

/* ======================================================================
   THE PEOPLE
   ====================================================================== */

$anna  = web_account('anna');                  // the one with the phone
$bram  = web_account('bram');                  // somebody else entirely
$annaId = user_id_of($anna['username']);
$bramId = user_id_of($bram['username']);

$fixture = (string) shell_exec('php ' . escapeshellarg($root . '/tools/hc-verify.php') . ' --fixture');

/* ======================================================================
   EXISTING JOLU INTEGRATIONS  (32–36, first half)
   ====================================================================== */

section('32. pairing still works, and makes a sync token');
$sync = pair($anna, 'Anna Pixel');
check('a 64-hex token comes back', (bool) preg_match('/^[a-f0-9]{64}$/', $sync));
$syncRow = row_of($sync);
check('its row says scope = sync', ($syncRow['scope'] ?? null) === 'sync', json_encode($syncRow['scope'] ?? null));

section('33–35 / 1. the sync token does everything it did');
$status = http('/api/integrations/status.php', ['bearer' => $sync, 'json' => []]);
check('status.php: 200', $status['status'] === 200, summary($status));
$ingest = http('/api/integrations/ingest.php', ['bearer' => $sync, 'raw' => $fixture]);
check('33. ingest.php: 200, the batch is stored', $ingest['status'] === 200 && ($ingest['body']['written'] ?? 0) === 7, summary($ingest));
$profile = http('/api/integrations/profile.php', ['bearer' => $sync, 'json' => []]);
check('34. profile.php: 200, the account\'s own username', $profile['status'] === 200 && ($profile['body']['account']['username'] ?? null) === $anna['username'], summary($profile));
$targets = http('/api/integrations/nutrition-targets.php', ['bearer' => $sync, 'json' => []]);
check('35. nutrition-targets.php: 200', $targets['status'] === 200 && ($targets['body']['ok'] ?? false) === true, summary($targets));

/* ======================================================================
   PASSWORD LOGIN  (8–14)
   ====================================================================== */

section('8. the app signs in with username + password');
$login = http('/api/auth/app-login.php', ['json' => [
    'identifier' => $anna['username'], 'password' => $password,
    'label' => 'Anna Pixel (app)', 'platform' => 'android', 'app_version' => '0.1',
]]);
check('200', $login['status'] === 200, summary($login));
$annaToken = (string) ($login['body']['token'] ?? '');
check('a 64-hex token', (bool) preg_match('/^[a-f0-9]{64}$/', $annaToken));
check('scope account, provider password', ($login['body']['scope'] ?? null) === 'account' && ($login['body']['provider'] ?? null) === 'password');
check('the account: username, avatar, created_at, age', array_keys($login['body']['account'] ?? []) === ['username', 'avatar', 'created_at', 'age']
    && $login['body']['account']['username'] === $anna['username']);
check('no user id, e-mail or hash anywhere in the answer',
    !preg_match('/user_id|"id"|email|_hash|\$2y\$|@/i', $login['raw']), $login['raw']);
check('no stay-signed-in cookie: the app is not a browser', !preg_match('/^Set-Cookie:\s*(__Host-)?jolu_login/mi', $login['headers']));
$annaRow = row_of($annaToken);
check('its row: scope account, the phone\'s Health Connect source',
    ($annaRow['scope'] ?? null) === 'account' && ($annaRow['provider'] ?? null) === 'google_health_connect', json_encode($annaRow));
check('only its SHA-256 is stored, never the token', ($annaRow['token_hash'] ?? null) === hash('sha256', $annaToken)
    && (int) db_value('SELECT COUNT(*) FROM user_devices WHERE token_hash = ?', [$annaToken]) === 0);

section('9. with the e-mail address, in any case');
$byMail = http('/api/auth/app-login.php', ['json' => ['identifier' => strtoupper($anna['email']), 'password' => $password]]);
check('200 and a token', $byMail['status'] === 200 && strlen((string) ($byMail['body']['token'] ?? '')) === 64, summary($byMail));
$byMailToken = (string) ($byMail['body']['token'] ?? '');

section('10, 11, 14. wrong password and unknown name: one answer');
$wrong   = http('/api/auth/app-login.php', ['json' => ['identifier' => $bram['username'], 'password' => 'not-the-password']]);
$unknown = http('/api/auth/app-login.php', ['json' => ['identifier' => 'at_' . $run . '_nobody', 'password' => 'not-the-password']]);
check('10. wrong password: 401', $wrong['status'] === 401, summary($wrong));
check('11. unknown name: 401', $unknown['status'] === 401, summary($unknown));
check('14. the two answers are identical', $wrong['raw'] === $unknown['raw'], $wrong['raw'] . ' vs ' . $unknown['raw']);
$empty = http('/api/auth/app-login.php', ['json' => ['identifier' => '', 'password' => '']]);
check('an empty form: 422, not counted as a guess', $empty['status'] === 422, summary($empty));
$garbage = http('/api/auth/app-login.php', ['raw' => '{not json']);
check('a body that is not JSON: 422', $garbage['status'] === 422, summary($garbage));
$typed = http('/api/auth/app-login.php', ['json' => ['identifier' => ['x'], 'password' => 12345678]]);
check('fields of the wrong type: 422', $typed['status'] === 422, summary($typed));

section('12. five wrong passwords, then the limit — even for the right one');
db_run('DELETE FROM auth_attempts');
$carla   = web_account('carla');
for ($i = 1; $i <= 5; $i++) {
    $r = http('/api/auth/app-login.php', ['json' => ['identifier' => $carla['username'], 'password' => 'wrong-' . $i]]);
    check("wrong password $i: 401", $r['status'] === 401, summary($r));
}
$sixth = http('/api/auth/app-login.php', ['json' => ['identifier' => $carla['username'], 'password' => $password]]);
check('the 6th, with the RIGHT password: 429', $sixth['status'] === 429, summary($sixth));
check('it says how long, in minutes', str_contains((string) ($sixth['body']['error'] ?? ''), 'Te veel mislukte pogingen'), $sixth['raw']);
check('Retry-After header, at most 15 minutes', (bool) preg_match('/^Retry-After:\s*(\d+)/mi', $sixth['headers'], $m) && (int) $m[1] > 0 && (int) $m[1] <= 900, $sixth['headers']);
check('no token in it', !isset($sixth['body']['token']));
$webLimited = web(browser(), '/api/auth/login.php', ['username' => $carla['username'], 'password' => $password]);
check('the website\'s login shares the limit: 429', $webLimited['status'] === 429, summary($webLimited));
check('rows are hashes: no name, no address in auth_attempts',
    (int) db_value("SELECT COUNT(*) FROM auth_attempts WHERE key_hash LIKE '%at\\_%' OR key_hash LIKE '%127.0%'") === 0
    && (int) db_value("SELECT COUNT(*) FROM auth_attempts WHERE key_hash REGEXP '^[a-f0-9]{64}$'") >= 5);

section('14. an unknown name is limited exactly the same way');
$ghost = 'at_' . $run . '_ghost';
for ($i = 1; $i <= 5; $i++) {
    http('/api/auth/app-login.php', ['json' => ['identifier' => $ghost, 'password' => 'x-' . $i]]);
}
$ghost6 = http('/api/auth/app-login.php', ['json' => ['identifier' => $ghost, 'password' => 'x']]);
check('429 for a name with no account', $ghost6['status'] === 429, summary($ghost6));
check('the same words as for a real account', preg_replace('/\d+/', 'N', (string) $ghost6['body']['error']) === preg_replace('/\d+/', 'N', (string) $sixth['body']['error']));

section('distinct identifiers: somebody else\'s name is not limited');
$other = http('/api/auth/app-login.php', ['json' => ['identifier' => $bram['username'], 'password' => $password]]);
check('bram signs in from the same address: 200', $other['status'] === 200, summary($other));
http('/api/auth/app-logout.php', ['bearer' => (string) ($other['body']['token'] ?? '')]);

section('distinct IPs: the same name from another address is not limited');
$fromElsewhere = http('/api/auth/login.php', [
    'form' => ['username' => $carla['username'], 'password' => $password, 'csrf' => csrf($elsewhere = browser())],
    'jar'  => $elsewhere['jar'],
    'from' => '127.0.0.2',
]);
check('carla on the website from 127.0.0.2: 200', $fromElsewhere['status'] === 200, summary($fromElsewhere));
$plainHttp = http('/api/auth/app-login.php', ['json' => ['identifier' => $bram['username'], 'password' => $password], 'from' => '127.0.0.2']);
check('the app over plain http from anything but localhost: 403', $plainHttp['status'] === 403 && !isset($plainHttp['body']['token']), summary($plainHttp));

section('13. after the window, the right password works and clears the count');
db_run('UPDATE auth_attempts SET attempted_at = attempted_at - INTERVAL 16 MINUTE');
$later = http('/api/auth/app-login.php', ['json' => ['identifier' => $carla['username'], 'password' => $password]]);
check('200 and a token', $later['status'] === 200 && isset($later['body']['token']), summary($later));
for ($i = 1; $i <= 4; $i++) {
    http('/api/auth/app-login.php', ['json' => ['identifier' => $carla['username'], 'password' => 'wrong-again-' . $i]]);
}
$afterClear = http('/api/auth/app-login.php', ['json' => ['identifier' => $carla['username'], 'password' => $password]]);
check('the count restarted: 4 new failures do not block', $afterClear['status'] === 200, summary($afterClear));
http('/api/auth/app-logout.php', ['bearer' => (string) ($later['body']['token'] ?? '')]);
http('/api/auth/app-logout.php', ['bearer' => (string) ($afterClear['body']['token'] ?? '')]);
check('a success leaves no failures behind', (int) db_value('SELECT COUNT(*) FROM auth_attempts WHERE attempted_at > NOW() - INTERVAL 15 MINUTE AND key_hash = ?',
    [hash('sha256', "login\n" . mb_strtolower($carla['username']) . "\n127.0.0.1")]) === 0);

/* ======================================================================
   TOKEN SCOPE + THE PILOT  (1–7, 28–31)
   ====================================================================== */

$annaPrimary   = goal_for($annaId, 'Anna eerste', 'primary');
$annaSecondary = goal_for($annaId, 'Anna tweede', 'secondary');

section('2 / 30. a sync token cannot change a goal');
$bySync = http('/api/goals/update.php', ['bearer' => $sync, 'json' => ['goal_id' => $annaSecondary, 'action' => 'pause']]);
check('403', $bySync['status'] === 403, summary($bySync));
check('the goal is untouched', goal_state($annaSecondary) === 'active/secondary', goal_state($annaSecondary));
$bySyncForm = http('/api/goals/update.php', ['bearer' => $sync, 'form' => ['goal_id' => $annaSecondary, 'action' => 'pause']]);
check('as a form post too: 403', $bySyncForm['status'] === 403, summary($bySyncForm));

section('3 / 29. an account token can');
$byAccount = http('/api/goals/update.php', ['bearer' => $annaToken, 'json' => ['goal_id' => $annaSecondary, 'action' => 'pause']]);
check('200', $byAccount['status'] === 200, summary($byAccount));
check('the goal is paused', goal_state($annaSecondary) === 'paused/secondary', goal_state($annaSecondary));
$byAccountForm = http('/api/goals/update.php', ['bearer' => $annaToken, 'form' => ['goal_id' => $annaSecondary, 'action' => 'resume']]);
check('a form post with the token works too, without CSRF', $byAccountForm['status'] === 200 && goal_state($annaSecondary) === 'active/secondary', summary($byAccountForm));
$notHers = http('/api/goals/update.php', ['bearer' => $annaToken, 'json' => ['goal_id' => goal_for($bramId, 'Bram', 'primary'), 'action' => 'pause']]);
check('somebody else\'s goal: 404', $notHers['status'] === 404, summary($notHers));

section('28. the website, session + CSRF, exactly as before');
$bySession = web($anna, '/api/goals/update.php', ['goal_id' => $annaSecondary, 'action' => 'pause']);
check('200', $bySession['status'] === 200, summary($bySession));
check('paused', goal_state($annaSecondary) === 'paused/secondary');

section('26. and without a good CSRF token it is refused');
$noCsrf = http('/api/goals/update.php', ['form' => ['goal_id' => $annaSecondary, 'action' => 'resume'], 'jar' => $anna['jar']]);
check('no token: 419', $noCsrf['status'] === 419, summary($noCsrf));
$badCsrf = http('/api/goals/update.php', ['form' => ['goal_id' => $annaSecondary, 'action' => 'resume', 'csrf' => str_repeat('0', 64)], 'jar' => $anna['jar']]);
check('a wrong token: 419', $badCsrf['status'] === 419, summary($badCsrf));
check('still paused', goal_state($annaSecondary) === 'paused/secondary');
$noSession = web(browser(), '/api/goals/update.php', ['goal_id' => $annaSecondary, 'action' => 'resume']);
check('a browser that is not signed in: 401', $noSession['status'] === 401, summary($noSession));

section('31. every action does the same thing either way');
$dora = web_account('dora');
$eva  = web_account('eva');
$doraGoals = [goal_for(user_id_of($dora['username']), 'D1', 'primary'), goal_for(user_id_of($dora['username']), 'D2', 'secondary')];
$evaGoals  = [goal_for(user_id_of($eva['username']), 'E1', 'primary'), goal_for(user_id_of($eva['username']), 'E2', 'secondary')];
$evaToken  = (string) (http('/api/auth/app-login.php', ['json' => ['identifier' => $eva['username'], 'password' => $password]])['body']['token'] ?? '');
$script    = [[1, 'pause'], [1, 'resume'], [1, 'primary'], [0, 'secondary'], [0, 'pause'], [1, 'complete'], [0, 'resume'], [1, 'bogus']];
$trail     = ['web' => [], 'app' => []];
foreach ($script as [$which, $action]) {
    $w = web($dora, '/api/goals/update.php', ['goal_id' => $doraGoals[$which], 'action' => $action]);
    $a = http('/api/goals/update.php', ['bearer' => $evaToken, 'json' => ['goal_id' => $evaGoals[$which], 'action' => $action]]);
    $trail['web'][] = $w['status'] . ' ' . goal_state($doraGoals[0]) . ' ' . goal_state($doraGoals[1]);
    $trail['app'][] = $a['status'] . ' ' . goal_state($evaGoals[0]) . ' ' . goal_state($evaGoals[1]);
}
check('the same statuses and the same goals after 8 actions', $trail['web'] === $trail['app'],
    json_encode($trail));
check('an unknown action: 400 both ways', str_starts_with(end($trail['web']), '400') && str_starts_with(end($trail['app']), '400'));
$missing = http('/api/goals/update.php', ['bearer' => $evaToken, 'json' => ['action' => 'pause']]);
check('no goal id: 400', $missing['status'] === 400, summary($missing));

section('6, 7. tokens that are nobody\'s, and headers that are not tokens');
$nobody = str_repeat('ab', 32);
foreach ([
    'an unknown token'         => ['bearer' => $nobody],
    'a token of the wrong shape' => ['bearer' => 'not-a-token'],
    'a sync token with junk after it' => ['authorization' => 'Bearer ' . $sync . 'x'],
] as $label => $auth) {
    $p = http('/api/goals/update.php', $auth + ['json' => ['goal_id' => $annaSecondary, 'action' => 'resume']]);
    $s = http('/api/integrations/status.php', $auth + ['json' => []]);
    check("$label: 401 on the pilot and on status.php", $p['status'] === 401 && $s['status'] === 401, summary($p) . ' / ' . summary($s));
}
foreach ([
    '"Bearer" and nothing else' => 'Bearer',
    'Basic credentials'         => 'Basic ' . base64_encode('anna:' . $password),
    'another scheme'            => 'Token ' . $annaToken,
] as $label => $header) {
    $p = http('/api/goals/update.php', ['authorization' => $header, 'json' => ['goal_id' => $annaSecondary, 'action' => 'resume']]);
    check("$label: no token, so the website's rules — 419 without CSRF", $p['status'] === 419, summary($p));
}
check('the goal never moved', goal_state($annaSecondary) === 'paused/secondary');

section('the final check: a sync token is a sync token');
foreach ([
    '/api/profile/update.php'  => ['first_name' => 'Gestolen'],
    '/api/friends/request.php' => ['action' => 'send', 'username' => $bram['username']],
    '/api/friends/settings.php' => ['allow_requests' => '0'],
    '/api/goals/create.php'    => ['name' => 'Gestolen doel', 'category' => 'habit', 'type' => 'accumulate', 'duration' => 'week', 'target_value' => '5'],
    '/api/goals/delete.php'    => ['goal_id' => (string) $annaPrimary],
    '/api/health/rating.php'   => ['rating' => '5'],
    '/api/profile/username.php' => ['username' => 'at_' . $run . '_stolen'],
] as $path => $fields) {
    $r = http($path, ['bearer' => $sync, 'form' => $fields]);
    check("sync token on $path: refused (" . $r['status'] . ')', $r['status'] >= 400 && ($r['body']['ok'] ?? null) !== true, summary($r));
}
check('nothing changed: name, friends, goals, username',
    db_value('SELECT first_name FROM user_profiles WHERE user_id = ?', [$annaId]) === null
    && (int) db_value('SELECT COUNT(*) FROM friendships WHERE requested_by = ?', [$annaId]) === 0
    && (int) db_value('SELECT COUNT(*) FROM goals WHERE user_id = ?', [$annaId]) === 2
    && user_id_of($anna['username']) === $annaId);

/* ======================================================================
   16. ONE PHONE, ONE ROW
   ====================================================================== */

section('16. signing in on the phone that was paired: its row becomes the account row');
$fern     = web_account('fern');
$fernId   = user_id_of($fern['username']);
$fernSync = pair($fern, 'Fern Pixel');
http('/api/integrations/ingest.php', ['bearer' => $fernSync, 'raw' => $fixture]);
$before   = row_of($fernSync);
$upgrade  = http('/api/auth/app-login.php', ['bearer' => $fernSync, 'json' => [
    'identifier' => $fern['username'], 'password' => $password, 'platform' => 'android', 'app_version' => '0.2',
]]);
$fernToken = (string) ($upgrade['body']['token'] ?? '');
$after     = row_of($fernToken);
check('200', $upgrade['status'] === 200, summary($upgrade));
check('a NEW token, not the sync token', $fernToken !== '' && $fernToken !== $fernSync);
check('on the same row: same id, label and sync history', $after !== null && $before !== null
    && $after['id'] === $before['id'] && $after['label'] === 'Fern Pixel' && $after['last_sync_at'] === $before['last_sync_at'], json_encode([$before, $after]));
check('which now says scope account, app_version 0.2', ($after['scope'] ?? null) === 'account' && ($after['app_version'] ?? null) === '0.2');
check('one live row for fern', (int) db_value('SELECT COUNT(*) FROM user_devices WHERE user_id = ? AND revoked_at IS NULL', [$fernId]) === 1);
$oldDead = http('/api/integrations/status.php', ['bearer' => $fernSync, 'json' => []]);
check('the old sync token stopped working: 401', $oldDead['status'] === 401, summary($oldDead));
$stillSyncs = http('/api/integrations/ingest.php', ['bearer' => $fernToken, 'raw' => $fixture]);
check('the account token syncs: ingest 200', $stillSyncs['status'] === 200 && ($stillSyncs['body']['ok'] ?? false) === true, summary($stillSyncs));
$listed = devices_for_user($fernId, 'google_health_connect');
check('Settings lists it, once', count($listed) === 1 && (int) $listed[0]['id'] === (int) $after['id']);

section('...and a phone paired to SOMEBODY ELSE is left alone');
$bramSync = pair($bram, 'Gedeelde telefoon');
$crossed  = http('/api/auth/app-login.php', ['bearer' => $bramSync, 'json' => ['identifier' => $fern['username'], 'password' => $password]]);
check('fern signs in: 200, a new row of her own', $crossed['status'] === 200
    && (int) (row_of((string) ($crossed['body']['token'] ?? ''))['user_id'] ?? 0) === $fernId, summary($crossed));
check('bram\'s sync token still works', http('/api/integrations/status.php', ['bearer' => $bramSync, 'json' => []])['status'] === 200);
check('bram\'s row is still his, still sync', (row_of($bramSync)['scope'] ?? null) === 'sync' && (int) row_of($bramSync)['user_id'] === $bramId);
http('/api/auth/app-logout.php', ['bearer' => (string) ($crossed['body']['token'] ?? '')]);

/* ======================================================================
   REGISTRATION  (15–20)
   ====================================================================== */

section('15, 20. the app registers an account');
$gijs = 'at_' . $run . '_gijs';
$made[] = $gijs;
$reg = http('/api/auth/app-register.php', ['json' => [
    'email' => $gijs . '@jolu-test.invalid', 'username' => $gijs, 'password' => $password,
    'label' => 'Gijs Pixel', 'platform' => 'android',
]]);
check('200', $reg['status'] === 200, summary($reg));
$gijsToken = (string) ($reg['body']['token'] ?? '');
check('20. a token with scope account', strlen($gijsToken) === 64 && ($reg['body']['scope'] ?? null) === 'account'
    && (row_of($gijsToken)['scope'] ?? null) === 'account');
check('the account exists, with a hashed password', user_id_of($gijs) > 0
    && str_starts_with((string) db_value("SELECT password_hash FROM user_auth_identities WHERE user_id = ? AND provider = 'email'", [user_id_of($gijs)]), '$2y$'));
check('nothing about it but username, avatar, created_at, age', array_keys($reg['body']['account'] ?? []) === ['username', 'avatar', 'created_at', 'age']);
check('no persistent sign-in cookie for a browser', !preg_match('/jolu_login/i', $reg['headers']));
check('and no browser sign-in row', (int) db_value('SELECT COUNT(*) FROM user_login_tokens WHERE user_id = ?', [user_id_of($gijs)]) === 0);
check('the token works on the pilot', http('/api/goals/update.php', ['bearer' => $gijsToken, 'json' => ['goal_id' => 999999999, 'action' => 'pause']])['status'] === 404);
check('Settings shows the phone under Health Connect', count(devices_for_user(user_id_of($gijs), 'google_health_connect')) === 1);

section('16–19. what registering refuses');
$cases = [
    '16. a taken username'   => ['email' => 'at_' . $run . '_x1@jolu-test.invalid', 'username' => $gijs, 'password' => $password],
    '17. a taken address'    => ['email' => $gijs . '@jolu-test.invalid', 'username' => 'at_' . $run . '_x2', 'password' => $password],
    '18. an invalid username' => ['email' => 'at_' . $run . '_x3@jolu-test.invalid', 'username' => 'no spaces!', 'password' => $password],
    '18. a username too short' => ['email' => 'at_' . $run . '_x4@jolu-test.invalid', 'username' => 'ab', 'password' => $password],
    '19. a password too short' => ['email' => 'at_' . $run . '_x5@jolu-test.invalid', 'username' => 'at_' . $run . '_x5', 'password' => 'short'],
    'an invalid address'     => ['email' => 'not-an-address', 'username' => 'at_' . $run . '_x6', 'password' => $password],
    'nothing at all'         => [],
];
foreach ($cases as $label => $body) {
    $r = http('/api/auth/app-register.php', ['json' => $body]);
    check("$label: 422, no token", $r['status'] === 422 && !isset($r['body']['token']), summary($r));
}
$wrongType = http('/api/auth/app-register.php', ['json' => ['email' => ['a'], 'username' => 1, 'password' => null]]);
check('fields of the wrong type: 422', $wrongType['status'] === 422, summary($wrongType));
check('none of them made an account', (int) db_value("SELECT COUNT(*) FROM users WHERE username LIKE ?", ['at\\_' . $run . '\\_x%']) === 0);

section('registering: trying taken addresses is limited like guessing passwords');
for ($i = 1; $i <= 4; $i++) {
    http('/api/auth/app-register.php', ['json' => ['email' => $gijs . '@jolu-test.invalid', 'username' => 'at_' . $run . '_y' . $i, 'password' => $password]]);
}
$probe = http('/api/auth/app-register.php', ['json' => ['email' => $gijs . '@jolu-test.invalid', 'username' => 'at_' . $run . '_y9', 'password' => $password]]);
check('the 6th try with a taken address: 429', $probe['status'] === 429, summary($probe));
$shortOnes = 'at_' . $run . '_h@jolu-test.invalid';
for ($i = 1; $i <= 6; $i++) {
    http('/api/auth/app-register.php', ['json' => ['email' => $shortOnes, 'username' => 'at_' . $run . '_h', 'password' => 'short']]);
}
$made[] = 'at_' . $run . '_h';
$fine = http('/api/auth/app-register.php', ['json' => ['email' => $shortOnes, 'username' => 'at_' . $run . '_h', 'password' => $password]]);
check('six too-short passwords do not count: the 7th, valid, registers', $fine['status'] === 200, summary($fine));
http('/api/auth/app-logout.php', ['bearer' => (string) ($fine['body']['token'] ?? '')]);

/* ======================================================================
   LOGOUT, REVOCATION, EXPIRY  (4, 5, 21–23, 36)
   ====================================================================== */

section('21. signing out revokes the token');
$out = http('/api/auth/app-logout.php', ['bearer' => $gijsToken]);
check('200, revoked', $out['status'] === 200 && ($out['body']['revoked'] ?? null) === true, summary($out));
$afterOut = http('/api/goals/update.php', ['bearer' => $gijsToken, 'json' => ['goal_id' => 1, 'action' => 'pause']]);
check('4. the revoked token: 401 on the pilot', $afterOut['status'] === 401, summary($afterOut));
check('   and 401 on ingest', http('/api/integrations/ingest.php', ['bearer' => $gijsToken, 'json' => ['records' => []]])['status'] === 401);
check('   and the phone is gone from Settings', count(devices_for_user(user_id_of($gijs), 'google_health_connect')) === 0);

section('22. signing out again');
$again = http('/api/auth/app-logout.php', ['bearer' => $gijsToken]);
check('200, revoked: false', $again['status'] === 200 && ($again['body']['revoked'] ?? null) === false, summary($again));
check('an unknown token: 200, revoked: false', (http('/api/auth/app-logout.php', ['bearer' => $nobody])['body']['revoked'] ?? null) === false);
check('no token at all: 400', http('/api/auth/app-logout.php', ['json' => []])['status'] === 400);

section('23. signing out touches nothing else');
$annaOut = http('/api/auth/app-logout.php', ['bearer' => $byMailToken]);
check('anna signs one app install out', ($annaOut['body']['revoked'] ?? null) === true);
check('her other app token still works', http('/api/goals/update.php', ['bearer' => $annaToken, 'json' => ['goal_id' => $annaSecondary, 'action' => 'resume']])['status'] === 200);
check('her paired phone still syncs', http('/api/integrations/ingest.php', ['bearer' => $sync, 'raw' => $fixture])['status'] === 200);
check('her browser is still signed in', (http('/api/auth/session.php', ['jar' => $anna['jar']])['body']['signed_in'] ?? null) === true);
$syncOut = http('/api/auth/app-logout.php', ['bearer' => $sync]);
check('a sync token is not revoked by the app\'s sign-out', ($syncOut['body']['revoked'] ?? null) === false
    && http('/api/integrations/status.php', ['bearer' => $sync, 'json' => []])['status'] === 200);

section('5. an account token unused for a year');
$hugo      = web_account('hugo');
$hugoToken = (string) (http('/api/auth/app-login.php', ['json' => ['identifier' => $hugo['username'], 'password' => $password]])['body']['token'] ?? '');
db_run('UPDATE user_devices SET last_seen_at = NOW() - INTERVAL 364 DAY WHERE token_hash = ?', [device_hash($hugoToken)]);
check('364 days: still works', http('/api/goals/update.php', ['bearer' => $hugoToken, 'json' => ['goal_id' => 1, 'action' => 'pause']])['status'] === 404);
check('and using it starts the year again', strtotime((string) row_of($hugoToken)['last_seen_at']) > time() - 60);
db_run('UPDATE user_devices SET last_seen_at = NOW() - INTERVAL 366 DAY WHERE token_hash = ?', [device_hash($hugoToken)]);
check('366 days: 401 on the pilot', http('/api/goals/update.php', ['bearer' => $hugoToken, 'json' => ['goal_id' => 1, 'action' => 'pause']])['status'] === 401);
check('366 days: 401 on status.php', http('/api/integrations/status.php', ['bearer' => $hugoToken, 'json' => []])['status'] === 401);
check('it is not listed in Settings, and not counted', count(devices_for_user(user_id_of($hugo['username']))) === 0
    && device_count(user_id_of($hugo['username']), 'google_health_connect') === 0);
check('it stays lapsed: the failed use did not revive it', strtotime((string) row_of($hugoToken)['last_seen_at']) < time() - 365 * 86400);
$hugoSync = pair($hugo, 'Hugo oud');
db_run('UPDATE user_devices SET last_seen_at = NOW() - INTERVAL 800 DAY WHERE token_hash = ?', [device_hash($hugoSync)]);
check('a sync token does not lapse: 800 days unused, still 200', http('/api/integrations/status.php', ['bearer' => $hugoSync, 'json' => []])['status'] === 200);

section('36. revoking on the website: sync and account tokens alike');
$annaDevices = devices_for_user($annaId, 'google_health_connect');
check('anna\'s Settings lists her paired phone and her app, each once', count($annaDevices) === 2, json_encode($annaDevices));
$revokeSync = web($anna, '/api/integrations/device-revoke.php', ['device' => (string) row_of($sync)['id']]);
check('revoking the paired phone: 200', $revokeSync['status'] === 200, summary($revokeSync));
check('its token: 401 on ingest', http('/api/integrations/ingest.php', ['bearer' => $sync, 'json' => ['records' => []]])['status'] === 401);
$revokeApp = web($anna, '/api/integrations/device-revoke.php', ['device' => (string) row_of($annaToken)['id']]);
check('revoking the app: 200', $revokeApp['status'] === 200, summary($revokeApp));
check('the app\'s next request: 401 (the app signs out)', http('/api/goals/update.php', ['bearer' => $annaToken, 'json' => ['goal_id' => $annaSecondary, 'action' => 'pause']])['status'] === 401);
$bramRevokes = web($bram, '/api/integrations/device-revoke.php', ['device' => (string) row_of($fernToken)['id']]);
check('nobody can revoke somebody else\'s phone: 404', $bramRevokes['status'] === 404 && http('/api/integrations/status.php', ['bearer' => $fernToken, 'json' => []])['status'] === 200);

section('the account token of a suspended account stops acting');
$ivo      = web_account('ivo');
$ivoToken = (string) (http('/api/auth/app-login.php', ['json' => ['identifier' => $ivo['username'], 'password' => $password]])['body']['token'] ?? '');
db_run("UPDATE users SET status = 'suspended' WHERE username = ?", [$ivo['username']]);
check('401 on the pilot', http('/api/goals/update.php', ['bearer' => $ivoToken, 'json' => ['goal_id' => 1, 'action' => 'pause']])['status'] === 401);
$suspendedLogin = http('/api/auth/app-login.php', ['json' => ['identifier' => $ivo['username'], 'password' => $password]]);
check('and cannot sign in again: 401, no token', $suspendedLogin['status'] === 401 && !isset($suspendedLogin['body']['token']), summary($suspendedLogin));

section('a deleted account takes its tokens with it');
$jip      = web_account('jip');
$jipToken = (string) (http('/api/auth/app-login.php', ['json' => ['identifier' => $jip['username'], 'password' => $password]])['body']['token'] ?? '');
$deleted  = web($jip, '/api/profile/delete.php', ['confirm' => 'verwijderen']);
check('deleted on the website: 200', $deleted['status'] === 200, summary($deleted));
check('the app token: 401', http('/api/goals/update.php', ['bearer' => $jipToken, 'json' => ['goal_id' => 1, 'action' => 'pause']])['status'] === 401);
check('and its row is gone', row_of($jipToken) === null);

section('17. account switching: one token, one account');
$kim      = web_account('kim');
$kimToken = (string) (http('/api/auth/app-login.php', ['json' => ['identifier' => $kim['username'], 'password' => $password]])['body']['token'] ?? '');
http('/api/auth/app-logout.php', ['bearer' => $kimToken]);
$lot      = web_account('lot');
$lotToken = (string) (http('/api/auth/app-login.php', ['bearer' => $kimToken, 'json' => ['identifier' => $lot['username'], 'password' => $password]])['body']['token'] ?? '');
$lotGoal  = goal_for(user_id_of($lot['username']), 'Lot', 'primary');
$kimGoal  = goal_for(user_id_of($kim['username']), 'Kim', 'primary');
check('the new account\'s token acts as that account', http('/api/goals/update.php', ['bearer' => $lotToken, 'json' => ['goal_id' => $lotGoal, 'action' => 'pause']])['status'] === 200);
check('and not as the one before', http('/api/goals/update.php', ['bearer' => $lotToken, 'json' => ['goal_id' => $kimGoal, 'action' => 'pause']])['status'] === 404
    && goal_state($kimGoal) === 'active/primary');
check('the old token stays dead', http('/api/goals/update.php', ['bearer' => $kimToken, 'json' => ['goal_id' => $kimGoal, 'action' => 'pause']])['status'] === 401);

section('the phone limit applies to app sign-ins too, and says so');
$max = web_account('max');
$maxTokens = [];
for ($i = 1; $i <= 5; $i++) {
    $maxTokens[] = (string) (http('/api/auth/app-login.php', ['json' => ['identifier' => $max['username'], 'password' => $password, 'label' => "Toestel $i"]])['body']['token'] ?? '');
}
$sixthPhone = http('/api/auth/app-login.php', ['json' => ['identifier' => $max['username'], 'password' => $password]]);
check('a 6th phone: 409 with the reason', $sixthPhone['status'] === 409 && str_contains($sixthPhone['raw'], 'maximum'), summary($sixthPhone));
$same = http('/api/auth/app-login.php', ['bearer' => $maxTokens[0], 'json' => ['identifier' => $max['username'], 'password' => $password]]);
check('signing in again on a phone that has a token reuses its row: 200', $same['status'] === 200, summary($same));
check('the reused token is dead, the new one works', http('/api/integrations/status.php', ['bearer' => $maxTokens[0], 'json' => []])['status'] === 401
    && http('/api/integrations/status.php', ['bearer' => (string) $same['body']['token'], 'json' => []])['status'] === 200);

/* ======================================================================
   WEB COMPATIBILITY  (24–27)
   ====================================================================== */

section('24, 25, 27. the website\'s sign-in, unchanged');
$nina    = web_account('nina');
http('/api/auth/logout.php', ['form' => ['csrf' => csrf($nina)], 'jar' => $nina['jar']]);
$browser = browser();
$webIn   = web($browser, '/api/auth/login.php', ['username' => $nina['username'], 'password' => $password]);
check('24. login.php: 200 with the same answer shape', $webIn['status'] === 200 && array_keys($webIn['body'] ?? []) === ['ok', 'account'], summary($webIn));
check('    and no token in it', !isset($webIn['body']['token']));
check('25. the session is signed in', (http('/api/auth/session.php', ['jar' => $browser['jar']])['body']['signed_in'] ?? null) === true);
check('27. the stay-signed-in cookie is set', (bool) preg_match('/^Set-Cookie:\s*(__Host-)?jolu_login=/mi', $webIn['headers']), $webIn['headers']);
/* A browser that closed: only the stay-signed-in cookie is left. */
$jar = (string) file_get_contents($browser['jar']);
file_put_contents($browser['jar'], implode("\n", array_filter(explode("\n", $jar), static fn (string $line): bool => !str_contains($line, 'jolu_session'))));
check('27. with only that cookie left, the session comes back', (http('/api/auth/session.php', ['jar' => $browser['jar']])['body']['signed_in'] ?? null) === true);
check('    and it can still save (its CSRF token belongs to the sign-in)', web($browser, '/api/goals/update.php', ['goal_id' => 1, 'action' => 'pause'])['status'] === 404);
$webWrong = web(browser(), '/api/auth/login.php', ['username' => $nina['username'], 'password' => 'wrong']);
check('a wrong password on the website: 401, the same words as ever', $webWrong['status'] === 401 && ($webWrong['body']['error'] ?? '') === 'Gebruikersnaam of wachtwoord klopt niet.', summary($webWrong));
$webOut = http('/api/auth/logout.php', ['form' => ['csrf' => csrf($browser)], 'jar' => $browser['jar']]);
check('the website\'s logout: 200, signed out', $webOut['status'] === 200 && (http('/api/auth/session.php', ['jar' => $browser['jar']])['body']['signed_in'] ?? null) === false);

/* ======================================================================
   GOOGLE  (37–41)
   ====================================================================== */

section('37. Google: somebody new — verify, choose a username, signed in');
$olaSub   = 'google-sub-ola-' . $run;
$olaMail  = 'at_' . $run . '_ola@jolu-test.invalid';
$app      = google_start();
check('a nonce', strlen($app['nonce']) >= 32);
$verified = http('/api/auth/app-google.php', ['jar' => $app['jar'], 'json' => [
    'action' => 'verify', 'id_token' => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $app['nonce']]),
]]);
check('200, choose_username, the verified address', $verified['status'] === 200 && ($verified['body']['status'] ?? null) === 'choose_username'
    && ($verified['body']['email'] ?? null) === $olaMail && !isset($verified['body']['token']), summary($verified));
$olaName = account_name('ola');
$named   = http('/api/auth/app-google.php', ['jar' => $app['jar'], 'json' => ['action' => 'username', 'username' => $olaName, 'platform' => 'android']]);
check('the username step: 200, signed_in, an account token', $named['status'] === 200 && ($named['body']['status'] ?? null) === 'signed_in'
    && ($named['body']['provider'] ?? null) === 'google' && (row_of((string) ($named['body']['token'] ?? ''))['scope'] ?? null) === 'account', summary($named));
check('the account is linked to that Google id, with the verified address',
    (int) db_value("SELECT COUNT(*) FROM user_auth_identities WHERE provider = 'google' AND provider_subject = ? AND email = ? AND user_id = ?",
        [$olaSub, $olaMail, user_id_of($olaName)]) === 1);
$replayName = http('/api/auth/app-google.php', ['jar' => $app['jar'], 'json' => ['action' => 'username', 'username' => 'at_' . $run . '_ola2']]);
check('the conversation is over: the username step again is 410', $replayName['status'] === 410, summary($replayName));

section('37. Google: somebody who has an account — straight in');
$again = google_start();
$back  = http('/api/auth/app-google.php', ['jar' => $again['jar'], 'json' => [
    'action' => 'verify', 'id_token' => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $again['nonce']]),
]]);
check('200, signed_in, a token for that account', $back['status'] === 200 && ($back['body']['status'] ?? null) === 'signed_in'
    && (int) (row_of((string) ($back['body']['token'] ?? ''))['user_id'] ?? 0) === user_id_of($olaName), summary($back));

section('38–40. Google: tokens that must be refused');
$refusals = [
    '38. signed by another key'        => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $n], $impostorKey),
    '38. another issuer'               => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $n, 'iss' => 'https://evil.example']),
    '38. for another app (audience)'   => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $n, 'aud' => '9999-other.apps.googleusercontent.com']),
    '38. asked for by another client (azp)' => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $n, 'azp' => '9999-other.apps.googleusercontent.com']),
    '38. with no authorised party'     => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $n, 'azp' => null]),
    '39. expired'                      => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $n, 'iat' => time() - 7200, 'exp' => time() - 3600]),
    '40. the wrong nonce'              => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => 'not-' . $n]),
    '40. no nonce'                     => fn (string $n) => id_token(['sub' => $olaSub, 'email' => $olaMail]),
    'not a token at all'               => fn (string $n) => 'abc.def.ghi',
];
foreach ($refusals as $label => $make) {
    $try = google_start();
    $r   = http('/api/auth/app-google.php', ['jar' => $try['jar'], 'json' => ['action' => 'verify', 'id_token' => $make($try['nonce'])]]);
    check("$label: 401, no token", $r['status'] === 401 && !isset($r['body']['token']), summary($r));
}
$once  = google_start();
$first = http('/api/auth/app-google.php', ['jar' => $once['jar'], 'json' => ['action' => 'verify', 'id_token' => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $once['nonce']])]]);
$reuse = http('/api/auth/app-google.php', ['jar' => $once['jar'], 'json' => ['action' => 'verify', 'id_token' => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $once['nonce']])]]);
check('40. a nonce works once: the second verify is 410', $first['status'] === 200 && $reuse['status'] === 410, summary($reuse));
$noConversation = http('/api/auth/app-google.php', ['json' => ['action' => 'verify', 'id_token' => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $once['nonce']])]]);
check('40. a token with no conversation behind it: 410', $noConversation['status'] === 410, summary($noConversation));
$stale = google_start();
$staleCheck = http('/api/auth/app-google.php', ['jar' => $stale['jar'], 'json' => ['action' => 'nonce']]);
check('asking again replaces the nonce', ($staleCheck['body']['nonce'] ?? '') !== $stale['nonce']);
$oldNonce = http('/api/auth/app-google.php', ['jar' => $stale['jar'], 'json' => ['action' => 'verify', 'id_token' => id_token(['sub' => $olaSub, 'email' => $olaMail, 'nonce' => $stale['nonce']])]]);
check('the replaced nonce no longer works: 401', $oldNonce['status'] === 401, summary($oldNonce));

section('41. Google: an address a password account has is not taken over');
$conflict = google_start();
$taken    = http('/api/auth/app-google.php', ['jar' => $conflict['jar'], 'json' => [
    'action' => 'verify', 'id_token' => id_token(['sub' => 'google-sub-anna-' . $run, 'email' => $anna['email'], 'nonce' => $conflict['nonce']]),
]]);
check('409 with the website\'s words, no token', $taken['status'] === 409 && !isset($taken['body']['token'])
    && str_contains((string) ($taken['body']['error'] ?? ''), 'koppel Google via Instellingen'), summary($taken));
check('and nothing was linked', (int) db_value("SELECT COUNT(*) FROM user_auth_identities WHERE provider = 'google' AND user_id = ?", [$annaId]) === 0);

section('Google: an address Google has not verified is not enough for a new account');
$unverified = google_start();
$uv = http('/api/auth/app-google.php', ['jar' => $unverified['jar'], 'json' => [
    'action' => 'verify', 'id_token' => id_token(['sub' => 'google-sub-uv-' . $run, 'email' => 'at_' . $run . '_uv@jolu-test.invalid', 'email_verified' => false, 'nonce' => $unverified['nonce']]),
]]);
check('403, no account made', $uv['status'] === 403 && !isset($uv['body']['token']), summary($uv));

section('Google: a session that did not start the app\'s conversation cannot finish one');
$webPending = browser();
http('/', ['get' => true, 'jar' => $webPending['jar']]);
$hijack = http('/api/auth/app-google.php', ['jar' => $webPending['jar'], 'json' => ['action' => 'username', 'username' => 'at_' . $run . '_hijack']]);
check('the username step without the app\'s conversation: 410', $hijack['status'] === 410, summary($hijack));
$unknownAction = http('/api/auth/app-google.php', ['json' => ['action' => 'launch']]);
check('an unknown action: 400', $unknownAction['status'] === 400);

section('Google: with no Android client configured, the app\'s Google sign-in is off');
$bare     = free_port();
$bareEnv  = $appEnv;
$bareEnv['GOOGLE_SIGNIN_ANDROID_CLIENT_IDS'] = '';
$servers[] = serve($bare, $root, $root . '/tools/app-auth-router.php', $bareEnv);
$off = http('/api/auth/app-google.php', ['base' => 'http://127.0.0.1:' . $bare, 'json' => ['action' => 'nonce']]);
check('501', $off['status'] === 501, summary($off));

/* ======================================================================
   BEFORE MIGRATION 013
   ====================================================================== */

$pre = (string) getenv('PRE13_DB');

if ($pre !== '') {
    section('before migration 013 (' . $pre . '): everything that worked still does');
    $prePort   = free_port();
    $preBase   = 'http://127.0.0.1:' . $prePort;
    $servers[] = serve($prePort, $root, $root . '/tools/app-auth-router.php', ['DB_NAME' => $pre] + $appEnv);

    $preUser  = web_account('pre', $preBase);
    $preSync  = pair($preUser, 'Pre phone', $preBase);
    check('pairing: a token', strlen($preSync) === 64);
    check('status, ingest, profile, targets: 200',
        http('/api/integrations/status.php', ['base' => $preBase, 'bearer' => $preSync, 'json' => []])['status'] === 200
        && http('/api/integrations/ingest.php', ['base' => $preBase, 'bearer' => $preSync, 'raw' => $fixture])['status'] === 200
        && http('/api/integrations/profile.php', ['base' => $preBase, 'bearer' => $preSync, 'json' => []])['status'] === 200
        && http('/api/integrations/nutrition-targets.php', ['base' => $preBase, 'bearer' => $preSync, 'json' => []])['status'] === 200);
    $preBrowser = browser();
    $preLogin   = web($preBrowser, '/api/auth/login.php', ['username' => $preUser['username'], 'password' => $password], $preBase);
    check('the website\'s login: 200', $preLogin['status'] === 200, summary($preLogin));
    for ($i = 1; $i <= 6; $i++) {
        $preWrong = web(browser(), '/api/auth/login.php', ['username' => $preUser['username'], 'password' => 'wrong'], $preBase);
    }
    check('no table to count in: wrong passwords stay 401, never an error', $preWrong['status'] === 401, summary($preWrong));
    check('the app\'s sign-in: 503, not available yet',
        http('/api/auth/app-login.php', ['base' => $preBase, 'json' => ['identifier' => $preUser['username'], 'password' => $password]])['status'] === 503
        && http('/api/auth/app-register.php', ['base' => $preBase, 'json' => ['email' => 'x@jolu-test.invalid', 'username' => 'x', 'password' => $password]])['status'] === 503
        && http('/api/auth/app-google.php', ['base' => $preBase, 'json' => ['action' => 'nonce']])['status'] === 503);
    check('the app\'s sign-out: 200, nothing to revoke', (http('/api/auth/app-logout.php', ['base' => $preBase, 'bearer' => $preSync])['body']['revoked'] ?? null) === false);
    check('the pilot with a sync token: 403 (every token is a sync token)',
        http('/api/goals/update.php', ['base' => $preBase, 'bearer' => $preSync, 'json' => ['goal_id' => 1, 'action' => 'pause']])['status'] === 403);
    check('the pilot from the website: works (404 for a goal that is not hers)',
        web($preUser, '/api/goals/update.php', ['goal_id' => 1, 'action' => 'pause'], $preBase)['status'] === 404);

    /* Its account lives in the other database: removed there. */
    $preDb = new PDO('mysql:host=localhost;dbname=' . $pre, (string) getenv('DB_USER'), (string) getenv('DB_PASSWORD'));
    $preDb->prepare('DELETE FROM users WHERE username = ?')->execute([$preUser['username']]);
} else {
    section('before migration 013: skipped (set PRE13_DB to run it)');
}

} finally {

    /* ==================================================================
       CLEAN UP
       ================================================================== */

    foreach (array_unique($made) as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    db_run('DELETE FROM auth_attempts WHERE attempted_at > NOW() - INTERVAL 1 HOUR');

    foreach ($servers as $process) {
        proc_terminate($process);
    }
    @unlink($keysDir . '/jwks.json');
    @rmdir($keysDir);
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);

exit($fail === 0 ? 0 : 1);
