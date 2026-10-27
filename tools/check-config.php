<?php
/**
 * The configuration check, on demand.
 *
 *     php tools/check-config.php
 *     php tools/check-config.php --generate-key
 *     php tools/check-config.php --ai-ping        does the Gemini key work?
 *     php tools/check-config.php --check-schema-rules   before importing migration 027
 *     php tools/check-config.php --send-test=you@example.com   one test message (Release B setup)
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

/* One request to Gemini, shaped as a question in the chat is — the system
   instruction, the nine tool declarations, the same output ceiling and
   thinking level — but with nothing personal in it: does the key work, is
   the model there, does it take what a question sends, and is this project
   on the free tier's quota? Uses one request (two if the model refuses the
   thinking level) of the free quota. */
if (in_array('--ai-ping', $argvFlags, true)) {
    require_once dirname(__DIR__) . '/includes/ai/assistant.php';

    if (ai_api_key() === null) {
        echo "No Gemini API key: set GEMINI_API_KEY or put it in config/ai.local.php (docs/AI.md).\n";
        exit(1);
    }

    echo 'Asking ' . ai_model() . ' with the key from ' . ai_key_source() . " …\n";

    $body = [
        'systemInstruction' => ['parts' => [['text' => ai_system_instruction(
            ['now' => date('Y-m-d H:i'), 'note' => 'A connection check by the people who run Ownify: there is no user and no data.']
        )]]],
        'tools'             => [['functionDeclarations' => ai_tool_declarations()]],
        'toolConfig'        => ['functionCallingConfig' => ['mode' => 'NONE']],
        'generationConfig'  => ai_generation_config(),
        'contents'          => [['role' => 'user', 'parts' => [['text' => 'This is a connection check. Reply with exactly: OK']]]],
    ];

    $answer = ai_gemini_generate($body);

    if (!$answer['ok'] && $answer['outcome'] === 'thinking') {
        echo "The model does not take thinking_level '" . ai_config()['thinking_level'] . "'; asking again without it, as a question would.\n";
        unset($body['generationConfig']['thinkingConfig']);
        $answer = ai_gemini_generate($body);
    }

    echo match ($answer['outcome']) {
        'ok'      => 'It works: Gemini answered "' . mb_substr((string) $answer['text'], 0, 40) . '" using '
            . (int) ($answer['usage']['input_tokens'] ?? 0) . ' + ' . (int) ($answer['usage']['output_tokens'] ?? 0) . " tokens.\n",
        'quota'   => "The key works, but the free quota is used up right now (HTTP 429). Try again later.\n",
        'config'  => "Gemini refused the key or the model — the reason is in the PHP error log. Check GEMINI_API_KEY and GEMINI_MODEL.\n",
        'timeout' => "No answer in time. Check the server's internet connection.\n",
        'invalid' => "Gemini answered, but with nothing to show — the line above says why (an answer cut off at max_output_tokens says so).\n",
        default   => "Gemini could not be reached (" . $answer['outcome'] . "). The PHP error log says more.\n",
    };

    exit($answer['ok'] ? 0 : 1);
}

/* Before migration 027 is imported (docs/ACCOUNT-SECURITY.md §5a): the
   server's version and sql_mode, and the 027 address rule — read verbatim
   from the migration file — tried on TEMPORARY tables (they create no
   database and vanish on disconnect) with the same cases as
   tools/canonical-check.php. Also: the advisory-lock name Release B uses.
   Changes nothing. Import 027 only when this says PASS. */
if (in_array('--check-schema-rules', $argvFlags, true)) {
    require_once dirname(__DIR__) . '/includes/account-security.php';
    require_once __DIR__ . '/sql-source.php';

    $pdo = db();
    if ($pdo === null) {
        echo "No database connection (config/database.php).\n";
        exit(1);
    }

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $mode    = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    echo "Server:   $version\nsql_mode: " . ($mode === '' ? '(empty)' : $mode) . "\n";

    $maria   = stripos($version, 'mariadb') !== false;
    $number  = preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) ? $m[1] : '0.0.0';
    $checks  = $maria ? version_compare($number, '10.2.1', '>=') : version_compare($number, '8.0.16', '>=');
    if (!$checks) {
        echo "FAIL: this server ignores CHECK constraints; the code alone would hold the address rule. Ask before importing 027.\n";
        exit(1);
    }

    $pattern = source_pattern();
    $ok      = !str_contains($pattern, '\\');
    printf("pattern from the migration: '%s' (%s)\n", $pattern, hex_bytes($pattern));

    $clause = source_checks()['chk_pending_email_canonical'];
    $values = ['sanne@example.com' => true, 'Sanne@example.com' => false, "sanne@example.com\n" => false, 'sanne@example.com ' => false,
               ' sanne@example.com' => false, "sanne@exa\0mple.com" => false, 'sänne@example.com' => false, 'sanne+tag@example.com' => true, '' => false];
    foreach (['' => 'default', 'NO_BACKSLASH_ESCAPES' => 'NO_BACKSLASH_ESCAPES'] as $setMode => $label) {
        $pdo->exec("SET SESSION sql_mode = '" . $setMode . "'");
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS ownify_chk_probe');
        $pdo->exec('CREATE TEMPORARY TABLE ownify_chk_probe (`email` VARCHAR(191) NOT NULL, CONSTRAINT `c` CHECK (' . $clause . ')) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        foreach ($values as $value => $accepted) {
            try {
                $pdo->prepare('INSERT INTO ownify_chk_probe (email) VALUES (?)')->execute([$value]);
                $pdo->exec('DELETE FROM ownify_chk_probe');
                $got = true;
            } catch (PDOException $e) {
                $got = false;
            }
            if ($got !== $accepted || $got !== account_email_storable($value)) {
                $ok = false;
                echo "  differs in $label: " . json_encode($value) . ' ' . ($got ? 'accepted' : 'refused') . "\n";
            }
        }
        foreach (['Sanne@Example.COM', "  sanne@example.com\n"] as $typed) {
            try {
                $pdo->prepare('INSERT INTO ownify_chk_probe (email) VALUES (?)')->execute([(string) account_email_canonical($typed)]);
                $pdo->exec('DELETE FROM ownify_chk_probe');
            } catch (PDOException $e) {
                $ok = false;
                echo "  the canonical form of " . json_encode($typed) . " was refused in $label\n";
            }
        }
        $pdo->exec('DROP TEMPORARY TABLE ownify_chk_probe');
        echo "  $label: " . count($values) . " direct inserts compared\n";
    }
    $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($mode));

    $lock = 'ownify:pending:' . substr(hash('sha256', 'sanne@example.com'), 0, 48);
    $held = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn() === 1
        && (int) $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')')->fetchColumn() === 1;
    echo '  advisory lock name (' . strlen($lock) . ' bytes): ' . ($held ? 'accepted' : 'REFUSED') . "\n";
    $ok = $ok && $held;

    echo $ok ? "PASS: migration 027's address rule works on this server.\n" : "FAIL: do not import 027; send this output.\n";
    exit($ok ? 0 : 1);
}

/* One message, sent at once (not through the outbox), to check Gmail works
   from this server: when the App Password is in config/mail.local.php.
   Prints "verstuurd" or the SMTP reply code — never the settings. */
foreach ($argvFlags as $flag) {
    if (str_starts_with($flag, '--send-test=')) {
        require_once dirname(__DIR__) . '/includes/mailer.php';

        $to = account_email_canonical(substr($flag, strlen('--send-test=')));
        if ($to === null) {
            echo "Not an address.\n";
            exit(1);
        }
        foreach (['host', 'username', 'password', 'from'] as $key) {
            if (trim((string) (mail_settings()[$key] ?? '')) === '') {
                echo "config/mail.php: '$key' is not set (config/mail.local.php on this server).\n";
                exit(1);
            }
        }

        $code = mail_smtp_send(
            ['recipient' => $to, 'message_id' => '<' . bin2hex(random_bytes(16)) . '@' . (parse_url((string) (mail_settings()['public_url'] ?? ''), PHP_URL_HOST) ?: 'ownify.invalid') . '>'],
            ['subject' => 'Ownify: testbericht', 'text' => "Dit is een testbericht van Ownify. Je hoeft er niets mee te doen.\n"]
        );

        echo $code === null ? "verstuurd\n" : "niet verstuurd (SMTP $code)\n";
        exit($code === null ? 0 : 1);
    }
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

        /* Not fatal: without it a new account opens on the app as before —
           no setup, no first days — and everybody's focus is Alles. */
        $setupColumns = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_profiles'
                AND column_name IN ('focus', 'setup_state', 'setup_done_at')"
        );

        if ($setupColumns === 3) {
            line('ok', 'first days', 'user_profiles.focus, setup_state and setup_done_at are there');
        } else {
            line('warn', 'first days', 'user_profiles.focus, setup_state or setup_done_at is missing, so a new account '
                . 'gets no setup and no first days, and nobody can choose a focus '
                . '— import database/migrations/016-setup-and-focus.sql');
        }

        /* Not fatal: without it the scores are stored and shown as before,
           but a day without a stored score of its own shows none, rather
           than the last score while it still held. */
        $scoreHistory = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'daily_scores' AND column_name = 'valid_until'"
        );

        if ($scoreHistory > 0) {
            line('ok', 'score history', 'daily_scores.valid_until is there');
        } else {
            line('warn', 'score history', 'daily_scores.valid_until is missing, so a day without its own stored score '
                . 'shows none in the Scorekompas — import database/migrations/017-score-history.sql');
        }

        /* Not fatal: without it the Slaap timeline shows each night's times
           without its stages, and a sync keeps the minutes per stage as before. */
        $sleepStages = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'sleep_stages'"
        );

        if ($sleepStages > 0) {
            line('ok', 'sleep stages', 'sleep_stages is there: the Slaap timeline draws each night stage by stage');
        } else {
            line('warn', 'sleep stages', 'sleep_stages is missing, so the Slaap timeline shows a night\'s times without its stages '
                . '— import database/migrations/018-sleep-stages.sql');
        }

        /* Not fatal: without them Community shows the leaderboard card only,
           and no challenge can be made or joined (docs/CHALLENGES.md). */
        $challengeTables = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name IN ('challenges', 'challenge_members')"
        );

        if ($challengeTables === 2) {
            line('ok', 'challenges', 'challenges and challenge_members are there: Community has Uitdagingen');
        } else {
            line('warn', 'challenges', 'the challenge tables are missing, so Community shows the leaderboard only '
                . '— import database/migrations/022-challenges.sql');
        }

        /* Not fatal: without it the week starts on Monday, Toegankelijkheid's
           own switches cannot be kept and phones keep their own names. */
        require_once dirname(__DIR__) . '/includes/devices.php';
        if (preferences_columns_present() && device_names_present()) {
            line('ok', 'voorkeuren', 'the week\'s first day, Toegankelijkheid and device names can be kept');
        } else {
            line('warn', 'voorkeuren', 'the week\'s first day, Toegankelijkheid and device names cannot be kept '
                . '— import database/migrations/024-preferences-and-device-names.sql');
        }

        /* Not fatal: VO2max is no longer read, shown or scored (18.6); the
           catalogue row only keeps it as a source the goal wizard offers. */
        if (db_value("SELECT COUNT(*) FROM health_metric_types WHERE code = 'vo2max'") > 0) {
            line('warn', 'VO2max', 'VO2max is still in the metric catalogue, so the goal wizard still offers it '
                . '— import database/migrations/023-remove-vo2max.sql');
        } else {
            line('ok', 'VO2max', 'VO2max is out of the metric catalogue');
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

require_once dirname(__DIR__) . '/includes/integrations.php';
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

    /* The example file's placeholders are not a client id (integration_placeholder()). */
    $unset = integration_placeholder((string) ($config['client_id'] ?? ''));
    line($unset ? 'warn' : 'ok', $provider, $unset ? 'no client id configured' : 'client id configured');
}

/* ---------------------------------------------------------------- polar */

/* Where the id and secret come from and where Polar sends people back — never
   the values themselves. docs/POLAR.md. */
require_once dirname(__DIR__) . '/includes/integrations.php';

$polar       = polar_config();
$polarSource = static function (string $variable, string $key) use ($polar): string {
    $env = getenv($variable);
    if ($env !== false && $env !== '') {
        return 'from the environment (' . $variable . ')';
    }
    return $polar[$key] === '' ? 'missing' : 'from config/integrations.local.php';
};

if (!polar_credentials_present()) {
    line('warn', 'Polar', 'not configured — set POLAR_CLIENT_ID and POLAR_CLIENT_SECRET (docs/POLAR.md); the button stays disabled');
} else {
    line('ok', 'Polar', 'client id ' . $polarSource('POLAR_CLIENT_ID', 'client_id') . ', secret ' . $polarSource('POLAR_CLIENT_SECRET', 'client_secret'));
}

line($polar['redirect_uri'] === '' ? 'fail' : 'ok', 'Polar redirect', $polar['redirect_uri'] === '' ? 'missing' : $polar['redirect_uri']);

if (db_available()) {
    line(polar_stored() ? 'ok' : 'warn', 'Polar tables', polar_stored() ? 'migration 020 imported' : 'migration 020 not imported yet');
}

/* Tokens are sealed with the app key before they are stored; without a usable
   one, connecting is refused ("De server kan tokens nog niet veilig opslaan."). */
$polarKey = crypto_status();
line($polarKey['state'] === 'ok' ? 'ok' : 'fail', 'Polar tokens',
    $polarKey['state'] === 'ok' ? 'can be stored encrypted (' . CRYPTO_KEY_VARIABLE . ' from ' . $polarKey['source'] . ')'
        : 'cannot be stored: ' . $polarKey['message']);
if ($polarKey['state'] !== 'ok') {
    $problems++;
}

line(function_exists('curl_init') ? 'ok' : 'fail', 'Polar HTTP', function_exists('curl_init') ? 'curl available' : 'the curl extension is missing');

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

/* ----------------------------------------------------- account security */

require_once dirname(__DIR__) . '/includes/account-security.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

if (db_available()) {
    $schema = account_security_schema();
    $have   = array_keys(array_filter(array_diff_key($schema, ['complete' => true])));
    if ($schema['complete']) {
        line('ok', 'migration 027', 'imported: verified addresses, session numbers, outbox');
    } elseif ($have === []) {
        line('warn', 'migration 027', 'not imported yet — password change is off until it is (php tools/check-config.php --check-schema-rules first)');
    } else {
        line('warn', 'migration 027', 'only partly there (' . implode(', ', $have) . ') — import it again; it adds what is missing');
    }
}

/* PHPMailer as vendored (D5): every file as its VERSION file lists it. */
$vendor  = dirname(__DIR__) . '/includes/vendor/phpmailer/';
$listed  = is_file($vendor . 'VERSION') ? (string) file_get_contents($vendor . 'VERSION') : '';
$changed = [];
preg_match_all('/^\s+([0-9a-f]{64})\s+(\S+)$/m', $listed, $sums, PREG_SET_ORDER);
foreach ($sums as [, $sum, $file]) {
    if (!is_file($vendor . $file) || hash_file('sha256', $vendor . $file) !== $sum) {
        $changed[] = $file;
    }
}
if ($sums === []) {
    line('fail', 'PHPMailer', 'includes/vendor/phpmailer/VERSION is missing');
    $problems++;
} elseif ($changed !== []) {
    line('fail', 'PHPMailer', 'differs from v7.1.1 as vendored: ' . implode(', ', $changed));
    $problems++;
} else {
    line('ok', 'PHPMailer', 'v7.1.1, ' . count($sums) . ' files as vendored');
}

/* Mail: off is fine in Release A. Never the password. */
$mailReason = mail_unavailable_reason();
line($mailReason === null ? 'ok' : 'warn', 'mail', $mailReason === null
    ? 'configured (' . (mail_settings()['host'] ?? '') . ':' . (mail_settings()['port'] ?? '') . ', from ' . (mail_settings()['from'] ?? '') . ')'
    : 'not configured — nothing is sent (' . $mailReason . ')');
line('ok', 'e-mail registration', account_email_registration_open() ? 'OPEN (test server only)' : 'closed (Release A)');

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
