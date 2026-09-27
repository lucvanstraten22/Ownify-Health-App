<?php
/**
 * Shared plumbing for the JSON endpoints.
 *
 * Every endpoint is POST, CSRF-checked, and — where it touches user data —
 * scoped to the id in the SESSION. No endpoint accepts a user id from the
 * request, which is what stops one account asking for another's records.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

/**
 * Anything that gets this far is a bug, and it must not leave as HTML.
 *
 * Without this an uncaught exception returns the server's error page, which on
 * a host with display_errors on carries the statement that failed and the
 * connection behind it. The caller gets one sentence and a 500; the detail
 * goes to the error log, where it belongs.
 */
if (!function_exists('api_handle_throwable')) {

    function api_handle_throwable(Throwable $e): never
    {
        error_log(sprintf(
            '[api] %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo json_encode(
            ['ok' => false, 'error' => 'Er ging iets mis op de server. Probeer het opnieuw.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    set_exception_handler('api_handle_throwable');

    /* A fatal that is not an exception — running out of memory, a timeout —
       never reaches the handler above, so the shutdown is checked too. */
    register_shutdown_function(static function (): void {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(
                ['ok' => false, 'error' => 'Er ging iets mis op de server. Probeer het opnieuw.'],
                JSON_UNESCAPED_UNICODE
            );
        }
    });
}

if (!function_exists('api_json')) {

    function api_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    function api_ok(array $payload = []): never
    {
        api_json(['ok' => true] + $payload);
    }

    function api_fail(string $message, int $status = 400): never
    {
        api_json(['ok' => false, 'error' => $message], $status);
    }

    function api_require_post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            api_fail('Alleen POST is toegestaan.', 405);
        }
    }

    function api_require_csrf(): void
    {
        if (!csrf_check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            api_fail('De sessie is verlopen. Laad de pagina opnieuw.', 419);
        }
    }

    function api_require_database(): void
    {
        if (!db_available()) {
            api_fail('Geen databaseverbinding. Importeer database/schema.sql en controleer config/database.php.', 503);
        }
    }

    /** The authenticated user id, from the session and nowhere else. */
    function api_require_user(): int
    {
        $userId = current_user_id();

        if ($userId === null) {
            api_fail('Je bent niet ingelogd.', 401);
        }

        /* A session can outlive its account: deleted from another phone, the
           session here still names it. It is signed out rather than left to
           write rows for somebody who no longer exists. */
        if (db_available()
            && db_value('SELECT id FROM users WHERE id = ? AND status <> ?', [$userId, 'deleted']) === null) {
            session_logout();
            api_fail('Je bent niet ingelogd.', 401);
        }

        return $userId;
    }

    /**
     * The authenticated user id for an endpoint that acts as the account —
     * for the website AND for the JoLu app.
     *
     *   Authorization: Bearer <token>
     *       The app. The token must be an ACCOUNT token (issued by signing in
     *       in the app), working, of an active account. A sync token from a
     *       pairing code is refused with 403: it may upload records, not act
     *       as the account. Unknown, revoked and lapsed tokens are 401. A
     *       request that carries a bearer token is decided by it alone and
     *       never falls back to a session cookie.
     *
     *       No CSRF token: a browser never adds an Authorization header by
     *       itself, and another site cannot make it add one (that takes a
     *       CORS preflight this server does not answer), so a forged request
     *       has no token to carry.
     *
     *   no bearer token
     *       The website, exactly as api_require_csrf() + api_require_user():
     *       the form's CSRF token (419 when wrong), then the session's user
     *       (401 when there is none, or its account is gone).
     *
     * Nothing about the user ever comes from the request body.
     */
    function api_require_account_user(): int
    {
        $token = api_bearer_token();

        if ($token === null) {
            api_require_csrf();

            return api_require_user();
        }

        require_once dirname(__DIR__) . '/includes/devices.php';

        $device = db_available() ? device_authenticate($token) : null;

        if ($device === null) {
            api_fail('Je bent niet ingelogd.', 401);
        }

        if ($device['scope'] !== 'account') {
            api_fail('Dit apparaat is alleen gekoppeld om gegevens te synchroniseren. Log in de app in met je account.', 403);
        }

        /* An account that was suspended keeps its rows; it does not keep
           acting through them. (A deleted one has no rows left at all.) */
        if (db_value('SELECT id FROM users WHERE id = ? AND status = ?', [$device['user_id'], 'active']) === null) {
            api_fail('Je bent niet ingelogd.', 401);
        }

        return $device['user_id'];
    }

    /**
     * HTTPS, for the endpoints that take a password or hand out a token.
     *
     * The same test session_boot() uses for the Secure cookie flag — which on
     * the live server is right, behind its proxy. Only this machine itself may
     * use plain http, for a development server on localhost.
     */
    function api_require_https(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        if (!$https && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
            api_fail('Gebruik een beveiligde verbinding (https).', 403);
        }
    }

    /**
     * The app has proved who it is for — a password, a new account, Google —
     * and gets its account token. The one answer every way of signing in in
     * the app ends with:
     *
     *   { ok, token, scope: "account", provider: "password"|"google",
     *     account: { username, avatar, created_at, age } }
     *
     * The token appears here once and never again: only its hash is stored.
     * No user id, no e-mail address, nothing about health. A token the app
     * already holds (Authorization header) for this same account is replaced
     * on the same phone row — see device_issue_account_token().
     *
     * @param array<string, mixed> $input  the request body: label, platform, app_version
     * @param array<string, mixed> $extra  more fields for the answer, e.g. Google's status
     */
    function api_app_signed_in(int $userId, string $method, array $input, array $extra = []): never
    {
        require_once dirname(__DIR__) . '/includes/devices.php';

        $issued = device_issue_account_token($userId, [
            'label'       => $input['label'] ?? null,
            'platform'    => $input['platform'] ?? null,
            'app_version' => $input['app_version'] ?? null,
        ], api_bearer_token());

        if (!$issued['ok']) {
            api_fail((string) $issued['error'], (int) ($issued['status'] ?? 500));
        }

        auth_touch_last_seen($userId);

        api_ok($extra + [
            'token'    => $issued['token'],
            'scope'    => 'account',
            'provider' => $method,
        ] + api_account_payload($userId));
    }

    /**
     * The request body as an array, whether it arrived as JSON or as a form.
     *
     * The phone app speaks JSON; curl and a browser form speak the other. Both
     * work, so the contract can be tried by hand before any app exists.
     */
    function api_json_body(): array
    {
        if ($_POST !== []) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The bearer token, or null.
     *
     * Some Apache and FastCGI setups drop the Authorization header before PHP
     * sees it, so the redirected copy is checked too — a sync that silently
     * never authenticates is a miserable thing to debug.
     */
    function api_bearer_token(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($header === null && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if (!is_string($header) || !preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }

        return $m[1];
    }

    /** The account summary the panel renders. Owner-only fields. */
    function api_account_payload(int $userId): array
    {
        $account = user_account($userId);

        if ($account === null) {
            return [];
        }

        return [
            'account' => [
                'username'   => $account['username'],
                'avatar'     => $account['avatar_path'],
                'created_at' => $account['created_at'],
                'age'        => $account['age'],
            ],
        ];
    }
}
