<?php
/**
 * The configuration check, on demand.
 *
 *     php tools/check-config.php
 *     php tools/check-config.php --generate-key
 *
 * Answers the questions you cannot answer by looking at the site: is the
 * database reachable with the credentials this checkout will actually use, and
 * is JOLU_APP_KEY set — and if it is set, where did it come from and is it the
 * right shape. The same question the app asks itself on every request, written
 * to the server log by includes/bootstrap.php; this is how you ask it without
 * reading a log.
 *
 * ---------------------------------------------------------------------------
 * IT PRINTS NO SECRETS
 * ---------------------------------------------------------------------------
 * Not the database password, not the key, not a prefix of the key. Which file
 * or environment a value came from, and whether it is usable — that is all,
 * because this is the sort of thing that gets pasted into a chat window when
 * something is broken.
 *
 * Exit code 0 when everything essential is in place, 1 when it is not, so a
 * deploy script or a cron can act on it.
 */

declare(strict_types=1);

/* This lives under the document root on a Hestia deploy, so it must never
   answer a browser. A configuration report is a map of the server. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/crypto.php';

$argvFlags = array_slice($argv ?? [], 1);

if (in_array('--generate-key', $argvFlags, true)) {
    $key = crypto_generate_key();

    echo "A fresh JOLU_APP_KEY. Put it in config/app.local.php as 'app_key',\n";
    echo "or in the environment. It is shown once — this tool does not store it.\n\n";
    echo $key . "\n\n";
    echo "Changing an existing key makes every token already encrypted with the\n";
    echo "old one unreadable, and everyone affected has to reconnect.\n";
    exit(0);
}

$problems = 0;

/** One line of the report. */
function line(string $state, string $label, string $detail): void
{
    $mark = match ($state) {
        'ok'   => '  ok  ',
        'warn' => ' warn ',
        default => ' FAIL ',
    };

    printf("[%s] %-22s %s\n", $mark, $label, $detail);
}

echo "JoLu configuration check\n";
echo str_repeat('-', 72) . "\n";

/* ------------------------------------------------------------------ PHP */

line('ok', 'PHP', PHP_VERSION . '  (' . PHP_SAPI . ')');

foreach (['pdo_mysql' => true, 'sodium' => false, 'mbstring' => true] as $extension => $essential) {
    if (extension_loaded($extension)) {
        line('ok', 'extension ' . $extension, 'loaded');
        continue;
    }

    line($essential ? 'fail' : 'warn', 'extension ' . $extension, 'missing');

    if ($essential) {
        $problems++;
    }
}

/* ------------------------------------------------------------- database */

$settings = (array) require dirname(__DIR__) . '/config/database.php';

/* Names and hosts, never the password. */
line(
    'ok',
    'database settings',
    sprintf(
        '%s@%s:%s/%s  (password %s)',
        $settings['username'] ?? '?',
        $settings['host'] ?? '?',
        $settings['port'] ?? '?',
        $settings['database'] ?? '?',
        ($settings['password'] ?? '') === '' ? 'EMPTY' : 'set'
    )
);

if (is_file(dirname(__DIR__) . '/config/database.local.php')) {
    line('ok', 'database.local.php', 'present');
} else {
    line('warn', 'database.local.php', 'absent — the defaults in config/database.php are in use');
}

if (db_available()) {
    line('ok', 'database connection', 'reachable');

    $tables = (int) db_value(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
    );

    if ($tables > 0) {
        line('ok', 'schema', $tables . ' tables');

        /* Not fatal either: without it a sign-in lasts as long as its session,
           as it always did — which is exactly what it is there to fix. */
        $logins = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'user_login_tokens'"
        );

        if ($logins > 0) {
            line('ok', 'staying signed in', 'user_login_tokens is there');
        } else {
            line('warn', 'staying signed in', 'user_login_tokens is missing, so a sign-in ends with its session — '
                . 'import database/migrations/008-persistent-login.sql');
        }

        /* Not fatal: without it the health scores still work, but nothing
           earns leaderboard points, because an award could not be made once. */
        $awards = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'point_events' AND column_name = 'award_key'"
        );

        if ($awards > 0) {
            line('ok', 'leaderboard points', 'point_events.award_key is there');
        } else {
            line('warn', 'leaderboard points', 'point_events.award_key is missing, so no points are awarded — '
                . 'import database/migrations/010-health-score-and-points.sql');
        }

        /* Not fatal: without it friends work and everybody accepts requests,
           but "Vriendverzoeken toestaan" cannot be switched off. */
        $friendSetting = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles' AND column_name = 'allow_friend_requests'"
        );

        if ($friendSetting > 0) {
            line('ok', 'friend requests', 'user_profiles.allow_friend_requests is there');
        } else {
            line('warn', 'friend requests', 'user_profiles.allow_friend_requests is missing, so "Vriendverzoeken toestaan" '
                . 'cannot be switched off — import database/migrations/011-friend-requests-setting.sql');
        }
    } else {
        line('fail', 'schema', 'the database is empty — import database/schema.sql');
        $problems++;
    }
} else {
    line('fail', 'database connection', db_note_failure() ?? 'unreachable');
    $problems++;
}

/* ------------------------------------------------------------- app key */

$key = crypto_status();

switch ($key['state']) {
    case 'ok':
        line('ok', 'JOLU_APP_KEY', 'set and usable, from ' . $key['source']);
        break;

    case 'missing':
        /* Not fatal. Nothing in the app stores an encrypted token yet, so this
           is a warning about the next feature rather than a broken one. */
        line('warn', 'JOLU_APP_KEY', 'not set — see config/app.local.php.example');
        break;

    default:
        line('fail', 'JOLU_APP_KEY', $key['message']);
        $problems++;
}

if (is_file(dirname(__DIR__) . '/config/app.local.php')) {
    line('ok', 'app.local.php', 'present');
} else {
    line('warn', 'app.local.php', 'absent');
}

/* --------------------------------------------------------- integrations */

$integrations = (array) require dirname(__DIR__) . '/config/integrations.php';

foreach ($integrations as $provider => $config) {
    if (array_key_exists('app_available', $config)) {
        line(
            'ok',
            $provider,
            $config['app_available']
                ? 'the companion app is published — pairing is offered'
                : 'waiting for a companion app — pairing is not offered'
        );
        continue;
    }

    line(
        ($config['client_id'] ?? '') === '' ? 'warn' : 'ok',
        $provider,
        ($config['client_id'] ?? '') === '' ? 'no client id configured' : 'client id configured'
    );
}

/* ------------------------------------------------------- google sign-in */

/* Present or absent, and where the redirect goes — never the id or secret. */
require_once dirname(__DIR__) . '/includes/google-signin.php';

$google = google_signin_config();

if ($google['client_id'] === '' && $google['client_secret'] === '') {
    line('warn', 'Google sign-in', 'not configured — the button stays disabled (see config/auth.local.php.example)');
} else {
    foreach (['client_id' => 'client id', 'client_secret' => 'client secret', 'redirect_uri' => 'redirect URI'] as $key => $label) {
        if ($google[$key] === '') {
            line('fail', 'Google sign-in', $label . ' missing');
            $problems++;
        }
    }

    if ($google['redirect_uri'] !== '') {
        $secure = str_starts_with($google['redirect_uri'], 'https://')
            || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $google['redirect_uri']);

        line(
            $secure ? 'ok' : 'fail',
            'Google redirect URI',
            $google['redirect_uri'] . ($secure ? '' : '  — Google only accepts https (or localhost)')
        );

        if (!$secure) {
            $problems++;
        }
    }

    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
        line('fail', 'Google sign-in', 'neither curl nor allow_url_fopen — the server cannot reach Google');
        $problems++;
    }

    if (google_signin_configured()) {
        line('ok', 'Google sign-in', 'configured — the button is offered');
    }
}

if (is_file(dirname(__DIR__) . '/config/auth.local.php')) {
    line('ok', 'auth.local.php', 'present');
}

/* ------------------------------------------------------------- version */

$version = dirname(__DIR__) . '/VERSION';

line(
    is_file($version) ? 'ok' : 'warn',
    'deployed version',
    is_file($version) ? trim((string) file_get_contents($version)) : 'no VERSION file (not a deploy)'
);

echo str_repeat('-', 72) . "\n";

echo $problems === 0
    ? "Nothing essential is missing.\n"
    : $problems . " problem(s) above need fixing.\n";

exit($problems === 0 ? 0 : 1);
