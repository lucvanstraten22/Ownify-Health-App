<?php
/**
 * Authentication.
 *
 * An account is not defined by how you sign in: user_auth_identities holds one
 * row per method, so connecting Google to an existing account later is an
 * INSERT, not a migration.
 *
 * ---------------------------------------------------------------------------
 * E-MAIL AND GOOGLE
 * ---------------------------------------------------------------------------
 * Google sign-in lives in includes/google-signin.php: an OpenID Connect flow
 * whose ID token is verified on this server before its 'sub' is believed. It
 * is offered only once config/auth.php has credentials; until then its button
 * renders disabled.
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n.php';   // language and units (docs/I18N.md)

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/account-security.php';   // the address rule, the password check (28.0)

if (!function_exists('auth_provider_available')) {

    /**
     * Whether a way of signing in can be offered right now. Google only once
     * it has credentials. Nothing here pretends otherwise.
     */
    function auth_provider_available(string $provider): bool
    {
        if ($provider === 'email') {
            return db_available();
        }

        if ($provider === 'google') {
            require_once __DIR__ . '/google-signin.php';

            return db_available() && google_signin_configured();
        }

        return false;
    }

    /* -------------------------------------------------- one-time messages */

    /**
     * A message for the next page render, and where it belongs: the account
     * panel ('account') or Settings > Account ('settings-account').
     *
     * For the moments a result arrives by redirect rather than as a JSON
     * answer — coming back from Google — so there is no script waiting to
     * show it.
     */
    function auth_flash(string $message, string $tone, string $target, ?array $link = null): void
    {
        session_boot();
        $_SESSION['auth_flash'] = [
            'message' => $message,
            'tone'    => $tone,
            'target'  => $target,
            /* A fixed address chosen here in the code, never from a request. */
            'link'    => $link === null ? null : ['href' => (string) $link['href'], 'label' => (string) $link['label']],
        ];
    }

    /** The waiting message, once. @return array{message: string, tone: string, target: string, link: ?array}|null */
    function auth_flash_take(): ?array
    {
        session_boot();

        $flash = $_SESSION['auth_flash'] ?? null;
        unset($_SESSION['auth_flash']);

        if (!is_array($flash) || !is_string($flash['message'] ?? null)) {
            return null;
        }

        /* Said in the language of the page it lands on (includes/i18n.php). */
        $flash['message'] = t($flash['message']);
        if (is_array($flash['link'] ?? null)) {
            $flash['link']['label'] = t((string) $flash['link']['label']);
        }

        return $flash;
    }

    /* --------------------------------------------------------- validation */

    /**
     * The canonical address (account_email_canonical(), includes/account-security.php).
     * Something that is not an address comes back trimmed and lower-cased, so
     * a lookup with it simply finds nothing.
     */
    function auth_normalise_email(string $email): string
    {
        return account_email_canonical($email) ?? mb_strtolower(trim($email));
    }

    function auth_validate_email(string $email): ?string
    {
        return account_email_canonical($email) === null ? t('Vul een geldig e-mailadres in.') : null;
    }

    /** The length rule alone; account_password_problem() is the whole rule. */
    function auth_validate_password(string $password): ?string
    {
        if (mb_strlen($password) < ACCOUNT_PASSWORD_MIN) {
            return t('Kies een wachtwoord van minimaal 8 tekens.');
        }

        if (mb_strlen($password) > ACCOUNT_PASSWORD_MAX) {
            return t('Kies een wachtwoord van hoogstens 200 tekens.');
        }

        return null;
    }

    /* ---------------------------------------------------------- register */

    /**
     * Creates an account with an email identity.
     * Returns ['ok' => bool, 'error' => ?string, 'user_id' => ?int].
     *
     * Closed in Release A (account_email_registration_open()): the endpoints
     * refuse before they get here. $repeat is the "Herhaal wachtwoord" field.
     */
    function auth_register_email(string $email, string $password, string $username, ?string $repeat = null): array
    {
        require_once __DIR__ . '/user.php';

        $pdo = db();
        if ($pdo === null) {
            return ['ok' => false, 'error' => t('Geen databaseverbinding.')];
        }

        $canonical = account_email_canonical($email);

        $error = auth_validate_email($email)
            ?? user_validate_username($username)
            ?? account_password_problem($password, $repeat ?? $password, $username, $canonical === null ? [] : [$canonical]);

        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $email = (string) $canonical;

        /* Taken: another account's sign-in address, or another account's
           verified address (a Google one too), or one nobody may claim yet. */
        $existing = db_value(
            'SELECT id FROM user_auth_identities WHERE provider = ? AND provider_subject = ?',
            ['email', $email]
        ) ?? (account_email_claimed($email) ? 1 : null);

        /* `taken` marks the two answers that say something about other
           accounts, which the app's registration limit counts
           (api/auth/app-register.php). It never reaches a response. */
        if ($existing !== null) {
            return ['ok' => false, 'error' => t('Er bestaat al een account met dit e-mailadres.'), 'taken' => true];
        }

        if (user_username_taken($username)) {
            return ['ok' => false, 'error' => t('Deze gebruikersnaam is al bezet.'), 'taken' => true];
        }

        try {
            $pdo->beginTransaction();

            db_run('INSERT INTO users (username) VALUES (?)', [$username]);
            $userId = (int) $pdo->lastInsertId();

            db_run('INSERT INTO user_profiles (user_id) VALUES (?)', [$userId]);

            /* A new account starts with its setup (includes/setup.php): the
               only place `pending` is written, so no existing account sees it. */
            if (is_file(__DIR__ . '/setup.php')) {
                require_once __DIR__ . '/setup.php';
                setup_begin($userId);
            }

            db_run(
                'INSERT INTO user_auth_identities
                    (user_id, provider, provider_subject, email, password_hash)
                 VALUES (?, ?, ?, ?, ?)',
                [$userId, 'email', $email, $email, password_hash($password, PASSWORD_DEFAULT)]
            );

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // A race on either unique key lands here.
            return ['ok' => false, 'error' => t('Dit account kon niet worden aangemaakt.')];
        }

        return ['ok' => true, 'error' => null, 'user_id' => $userId];
    }

    /* ------------------------------------------------------------- login */

    /**
     * Signs in with a password.
     *
     * The identifier is a username or the e-mail address the account signs in
     * with: a username is `^[A-Za-z0-9._-]+$` and an address always has an
     * `@`, so the two never collide. An address is made canonical first
     * (account_email_canonical()).
     *
     * One generic answer whether the name is unknown, has no password, or the
     * password is wrong, and the same work every time: exactly
     * AUTH_SIGNIN_PASSWORD_CHECKS comparisons through auth_password_matches()
     * (docs/ACCOUNT-SECURITY.md §3a), so the time taken says nothing either.
     * The stored hash is renewed only after a successful check.
     */
    function auth_login_password(string $identifier, string $password): array
    {
        if (!db_available()) {
            return ['ok' => false, 'error' => t('Geen databaseverbinding.')];
        }

        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            $address  = account_email_canonical($identifier);
            $identity = $address === null ? null : db_one(
                'SELECT i.id, i.user_id, i.password_hash, u.status
                   FROM user_auth_identities i
                   JOIN users u ON u.id = i.user_id
                  WHERE i.provider = ? AND i.provider_subject = ?
                  LIMIT 1',
                ['email', $address]
            );
        } else {
            $identity = $identifier === '' ? null : db_one(
                'SELECT i.id, i.user_id, i.password_hash, u.status
                   FROM user_auth_identities i
                   JOIN users u ON u.id = i.user_id
                  WHERE i.provider = ? AND u.username = ?
                  LIMIT 1',
                ['email', $identifier]
            );
        }

        /* Step 1: the account itself, always first. A pending registration
           can never stand in front of it. */
        $matches = auth_password_matches($identity === null ? null : $identity['password_hash'], $password);

        /* The rest of the fixed work. Release B checks the identifier's
           pending registrations here (at most 3); until then the same number
           of comparisons against the dummy. */
        for ($i = 1; $i < AUTH_SIGNIN_PASSWORD_CHECKS; $i++) {
            auth_password_matches(null, $password);
        }

        /* `credentials` marks the one failure that is a wrong guess, so the
           sign-in limit (includes/auth-throttle.php) counts that and nothing
           else. It never reaches a response. */
        if (!$matches) {
            return ['ok' => false, 'error' => t('Gebruikersnaam of wachtwoord klopt niet.'), 'credentials' => true];
        }

        if ($identity['status'] !== 'active') {
            return ['ok' => false, 'error' => t('Dit account is niet actief.')];
        }

        // Keep the stored hash current if the default algorithm moved on.
        if (password_needs_rehash($identity['password_hash'], PASSWORD_DEFAULT)) {
            db_run('UPDATE user_auth_identities SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $identity['id']]);
        }

        db_run('UPDATE user_auth_identities SET last_login_at = NOW() WHERE id = ?', [(int) $identity['id']]);

        return ['ok' => true, 'error' => null, 'user_id' => (int) $identity['user_id']];
    }

    /** Sign-in methods on an account, for the account panel. Never the hashes. */
    function auth_identities_for_user(int $userId): array
    {
        return db_all(
            'SELECT provider, email, email_verified_at, created_at, last_login_at
               FROM user_auth_identities
              WHERE user_id = ?
              ORDER BY created_at',
            [$userId]
        );
    }

    function auth_touch_last_seen(int $userId): void
    {
        db_run('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [$userId]);
    }
}
