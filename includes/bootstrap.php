<?php
/**
 * Request bootstrap: session, database, and who is signed in.
 *
 * Included once from index.php and from every API endpoint. If the database
 * is unreachable the app still renders — signed out, on placeholder data —
 * rather than failing, so a fresh checkout works before schema.sql is imported.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/user.php';
require_once __DIR__ . '/crypto.php';

session_boot();

/* ---------------------------------------------------------------------------
 * WHEN A PAGE DIES, SAY SO SOMEWHERE
 * ---------------------------------------------------------------------------
 * The JSON endpoints have handled this since bb0d29a: an uncaught throwable
 * leaves as one sentence and a 500, with the detail in the error log. A PAGE
 * had nothing. display_errors is off on any sane server, so a fatal in
 * index.php or anything it pulls in arrives at the browser as a blank 500 —
 * no message, no file, no line, and nothing written down unless PHP's own
 * logging happens to be configured the way you hoped.
 *
 * That is precisely how this server spent an afternoon offline with no clue
 * as to why. So: log it properly, always, with the URL that caused it, and
 * show the visitor a plain page rather than a blank one.
 *
 * api/bootstrap.php installs its own exception handler AFTER requiring this
 * file, so an endpoint still answers JSON. This is the page's fallback.
 * ------------------------------------------------------------------------ */
if (!function_exists('app_log_fatal')) {

    function app_log_fatal(string $kind, string $message, string $file, int $line): void
    {
        error_log(sprintf(
            '[jolu] %s: %s in %s:%d  (request: %s %s)',
            $kind,
            $message,
            $file,
            $line,
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $_SERVER['REQUEST_URI'] ?? '-'
        ));
    }

    set_exception_handler(static function (Throwable $e): void {
        app_log_fatal($e::class, $e->getMessage(), $e->getFile(), $e->getLine());

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }

        /* Nothing about the failure reaches the visitor — a message here is a
           message to whoever is probing the site. It goes in the log. */
        echo '<!doctype html><meta charset="utf-8"><title>Even niet beschikbaar</title>'
            . '<p style="font:16px/1.5 system-ui;margin:3rem auto;max-width:28rem;text-align:center">'
            . 'Er ging iets mis op de server. Probeer het zo opnieuw.</p>';
    });

    /* A fatal that is not a throwable — memory, a timeout, a parse error in an
       included file — never reaches the handler above. */
    register_shutdown_function(static function (): void {
        $fatal = error_get_last();

        if ($fatal === null
            || !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        app_log_fatal('FATAL', $fatal['message'], $fatal['file'], (int) $fatal['line']);
    });
}


/* The configuration check, on the way in.
 *
 * A missing JOLU_APP_KEY does not stop the app: signing in, the health data
 * and the Health Connect pairing flow all work without it, because none of
 * them stores an encrypted token. It stops the things that do — and it does so
 * silently unless somebody is told, which is what this writes to the server
 * log, once per process. `php tools/check-config.php` asks the same question
 * on demand.
 *
 * Nothing about the key reaches the page: the browser is told, at most, that
 * a source cannot be connected yet.
 *
 * GUARDED, AND THAT IS NOT PARANOIA
 * ---------------------------------
 * A deploy copies files one at a time, and a PHP opcode cache can go on
 * serving an old one after a new one lands. So there is a window in which this
 * file is the new version and crypto.php is still the old one — and an
 * unguarded call to a function added in the same commit is then a fatal on
 * every page of the site, which is exactly what happened on 18 September.
 *
 * A check whose whole job is to report a misconfiguration must never be able
 * to become one. If the function is not there, that is itself worth saying,
 * and the site keeps serving while somebody reads it. */
if (function_exists('crypto_check')) {
    crypto_check();
} else {
    error_log(
        '[jolu] configuration: includes/crypto.php is out of date on this server — '
        . 'crypto_check() is missing, so the startup check is being skipped. The site '
        . 'is serving normally. Clear the PHP opcode cache or re-deploy to fix it.'
    );
}

if (!function_exists('app_auth')) {

    /**
     * The authentication state for this request.
     * `user` is the account's OWN record — it is only ever rendered for the
     * person it belongs to.
     */
    function app_auth(): array
    {
        static $state = null;

        if ($state !== null) {
            return $state;
        }

        $user = null;
        $userId = current_user_id();

        if ($userId !== null && db_available()) {
            $user = user_account($userId);

            if ($user === null) {
                /* The account is gone — deleted, from here or from another
                   phone — so the session is stale. A new one, with a cookie
                   of its own, so the page this renders can act straight
                   away instead of failing once on a token for a session
                   the browser has just been told to drop. */
                session_logout();
                session_boot();
                session_regenerate_id(true);
            } else {
                auth_touch_last_seen($userId);
            }
        }

        /* Somebody Google has just vouched for who has no account yet: the
           panel asks them for a username. Only the address is passed on —
           the Google id stays in the session. */
        $pending = null;
        if ($user === null && auth_provider_available('google')) {
            $waiting = google_signin_pending();
            $pending = $waiting === null ? null : [
                'email'   => $waiting['email'],
                'minutes' => max(1, (int) ceil(($waiting['expires'] - time()) / 60)),
            ];
        }

        $state = [
            'signed_in' => $user !== null,
            'user'      => $user,
            'database'  => db_available(),
            'csrf'      => csrf_token(),
            'providers' => [
                'email'  => auth_provider_available('email'),
                'apple'  => auth_provider_available('apple'),
                'google' => auth_provider_available('google'),
            ],
            'google_pending' => $pending,
            'identities'     => $user === null ? [] : auth_identities_for_user((int) $user['id']),
            'flash'          => auth_flash_take(),
        ];

        return $state;
    }

    /** Uploaded files live outside the code directories. */
    function app_upload_root(): string
    {
        return dirname(__DIR__) . '/uploads';
    }
}
