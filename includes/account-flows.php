<?php
/**
 * Account security — Release B (35.0): everything that needs mail. The
 * approved plan (Phase 2, version 9) is docs/ACCOUNT-SECURITY.md; the section
 * numbers below are its.
 *
 *   links            §7   256-bit, base64url, stored as SHA-256 only, used once
 *                         and atomically, voided by a newer link of the same
 *                         purpose. Opening only reads; a tap confirms.
 *   registration     §3–4 a pending account (or, for a taken address, a
 *                         placeholder nobody can tell apart), at most 3 per
 *                         address under a per-address lock, deleted after 7 days
 *   verification     §7B  an existing account's address
 *   Wachtwoord vergeten   the same answer always; a reset or a decoy
 *   address change   §6   with the 72-hour revert link to the old address
 *   set password     §7   a Google account with a confirmed address
 *   Google           §7   link (web and native) and remove
 *   notices          §13  e-mails 5, 6a, 6b and 7
 *
 * Every flow puts exactly one row in the outbox (or a decoy) inside its own
 * transaction, and the mail is sent after the response (§11): nothing a
 * person waits for depends on SMTP, and the privacy-sensitive branches do the
 * same work.
 *
 * Off — every flow as Release A left it — until config/mail.php's
 * `account_mail` is switched on, mail is configured and migration 027 is
 * complete (account_mail_open()).
 */
declare(strict_types=1);

require_once __DIR__ . '/account-security.php';
require_once __DIR__ . '/auth-throttle.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail-templates.php';

if (!defined('ACCOUNT_PENDING_DAYS')) {
    define('ACCOUNT_PENDING_DAYS', 7);       // D3
    define('ACCOUNT_PENDING_MAX', 3);        // per address, real and placeholder together
    define('ACCOUNT_LINK_HOURS', [
        'verify_signup'  => 24,
        'verify_link'    => 24,
        'verify_change'  => 24,
        'password_reset' => 1,
        'revert_email'   => 72,
    ]);
}

if (!function_exists('account_link_url')) {

    /* ================================================================ LINKS */

    /** The link in an e-mail: the configured https address, never the request's Host. */
    function account_link_url(string $token): string
    {
        return rtrim((string) mail_settings()['public_url'], '/') . '/?account-link#' . $token;
    }

    /** Whether a string has the shape of a link token (43 base64url characters). */
    function account_link_token_shape(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /**
     * A new link for one purpose, voiding every open link of the account
     * with the same purpose first. Inside the caller's transaction.
     */
    function account_link_create(int $userId, string $purpose, string $email, ?int $changeId = null): string
    {
        $hours = ACCOUNT_LINK_HOURS[$purpose] ?? null;

        if ($hours === null) {
            throw new InvalidArgumentException('Unknown link purpose: ' . $purpose);
        }

        db_run(
            'UPDATE auth_email_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
            [$userId, $purpose]
        );

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        db_run(
            'INSERT INTO auth_email_tokens (user_id, purpose, email, change_id, token_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? HOUR)',
            [$userId, $purpose, $email, $changeId, hash('sha256', $token), $hours]
        );

        return $token;
    }

    /** An open link, without using it (opening a link only reads). */
    function account_link_find(string $token): ?array
    {
        if (!account_link_token_shape($token)) {
            return null;
        }

        return db_one(
            'SELECT t.id, t.user_id, t.purpose, t.email, t.change_id, t.created_at, t.expires_at,
                    u.username, u.status
               FROM auth_email_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()',
            [hash('sha256', $token)]
        );
    }

    /**
     * Uses a link, atomically: one affected row or nothing. Inside the
     * caller's transaction, so a rollback gives the link back.
     */
    function account_link_take(string $token, string $purpose): ?array
    {
        if (!account_link_token_shape($token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $used = db_run(
            'UPDATE auth_email_tokens SET used_at = NOW()
              WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()',
            [$hash, $purpose]
        );

        if ($used === null || $used->rowCount() !== 1) {
            return null;
        }

        return db_one(
            'SELECT t.id, t.user_id, t.purpose, t.email, t.change_id, u.username, u.status
               FROM auth_email_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ?',
            [$hash]
        );
    }

    /** Marks a link used outside any transaction (after a rollback that must not give it back). */
    function account_link_spend(string $token): void
    {
        if (account_link_token_shape($token)) {
            db_run('UPDATE auth_email_tokens SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL', [hash('sha256', $token)]);
        }
    }

    /* ================================================================= MAIL */

    /** The account's own language for a mail to it, else the request's. */
    function account_locale(int $userId): string
    {
        $chosen = function_exists('i18n_columns_present') && i18n_columns_present()
            ? db_value('SELECT locale FROM user_profiles WHERE user_id = ?', [$userId])
            : null;

        return i18n_valid($chosen) ?? i18n_locale();
    }

    /**
     * One account e-mail into the outbox, rendered now in $locale, inside the
     * caller's transaction; sent after the response (account_mail_send_later()).
     *
     * @param array<string, mixed> $facts
     */
    function account_mail_enqueue(string $kind, string $recipient, array $facts, int $validMinutes, string $locale): ?int
    {
        $message = mail_template($kind, $facts, $locale);
        $id      = mail_outbox_enqueue($kind, $recipient, $message, $validMinutes);

        if ($id !== null) {
            account_mail_send_later($id);
        }

        return $id;
    }

    /** A job that sends nothing, so a branch with nothing to send does the same work (§11). */
    function account_mail_decoy(): ?int
    {
        $id = mail_outbox_enqueue('none', null, [], 60);

        if ($id !== null) {
            account_mail_send_later($id);
        }

        return $id;
    }

    /**
     * After the response (§11): where PHP-FPM can finish the response first
     * (Hestia), this request then sends its own jobs and up to three others.
     * Without it (WAMP's Apache module, a test server) an API request sends
     * nothing itself: the next page request sends what is due after its
     * output, and tools/mail-outbox.php from cron sends the rest.
     */
    function account_mail_send_later(?int $id = null): void
    {
        account_mail_after_response(false, $id);
    }

    /**
     * Registers the one sender of this request, once. $page: a page request,
     * which may send after its output even without PHP-FPM.
     */
    function account_mail_after_response(bool $page, ?int $id = null): void
    {
        static $ids = [];
        static $registered = false;

        if ($id !== null) {
            $ids[] = $id;
        }

        $finish = function_exists('fastcgi_finish_request');

        if ($registered || (!$finish && !$page)) {
            return;
        }

        $registered = true;

        register_shutdown_function(static function () use (&$ids, $finish): void {
            /* The session first: written and unlocked now, so the person's
               next request never waits for SMTP behind this one. */
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if ($finish) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
            }

            try {
                if ($ids !== []) {
                    mail_outbox_run(3, $ids);
                }
                mail_outbox_run(3);

                /* The 7-day clean-up (D3) rides along now and then. */
                if (random_int(1, 20) === 1) {
                    account_pending_expire();
                }
            } catch (Throwable $e) {
                error_log('[ownify] mail after the response failed: ' . get_class($e));
            }
        });
    }

    /**
     * An address as the screens and e-mail 6a show somebody else's:
     * "s•••••••••@e••••••.com" — the first letter of each part and the ending.
     */
    function account_email_mask(string $address): string
    {
        $at = strrpos($address, '@');

        if ($at === false) {
            return '•••';
        }

        $local  = substr($address, 0, $at);
        $domain = substr($address, $at + 1);
        $dot    = strrpos($domain, '.');
        $name   = $dot === false ? $domain : substr($domain, 0, $dot);
        $end    = $dot === false ? '' : substr($domain, $dot);
        $dots   = static fn (string $part): string => $part === '' ? '' : $part[0] . str_repeat('•', max(1, strlen($part) - 1));

        return $dots($local) . '@' . $dots($name) . $end;
    }

    /** The account's confirmed sign-in address, where its security notices go; null when it has none. */
    function account_notice_address(int $userId): ?string
    {
        $address = db_value(
            "SELECT provider_subject FROM user_auth_identities
              WHERE user_id = ? AND provider = 'email' AND email_verified_at IS NOT NULL LIMIT 1",
            [$userId]
        );

        return is_string($address) ? $address : null;
    }

    /** Whether an address may not be claimed by $userId: another account signs in with it, has it verified, or it is contested. */
    function account_email_taken_by_other(string $canonical, int $userId): bool
    {
        if (db_value(
            "SELECT user_id FROM user_auth_identities WHERE provider = 'email' AND provider_subject = ? AND user_id <> ?",
            [$canonical, $userId]
        ) !== null) {
            return true;
        }

        if (db_value('SELECT user_id FROM account_emails WHERE email = ? AND user_id <> ?', [$canonical, $userId]) !== null) {
            return true;
        }

        return db_value('SELECT email FROM account_email_conflicts WHERE email = ? AND resolved_at IS NULL', [$canonical]) !== null;
    }

    /** account_emails: this address is the account's, via its sign-in (via_password) or its Google (via_google). */
    function account_emails_claim(int $userId, string $canonical, string $via): void
    {
        $column = $via === 'google' ? 'via_google' : 'via_password';

        $own = db_value('SELECT user_id FROM account_emails WHERE email = ?', [$canonical]);

        if ($own !== null && (int) $own === $userId) {
            db_run("UPDATE account_emails SET $column = 1 WHERE email = ?", [$canonical]);
            return;
        }

        /* Another account's: the primary key refuses it and the caller's
           transaction rolls back (a plain INSERT, never IGNORE). */
        db_run("INSERT INTO account_emails (email, user_id, $column) VALUES (?, ?, 1)", [$canonical, $userId]);
    }

    /** account_emails: this address is no longer the account's via $via; the row goes when neither is left. */
    function account_emails_release(int $userId, string $canonical, string $via): void
    {
        $column = $via === 'google' ? 'via_google' : 'via_password';
        $other  = $via === 'google' ? 'via_password' : 'via_google';

        db_run("DELETE FROM account_emails WHERE email = ? AND user_id = ? AND $other = 0", [$canonical, $userId]);
        db_run("UPDATE account_emails SET $column = 0 WHERE email = ? AND user_id = ?", [$canonical, $userId]);
    }

    /** The neutral answer every limit and refusal of a registration gives, free or taken alike (§3, §12). */
    function account_try_later(): array
    {
        return ['ok' => false, 'error' => t('Dit kan even niet. Probeer het later opnieuw.'), 'status' => 429];
    }

    /**
     * The limits on mail one account (or one waiting registration) asks for
     * itself (§12): one a minute, 3 an hour, 10 a day. $who is 'u<id>' for an
     * account, 'p<id>' for a registration — the registrant's own, so the wait
     * may be said. Counts this request when there is room.
     *
     * @return array{ok: false, error: string, status: int, retry_after: int}|null
     */
    function account_mail_limit(string $who): ?array
    {
        $wait = auth_throttle_wait('acct_mail_m', $who, 1, 60, false)
            ?? auth_throttle_wait('acct_mail_h', $who, 3, 3600, false)
            ?? auth_throttle_wait('acct_mail_d', $who, 10, 86400, false);

        if ($wait !== null) {
            return ['ok' => false, 'error' => account_mail_wait_message($wait), 'status' => 429, 'retry_after' => $wait];
        }

        foreach (['acct_mail_m', 'acct_mail_h', 'acct_mail_d'] as $action) {
            auth_throttle_fail($action, $who, false);
        }

        return null;
    }

    /** "Je kunt over 40 seconden opnieuw een e-mail laten sturen." */
    function account_mail_wait_message(int $seconds): string
    {
        if ($seconds < 60) {
            return tn($seconds, 'Je kunt over %d seconde opnieuw een e-mail laten sturen.',
                'Je kunt over %d seconden opnieuw een e-mail laten sturen.');
        }

        $minutes = (int) ceil($seconds / 60);

        return tn($minutes, 'Je kunt over %d minuut opnieuw een e-mail laten sturen.',
            'Je kunt over %d minuten opnieuw een e-mail laten sturen.');
    }

    /* ========================================================= REGISTRATION */

    /** The per-address lock's name: always 63 ASCII bytes (§3). */
    function account_pending_lock_name(string $canonical): string
    {
        return 'ownify:pending:' . substr(hash('sha256', $canonical), 0, 48);
    }

    /** Deletes registrations whose 7 days have passed (D3): the users row, and with it everything (cascade). */
    function account_pending_expire(?string $canonical = null): int
    {
        if (!account_security_schema()['pending']) {
            return 0;
        }

        $done = db_run(
            "DELETE u FROM users u
               JOIN pending_registrations p ON p.user_id = u.id
              WHERE u.status = 'pending' AND p.expires_at <= NOW()" . ($canonical === null ? '' : ' AND p.email = ?'),
            $canonical === null ? [] : [$canonical]
        );

        return $done === null ? 0 : $done->rowCount();
    }

    /**
     * A new e-mail registration (§3–4). Free address: a pending account and
     * e-mail 1. Taken address: a placeholder and e-mail 7 to the owner (at
     * most once a day; otherwise a decoy). Both look the same from outside:
     * the same answer, one outbox row, the same password checks at sign-in.
     * Nothing is created at the limits; the answer is then the neutral one.
     *
     * @return array{ok: bool, user_id?: int, email?: string, error?: string, status?: int}
     */
    function account_pending_register(string $email, string $password, string $username, string $repeat): array
    {
        require_once __DIR__ . '/user.php';

        $pdo = db();

        if ($pdo === null) {
            return ['ok' => false, 'error' => t('Geen databaseverbinding.'), 'status' => 503];
        }

        $username  = trim($username);
        $canonical = account_email_canonical($email);

        $error = auth_validate_email($email)
            ?? user_validate_username($username)
            ?? account_password_problem($password, $repeat, $username, $canonical === null ? [] : [$canonical]);

        if ($error !== null) {
            return ['ok' => false, 'error' => $error, 'status' => 422];
        }

        /* A taken username is said outright: usernames are public in friend search. */
        if (user_username_taken($username)) {
            return ['ok' => false, 'error' => t('Deze gebruikersnaam is al bezet.'), 'status' => 422];
        }

        $email = (string) $canonical;

        /* Per recipient, from every network address together, before the
           free/taken branch and the same for both (§12). */
        if (auth_throttle_attempt('reg_to_h', $email, 3, 3600, false) !== null
            || auth_throttle_attempt('reg_to_d', $email, 6, 86400, false) !== null) {
            return account_try_later();
        }

        $lock = account_pending_lock_name($email);
        $held = (int) db_value('SELECT GET_LOCK(?, 5)', [$lock]) === 1;

        if (!$held) {
            return account_try_later();
        }

        try {
            $pdo->beginTransaction();

            account_pending_expire($email);

            $count = (int) db_value('SELECT COUNT(*) FROM pending_registrations WHERE email = ?', [$email]);

            if ($count >= ACCOUNT_PENDING_MAX) {
                $pdo->rollBack();

                return account_try_later();
            }

            $owner = db_value(
                "SELECT user_id FROM user_auth_identities WHERE provider = 'email' AND provider_subject = ?",
                [$email]
            ) ?? db_value('SELECT user_id FROM account_emails WHERE email = ?', [$email]);

            $taken = $owner !== null || account_email_claimed($email);

            db_run("INSERT INTO users (username, status) VALUES (?, 'pending')", [$username]);
            $userId = (int) $pdo->lastInsertId();

            db_run('INSERT INTO user_profiles (user_id) VALUES (?)', [$userId]);

            if (is_file(__DIR__ . '/setup.php')) {
                require_once __DIR__ . '/setup.php';
                setup_begin($userId);
            }

            db_run(
                'INSERT INTO pending_registrations (user_id, email, password_hash, address_taken, expires_at)
                 VALUES (?, ?, ?, ?, NOW() + INTERVAL ? DAY)',
                [$userId, $email, password_hash($password, PASSWORD_DEFAULT), $taken ? 1 : 0, ACCOUNT_PENDING_DAYS]
            );

            if (!$taken) {
                $token = account_link_create($userId, 'verify_signup', $email);
                account_mail_enqueue('signup', $email, ['username' => $username, 'email' => $email, 'link' => account_link_url($token)],
                    ACCOUNT_LINK_HOURS['verify_signup'] * 60, i18n_locale());
            } elseif ($owner !== null && auth_throttle_attempt('already_d', $email, 1, 86400, false) === null) {
                /* E-mail 7, to the address's own account, in its language. */
                $hasPassword = (string) (db_value(
                    "SELECT password_hash FROM user_auth_identities WHERE user_id = ? AND provider = 'email' LIMIT 1",
                    [(int) $owner]
                ) ?? '') !== '';
                account_mail_enqueue('already', $email, [
                    'email' => $email, 'when' => new DateTimeImmutable(), 'google_only' => !$hasPassword,
                ], 7 * 24 * 60, account_locale((int) $owner));
            } else {
                account_mail_decoy();
            }

            $pdo->commit();

            /* The first mail counts as the registrant's own: "Stuur opnieuw"
               waits its minute, the same for a placeholder. */
            foreach (['acct_mail_m', 'acct_mail_h', 'acct_mail_d'] as $action) {
                auth_throttle_fail($action, 'p' . $userId, false);
            }

            return ['ok' => true, 'user_id' => $userId, 'email' => $email, 'resend_after' => 60];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $ignored) {
                    error_log('[ownify] registration rollback failed');
                }
            }

            /* A race on the username lands here; anything else is logged by
               its kind only. */
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
                return ['ok' => false, 'error' => t('Deze gebruikersnaam is al bezet.'), 'status' => 422];
            }

            error_log('[ownify] registration failed: ' . get_class($e));

            return ['ok' => false, 'error' => t('Dit account kon niet worden aangemaakt.'), 'status' => 500];
        } finally {
            /* COMMIT and ROLLBACK do not release a GET_LOCK (§3). */
            if (db_value('SELECT RELEASE_LOCK(?)', [$lock]) === null) {
                error_log('[ownify] pending lock release returned NULL');
            }
        }
    }

    /** A pending registration (or placeholder) by its users row. */
    function account_pending_row(int $userId): ?array
    {
        if (!account_security_schema()['pending']) {
            return null;
        }

        return db_one(
            "SELECT p.user_id, p.email, p.address_taken, p.expires_at, u.username
               FROM pending_registrations p JOIN users u ON u.id = p.user_id
              WHERE p.user_id = ? AND u.status = 'pending' AND p.expires_at > NOW()",
            [$userId]
        );
    }

    /**
     * What the verification screen shows: the address, masked. Null when the
     * registration is gone (verified, expired or cancelled).
     *
     * @return array{email_masked: string, username: string}|null
     */
    function account_pending_view(int $userId): ?array
    {
        $row = account_pending_row($userId);

        return $row === null ? null : ['email_masked' => account_email_mask((string) $row['email']), 'username' => (string) $row['username']];
    }

    /**
     * "Stuur opnieuw" (§3–4): a new link and e-mail 1 for a real registration;
     * for a placeholder nothing is sent. Both answer the same, from the same
     * limiters, and a refused resend voids nothing.
     *
     * @return array{ok: bool, error?: string, status?: int}
     */
    function account_pending_resend(int $userId): array
    {
        $row = account_pending_row($userId);

        if ($row === null) {
            return ['ok' => false, 'error' => t('Deze registratie bestaat niet meer. Begin opnieuw.'), 'status' => 410, 'gone' => true];
        }

        $email = (string) $row['email'];

        $limited = account_mail_limit('p' . $userId);

        if ($limited !== null) {
            return $limited;
        }

        /* Per recipient, as a registration (§12): the neutral answer, no wait. */
        if (auth_throttle_attempt('reg_to_h', $email, 3, 3600, false) !== null
            || auth_throttle_attempt('reg_to_d', $email, 6, 86400, false) !== null) {
            return account_try_later();
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            if ((int) $row['address_taken'] === 0) {
                $token = account_link_create($userId, 'verify_signup', $email);
                account_mail_enqueue('signup', $email, ['username' => (string) $row['username'], 'email' => $email, 'link' => account_link_url($token)],
                    ACCOUNT_LINK_HOURS['verify_signup'] * 60, i18n_locale());
            } else {
                account_mail_decoy();
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] resend failed: ' . get_class($e));

            return ['ok' => false, 'error' => t('Dit kan even niet. Probeer het later opnieuw.'), 'status' => 500];
        }

        return ['ok' => true, 'resend_after' => 60];
    }

    /**
     * This browser — or this app conversation (its cookie jar) — started the
     * registration, or signed in to it: what the verification screen and the
     * link need to know (§3). A new session id first.
     */
    function account_pending_hold(int $userId): void
    {
        session_boot();
        session_regenerate_id(true);

        $_SESSION['pending_registration'] = [
            'user_id'  => $userId,
            'username' => (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]),
            'since'    => time(),
        ];
    }

    /** The registration this session holds, or null. */
    function account_pending_held(): ?array
    {
        session_boot();

        $hold = $_SESSION['pending_registration'] ?? null;

        if (!is_array($hold) || !is_int($hold['user_id'] ?? null) || !is_string($hold['username'] ?? null)
            || !is_int($hold['since'] ?? null) || $hold['since'] < time() - ACCOUNT_PENDING_DAYS * 86400) {
            unset($_SESSION['pending_registration']);

            return null;
        }

        return $hold;
    }

    function account_pending_release(): void
    {
        session_boot();
        unset($_SESSION['pending_registration']);
    }

    /**
     * Where the held registration is now: 'pending' (still waiting — a
     * placeholder always is), 'active' (its link was used: this session may
     * sign in) or 'gone' (cancelled, expired, or not this registration).
     */
    function account_pending_state(array $hold): string
    {
        $row = db_one('SELECT username, status FROM users WHERE id = ?', [(int) $hold['user_id']]);

        if ($row === null || !hash_equals((string) $row['username'], (string) $hold['username'])) {
            return 'gone';
        }

        if ($row['status'] === 'active') {
            return 'active';
        }

        return $row['status'] === 'pending' && account_pending_row((int) $hold['user_id']) !== null ? 'pending' : 'gone';
    }

    /**
     * The verification screen's facts for a waiting registration, or null:
     * the masked address, and the username for the screen's line.
     *
     * @return array{email_masked: string, username: string}|null
     */
    function account_pending_screen(): ?array
    {
        $hold = account_pending_held();

        if ($hold === null || account_pending_state($hold) !== 'pending') {
            return null;
        }

        return account_pending_view($hold['user_id']);
    }

    /** "Ander adres gebruiken" (D2): the pending row goes, and with it its links (cascade). */
    function account_pending_cancel(int $userId): void
    {
        if (account_security_schema()['pending']) {
            db_run("DELETE FROM users WHERE id = ? AND status = 'pending'", [$userId]);
        }
    }

    /* ======================================================== VERIFICATION */

    /**
     * Settings › Inloggen › "Stuur bevestiging": e-mail 1 to the account's
     * own unconfirmed sign-in address (§7B). Limited per account and per
     * inbox; at the inbox limit a decoy, the same answer.
     *
     * @return array{ok: bool, error?: string, status?: int, sent_to?: string}
     */
    function account_verify_request(int $userId): array
    {
        $identity = db_one(
            "SELECT provider_subject, email_verified_at FROM user_auth_identities WHERE user_id = ? AND provider = 'email' LIMIT 1",
            [$userId]
        );

        if ($identity === null) {
            return ['ok' => false, 'error' => t('Je account heeft nog geen e-mailadres.'), 'status' => 409];
        }

        if ($identity['email_verified_at'] !== null) {
            return ['ok' => false, 'error' => t('Je e-mailadres is al bevestigd.'), 'status' => 409];
        }

        $email = (string) $identity['provider_subject'];

        /* Another account's confirmed address, or contested: it can never be
           confirmed for this one (§5a). Said to the owner of the account. */
        if (account_email_taken_by_other($email, $userId)) {
            return ['ok' => false, 'error' => t('Dit e-mailadres hoort al bij een ander Ownify-account. Kies een ander adres via Wijzigen.'), 'status' => 409];
        }

        $limited = account_mail_limit('u' . $userId);

        if ($limited !== null) {
            return $limited;
        }

        $inboxFull = auth_throttle_attempt('inbox_h', $email, 3, 3600, false) !== null
            || auth_throttle_attempt('inbox_d', $email, 6, 86400, false) !== null;

        $pdo      = db();
        $username = (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]);

        try {
            $pdo->beginTransaction();

            if ($inboxFull) {
                account_mail_decoy();
            } else {
                $token = account_link_create($userId, 'verify_signup', $email);
                account_mail_enqueue('signup', $email, ['username' => $username, 'email' => $email, 'link' => account_link_url($token), 'existing' => true],
                    ACCOUNT_LINK_HOURS['verify_signup'] * 60, i18n_locale());
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] verification request failed: ' . get_class($e));

            return ['ok' => false, 'error' => t('Dit kan even niet. Probeer het later opnieuw.'), 'status' => 500];
        }

        return ['ok' => true, 'sent_to' => $email, 'resend_after' => 60];
    }

    /* ============================================================ FORGOTTEN */

    /** Wachtwoord vergeten's one answer (§7B), whatever the address. */
    function account_forgot_answer(): string
    {
        return t('Als dit adres bij een Ownify-account met een wachtwoord hoort, krijg je binnen een paar minuten een e-mail met een link. Log je in met Google? Kies dan Doorgaan met Google.');
    }

    /**
     * Wachtwoord vergeten (§7B): the same answer, always, and exactly one
     * outbox row: the reset (e-mail 4, 1 hour) for a password account on that
     * address — confirmed or not — or a decoy. Never a reset for an account
     * without a password, or for an address that is another account's
     * confirmed address or contested (§5a).
     *
     * @return array{ok: bool, error?: string, status?: int, retry_after?: int}
     */
    function account_forgot_password(string $address): array
    {
        $canonical = account_email_canonical($address);

        if ($canonical === null) {
            return ['ok' => false, 'error' => t('Vul een geldig e-mailadres in.'), 'status' => 422];
        }

        /* Per address from here, and per network address: these reveal nothing. */
        $wait = auth_throttle_attempt('forgot_ip', '', 10, 3600)
            ?? auth_throttle_attempt('forgot', $canonical, 3, 900);

        if ($wait !== null) {
            return ['ok' => false, 'error' => auth_throttle_message($wait), 'status' => 429, 'retry_after' => $wait];
        }

        $identity = db_one(
            "SELECT i.user_id, i.password_hash, u.username
               FROM user_auth_identities i JOIN users u ON u.id = i.user_id
              WHERE i.provider = 'email' AND i.provider_subject = ? AND u.status = 'active' LIMIT 1",
            [$canonical]
        );

        $send = $identity !== null
            && (string) ($identity['password_hash'] ?? '') !== ''
            && !account_email_taken_by_other($canonical, (int) $identity['user_id']);

        /* Per recipient, from every network address: at the cap a decoy, the same answer. */
        if ($send && (auth_throttle_attempt('forgot_to_h', $canonical, 3, 3600, false) !== null
            || auth_throttle_attempt('forgot_to_d', $canonical, 5, 86400, false) !== null)) {
            $send = false;
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();

            if ($send) {
                $token = account_link_create((int) $identity['user_id'], 'password_reset', $canonical);
                account_mail_enqueue('reset', $canonical, ['username' => (string) $identity['username'], 'link' => account_link_url($token)],
                    ACCOUNT_LINK_HOURS['password_reset'] * 60, i18n_locale());
            } else {
                account_mail_decoy();
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] password reset request failed: ' . get_class($e));
        }

        return ['ok' => true];
    }

    /* ============================================================ ADDRESSES */

    /**
     * Changing the sign-in address (§6), or — an account that signs in with
     * Google only — adding one (verify_link). After the proof (the current
     * password, or Google): the new address must be free now and is checked
     * again when its link is used. Nothing about the old address changes
     * until then.
     *
     * @param array<string, mixed> $proof the request: password, or id_token (app)
     * @return array{ok: bool, error?: string, status?: int, reauth?: string, sent_to?: string}
     */
    function account_email_change_request(int $userId, string $newEmail, array $proof, ?int $deviceId): array
    {
        $canonical = account_email_canonical($newEmail);

        if ($canonical === null) {
            return ['ok' => false, 'error' => t('Vul een geldig e-mailadres in.'), 'status' => 422];
        }

        $identity = db_one(
            "SELECT provider_subject, email_verified_at FROM user_auth_identities WHERE user_id = ? AND provider = 'email' LIMIT 1",
            [$userId]
        );

        if ($identity !== null && (string) $identity['provider_subject'] === $canonical) {
            return ['ok' => false, 'error' => t('Dit is al je e-mailadres.'), 'status' => 422];
        }

        $refused = account_require_proof($userId, $identity === null ? 'add_email' : 'change_email', $proof, $deviceId);

        if ($refused !== null) {
            return ['ok' => false] + $refused;
        }

        if (account_email_taken_by_other($canonical, $userId)) {
            return ['ok' => false, 'error' => t('Dit e-mailadres hoort al bij een ander Ownify-account.'), 'status' => 409];
        }

        $limited = account_mail_limit('u' . $userId);

        if ($limited !== null) {
            return $limited;
        }

        $inboxFull = auth_throttle_attempt('inbox_h', $canonical, 3, 3600, false) !== null
            || auth_throttle_attempt('inbox_d', $canonical, 6, 86400, false) !== null;

        $pdo      = db();
        $username = (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]);

        try {
            $pdo->beginTransaction();
            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            if ($identity === null) {
                $token = account_link_create($userId, 'verify_link', $canonical);
                $inboxFull ? account_mail_decoy() : account_mail_enqueue('link', $canonical,
                    ['username' => $username, 'email' => $canonical, 'link' => account_link_url($token)],
                    ACCOUNT_LINK_HOURS['verify_link'] * 60, i18n_locale());
            } else {
                /* Earlier unconfirmed changes are voided (§6). */
                db_run(
                    "UPDATE auth_email_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = 'verify_change' AND used_at IS NULL",
                    [$userId]
                );

                db_run(
                    'INSERT INTO email_changes (user_id, old_email, old_verified, new_email) VALUES (?, ?, ?, ?)',
                    [$userId, (string) $identity['provider_subject'], $identity['email_verified_at'] !== null ? 1 : 0, $canonical]
                );
                $changeId = (int) $pdo->lastInsertId();

                $token = account_link_create($userId, 'verify_change', $canonical, $changeId);
                $inboxFull ? account_mail_decoy() : account_mail_enqueue('change', $canonical,
                    ['username' => $username, 'new_email' => $canonical, 'link' => account_link_url($token)],
                    ACCOUNT_LINK_HOURS['verify_change'] * 60, i18n_locale());
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] address change request failed: ' . get_class($e));

            return ['ok' => false, 'error' => t('Dit kan even niet. Probeer het later opnieuw.'), 'status' => 500];
        }

        return ['ok' => true, 'sent_to' => $canonical, 'resend_after' => 60];
    }

    /* ============================================================ PASSWORDS */

    /**
     * A Google account sets its first password (§7): after Google, and only
     * with a confirmed address to sign in with. Nobody is signed out.
     *
     * @param array<string, mixed> $proof
     * @return array{ok: bool, error?: string, status?: int, reauth?: string}
     */
    function account_set_password(int $userId, string $password, string $repeat, array $proof, ?int $deviceId): array
    {
        $identity = db_one(
            "SELECT id, provider_subject, email_verified_at, password_hash FROM user_auth_identities
              WHERE user_id = ? AND provider = 'email' LIMIT 1",
            [$userId]
        );

        if ($identity === null || $identity['email_verified_at'] === null) {
            return ['ok' => false, 'error' => t('Voeg eerst een e-mailadres toe en bevestig het.'), 'status' => 409];
        }

        if ((string) ($identity['password_hash'] ?? '') !== '') {
            return ['ok' => false, 'error' => t('Je account heeft al een wachtwoord.'), 'status' => 409];
        }

        $username = (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]);
        $problem  = account_password_problem($password, $repeat, $username, account_addresses($userId));

        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem, 'status' => 422];
        }

        $refused = account_require_proof($userId, 'set_password', $proof, $deviceId);

        if ($refused !== null) {
            return ['ok' => false] + $refused;
        }

        $pdo     = db();
        $address = (string) $identity['provider_subject'];

        try {
            $pdo->beginTransaction();
            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            $set = db_run(
                "UPDATE user_auth_identities SET password_hash = ?
                  WHERE id = ? AND provider = 'email' AND (password_hash IS NULL OR password_hash = '')",
                [password_hash($password, PASSWORD_DEFAULT), (int) $identity['id']]
            );

            if ($set === null || $set->rowCount() !== 1) {
                $pdo->rollBack();

                return ['ok' => false, 'error' => t('Je account heeft al een wachtwoord.'), 'status' => 409];
            }

            account_mail_enqueue('password', $address, [
                'username' => $username, 'set' => true, 'when' => new DateTimeImmutable(), 'via' => $deviceId === null ? 'web' : 'app',
            ], 7 * 24 * 60, i18n_locale());

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] set password failed: ' . get_class($e));

            return ['ok' => false, 'error' => t('Je wachtwoord kon niet worden ingesteld. Probeer het opnieuw.'), 'status' => 500];
        }

        return ['ok' => true];
    }

    /** E-mail 5 after a password change (Release A's change, §7A), to the confirmed address. Inside the change's transaction. */
    function account_notice_password_changed(int $userId, ?int $deviceId): void
    {
        if (!account_mail_open()) {
            return;
        }

        $address = account_notice_address($userId);

        if ($address === null) {
            return;
        }

        account_mail_enqueue('password', $address, [
            'username' => (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]),
            'when' => new DateTimeImmutable(), 'via' => $deviceId === null ? 'web' : 'app',
        ], 7 * 24 * 60, account_locale($userId));
    }

    /* =============================================================== GOOGLE */

    /**
     * Whether this account may link Google now (§5, §7): no Google yet, a
     * password, and its own address confirmed.
     */
    function account_google_link_allowed(int $userId): bool
    {
        if (!account_mail_open() || account_google_sub($userId) !== null) {
            return false;
        }

        $state = account_signin_state($userId);

        return $state['password'] && $state['email_verified'];
    }

    /**
     * Links a verified Google identity to the signed-in account (web and
     * app alike), under the account lock: the Google account must be nobody
     * else's, and its confirmed address must not be another account's.
     * Google's address becomes this account's too (account_emails, via_google)
     * when it is free.
     *
     * @return array{ok: bool, outcome: string, message: string}
     */
    function account_google_link(int $userId, string $sub, ?string $email): array
    {
        $fail = static fn (string $message): array => ['ok' => false, 'outcome' => 'error', 'message' => $message];

        if (!account_google_link_allowed($userId)) {
            return $fail(t('Bevestig eerst je e-mailadres.'));
        }

        $owner = db_value("SELECT user_id FROM user_auth_identities WHERE provider = 'google' AND provider_subject = ?", [$sub]);

        if ($owner !== null) {
            return (int) $owner === $userId
                ? ['ok' => true, 'outcome' => 'linked', 'message' => t('Dit Google-account was al aan je account gekoppeld.')]
                : $fail(t('Dit Google-account hoort al bij een ander Ownify-account.'));
        }

        $canonical = $email === null ? null : account_email_canonical($email);

        if ($canonical !== null && account_email_taken_by_other($canonical, $userId)) {
            return $fail(t('Het e-mailadres van dit Google-account hoort al bij een ander Ownify-account. Koppel een ander Google-account.'));
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();
            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            if (db_value("SELECT id FROM user_auth_identities WHERE user_id = ? AND provider = 'google'", [$userId]) !== null) {
                $pdo->rollBack();

                return $fail(t('Je account is al aan een ander Google-account gekoppeld.'));
            }

            db_run(
                "INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, email_verified_at)
                 VALUES (?, 'google', ?, ?, ?)",
                [$userId, $sub, $canonical, $canonical === null ? null : date('Y-m-d H:i:s')]
            );

            if ($canonical !== null) {
                account_emails_claim($userId, $canonical, 'google');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            /* A race on a unique key: the Google account or its address. */
            return $fail(t('Dit Google-account hoort al bij een ander Ownify-account.'));
        }

        return ['ok' => true, 'outcome' => 'linked', 'message' => t('Google is gekoppeld. Je kunt voortaan ook met Google inloggen.')];
    }

    /**
     * The app's native link, step 1 (§7): the current password, then a nonce
     * for Credential Manager, kept in this conversation's session (the app's
     * cookie jar) and tied to the account and the app token asking.
     *
     * @return array{ok: bool, nonce?: string, error?: string, status?: int, reauth?: string, retry_after?: int}
     */
    function account_google_link_start_native(int $userId, int $deviceId, string $password): array
    {
        require_once __DIR__ . '/google-signin.php';

        if (!account_google_link_allowed($userId)) {
            return ['ok' => false, 'error' => t('Bevestig eerst je e-mailadres.'), 'status' => 409];
        }

        if (!google_signin_native_configured()) {
            return ['ok' => false, 'error' => t('Google koppelen is in de app nog niet beschikbaar.'), 'status' => 501];
        }

        $refused = account_require_proof($userId, 'link_google', ['password' => $password], $deviceId);

        if ($refused !== null) {
            return ['ok' => false] + $refused;
        }

        session_boot();
        $nonce = google_signin_b64url_encode(random_bytes(32));
        $_SESSION['account_link_nonce'] = ['nonce' => $nonce, 'user_id' => $userId, 'device_id' => $deviceId, 'started' => time()];

        return ['ok' => true, 'nonce' => $nonce];
    }

    /**
     * The app's native link, step 2: the ID token Credential Manager gave
     * back for that nonce — checked like a sign-in, then linked.
     *
     * @return array{ok: bool, outcome: string, message: string, status: int}
     */
    function account_google_link_native(int $userId, int $deviceId, string $idToken): array
    {
        require_once __DIR__ . '/google-signin.php';

        session_boot();
        $flow = $_SESSION['account_link_nonce'] ?? null;
        unset($_SESSION['account_link_nonce']);              // works once, whatever happens next

        $fail = t('Google koppelen is niet gelukt. Probeer het opnieuw.');

        if (!is_array($flow) || ($flow['user_id'] ?? null) !== $userId || ($flow['device_id'] ?? null) !== $deviceId
            || !is_int($flow['started'] ?? null) || time() - $flow['started'] > ACCOUNT_REAUTH_SECONDS
            || !is_string($flow['nonce'] ?? null)) {
            return ['ok' => false, 'outcome' => 'error', 'message' => t('Deze koppeling is verlopen. Begin opnieuw.'), 'status' => 409];
        }

        if ($idToken === '' || strlen($idToken) > 8192 || !google_signin_native_configured()) {
            return ['ok' => false, 'outcome' => 'error', 'message' => $fail, 'status' => 400];
        }

        $keys = google_signin_keys();

        if ($keys === null) {
            return ['ok' => false, 'outcome' => 'error', 'message' => t('Google kon je aanmelding niet bevestigen. Probeer het opnieuw.'), 'status' => 502];
        }

        $config   = google_signin_config();
        $verified = google_signin_verify($idToken, $keys, $config['client_id'], $flow['nonce'], null, $config['android_client_ids']);

        if (!$verified['ok']) {
            error_log('[google-signin] app link token refused: ' . $verified['error']);

            return ['ok' => false, 'outcome' => 'error', 'message' => $fail, 'status' => 403];
        }

        $result = account_google_link($userId, (string) $verified['claims']['sub'], google_signin_verified_email($verified['claims']));

        return $result + ['status' => $result['ok'] ? 200 : 409];
    }

    /**
     * Removes Google (§7): only with a password and a confirmed address, and
     * after the current password. The Google identity goes, its address stays
     * only where the sign-in has it too; every other session ends; e-mail 6b.
     *
     * @return array{ok: bool, error?: string, status?: int, reauth?: string}
     */
    function account_google_remove(int $userId, string $password, ?int $deviceId): array
    {
        $state = account_signin_state($userId);

        if (!$state['google']) {
            return ['ok' => false, 'error' => t('Je account is niet aan Google gekoppeld.'), 'status' => 409];
        }

        if (!$state['password'] || !$state['email_verified']) {
            return ['ok' => false, 'error' => t('Stel eerst een wachtwoord in en bevestig je e-mailadres.'), 'status' => 409];
        }

        if (!account_security_schema()['session_generation']) {
            return ['ok' => false, 'error' => t('Dit kan even niet. Probeer het later opnieuw.'), 'status' => 503];
        }

        $refused = account_require_proof($userId, 'remove_google', ['password' => $password], $deviceId);

        if ($refused !== null) {
            return ['ok' => false] + $refused;
        }

        $keepSelector = null;
        if ($deviceId === null && is_array($_SESSION['persistent_login'] ?? null)) {
            $keepSelector = (string) $_SESSION['persistent_login']['selector'];
        }

        $pdo = db();

        try {
            $pdo->beginTransaction();
            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            $google = db_one("SELECT id, email FROM user_auth_identities WHERE user_id = ? AND provider = 'google' FOR UPDATE", [$userId]);

            if ($google === null) {
                $pdo->rollBack();

                return ['ok' => false, 'error' => t('Je account is niet aan Google gekoppeld.'), 'status' => 409];
            }

            db_run('DELETE FROM user_auth_identities WHERE id = ?', [(int) $google['id']]);

            if ($google['email'] !== null) {
                account_emails_release($userId, (string) $google['email'], 'google');
            }

            $generation = account_end_other_sessions($userId, $keepSelector, $deviceId);

            $address = account_notice_address($userId);
            if ($address !== null) {
                account_mail_enqueue('google_removed', $address, [
                    'username' => $state['username'], 'google_email' => (string) ($google['email'] ?? ''),
                    'when' => new DateTimeImmutable(), 'via' => $deviceId === null ? 'web' : 'app',
                ], 7 * 24 * 60, i18n_locale());
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ownify] Google removal failed: ' . get_class($e));

            return ['ok' => false, 'error' => t('Google kon niet worden losgekoppeld. Probeer het opnieuw.'), 'status' => 500];
        }

        /* The session that removed it stays, on a new id and the new number (§9). */
        if ($deviceId === null && current_user_id() === $userId) {
            session_regenerate_id(true);
            $_SESSION['session_generation'] = $generation;
        }

        return ['ok' => true];
    }

    /* =============================================================== THE LINK */

    /**
     * What an opened link is about, without using it — for the page that
     * asks for the tap (or the new password). Null: expired, used or unknown.
     *
     * @return array{purpose: string, needs: string, email: string, username: string, old_email?: string, changed_at?: string}|null
     */
    function account_link_view(string $token): ?array
    {
        $link = account_link_find($token);

        if ($link === null) {
            return null;
        }

        $purpose = (string) $link['purpose'];
        $view    = [
            'purpose'  => $purpose,
            'needs'    => in_array($purpose, ['password_reset', 'revert_email'], true) ? 'password' : 'confirm',
            'email'    => (string) $link['email'],
            'username' => (string) $link['username'],
        ];

        if ($purpose === 'revert_email' && $link['change_id'] !== null) {
            $change = db_one('SELECT old_email, changed_at FROM email_changes WHERE id = ?', [(int) $link['change_id']]);
            if ($change !== null) {
                $view['old_email']  = (string) $change['old_email'];
                $view['changed_at'] = $change['changed_at'] === null ? '' : mail_tpl_when(new DateTimeImmutable((string) $change['changed_at']));
            }
        }

        return $view;
    }

    /**
     * Uses a link — the tap, or the new password and its repeat. A wrong new
     * password is said before anything is used, so it can be corrected.
     *
     * @return array{ok: bool, message: string, status?: int, signed_in_user?: int, signed_out?: bool, error?: string}
     */
    function account_link_use(string $token, string $password = '', string $repeat = ''): array
    {
        $gone = ['ok' => false, 'message' => t('Deze link is verlopen of al gebruikt.'), 'error' => t('Deze link is verlopen of al gebruikt.'), 'status' => 410];
        $link = account_link_find($token);

        if ($link === null) {
            return $gone;
        }

        return match ((string) $link['purpose']) {
            'verify_signup'  => account_link_verify_signup($token, $link),
            'verify_link'    => account_link_verify_link($token, $link),
            'verify_change'  => account_link_verify_change($token, $link),
            'password_reset' => account_link_reset($token, $link, $password, $repeat),
            'revert_email'   => account_link_revert($token, $link, $password, $repeat),
            default          => $gone,
        };
    }

    /** One link transaction around $work; a duplicate key is a conflict, nothing half-done. */
    function account_link_transaction(callable $work, string $conflict): array
    {
        $pdo = db();

        try {
            $pdo->beginTransaction();
            $result = $work();

            if (!($result['ok'] ?? false)) {
                $pdo->rollBack();

                return $result;
            }

            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException && in_array($e->errorInfo[1] ?? null, [1062, 4025, 3819], true)) {
                return ['ok' => false, 'message' => $conflict, 'error' => $conflict, 'status' => 409];
            }

            error_log('[ownify] link failed: ' . get_class($e));

            return ['ok' => false, 'message' => t('Dit kan even niet. Probeer het later opnieuw.'), 'error' => t('Dit kan even niet. Probeer het later opnieuw.'), 'status' => 500];
        }
    }

    /** A new account's link, or an existing account's "Stuur bevestiging" (§3, §7B). */
    function account_link_verify_signup(string $token, array $link): array
    {
        $gone     = ['ok' => false, 'message' => t('Deze link is verlopen of al gebruikt.'), 'error' => t('Deze link is verlopen of al gebruikt.'), 'status' => 410];
        $userId   = (int) $link['user_id'];
        $email    = (string) $link['email'];
        $taken    = t("Dit e-mailadres is inmiddels in gebruik. Kies 'Ander adres gebruiken'.");

        $result = account_link_transaction(static function () use ($token, $userId, $email, $gone, $taken): array {
            if (account_link_take($token, 'verify_signup') === null) {
                return $gone;
            }

            $status = db_value('SELECT status FROM users WHERE id = ? FOR UPDATE', [$userId]);

            if ($status === 'pending') {
                $pending = db_one('SELECT email, password_hash, address_taken FROM pending_registrations WHERE user_id = ? FOR UPDATE', [$userId]);

                if ($pending === null || (int) $pending['address_taken'] === 1 || (string) $pending['email'] !== $email) {
                    return $gone;
                }

                if (account_email_taken_by_other($email, $userId)) {
                    return ['ok' => false, 'message' => $taken, 'error' => $taken, 'status' => 409];
                }

                db_run(
                    "INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, password_hash, email_verified_at)
                     VALUES (?, 'email', ?, ?, ?, NOW())",
                    [$userId, $email, $email, (string) $pending['password_hash']]
                );
                account_emails_claim($userId, $email, 'password');
                db_run("UPDATE users SET status = 'active' WHERE id = ?", [$userId]);
                db_run('DELETE FROM pending_registrations WHERE user_id = ?', [$userId]);
                db_run('UPDATE auth_email_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);

                return ['ok' => true, 'message' => t('Bevestigd. Je account is klaar.'), 'registered' => true];
            }

            /* An existing account confirming its own sign-in address. */
            $identity = db_one(
                "SELECT id, provider_subject, email_verified_at FROM user_auth_identities WHERE user_id = ? AND provider = 'email' FOR UPDATE",
                [$userId]
            );

            if ($status !== 'active' || $identity === null || (string) $identity['provider_subject'] !== $email) {
                return ['ok' => false, 'message' => t('Deze wijziging is achterhaald.'), 'error' => t('Deze wijziging is achterhaald.'), 'status' => 409];
            }

            if (account_email_taken_by_other($email, $userId)) {
                $other = t('Dit e-mailadres hoort al bij een ander Ownify-account.');

                return ['ok' => false, 'message' => $other, 'error' => $other, 'status' => 409];
            }

            db_run('UPDATE user_auth_identities SET email_verified_at = NOW() WHERE id = ? AND email_verified_at IS NULL', [(int) $identity['id']]);
            account_emails_claim($userId, $email, 'password');

            return ['ok' => true, 'message' => t('Je e-mailadres is bevestigd.')];
        }, $taken);

        if ($result['ok'] && !empty($result['registered'])) {
            /* Signed in now only where the registration began (§3). */
            $hold = account_pending_held();
            if ($hold !== null && $hold['user_id'] === $userId) {
                account_pending_release();
                $result['signed_in_user'] = $userId;
            } else {
                $result['message'] = t('Bevestigd. Log in om verder te gaan.');
            }
        }

        return $result;
    }

    /** An address added to an account that signs in with Google (§6, verify_link). */
    function account_link_verify_link(string $token, array $link): array
    {
        $gone   = ['ok' => false, 'message' => t('Deze link is verlopen of al gebruikt.'), 'error' => t('Deze link is verlopen of al gebruikt.'), 'status' => 410];
        $userId = (int) $link['user_id'];
        $email  = (string) $link['email'];
        $taken  = t('Dit e-mailadres is inmiddels in gebruik.');

        return account_link_transaction(static function () use ($token, $userId, $email, $gone, $taken): array {
            if (account_link_take($token, 'verify_link') === null) {
                return $gone;
            }

            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            if (db_value("SELECT id FROM user_auth_identities WHERE user_id = ? AND provider = 'email' FOR UPDATE", [$userId]) !== null) {
                return ['ok' => false, 'message' => t('Deze wijziging is achterhaald.'), 'error' => t('Deze wijziging is achterhaald.'), 'status' => 409];
            }

            if (account_email_taken_by_other($email, $userId)) {
                return ['ok' => false, 'message' => $taken, 'error' => $taken, 'status' => 409];
            }

            db_run(
                "INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, password_hash, email_verified_at)
                 VALUES (?, 'email', ?, ?, NULL, NOW())",
                [$userId, $email, $email]
            );
            account_emails_claim($userId, $email, 'password');

            return ['ok' => true, 'message' => t('Je e-mailadres is gekoppeld. Je kunt nu ook een wachtwoord instellen.')];
        }, $taken);
    }

    /** A confirmed address change (§6): the sign-in moves to the new address; e-mail 6a to the old one. */
    function account_link_verify_change(string $token, array $link): array
    {
        $gone   = ['ok' => false, 'message' => t('Deze link is verlopen of al gebruikt.'), 'error' => t('Deze link is verlopen of al gebruikt.'), 'status' => 410];
        $stale  = t('Deze wijziging is achterhaald.');
        $taken  = t('Dit e-mailadres is inmiddels in gebruik.');
        $userId = (int) $link['user_id'];
        $new    = (string) $link['email'];
        $change = (int) ($link['change_id'] ?? 0);

        return account_link_transaction(static function () use ($token, $userId, $new, $change, $gone, $stale, $taken): array {
            if ($change === 0 || account_link_take($token, 'verify_change') === null) {
                return $gone;
            }

            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            $row      = db_one('SELECT old_email, old_verified, changed_at FROM email_changes WHERE id = ? AND user_id = ? FOR UPDATE', [$change, $userId]);
            $identity = db_one("SELECT id, provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = 'email' FOR UPDATE", [$userId]);

            if ($row === null || $row['changed_at'] !== null || $identity === null || (string) $identity['provider_subject'] !== (string) $row['old_email']) {
                return ['ok' => false, 'message' => $stale, 'error' => $stale, 'status' => 409];
            }

            if (account_email_taken_by_other($new, $userId)) {
                return ['ok' => false, 'message' => $taken, 'error' => $taken, 'status' => 409];
            }

            $old = (string) $row['old_email'];

            db_run(
                "UPDATE user_auth_identities SET provider_subject = ?, email = ?, email_verified_at = NOW() WHERE id = ? AND provider = 'email'",
                [$new, $new, (int) $identity['id']]
            );

            account_emails_release($userId, $old, 'password');
            account_emails_claim($userId, $new, 'password');

            db_run('UPDATE email_changes SET changed_at = NOW() WHERE id = ?', [$change]);
            db_run(
                "INSERT INTO email_change_google_baseline (change_id, identity_id)
                 SELECT ?, id FROM user_auth_identities WHERE user_id = ? AND provider = 'google'",
                [$change, $userId]
            );

            if ((int) $row['old_verified'] === 1) {
                $revert = account_link_create($userId, 'revert_email', $old, $change);
                account_mail_enqueue('email_changed', $old, [
                    'username' => (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]),
                    'old_email' => $old, 'new_masked' => account_email_mask($new),
                    'link' => account_link_url($revert), 'when' => new DateTimeImmutable(),
                ], ACCOUNT_LINK_HOURS['revert_email'] * 60, account_locale($userId));
            }

            return ['ok' => true, 'message' => t('Je nieuwe e-mailadres is bevestigd. Je logt voortaan daarmee in.')];
        }, $taken);
    }

    /** Wachtwoord vergeten's link (§7B): the new password, the address confirmed, everybody signed out. */
    function account_link_reset(string $token, array $link, string $password, string $repeat): array
    {
        $userId  = (int) $link['user_id'];
        $address = (string) $link['email'];
        $gone    = ['ok' => false, 'message' => t('Deze link is verlopen of al gebruikt.'), 'error' => t('Deze link is verlopen of al gebruikt.'), 'status' => 410];

        $problem = account_password_problem($password, $repeat, (string) $link['username'], account_addresses($userId));

        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem, 'error' => $problem, 'status' => 422];
        }

        $conflict = false;

        $result = account_link_transaction(static function () use ($token, $userId, $address, $password, $gone, &$conflict): array {
            if (account_link_take($token, 'password_reset') === null) {
                return $gone;
            }

            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            $identity = db_one(
                "SELECT id, password_hash FROM user_auth_identities WHERE user_id = ? AND provider = 'email' AND provider_subject = ? FOR UPDATE",
                [$userId, $address]
            );

            if ($identity === null || (string) ($identity['password_hash'] ?? '') === '' || account_email_taken_by_other($address, $userId)) {
                $conflict = true;

                return $gone;
            }

            db_run(
                'UPDATE user_auth_identities SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int) $identity['id']]
            );
            account_emails_claim($userId, $address, 'password');
            account_end_other_sessions($userId, null, null);

            account_mail_enqueue('password', $address, [
                'username' => (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]),
                'when' => new DateTimeImmutable(), 'via' => 'web',
            ], 7 * 24 * 60, account_locale($userId));

            return ['ok' => true, 'message' => t('Je nieuwe wachtwoord is ingesteld. Je bent overal uitgelogd. Log in met je nieuwe wachtwoord.')];
        }, t('Deze link is verlopen of al gebruikt.'));

        /* A conflict found under the lock: everything rolled back, and the
           link is spent anyway (§7B). */
        if ($conflict) {
            account_link_spend($token);
        }

        if ($result['ok']) {
            $result['signed_out'] = true;
        }

        return $result;
    }

    /** The revert link (§6): the old address back, a new password, every session ended, Google identities linked since removed (D4). */
    function account_link_revert(string $token, array $link, string $password, string $repeat): array
    {
        $userId = (int) $link['user_id'];
        $old    = (string) $link['email'];
        $change = (int) ($link['change_id'] ?? 0);
        $gone   = ['ok' => false, 'message' => t('Deze link is verlopen of al gebruikt.'), 'error' => t('Deze link is verlopen of al gebruikt.'), 'status' => 410];

        $row = $change === 0 ? null : db_one('SELECT old_email, new_email FROM email_changes WHERE id = ? AND user_id = ?', [$change, $userId]);

        if ($row === null) {
            return $gone;
        }

        $problem = account_password_problem($password, $repeat, (string) $link['username'],
            array_merge(account_addresses($userId), [(string) $row['old_email'], (string) $row['new_email']]));

        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem, 'error' => $problem, 'status' => 422];
        }

        $elsewhere = t('Je oude adres hoort inmiddels bij een ander Ownify-account van jou. Log daar in met dat adres.');

        $result = account_link_transaction(static function () use ($token, $userId, $old, $change, $password, $gone, $elsewhere): array {
            if (account_link_take($token, 'revert_email') === null) {
                return $gone;
            }

            db_run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            $identity = db_one("SELECT id, provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = 'email' FOR UPDATE", [$userId]);
            $row      = db_one('SELECT changed_at, reverted_at FROM email_changes WHERE id = ? AND user_id = ? FOR UPDATE', [$change, $userId]);

            if ($identity === null || $row === null || $row['changed_at'] === null || $row['reverted_at'] !== null) {
                return $gone;
            }

            if (account_email_taken_by_other($old, $userId)) {
                return ['ok' => false, 'message' => $elsewhere, 'error' => $elsewhere, 'status' => 409];
            }

            $current = (string) $identity['provider_subject'];

            db_run(
                "UPDATE user_auth_identities SET provider_subject = ?, email = ?, email_verified_at = NOW(), password_hash = ? WHERE id = ? AND provider = 'email'",
                [$old, $old, password_hash($password, PASSWORD_DEFAULT), (int) $identity['id']]
            );

            if ($current !== $old) {
                account_emails_release($userId, $current, 'password');
            }
            account_emails_claim($userId, $old, 'password');

            /* D4, by id: every Google identity not in the change's baseline goes. */
            foreach (db_all(
                "SELECT id, email FROM user_auth_identities
                  WHERE user_id = ? AND provider = 'google'
                    AND id NOT IN (SELECT identity_id FROM email_change_google_baseline WHERE change_id = ?)",
                [$userId, $change]
            ) as $google) {
                db_run('DELETE FROM user_auth_identities WHERE id = ?', [(int) $google['id']]);
                if ($google['email'] !== null) {
                    account_emails_release($userId, (string) $google['email'], 'google');
                }
            }

            db_run('UPDATE email_changes SET reverted_at = NOW() WHERE id = ?', [$change]);
            db_run('UPDATE auth_email_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
            account_end_other_sessions($userId, null, null);

            account_mail_enqueue('password', $old, [
                'username' => (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]),
                'when' => new DateTimeImmutable(), 'via' => 'web',
            ], 7 * 24 * 60, account_locale($userId));

            return ['ok' => true, 'message' => t('Je oude adres is terug en je nieuwe wachtwoord is ingesteld. Je bent overal uitgelogd. Log in met je oude adres en je nieuwe wachtwoord.')];
        }, $elsewhere);

        if ($result['ok']) {
            $result['signed_out'] = true;
        }

        return $result;
    }
}
