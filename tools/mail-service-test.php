<?php
/**
 * The mail service (includes/mailer.php) end to end, against a stand-in for
 * Gmail's SMTP on this machine (tools/fake-smtp.py): STARTTLS with a
 * certificate PHP really verifies, a real login, a real DATA — the same code
 * that talks to smtp.gmail.com, with only the host and port pointed here
 * (OWNIFY_SMTP_HOST / OWNIFY_SMTP_PORT). No database needed.
 *
 *     php tools/mail-service-test.php
 *
 * What it cannot do is reach Gmail: tools/mail-test.php on the server does
 * that (docs/MAIL.md).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
const FAKE_USER = 'noreply.ownify@gmail.com';
const FAKE_PASS = 'abcdefghijklmnop';      // a stand-in: the shape of an App Password, nothing else

/* ------------------------------------------------------- one case, in a child */
$case = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--case=')) {
        $case = substr($arg, 7);
    }
}

if ($case !== null) {
    require_once $root . '/includes/mailer.php';
    $out = match ($case) {
        'settings' => (static function (): array {
            $s = mail_settings();
            return ['host' => $s['host'], 'port' => $s['port'], 'encryption' => $s['encryption'], 'from' => $s['from'],
                    'username' => $s['username'], 'from_name' => $s['from_name'], 'password_length' => strlen($s['password']),
                    'transport' => mail_transport_reason()];
        })(),
        'check'   => mail_smtp_check(),
        'send'    => mail_send('Test.Persoon@Example.com', 'Ownify: café ✓ — test',
            "Hallo,\n\nDit is de tekst: één café.\n", '<p>Hallo, <strong>één café</strong>.</p>', ['kind' => 'test']),
        'textonly' => mail_send(['een@example.com', 'twee@example.com'], 'Alleen tekst', "Platte tekst.\n", null, ['kind' => 'test']),
        'outbox'  => ['code' => mail_smtp_send(
            ['recipient' => 'outbox@example.com', 'kind' => 'verify_signup', 'message_id' => '<fixed-id-123@ownify.acits.nl>'],
            ['subject' => 'Bevestig je e-mailadres', 'text' => 'Link: https://ownify.acits.nl/x', 'html' => '<a href="https://ownify.acits.nl/x">Bevestig</a>']
        )],
        'badto'   => mail_send("a@example.com\nBcc: someone@else.invalid", 'x', 'x'),
        'badsubj' => mail_send('a@example.com', "x\r\nBcc: someone@else.invalid", 'x'),
        default   => ['unknown case'],
    };
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit(0);
}

/* ----------------------------------------------------------- the harness */
$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($ok || $detail === '' ? '' : "\n          " . $detail) . "\n";
}

$dir = sys_get_temp_dir() . '/ownify-mail-test-' . bin2hex(random_bytes(4));
mkdir($dir . '/out', 0700, true);

/* A CA and a certificate for "localhost" signed by it: PHP verifies the
   chain and the name, as it does Gmail's. */
$q = static fn (string $s): string => escapeshellarg($s);
exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=Ownify test CA" -keyout ' . $q("$dir/ca.key") . ' -out ' . $q("$dir/ca.pem") . ' 2>&1', $o1, $r1);
file_put_contents("$dir/san.cnf", "subjectAltName=DNS:localhost\n");
exec('openssl req -newkey rsa:2048 -nodes -subj "/CN=localhost" -keyout ' . $q("$dir/srv.key") . ' -out ' . $q("$dir/srv.csr") . ' 2>&1', $o2, $r2);
exec('openssl x509 -req -in ' . $q("$dir/srv.csr") . ' -CA ' . $q("$dir/ca.pem") . ' -CAkey ' . $q("$dir/ca.key") . ' -CAcreateserial -days 1 -extfile ' . $q("$dir/san.cnf") . ' -out ' . $q("$dir/srv.pem") . ' 2>&1', $o3, $r3);
if ($r1 || $r2 || $r3) {
    echo "Could not make a test certificate (openssl).\n";
    exit(2);
}

$port = 30000 + random_int(0, 20000);
$server = proc_open(['python3', __DIR__ . '/fake-smtp.py', (string) $port, "$dir/srv.pem", "$dir/srv.key", FAKE_USER, FAKE_PASS, "$dir/out"],
    [['pipe', 'r'], ['file', "$dir/server.err", 'w'], ['file', "$dir/server.err", 'a']], $pipes);
for ($i = 0; $i < 50 && !is_file("$dir/out/ready"); $i++) {
    usleep(100000);
}
if (!is_file("$dir/out/ready")) {
    echo "The stand-in SMTP server did not start.\n";
    exit(2);
}

/** Runs one case (or a script) in its own PHP, with the settings it is given. */
function run(array $args, array $env, string $dir, bool $trust = true): array
{
    $log = "$dir/php-error.log";
    @unlink($log);
    $cmd = array_merge([PHP_BINARY, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $log],
        $trust ? ['-d', 'openssl.cafile=' . "$dir/ca.pem"] : ['-d', 'openssl.cafile=', '-d', 'openssl.capath='], $args);
    $proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $p, null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    $stdout = stream_get_contents($p[1]);
    $stderr = stream_get_contents($p[2]);
    $code = proc_close($proc);
    return ['out' => $stdout, 'err' => $stderr, 'code' => $code, 'log' => is_file($log) ? (string) file_get_contents($log) : ''];
}

$here = ['OWNIFY_SMTP_HOST' => 'localhost', 'OWNIFY_SMTP_PORT' => (string) $port];
$good = $here + ['OWNIFY_SMTP_PASSWORD' => 'abcd efgh ijkl mnop'];   // as Google shows it: spaces and all
$bad  = $here + ['OWNIFY_SMTP_PASSWORD' => 'wrongwrongwrongw'];
$case = static fn (string $name, array $env, bool $trust = true): array => run([__FILE__, '--case=' . $name], $env, $dir, $trust);
$json = static fn (array $r): array => (array) json_decode($r['out'], true);
$messages = static fn (): array => glob("$dir/out/message-*.eml") ?: [];
$sessions = static fn (): string => is_file("$dir/out/log.txt") ? (string) file_get_contents("$dir/out/log.txt") : '';

echo "Ownify mail service, against a stand-in for Gmail\n";

/* ---------------------------------------------------------------- settings */
echo "\n== The settings ==\n";
$s = $json(run([__FILE__, '--case=settings'], [], $dir));
check('Gmail SMTP on 587 with STARTTLS', $s['host'] === 'smtp.gmail.com' && $s['port'] === 587 && $s['encryption'] === 'starttls', json_encode($s));
check('noreply.ownify@gmail.com, as Ownify, logs in as itself', $s['from'] === 'noreply.ownify@gmail.com' && $s['username'] === 'noreply.ownify@gmail.com' && $s['from_name'] === 'Ownify');
check('no password in the repository: not configured here', $s['password_length'] === 0 && str_contains((string) $s['transport'], "'password' is not set"), (string) $s['transport']);
$s = $json(run([__FILE__, '--case=settings'], $good, $dir));
check('the App Password as Google shows it (with spaces) is read without them', $s['password_length'] === 16 && $s['transport'] === null);

/* ---------------------------------------------------------------- nothing set */
echo "\n== Not configured ==\n";
$before = $sessions();
$r = $case('send', $here);
check('nothing is sent, and nothing connects', $json($r)['ok'] === false && $json($r)['stage'] === 'config' && $sessions() === $before, $r['out']);
check('logged as not configured, without the address', str_contains($r['log'], 'not sent (config, not-configured)') && !str_contains(strtolower($r['log']), 'example.com'), $r['log']);

/* ---------------------------------------------------------------- connect and log in */
echo "\n== Connect, STARTTLS, log in ==\n";
$r = $case('check', $good);
check('connects, starts TLS and logs in', $json($r)['ok'] === true, $r['out'] . $r['err']);
check('… over TLS, never in the clear', str_contains($sessions(), "tls\nauth ok"), $sessions());
$r = $case('check', $good, false);
check('an untrusted certificate is refused (verification stays on)', $json($r)['ok'] === false && in_array($json($r)['stage'], ['tls', 'connect'], true), $r['out']);
$r = $case('check', $bad);
check('a wrong App Password: refused at the login, with Gmail\'s code', $json($r)['ok'] === false && $json($r)['stage'] === 'auth' && str_starts_with((string) $json($r)['code'], '535'), $r['out']);

/* ---------------------------------------------------------------- sending */
echo "\n== Sending ==\n";
$n = count($messages());
$r = $case('send', $good);
check('an HTML message with a text part is accepted', $json($r) === ['ok' => true, 'stage' => null, 'code' => null], $r['out'] . $r['err'] . $r['log']);
$all = $messages();
$eml = count($all) > $n ? (string) file_get_contents(end($all)) : '';
check('from Ownify <noreply.ownify@gmail.com>', (bool) preg_match('/^From: Ownify <noreply\.ownify@gmail\.com>\r?$/m', $eml), substr($eml, 0, 400));
check('to the address as the account rule writes it', str_contains($eml, 'X-Fake-Envelope-To: test.persoon@example.com') && (bool) preg_match('/^To: test\.persoon@example\.com\r?$/mi', $eml));
check('UTF-8 throughout: the subject encoded, the parts declared', (bool) preg_match('/^Subject: =\?utf-8\?/mi', $eml) && substr_count(strtolower($eml), 'charset=utf-8') >= 2);
$subject = preg_match('/^Subject: (.+(?:\r?\n[ \t].+)*)/mi', $eml, $m) ? preg_replace('/\r?\n[ \t]+/', ' ', $m[1]) : '';
check('the subject decodes to what was sent', mb_decode_mimeheader(trim($subject)) === 'Ownify: café ✓ — test', $subject);
check('both parts: text/plain and text/html', str_contains($eml, 'multipart/alternative') && str_contains($eml, 'text/plain') && str_contains($eml, 'text/html'));
check('the text arrives intact (quoted-printable, decoded)', str_contains(quoted_printable_decode($eml), 'Dit is de tekst: één café.'));
check('no X-Mailer header naming the library', !str_contains($eml, 'X-Mailer'));

$r = $case('textonly', $good);
$all = $messages();
$eml = (string) file_get_contents(end($all));
check('plain text to two recipients', $json($r)['ok'] === true && str_contains($eml, 'X-Fake-Envelope-To: een@example.com,twee@example.com') && !str_contains($eml, 'text/html'), $r['out']);

$r = $case('outbox', $good);
$all = $messages();
$eml = (string) file_get_contents(end($all));
check('the outbox sends through the same service, with its own Message-ID', $json($r) === ['code' => null] && str_contains($eml, 'Message-ID: <fixed-id-123@ownify.acits.nl>'), $r['out']);

/* ---------------------------------------------------------------- refused before sending */
echo "\n== Refused before anything connects ==\n";
$before = $sessions();
$r1 = $case('badto', $good);
$r2 = $case('badsubj', $good);
check('a header in an address goes nowhere', $json($r1)['ok'] === false && $json($r1)['stage'] === 'recipient', $r1['out']);
check('a header in a subject goes nowhere', $json($r2)['ok'] === false && $json($r2)['stage'] === 'subject', $r2['out']);
check('… and neither connected', $sessions() === $before);

/* ---------------------------------------------------------------- nothing leaks */
echo "\n== What is logged and printed ==\n";
$r = $case('send', $bad);
check('a failure is logged: the kind, the step, the code', (bool) preg_match('/\[ownify\] mail: a test message was not sent \(auth, 535[0-9. ]*\)/', $r['log']), $r['log']);
foreach (['the password' => 'wrongwrongwrongw', 'the address' => 'example.com', 'the login' => FAKE_USER, 'the subject' => 'café'] as $what => $secret) {
    check("never $what in the log or the output", !str_contains(strtolower($r['log'] . $r['out'] . $r['err']), strtolower($secret)));
}
check('no SMTP conversation printed (debug off)', !str_contains($r['out'] . $r['err'], 'AUTH') && !str_contains($r['out'] . $r['err'], 'CLIENT -> SERVER'));

/* ---------------------------------------------------------------- the tools */
echo "\n== The tools ==\n";
$r = run([$root . '/tools/mail-test.php', 'you@example.com'], $good, $dir);
check('tools/mail-test.php: connect, log in and send — "sent", exit 0', $r['code'] === 0 && str_contains($r['out'], 'connect+login  ok') && str_contains($r['out'], 'sent — Gmail accepted it'), $r['out'] . $r['err']);
check('… says which settings it read, never the password', str_contains($r['out'], '$OWNIFY_SMTP_PASSWORD') && !str_contains($r['out'], FAKE_PASS) && !str_contains($r['out'], 'abcd efgh'), $r['out']);
$r = run([$root . '/tools/mail-test.php', 'you@example.com'], $bad, $dir);
check('… a wrong App Password: "FAILED at auth (535 …)", exit 1', $r['code'] === 1 && str_contains($r['out'], 'FAILED at auth (535'), $r['out']);
$r = run([$root . '/tools/mail-test.php', 'you@example.com'], $here, $dir);
check('… no password: says where it goes, connects nowhere, exit 1', $r['code'] === 1 && str_contains($r['out'], "'password' is not set") && str_contains($r['out'], 'password       NOT SET'), $r['out']);
$r = run([$root . '/tools/check-config.php', '--send-test=you@example.com'], $good, $dir);
check('check-config --send-test uses the same service', $r['code'] === 0 && trim($r['out']) === 'verstuurd', $r['out'] . $r['err']);

/* ---------------------------------------------------------------- done */
proc_terminate($server);
proc_close($server);
exec('rm -rf ' . $q($dir));

echo "\n  $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
