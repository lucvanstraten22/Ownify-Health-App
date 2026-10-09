<?php
/**
 * Whether a cloud source can be connected, and why not — the one check both
 * Polar and Google Health go through (integration_blocked_reason() in
 * includes/integrations.php, and the app key in includes/crypto.php).
 *
 *     DB_NAME=ownify_dev DB_USER=root php tools/integration-readiness-test.php
 *
 * Each case runs in a fresh copy of includes/, config/ and api/ in a
 * temporary folder, so the key and the local config files of THIS machine
 * play no part: the server's situation is rebuilt file by file.
 *
 *   live before the fix   no app key anywhere, config/integrations.local.php
 *                         copied from the example (Google's placeholders
 *                         left in) with Polar's id and secret
 *   a usable key          config/app.local.php with 32 bytes of base64, or
 *                         OWNIFY_APP_KEY in the environment
 *   a broken key          the example's words, or too short
 *
 * The database (DB_NAME) needs migration 020. Nothing is written to it.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

if ((string) getenv('DB_NAME') === '') {
    fwrite(STDERR, "Set DB_NAME to a development database with migration 020.\n");
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

function copy_tree(string $from, string $to): void
{
    @mkdir($to, 0700, true);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        $item->isDir() ? @mkdir($target, 0700, true) : copy($item->getPathname(), $target);
    }
}

/**
 * A server, file by file: `$files` are config files to write (name => PHP
 * returned array, as source), `$env` the environment. Answers what each
 * source's row would say, and what the startup check logged.
 *
 * @return array{reasons: array<string, ?string>, log: string, output: string}
 */
function server(array $files, array $env = []): array
{
    global $root;
    $dir = sys_get_temp_dir() . '/ownify-ready-' . bin2hex(random_bytes(4));
    foreach (['includes', 'config', 'api'] as $part) {
        copy_tree($root . '/' . $part, $dir . '/' . $part);
    }
    foreach (glob($dir . '/config/*.local.php') ?: [] as $local) {
        if (basename($local) !== 'database.local.php') {
            unlink($local);                               // this machine's own key and sources: not the server's
        }
    }
    foreach ($files as $name => $body) {
        file_put_contents($dir . '/config/' . $name, "<?php\nreturn " . $body . ";\n");
    }

    $script = <<<'PHP'
        require 'includes/bootstrap.php';
        require 'includes/integrations.php';
        $out = [];
        foreach (['polar', 'google_health', 'google_health_connect'] as $p) { $out[$p] = integration_blocked_reason($p); }
        echo json_encode($out);
        PHP;

    $log  = $dir . '/php-error.log';
    $base = ['DB_NAME' => getenv('DB_NAME'), 'DB_USER' => (string) getenv('DB_USER'), 'DB_PASSWORD' => (string) getenv('DB_PASSWORD'), 'PATH' => getenv('PATH')];
    $proc = proc_open(['php', '-d', 'error_log=' . $log, '-d', 'log_errors=1', '-r', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env + $base);
    $output = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    proc_close($proc);

    $result = ['reasons' => (array) json_decode($output, true), 'log' => (string) @file_get_contents($log), 'output' => $output];
    exec('rm -rf ' . escapeshellarg($dir));
    return $result;
}

$example = file_get_contents($root . '/config/integrations.local.php.example');
$exampleArray = substr($example, strpos($example, 'return') + 6, strrpos($example, ';') - strpos($example, 'return') - 6);
$polarCreds = "['polar' => ['client_id' => 'live-client-id-123', 'client_secret' => 'live-secret-456']]";

/* As the live server had it: the example copied, Polar filled in, no key. */
$liveIntegrations = str_replace("'PUT-YOUR-POLAR-CLIENT-ID-HERE'", "'live-client-id-123'",
    str_replace("'PUT-YOUR-POLAR-CLIENT-SECRET-HERE'", "'live-secret-456'", $exampleArray));
$key = base64_encode(random_bytes(32));

echo "\n== the live server, before ==\n";
$live = server(['integrations.local.php' => $liveIntegrations]);
check('Polar: credentials and migration found, then stopped by the missing app key',
    ($live['reasons']['polar'] ?? null) === 'De server kan tokens nog niet veilig opslaan.', $live['output']);
check('Google Health: the example\'s placeholders no longer count as credentials, and its flow was never built',
    ($live['reasons']['google_health'] ?? null) === 'Deze koppeling is nog niet beschikbaar in Ownify.', $live['output']);
check('the server log says why, by name, without a value', str_contains($live['log'], 'OWNIFY_APP_KEY is not set') && str_contains($live['log'], 'config/app.local.php'), $live['log']);
check('Health Connect is untouched by the key', array_key_exists('google_health_connect', $live['reasons']) && $live['reasons']['google_health_connect'] === null);

echo "\n== the fix: config/app.local.php with app_key ==\n";
$fixed = server(['integrations.local.php' => $liveIntegrations, 'app.local.php' => "['app_key' => '" . $key . "']"]);
check('Polar passes the check: it can be connected', array_key_exists('polar', $fixed['reasons']) && $fixed['reasons']['polar'] === null, $fixed['output']);
check('Google Health still says plainly it is not available (no connect flow), not a key problem', ($fixed['reasons']['google_health'] ?? '') === 'Deze koppeling is nog niet beschikbaar in Ownify.');
check('nothing about the key in the log, and never the key', !str_contains($fixed['log'], 'APP_KEY') && !str_contains($fixed['log'] . $fixed['output'], $key));

echo "\n== or OWNIFY_APP_KEY in the environment ==\n";
$env = server(['integrations.local.php' => $polarCreds], ['OWNIFY_APP_KEY' => $key]);
check('Polar passes the check with the key from the environment', array_key_exists('polar', $env['reasons']) && $env['reasons']['polar'] === null, $env['output']);

echo "\n== a key that is not one ==\n";
foreach (['the example\'s words' => 'PUT-32-BYTES-OF-BASE64-HERE', 'too short' => base64_encode(random_bytes(16))] as $what => $bad) {
    $broken = server(['integrations.local.php' => $polarCreds, 'app.local.php' => "['app_key' => '" . $bad . "']"]);
    check("$what: refused, never used, and tokens are not stored in the clear", ($broken['reasons']['polar'] ?? null) === 'De server kan tokens nog niet veilig opslaan.', $broken['output']);
    check("$what: the log says it is invalid and how long, not what it is", str_contains($broken['log'], 'is not 32 bytes') && !str_contains($broken['log'], $bad), $broken['log']);
}

echo "\n== placeholders ==\n";
require_once $root . '/includes/integrations.php';
foreach (['000000000000-xxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com', 'GOCSPX-xxxxxxxxxxxxxxxxxxxx', 'https://your-domain.tld/x', 'PUT-YOUR-POLAR-CLIENT-ID-HERE', ''] as $p) {
    check('"' . $p . '" reads as not set', integration_placeholder($p));
}
foreach (['a1b2c3d4-1111-2222-3333-444455556666', 'https://ownify.acits.nl/api/integrations/polar/callback.php'] as $p) {
    check('"' . $p . '" reads as set', !integration_placeholder($p));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
