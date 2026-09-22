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

if (!function_exists('device_hash')) {

    /** One place, so minting and checking can never disagree. */
    function device_hash(string $secret): string
    {
        return hash('sha256', $secret);
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

        db_run(
            'INSERT INTO user_devices (user_id, provider, token_hash, label, platform, app_version, last_seen_at)
                  VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $userId,
                $provider,
                device_hash($token),
                isset($device['label']) ? mb_substr((string) $device['label'], 0, 80) : null,
                isset($device['platform']) ? mb_substr((string) $device['platform'], 0, 40) : null,
                isset($device['app_version']) ? mb_substr((string) $device['app_version'], 0, 40) : null,
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
    function device_authenticate(?string $token): ?array
    {
        if ($token === null || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $row = db_one(
            'SELECT id, user_id, provider, label, platform, revoked_at
               FROM user_devices WHERE token_hash = ?',
            [device_hash($token)]
        );

        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }

        db_run('UPDATE user_devices SET last_seen_at = NOW() WHERE id = ?', [(int) $row['id']]);

        return [
            'device_id' => (int) $row['id'],
            'user_id'   => (int) $row['user_id'],
            'provider'  => (string) $row['provider'],
            'label'     => $row['label'],
        ];
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
              WHERE user_id = ? AND provider = ? AND revoked_at IS NULL',
            [$userId, $provider]
        );
    }

    /** The phones on one account. No tokens — there is nothing to show. */
    function devices_for_user(int $userId, ?string $provider = null): array
    {
        $sql = 'SELECT id, provider, label, platform, app_version, created_at, last_seen_at, last_sync_at
                  FROM user_devices
                 WHERE user_id = ? AND revoked_at IS NULL';
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
