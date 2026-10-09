<?php
/**
 * Connections to outside health platforms — PRIVATE, scoped to the owner.
 *
 * ---------------------------------------------------------------------------
 * PROVIDER-AGNOSTIC ON PURPOSE
 * ---------------------------------------------------------------------------
 * Nothing in this file knows what Google is. It records that a user connected
 * *something*, what it is allowed to read, the tokens for it, and when it last
 * managed to sync. Google Health, a Health Connect companion app, Polar
 * and a watch are all the same shape of thing from here; only the code that
 * fetches differs.
 *
 * That matters because the architecture question — cloud API or phone app —
 * is not settled, and this layer is correct either way.
 *
 * ---------------------------------------------------------------------------
 * THE PRIVACY RULE, SAME AS THE REST
 * ---------------------------------------------------------------------------
 * Every function takes the authenticated user's id first and every statement
 * filters on it. Tokens are never returned to a browser, only used server-side
 * — integration_public() is what the settings screen gets, and it has no
 * tokens in it at all.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';

if (!function_exists('integration_providers')) {

    /**
     * The providers the app knows about, and what each one needs to work.
     *
     * `transport` is the honest part:
     *   cloud   the server can fetch on its own, given a token
     *   device  the data lives on a phone; something on that phone has to
     *           send it, because no server can reach it
     */
    function integration_providers(): array
    {
        return [
            'google_health' => [
                'label'     => 'Google Health',
                'transport' => 'cloud',
                'note'      => 'Fitbit en Pixel Watch, via je Google-account',
            ],
            'polar' => [
                'label'     => 'Polar',
                'transport' => 'cloud',
                'note'      => 'Polar-horloges en -sensoren, via Polar Flow',
                /* What Polar needs beyond every cloud source: its own
                   credentials and migration 020 (includes/polar.php). */
                'check'     => 'polar_problem',
            ],
            'google_health_connect' => [
                'label'     => 'Health Connect',
                'transport' => 'device',
                'note'      => 'Android — vereist de Ownify-app op je telefoon',
            ],
        ];
    }

    function integration_known(string $provider): bool
    {
        return isset(integration_providers()[$provider]);
    }

    /* ------------------------------------------------------ credentials */

    /** Everything configured for one provider, or an empty array. */
    function integration_config(string $provider): array
    {
        static $all = null;

        if ($all === null) {
            /* In a scope of its own: the file's loops assign `$provider`, which
               would otherwise overwrite this function's argument on its first
               call, and answer for a different provider. */
            $all = (array) (static function (): mixed {
                return require dirname(__DIR__) . '/config/integrations.php';
            })();
        }

        return $all[$provider] ?? [];
    }

    /**
     * Whether this provider could actually be connected right now: exactly
     * when integration_blocked_reason() has nothing to say, so the button and
     * the line under it can never disagree.
     */
    function integration_configured(string $provider): bool
    {
        return integration_blocked_reason($provider) === null;
    }

    /**
     * A credential that is filled in with the words of an example file — the
     * placeholders of config/integrations.local.php.example — rather than
     * with a real value. Copying the example and filling in one provider
     * leaves the others' placeholders behind, and those must read as "not
     * set up", never as set up.
     */
    function integration_placeholder(string $value): bool
    {
        $value = trim($value);

        return $value === ''
            || str_starts_with($value, 'PUT-')
            || str_contains($value, 'your-domain.tld')
            || (bool) preg_match('/x{8,}|^0{6,}-/i', $value);
    }

    /**
     * Why a provider cannot be connected, for the owner. Null when it can.
     *
     * For a cloud source, in this order — each a different fix:
     *
     *   connect flow   its sign-in pages exist (api/integrations/<provider>/
     *                  callback.php): Google Health's were never built
     *   credentials    a client id, secret and redirect URI that are real
     *   its own check  what only that provider needs (Polar: migration 020)
     *   the app key    tokens can be sealed before they are stored
     *                  (includes/crypto.php: OWNIFY_APP_KEY or app_key in
     *                  config/app.local.php). Never stored without it.
     */
    function integration_blocked_reason(string $provider): ?string
    {
        $meta = integration_providers()[$provider] ?? null;

        if ($meta === null) {
            return 'Onbekende koppeling.';
        }

        /* A phone source is ready when the app that reads it exists. There is
           nothing to configure on this side — the server half is done — so the
           only question is whether there is anything to pair with. */
        if ($meta['transport'] === 'device') {
            return empty(integration_config($provider)['app_available'])
                ? ($meta['unavailable'] ?? 'Deze gegevens staan op je telefoon. Koppelen kan zodra de Ownify-app er is.')
                : null;
        }

        if (!is_file(dirname(__DIR__) . '/api/integrations/' . basename($provider) . '/callback.php')) {
            return 'Deze koppeling is nog niet beschikbaar in Ownify.';
        }

        $config = integration_config($provider);

        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (integration_placeholder((string) ($config[$key] ?? ''))) {
                return 'Deze koppeling is op de server nog niet ingesteld.';
            }
        }

        if (isset($meta['check']) && function_exists($meta['check']) && ($problem = ($meta['check'])()) !== null) {
            return $problem;
        }

        if (!crypto_available()) {
            /* The why (missing, invalid, no libsodium) is in the server log
               (crypto_check()) and tools/check-config.php — never on a page. */
            return 'De server kan tokens nog niet veilig opslaan.';
        }

        return null;
    }

    /* ----------------------------------------------------------- reading */

    /** One connection, tokens included. Server-side use only. */
    function integration_get(int $userId, string $provider): ?array
    {
        if (!integration_known($provider)) {
            return null;
        }

        return db_one(
            'SELECT * FROM user_integrations WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );
    }

    /**
     * What the settings screen may see: status and timings, never a token.
     *
     * Returned for every known provider, connected or not, so the page can
     * render the whole list from one call.
     */
    function integration_public(int $userId, string $provider): array
    {
        $meta = integration_providers()[$provider] ?? ['label' => $provider, 'transport' => 'cloud', 'note' => null];
        $row  = db_available() ? integration_get($userId, $provider) : null;

        return [
            'provider'      => $provider,
            'label'         => $meta['label'],
            'transport'     => $meta['transport'],
            'note'          => $meta['note'],
            'status'        => $row['status'] ?? 'disconnected',
            'connected'     => ($row['status'] ?? null) === 'connected',
            'account'       => $row['external_account_label'] ?? null,
            'connected_at'  => $row['connected_at'] ?? null,
            'last_sync_at'  => $row['last_sync_at'] ?? null,
            'last_sync'     => $row['last_sync_status'] ?? 'never',
            'last_error'    => $row['last_error'] ?? null,
            /* A sync running now — started under a quarter of an hour ago,
               so one that died does not say so forever. */
            'syncing'       => !empty($row['sync_started_at'])
                && strtotime((string) $row['sync_started_at']) > time() - 900,
            'available'     => integration_configured($provider),
            'blocked'       => integration_blocked_reason($provider),
            'scopes'        => $row === null || $row['scopes'] === null
                ? []
                : array_values(array_filter(explode(' ', (string) $row['scopes']))),
        ];
    }

    /** Every provider's state for one user, for the devices screen. */
    function integrations_for_user(?int $userId): array
    {
        $out = [];

        foreach (array_keys(integration_providers()) as $provider) {
            $out[$provider] = $userId === null
                ? integration_public(0, $provider)   // signed out: all disconnected
                : integration_public($userId, $provider);
        }

        return $out;
    }

    function integrations_connected_count(?int $userId): int
    {
        if ($userId === null || !db_available()) {
            return 0;
        }

        return (int) db_value(
            "SELECT COUNT(*) FROM user_integrations WHERE user_id = ? AND status = 'connected'",
            [$userId]
        );
    }

    /* ----------------------------------------------------------- writing */

    /**
     * Records a completed connection.
     *
     * Refuses when there is nowhere safe to put the tokens. Storing a refresh
     * token in the clear because a key was missing would be the worst possible
     * failure mode: silent, and not visible until it mattered.
     */
    function integration_connect(int $userId, string $provider, array $connection): array
    {
        if (!integration_known($provider)) {
            return ['ok' => false, 'error' => 'Onbekende koppeling.'];
        }

        $needsTokens = ($connection['access_token'] ?? null) !== null
            || ($connection['refresh_token'] ?? null) !== null;

        if ($needsTokens && !crypto_available()) {
            return ['ok' => false, 'error' => 'De server kan tokens niet veilig opslaan. Stel OWNIFY_APP_KEY in.'];
        }

        db_run(
            'INSERT INTO user_integrations
                (user_id, provider, status, external_account_id, external_account_label,
                 scopes, access_token, refresh_token, token_expires_at, connected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                external_account_id = VALUES(external_account_id),
                external_account_label = VALUES(external_account_label),
                scopes = VALUES(scopes),
                access_token = VALUES(access_token),
                refresh_token = COALESCE(VALUES(refresh_token), refresh_token),
                token_expires_at = VALUES(token_expires_at),
                connected_at = COALESCE(connected_at, NOW()),
                last_error = NULL',
            [
                $userId,
                $provider,
                'connected',
                $connection['external_account_id'] ?? null,
                $connection['external_account_label'] ?? null,
                $connection['scopes'] ?? null,
                crypto_seal($connection['access_token'] ?? null),
                crypto_seal($connection['refresh_token'] ?? null),
                $connection['token_expires_at'] ?? null,
            ]
        );

        return ['ok' => true, 'error' => null];
    }

    /**
     * Replaces the access token after a refresh.
     *
     * A provider often does not re-issue the refresh token, so COALESCE keeps
     * the one already stored rather than overwriting it with null and locking
     * the user out of their own connection.
     */
    function integration_store_tokens(
        int $userId,
        string $provider,
        ?string $accessToken,
        ?string $refreshToken,
        ?string $expiresAt
    ): bool {
        if (!crypto_available()) {
            return false;
        }

        $statement = db_run(
            'UPDATE user_integrations
                SET access_token = ?,
                    refresh_token = COALESCE(?, refresh_token),
                    token_expires_at = ?,
                    status = CASE WHEN status = \'connected\' THEN status ELSE \'connected\' END,
                    last_error = NULL
              WHERE user_id = ? AND provider = ?',
            [
                crypto_seal($accessToken),
                crypto_seal($refreshToken),
                $expiresAt,
                $userId,
                $provider,
            ]
        );

        return $statement !== null && $statement->rowCount() >= 0;
    }

    /** The decrypted tokens, for the fetching code. Never leaves the server. */
    function integration_tokens(int $userId, string $provider): ?array
    {
        $row = integration_get($userId, $provider);

        if ($row === null) {
            return null;
        }

        return [
            'access_token'  => crypto_open($row['access_token'] ?? null),
            'refresh_token' => crypto_open($row['refresh_token'] ?? null),
            'expires_at'    => $row['token_expires_at'] ?? null,
            'expired'       => $row['token_expires_at'] !== null
                && strtotime((string) $row['token_expires_at']) <= time() + 60,
        ];
    }

    /**
     * Disconnects.
     *
     * The tokens are destroyed rather than kept "in case", because a token we
     * are no longer allowed to use is only a liability. The row stays so the
     * screen can say when the connection existed.
     */
    function integration_disconnect(int $userId, string $provider): bool
    {
        $statement = db_run(
            'UPDATE user_integrations
                SET status = \'disconnected\', access_token = NULL, refresh_token = NULL,
                    token_expires_at = NULL, last_error = NULL
              WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );

        return $statement !== null;
    }

    /**
     * Marks a connection the provider has revoked.
     *
     * Different from disconnecting: the user did not ask, so the screen has to
     * say so and invite them to reconnect. The tokens go either way — they no
     * longer work.
     */
    function integration_mark_revoked(int $userId, string $provider, ?string $reason = null): void
    {
        db_run(
            'UPDATE user_integrations
                SET status = \'revoked\', access_token = NULL, refresh_token = NULL,
                    token_expires_at = NULL, last_error = ?
              WHERE user_id = ? AND provider = ?',
            [$reason === null ? null : mb_substr($reason, 0, 255), $userId, $provider]
        );
    }

    /** Records how a sync went. `partial` means some categories failed. */
    function integration_note_sync(
        int $userId,
        string $provider,
        string $status,
        ?string $error = null
    ): void {
        if (!in_array($status, ['ok', 'partial', 'failed'], true)) {
            return;
        }

        db_run(
            'UPDATE user_integrations
                SET last_sync_status = ?,
                    last_sync_at = CASE WHEN ? IN (\'ok\', \'partial\') THEN NOW() ELSE last_sync_at END,
                    last_error = ?,
                    status = CASE WHEN ? = \'failed\' AND status = \'connected\' THEN \'error\'
                                  WHEN ? IN (\'ok\', \'partial\') AND status = \'error\' THEN \'connected\'
                                  ELSE status END
              WHERE user_id = ? AND provider = ?',
            [$status, $status, $error === null ? null : mb_substr($error, 0, 255),
             $status, $status, $userId, $provider]
        );
    }
}

/* Polar's own half: its credentials check, connecting and syncing. Loaded
   after the functions above, which it builds on. */
require_once __DIR__ . '/polar.php';
