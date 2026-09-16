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

session_boot();

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
                // The account is gone or suspended: the session is stale.
                session_logout();
                session_boot();
            } else {
                auth_touch_last_seen($userId);
            }
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
        ];

        return $state;
    }

    /** Uploaded files live outside the code directories. */
    function app_upload_root(): string
    {
        return dirname(__DIR__) . '/uploads';
    }
}
