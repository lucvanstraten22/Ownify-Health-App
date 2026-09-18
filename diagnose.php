<?php
/**
 * TEMPORARY. Delete this file once the site is healthy again.
 *
 *     https://your-domain.tld/diagnose.php
 *
 * When the site answers 500 and nothing else, the error is in a log only the
 * server's owner can read. This answers the same question from the outside: it
 * deliberately does NOT go through includes/bootstrap.php, so it still works
 * when that is what is broken, and it reports the failure rather than becoming
 * it.
 *
 * It prints versions, yes/no facts and one commit hash. No passwords, no key,
 * no database name, no absolute paths — the document root is stripped out of
 * everything, including exception messages. That is deliberate: a page that
 * exists because something is broken is a page that gets left behind.
 *
 * Leave it behind anyway and the worst it gives away is a PHP version, which
 * most hosts already put in a response header. Still: delete it.
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$root = __DIR__;

/** Never print where the site lives on disk. */
function scrub(string $text): string
{
    global $root;

    return str_replace([$root . DIRECTORY_SEPARATOR, $root], ['', '.'], $text);
}

function row(string $label, string $value): void
{
    printf("%-26s %s\n", $label, $value);
}

echo "JoLu diagnostics\n";
echo str_repeat('=', 72) . "\n\n";

echo "RUNTIME\n";
row('PHP', PHP_VERSION . '  (' . PHP_SAPI . ')');

foreach (['pdo_mysql', 'sodium', 'mbstring', 'json'] as $extension) {
    row('extension ' . $extension, extension_loaded($extension) ? 'loaded' : 'MISSING');
}

echo "\nWHAT IS DEPLOYED\n";

$version = $root . '/VERSION';
row(
    'VERSION',
    is_file($version)
        ? substr(trim((string) file_get_contents($version)), 0, 12)
            . '   (written ' . date('Y-m-d H:i:s', (int) filemtime($version)) . ')'
        : 'no VERSION file'
);

/* The files that every request depends on. An mtime older than VERSION means
   that file did not arrive in the last deploy. */
foreach ([
    'includes/bootstrap.php',
    'includes/crypto.php',
    'includes/db.php',
    'includes/session.php',
    'index.php',
] as $file) {
    $path = $root . '/' . $file;

    row(
        $file,
        is_file($path)
            ? date('Y-m-d H:i:s', (int) filemtime($path)) . '  ' . filesize($path) . ' bytes'
            : 'MISSING'
    );
}

echo "\nOPCODE CACHE\n";

if (!function_exists('opcache_get_status')) {
    row('opcache', 'not installed — nothing can be stale');
} else {
    $status = @opcache_get_status(true);

    if (!is_array($status) || empty($status['opcache_enabled'])) {
        row('opcache', 'installed but off — nothing can be stale');
    } else {
        row('opcache', 'ON');
        row('  validate_timestamps', ini_get('opcache.validate_timestamps') ? '1 (re-checks files)' : '0 (NEVER re-checks — must be reset by hand)');
        row('  revalidate_freq', (string) ini_get('opcache.revalidate_freq') . ' seconds');

        /* The decisive comparison: what the cache compiled, against what is on
           disk right now. */
        foreach (['includes/crypto.php', 'includes/bootstrap.php'] as $file) {
            $path   = $root . '/' . $file;
            $cached = $status['scripts'][$path] ?? null;

            if ($cached === null) {
                row('  ' . $file, 'not cached');
                continue;
            }

            $onDisk   = (int) filemtime($path);
            $compiled = (int) ($cached['timestamp'] ?? 0);

            row(
                '  ' . $file,
                $compiled < $onDisk
                    ? 'STALE — compiled ' . date('H:i:s', $compiled) . ', file changed ' . date('H:i:s', $onDisk)
                    : 'fresh'
            );
        }
    }
}

echo "\nDOES THE APP LOAD\n";

/* The whole point. A fatal inside an include is a throwable in PHP 8, so the
   thing that 500s the site can be caught here and printed. */
$level = ob_get_level();
ob_start();

try {
    require_once $root . '/includes/bootstrap.php';
    ob_end_clean();

    row('includes/bootstrap.php', 'loads');

    foreach (['app_auth', 'db_available', 'crypto_key', 'crypto_check', 'crypto_status'] as $function) {
        row('  ' . $function . '()', function_exists($function) ? 'defined' : 'NOT DEFINED');
    }

    row('database', function_exists('db_available') && db_available() ? 'reachable' : 'NOT reachable');
} catch (Throwable $e) {
    while (ob_get_level() > $level) {
        ob_end_clean();
    }

    row('includes/bootstrap.php', 'FAILS');
    echo "\n";
    echo '  ' . $e::class . ': ' . scrub($e->getMessage()) . "\n";
    echo '  at ' . scrub($e->getFile()) . ':' . $e->getLine() . "\n";
    echo "\n  This is what the browser is seeing as a 500.\n";
}

/* ---------------------------------------------------------------------------
 * THE SCHEMA
 * ---------------------------------------------------------------------------
 * A table the code reads and the database does not have is invisible from a
 * browser until it takes a page down — which is how 18 September went. The
 * expected list is read out of database/schema.sql rather than written here,
 * so it cannot drift from the schema it is checking.
 * ------------------------------------------------------------------------ */

echo "\nDATABASE SCHEMA\n";

$sql = @file_get_contents($root . '/database/schema.sql');

if (!is_string($sql) || $sql === '') {
    row('schema.sql', 'not readable — cannot compare');
} elseif (!function_exists('db_available') || !db_available()) {
    row('comparison', 'no database connection');
} else {
    preg_match_all('/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?([a-z0-9_]+)`?/i', $sql, $found);

    $expected = array_unique($found[1] ?? []);
    sort($expected);

    $present = [];
    foreach (db_all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
        $present[strtolower((string) reset($r))] = true;
    }

    $missing = [];
    foreach ($expected as $table) {
        if (!isset($present[strtolower($table)])) {
            $missing[] = $table;
        }
    }

    row('tables expected', (string) count($expected));
    row('tables present', (string) count($present));

    if ($missing === []) {
        row('missing', 'none — the schema is complete');
    } else {
        row('MISSING', implode(', ', $missing));
        echo "\n  Import the migration that adds these, in database/migrations/.\n";
    }
}

echo "\nWHERE PHP WRITES ERRORS\n";
row('error_log', (string) (ini_get('error_log') ?: 'the SAPI default (Apache/FPM log)'));
row('display_errors', ini_get('display_errors') ? 'on' : 'off  (so a fatal is a blank 500)');

/* ---------------------------------------------------------------------------
 * The page itself.
 *
 * bootstrap.php loading does not mean index.php renders — everything the page
 * pulls in afterwards (the config files, the hydrators, lib/, the components)
 * can fatal just as well, and from a browser that looks identical. So render it
 * into a buffer and throw the output away; only the failure is interesting.
 *
 * Two nets, because there are two kinds of fatal: a throwable, which the catch
 * takes, and the kind that cannot be caught at all — memory exhaustion, a
 * timeout, a parse error in an included file — which only the shutdown handler
 * sees.
 * ------------------------------------------------------------------------ */

echo "\nDOES THE PAGE RENDER\n";

row('output_buffering (ini)', (string) (ini_get('output_buffering') ?: '0'));
row('buffer level here', (string) ob_get_level());
row('memory_limit', (string) ini_get('memory_limit'));
row('max_execution_time', (string) ini_get('max_execution_time'));

$finished = false;

register_shutdown_function(static function () use (&$finished, $root): void {
    if ($finished) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $fatal = error_get_last();

    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "index.php                  FATAL — this is the 500\n\n";
        echo '  ' . scrub($fatal['message']) . "\n";
        echo '  at ' . scrub($fatal['file']) . ':' . $fatal['line'] . "\n";
    } else {
        echo "index.php                  stopped early (exit or redirect), no error recorded\n";
    }

    echo "\n" . str_repeat('=', 72) . "\n";
});

$before = ob_get_level();
ob_start();

try {
    require $root . '/index.php';

    /* Read the buffer BEFORE closing it, and say how many levels are open, so
       that "0 bytes" can be told apart from "something ate the buffer". The
       first time this ran against the live server it reported 0 bytes and no
       error at all, which is not a thing a 232 KB page does. */
    $after = ob_get_level();
    $html  = $after > $before ? (string) ob_get_contents() : '';

    while (ob_get_level() > $before) {
        ob_end_clean();
    }

    $finished = true;

    row('index.php', 'ran without throwing');
    row('  buffer levels', $before . ' before, ' . $after . ' after');
    row('  output captured', strlen($html) . ' bytes');
    row('  has the app shell', str_contains($html, '<html') || str_contains($html, '<main') ? 'yes' : 'NO');

    if ($html !== '' && !str_contains($html, '</html>')) {
        echo "\n  The page is TRUNCATED — it stopped part way through.\n";
        echo "  last 300 characters it managed:\n\n";
        echo '  ' . scrub(substr($html, -300)) . "\n";
    }
} catch (Throwable $e) {
    while (ob_get_level() > $level) {
        ob_end_clean();
    }

    $finished = true;

    echo "index.php                  FAILS — this is the 500\n\n";
    echo '  ' . $e::class . ': ' . scrub($e->getMessage()) . "\n";
    echo '  at ' . scrub($e->getFile()) . ':' . $e->getLine() . "\n\n";
    echo "  how it got there:\n";

    foreach (array_slice($e->getTrace(), 0, 6) as $depth => $frame) {
        printf(
            "    #%d %s:%s  %s()\n",
            $depth,
            scrub((string) ($frame['file'] ?? '?')),
            (string) ($frame['line'] ?? '?'),
            (string) ($frame['function'] ?? '?')
        );
    }
}

$last = error_get_last();

if ($last !== null) {
    echo "\nLAST PHP ERROR\n";
    echo '  ' . scrub($last['message']) . "\n";
    echo '  at ' . scrub($last['file']) . ':' . $last['line'] . "\n";
}

echo "\n" . str_repeat('=', 72) . "\n";
echo "Delete this file when the site is healthy.\n";
