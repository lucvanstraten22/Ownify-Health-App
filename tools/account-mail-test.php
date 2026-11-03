<?php
/**
 * Account security, Release B (35.0), tested end to end: every flow that
 * needs mail, through the real endpoints, with real SMTP to a stand-in.
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/account-mail-test.php
 *
 * DB_NAME must be a DEVELOPMENT database with migration 027 — never a live
 * one. Accounts are made under names starting `am_` and are removed again
 * at the end, with everything hanging off them; addresses end in .invalid.
 *
 * What runs, on PHP's built-in server with a stand-in for Google and
 * tools/fake-smtp.py for Gmail (STARTTLS with a certificate PHP really
 * verifies — nothing leaves this machine):
 *
 *   - the switch: with account_mail off every Release B door is shut;
 *   - registration with the verification screen: a free address (the link
 *     in another browser, and in the same one), a taken address (the
 *     placeholder, e-mail 7 at most once a day, otherwise a decoy), the same
 *     answer for both, the cap of 3, sign-in precedence with exactly 4
 *     password checks, "Stuur opnieuw" and its wait, "Ander adres gebruiken";
 *   - the app's registration, verification screen and token;
 *   - links: opening only reads, a tap uses, once; expired and voided links;
 *   - Wachtwoord vergeten: the same answer and one outbox row whatever the
 *     address, the reset itself, what it signs out, its limits;
 *   - "Stuur bevestiging" for an existing address, and the conflict rule;
 *   - changing an address, e-mail 6a, and the revert (D4 included);
 *   - adding an address and setting a password on a Google account;
 *   - linking Google on the website and in the app, and removing it (6b);
 *   - e-mail 5 after a password change; the outbox holds no link in the clear.
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

/* The test process reads and writes the database and decrypts the outbox
   with the same key the servers use; it never sends mail itself. */
$appKey = base64_encode(random_bytes(32));
putenv('OWNIFY_APP_KEY=' . $appKey);
putenv('OWNIFY_PUBLIC_URL=https://ownify.test');

require_once $root . '/includes/db.php';
require_once $root . '/includes/session.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/devices.php';
require_once $root . '/includes/google-signin.php';
require_once $root . '/includes/mailer.php';

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

/** @param list<string> $ini extra -d settings */
function serve(int $port, string $docroot, ?string $router, array $env, array $ini = [])
{
    $command = ['php'];
    foreach ($ini as $setting) {
        $command[] = '-d';
        $command[] = $setting;
    }
    array_push($command, '-S', '127.0.0.1:' . $port, '-t', $docroot);
    if ($router !== null) {
        $command[] = $router;
    }

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
        CURLOPT_TIMEOUT        => 60,
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
    return 'HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 400);
}

function browser(): array
{
    return ['jar' => tempnam(sys_get_temp_dir(), 'amjar')];
}

function csrf(array $browser, ?string $baseUrl = null): string
{
    static $cache = [];

    /* One page load per session: page loads also send what is due in the
       outbox after their output (index.php), which the checks below count
       in the table — so as few as possible. */
    $key = $browser['jar'] . '|' . session_cookie($browser);

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $page = http('/', ['get' => true, 'jar' => $browser['jar']] + ($baseUrl === null ? [] : ['base' => $baseUrl]));
    preg_match('/data-csrf="([^"]+)"/', $page['raw'], $m);

    return $cache[$browser['jar'] . '|' . session_cookie($browser)] = $m[1] ?? '';
}

function session_cookie(array $browser): string
{
    foreach (file($browser['jar']) ?: [] as $line) {
        $fields = explode("\t", (string) preg_replace('/^#HttpOnly_/', '', trim($line)));
        if (count($fields) >= 7 && $fields[5] === 'ownify_session') {
            return $fields[6];
        }
    }

    return '';
}

function web(array $browser, string $path, array $fields = [], ?string $baseUrl = null): array
{
    return http($path, [
        'form' => $fields + ['csrf' => csrf($browser, $baseUrl)],
        'jar'  => $browser['jar'],
    ] + ($baseUrl === null ? [] : ['base' => $baseUrl]));
}

function web_username(array $browser): ?string
{
    $state = web($browser, '/api/app/state.php');

    return $state['body']['data']['auth']['user']['username'] ?? null;
}

function app_json(string $path, array $json, ?array $jar = null, ?string $bearer = null): array
{
    return http($path, ['json' => $json] + ($jar === null ? [] : ['jar' => $jar['jar']]) + ($bearer === null ? [] : ['bearer' => $bearer]));
}

function app_ok(string $token): bool
{
    return http('/api/app/state.php', ['bearer' => $token])['status'] === 200;
}

function clear_limits(): void
{
    db_run('DELETE FROM auth_attempts WHERE attempted_at > NOW() - INTERVAL 2 DAY');
}

function user_id(string $username): ?int
{
    $id = db_value('SELECT id FROM users WHERE username = ?', [$username]);

    return $id === null ? null : (int) $id;
}

function outbox(): array
{
    return db_all('SELECT id, kind, recipient, payload FROM mail_outbox ORDER BY id');
}

/**
 * The kinds of the jobs queued since $after (outbox_top()), whether or not
 * a page load has sent them meanwhile: every job is also noted by a trigger
 * on mail_outbox the test installs (and removes).
 */
function outbox_kinds(int $after): array
{
    return array_map(static fn (array $r): string => (string) $r['kind'], db_all('SELECT kind FROM am_outbox_seen WHERE id > ? ORDER BY id', [$after]));
}

function outbox_top(): int
{
    return (int) (db_value('SELECT MAX(id) FROM am_outbox_seen') ?? 0);
}

/* ------------------------------------------------------------ the mail */

$mailDir = sys_get_temp_dir() . '/ownify-account-mail-' . bin2hex(random_bytes(3));
mkdir($mailDir . '/out', 0700, true);

/**
 * Sends what is due through the real outbox runner (the fake Gmail takes
 * it), waits for the outbox to be empty, and returns every message that
 * arrived since the last call: to, subject, text.
 *
 * @return list<array{to: string, subject: string, text: string, html: string, raw: string}>
 */
function deliver(): array
{
    global $root, $mailDir, $mailEnv, $caFile;
    static $seen = [];

    for ($round = 0; $round < 20; $round++) {
        $command = ['php', '-d', 'openssl.cafile=' . $caFile, $root . '/tools/mail-outbox.php'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $mailEnv + getenv());
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);

        if ((int) db_value("SELECT COUNT(*) FROM mail_outbox WHERE send_after <= NOW()") === 0) {
            break;
        }
        usleep(200_000);
    }

    $messages = [];
    foreach (glob($mailDir . '/out/message-*.eml') ?: [] as $file) {
        if (isset($seen[$file])) {
            continue;
        }
        $seen[$file] = true;
        $messages[]  = parse_mail((string) file_get_contents($file));
    }

    return $messages;
}

/** The parts of one message the checks need. */
function parse_mail(string $raw): array
{
    [$head] = explode("\r\n\r\n", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw)), 2) + [1 => ''];
    preg_match('/^To:\s*(.+)$/mi', $head, $to);
    preg_match('/^Subject:\s*((?:.+)(?:\r\n[ \t].+)*)$/mi', $head, $subject);

    $decoded = '';
    $html    = '';
    /* Every part, decoded by its own transfer encoding. */
    foreach (preg_split('/\r?\n--[^\r\n]+\r?\n/', $raw) ?: [] as $part) {
        $split = preg_split('/\r?\n\r?\n/', $part, 2);
        if (count($split) < 2) {
            continue;
        }
        [$h, $b] = $split;
        if (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $h)) {
            $b = quoted_printable_decode($b);
        } elseif (preg_match('/Content-Transfer-Encoding:\s*base64/i', $h)) {
            $b = (string) base64_decode(preg_replace('/\s+/', '', $b));
        }
        if (preg_match('#Content-Type:\s*text/html#i', $h)) {
            $html .= $b;
        } elseif (preg_match('#Content-Type:\s*text/plain#i', $h)) {
            $decoded .= $b;
        }
    }

    preg_match('/<([^>]+)>/', $to[1] ?? '', $address);

    return [
        'to'      => strtolower(trim($address[1] ?? ($to[1] ?? ''))),
        'subject' => mb_decode_mimeheader(preg_replace('/\r\n[ \t]/', ' ', $subject[1] ?? '')),
        'text'    => $decoded,
        'html'    => $html,
        'raw'     => $raw,
    ];
}

/** The account link in a message, or ''. */
function link_token(array $message): string
{
    preg_match('/\/\?account-link#([A-Za-z0-9_-]{43})/', $message['text'] . $message['html'], $m);

    return $m[1] ?? '';
}

/** @param list<array> $messages */
function mail_to(array $messages, string $address): ?array
{
    foreach ($messages as $message) {
        if ($message['to'] === strtolower($address)) {
            return $message;
        }
    }

    return null;
}

/* --------------------------------------------------------- the accounts */

$run      = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'account-mail-test-password';
$made     = [];

/** @return array{username: string, id: int, email: string} */
function make_account(string $who, ?string $secret, bool $verified, bool $withEmail = true): array
{
    global $run, $made;

    $username = 'am_' . $run . '_' . $who;
    $email    = $username . '@ownify-test.invalid';
    $made[]   = $username;

    db_run('INSERT INTO users (username) VALUES (?)', [$username]);
    $id = (int) db_value('SELECT id FROM users WHERE username = ?', [$username]);
    db_run('INSERT INTO user_profiles (user_id) VALUES (?)', [$id]);

    if ($withEmail) {
        db_run(
            'INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, password_hash, email_verified_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, 'email', $email, $email, $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT), $verified ? date('Y-m-d H:i:s') : null]
        );
        if ($verified) {
            db_run('INSERT INTO account_emails (email, user_id, via_password) VALUES (?, ?, 1)', [$email, $id]);
        }
    }

    return ['username' => $username, 'id' => $id, 'email' => $email];
}

function web_login(array $browser, string $identifier, string $secret): array
{
    return web($browser, '/api/auth/login.php', ['username' => $identifier, 'password' => $secret]);
}

function app_login(string $identifier, string $secret, ?array $jar = null): array
{
    return app_json('/api/auth/app-login.php', ['identifier' => $identifier, 'password' => $secret, 'platform' => 'android', 'label' => 'am phone'], $jar);
}

/* ---------------------------------------------------- Google, stood in */

$webClient     = '1111-web.apps.googleusercontent.com';
$androidClient = '2222-android.apps.googleusercontent.com';
$googleKey     = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$googleDetails = openssl_pkey_get_details($googleKey);

$keysDir = $mailDir . '/google';
mkdir($keysDir);
file_put_contents($keysDir . '/jwks.json', json_encode(['keys' => [[
    'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
    'n'   => google_signin_b64url_encode($googleDetails['rsa']['n']),
    'e'   => google_signin_b64url_encode($googleDetails['rsa']['e']),
]]]));
openssl_pkey_export($googleKey, $googlePem);
file_put_contents($keysDir . '/google-key.pem', $googlePem);
file_put_contents($keysDir . '/stand-in.json', json_encode(['client' => $webClient]));
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
file_put_contents($keysDir . '/revoke.php', "<?php http_response_code(200); echo '{}';\n");

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

function google_back(array $browser, string $redirect, array $who): array
{
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
    $code = google_signin_b64url_encode(json_encode($who + ['nonce' => (string) ($query['nonce'] ?? '')]));

    return http('/api/auth/google-callback.php?' . http_build_query(['code' => $code, 'state' => (string) ($query['state'] ?? '')]),
        ['get' => true, 'jar' => $browser['jar']]) + ['query' => $query];
}

/** A Google account made on the website: signed in. */
function web_google_account(string $who, string $sub): array
{
    global $run, $made;

    $browser  = browser();
    $username = 'am_' . $run . '_' . $who;
    $email    = $username . '@gmail.invalid';
    $made[]   = $username;

    $start = web($browser, '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'login']);
    google_back($browser, (string) ($start['body']['redirect'] ?? ''), ['sub' => $sub, 'email' => $email]);
    web($browser, '/api/auth/google-username.php', ['username' => $username]);

    return $browser + ['username' => $username, 'email' => $email, 'sub' => $sub, 'id' => (int) user_id($username)];
}

/* --------------------------------------------------------- fake Gmail */

$caFile = $mailDir . '/ca.pem';
$q      = static fn (string $path): string => escapeshellarg($path);
file_put_contents($mailDir . '/san.cnf', "subjectAltName=DNS:localhost\n");
exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=Ownify test CA" -keyout ' . $q("$mailDir/ca.key") . ' -out ' . $q($caFile) . ' 2>&1', $o1, $r1);
exec('openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" -keyout ' . $q("$mailDir/srv.key") . ' -out ' . $q("$mailDir/srv.csr") . ' 2>&1', $o2, $r2);
exec('openssl x509 -req -in ' . $q("$mailDir/srv.csr") . ' -CA ' . $q($caFile) . ' -CAkey ' . $q("$mailDir/ca.key") . ' -CAcreateserial -days 1 -extfile ' . $q("$mailDir/san.cnf") . ' -out ' . $q("$mailDir/srv.pem") . ' 2>&1', $o3, $r3);

if ($r1 !== 0 || $r2 !== 0 || $r3 !== 0) {
    fwrite(STDERR, "Could not make a test certificate (openssl).\n");
    exit(2);
}

$smtpPort = free_port();
$smtpPass = 'fake-gmail-app-password';
$smtp     = proc_open(['python3', __DIR__ . '/fake-smtp.py', (string) $smtpPort, "$mailDir/srv.pem", "$mailDir/srv.key",
    'noreply.ownify@gmail.com', $smtpPass, "$mailDir/out"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $smtpPort) === false; $i++) {
    usleep(50_000);
}

/* ------------------------------------------------------------- servers */

$googlePort = free_port();
$appPort    = free_port();
$offPort    = free_port();
$base       = 'http://127.0.0.1:' . $appPort;
$offBase    = 'http://127.0.0.1:' . $offPort;

$mailEnv = [
    'OWNIFY_APP_KEY'        => $appKey,
    'OWNIFY_PUBLIC_URL'     => 'https://ownify.test',
    'OWNIFY_SMTP_HOST'      => 'localhost',
    'OWNIFY_SMTP_PORT'      => (string) $smtpPort,
    'OWNIFY_SMTP_PASSWORD'  => $smtpPass,
    'OWNIFY_ACCOUNT_MAIL'   => '1',
    'OWNIFY_EMAIL_REGISTRATION' => '1',
];

$appEnv = $mailEnv + [
    'OWNIFY_TEST_GOOGLE_JWKS_URL'      => 'http://127.0.0.1:' . $googlePort . '/jwks.json',
    'OWNIFY_TEST_GOOGLE_TOKEN_URL'     => 'http://127.0.0.1:' . $googlePort . '/token.php',
    'OWNIFY_TEST_GOOGLE_REVOKE_URL'    => 'http://127.0.0.1:' . $googlePort . '/revoke.php',
    'GOOGLE_SIGNIN_CLIENT_ID'          => $webClient,
    'GOOGLE_SIGNIN_CLIENT_SECRET'      => 'not-a-secret-test-value',
    'GOOGLE_SIGNIN_REDIRECT_URI'       => $base . '/api/auth/google-callback.php',
    'GOOGLE_SIGNIN_ANDROID_CLIENT_IDS' => $androidClient,
    'PHP_CLI_SERVER_WORKERS'           => '4',
];

$offEnv = ['OWNIFY_ACCOUNT_MAIL' => '0', 'OWNIFY_EMAIL_REGISTRATION' => '0'] + $appEnv;
$offEnv['GOOGLE_SIGNIN_REDIRECT_URI'] = $offBase . '/api/auth/google-callback.php';

$servers = [
    serve($googlePort, $keysDir, null, []),
    serve($appPort, $root, $root . '/tools/app-auth-router.php', $appEnv, ['openssl.cafile=' . $caFile]),
    serve($offPort, $root, $root . '/tools/app-auth-router.php', $offEnv, ['openssl.cafile=' . $caFile]),
];

echo "\nAccount security, Release B\n";
echo '  database ' . getenv('DB_NAME') . ", run $run, app on $base\n";
echo str_repeat('-', 72) . "\n";

clear_limits();
db_run('DELETE FROM mail_outbox');
db_run('CREATE TABLE IF NOT EXISTS am_outbox_seen (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, kind VARCHAR(24) NOT NULL) ENGINE=InnoDB');
db_run('DELETE FROM am_outbox_seen');
db_run('DROP TRIGGER IF EXISTS am_outbox_seen_insert');
db_run('CREATE TRIGGER am_outbox_seen_insert AFTER INSERT ON mail_outbox FOR EACH ROW INSERT INTO am_outbox_seen (id, kind) VALUES (NEW.id, NEW.kind)');

try {

/* ======================================================================
   THE SWITCH
   ====================================================================== */

section('with account_mail off, every Release B door is shut');

$offBrowser = browser();
$r = web($offBrowser, '/api/auth/register.php', ['email' => "am_{$run}_off@ownify-test.invalid", 'username' => "am_{$run}_off", 'password' => $password, 'password_confirm' => $password], $offBase);
check('registration with e-mail is closed (403)', $r['status'] === 403, summary($r));
$r = web($offBrowser, '/api/auth/forgot.php', ['email' => 'nobody@ownify-test.invalid'], $offBase);
check('Wachtwoord vergeten is closed (403)', $r['status'] === 403, summary($r));
$r = web($offBrowser, '/api/auth/link.php', ['action' => 'info', 'token' => str_repeat('A', 43)], $offBase);
check('a link says it is expired or used (410)', $r['status'] === 410, summary($r));

$offUser = make_account('offuser', $password, false);
web_login($offBrowser, $offUser['username'], $password, );
$offBrowser2 = browser();
web($offBrowser2, '/api/auth/login.php', ['username' => $offUser['username'], 'password' => $password], $offBase);
$state = web($offBrowser2, '/api/app/state.php', [], $offBase);
$signin = $state['body']['data']['auth']['signin'] ?? [];
check('Settings offers none of Release B', ($signin['mail'] ?? null) === false && ($signin['can_verify'] ?? null) === false
    && ($signin['can_change_email'] ?? null) === false && ($signin['can_link_google'] ?? null) === false, json_encode($signin));
$r = web($offBrowser2, '/api/account/verify.php', [], $offBase);
check('"Stuur bevestiging" is refused (403)', $r['status'] === 403, summary($r));
$r = web($offBrowser2, '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'link', 'password' => $password], $offBase);
check('linking Google keeps Release A\'s answer', $r['status'] === 409 && str_contains((string) ($r['body']['error'] ?? ''), 'binnenkort'), summary($r));
check('nothing reached the outbox', outbox() === [], json_encode(outbox()));

/* ======================================================================
   REGISTRATION, A FREE ADDRESS
   ====================================================================== */

section('registration: a free address waits for its link');

$freeName  = "am_{$run}_free";
$freeEmail = "AM_{$run}_Free@Ownify-Test.invalid";
$made[]    = $freeName;
$reg       = browser();
$top       = outbox_top();

$r = web($reg, '/api/auth/register.php', ['email' => '  ' . $freeEmail . ' ', 'username' => $freeName, 'password' => $password, 'password_confirm' => $password]);
check('answered with the verification screen', $r['status'] === 200 && isset($r['body']['pending']['email_masked']) && !isset($r['body']['account']), summary($r));
check('the address is masked', ($r['body']['pending']['email_masked'] ?? '') !== strtolower($freeEmail) && str_contains((string) ($r['body']['pending']['email_masked'] ?? ''), '•'), summary($r));
$freeId = user_id($freeName);
check('a pending account holds the username', $freeId !== null && db_value('SELECT status FROM users WHERE id = ?', [$freeId]) === 'pending');
check('the address waits canonical in pending_registrations, not as a sign-in',
    db_value('SELECT email FROM pending_registrations WHERE user_id = ?', [$freeId]) === strtolower($freeEmail)
    && db_value('SELECT COUNT(*) FROM user_auth_identities WHERE user_id = ?', [$freeId]) === 0
    && db_value('SELECT COUNT(*) FROM account_emails WHERE user_id = ?', [$freeId]) === 0);
check('one verify_signup link, stored as a hash', (int) db_value("SELECT COUNT(*) FROM auth_email_tokens WHERE user_id = ? AND purpose = 'verify_signup' AND used_at IS NULL", [$freeId]) === 1
    && strlen((string) db_value('SELECT token_hash FROM auth_email_tokens WHERE user_id = ?', [$freeId])) === 64);
check('exactly one outbox row: e-mail 1', outbox_kinds($top) === ['signup'], json_encode(outbox_kinds($top)));
$payload = (string) db_value('SELECT payload FROM mail_outbox WHERE id > ?', [$top]);
check('the outbox holds no link in the clear', $payload !== '' && !str_contains($payload, 'account-link') && !str_contains(base64_decode($payload), 'account-link'));
check('this browser is not signed in', web_username($reg) === null);

$mails = deliver();
$one   = mail_to($mails, strtolower($freeEmail));
check('e-mail 1 arrived at the address, through SMTP', $one !== null && str_contains($one['subject'], 'Bevestig je e-mailadres'), json_encode(array_column($mails, 'subject')));
$freeToken = $one === null ? '' : link_token($one);
check('… with a link to the configured site', $freeToken !== '' && str_contains($one['text'], 'https://ownify.test/?account-link#'), $one['text'] ?? '');

section('sign-in while waiting: the verification screen, nothing signed in');

$before = auth_password_checks();
$res    = auth_login_password($freeName, $password);
check('by username: pending, not signed in', !$res['ok'] && ($res['pending'] ?? null) === $freeId, json_encode($res));
check('… with exactly 4 password checks', auth_password_checks() - $before === 4, (string) (auth_password_checks() - $before));
$before = auth_password_checks();
$res    = auth_login_password(strtolower($freeEmail), $password);
check('by address: pending as well, 4 checks', !$res['ok'] && ($res['pending'] ?? null) === $freeId && auth_password_checks() - $before === 4);
$before = auth_password_checks();
$res    = auth_login_password('am_nobody_' . $run, $password);
check('an unknown name: the generic failure, 4 checks', !$res['ok'] && !empty($res['credentials']) && auth_password_checks() - $before === 4);
$res = auth_login_password($freeName, 'wrong-password-123');
check('a wrong password for a waiting name: the generic failure', !$res['ok'] && !empty($res['credentials']) && !isset($res['pending']));

$elsewhere = browser();
$r = web_login($elsewhere, $freeName, $password);
check('the website answers with the verification screen', $r['status'] === 200 && isset($r['body']['pending']) && web_username($elsewhere) === null, summary($r));

$r = web($reg, '/api/auth/pending.php', ['action' => 'resend']);
check('"Stuur opnieuw" at once: its own wait, said', $r['status'] === 429 && ($r['body']['resend_after'] ?? 0) > 0 && str_contains((string) ($r['body']['error'] ?? ''), 'seconde'), summary($r));
check('… and the link still works (a refused resend voids nothing)', db_value('SELECT COUNT(*) FROM auth_email_tokens WHERE user_id = ? AND used_at IS NULL', [$freeId]) === 1);
$r = web($reg, '/api/auth/pending.php', ['action' => 'status']);
check('"Ik heb bevestigd" before the link: still pending', $r['status'] === 200 && ($r['body']['state'] ?? '') === 'pending', summary($r));

section('the link: opening reads, the tap uses, once');

$scanner = browser();
$r1 = web($scanner, '/api/auth/link.php', ['action' => 'info', 'token' => $freeToken]);
$r2 = web($scanner, '/api/auth/link.php', ['action' => 'info', 'token' => $freeToken]);
check('opening twice uses nothing (a mail scanner)', $r1['status'] === 200 && $r2['status'] === 200 && ($r2['body']['link']['purpose'] ?? '') === 'verify_signup'
    && ($r2['body']['link']['needs'] ?? '') === 'confirm', summary($r2));
check('… and says nothing secret', !str_contains($r2['raw'], 'password_hash') && !str_contains($r2['raw'], $freeToken));
$r = web($scanner, '/api/auth/link.php', ['action' => 'info', 'token' => strrev($freeToken)]);
check('an unknown link: "Deze link is verlopen of al gebruikt." (410)', $r['status'] === 410 && str_contains((string) ($r['body']['error'] ?? ''), 'verlopen of al gebruikt'), summary($r));

$r = web($scanner, '/api/auth/link.php', ['action' => 'use', 'token' => $freeToken]);
check('the tap in another browser confirms: "Log in om verder te gaan."', $r['status'] === 200 && str_contains((string) ($r['body']['message'] ?? ''), 'Log in om verder te gaan') && empty($r['body']['signed_in']), summary($r));
check('… that browser is not signed in', web_username($scanner) === null);
check('the account is real now: active, verified, account_emails via_password, no pending row',
    db_value('SELECT status FROM users WHERE id = ?', [$freeId]) === 'active'
    && db_value("SELECT email_verified_at FROM user_auth_identities WHERE user_id = ? AND provider = 'email'", [$freeId]) !== null
    && (int) db_value('SELECT via_password FROM account_emails WHERE user_id = ? AND email = ?', [$freeId, strtolower($freeEmail)]) === 1
    && db_value('SELECT COUNT(*) FROM pending_registrations WHERE user_id = ?', [$freeId]) === 0);
$r = web($scanner, '/api/auth/link.php', ['action' => 'use', 'token' => $freeToken]);
check('a second tap: 410', $r['status'] === 410, summary($r));

$r = web($reg, '/api/auth/pending.php', ['action' => 'status']);
check('the browser that registered is signed in by "Ik heb bevestigd"', $r['status'] === 200 && ($r['body']['state'] ?? '') === 'active' && web_username($reg) === $freeName, summary($r));
$r = web_login(browser(), strtolower($freeEmail), $password);
check('signing in with the address and password works normally', $r['status'] === 200 && isset($r['body']['account']), summary($r));
$res = auth_login_password($freeName, $password);
check('… also by username', $res['ok'] === true);

section('registration confirmed in the browser that started it');

clear_limits();
$sameName  = "am_{$run}_same";
$sameEmail = "am_{$run}_same@ownify-test.invalid";
$made[]    = $sameName;
$same      = browser();
web($same, '/api/auth/register.php', ['email' => $sameEmail, 'username' => $sameName, 'password' => $password, 'password_confirm' => $password]);
$sameToken = link_token(mail_to(deliver(), $sameEmail) ?? ['text' => '', 'html' => '']);
$r = web($same, '/api/auth/link.php', ['action' => 'use', 'token' => $sameToken]);
check('the tap signs this browser in', $r['status'] === 200 && !empty($r['body']['signed_in']) && web_username($same) === $sameName, summary($r));

/* ======================================================================
   REGISTRATION, A TAKEN ADDRESS
   ====================================================================== */

section('registration: a taken address looks the same and gives nothing');

clear_limits();
$owner    = make_account('owner', $password, true);
$takeName = "am_{$run}_take";
$made[]   = $takeName;
$taker    = browser();
$top      = outbox_top();
$takerPw  = 'placeholder-password-9';

$free2Name = "am_{$run}_free2";
$made[]    = $free2Name;
$free2     = web(browser(), '/api/auth/register.php', ['email' => "am_{$run}_free2@ownify-test.invalid", 'username' => $free2Name, 'password' => $password, 'password_confirm' => $password]);
$topTaken  = outbox_top();
$taken     = web($taker, '/api/auth/register.php', ['email' => strtoupper($owner['email']), 'username' => $takeName, 'password' => $takerPw, 'password_confirm' => $takerPw]);

$shape = static fn (array $r): array => [$r['status'], array_keys($r['body'] ?? []), array_keys($r['body']['pending'] ?? [])];
check('the same status and the same answer as a free address', $shape($taken) === $shape($free2), json_encode([$shape($taken), $shape($free2)]));
$takeId = user_id($takeName);
check('a placeholder: pending, address_taken = 1, no link, no identity',
    $takeId !== null && (int) db_value('SELECT address_taken FROM pending_registrations WHERE user_id = ?', [$takeId]) === 1
    && db_value('SELECT COUNT(*) FROM auth_email_tokens WHERE user_id = ?', [$takeId]) === 0
    && db_value('SELECT COUNT(*) FROM user_auth_identities WHERE user_id = ?', [$takeId]) === 0);
check('exactly one outbox row: e-mail 7 to the owner', outbox_kinds($topTaken) === ['already'], json_encode(outbox_kinds($topTaken)));
$mails = deliver();
$seven = mail_to($mails, $owner['email']);
check('e-mail 7 arrived at the owner, with no link into the account', $seven !== null && str_contains($seven['subject'], 'al een Ownify-account') && link_token($seven) === '', json_encode(array_column($mails, 'subject')));

$top = outbox_top();
$take2 = "am_{$run}_take2";
$made[] = $take2;
web(browser(), '/api/auth/register.php', ['email' => $owner['email'], 'username' => $take2, 'password' => $takerPw, 'password_confirm' => $takerPw]);
check('a second try the same day: a decoy, not another e-mail 7', outbox_kinds($top) === ['none'], json_encode(outbox_kinds($top)));
deliver();

$r = web_login(browser(), $owner['email'], $takerPw);
check('the address + the registrant\'s password: the verification screen', $r['status'] === 200 && isset($r['body']['pending']), summary($r));
$r = web_login(browser(), $owner['email'], $password);
check('the owner signs in normally (step 1 always first)', $r['status'] === 200 && isset($r['body']['account']), summary($r));
check('the owner\'s account is untouched', (int) db_value("SELECT COUNT(*) FROM user_auth_identities WHERE user_id = ? AND provider = 'email' AND email_verified_at IS NOT NULL", [$owner['id']]) === 1);

db_run("DELETE FROM auth_attempts WHERE action LIKE 'acct_mail%'");
$top = outbox_top();
$r = web($taker, '/api/auth/pending.php', ['action' => 'resend']);
check('"Stuur opnieuw" for a placeholder: the same "Verstuurd", a decoy', $r['status'] === 200 && ($r['body']['resend_after'] ?? 0) === 60 && outbox_kinds($top) === ['none'], summary($r) . json_encode(outbox_kinds($top)));
$r = web($taker, '/api/auth/pending.php', ['action' => 'status']);
check('… and it is never confirmed', ($r['body']['state'] ?? '') === 'pending', summary($r));
$r = web($taker, '/api/auth/pending.php', ['action' => 'cancel']);
check('"Ander adres gebruiken" deletes the placeholder', $r['status'] === 200 && user_id($takeName) === null, summary($r));
deliver();

section('at most 3 waiting registrations per address');

clear_limits();
$capEmail = "am_{$run}_cap@ownify-test.invalid";
for ($i = 1; $i <= 3; $i++) {
    $made[] = "am_{$run}_cap{$i}";
    $r = web(browser(), '/api/auth/register.php', ['email' => $capEmail, 'username' => "am_{$run}_cap{$i}", 'password' => $password . $i, 'password_confirm' => $password . $i]);
}
check('three registrations wait', (int) db_value('SELECT COUNT(*) FROM pending_registrations WHERE email = ?', [$capEmail]) === 3);
clear_limits();
$counts = static fn (): array => [db_value('SELECT COUNT(*) FROM users'), db_value('SELECT COUNT(*) FROM pending_registrations'),
    db_value('SELECT COUNT(*) FROM auth_email_tokens'), outbox_top()];
$before = $counts();
$made[] = "am_{$run}_cap4";
$r = web(browser(), '/api/auth/register.php', ['email' => $capEmail, 'username' => "am_{$run}_cap4", 'password' => $password, 'password_confirm' => $password]);
check('a 4th: "Dit kan even niet. Probeer het later opnieuw." (429), no wait said', $r['status'] === 429 && str_contains((string) ($r['body']['error'] ?? ''), 'Dit kan even niet') && !str_contains($r['headers'], 'Retry-After'), summary($r));
check('… and nothing was made', $counts() === $before, json_encode([$before, $counts()]));
$res = auth_login_password($capEmail, $password . '3');
check('the third registrant still reaches their screen', ($res['pending'] ?? null) === user_id("am_{$run}_cap3"));
deliver();

section('the per-recipient limit (3 an hour), free and taken alike');

clear_limits();
$limitEmail = "am_{$run}_lim@ownify-test.invalid";
$answers    = [];
for ($i = 1; $i <= 4; $i++) {
    $made[] = "am_{$run}_lim{$i}";
    $answers[] = web(browser(), '/api/auth/register.php', ['email' => $limitEmail, 'username' => "am_{$run}_lim{$i}", 'password' => $password, 'password_confirm' => $password]);
    if ($i === 2) {
        /* One of the two waiting ones goes: room under the cap, not under the limit. */
        web(browser(), '/api/auth/login.php', ['username' => "am_{$run}_lim1", 'password' => $password]);
        db_run("DELETE FROM users WHERE username = ? AND status = 'pending'", ["am_{$run}_lim1"]);
    }
}
check('the 4th in an hour is refused neutrally, though under the cap', $answers[3]['status'] === 429 && str_contains((string) ($answers[3]['body']['error'] ?? ''), 'Dit kan even niet'), summary($answers[3]));
deliver();

/* ======================================================================
   THE APP
   ====================================================================== */

section('the app: registration, its verification screen, its token');

clear_limits();
$appName  = "am_{$run}_app";
$appEmail = "am_{$run}_app@ownify-test.invalid";
$made[]   = $appName;
$appJar   = browser();
$r = app_json('/api/auth/app-register.php', ['email' => $appEmail, 'username' => $appName, 'password' => $password, 'password_confirm' => $password, 'platform' => 'android'], $appJar);
check('the app gets the verification screen, no token', $r['status'] === 200 && isset($r['body']['pending']['email_masked']) && !isset($r['body']['token']), summary($r));
$r = app_json('/api/auth/app-pending.php', ['action' => 'status'], $appJar);
check('"Ik heb bevestigd" before the link: pending', ($r['body']['state'] ?? '') === 'pending', summary($r));
$appToken = link_token(mail_to(deliver(), $appEmail) ?? ['text' => '', 'html' => '']);
$r = web(browser(), '/api/auth/link.php', ['action' => 'use', 'token' => $appToken]);
check('the link opened in a browser: "Log in om verder te gaan."', $r['status'] === 200 && str_contains((string) ($r['body']['message'] ?? ''), 'Log in om verder te gaan'), summary($r));
$r = app_json('/api/auth/app-pending.php', ['action' => 'status', 'platform' => 'android', 'label' => 'am phone'], $appJar);
check('back in the app: active, with its account token', $r['status'] === 200 && ($r['body']['state'] ?? '') === 'active' && app_ok((string) ($r['body']['token'] ?? '')), summary($r));
$r = app_json('/api/auth/app-pending.php', ['action' => 'status'], $appJar);
check('… once: the hold is gone afterwards', $r['status'] === 410, summary($r));

$appPend = "am_{$run}_apppend";
$made[]  = $appPend;
$jar2    = browser();
app_json('/api/auth/app-register.php', ['email' => "am_{$run}_apppend@ownify-test.invalid", 'username' => $appPend, 'password' => $password, 'password_confirm' => $password], $jar2);
$jar3 = browser();
$r = app_login($appPend, $password, $jar3);
check('signing in to a waiting registration in the app: the screen again', $r['status'] === 200 && isset($r['body']['pending']) && !isset($r['body']['token']), summary($r));
$r = app_json('/api/auth/app-pending.php', ['action' => 'cancel'], $jar3);
check('"Ander adres gebruiken" in the app', $r['status'] === 200 && user_id($appPend) === null, summary($r));
deliver();

/* ======================================================================
   WACHTWOORD VERGETEN
   ====================================================================== */

section('Wachtwoord vergeten: one answer, one outbox row');

clear_limits();
$resetUser   = make_account('reset', $password, true);
$unverified  = make_account('unver', $password, false);
$googleOnly  = web_google_account('gonly', 'sub-' . $run . '-gonly');

$answers = [];
foreach ([$resetUser['email'], 'nobody-' . $run . '@ownify-test.invalid', $googleOnly['email'], $unverified['email']] as $address) {
    $top = outbox_top();
    $r   = web(browser(), '/api/auth/forgot.php', ['email' => $address]);
    $answers[] = [$r['status'], $r['body']['message'] ?? null, count(outbox_kinds($top)), outbox_kinds($top)[0] ?? null];
}
check('the same status and message for all four', count(array_unique(array_map(static fn ($a) => json_encode([$a[0], $a[1]]), $answers))) === 1, json_encode($answers));
check('… mentioning Google', str_contains((string) $answers[0][1], 'Doorgaan met Google'));
check('exactly one outbox row each', array_column($answers, 2) === [1, 1, 1, 1], json_encode($answers));
check('a reset for the password accounts, a decoy for nobody and for Google-only', array_column($answers, 3) === ['reset', 'none', 'none', 'reset'], json_encode(array_column($answers, 3)));

$mails      = deliver();
$resetToken = link_token(mail_to($mails, $resetUser['email']) ?? ['text' => '', 'html' => '']);
$unverToken = link_token(mail_to($mails, $unverified['email']) ?? ['text' => '', 'html' => '']);
check('e-mail 4 arrived with its link', $resetToken !== '' && $unverToken !== '');
check('nothing arrived for the Google-only address', mail_to($mails, $googleOnly['email']) === null);

$other = browser();
web_login($other, $resetUser['username'], $password);
$appTok = (string) (app_login($resetUser['username'], $password)['body']['token'] ?? '');
check('(before: signed in in a browser and the app)', web_username($other) === $resetUser['username'] && app_ok($appTok));

$page = browser();
$r = web($page, '/api/auth/link.php', ['action' => 'info', 'token' => $resetToken]);
check('the link asks for a new password', ($r['body']['link']['needs'] ?? '') === 'password' && ($r['body']['link']['purpose'] ?? '') === 'password_reset', summary($r));
$r = web($page, '/api/auth/link.php', ['action' => 'use', 'token' => $resetToken, 'password' => 'new-password-1', 'password_confirm' => 'new-password-2']);
check('a typo is said and uses nothing', $r['status'] === 422 && db_value('SELECT used_at FROM auth_email_tokens WHERE token_hash = ?', [hash('sha256', $resetToken)]) === null, summary($r));
$r = web($page, '/api/auth/link.php', ['action' => 'use', 'token' => $resetToken, 'password' => $resetUser['username'], 'password_confirm' => $resetUser['username']]);
check('the rules hold (not the username)', $r['status'] === 422, summary($r));
$top = outbox_top();
$r = web($page, '/api/auth/link.php', ['action' => 'use', 'token' => $resetToken, 'password' => 'brand-new-password', 'password_confirm' => 'brand-new-password']);
check('the reset: set, and everybody signed out', $r['status'] === 200 && str_contains((string) ($r['body']['message'] ?? ''), 'overal uitgelogd'), summary($r));
$who = web_username($other);
$app = app_ok($appTok);
check('… the other browser and the app are signed out', $who === null && !$app, json_encode([$who, $app]));
check('… the old password fails, the new one works', !auth_login_password($resetUser['username'], $password)['ok'] && auth_login_password($resetUser['username'], 'brand-new-password')['ok']);
check('… e-mail 5 is queued', outbox_kinds($top) === ['password'], json_encode(outbox_kinds($top)));
$r = web($page, '/api/auth/link.php', ['action' => 'use', 'token' => $resetToken, 'password' => 'another-password-1', 'password_confirm' => 'another-password-1']);
check('the link works once', $r['status'] === 410, summary($r));

$r = web(browser(), '/api/auth/link.php', ['action' => 'use', 'token' => $unverToken, 'password' => 'unverified-new-pass', 'password_confirm' => 'unverified-new-pass']);
check('a reset of an unconfirmed address confirms it', $r['status'] === 200
    && db_value("SELECT email_verified_at FROM user_auth_identities WHERE user_id = ? AND provider = 'email'", [$unverified['id']]) !== null
    && (int) db_value('SELECT COUNT(*) FROM account_emails WHERE user_id = ?', [$unverified['id']]) === 1, summary($r));
deliver();

clear_limits();
web(browser(), '/api/auth/forgot.php', ['email' => $resetUser['email']]);
web(browser(), '/api/auth/forgot.php', ['email' => $resetUser['email']]);
$latest = link_token(array_values(array_filter(deliver(), static fn ($m) => $m['to'] === $resetUser['email']))[1] ?? ['text' => '', 'html' => '']);
$older  = db_value("SELECT COUNT(*) FROM auth_email_tokens WHERE user_id = ? AND purpose = 'password_reset' AND used_at IS NULL", [$resetUser['id']]);
check('a newer link voids the older one', (int) $older === 1 && $latest !== '');
db_run('UPDATE auth_email_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE token_hash = ?', [hash('sha256', $latest)]);
$r = web(browser(), '/api/auth/link.php', ['action' => 'info', 'token' => $latest]);
check('an expired link: the same 410', $r['status'] === 410, summary($r));

clear_limits();
$codes = [];
for ($i = 0; $i < 4; $i++) {
    $codes[] = web(browser(), '/api/auth/forgot.php', ['email' => 'limit-' . $run . '@ownify-test.invalid'])['status'];
}
check('3 per quarter hour per address from here, then a wait', $codes === [200, 200, 200, 429], json_encode($codes));
deliver();

/* ======================================================================
   STUUR BEVESTIGING
   ====================================================================== */

section('"Stuur bevestiging" for an existing address');

clear_limits();
$verifyUser = make_account('verify', $password, false);
$vb = browser();
web_login($vb, $verifyUser['username'], $password);
$state = web($vb, '/api/app/state.php');
check('Settings offers it', ($state['body']['data']['auth']['signin']['can_verify'] ?? null) === true, json_encode($state['body']['data']['auth']['signin'] ?? null));
$r = web($vb, '/api/account/verify.php');
check('sent, with its wait', $r['status'] === 200 && ($r['body']['resend_after'] ?? 0) === 60, summary($r));
$m = mail_to(deliver(), $verifyUser['email']);
check('e-mail 1, the variant for an existing account', $m !== null && !str_contains($m['text'], 'Welkom bij Ownify'), $m['text'] ?? '');
$r = web(browser(), '/api/auth/link.php', ['action' => 'use', 'token' => link_token($m ?? ['text' => '', 'html' => ''])]);
check('the link confirms it; nobody is signed out', $r['status'] === 200 && web_username($vb) === $verifyUser['username']
    && db_value("SELECT email_verified_at FROM user_auth_identities WHERE user_id = ? AND provider = 'email'", [$verifyUser['id']]) !== null, summary($r));
$r = web($vb, '/api/account/verify.php');
check('again: "al bevestigd" (409)', $r['status'] === 409, summary($r));

$shadow = make_account('shadow', $password, false);
db_run('INSERT INTO account_emails (email, user_id, via_google) VALUES (?, ?, 1)', ['shadow-' . $run . '@ownify-test.invalid', $owner['id']]);
db_run("UPDATE user_auth_identities SET provider_subject = ?, email = ? WHERE user_id = ? AND provider = 'email'", ['shadow-' . $run . '@ownify-test.invalid', 'shadow-' . $run . '@ownify-test.invalid', $shadow['id']]);
$sb = browser();
web_login($sb, $shadow['username'], $password);
$r = web($sb, '/api/account/verify.php');
check('an address another account has confirmed can never be confirmed here (409)', $r['status'] === 409 && str_contains((string) ($r['body']['error'] ?? ''), 'ander Ownify-account'), summary($r));

/* ======================================================================
   CHANGING AN ADDRESS, AND REVERTING IT
   ====================================================================== */

section('changing the address: nothing changes until the new one is confirmed');

clear_limits();
$mover = make_account('mover', $password, true);
$mb    = browser();
web_login($mb, $mover['username'], $password);
$newAddress = "am_{$run}_moved@ownify-test.invalid";

$r = web($mb, '/api/account/email.php', ['email' => $newAddress, 'password' => 'wrong-password-1']);
check('the wrong password: refused', $r['status'] === 403 && ($r['body']['reauth'] ?? '') === 'password', summary($r));
$r = web($mb, '/api/account/email.php', ['email' => $owner['email'], 'password' => $password]);
check('another account\'s address: refused (409)', $r['status'] === 409, summary($r));
$top = outbox_top();
$r = web($mb, '/api/account/email.php', ['email' => $newAddress, 'password' => $password]);
check('asked: a link to the new address (e-mail 3)', $r['status'] === 200 && outbox_kinds($top) === ['change'], summary($r) . json_encode(outbox_kinds($top)));
check('the old address still signs in', auth_login_password($mover['email'], $password)['ok']);
$state = web($mb, '/api/app/state.php');
check('Settings shows the address waiting for its link', ($state['body']['data']['auth']['signin']['pending_email'] ?? null) === $newAddress);
$changeToken = link_token(mail_to(deliver(), $newAddress) ?? ['text' => '', 'html' => '']);
$top = outbox_top();
$r = web(browser(), '/api/auth/link.php', ['action' => 'use', 'token' => $changeToken]);
check('confirmed: the sign-in moves to the new address', $r['status'] === 200
    && db_value("SELECT provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = 'email'", [$mover['id']]) === $newAddress
    && db_value('SELECT COUNT(*) FROM account_emails WHERE email = ?', [$mover['email']]) === 0
    && (int) db_value('SELECT via_password FROM account_emails WHERE email = ?', [$newAddress]) === 1, summary($r));
check('e-mail 6a to the old address, with the revert link', outbox_kinds($top) === ['email_changed'], json_encode(outbox_kinds($top)));
check('the new address and password sign in; the old address no longer', auth_login_password($newAddress, $password)['ok'] && !auth_login_password($mover['email'], $password)['ok']);

section('… and the revert (72 hours) — D4 included');

/* A Google account linked after the change: the revert removes it. */
$moverApp = (string) (app_login($mover['username'], $password)['body']['token'] ?? '');
$linkJar  = browser();
$n = app_json('/api/account/google.php', ['action' => 'nonce', 'password' => $password], $linkJar, $moverApp);
$l = app_json('/api/account/google.php', ['action' => 'link', 'id_token' => id_token(['sub' => 'sub-' . $run . '-intruder', 'email' => "am_{$run}_intruder@gmail.invalid", 'nonce' => (string) ($n['body']['nonce'] ?? '')])], $linkJar, $moverApp);
check('(an intruder links their Google after the change)', $l['status'] === 200 && account_google_sub($mover['id']) === 'sub-' . $run . '-intruder', summary($n) . summary($l));

$sixA       = mail_to(deliver(), $mover['email']);
$revertTok  = link_token($sixA ?? ['text' => '', 'html' => '']);
check('e-mail 6a arrived at the old address, the new one masked', $sixA !== null && $revertTok !== '' && !str_contains($sixA['text'], $newAddress) && str_contains($sixA['text'], '•'), $sixA['text'] ?? '');
$rb = browser();
$r  = web($rb, '/api/auth/link.php', ['action' => 'info', 'token' => $revertTok]);
check('the revert page shows the old address', ($r['body']['link']['purpose'] ?? '') === 'revert_email' && ($r['body']['link']['old_email'] ?? '') === $mover['email'], summary($r));
$r = web($rb, '/api/auth/link.php', ['action' => 'use', 'token' => $revertTok, 'password' => $newAddress, 'password_confirm' => $newAddress]);
check('the new password may not be an address involved', $r['status'] === 422, summary($r));
$top = outbox_top();
$r = web($rb, '/api/auth/link.php', ['action' => 'use', 'token' => $revertTok, 'password' => 'reverted-password', 'password_confirm' => 'reverted-password']);
$who = web_username($mb);
$app = app_ok($moverApp);
check('reverted: the old address back, signed out everywhere', $r['status'] === 200
    && db_value("SELECT provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = 'email'", [$mover['id']]) === $mover['email']
    && $who === null && !$app, summary($r) . json_encode([$who, $app]));
check('the Google account linked after the change is gone (D4)', account_google_sub($mover['id']) === null
    && db_value('SELECT COUNT(*) FROM account_emails WHERE email = ?', ["am_{$run}_intruder@gmail.invalid"]) === 0);
check('the intruder\'s address no longer belongs to the account', db_value('SELECT COUNT(*) FROM account_emails WHERE email = ?', [$newAddress]) === 0);
check('the old address with the new password signs in', auth_login_password($mover['email'], 'reverted-password')['ok']);
check('e-mail 5 to the old address', outbox_kinds($top) === ['password'], json_encode(outbox_kinds($top)));
deliver();

/* ======================================================================
   A GOOGLE ACCOUNT: AN ADDRESS, THEN A PASSWORD
   ====================================================================== */

section('a Google account adds an address and sets a password');

clear_limits();
$g = web_google_account('gadd', 'sub-' . $run . '-gadd');
$state = web($g, '/api/app/state.php');
check('Settings offers adding an address', ($state['body']['data']['auth']['signin']['can_add_email'] ?? null) === true, json_encode($state['body']['data']['auth']['signin'] ?? null));
$gAddress = "am_{$run}_gadd@ownify-test.invalid";
$r = web($g, '/api/account/email.php', ['email' => $gAddress]);
check('without Google first: "Bevestig eerst met Google."', $r['status'] === 403 && ($r['body']['reauth'] ?? '') === 'google', summary($r));
$start = web($g, '/api/auth/reauth.php', ['action' => 'google', 'purpose' => 'add_email']);
google_back($g, (string) ($start['body']['redirect'] ?? ''), ['sub' => $g['sub'], 'email' => $g['email'], 'auth_time' => time()]);
$top = outbox_top();
$r = web($g, '/api/account/email.php', ['email' => $gAddress]);
check('after Google: e-mail 2 to the new address', $r['status'] === 200 && outbox_kinds($top) === ['link'], summary($r));
$addToken = link_token(mail_to(deliver(), $gAddress) ?? ['text' => '', 'html' => '']);
$r = web(browser(), '/api/auth/link.php', ['action' => 'use', 'token' => $addToken]);
check('the link adds it, confirmed, without a password', $r['status'] === 200
    && db_value("SELECT provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = 'email' AND password_hash IS NULL AND email_verified_at IS NOT NULL", [$g['id']]) === $gAddress, summary($r));
$res = auth_login_password($gAddress, 'anything-at-all');
check('a password-less address signs nobody in', !$res['ok'] && !empty($res['credentials']));

$r = web($g, '/api/account/set-password.php', ['password' => 'google-users-pass', 'password_confirm' => 'google-users-pass']);
check('setting a password needs Google first', $r['status'] === 403 && ($r['body']['reauth'] ?? '') === 'google', summary($r));
$start = web($g, '/api/auth/reauth.php', ['action' => 'google', 'purpose' => 'set_password']);
$back  = google_back($g, (string) ($start['body']['redirect'] ?? ''), ['sub' => $g['sub'], 'email' => $g['email'], 'auth_time' => time()]);
$top = outbox_top();
$r = web($g, '/api/account/set-password.php', ['password' => 'google-users-pass', 'password_confirm' => 'google-users-pass']);
check('after Google: set, and e-mail 5 (set) queued', $r['status'] === 200 && outbox_kinds($top) === ['password'], summary($r));
check('the address and the new password sign in', auth_login_password($gAddress, 'google-users-pass')['ok']);
check('still signed in here (nobody is signed out)', web_username($g) === $g['username']);
$m = mail_to(deliver(), $gAddress);
check('e-mail 5 says a password was set', $m !== null && str_contains($m['subject'], 'wachtwoord ingesteld'), $m['subject'] ?? '');

/* ======================================================================
   LINKING AND REMOVING GOOGLE
   ====================================================================== */

section('linking Google on the website: the password first, then Google');

clear_limits();
$linker = make_account('linker', $password, true);
$lb     = browser();
web_login($lb, $linker['username'], $password);
$state = web($lb, '/api/app/state.php');
check('Settings offers linking', ($state['body']['data']['auth']['signin']['can_link_google'] ?? null) === true);
$r = web($lb, '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'link']);
check('without the password: refused', $r['status'] === 403, summary($r));
$r = web($lb, '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'link', 'password' => $password]);
check('with it: off to Google', $r['status'] === 200 && str_contains((string) ($r['body']['redirect'] ?? ''), 'state='), summary($r));
google_back($lb, (string) ($r['body']['redirect'] ?? ''), ['sub' => 'sub-' . $run . '-linker', 'email' => $linker['email']]);
check('linked; its address is the account\'s too (via_google)', account_google_sub($linker['id']) === 'sub-' . $run . '-linker'
    && (int) db_value('SELECT via_google FROM account_emails WHERE email = ?', [$linker['email']]) === 1);

$unv = make_account('unvlink', $password, false);
$ub  = browser();
web_login($ub, $unv['username'], $password);
$r = web($ub, '/api/auth/oauth.php', ['provider' => 'google', 'mode' => 'link', 'password' => $password]);
check('an unconfirmed address: "Bevestig eerst je e-mailadres." (409)', $r['status'] === 409, summary($r));

section('linking Google in the app (Credential Manager)');

$appLinker = make_account('applink', $password, true);
$tok = (string) (app_login($appLinker['username'], $password)['body']['token'] ?? '');
$jar = browser();
$r = app_json('/api/account/google.php', ['action' => 'nonce', 'password' => 'wrong-password-1'], $jar, $tok);
check('the wrong password: no nonce', $r['status'] === 403 && !isset($r['body']['nonce']), summary($r));
$r = app_json('/api/account/google.php', ['action' => 'nonce', 'password' => $password], $jar, $tok);
check('the password: a nonce', $r['status'] === 200 && is_string($r['body']['nonce'] ?? null), summary($r));
$taken = app_json('/api/account/google.php', ['action' => 'link', 'id_token' => id_token(['sub' => 'sub-' . $run . '-linker', 'email' => $linker['email'], 'nonce' => (string) ($r['body']['nonce'] ?? '')])], $jar, $tok);
check('a Google account another account has: refused', $taken['status'] === 409 && str_contains((string) ($taken['body']['error'] ?? ''), 'ander Ownify-account'), summary($taken));
$r = app_json('/api/account/google.php', ['action' => 'link', 'id_token' => id_token(['sub' => 'sub-' . $run . '-applink', 'email' => "am_{$run}_applink@gmail.invalid", 'nonce' => 'x'])], $jar, $tok);
check('the nonce works once', $r['status'] === 409, summary($r));
$r = app_json('/api/account/google.php', ['action' => 'nonce', 'password' => $password], $jar, $tok);
$r = app_json('/api/account/google.php', ['action' => 'link', 'id_token' => id_token(['sub' => 'sub-' . $run . '-applink', 'email' => "am_{$run}_applink@gmail.invalid", 'nonce' => (string) ($r['body']['nonce'] ?? '')])], $jar, $tok);
check('linked from the app', $r['status'] === 200 && account_google_sub($appLinker['id']) === 'sub-' . $run . '-applink', summary($r));

section('removing Google: the password, others signed out, e-mail 6b');

$elsewhereB = browser();
web_login($elsewhereB, $linker['username'], $password);
$r = web($lb, '/api/account/google.php', ['action' => 'remove', 'password' => 'wrong-password-1']);
check('the wrong password: refused, Google stays', $r['status'] === 403 && account_google_sub($linker['id']) !== null, summary($r));
$top = outbox_top();
$r = web($lb, '/api/account/google.php', ['action' => 'remove', 'password' => $password]);
check('removed; the website goes past Google to forget Ownify', $r['status'] === 200 && str_contains((string) ($r['body']['redirect'] ?? ''), 'prompt=none') && account_google_sub($linker['id']) === null, summary($r));
check('… the address stays (via_password), via_google cleared', (int) db_value('SELECT via_password FROM account_emails WHERE email = ?', [$linker['email']]) === 1
    && (int) db_value('SELECT via_google FROM account_emails WHERE email = ?', [$linker['email']]) === 0);
check('… this browser stays, the other is signed out', web_username($lb) === $linker['username'] && web_username($elsewhereB) === null);
check('… e-mail 6b queued', outbox_kinds($top) === ['google_removed'], json_encode(outbox_kinds($top)));
$m = mail_to(deliver(), $linker['email']);
check('e-mail 6b arrived', $m !== null && str_contains($m['subject'], 'losgekoppeld'), $m['subject'] ?? '');

/* ======================================================================
   E-MAIL 5 AFTER A PASSWORD CHANGE
   ====================================================================== */

section('e-mail 5 after a password change');

clear_limits();
$changer = make_account('changer', $password, true);
$cb      = browser();
web_login($cb, $changer['username'], $password);
$top = outbox_top();
$r = http('/api/account/password.php', ['jar' => $cb['jar'], 'form' => ['csrf' => csrf($cb), 'current' => $password, 'password' => 'changed-password-1', 'password_confirm' => 'changed-password-1']]);
check('changed, and e-mail 5 queued', $r['status'] === 200 && outbox_kinds($top) === ['password'], summary($r) . json_encode(outbox_kinds($top)));
$noAddress = make_account('noaddr', $password, false);
$nb = browser();
web_login($nb, $noAddress['username'], $password);
$top = outbox_top();
http('/api/account/password.php', ['jar' => $nb['jar'], 'form' => ['csrf' => csrf($nb), 'current' => $password, 'password' => 'changed-password-2', 'password_confirm' => 'changed-password-2']]);
check('… but never to an unconfirmed address', outbox_kinds($top) === [], json_encode(outbox_kinds($top)));
$m = mail_to(deliver(), $changer['email']);
check('e-mail 5 arrived, a security notice', $m !== null && str_contains($m['subject'], 'gewijzigd'), $m['subject'] ?? '');

section('housekeeping');

$old = "am_{$run}_old";
$made[] = $old;
web(browser(), '/api/auth/register.php', ['email' => "am_{$run}_old@ownify-test.invalid", 'username' => $old, 'password' => $password, 'password_confirm' => $password]);
db_run('UPDATE pending_registrations SET expires_at = NOW() - INTERVAL 1 SECOND WHERE user_id = ?', [user_id($old)]);
deliver();
check('a registration past its 7 days is deleted, username and all', user_id($old) === null);
check('the outbox is empty after delivery', (int) db_value('SELECT COUNT(*) FROM mail_outbox') === 0);
$log = (string) @file_get_contents($mailDir . '/out/log.txt');
check('every message went through STARTTLS and a login', $log !== '' && !str_contains(strtolower($log), 'refused'), substr($log, 0, 300));

} finally {
    /* -------------------------------------------------------- clean up */
    foreach ($servers as $server) {
        proc_terminate($server);
    }
    proc_terminate($smtp);

    foreach (array_unique($made) as $username) {
        db_run('DELETE FROM users WHERE username = ?', [$username]);
    }
    db_run("DELETE FROM users WHERE username LIKE ?", ['am\\_' . $run . '\\_%']);
    db_run('DELETE FROM account_emails WHERE email LIKE ?', ['%' . $run . '%']);
    db_run('DELETE FROM mail_outbox');
    db_run('DROP TRIGGER IF EXISTS am_outbox_seen_insert');
    db_run('DROP TABLE IF EXISTS am_outbox_seen');
    clear_limits();

    exec('rm -rf ' . escapeshellarg($mailDir));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
