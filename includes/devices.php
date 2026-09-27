<?php
/**
 * Paired phones — PRIVATE.
 *
 * ---------------------------------------------------------------------------
 * WHY A PHONE NEEDS ITS OWN CREDENTIAL
 * ---------------------------------------------------------------------------
 * Health Connect and Apple Health cannot be read from a server. The data sits
 * on the phone, behind permissions the person grants to an app on that phone,
 * and no amount of server-side OAuth reaches it. So an app reads it and posts
 * it here — which means that app has to prove which JoLu account it is posting
 * for.
 *
 * It cannot use the session cookie: it is not a browser, and a cookie that
 * lives long enough to be useful to a background sync is a cookie that is
 * dangerous in a browser. It must not hold the account password either — a
 * credential that can change the e-mail address is far too much authority for
 * something whose only job is to upload step counts.
 *
 * So it holds a token of its own, with exactly that authority and no more:
 *
 *   1. the website mints a short pairing code for the signed-in user
 *   2. the app exchanges it, once, for a long-lived device token
 *   3. the app sends records with that token
 *   4. revoking the device kills that token and nothing else
 *
 * ---------------------------------------------------------------------------
 * TWO SCOPES: SYNC AND ACCOUNT (migration 013)
 * ---------------------------------------------------------------------------
 * That token is a SYNC token, and it stays exactly that. The full JoLu app
 * also has to act as the account — set goals, see friends, change the
 * profile — and it gets that authority the only way that proves it is the
 * account holder: by signing in, with the password or with Google, in the app
 * (api/auth/app-login.php, app-register.php, app-google.php). What that
 * issues is an ACCOUNT token, in this same table:
 *
 *   scope    issued by                     may
 *   sync     a pairing code (pair.php)     upload records and read what a
 *                                          sync needs (the integration
 *                                          endpoints) — nothing else
 *   account  signing in in the app         all of that, and act as the
 *                                          account where an endpoint says so
 *                                          (api_require_account_user())
 *
 * A token never changes scope. When the app signs in on a phone that already
 * has a token, and shows it, that phone's row is given a NEW token with the
 * account scope and the old one stops working that instant
 * (device_issue_account_token()). So there is one row per phone, and a
 * pairing code's token can never be turned into more than it was.
 *
 * An account token lapses after DEVICE_ACCOUNT_TOKEN_DAYS without use;
 * last_seen_at, set on every authenticated request, says when that is. It is
 * not rotated on use: a background sync and the app can hold the same token
 * at the same moment, and a rotation one of them misses would sign the phone
 * out. Sync tokens do not lapse, as before 013.
 *
 * Both kinds are revoked the same way — the button in Settings, disconnecting
 * the source, deleting the account — and an account token also by signing
 * out in the app (device_revoke_token()).
 *
 * ---------------------------------------------------------------------------
 * HOW THE SECRETS ARE STORED
 * ---------------------------------------------------------------------------
 * Hashed, not encrypted. Neither the pairing code nor the device token is ever
 * needed back — only recognised when presented — so they are stored the way a
 * password is. A database dump yields nothing anyone can replay.
 *
 * SHA-256 rather than bcrypt, deliberately: these are 256 bits of random from
 * the server, not something a person chose, so there is no dictionary to walk
 * and nothing for a slow hash to buy. The comparison is still constant-time.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/integrations.php';

/** How long a pairing code is worth typing. Long enough to walk to the phone. */
if (!defined('DEVICE_PAIRING_TTL')) {
    define('DEVICE_PAIRING_TTL', 600);          // seconds
}

/** How many phones one account may pair per source. */
if (!defined('DEVICE_LIMIT_PER_PROVIDER')) {
    define('DEVICE_LIMIT_PER_PROVIDER', 5);
}

/** Days an account token keeps working unused. Every use starts them again. */
if (!defined('DEVICE_ACCOUNT_TOKEN_DAYS')) {
    define('DEVICE_ACCOUNT_TOKEN_DAYS', 365);
}

if (!function_exists('device_hash')) {

    /** One place, so minting and checking can never disagree. */
    function device_hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Whether user_devices has migration 013's scope column. Asked once per
     * request. Before it, every token is what pairing made — a sync token —
     * and signing in in the app is not offered.
     */
    function devices_scoped(): bool
    {
        static $scoped = null;

        if ($scoped !== null) {
            return $scoped;
        }

        if (!db_available()) {
            return $scoped = false;
        }

        return $scoped = (int) db_value(
            "SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'user_devices' AND column_name = 'scope'"
        ) === 1;
    }

    /**
     * The condition for a row that still works: not revoked and, for an
     * account token, used within DEVICE_ACCOUNT_TOKEN_DAYS. `$alias` is the
     * table's alias in the statement, if it has one.
     */
    function device_live_sql(string $alias = ''): string
    {
        $t   = $alias === '' ? '' : $alias . '.';
        $sql = $t . 'revoked_at IS NULL';

        if (devices_scoped()) {
            $sql .= ' AND (' . $t . "scope = 'sync' OR COALESCE(" . $t . 'last_seen_at, ' . $t . 'created_at) >= NOW() - INTERVAL '
                . (int) DEVICE_ACCOUNT_TOKEN_DAYS . ' DAY)';
        }

        return $sql;
    }

    /** The phone's own source, from the platform the app says it runs on. */
    function device_app_provider(?string $platform): string
    {
        return strtolower(trim((string) $platform)) === 'ios' ? 'apple_health' : 'google_health_connect';
    }

    /* ------------------------------------------------------ pairing code */

    /**
     * Mints a pairing code for the signed-in user.
     *
     * Returned once, in the response that creates it, and never retrievable
     * again — the database holds only the hash. Showing it again would mean
     * storing it, and then it would be worth stealing.
     *
     * The alphabet leaves out the characters people confuse when copying from
     * a screen to a phone: no O/0, no I/1/l.
     */
    function device_create_pairing_code(int $userId, string $provider): ?array
    {
        if (!integration_known($provider)) {
            return null;
        }

        /* One live code at a time per source. Asking again replaces the last,
           so a code left on a screen an hour ago stops working. */
        db_run(
            'DELETE FROM device_pairing_codes WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );

        /* Opportunistic housekeeping. Codes nobody used are worthless after a
           day, and without this they accumulate for the life of the database.
           Done here rather than on a schedule because it costs one indexed
           DELETE on the one occasion somebody is already writing to this
           table, and a cron job is a thing to install, forget, and discover
           missing years later. */
        device_purge_expired_codes();

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code     = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $expiresAt = date('Y-m-d H:i:s', time() + DEVICE_PAIRING_TTL);

        db_run(
            'INSERT INTO device_pairing_codes (code_hash, user_id, provider, expires_at)
                  VALUES (?, ?, ?, ?)',
            [device_hash($code), $userId, $provider, $expiresAt]
        );

        return [
            'code'       => $code,
            'expires_at' => $expiresAt,
            'expires_in' => DEVICE_PAIRING_TTL,
        ];
    }

    /**
     * Exchanges a pairing code for a device token.
     *
     * Everything that can go wrong returns the same message. A response that
     * distinguished "no such code" from "that code expired" would let somebody
     * grind the code space and learn which guesses were once real.
     */
    function device_pair(string $code, array $device = []): array
    {
        $generic = ['ok' => false, 'error' => 'Deze koppelcode is niet geldig of verlopen.'];

        $code = strtoupper(trim($code));

        if ($code === '' || !preg_match('/^[A-Z2-9]{8}$/', $code)) {
            return $generic;
        }

        $row = db_one(
            'SELECT user_id, provider, expires_at, consumed_at
               FROM device_pairing_codes WHERE code_hash = ?',
            [device_hash($code)]
        );

        if ($row === null
            || $row['consumed_at'] !== null
            || strtotime((string) $row['expires_at']) < time()) {
            return $generic;
        }

        $userId   = (int) $row['user_id'];
        $provider = (string) $row['provider'];

        /* A code is worth one device. Marking it used before issuing anything
           means two apps racing the same code cannot both win. */
        $claim = db_run(
            'UPDATE device_pairing_codes SET consumed_at = NOW()
              WHERE code_hash = ? AND consumed_at IS NULL',
            [device_hash($code)]
        );

        if ($claim === null || $claim->rowCount() === 0) {
            return $generic;
        }

        if (device_count($userId, $provider) >= DEVICE_LIMIT_PER_PROVIDER) {
            return ['ok' => false, 'error' => 'Je hebt het maximum aantal gekoppelde apparaten bereikt.'];
        }

        /* 256 bits from the server. Returned now and never again. */
        $token = bin2hex(random_bytes(32));

        /* A sync token, said in so many words rather than left to the
           column's default: a pairing code never buys more than that. */
        db_run(
            'INSERT INTO user_devices (user_id, provider, token_hash, ' . (devices_scoped() ? 'scope, ' : '')
                . 'label, platform, app_version, last_seen_at)
                  VALUES (?, ?, ?, ' . (devices_scoped() ? "'sync', " : '') . '?, ?, ?, NOW())',
            [
                $userId,
                $provider,
                device_hash($token),
                ...device_details($device),
            ]
        );

        /* The source counts as connected the moment a phone is paired. */
        integration_connect($userId, $provider, [
            'external_account_label' => $device['label'] ?? null,
        ]);

        return [
            'ok'       => true,
            'error'    => null,
            'token'    => $token,
            'user_id'  => $userId,
            'provider' => $provider,
        ];
    }

    /* ----------------------------------------------------- device token */

    /**
     * Resolves a device token to the account it belongs to.
     *
     * The ONLY way an ingest request learns whose data it is sending. Nothing
     * in the request body is ever consulted for identity: a user id in a body
     * is a user id an attacker can change.
     */
    /**
     * Also for the app's account token (scope 'account'): this says which
     * kind it is, and the caller decides whether that kind is enough. The
     * integration endpoints take either; anything that acts as the account
     * asks api_require_account_user(), which takes only 'account'.
     *
     * An account token unused for DEVICE_ACCOUNT_TOKEN_DAYS is refused like a
     * revoked one. Using one moves last_seen_at, which starts its days again.
     *
     * @return array{device_id: int, user_id: int, provider: string, label: ?string,
     *               scope: string, last_seen_at: ?string}|null
     */
    function device_authenticate(?string $token): ?array
    {
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = db_one(
            'SELECT id, user_id, provider, label, platform, created_at, last_seen_at, revoked_at'
                . (devices_scoped() ? ', scope' : '') . '
               FROM user_devices WHERE token_hash = ?',
            [device_hash($token)]
        );

        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }

        $scope = (string) ($row['scope'] ?? 'sync');

        if ($scope === 'account') {
            $used = strtotime((string) ($row['last_seen_at'] ?? $row['created_at']));

            if ($used === false || $used < time() - DEVICE_ACCOUNT_TOKEN_DAYS * 86400) {
                return null;
            }
        }

        db_run('UPDATE user_devices SET last_seen_at = NOW() WHERE id = ?', [(int) $row['id']]);

        return [
            'device_id'    => (int) $row['id'],
            'user_id'      => (int) $row['user_id'],
            'provider'     => (string) $row['provider'],
            'label'        => $row['label'],
            'scope'        => $scope,
            'last_seen_at' => $row['last_seen_at'],
        ];
    }

    /**
     * What the app says about itself, cut to the columns' sizes.
     *
     * @return list<?string>  label, platform, app_version
     */
    function device_details(array $device): array
    {
        return [
            isset($device['label']) && is_scalar($device['label']) ? mb_substr((string) $device['label'], 0, 80) : null,
            isset($device['platform']) && is_scalar($device['platform']) ? mb_substr((string) $device['platform'], 0, 40) : null,
            isset($device['app_version']) && is_scalar($device['app_version']) ? mb_substr((string) $device['app_version'], 0, 40) : null,
        ];
    }

    /* ---------------------------------------------------- account token */

    /**
     * An account token for somebody who has just proved who they are in the
     * app — with their password, a new registration, or Google. Never called
     * for anything less: this is the one place that makes a token that can
     * act as the account.
     *
     * $presented is the token the app already holds, if any, from its
     * Authorization header. When it is a working token of this same account —
     * the phone was paired before, or signed in before — that phone's row is
     * reused: a new token, scope 'account', and the old token dead at once.
     * One phone stays one row in Settings, its sync history intact.
     *
     * A token of somebody else's account is left alone: signing in as B
     * proves nothing about A's phone entry, which A can see and revoke.
     *
     * The phone's source is connected, as pairing does: the row is listed,
     * and revoked, under it in Settings.
     *
     * @return array{ok: bool, error: ?string, status?: int, token?: string, device_id?: int, provider?: string}
     */
    function device_issue_account_token(int $userId, array $device, ?string $presented = null): array
    {
        if (!devices_scoped()) {
            return ['ok' => false, 'error' => 'Inloggen in de app is op deze server nog niet beschikbaar.', 'status' => 503];
        }

        /* 256 bits from the server, like a pairing token, and unrelated to
           anything else — the password, the browser's sign-in, a sync token.
           Returned now and never again. */
        $token   = bin2hex(random_bytes(32));
        $details = device_details($device);
        $current = device_authenticate($presented);

        if ($current !== null && $current['user_id'] === $userId) {
            $statement = db_run(
                "UPDATE user_devices
                    SET token_hash = ?, scope = 'account',
                        label = COALESCE(?, label), platform = COALESCE(?, platform),
                        app_version = COALESCE(?, app_version), last_seen_at = NOW()
                  WHERE id = ? AND user_id = ? AND revoked_at IS NULL",
                [device_hash($token), ...$details, $current['device_id'], $userId]
            );

            if ($statement !== null && $statement->rowCount() === 1) {
                integration_connect($userId, $current['provider'], ['external_account_label' => $details[0]]);

                return [
                    'ok'        => true,
                    'error'     => null,
                    'token'     => $token,
                    'device_id' => $current['device_id'],
                    'provider'  => $current['provider'],
                ];
            }
        }

        $provider = device_app_provider($details[1]);

        if (device_count($userId, $provider) >= DEVICE_LIMIT_PER_PROVIDER) {
            return [
                'ok'     => false,
                'error'  => 'Je hebt het maximum aantal gekoppelde apparaten bereikt. Verwijder er een via Instellingen op de website.',
                'status' => 409,
            ];
        }

        $insert = db_run(
            "INSERT INTO user_devices (user_id, provider, token_hash, scope, label, platform, app_version, last_seen_at)
                  VALUES (?, ?, ?, 'account', ?, ?, ?, NOW())",
            [$userId, $provider, device_hash($token), ...$details]
        );

        $deviceId = $insert === null ? null : db_insert_id();

        /* Never a token without the row that makes it work. */
        if ($deviceId === null || $deviceId <= 0) {
            return ['ok' => false, 'error' => 'Er kon niet worden ingelogd. Probeer het opnieuw.', 'status' => 500];
        }

        integration_connect($userId, $provider, ['external_account_label' => $details[0]]);

        return [
            'ok'        => true,
            'error'     => null,
            'token'     => $token,
            'device_id' => $deviceId,
            'provider'  => $provider,
        ];
    }

    /**
     * Signing out in the app: the account token it shows, and nothing else —
     * not the account's other phones, not a browser. Only an account token:
     * a sync token is ended by revoking the phone on the website, as always.
     *
     * True when a token was revoked; false when there was nothing to revoke
     * (unknown, already revoked, lapsed, or not an account token).
     */
    function device_revoke_token(?string $token): bool
    {
        if (!devices_scoped() || $token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $statement = db_run(
            "UPDATE user_devices SET revoked_at = NOW()
              WHERE token_hash = ? AND scope = 'account' AND revoked_at IS NULL",
            [device_hash($token)]
        );

        return $statement !== null && $statement->rowCount() > 0;
    }

    function device_note_sync(int $deviceId): void
    {
        db_run('UPDATE user_devices SET last_sync_at = NOW() WHERE id = ?', [$deviceId]);
    }

    /* ---------------------------------------------------------- listing */

    function device_count(int $userId, string $provider): int
    {
        return (int) db_value(
            'SELECT COUNT(*) FROM user_devices
              WHERE user_id = ? AND provider = ? AND ' . device_live_sql(),
            [$userId, $provider]
        );
    }

    /** The phones on one account. No tokens — there is nothing to show. */
    function devices_for_user(int $userId, ?string $provider = null): array
    {
        $sql = 'SELECT id, provider, label, platform, app_version, created_at, last_seen_at, last_sync_at
                  FROM user_devices
                 WHERE user_id = ? AND ' . device_live_sql();
        $params = [$userId];

        if ($provider !== null) {
            $sql .= ' AND provider = ?';
            $params[] = $provider;
        }

        return db_all($sql . ' ORDER BY created_at', $params);
    }

    /**
     * Revokes one phone.
     *
     * The row stays, with a revoked_at, so the account can still show that the
     * phone was once paired — and so a token that turns up afterwards is a
     * known revoked one rather than an unknown.
     */
    function device_revoke(int $userId, int $deviceId): bool
    {
        $statement = db_run(
            'UPDATE user_devices SET revoked_at = NOW()
              WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$deviceId, $userId]
        );

        return $statement !== null && $statement->rowCount() > 0;
    }

    /** Revokes every phone for a source, which is what disconnecting means. */
    function device_revoke_provider(int $userId, string $provider): int
    {
        $statement = db_run(
            'UPDATE user_devices SET revoked_at = NOW()
              WHERE user_id = ? AND provider = ? AND revoked_at IS NULL',
            [$userId, $provider]
        );

        return $statement === null ? 0 : $statement->rowCount();
    }

    /** Housekeeping: codes that were never used are worth nothing after a day. */
    function device_purge_expired_codes(): void
    {
        db_run('DELETE FROM device_pairing_codes WHERE expires_at < (NOW() - INTERVAL 1 DAY)');
    }
}
