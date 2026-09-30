<?php
/**
 * The configuration check, on demand.
 *
 *     php tools/check-config.php
 *     php tools/check-config.php --generate-key
 *     php tools/check-config.php --ai-ping        does the Gemini key work?
 *
 * Answers the questions you cannot answer by looking at the site: is the
 * database reachable with the credentials this checkout will actually use, and
 * is OWNIFY_APP_KEY set — and if it is set, where did it come from and is it the
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
require_once dirname(__DIR__) . '/includes/user.php';

$argvFlags = array_slice($argv ?? [], 1);

if (in_array('--generate-key', $argvFlags, true)) {
    $key = crypto_generate_key();

    echo "A fresh " . CRYPTO_KEY_VARIABLE . ". Put it in config/app.local.php as 'app_key',\n";
    echo "or in the environment. It is shown once — this tool does not store it.\n\n";
    echo $key . "\n\n";
    echo "Changing an existing key makes every token already encrypted with the\n";
    echo "old one unreadable, and everyone affected has to reconnect.\n";
    exit(0);
}

/* One request to Gemini, with nothing personal in it: does the key work, is
   the model there, and is this project on the free tier's quota? Uses one
   request of the free quota. */
if (in_array('--ai-ping', $argvFlags, true)) {
    require_once dirname(__DIR__) . '/includes/ai/gemini.php';

    if (ai_api_key() === null) {
        echo "No Gemini API key: set GEMINI_API_KEY or put it in config/ai.local.php (docs/AI.md).\n";
        exit(1);
    }

    echo 'Asking ' . ai_model() . ' with the key from ' . ai_key_source() . " …\n";

    $answer = ai_gemini_generate([
        'contents'         => [['role' => 'user', 'parts' => [['text' => 'Reply with exactly: OK']]]],
        'generationConfig' => ['maxOutputTokens' => 256],
    ]);

    echo match ($answer['outcome']) {
        'ok'      => 'It works: Gemini answered "' . mb_substr((string) $answer['text'], 0, 40) . '" using '
            . (int) ($answer['usage']['input_tokens'] ?? 0) . ' + ' . (int) ($answer['usage']['output_tokens'] ?? 0) . " tokens.\n",
        'quota'   => "The key works, but the free quota is used up right now (HTTP 429). Try again later.\n",
        'config'  => "Gemini refused the key or the model — the reason is in the PHP error log. Check GEMINI_API_KEY and GEMINI_MODEL.\n",
        'timeout' => "No answer in time. Check the server's internet connection.\n",
        default   => "Gemini could not be reached (" . $answer['outcome'] . "). The PHP error log says more.\n",
    };

    exit($answer['ok'] ? 0 : 1);
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

echo "Ownify configuration check\n";
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

        /* Not fatal: without it every picture shows on the boards, but
           "Profielfoto op de ranglijst" cannot be switched off. */
        $boardAvatar = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles' AND column_name = 'leaderboard_avatar'"
        );

        if ($boardAvatar > 0) {
            line('ok', 'board pictures', 'user_profiles.leaderboard_avatar is there');
        } else {
            line('warn', 'board pictures', 'user_profiles.leaderboard_avatar is missing, so "Profielfoto op de ranglijst" '
                . 'cannot be switched off — import database/migrations/014-leaderboard-avatar-setting.sql');
        }

        /* Not fatal: without GD the boards and friends lists show each
           picture as uploaded (up to 3 MB) instead of a small copy. */
        if (!function_exists('imagecreatetruecolor')) {
            line('warn', 'small pictures', 'PHP has no GD, so the boards show profile pictures at full size — enable the gd extension');
        } else {
            line('ok', 'small pictures', 'GD is there; small copies are written as ' . (str_ends_with(avatar_small_path('x.png'), '.webp') ? 'WebP' : 'JPEG'));
        }

        /* Not fatal: Ownify AI says it is not available, nothing else changes.
           The key is named by where it was found, never shown. */
        require_once dirname(__DIR__) . '/includes/ai/config.php';
        if (!ai_installed()) {
            line('warn', 'ownify ai', 'its tables are missing, so the assistant says it is not available '
                . '— import database/migrations/015-ai-assistant.sql');
        } elseif (ai_api_key() === null) {
            line('warn', 'ownify ai', 'no GEMINI_API_KEY, so the assistant says it is not available — see docs/AI.md');
        } elseif (ai_model() === null) {
            line('fail', 'ownify ai', 'GEMINI_MODEL is not a model name');
        } else {
            line('ok', 'ownify ai', 'key from ' . ai_key_source() . ', model ' . ai_model()
                . ', ' . (int) ai_config()['daily_limit'] . ' messages a day each, '
                . (int) ai_config()['global_daily_limit'] . ' Gemini requests a day in total');
        }

        /* Not fatal: without it a day's steps, distance and calories are the
           plain sum of every record, so a walk the phone and a watch both
           counted is counted twice — as it always was. */
        $intervals = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'health_metrics'
                AND column_name IN ('started_at', 'data_origin')"
        ) + (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'health_metric_day_totals'"
        );

        if ($intervals === 3) {
            line('ok', 'activity totals', 'health_metrics.started_at/data_origin and health_metric_day_totals are there');
        } else {
            line('warn', 'activity totals', 'migration 012 is not (fully) in, so steps from two apps are added up — '
                . 'import database/migrations/012-metric-intervals.sql');
        }

        /* Not fatal: without it paired phones sync as they always did, but
           the app cannot sign in as an account and failed sign-ins are not
           counted, so password guessing is not slowed down. */
        $appTokens = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_devices' AND column_name = 'scope'"
        ) + (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'auth_attempts'"
        );

        if ($appTokens === 2) {
            line('ok', 'app sign-in', 'user_devices.scope and auth_attempts are there');
        } else {
            line('warn', 'app sign-in', 'migration 013 is not (fully) in, so the app cannot sign in as an account and '
                . 'failed sign-ins are not limited — import database/migrations/013-app-tokens.sql');
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
        line('ok', CRYPTO_KEY_VARIABLE, 'set and usable, from ' . $key['source']);
        /* Still under the name from before Ownify: it works, and the same
           value only needs to move to the new name. */
        if (str_contains((string) $key['source'], CRYPTO_KEY_VARIABLE_LEGACY)) {
            line('warn', CRYPTO_KEY_VARIABLE_LEGACY, 'the old name of ' . CRYPTO_KEY_VARIABLE
                . ' — rename the variable, keeping its value exactly as it is');
        }
        break;

    case 'missing':
        /* Not fatal. Nothing in the app stores an encrypted token yet, so this
           is a warning about the next feature rather than a broken one. */
        line('warn', CRYPTO_KEY_VARIABLE, 'not set — see config/app.local.php.example');
        break;

    default:
        line('fail', CRYPTO_KEY_VARIABLE, $key['message']);
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

/* A config file that does not load switches Google off on the site and in the
   app (google_signin_settings()); here it is a failure, with where to look. */
$googleBroken = function_exists('google_signin_config_error') ? google_signin_config_error() : null;

if ($googleBroken !== null) {
    line('fail', 'Google sign-in', 'config/auth.php could not be loaded, so Google is off — ' . $googleBroken);
    $problems++;
} elseif ($google['client_id'] === '' && $google['client_secret'] === '') {
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

/* The Ownify app's own Google clients (api/auth/app-google.php): how many, and
   whether they can work with the Web client above — never the ids. The app
   asks Google for a token for the Web client, and Google gives one only to an
   Android client of the same Google Cloud project: the number in front. */
$androidClients = $google['android_client_ids'];

if ($googleBroken !== null) {
    /* Said above: nothing about Google was read. */
} elseif ($androidClients === []) {
    line('warn', 'Google in the app', 'no Android client ids — the app offers no Google sign-in (docs/APP-AUTH.md)');
} else {
    $project     = static fn (string $id): string => (string) strstr($id, '-', true);
    $appProblems = [];

    if ($google['client_id'] === '') {
        $appProblems[] = 'the Web client id is missing — the app\'s tokens are made out to it';
    }

    foreach ($androidClients as $id) {
        if (!preg_match('/^\d+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $id)) {
            $appProblems[] = 'an Android client id is not a client id (…apps.googleusercontent.com)';
        } elseif ($id === $google['client_id']) {
            $appProblems[] = 'an Android client id is the Web client\'s own — create a client of type Android';
        } elseif ($google['client_id'] !== '' && $project($id) !== $project($google['client_id'])) {
            $appProblems[] = 'an Android client is in another Google Cloud project than the Web client';
        }
    }

    foreach (array_unique($appProblems) as $appProblem) {
        line('fail', 'Google in the app', $appProblem);
        $problems++;
    }

    if ($appProblems === []) {
        $count = count($androidClients);
        line('ok', 'Google in the app', $count . ' Android client' . ($count === 1 ? '' : 's') . ' — the app offers Google sign-in');
    }
}

if ($googleBroken === null && is_file(dirname(__DIR__) . '/config/auth.local.php')) {
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
