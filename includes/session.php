<?php
/**
 * Server-side session state.
 *
 * The browser receives a session cookie and, once signed in, a second cookie
 * that keeps it signed in after the session is gone
 * (includes/persistent-login.php). Neither carries the user id: that lives on
 * the server and is never sent to the client, so no request can claim to be
 * another user by editing something.
 */

declare(strict_types=1);

if (!function_exists('session_boot')) {

    function session_boot(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,          // not readable from JavaScript
            'samesite' => 'Lax',         // not sent on cross-site POSTs
            'secure'   => $https,        // https only once there is https
        ]);

        session_name('jolu_session');
        session_start();
    }

    function current_user_id(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    function session_login(int $userId): void
    {
        session_boot();
        // New id on privilege change, so a fixated session cannot be reused.
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['logged_in_at'] = time();

        /* And stays signed in, past the end of this session, until signing
           out. Guarded because a deploy lands one file at a time: this file
           may be new while the one defining it is not yet there. */
        if (function_exists('persistent_login_issue')) {
            persistent_login_issue($userId);
        }
    }

    function session_logout(): void
    {
        session_boot();

        /* Signing out is for good: the sign-in that outlives the session goes
           with it, row and cookie. Guarded for the same reason as above. */
        if (function_exists('persistent_login_revoke')) {
            persistent_login_revoke();
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
        }

        session_destroy();
    }

    /* ------------------------------------------------------------- CSRF */

    function csrf_token(): string
    {
        session_boot();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    function csrf_check(?string $token): bool
    {
        session_boot();
        $expected = $_SESSION['csrf_token'] ?? '';

        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }
}
