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

        return $userId;
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
