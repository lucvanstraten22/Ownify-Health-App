<?php
/**
 * Account security, Release A (28.0), tested end to end.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/account-security-test.php
 *     NO027_DB=ownify_old DB_NAME=… php tools/account-security-test.php   # also: the code without migration 027
 *
 * DB_NAME must be a DEVELOPMENT database with migration 027 — never a live
 * one. The test makes accounts (names starting `as_`) directly in it,
 * because registering with e-mail is closed in Release A, and removes them
 * again at the end with everything hanging off them.
 *
 * NO027_DB, when set, names a development database WITHOUT migration 027,
 * to check that the code works there and switches off only what needs it.
 *
 * What runs:
 *   - the rules themselves, in this process: the canonical address, the
 *     password rules, the one password check and its fixed count, Google's
 *     verified address;
 *   - the real endpoints on PHP's built-in server (tools/app-auth-router.php)
 *     with a stand-in for Google that signs ID tokens with a key made here:
 *     registration closed, changing the password and exactly what it signs
 *     out, sessions that carry no number (fail-closed), deleting with the
 *     password and with Google on the website and in the app, the Google
 *     limit, linking switched off, Google's verified address on new accounts.
 *
 * Exit code 0 when every check passes. The passwords are for throwaway
 * accounts and are in the repository on purpose.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 027 (never a live one).\n");
    exit(2);
}

date_default_timezone_set('Europe/Amsterdam');

require_once $root . '/includes/db.php';
require_once $root . '/includes/session.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/devices.php';
require_once $root . '/includes/google-signin.php';

if (!db_available() || !account_security_schema()['complete']) {
    fwrite(STDERR, 'The database ' . getenv('DB_NAME') . " is unreachable or has no migration 027.\n");
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

function serve(int $port, string $docroot, ?string $router, array $env)
{
    $command = ['php', '-S', '127.0.0.1:' . $port, '-t', $docroot];
    if ($router !== null) {
        $command[] = $router;
    }

    /* Never the test-only registration switch: this test needs it closed. */
    $inherited = getenv();
    unset($inherited['OWNIFY_TEST_EMAIL_REGISTRATION']);

    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes, $docroot, $env + $inherited);

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

/** @return array{status: int, body: ?array, raw: string, headers: string} */
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

function browser(): array
{
    return ['jar' => tempnam(sys_get_temp_dir(), 'asjar')];
}

function csrf(array $browser, ?string $baseUrl = null): string
{
    $page = http('/', ['get' => true, 'jar' => $browser['jar']] + ($baseUrl === null ? [] : ['base' => $baseUrl]));
    preg_match('/data-csrf="([^"]+)"/', $page['raw'], $m);

    return $m[1] ?? '';
}

function web(array $browser, string $path, array $fields = [], ?string $baseUrl = null): array
{
    return http($path, [
        'form' => $fields + ['csrf' => csrf($browser, $baseUrl)],
        'jar'  => $browser['jar'],
    ] + ($baseUrl === null ? [] : ['base' => $baseUrl]));
}

/** Who a browser is signed in as, by the app's own read of the page. */
function web_username(array $browser, ?string $baseUrl = null): ?string
{
    $state = web($browser, '/api/app/state.php', [], $baseUrl);

    return $state['body']['data']['auth']['user']['username'] ?? null;
}

/** A cookie's value from a browser's jar. */
function cookie(array $browser, string $name): ?string
{
    foreach (file($browser['jar']) ?: [] as $line) {
        $line   = preg_replace('/^#HttpOnly_/', '', trim($line));
        $fields = explode("\t", (string) $line);
        if (count($fields) >= 7 && $fields[5] === $name) {
            return $fields[6];
        }
    }

    return null;
}

/** Drops one cookie from a browser's jar, as a browser closing would the session cookie. */
function drop_cookie(array $browser, string $name): void
{
    $kept = array_filter(file($browser['jar']) ?: [], static function (string $line) use ($name): bool {
        $fields = explode("\t", preg_replace('/^#HttpOnly_/', '', trim($line)) ?? '');
        return !(count($fields) >= 7 && $fields[5] === $name);
    });
    file_put_contents($browser['jar'], implode('', $kept));
}

/** The server-side session file behind a browser's session cookie. */
function session_file(array $browser): ?string
{
    $id   = cookie($browser, 'ownify_session');
    $path = (string) ini_get('session.save_path');
    $path = $path === '' ? sys_get_temp_dir() : preg_replace('/^\d+;/', '', $path);

    return $id === null ? null : $path . '/sess_' . $id;
}

/** Makes a session look like one from before the session number: the number taken out. */
function strip_generation(array $browser): bool
{
    $file = session_file($browser);
    if ($file === null || !is_file($file)) {
        return false;
    }
    $data = (string) file_get_contents($file);
    $new  = (string) preg_replace('/session_generation\|i:\d+;/', '', $data);
    file_put_contents($file, $new);

    return $new !== $data;
}

function session_has_generation(array $browser): ?bool
{
    $file = session_file($browser);

    return $file === null || !is_file($file) ? null : str_contains((string) file_get_contents($file), 'session_generation|');
}

/* --------------------------------------------------------- the accounts */

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'account-security-test-password';
$made     = [];

/**
 * An account made directly in the database (registration is closed): a
 * password account, or — $password null — an account whose e-mail identity
 * has no password (a Google account that added an address).
 *
 * @return array{username: string, id: int, email: string}
 */
function make_account(string $who, ?string $secret, bool $withEmail = true): array
{
    global $run, $made;

    $username = 'as_' . $run . '_' . $who;
    $email    = $username . '@ownify-test.invalid';
    $made[]   = $username;

    db_run('INSERT INTO users (username) VALUES (?)', [$username]);
    $id = (int) db_value('SELECT id FROM users WHERE username = ?', [$username]);
    db_run('INSERT INTO user_profiles (user_id) VALUES (?)', [$id]);

    if ($withEmail) {
        db_run(
            'INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, password_hash) VALUES (?, ?, ?, ?, ?)',
            [$id, 'email', $email, $email, $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT)]
        );
    }

    return ['username' => $username, 'id' => $id, 'email' => $email];
}

function web_login(array $browser, string $identifier, string $secret, ?string $baseUrl = null): array
{
    return web($browser, '/api/auth/login.php', ['username' => $identifier, 'password' => $secret], $baseUrl);
}

function app_login(string $identifier, string $secret): string
{
    $r = http('/api/auth/app-login.php', ['json' => ['identifier' => $identifier, 'password' => $secret, 'platform' => 'android', 'label' => 'as phone']]);

    return (string) ($r['body']['token'] ?? '');
}

function app_ok(string $token): bool
{
    return http('/api/app/state.php', ['bearer' => $token])['status'] === 200;
}

function generation(int $userId): int
{
    return (int) db_value('SELECT session_generation FROM users WHERE id = ?', [$userId]);
}

function sync_token(string $accountToken): string
{
    $code = http('/api/integrations/pairing-code.php', ['bearer' => $accountToken, 'form' => ['provider' => 'google_health_connect']]);
    $pair = http('/api/integrations/pair.php', ['json' => [
        'code' => $code['body']['code'] ?? '', 'label' => 'Sync phone', 'platform' => 'android', 'app_version' => 'test',
    ]]);

    return (string) ($pair['body']['token'] ?? '');
}

function sync_ok(string $token): bool
{
    return http('/api/integrations/status.php', ['bearer' => $token, 'json' => []])['status'] === 200;
}

function clear_limits(): void
{
    db_run('DELETE FROM auth_attempts WHERE attempted_at > NOW() - INTERVAL 1 DAY');
}

/* ---------------------------------------------------- Google, stood in */

$webClient     = '1111-web.apps.googleusercontent.com';
$androidClient = '2222-android.apps.googleusercontent.com';
$googleKey     = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$googleDetails = openssl_pkey_get_details($googleKey);

$keysDir = sys_get_temp_dir() . '/ownify-account-security-' . $run;
mkdir($keysDir);
file_put_contents($keysDir . '/jwks.json', json_encode(['keys' => [[
    'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
    'n'   => google_signin_b64url_encode($googleDetails['rsa']['n']),
    'e'   => google_signin_b64url_encode($googleDetails['rsa']['e']),
]]]));
openssl_pkey_export($googleKey, $googlePem);
file_put_contents($keysDir . '/google-key.pem', $googlePem);
file_put_contents($keysDir . '/stand-in.json', json_encode(['client' => $webClient]));

/* Google's token endpoint for the website's flows. The code carries who
   signs in, the nonce, and — for a confirmation — when the password was
   typed at Google (auth_time). */
file_put_contents($keysDir . '/token.php', <<<'PHP'
<?php
$b64    = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$code   = json_decode((string) base64_decode(strtr((string) ($_POST['code'] ?? ''), '-_', '+/')), true);
$client = json_decode((string) file_get_contents(__DIR__ . '/stand-in.json'), true)['client'] ?? '';
if (!is_array($code) || ($_POST['client_id'] ?? null) !== $client) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant']);
    return;
}
$claims = [
    'iss' => 'https://accounts.google.com', 'aud' => $client, 'azp' => $client,
    'sub' => $code['sub'], 'email' => $code['email'], 'email_verified' => $code['verified'] ?? true, 'nonce' => $code['nonce'],
    'iat' => time(), 'exp' => time() + 3600,
];
if (isset($code['auth_time'])) { $claims['auth_time'] = $code['auth_time']; }
$input = $b64(json_encode(['alg' => 'RS256', 'kid' => 'test-key', 'typ' => 'JWT'])) . '.' . $b64(json_encode($claims));
openssl_sign($input, $signature, openssl_pkey_get_private((string) file_get_contents(__DIR__ . '/google-key.pem')), OPENSSL_ALGO_SHA256);
header('Content-Type: application/json');
echo json_encode(['id_token' => $input . '.' . $b64($signature), 'access_token' => 'stand-in-access-token', 'token_type' => 'Bearer']);
PHP);

/** An ID token as Credential Manager hands the app. */
function id_token(array $claims): string
{
    global $googleKey, $webClient, $androidClient;

    $claims += [
        'iss' => 'https://accounts.google.com', 'aud' => $webClient, 'azp' => $androidClient,
        'iat' => time(), 'exp' => time() + 3600, 'email_verified' => true,
    ];

    $input = google_signin_b64url_encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key', 'typ' => 'JWT']))
        . '.' . google_signin_b64url_encode(json_encode($claims));
    openssl_sign($input, $signature, $googleKey, OPENSSL_ALGO_SHA256);

    return $input . '.' . google_signin_b64url_encode($signature);
}

/** Back from Google to the website's callback, with what Google would put in the token. */
function google_back(array $browser, string $redirect, array $who): array
{
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
    $code = google_signin_b64url_encode(json_encode($who + ['nonce' => (string) ($query['nonce'] ?? '')]));

    return http('/api/auth/google-callback.php?' . http_build_query(['code' => $code, 'state' => (string) ($query['state'] ?? '')]),
        ['get' => true, 'jar' => $browser['jar']]) + ['query' => $query];
}

/** A Google account made on the website: the button, Google, the username step. Signed in. */
function web_google_account(string $who, string $sub): array
{
    global $run, $made;

    $browser  = browser();
    $username = 'as_' . $run . '_' . $who;
    $email    = $username . '@gmail.invalid';
    $made[]   = $username;

    $start = web($browser, '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'login']);
    google_back($browser, (string) ($start['body']['redirect'] ?? ''), ['sub' => $sub, 'email' => $email]);
    web($browser, '/api/auth/google-username.php', ['username' => $username]);

    return $browser + ['username' => $username, 'email' => $email, 'sub' => $sub, 'id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username])];
}

/** A Google account made in the app. @return array{username, token, sub, email, id} */
function app_google_account(string $who, string $sub): array
{
    global $run, $made;

    $username = 'as_' . $run . '_' . $who;
    $email    = $username . '@gmail.invalid';
    $made[]   = $username;

    $jar   = browser();
    $nonce = http('/api/auth/app-google.php', ['json' => ['action' => 'nonce'], 'jar' => $jar['jar']]);
    http('/api/auth/app-google.php', ['jar' => $jar['jar'], 'json' => [
        'action' => 'verify', 'id_token' => id_token(['sub' => $sub, 'email' => $email, 'nonce' => (string) ($nonce['body']['nonce'] ?? '')]),
    ]]);
    $named = http('/api/auth/app-google.php', ['jar' => $jar['jar'], 'json' => ['action' => 'username', 'username' => $username, 'platform' => 'android']]);

    return ['username' => $username, 'token' => (string) ($named['body']['token'] ?? ''), 'sub' => $sub, 'email' => $email,
            'id' => (int) db_value('SELECT id FROM users WHERE username = ?', [$username])];
}

/* ------------------------------------------------------------- servers */

$googlePort = free_port();
$appPort    = free_port();
$base       = 'http://127.0.0.1:' . $appPort;

$appEnv = [
    'OWNIFY_TEST_GOOGLE_JWKS_URL'      => 'http://127.0.0.1:' . $googlePort . '/jwks.json',
    'OWNIFY_TEST_GOOGLE_TOKEN_URL'     => 'http://127.0.0.1:' . $googlePort . '/token.php',
    'GOOGLE_SIGNIN_CLIENT_ID'          => $webClient,
    'GOOGLE_SIGNIN_CLIENT_SECRET'      => 'not-a-secret-test-value',
    'GOOGLE_SIGNIN_REDIRECT_URI'       => $base . '/api/auth/google-callback.php',
    'GOOGLE_SIGNIN_ANDROID_CLIENT_IDS' => $androidClient,
    'PHP_CLI_SERVER_WORKERS'           => '4',
];

$servers   = [serve($googlePort, $keysDir, null, []), serve($appPort, $root, $root . '/tools/app-auth-router.php', $appEnv)];
$no027Base = null;

echo "\nAccount security, Release A\n";
echo '  database ' . getenv('DB_NAME') . ", run $run, app on $base\n";
echo str_repeat('-', 72) . "\n";

clear_limits();

try {

/* ======================================================================
   THE RULES THEMSELVES
   ====================================================================== */

section('canonical A: what people type is normalised');
foreach (['Sanne@Example.com', 'SANNE@EXAMPLE.COM', '  sanne@example.com', "sanne@example.com \n", "\tsanne@example.com",
          "\u{00A0}sanne@example.com\u{2003}", "sanne@example.com\r\n", "sanne@example.com\n"] as $typed) {
    check('normalised: ' . json_encode($typed, JSON_UNESCAPED_UNICODE), account_email_canonical($typed) === 'sanne@example.com');
}

section('canonical B: what is refused');
foreach (['san ne@example.com', "sanne@exa\tmple.com", "sanne@exa\0mple.com", 'sänne@example.com', 'sanne@exämple.com',
          'not-an-address', '', '   ', str_repeat('a', 180) . '@example.com'] as $typed) {
    check('refused: ' . json_encode($typed, JSON_UNESCAPED_UNICODE), account_email_canonical($typed) === null);
}
check('Gmail dots and +tags stay distinct', account_email_canonical('San.Ne+tag@gmail.com') === 'san.ne+tag@gmail.com'
    && account_email_canonical('sanne@gmail.com') !== account_email_canonical('s.anne@gmail.com'));
check('the refusal on the website is the normal one', auth_validate_email('san ne@example.com') === 'Vul een geldig e-mailadres in.');
check('every canonical output is storable byte for byte', account_email_storable((string) account_email_canonical(' A.B+c@Example.COM ')));
check('the stored-value rule refuses a capital and a trailing newline', !account_email_storable('a@B.com') && !account_email_storable("a@b.com\n"));

section('the password rules');
$rules = [
    ['seven77', 'seven77', 'refused (7 characters)'],
    [str_repeat('x', 201), str_repeat('x', 201), 'refused (201 characters)'],
    ['As_Someone', 'As_Someone', 'refused (the username, another case)'],
    ['SOMEONE@EXAMPLE.COM', 'SOMEONE@EXAMPLE.COM', 'refused (the address, another case)'],
    ['a-good-password', 'a-good-passwort', 'refused (the repeat differs)'],
];
foreach ($rules as [$pw, $repeat, $label]) {
    check($label, account_password_problem($pw, $repeat, 'as_someone', ['someone@example.com']) !== null);
}
check('8 and 200 characters are fine', account_password_problem('eight888', 'eight888', 'x', []) === null
    && account_password_problem(str_repeat('y', 200), str_repeat('y', 200), 'x', []) === null);

section('one password check, and always the same number of them');
$plain    = make_account('plain', $password);
$noPass   = make_account('nopass', null);
$counted  = static function (string $identifier, string $secret): int {
    $before = auth_password_checks();
    auth_login_password($identifier, $secret);
    return auth_password_checks() - $before;
};
check('an unknown name: 4 checks', $counted('as_' . $run . '_nobody', 'whatever-password') === AUTH_SIGNIN_PASSWORD_CHECKS);
check('a known name, wrong password: 4 checks', $counted($plain['username'], 'wrong-password') === AUTH_SIGNIN_PASSWORD_CHECKS);
check('a known name, right password: 4 checks', $counted($plain['username'], $password) === AUTH_SIGNIN_PASSWORD_CHECKS);
check('an address without a password: 4 checks', $counted($noPass['email'], 'whatever-password') === AUTH_SIGNIN_PASSWORD_CHECKS);
check('the address, typed differently, signs in', auth_login_password('  ' . strtoupper($plain['email']) . "\n", $password)['ok'] === true);
check('an address without a password never signs in', auth_login_password($noPass['email'], '')['ok'] === false
    && auth_login_password($noPass['username'], 'anything-at-all')['ok'] === false);
check('auth_password_matches: no hash is no match', auth_password_matches(null, '') === false && auth_password_matches('', 'x') === false);

$time = static function (callable $f): float { $t = microtime(true); for ($i = 0; $i < 5; $i++) { $f(); } return (microtime(true) - $t) / 5; };
$real  = $time(static fn () => auth_password_matches((string) db_value("SELECT password_hash FROM user_auth_identities WHERE provider_subject = ?", [$plain['email']]), 'wrong'));
$dummy = $time(static fn () => auth_password_matches(null, 'wrong'));
check(sprintf('the dummy takes as long as a real hash (%.0f ms vs %.0f ms)', $dummy * 1000, $real * 1000), $dummy > $real * 0.6 && $dummy < $real * 1.6);

section('Google\'s verified address: exactly true');
check('true: the canonical address', google_signin_verified_email(['email_verified' => true, 'email' => ' Ann@Example.COM']) === 'ann@example.com');
check('false: none', google_signin_verified_email(['email_verified' => false, 'email' => 'ann@example.com']) === null);
check('missing: none', google_signin_verified_email(['email' => 'ann@example.com']) === null);
check('the string "true": none', google_signin_verified_email(['email_verified' => 'true', 'email' => 'ann@example.com']) === null);

/* ======================================================================
   REGISTRATION IS CLOSED
   ====================================================================== */

section('registering with e-mail is closed, on the website and in the app');
$someone = browser();
$webReg  = web($someone, '/api/auth/register.php', ['username' => 'as_' . $run . '_reg', 'email' => 'as_' . $run . '_reg@ownify-test.invalid',
    'password' => $password, 'password_confirm' => $password]);
check('the website: 403, "Registreren met e-mail is tijdelijk niet beschikbaar."', $webReg['status'] === 403
    && ($webReg['body']['error'] ?? null) === 'Registreren met e-mail is tijdelijk niet beschikbaar.', summary($webReg));
$appReg = http('/api/auth/app-register.php', ['json' => ['username' => 'as_' . $run . '_reg', 'email' => 'as_' . $run . '_reg@ownify-test.invalid', 'password' => $password]]);
check('the app: 403, the same words', $appReg['status'] === 403 && ($appReg['body']['error'] ?? null) === 'Registreren met e-mail is tijdelijk niet beschikbaar.', summary($appReg));
check('no account was made', db_value('SELECT id FROM users WHERE username = ?', ['as_' . $run . '_reg']) === null);
$status = http('/api/auth/app-google.php', ['json' => ['action' => 'status']]);
check('the app is told before it asks: email_registration false', ($status['body']['email_registration'] ?? null) === false, summary($status));
$page = http('/', ['get' => true, 'jar' => $someone['jar']]);
check('the website\'s panel says so and keeps Google', str_contains($page['raw'], 'Registreren met e-mail is tijdelijk niet beschikbaar.')
    && str_contains($page['raw'], 'data-account-provider="google"'));

/* ======================================================================
   CHANGING THE PASSWORD — WHAT ENDS AND WHAT STAYS
   ====================================================================== */

section('changing the password: what is refused');
$cara    = make_account('cara', $password);
$caraA   = browser();
check('signs in with the address in another case', web_login($caraA, strtoupper($cara['email']), $password)['status'] === 200);
$refused = static function (array $fields, int $status, string $label) use ($caraA, $cara, $password): void {
    $r = web($caraA, '/api/account/password.php', $fields);
    check($label . ': ' . $status, $r['status'] === $status, summary($r));
};
$refused(['current' => 'wrong-current', 'password' => 'new-password-1', 'password_confirm' => 'new-password-1'], 403, 'a wrong current password');
check('a wrong current password counts like a wrong sign-in', (int) db_value(
    "SELECT COUNT(*) FROM auth_attempts WHERE action = 'login' AND key_hash = ?", [hash('sha256', "login\n" . strtolower($cara['username']) . "\n127.0.0.1")]) === 1);
$refused(['current' => $password, 'password' => 'short77', 'password_confirm' => 'short77'], 422, '7 characters');
$refused(['current' => $password, 'password' => str_repeat('z', 201), 'password_confirm' => str_repeat('z', 201)], 422, '201 characters');
$refused(['current' => $password, 'password' => strtoupper($cara['username']), 'password_confirm' => strtoupper($cara['username'])], 422, 'the username');
$refused(['current' => $password, 'password' => strtoupper($cara['email']), 'password_confirm' => strtoupper($cara['email'])], 422, 'the address');
$refused(['current' => $password, 'password' => 'new-password-1', 'password_confirm' => 'new-password-2'], 422, 'a repeat that differs');
check('none of that changed anything: the old password, number 0', auth_login_password($cara['username'], $password)['ok'] && generation($cara['id']) === 0);

section('changing the password on the website: every other sign-in ends, this one stays');
$dirk   = make_account('dirk', $password);
$dirkA  = browser();                                   // makes the change
$dirkB  = browser();                                   // another browser
$dirkC  = browser();                                   // signed in the same second as …
$dirkD  = browser();                                   // … this one
web_login($dirkA, $dirk['username'], $password);
web_login($dirkB, $dirk['username'], $password);
$app1   = app_login($dirk['username'], $password);
$app2   = app_login($dirk['email'], $password);
$sync   = sync_token($app1);
web_login($dirkC, $dirk['username'], $password);
web_login($dirkD, $dirk['username'], $password);
check('everybody is signed in first', web_username($dirkB) === $dirk['username'] && web_username($dirkC) === $dirk['username']
    && app_ok($app1) && app_ok($app2) && sync_ok($sync));
$changed = web($dirkA, '/api/account/password.php', ['current' => $password, 'password' => 'dirk-new-password', 'password_confirm' => 'dirk-new-password']);
check('200', $changed['status'] === 200, summary($changed));
$page = http('/', ['get' => true, 'jar' => $dirkA['jar']]);
check('Settings says so, once', str_contains($page['raw'], 'Je wachtwoord is gewijzigd. Je bent op je andere apparaten uitgelogd.')
    && !str_contains(http('/', ['get' => true, 'jar' => $dirkA['jar']])['raw'], 'Je wachtwoord is gewijzigd.'));
check('the account\'s number moved on: 1', generation($dirk['id']) === 1);
check('this browser stays signed in, on the new number', web_username($dirkA) === $dirk['username']);
check('the other browser is signed out on its next request', web_username($dirkB) === null);
check('two browsers signed in moments before: both signed out', web_username($dirkC) === null && web_username($dirkD) === null);
check('every app sign-in is revoked: 401', !app_ok($app1) && !app_ok($app2));
check('the health-sync token keeps syncing', sync_ok($sync));
check('only this browser\'s stay-signed-in cookie is left', (int) db_value('SELECT COUNT(*) FROM user_login_tokens WHERE user_id = ?', [$dirk['id']]) === 1);
check('the old password no longer signs in, the new one does', !auth_login_password($dirk['username'], $password)['ok']
    && auth_login_password($dirk['username'], 'dirk-new-password')['ok']);

section('a stay-signed-in cookie cannot bring a signed-out browser back');
$dirkE = browser();
web_login($dirkE, $dirk['username'], 'dirk-new-password');
web($dirkA, '/api/account/password.php', ['current' => 'dirk-new-password', 'password' => 'dirk-newer-password', 'password_confirm' => 'dirk-newer-password']);
drop_cookie($dirkE, 'ownify_session');               // the browser closed: only the cookie that lasts is left
check('the cookie was there', cookie($dirkE, 'ownify_login') !== null);
check('opening it again: signed out, its row is gone', web_username($dirkE) === null);
check('the browser that changed it is still in', web_username($dirkA) === $dirk['username']);

section('changing the password in the app: that app stays, everything else ends');
$eva    = make_account('eva', $password);
$evaWeb = browser();
web_login($evaWeb, $eva['username'], $password);
$evaApp = app_login($eva['username'], $password);
$evaTwo = app_login($eva['username'], $password);
$evaSync = sync_token($evaApp);
$fromApp = http('/api/account/password.php', ['bearer' => $evaApp, 'json' => ['current' => $password, 'password' => 'eva-new-password', 'password_confirm' => 'eva-new-password']]);
check('200', $fromApp['status'] === 200, summary($fromApp));
check('this app keeps working', app_ok($evaApp));
check('the other app is revoked', !app_ok($evaTwo));
check('the browser is signed out', web_username($evaWeb) === null);
check('no stay-signed-in cookie is left', (int) db_value('SELECT COUNT(*) FROM user_login_tokens WHERE user_id = ?', [$eva['id']]) === 0);
check('sync keeps working', sync_ok($evaSync));

section('fail-closed: a session from before the session number');
$finn   = make_account('finn', $password);
$finnOld = browser();
web_login($finnOld, $finn['username'], $password);
check('made to look like one from before: no number in it', strip_generation($finnOld) && session_has_generation($finnOld) === false);
check('the account is at 0: it carries on, over several requests', web_username($finnOld) === $finn['username']
    && web_username($finnOld) === $finn['username'] && web_username($finnOld) === $finn['username']);
check('and no number was written into it', session_has_generation($finnOld) === false);
$failed = web($finnOld, '/api/account/password.php', ['current' => 'not-it', 'password' => 'finn-new-password', 'password_confirm' => 'finn-new-password']);
check('a failed change advances nobody: still 0, still no number', $failed['status'] === 403 && generation($finn['id']) === 0
    && session_has_generation($finnOld) === false && web_username($finnOld) === $finn['username']);
$finnNew = browser();
web_login($finnNew, $finn['username'], $password);
web($finnNew, '/api/account/password.php', ['current' => $password, 'password' => 'finn-new-password', 'password_confirm' => 'finn-new-password']);
check('after a change elsewhere: the old session is ended on its next request', web_username($finnOld) === null);

$gus     = make_account('gus', $password);
$gusOld  = browser();
$gusMate = browser();
web_login($gusOld, $gus['username'], $password);
web_login($gusMate, $gus['username'], $password);
strip_generation($gusOld);
$self = web($gusOld, '/api/account/password.php', ['current' => $password, 'password' => 'gus-new-password', 'password_confirm' => 'gus-new-password']);
check('an old session making the change itself: 200', $self['status'] === 200, summary($self));
check('it is the one session advanced, and stays', web_username($gusOld) === $gus['username'] && session_has_generation($gusOld) === true);
check('every other session ends, with a number or without', web_username($gusMate) === null);

/* ======================================================================
   AN ADDRESS WITHOUT A PASSWORD
   ====================================================================== */

section('an address without a password (a Google account that added one)');
clear_limits();
$quiet  = browser();
$byMail = web_login($quiet, $noPass['email'], 'any-password-here');
$byName = web_login($quiet, $noPass['username'], 'any-password-here');
check('the address: the generic failure', $byMail['status'] === 401 && ($byMail['body']['error'] ?? null) === 'Gebruikersnaam of wachtwoord klopt niet.', summary($byMail));
check('the username: the generic failure', $byName['status'] === 401 && ($byName['body']['error'] ?? null) === 'Gebruikersnaam of wachtwoord klopt niet.');
$appTry = http('/api/auth/app-login.php', ['json' => ['identifier' => $noPass['email'], 'password' => 'any-password-here']]);
check('the app: the same', $appTry['status'] === 401 && ($appTry['body']['error'] ?? null) === 'Gebruikersnaam of wachtwoord klopt niet.');
check('and each one is counted', (int) db_value("SELECT COUNT(*) FROM auth_attempts WHERE action = 'login' AND attempted_at > NOW() - INTERVAL 1 MINUTE") >= 3);
clear_limits();

/* ======================================================================
   DELETING THE ACCOUNT ASKS FOR PROOF
   ====================================================================== */

section('deleting with Google on the website: a confirmation through Google first');
$hana   = web_google_account('hana', 'as-sub-hana-' . $run);
check('a Google account, signed in on the website', web_username($hana) === $hana['username']);
check('its verified address is its own (account_emails, via_google)', (int) db_value(
    'SELECT COUNT(*) FROM account_emails WHERE email = ? AND user_id = ? AND via_google = 1', [$hana['email'], $hana['id']]) === 1);
$asks = web($hana, '/api/profile/delete.php', ['confirm' => 'verwijderen']);
check('without a confirmation: 403, Google is asked for, nothing deleted', $asks['status'] === 403 && ($asks['body']['reauth'] ?? null) === 'google'
    && db_value('SELECT id FROM users WHERE id = ?', [$hana['id']]) !== null, summary($asks));
$pwTry = web($hana, '/api/profile/delete.php', ['confirm' => 'verwijderen', 'password' => 'a-password-anyway']);
check('a password sent anyway does not count', $pwTry['status'] === 403 && db_value('SELECT id FROM users WHERE id = ?', [$hana['id']]) !== null);
$start = web($hana, '/api/auth/reauth.php', ['action' => 'google', 'purpose' => 'delete']);
parse_str((string) parse_url((string) ($start['body']['redirect'] ?? ''), PHP_URL_QUERY), $asked);
check('off to Google, which asks for the password again (prompt=login, max_age=0)', ($asked['prompt'] ?? null) === 'login' && ($asked['max_age'] ?? null) === '0', summary($start));
$stale = google_back($hana, (string) $start['body']['redirect'], ['sub' => $hana['sub'], 'email' => $hana['email'], 'auth_time' => time() - 900]);
check('a password typed at Google 15 minutes ago: no confirmation', web($hana, '/api/profile/delete.php', ['confirm' => 'verwijderen'])['status'] === 403);
$start = web($hana, '/api/auth/reauth.php', ['action' => 'google', 'purpose' => 'delete']);
google_back($hana, (string) $start['body']['redirect'], ['sub' => 'someone-else-' . $run, 'email' => 'x@gmail.invalid', 'auth_time' => time()]);
check('another Google account: no confirmation', web($hana, '/api/profile/delete.php', ['confirm' => 'verwijderen'])['status'] === 403);
$start = web($hana, '/api/auth/reauth.php', ['action' => 'google', 'purpose' => 'delete']);
$back  = google_back($hana, (string) $start['body']['redirect'], ['sub' => $hana['sub'], 'email' => $hana['email'], 'auth_time' => time()]);
check('its own Google account, just now: back to the app', $back['status'] === 303);
$gone = web($hana, '/api/profile/delete.php', ['confirm' => 'verwijderen']);
check('then deleted: 200, the account is gone', $gone['status'] === 200 && db_value('SELECT id FROM users WHERE id = ?', [$hana['id']]) === null, summary($gone));

section('deleting with Google in the app: the ID token goes with the request');
$ivo  = app_google_account('ivo', 'as-sub-ivo-' . $run);
check('a Google account made in the app, with its verified address', $ivo['token'] !== '' && (int) db_value(
    'SELECT COUNT(*) FROM account_emails WHERE email = ? AND user_id = ?', [$ivo['email'], $ivo['id']]) === 1);
$conv  = browser();
$plainAsk = http('/api/profile/delete.php', ['bearer' => $ivo['token'], 'form' => ['confirm' => 'verwijderen']]);
check('without a token: 403 asking for Google', $plainAsk['status'] === 403 && ($plainAsk['body']['reauth'] ?? null) === 'google', summary($plainAsk));
$nonce = http('/api/auth/reauth.php', ['bearer' => $ivo['token'], 'jar' => $conv['jar'], 'json' => ['action' => 'nonce']]);
check('a nonce for Credential Manager, with the Web client', ($nonce['body']['nonce'] ?? '') !== '' && ($nonce['body']['client_id'] ?? null) === $webClient, summary($nonce));
$other = http('/api/profile/delete.php', ['bearer' => $ivo['token'], 'jar' => $conv['jar'], 'form' => ['confirm' => 'verwijderen',
    'id_token' => id_token(['sub' => 'someone-else-' . $run, 'email' => 'x@gmail.invalid', 'nonce' => (string) $nonce['body']['nonce']])]]);
check('another Google account: 403, nothing deleted', $other['status'] === 403 && db_value('SELECT id FROM users WHERE id = ?', [$ivo['id']]) !== null, summary($other));
$reuse = http('/api/profile/delete.php', ['bearer' => $ivo['token'], 'jar' => $conv['jar'], 'form' => ['confirm' => 'verwijderen',
    'id_token' => id_token(['sub' => $ivo['sub'], 'email' => $ivo['email'], 'nonce' => (string) $nonce['body']['nonce']])]]);
check('the nonce worked once: the right token with it now is 403', $reuse['status'] === 403 && db_value('SELECT id FROM users WHERE id = ?', [$ivo['id']]) !== null);
$nonce = http('/api/auth/reauth.php', ['bearer' => $ivo['token'], 'jar' => $conv['jar'], 'json' => ['action' => 'nonce']]);
$elsewhere = http('/api/profile/delete.php', ['bearer' => $ivo['token'], 'form' => ['confirm' => 'verwijderen',
    'id_token' => id_token(['sub' => $ivo['sub'], 'email' => $ivo['email'], 'nonce' => (string) $nonce['body']['nonce']])]]);
check('outside the conversation that asked for the nonce: 403', $elsewhere['status'] === 403);
$gone = http('/api/profile/delete.php', ['bearer' => $ivo['token'], 'jar' => $conv['jar'], 'form' => ['confirm' => 'verwijderen',
    'id_token' => id_token(['sub' => $ivo['sub'], 'email' => $ivo['email'], 'nonce' => (string) $nonce['body']['nonce']])]]);
check('its own Google account with the nonce: 200, deleted', $gone['status'] === 200 && db_value('SELECT id FROM users WHERE id = ?', [$ivo['id']]) === null, summary($gone));

/* ======================================================================
   GOOGLE: RULES AND LIMITS
   ====================================================================== */

section('Google linking is not offered in Release A');
$jan    = make_account('jan', $password);
$janWeb = browser();
web_login($janWeb, $jan['username'], $password);
$link = web($janWeb, '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'link']);
check('409, "Bevestig eerst je e-mailadres. Dat kan binnenkort."', $link['status'] === 409
    && ($link['body']['error'] ?? null) === 'Bevestig eerst je e-mailadres. Dat kan binnenkort.', summary($link));
$janState = web($janWeb, '/api/app/state.php');
$signin   = $janState['body']['data']['auth']['signin'] ?? [];
check('Settings knows: unverified, a password, may change it, may not link', ($signin['email_verified'] ?? null) === false
    && ($signin['password'] ?? null) === true && ($signin['can_change_password'] ?? null) === true && ($signin['can_link_google'] ?? null) === false,
    json_encode($signin));

section('Google sign-up with an address that is already somebody\'s');
$kees = make_account('kees', $password);
db_run('INSERT INTO account_emails (email, user_id, via_password) VALUES (?, ?, 1)', ['as_' . $run . '_verified@gmail.invalid', $kees['id']]);
$made[] = 'as_' . $run . '_taker';
$jar   = browser();
$nonce = http('/api/auth/app-google.php', ['json' => ['action' => 'nonce'], 'jar' => $jar['jar']]);
$taken = http('/api/auth/app-google.php', ['jar' => $jar['jar'], 'json' => [
    'action' => 'verify', 'id_token' => id_token(['sub' => 'as-sub-taker-' . $run, 'email' => 'AS_' . $run . '_VERIFIED@gmail.invalid', 'nonce' => (string) ($nonce['body']['nonce'] ?? '')]),
]]);
check('another account\'s verified address, in capitals: refused 409, nothing made', $taken['status'] === 409, summary($taken));
$nonce = http('/api/auth/app-google.php', ['json' => ['action' => 'nonce'], 'jar' => $jar['jar']]);
$str   = http('/api/auth/app-google.php', ['jar' => $jar['jar'], 'json' => [
    'action' => 'verify', 'id_token' => id_token(['sub' => 'as-sub-str-' . $run, 'email' => 'as_' . $run . '_str@gmail.invalid', 'email_verified' => 'true', 'nonce' => (string) ($nonce['body']['nonce'] ?? '')]),
]]);
check('email_verified as the string "true": no new account (403)', $str['status'] === 403, summary($str));

section('Google: at most 20 starts per quarter of an hour from one address');
clear_limits();
$codes = [];
for ($i = 0; $i < 21; $i++) {
    $codes[] = http('/api/auth/app-google.php', ['json' => ['action' => 'nonce']])['status'];
}
check('20 answered, the 21st is 429', count(array_filter($codes, static fn ($c) => $c === 200)) === 20 && end($codes) === 429, implode(',', $codes));
check('the website\'s Google button is limited by the same count', web(browser(), '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'login'])['status'] === 429);
clear_limits();

/* ======================================================================
   WITHOUT MIGRATION 027
   ====================================================================== */

$no027 = (string) getenv('NO027_DB');

if ($no027 === '') {
    section('without migration 027: skipped (set NO027_DB to run it)');
} else {
    section('without migration 027: everything works, the change waits');
    $port      = free_port();
    $no027Base = 'http://127.0.0.1:' . $port;
    $servers[] = serve($port, $root, $root . '/tools/app-auth-router.php', ['DB_NAME' => $no027] + $appEnv);

    $old = new PDO('mysql:host=127.0.0.1;dbname=' . $no027 . ';charset=utf8mb4', (string) (getenv('DB_USER') ?: 'root'), (string) (getenv('DB_PASS') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $oldName = 'as_' . $run . '_old';
    $old->prepare('INSERT INTO users (username) VALUES (?)')->execute([$oldName]);
    $oldId = (int) $old->lastInsertId();
    $old->prepare('INSERT INTO user_profiles (user_id) VALUES (?)')->execute([$oldId]);
    $old->prepare('INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, password_hash) VALUES (?, ?, ?, ?, ?)')
        ->execute([$oldId, 'email', $oldName . '@ownify-test.invalid', $oldName . '@ownify-test.invalid', password_hash($password, PASSWORD_DEFAULT)]);

    $oldWeb = browser();
    check('signing in works', web_login($oldWeb, $oldName, $password, $no027Base)['status'] === 200);
    check('the pages work, signed in', web_username($oldWeb, $no027Base) === $oldName);
    $change = web($oldWeb, '/api/account/password.php', ['current' => $password, 'password' => 'old-new-password', 'password_confirm' => 'old-new-password'], $no027Base);
    check('changing the password: 503 "Dit kan even niet. Probeer het later opnieuw.", nothing changed', $change['status'] === 503
        && ($change['body']['error'] ?? null) === 'Dit kan even niet. Probeer het later opnieuw.', summary($change));
    $state = web($oldWeb, '/api/app/state.php', [], $no027Base);
    check('Settings does not offer it', ($state['body']['data']['auth']['signin']['can_change_password'] ?? null) === false);
    check('registering stays closed', web(browser(), '/api/auth/register.php', ['username' => 'x', 'email' => 'x@y.zz', 'password' => $password], $no027Base)['status'] === 403);
    $del = web($oldWeb, '/api/profile/delete.php', ['confirm' => 'verwijderen', 'password' => $password], $no027Base);
    check('deleting with the password works', $del['status'] === 200, summary($del));
    $old->prepare('DELETE FROM users WHERE username = ?')->execute([$oldName]);
}

} finally {
    foreach (array_unique($made) as $username) {
        db_run('DELETE FROM account_emails WHERE user_id = (SELECT id FROM users WHERE username = ?)', [$username]);
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    clear_limits();
    foreach ($servers as $server) {
        proc_terminate($server);
    }
    array_map('unlink', glob($keysDir . '/*') ?: []);
    @rmdir($keysDir);
}

echo "\n" . str_repeat('-', 72) . "\n";
printf("  %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
