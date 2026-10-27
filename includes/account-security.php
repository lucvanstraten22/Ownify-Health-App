<?php
/**
 * Account security — Release A (28.0). docs/ACCOUNT-SECURITY.md has the
 * whole picture; this file is the part every request and every sign-in uses.
 *
 * ---------------------------------------------------------------------------
 * ONE ADDRESS RULE
 * ---------------------------------------------------------------------------
 * account_email_canonical() decides what an address is: surrounding
 * whitespace (ASCII and Unicode) removed, nothing else that is not printable
 * ASCII, lower-case, a valid address, at most 191 characters. Nothing
 * compares or stores an address that has not been through it. The database
 * holds the same rule for the stored characters (migration 027's CHECK
 * constraints); account_email_storable() is its PHP twin, byte for byte.
 *
 * ---------------------------------------------------------------------------
 * ONE PASSWORD CHECK
 * ---------------------------------------------------------------------------
 * auth_password_matches() is the only place a password is compared. An empty
 * hash (an account without a password) is compared against a real dummy hash
 * made once per process, so it takes the time a real account takes and says
 * no. Signing in always makes AUTH_SIGNIN_PASSWORD_CHECKS comparisons, so the
 * time it takes says nothing about which branch was taken.
 *
 * ---------------------------------------------------------------------------
 * ENDING OTHER SESSIONS: A NUMBER, NOT A TIME
 * ---------------------------------------------------------------------------
 * users.session_generation (migration 027). Every new browser session — a
 * sign-in, or the stay-signed-in cookie putting one back — carries the
 * account's number at that moment. A password change moves the number on in
 * its own transaction and gives the new number to the one session that made
 * it; every other session holds an older number and is signed out on its
 * next request (account_session_enforce(), from includes/bootstrap.php).
 *
 * Fail-closed: a session that carries no number (signed in before this) is
 * generation 0 and is never given a newer one. It carries on while the
 * account is at 0 and ends once a security action has moved the account on.
 *
 * Without the column there is no way to end other browser sessions, so the
 * password change is switched off rather than run half-way.
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';   // language and units (docs/I18N.md)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

/** Characters a password must have, and may have. */
if (!defined('ACCOUNT_PASSWORD_MIN')) {
    define('ACCOUNT_PASSWORD_MIN', 8);
    define('ACCOUNT_PASSWORD_MAX', 200);
}

/** How long a confirmation with Google (or its nonce) is good for, in seconds. */
if (!defined('ACCOUNT_REAUTH_SECONDS')) {
    define('ACCOUNT_REAUTH_SECONDS', 300);
}

/** Password comparisons per sign-in attempt, whichever branch it takes (docs/ACCOUNT-SECURITY.md §3a). */
if (!defined('AUTH_SIGNIN_PASSWORD_CHECKS')) {
    define('AUTH_SIGNIN_PASSWORD_CHECKS', 4);
}

/** Google sign-in, sign-up and confirmation: attempts per address, per quarter of an hour. */
if (!defined('ACCOUNT_GOOGLE_LIMIT')) {
    define('ACCOUNT_GOOGLE_LIMIT', 20);
}

if (!function_exists('account_email_canonical')) {

    /* ============================================================ ADDRESSES */

    /**
     * The canonical form of an address, or null when it is not one.
     *
     *   'Sanne@Example.com'      -> 'sanne@example.com'
     *   "  sanne@example.com\n"  -> 'sanne@example.com'   (surrounding space, any kind)
     *   'san ne@example.com'     -> null                  (space inside)
     *   'sänne@example.com'      -> null                  (not ASCII)
     *
     * Gmail's dots and +tags are not folded: they are different addresses to
     * Ownify, as to every mail system except Gmail's own.
     */
    function account_email_canonical(string $raw): ?string
    {
        $trimmed = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw);

        /* Null also when the input is not valid UTF-8: not an address. */
        if (!is_string($trimmed) || $trimmed === '') {
            return null;
        }

        /* Whitespace, control characters and anything not ASCII inside: refused. */
        if (preg_match('/[^\x21-\x7E]/', $trimmed) === 1) {
            return null;
        }

        $address = strtolower($trimmed);

        if (strlen($address) > 191 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        /* The database's own rule, so every address made here can be stored. */
        return account_email_storable($address) ? $address : null;
    }

    /**
     * Whether a value may be stored in an address column: 1–191 bytes, every
     * byte printable ASCII and none a capital — byte for byte the CHECK
     * constraints of migration 027 (tools/canonical-check.php compares them).
     */
    function account_email_storable(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 191
            && preg_match('/[^\x21-\x40\x5B-\x7E]/', $value) === 0;
    }

    /**
     * Whether an address is already somebody's verified address
     * (account_emails, a Google one included) or is listed as contested and
     * not yet resolved (account_email_conflicts). Either way nobody may claim
     * it. False before migration 027, when neither table exists.
     */
    function account_email_claimed(string $canonical): bool
    {
        $schema = account_security_schema();

        if ($schema['account_emails']
            && db_value('SELECT user_id FROM account_emails WHERE email = ?', [$canonical]) !== null) {
            return true;
        }

        return $schema['conflicts']
            && db_value('SELECT email FROM account_email_conflicts WHERE email = ? AND resolved_at IS NULL', [$canonical]) !== null;
    }

    /* ============================================================ PASSWORDS */

    /** A real hash at the current default cost, made once per process. Matches nothing anybody types. */
    function auth_password_dummy_hash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }

    /**
     * The one password comparison. An absent or empty hash spends the same
     * time on the dummy hash and says no.
     */
    function auth_password_matches(?string $hash, string $password): bool
    {
        auth_password_checks(1);

        if ($hash === null || $hash === '') {
            password_verify($password, auth_password_dummy_hash());

            return false;
        }

        return password_verify($password, $hash);
    }

    /** Comparisons made in this process so far (for the tests); $add adds to it. */
    function auth_password_checks(int $add = 0): int
    {
        static $count = 0;

        return $count += $add;
    }

    /**
     * What is wrong with a new password, or null. The same rules on every
     * screen that sets one: 8–200 characters, not the username, not one of
     * the account's addresses (neither in any case), and typed the same twice.
     *
     * @param list<string> $addresses
     */
    function account_password_problem(string $password, string $repeat, string $username, array $addresses = []): ?string
    {
        $length = mb_strlen($password);

        if ($length < ACCOUNT_PASSWORD_MIN) {
            return t('Kies een wachtwoord van minimaal 8 tekens.');
        }

        if ($length > ACCOUNT_PASSWORD_MAX) {
            return t('Kies een wachtwoord van hoogstens 200 tekens.');
        }

        $same = static fn (string $a, string $b): bool
            => $b !== '' && mb_strtolower(trim($a)) === mb_strtolower(trim($b));

        if ($same($password, $username)) {
            return t('Je wachtwoord mag niet hetzelfde zijn als je gebruikersnaam.');
        }

        foreach ($addresses as $address) {
            if ($same($password, (string) $address)) {
                return t('Je wachtwoord mag niet hetzelfde zijn als je e-mailadres.');
            }
        }

        if (!hash_equals($password, $repeat)) {
            return t('De twee wachtwoorden zijn niet hetzelfde.');
        }

        return null;
    }

    /* =============================================================== SCHEMA */

    /**
     * Which parts of migration 027 this database has. One query, once per
     * request, and only asked where something depends on it.
     *
     * @return array{session_generation: bool, pending: bool, account_emails: bool, conflicts: bool,
     *               tokens: bool, changes: bool, baseline: bool, outbox: bool, complete: bool}
     */
    function account_security_schema(bool $fresh = false): array
    {
        static $schema = null;

        if ($schema !== null && !$fresh) {
            return $schema;
        }

        $none = ['session_generation' => false, 'pending' => false, 'account_emails' => false, 'conflicts' => false,
                 'tokens' => false, 'changes' => false, 'baseline' => false, 'outbox' => false, 'complete' => false];

        if (!db_available()) {
            return $schema = $none;
        }

        $rows = db_all(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND ((TABLE_NAME = 'users' AND COLUMN_NAME IN ('session_generation', 'status'))
                     OR (TABLE_NAME = 'auth_email_tokens' AND COLUMN_NAME = 'change_id')
                     OR (TABLE_NAME = 'mail_outbox' AND COLUMN_NAME IN ('claim_token', 'lease_until', 'attempts'))
                     OR (TABLE_NAME IN ('pending_registrations', 'account_emails', 'account_email_conflicts',
                                        'email_changes', 'email_change_google_baseline') AND ORDINAL_POSITION = 1))"
        );

        $has = [];
        foreach ($rows as $row) {
            $has[$row['t'] . '.' . $row['c']] = (string) $row['type'];
            $has[$row['t']] = true;
        }

        $schema = [
            'session_generation' => isset($has['users.session_generation']),
            'pending'            => isset($has['pending_registrations'])
                                    && str_contains($has['users.status'] ?? '', "'pending'"),
            'account_emails'     => isset($has['account_emails']),
            'conflicts'          => isset($has['account_email_conflicts']),
            'tokens'             => isset($has['auth_email_tokens.change_id']),
            'changes'            => isset($has['email_changes']),
            'baseline'           => isset($has['email_change_google_baseline']),
            'outbox'             => isset($has['mail_outbox.claim_token'], $has['mail_outbox.lease_until'], $has['mail_outbox.attempts']),
        ];
        $schema['complete'] = !in_array(false, $schema, true);

        return $schema;
    }

    /* ============================================================= SESSIONS */

    /** The account's session number, or null without migration 027 (or without the account). */
    function account_session_generation(int $userId): ?int
    {
        if (!account_security_schema()['session_generation']) {
            return null;
        }

        $value = db_value('SELECT session_generation FROM users WHERE id = ?', [$userId]);

        return $value === null ? null : (int) $value;
    }

    /**
     * A NEW session — a sign-in, or the stay-signed-in cookie putting one
     * back — starts at the account's current number. Called by
     * session_login() and persistent_login_enter(), and by nothing else that
     * could hand an existing session a newer number.
     */
    function account_session_stamp(int $userId): void
    {
        $generation = account_session_generation($userId);

        if ($generation !== null) {
            $_SESSION['session_generation'] = $generation;
        } else {
            unset($_SESSION['session_generation']);
        }
    }

    /**
     * Every request, once the session's user is known (includes/bootstrap.php):
     * a session whose number is older than the account's is signed out. A
     * session without a number counts as 0 (fail-closed).
     */
    function account_session_enforce(): void
    {
        $userId = current_user_id();

        if ($userId === null) {
            return;
        }

        $current = account_session_generation($userId);

        if ($current === null) {
            return;
        }

        $mine = $_SESSION['session_generation'] ?? 0;
        $mine = is_int($mine) ? $mine : 0;

        if ($current > $mine) {
            session_logout();
            session_boot();
        }
    }

    /**
     * Inside a security action's transaction, after the account row is
     * locked: the number moves on, every stay-signed-in cookie of the account
     * goes and every app sign-in is revoked — except the one browser cookie
     * ($keepSelector) and the one app token ($keepDeviceId) that made the
     * change, when there is one. Health-sync tokens (scope 'sync') are never
     * touched. Returns the new number.
     */
    function account_end_other_sessions(int $userId, ?string $keepSelector, ?int $keepDeviceId): int
    {
        db_run('UPDATE users SET session_generation = session_generation + 1 WHERE id = ?', [$userId]);
        $generation = (int) db_value('SELECT session_generation FROM users WHERE id = ?', [$userId]);

        if ($keepSelector !== null) {
            db_run('DELETE FROM user_login_tokens WHERE user_id = ? AND selector <> ?', [$userId, $keepSelector]);
        } else {
            db_run('DELETE FROM user_login_tokens WHERE user_id = ?', [$userId]);
        }

        if (function_exists('devices_scoped') && devices_scoped()) {
            if ($keepDeviceId !== null) {
                db_run(
                    "UPDATE user_devices SET revoked_at = NOW()
                      WHERE user_id = ? AND scope = 'account' AND revoked_at IS NULL AND id <> ?",
                    [$userId, $keepDeviceId]
                );
            } else {
                db_run(
                    "UPDATE user_devices SET revoked_at = NOW()
                      WHERE user_id = ? AND scope = 'account' AND revoked_at IS NULL",
                    [$userId]
                );
            }
        }

        return $generation;
    }

    /* ============================================================= ACCOUNT */

    /**
     * The account's ways in, as Settings shows them. Never a hash.
     *
     * @return array{username: string, email: ?string, email_verified: bool, password: bool,
     *               google: bool, google_email: ?string, can_change_password: bool,
     *               can_link_google: bool}
     */
    function account_signin_state(int $userId): array
    {
        $username = (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]);
        $email    = db_one(
            "SELECT provider_subject, email_verified_at, password_hash
               FROM user_auth_identities WHERE user_id = ? AND provider = 'email' LIMIT 1",
            [$userId]
        );
        $google   = db_one(
            "SELECT email FROM user_auth_identities WHERE user_id = ? AND provider = 'google' LIMIT 1",
            [$userId]
        );

        $hasPassword = $email !== null && (string) ($email['password_hash'] ?? '') !== '';

        return [
            'username'            => $username,
            'email'               => $email !== null ? (string) $email['provider_subject'] : null,
            'email_verified'      => $email !== null && $email['email_verified_at'] !== null,
            'password'            => $hasPassword,
            'google'              => $google !== null,
            'google_email'        => $google !== null && $google['email'] !== null ? (string) $google['email'] : null,
            /* Without the session number other browsers could not be signed
               out, so the change is not offered at all. */
            'can_change_password' => $hasPassword && account_security_schema()['session_generation'],
            /* Linking needs the account's own address verified, which needs
               mail: not before Release B (docs/ACCOUNT-SECURITY.md §8). */
            'can_link_google'     => false,
        ];
    }

    /** Every address the account has, lower-case: sign-in, Google and verified. */
    function account_addresses(int $userId): array
    {
        $addresses = [];

        foreach (db_all('SELECT provider, provider_subject, email FROM user_auth_identities WHERE user_id = ?', [$userId]) as $row) {
            if ($row['provider'] === 'email') {
                $addresses[] = (string) $row['provider_subject'];
            }
            if ($row['email'] !== null) {
                $addresses[] = (string) $row['email'];
            }
        }

        if (account_security_schema()['account_emails']) {
            foreach (db_all('SELECT email FROM account_emails WHERE user_id = ?', [$userId]) as $row) {
                $addresses[] = (string) $row['email'];
            }
        }

        return array_values(array_unique(array_map(static fn (string $a): string => mb_strtolower(trim($a)), $addresses)));
    }

    /**
     * Changes the password, after the current one, and signs out everything
     * else (docs/ACCOUNT-SECURITY.md §9). The session or app token making the
     * change stays signed in.
     *
     * @param ?int $deviceId the app's own token row when the app asks; null for a browser
     * @return array{ok: bool, error?: string, status?: int, retry_after?: int}
     */
    function account_change_password(int $userId, string $current, string $new, string $repeat, ?int $deviceId): array
    {
        require_once __DIR__ . '/auth-throttle.php';

        $pdo = db();

        if ($pdo === null) {
            return ['ok' => false, 'error' => t('Geen databaseverbinding.'), 'status' => 503];
        }

        if (!account_security_schema()['session_generation']) {
            return ['ok' => false, 'error' => t('Dit kan even niet. Probeer het later opnieuw.'), 'status' => 503];
        }

        $state = account_signin_state($userId);

        if (!$state['password']) {
            return ['ok' => false, 'error' => t('Je account heeft nog geen wachtwoord.'), 'status' => 409];
        }

        /* The current password is a sign-in by another name: the same
           counter, keyed on the username. */
        $wait = auth_throttle_wait('login', $state['username']);

        if ($wait !== null) {
            return ['ok' => false, 'error' => auth_throttle_message($wait), 'status' => 429, 'retry_after' => $wait];
        }

        $hash = db_value(
            "SELECT password_hash FROM user_auth_identities WHERE user_id = ? AND provider = 'email' LIMIT 1",
            [$userId]
        );

        if (!auth_password_matches(is_string($hash) ? $hash : null, $current)) {
            auth_throttle_fail('login', $state['username']);

            return ['ok' => false, 'error' => t('Je huidige wachtwoord klopt niet.'), 'status' => 403];
        }

        auth_throttle_clear('login', $state['username']);

        $problem = account_password_problem($new, $repeat, $state['username'], account_addresses($userId));

        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem, 'status' => 422];
        }

        $keepSelector = null;
        if ($deviceId === null && is_array($_SESSION['persistent_login'] ?? null)) {
            $keepSelector = (string) $_SESSION['persistent_login']['selector'];
        }

        try {
            $pdo->beginTransaction();

            /* Every account-security change starts with this same lock. */
            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            db_run(
                "UPDATE user_auth_identities SET password_hash = ? WHERE user_id = ? AND provider = 'email'",
                [password_hash($new, PASSWORD_DEFAULT), $userId]
            );

            $generation = account_end_other_sessions($userId, $keepSelector, $deviceId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('[ownify] password change failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => t('Je wachtwoord kon niet worden gewijzigd. Probeer het opnieuw.'), 'status' => 500];
        }

        /* The browser that made the change: the one session given the new
           number, on a new id. */
        if ($deviceId === null && current_user_id() === $userId) {
            session_regenerate_id(true);
            $_SESSION['session_generation'] = $generation;
        }

        return ['ok' => true];
    }

    /* ======================================================= REAUTHENTICATION */

    /**
     * How this account proves it is itself again before deletion: its
     * password when it has one, otherwise Google.
     */
    function account_reauth_method(int $userId): string
    {
        return account_signin_state($userId)['password'] ? 'password' : 'google';
    }

    /** The Google id (`sub`) linked to the account, or null. */
    function account_google_sub(int $userId): ?string
    {
        $sub = db_value(
            "SELECT provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = 'google' LIMIT 1",
            [$userId]
        );

        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    /** The website came back from Google having proved the account: one action, five minutes. */
    function account_reauth_grant(int $userId, string $purpose): void
    {
        session_boot();
        $_SESSION['account_reauth'] = ['user_id' => $userId, 'purpose' => $purpose, 'until' => time() + ACCOUNT_REAUTH_SECONDS];
    }

    /** Uses up the website's grant, if there is a live one for this account and purpose. */
    function account_reauth_take(int $userId, string $purpose): bool
    {
        session_boot();

        $grant = $_SESSION['account_reauth'] ?? null;

        $ok = is_array($grant)
            && ($grant['user_id'] ?? null) === $userId
            && ($grant['purpose'] ?? null) === $purpose
            && is_int($grant['until'] ?? null)
            && $grant['until'] >= time();

        if ($ok) {
            unset($_SESSION['account_reauth']);
        }

        return $ok;
    }

    /** Whether a live grant is waiting (without using it). */
    function account_reauth_waiting(int $userId, string $purpose): bool
    {
        $grant = $_SESSION['account_reauth'] ?? null;

        return is_array($grant)
            && ($grant['user_id'] ?? null) === $userId
            && ($grant['purpose'] ?? null) === $purpose
            && is_int($grant['until'] ?? null)
            && $grant['until'] >= time();
    }

    /**
     * The app's confirmation with Google, step 1: a nonce for Credential
     * Manager, kept in this conversation's session (the app's cookie jar) and
     * tied to the account and the app token asking.
     */
    function account_reauth_nonce(int $userId, int $deviceId): string
    {
        require_once __DIR__ . '/google-signin.php';

        session_boot();
        $nonce = google_signin_b64url_encode(random_bytes(32));

        $_SESSION['account_reauth_nonce'] = [
            'nonce'     => $nonce,
            'user_id'   => $userId,
            'device_id' => $deviceId,
            'started'   => time(),
        ];

        return $nonce;
    }

    /**
     * The app's confirmation with Google, step 2: the ID token Credential
     * Manager gave back, sent WITH the action itself. Checked like a sign-in
     * (google_signin_verify(): signature, issuer, audience, the app's own
     * client, expiry, and this nonce, which is then used up) and it must be
     * the account's own Google id. Null when it holds; otherwise the reason.
     */
    function account_reauth_google_token(int $userId, int $deviceId, string $idToken): ?string
    {
        require_once __DIR__ . '/google-signin.php';

        session_boot();

        $flow = $_SESSION['account_reauth_nonce'] ?? null;
        unset($_SESSION['account_reauth_nonce']);              // works once, whatever happens next

        $fail = t('Bevestigen met Google is niet gelukt. Probeer het opnieuw.');

        if (!is_array($flow) || ($flow['user_id'] ?? null) !== $userId || ($flow['device_id'] ?? null) !== $deviceId
            || !is_int($flow['started'] ?? null) || time() - $flow['started'] > ACCOUNT_REAUTH_SECONDS
            || !is_string($flow['nonce'] ?? null)) {
            return t('Deze bevestiging is verlopen. Begin opnieuw.');
        }

        if ($idToken === '' || strlen($idToken) > 8192 || !google_signin_native_configured()) {
            return $fail;
        }

        $keys = google_signin_keys();

        if ($keys === null) {
            return t('Google kon je aanmelding niet bevestigen. Probeer het opnieuw.');
        }

        $config   = google_signin_config();
        $verified = google_signin_verify($idToken, $keys, $config['client_id'], $flow['nonce'], null, $config['android_client_ids']);

        if (!$verified['ok']) {
            error_log('[google-signin] app reauth token refused: ' . $verified['error']);
            return $fail;
        }

        $sub = account_google_sub($userId);

        if ($sub === null || !hash_equals($sub, (string) $verified['claims']['sub'])) {
            return t('Gebruik het Google-account dat aan je Ownify-account is gekoppeld.');
        }

        return null;
    }

    /**
     * The proof an action like deleting the account asks for. Null when it
     * holds; otherwise what to answer: the message, the HTTP status, and which
     * proof is wanted (`reauth`: password | google) so a screen can ask for it.
     *
     * @param array<string, mixed> $input the request: password, or id_token (app)
     * @return array{error: string, status: int, reauth: string, retry_after?: int}|null
     */
    function account_require_proof(int $userId, string $purpose, array $input, ?int $deviceId): ?array
    {
        require_once __DIR__ . '/auth-throttle.php';

        $method = account_reauth_method($userId);

        if ($method === 'password') {
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';

            if ($password === '') {
                return ['error' => t('Vul je wachtwoord in om dit te bevestigen.'), 'status' => 403, 'reauth' => 'password'];
            }

            $username = (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]);
            $wait     = auth_throttle_wait('login', $username);

            if ($wait !== null) {
                return ['error' => auth_throttle_message($wait), 'status' => 429, 'reauth' => 'password', 'retry_after' => $wait];
            }

            $hash = db_value(
                "SELECT password_hash FROM user_auth_identities WHERE user_id = ? AND provider = 'email' LIMIT 1",
                [$userId]
            );

            if (!auth_password_matches(is_string($hash) ? $hash : null, $password)) {
                auth_throttle_fail('login', $username);

                return ['error' => t('Je wachtwoord klopt niet.'), 'status' => 403, 'reauth' => 'password'];
            }

            auth_throttle_clear('login', $username);

            return null;
        }

        /* No password: Google. The app sends its ID token with the action;
           the website has come back from Google with a grant. */
        if ($deviceId !== null) {
            $idToken = is_string($input['id_token'] ?? null) ? $input['id_token'] : '';

            if ($idToken === '') {
                return ['error' => t('Bevestig eerst met Google.'), 'status' => 403, 'reauth' => 'google'];
            }

            $problem = account_reauth_google_token($userId, $deviceId, $idToken);

            return $problem === null ? null : ['error' => $problem, 'status' => 403, 'reauth' => 'google'];
        }

        if (account_reauth_take($userId, $purpose)) {
            return null;
        }

        return ['error' => t('Bevestig eerst met Google.'), 'status' => 403, 'reauth' => 'google'];
    }

    /* ========================================================= REGISTRATION */

    /**
     * Whether a new account can be made with an e-mail address and password.
     *
     * Release A: no. New e-mail registrations wait on a verification screen
     * until their link is used, which needs mail — Release B. config/mail.php's
     * `email_registration` switch is read by Release B and ignored here.
     *
     * OWNIFY_TEST_EMAIL_REGISTRATION=1 in the environment of a LOCAL test
     * server (tools/*-test.php) opens today's immediate registration so the
     * test suites can make their throwaway accounts. Never set it on a
     * server people use.
     */
    function account_email_registration_open(): bool
    {
        return getenv('OWNIFY_TEST_EMAIL_REGISTRATION') === '1';
    }

    /** Google linking is not offered before Release B (docs/ACCOUNT-SECURITY.md §8). */
    function account_google_link_open(): bool
    {
        return false;
    }
}
