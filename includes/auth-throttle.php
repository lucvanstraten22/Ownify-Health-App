<?php
/**
 * Slowing down password guessing.
 *
 * ---------------------------------------------------------------------------
 * THE RULE
 * ---------------------------------------------------------------------------
 * A name or e-mail address, typed from one network address, gets
 * AUTH_THROTTLE_LIMIT wrong passwords per AUTH_THROTTLE_WINDOW. After that,
 * signing in with that name from that address is refused — without looking at
 * the password — until the oldest of those failures is older than the window.
 * A correct password clears the count.
 *
 *   - Nothing is ever locked for good: the window slides, and waiting is all
 *     it takes.
 *   - The key is the pair. Somebody guessing at your account from their own
 *     connection does not stop you signing in from yours, and a mistyped
 *     password on one account does not touch another.
 *   - It says nothing about accounts. A name that has no account is counted
 *     and refused exactly like one that does, with the same message, so the
 *     limit cannot be used to find out which names exist.
 *
 * The same counter, under its own name, limits failed registrations in the
 * app (api/auth/app-register.php): one e-mail address from one network
 * address, a few tries per window.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS STORED
 * ---------------------------------------------------------------------------
 * One row per failure in auth_attempts: what it was (login, register), when,
 * and a SHA-256 of the typed name together with the address. Neither is kept
 * as text, and rows older than a day are deleted as new ones arrive.
 *
 * The address is REMOTE_ADDR and nothing else. A forwarded-for header is
 * whatever the caller wants it to be, so believing one would let anybody pick
 * a fresh address for every guess.
 *
 * Before migration 013 there is no table, and nothing here does anything:
 * signing in works exactly as it did.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Wrong passwords allowed per name and address, per window. */
if (!defined('AUTH_THROTTLE_LIMIT')) {
    define('AUTH_THROTTLE_LIMIT', 5);
}

/** The window, in seconds: a quarter of an hour. */
if (!defined('AUTH_THROTTLE_WINDOW')) {
    define('AUTH_THROTTLE_WINDOW', 900);
}

if (!function_exists('auth_throttle_available')) {

    /** Whether migration 013's table is there. Asked once per request. */
    function auth_throttle_available(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        if (!db_available()) {
            return $available = false;
        }

        return $available = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'auth_attempts'"
        ) === 1;
    }

    /** The key for one name from one address. The name as the lookup treats it: case and spaces do not count. */
    function auth_throttle_key(string $action, string $identifier): string
    {
        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return hash('sha256', $action . "\n" . mb_strtolower(trim($identifier)) . "\n" . $address);
    }

    /**
     * Seconds until this name may be tried again from here, or null when it
     * may be tried now.
     */
    function auth_throttle_wait(string $action, string $identifier): ?int
    {
        if (!auth_throttle_available()) {
            return null;
        }

        /* The failure that has to leave the window before there is room for
           one more: the LIMIT-th newest. Until it does, the count is full. */
        $wait = db_value(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL ? SECOND)
               FROM auth_attempts
              WHERE action = ? AND key_hash = ? AND attempted_at > NOW() - INTERVAL ? SECOND
              ORDER BY attempted_at DESC, id DESC
              LIMIT 1 OFFSET ' . (AUTH_THROTTLE_LIMIT - 1),
            [AUTH_THROTTLE_WINDOW, $action, auth_throttle_key($action, $identifier), AUTH_THROTTLE_WINDOW]
        );

        return $wait === null ? null : max(1, (int) $wait);
    }

    /** Counts one failure. */
    function auth_throttle_fail(string $action, string $identifier): void
    {
        if (!auth_throttle_available()) {
            return;
        }

        db_run(
            'INSERT INTO auth_attempts (action, key_hash) VALUES (?, ?)',
            [$action, auth_throttle_key($action, $identifier)]
        );

        /* Housekeeping on the one occasion somebody is already writing here,
           as with pairing codes: one indexed DELETE, and no cron job to forget. */
        db_run('DELETE FROM auth_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    }

    /** A success: the failures before it no longer count. */
    function auth_throttle_clear(string $action, string $identifier): void
    {
        if (!auth_throttle_available()) {
            return;
        }

        db_run(
            'DELETE FROM auth_attempts WHERE action = ? AND key_hash = ?',
            [$action, auth_throttle_key($action, $identifier)]
        );
    }

    /** What somebody who has to wait is told. The same for every name, known or not. */
    function auth_throttle_message(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return sprintf(
            'Te veel mislukte pogingen. Probeer het over %d %s opnieuw.',
            $minutes,
            $minutes === 1 ? 'minuut' : 'minuten'
        );
    }

    /**
     * Signing in with a password, counted. What the website's login and the
     * app's both call.
     *
     * The password is checked by auth_login_password() — the one place that
     * does it — and only when the name is not waiting out its limit. Only a
     * wrong name or password counts as a failure; a database that is down or
     * an account that is not active is not a guess.
     *
     * @return array{ok: bool, error: ?string, user_id?: int, retry_after?: int}
     *         retry_after is set when the answer is "wait", in seconds
     */
    function auth_login_attempt(string $identifier, string $password): array
    {
        $wait = auth_throttle_wait('login', $identifier);

        if ($wait !== null) {
            return ['ok' => false, 'error' => auth_throttle_message($wait), 'retry_after' => $wait];
        }

        $result = auth_login_password($identifier, $password);

        if ($result['ok']) {
            auth_throttle_clear('login', $identifier);
        } elseif (!empty($result['credentials'])) {
            auth_throttle_fail('login', $identifier);
        }

        unset($result['credentials']);

        return $result;
    }
}
