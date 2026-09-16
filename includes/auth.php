<?php
/**
 * Authentication.
 *
 * An account is not defined by how you sign in: user_auth_identities holds one
 * row per method, so connecting Google to an existing account later is an
 * INSERT, not a migration.
 *
 * ---------------------------------------------------------------------------
 * APPLE AND GOOGLE ARE NOT WIRED UP
 * ---------------------------------------------------------------------------
 * The schema and the linking code below are ready for them, but no OAuth flow
 * is implemented and none is faked: auth_provider_available() returns false
 * for both, the buttons render disabled, and the endpoint answers with an
 * explicit "not configured". Implementing them means verifying the provider's
 * ID token server-side and calling auth_link_identity() with the verified
 * 'sub' claim — see docs/DATABASE.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

if (!function_exists('auth_provider_available')) {

    /** Only email/password is implemented. Nothing here pretends otherwise. */
    function auth_provider_available(string $provider): bool
    {
        return $provider === 'email' && db_available();
    }

    /* --------------------------------------------------------- validation */

    function auth_normalise_email(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    function auth_validate_email(string $email): ?string
    {
        $email = auth_normalise_email($email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            return 'Vul een geldig e-mailadres in.';
        }

        return null;
    }

    function auth_validate_password(string $password): ?string
    {
        if (mb_strlen($password) < 8) {
            return 'Kies een wachtwoord van minimaal 8 tekens.';
        }

        if (mb_strlen($password) > 200) {
            return 'Dit wachtwoord is te lang.';
        }

        return null;
    }

    /* ---------------------------------------------------------- register */

    /**
     * Creates an account with an email identity.
     * Returns ['ok' => bool, 'error' => ?string, 'user_id' => ?int].
     */
    function auth_register_email(string $email, string $password, string $username): array
    {
        require_once __DIR__ . '/user.php';

        $pdo = db();
        if ($pdo === null) {
            return ['ok' => false, 'error' => 'Geen databaseverbinding.'];
        }

        $error = auth_validate_email($email)
            ?? auth_validate_password($password)
            ?? user_validate_username($username);

        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $email = auth_normalise_email($email);

        $existing = db_value(
            'SELECT id FROM user_auth_identities WHERE provider = ? AND provider_subject = ?',
            ['email', $email]
        );

        if ($existing !== null) {
            return ['ok' => false, 'error' => 'Er bestaat al een account met dit e-mailadres.'];
        }

        if (user_username_taken($username)) {
            return ['ok' => false, 'error' => 'Deze gebruikersnaam is al bezet.'];
        }

        try {
            $pdo->beginTransaction();

            db_run('INSERT INTO users (username) VALUES (?)', [$username]);
            $userId = (int) $pdo->lastInsertId();

            db_run('INSERT INTO user_profiles (user_id) VALUES (?)', [$userId]);

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
            return ['ok' => false, 'error' => 'Dit account kon niet worden aangemaakt.'];
        }

        return ['ok' => true, 'error' => null, 'user_id' => $userId];
    }

    /* ------------------------------------------------------------- login */

    /**
     * Verifies an email/password pair.
     * The same message is returned for an unknown address and a wrong password,
     * so the response never reveals which addresses have accounts.
     */
    /**
     * Signs in with a password.
     *
     * The identifier is a username or the e-mail the account was created with:
     * a username is `^[A-Za-z0-9._-]+$` and an e-mail always has an `@`, so the
     * two can never collide and one lookup covers both. Everything else about
     * this function is unchanged — same hash, same generic error whether the
     * account is unknown or the password is wrong, same rehash on the way out.
     */
    function auth_login_password(string $identifier, string $password): array
    {
        if (!db_available()) {
            return ['ok' => false, 'error' => 'Geen databaseverbinding.'];
        }

        $identifier = trim($identifier);

        $identity = db_one(
            'SELECT i.id, i.user_id, i.password_hash, u.status
               FROM user_auth_identities i
               JOIN users u ON u.id = i.user_id
              WHERE i.provider = ?
                AND (i.provider_subject = ? OR u.username = ?)
              LIMIT 1',
            ['email', auth_normalise_email($identifier), $identifier]
        );

        $generic = ['ok' => false, 'error' => 'Gebruikersnaam of wachtwoord klopt niet.'];

        if ($identity === null || empty($identity['password_hash'])) {
            // Spend comparable time so the response cannot be used to probe.
            password_verify($password, '$2y$12$usesomesillystringforsalt0000000000000000000000000000000');
            return $generic;
        }

        if (!password_verify($password, $identity['password_hash'])) {
            return $generic;
        }

        if ($identity['status'] !== 'active') {
            return ['ok' => false, 'error' => 'Dit account is niet actief.'];
        }

        // Keep the stored hash current if the default algorithm moved on.
        if (password_needs_rehash($identity['password_hash'], PASSWORD_DEFAULT)) {
            db_run('UPDATE user_auth_identities SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $identity['id']]);
        }

        db_run('UPDATE user_auth_identities SET last_login_at = NOW() WHERE id = ?', [(int) $identity['id']]);

        return ['ok' => true, 'error' => null, 'user_id' => (int) $identity['user_id']];
    }

    /* ------------------------------------------------- provider identities */

    /**
     * Attaches a verified provider identity to an account.
     *
     * NOT called by anything yet. The caller must have verified the provider's
     * ID token server-side first — this function trusts its arguments, so
     * handing it an unverified 'sub' would be handing out accounts.
     */
    function auth_link_identity(int $userId, string $provider, string $subject, ?string $email = null): array
    {
        if (!in_array($provider, ['apple', 'google'], true)) {
            return ['ok' => false, 'error' => 'Onbekende aanbieder.'];
        }

        if (!db_available()) {
            return ['ok' => false, 'error' => 'Geen databaseverbinding.'];
        }

        $owner = db_value(
            'SELECT user_id FROM user_auth_identities WHERE provider = ? AND provider_subject = ?',
            [$provider, $subject]
        );

        if ($owner !== null && (int) $owner !== $userId) {
            return ['ok' => false, 'error' => 'Deze aanmelding hoort al bij een ander account.'];
        }

        db_run(
            'INSERT INTO user_auth_identities (user_id, provider, provider_subject, email)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE email = VALUES(email), last_login_at = NOW()',
            [$userId, $provider, $subject, $email]
        );

        return ['ok' => true, 'error' => null];
    }

    /** Sign-in methods on an account, for the account panel. Never the hashes. */
    function auth_identities_for_user(int $userId): array
    {
        return db_all(
            'SELECT provider, email, created_at, last_login_at
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
