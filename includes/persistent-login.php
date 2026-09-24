<?php
/**
 * Staying signed in.
 *
 * ---------------------------------------------------------------------------
 * WHY THE SESSION ALONE WAS NOT ENOUGH
 * ---------------------------------------------------------------------------
 * Signing in used to last exactly as long as the PHP session, and the PHP
 * session is not built to last. Its cookie has no expiry, so the browser drops
 * it when it closes. Its file on the server is thrown away after a short idle
 * spell — session.gc_maxlifetime, 24 minutes unless somebody changed it, plus
 * whatever clean-up the host runs, which the app does not control. Either one
 * sent somebody who had signed in back to the opening screen: closing the
 * browser, or putting the phone down for half an hour.
 *
 * So a sign-in now has a second half that does last: a row in
 * user_login_tokens, and a cookie on the browser naming it. The session is
 * still what every request runs on. When it is gone, the cookie puts it back,
 * before anything else looks — so the opening screen is only ever for
 * somebody who is signed out.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE COOKIE HOLDS, AND WHAT THE DATABASE HOLDS
 * ---------------------------------------------------------------------------
 * `selector.validator`, both random. The selector finds the row and proves
 * nothing. The validator is 256 bits the server made up, and the database has
 * only its SHA-256 — the way paired phones' tokens are kept, for the same
 * reason: a dump of the table signs nobody in. SHA-256 rather than
 * password_hash() because there is no dictionary to walk; the comparison is
 * constant-time all the same.
 *
 * The cookie is HttpOnly, so no script on the page can read it — the whole
 * difference from keeping something in localStorage. It is Secure on https,
 * and there it is also __Host- prefixed, so nothing else on the domain can set
 * or overwrite it. SameSite=Lax, like the session's. It lasts a year from the
 * last time it was used.
 *
 * ---------------------------------------------------------------------------
 * ROTATION, AND WHY IT NEVER SIGNS ANYBODY OUT BY MISTAKE
 * ---------------------------------------------------------------------------
 * Every time the cookie puts a session back, it gets a new validator. A copy
 * taken earlier then stops working, and a copy that turns up after the real
 * browser has moved on can only be a copy: that sign-in is revoked outright,
 * for whoever holds it.
 *
 * Browsers make that harder than it sounds. A browser reopening restores all
 * its tabs at once, each with the same cookie; a response can be lost on the
 * way back after the server has already rotated. Neither may sign anybody
 * out — signing people out for nothing is the very bug this file is for. So:
 *
 *   - the validator before the current one keeps working until the browser
 *     has shown it got the new one, which the next request carrying it does;
 *   - for a few minutes after a rotation, the one before restores without
 *     rotating again, so tabs racing each other all end up on the same cookie;
 *   - after that it rotates once more, which is how a lost response heals.
 *
 * ---------------------------------------------------------------------------
 * THE FORM TOKEN BELONGS TO THE SIGN-IN
 * ---------------------------------------------------------------------------
 * A session that is put back gets the CSRF token its sign-in has always had,
 * not a new one. So the app left open in a tab while its session expired
 * keeps working: its next save carries a token that is still good, rather
 * than failing with "De sessie is verlopen".
 *
 * ---------------------------------------------------------------------------
 * SIGNING OUT
 * ---------------------------------------------------------------------------
 * session_logout() calls persistent_login_revoke(): this browser's row is
 * deleted and its cookie expired, so signing out — or an account that is
 * gone — really is the end of it. Other browsers the account is signed in on
 * keep their own. Deleting the account deletes every row it had.
 *
 * ---------------------------------------------------------------------------
 * BEFORE MIGRATION 008
 * ---------------------------------------------------------------------------
 * Without the table, signing in works exactly as it always did — for as long
 * as the session lasts — and each session writes one line to the log saying
 * why. Nothing here can take a page down: whatever goes wrong, the request
 * carries on, signed in or not, as the session alone would have it.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

/** How long a sign-in lasts unused. Every use starts the year again. */
if (!defined('PERSISTENT_LOGIN_DAYS')) {
    define('PERSISTENT_LOGIN_DAYS', 365);
}

/** How long after a rotation the validator before it restores without rotating again. */
if (!defined('PERSISTENT_LOGIN_RACE_SECONDS')) {
    define('PERSISTENT_LOGIN_RACE_SECONDS', 300);
}

if (!function_exists('persistent_login_resume')) {

    /* ============================================================ REQUEST */

    /**
     * Every request, from includes/bootstrap.php, as soon as the session has
     * started: a session that has lost its sign-in gets it back, and one that
     * has it is kept in step with its cookie.
     */
    function persistent_login_resume(): void
    {
        try {
            if (current_user_id() !== null) {
                persistent_login_keep();
            } else {
                persistent_login_restore();
            }
        } catch (Throwable $e) {
            /* Staying signed in is a convenience; it is never a reason for a
               page to fail. This request goes on as the session has it. */
            persistent_login_log('skipped for this request: ' . $e->getMessage());
        }
    }

    /**
     * A session with nobody signed in, and a cookie that says somebody was:
     * signs that account back in, on a new session id.
     */
    function persistent_login_restore(): void
    {
        if (!array_key_exists(persistent_login_cookie_name(), $_COOKIE)) {
            return;
        }

        $cookie = persistent_login_presented();

        if ($cookie === null) {
            persistent_login_clear_cookie();        // not the shape of one of ours
            return;
        }

        /* No database, no answer: the cookie stays for when it is back. */
        if (!db_available()) {
            return;
        }

        /* Twice at most: the second look is for when another request, from
           this same browser, rotated the row in between. */
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $statement = db_run(
                'SELECT t.id, t.user_id, t.token_hash, t.previous_hash, t.csrf_token,
                        t.expires_at > NOW() AS live,
                        t.rotated_at > NOW() - INTERVAL ? SECOND AS racing,
                        u.status
                   FROM user_login_tokens t
                   JOIN users u ON u.id = t.user_id
                  WHERE t.selector = ?',
                [PERSISTENT_LOGIN_RACE_SECONDS, $cookie['selector']]
            );

            /* The read failed (and is in the log). Not signed in this time,
               but nothing is thrown away over it. */
            if ($statement === null) {
                return;
            }

            $row = $statement->fetch();

            /* Signed out, expired and cleared away, or never ours. */
            if ($row === false) {
                persistent_login_clear_cookie();
                return;
            }

            if (!$row['live'] || $row['status'] !== 'active') {
                db_run('DELETE FROM user_login_tokens WHERE id = ?', [(int) $row['id']]);
                persistent_login_clear_cookie();
                return;
            }

            $isCurrent  = hash_equals($row['token_hash'], $cookie['hash']);
            $isPrevious = !$isCurrent
                && $row['previous_hash'] !== null
                && hash_equals($row['previous_hash'], $cookie['hash']);

            if (!$isCurrent && !$isPrevious) {
                if ($attempt > 0) {
                    return;     // lost a race twice over: not signed in this time, nothing revoked
                }

                /* A validator this sign-in has moved past, after the browser
                   that holds the new one has shown it has it. Only a copy
                   can do that, so the sign-in goes, for everybody holding
                   it. The real browser signs in again; the copy is useless. */
                persistent_login_log(sprintf(
                    'a validator that was already replaced was presented; sign-in %d of user %d is revoked',
                    (int) $row['id'],
                    (int) $row['user_id']
                ));
                db_run('DELETE FROM user_login_tokens WHERE id = ?', [(int) $row['id']]);
                persistent_login_clear_cookie();
                return;
            }

            /* Another request rotated moments ago, and its answer carries the
               new cookie to this same browser. Signing in on the one before
               without rotating again is what keeps racing tabs on one cookie. */
            if ($isPrevious && $row['racing']) {
                persistent_login_enter($row, $cookie['selector'], $row['token_hash']);
                return;
            }

            /* Rotate — only if nobody else has in the meantime. */
            $validator = bin2hex(random_bytes(32));
            $next      = persistent_login_hash($validator);

            $rotated = db_run(
                'UPDATE user_login_tokens
                    SET token_hash    = ?,
                        previous_hash = ?,
                        rotated_at    = NOW(),
                        last_used_at  = NOW(),
                        expires_at    = NOW() + INTERVAL ? DAY
                  WHERE id = ? AND token_hash = ?',
                [$next, $cookie['hash'], PERSISTENT_LOGIN_DAYS, (int) $row['id'], $row['token_hash']]
            );

            if ($rotated !== null && $rotated->rowCount() === 1) {
                persistent_login_set_cookie($cookie['selector'], $validator);
                persistent_login_enter($row, $cookie['selector'], $next);
                return;
            }
        }
    }

    /** Signs this request in from a sign-in row, on a new session id. */
    function persistent_login_enter(array $row, string $selector, string $confirm): void
    {
        // A new id, as on any sign-in, so a planted session id is worth nothing.
        session_regenerate_id(true);

        $_SESSION['user_id']      = (int) $row['user_id'];
        $_SESSION['logged_in_at'] = time();
        // The sign-in's own form token, so pages still open keep working.
        $_SESSION['csrf_token']   = (string) $row['csrf_token'];

        $_SESSION['persistent_login'] = ['selector' => $selector, 'confirm' => $confirm];
    }

    /**
     * A session that is signed in. Two things can be owed to it: confirming a
     * rotation it made, once the browser shows it has the new cookie; and, for
     * a session that was signed in before this file existed, a sign-in that
     * lasts — so nobody has to sign in again for the change to reach them.
     */
    function persistent_login_keep(): void
    {
        $state = $_SESSION['persistent_login'] ?? null;

        /* Already tried in this session, and there is no table to keep it in. */
        if ($state === false) {
            return;
        }

        if (is_array($state)) {
            if (($state['confirm'] ?? null) === null) {
                return;
            }

            $cookie = persistent_login_presented();

            if ($cookie !== null
                && hash_equals($state['selector'], $cookie['selector'])
                && hash_equals($state['confirm'], $cookie['hash'])) {
                /* The browser has the new validator, so the one before it
                   stops working from now on. */
                db_run(
                    'UPDATE user_login_tokens SET previous_hash = NULL WHERE selector = ? AND token_hash = ?',
                    [$cookie['selector'], $cookie['hash']]
                );
                $_SESSION['persistent_login']['confirm'] = null;
            }

            return;
        }

        $userId = current_user_id();

        if ($userId !== null
            && db_available()
            && db_value('SELECT status FROM users WHERE id = ?', [$userId]) === 'active') {
            persistent_login_issue($userId);
        }
    }

    /* ============================================================ SIGN-IN */

    /**
     * This browser stays signed in as $userId. Called by session_login(), so
     * every way of signing in — password, registering, Google — gets it.
     * Whatever sign-in the browser had before is replaced, not left behind.
     */
    function persistent_login_issue(int $userId): bool
    {
        session_boot();

        if (!db_available()) {
            return false;
        }

        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        try {
            persistent_login_forget();

            /* Housekeeping while writing here anyway: sign-ins unused for a
               year. One indexed DELETE, and no cron job to forget. */
            db_run('DELETE FROM user_login_tokens WHERE expires_at < NOW()');

            db_run(
                'INSERT INTO user_login_tokens (user_id, selector, token_hash, csrf_token, expires_at)
                      VALUES (?, ?, ?, ?, NOW() + INTERVAL ? DAY)',
                [$userId, $selector, persistent_login_hash($validator), csrf_token(), PERSISTENT_LOGIN_DAYS]
            );
        } catch (PDOException $e) {
            persistent_login_log(
                'could not be saved, so this sign-in lasts only as long as its session. If the table '
                . 'user_login_tokens is missing, import database/migrations/008-persistent-login.sql. ('
                . $e->getMessage() . ')'
            );
            $_SESSION['persistent_login'] = false;

            return false;
        }

        persistent_login_set_cookie($selector, $validator);
        $_SESSION['persistent_login'] = ['selector' => $selector, 'confirm' => null];

        return true;
    }

    /* =========================================================== SIGN-OUT */

    /**
     * Signing out, called by session_logout(): this browser's sign-in is
     * deleted and its cookie expired. Other browsers keep theirs — signing
     * out on a phone does not sign anybody out of their laptop.
     */
    function persistent_login_revoke(): void
    {
        session_boot();

        try {
            if (db_available()) {
                persistent_login_forget();
            }
        } catch (Throwable $e) {
            persistent_login_log('could not delete a sign-in while signing out: ' . $e->getMessage());
        }

        unset($_SESSION['persistent_login']);
        persistent_login_clear_cookie();
    }

    /** Deletes this browser's sign-in row, if it has one. Throws if the delete fails. */
    function persistent_login_forget(): void
    {
        $state = $_SESSION['persistent_login'] ?? null;

        if (is_array($state)) {
            db_run('DELETE FROM user_login_tokens WHERE selector = ?', [$state['selector']]);
        }

        /* The cookie's own row as well, when the session did not know it —
           but only on proof that it is this browser's. A selector alone is
           not enough to sign somebody else out with. */
        $cookie = persistent_login_presented();

        if ($cookie !== null && (!is_array($state) || $cookie['selector'] !== $state['selector'])) {
            db_run(
                'DELETE FROM user_login_tokens WHERE selector = ? AND (token_hash = ? OR previous_hash = ?)',
                [$cookie['selector'], $cookie['hash'], $cookie['hash']]
            );
        }

        unset($_SESSION['persistent_login']);
    }

    /* ============================================================= COOKIE */

    /** Whether this request is https, as the session cookie already decided. */
    function persistent_login_secure(): bool
    {
        session_boot();

        return (bool) (session_get_cookie_params()['secure'] ?? false);
    }

    function persistent_login_cookie_name(): string
    {
        return persistent_login_secure() ? '__Host-jolu_login' : 'jolu_login';
    }

    /** One place, so issuing and checking can never disagree. */
    function persistent_login_hash(string $validator): string
    {
        return hash('sha256', $validator);
    }

    /**
     * The cookie this request carried, as a selector and the hash of its
     * validator — or null. The validator itself goes no further than here.
     *
     * @return array{selector: string, hash: string}|null
     */
    function persistent_login_presented(): ?array
    {
        $value = $_COOKIE[persistent_login_cookie_name()] ?? null;

        if (!is_string($value) || !preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})$/', $value, $match)) {
            return null;
        }

        return ['selector' => $match[1], 'hash' => persistent_login_hash($match[2])];
    }

    function persistent_login_set_cookie(string $selector, string $validator): void
    {
        $value = $selector . '.' . $validator;

        persistent_login_send($value, time() + PERSISTENT_LOGIN_DAYS * 86400);
        $_COOKIE[persistent_login_cookie_name()] = $value;
    }

    function persistent_login_clear_cookie(): void
    {
        persistent_login_send('', time() - 42000);
        unset($_COOKIE[persistent_login_cookie_name()]);
    }

    function persistent_login_send(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(persistent_login_cookie_name(), $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => persistent_login_secure(),
            'httponly' => true,             // never readable from JavaScript
            'samesite' => 'Lax',            // not sent on cross-site POSTs
        ]);
    }

    function persistent_login_log(string $message): void
    {
        error_log('[jolu] staying signed in: ' . $message);
    }
}
