<?php
/**
 * Polar — AccessLink Dynamic API v4, read server to server. PRIVATE.
 *
 * docs/POLAR.md has the whole story; this is the code.
 *
 * ---------------------------------------------------------------------------
 * CONNECTING (OAuth 2.0, authorization code)
 * ---------------------------------------------------------------------------
 *   1. polar_begin()     a signed-in person asks to connect: a state of 32
 *                        random bytes is made, only its hash stored, with
 *                        whose it is (from the session or the app's account
 *                        token — never from a request) and where it may be
 *                        finished; the browser goes to auth.polar.com.
 *   2. Polar asks the person, and sends the browser back to
 *      api/integrations/polar/callback.php with ?code&state (or ?error).
 *   3. polar_callback()  the state must be known, unused and under ten
 *                        minutes old, and:
 *                          web  the browser session that started it
 *                          app  a page naming the Ownify account, where the
 *                               person confirms (polar_confirm()) — the app's
 *                               browser has no Ownify session to compare
 *                        Only then is the code exchanged (Basic auth with the
 *                        client id and secret) and the tokens stored, sealed
 *                        (includes/crypto.php), for the user the STATE names.
 *
 * The access token lasts 12 hours; polar_access_token() refreshes it with
 * the refresh token when it has (nearly) run out. A refresh Polar refuses
 * means the person withdrew Ownify's access: the connection is marked
 * revoked, the tokens destroyed, and the screen asks to connect again.
 *
 * ---------------------------------------------------------------------------
 * SYNCING
 * ---------------------------------------------------------------------------
 * polar_sync() fetches a window of days — 28 the first time, after that from
 * the last sync with three days' overlap, because a watch reaches Polar Flow
 * when it is synced, not when it measured — and hands everything to the one
 * importer (includes/health-import.php) as Ownify records. Every record has
 * Polar's own id, so fetching a day again rewrites it rather than adding to
 * it. Nothing is estimated: a figure Polar does not send stays empty.
 *
 * Nothing here ever reaches a browser or a log: no token, no code, no secret,
 * no response body. The owner sees one sentence (user_integrations.last_error).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/integrations.php';

if (!function_exists('polar_config')) {

    /* ================================================================ setup */

    /** The configuration, environment first (config/integrations.php). */
    function polar_config(): array
    {
        $config = integration_config('polar');

        foreach (['client_id', 'client_secret', 'redirect_uri', 'authorize_url', 'token_url', 'api_base'] as $key) {
            $config[$key] = trim((string) ($config[$key] ?? ''));
        }

        $config['scopes']       = array_values(array_filter((array) ($config['scopes'] ?? []), 'is_string'));
        $config['initial_days'] = max(1, min(28, (int) ($config['initial_days'] ?? 28)));
        $config['overlap_days'] = max(1, min(7, (int) ($config['overlap_days'] ?? 3)));

        return $config;
    }

    /** An id and a secret that are real — not empty, not the example's words. */
    function polar_credentials_present(): bool
    {
        $config = polar_config();

        foreach (['client_id', 'client_secret'] as $key) {
            if (integration_placeholder($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /** Migration 020: the state table, the sync marker and the source. */
    function polar_stored(): bool
    {
        static $stored = null;

        if ($stored === null) {
            $stored = db_available()
                && db_value("SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_oauth_states'") > 0
                && db_value("SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_integrations'
                                AND COLUMN_NAME = 'sync_started_at'") > 0
                && db_value("SELECT COUNT(*) FROM data_sources WHERE code = 'polar'") > 0;
        }

        return $stored;
    }

    /**
     * Why Polar cannot be connected right now, for the owner — or null. Asked
     * by integration_blocked_reason(), on top of what every cloud source needs.
     */
    function polar_problem(): ?string
    {
        if (!polar_credentials_present()) {
            return 'Deze koppeling is op de server nog niet ingesteld.';
        }

        if (!polar_stored()) {
            return 'De database is nog niet bijgewerkt voor Polar (migratie 020).';
        }

        return null;
    }

    /* ================================================================= HTTP */

    /**
     * One request to Polar. Never logs a body, a token or a query string.
     *
     * @return array{status: int, body: string, headers: array<string, string>}|null  null: no answer at all
     */
    function polar_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 20): ?array
    {
        if (!function_exists('curl_init')) {
            error_log('[polar] the curl extension is missing');
            return null;
        }

        $received = [];
        $handle   = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($handle);
        unset($handle);

        if (!is_string($response)) {
            error_log('[polar] ' . $method . ' ' . (string) parse_url($url, PHP_URL_HOST)
                . (string) parse_url($url, PHP_URL_PATH) . ' failed: ' . $error);
            return null;
        }

        return ['status' => $status, 'body' => $response, 'headers' => $received];
    }

    /**
     * The token endpoint: Basic auth with the client's id and secret, a form
     * body. Used for the code and for a refresh.
     *
     * @return array{ok: bool, status: int, data: array, error: ?string}
     */
    function polar_token_request(array $form): array
    {
        $config = polar_config();
        $basic  = base64_encode($config['client_id'] . ':' . $config['client_secret']);

        $response = polar_http('POST', $config['token_url'], [
            'Authorization: Basic ' . $basic,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ], http_build_query($form, '', '&'));

        if ($response === null) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'unreachable'];
        }

        $data = json_decode($response['body'], true);
        $data = is_array($data) ? $data : [];
        $ok   = $response['status'] === 200 && is_string($data['access_token'] ?? null) && $data['access_token'] !== '';

        if (!$ok) {
            /* Polar's error code (invalid_grant, invalid_client…) is safe to
               log; the rest of the answer is not logged. */
            error_log(sprintf('[polar] token request (%s) refused: HTTP %d %s',
                (string) ($form['grant_type'] ?? '?'), $response['status'],
                is_string($data['error'] ?? null) ? $data['error'] : ''));
        }

        return [
            'ok'     => $ok,
            'status' => $response['status'],
            'data'   => $data,
            'error'  => $ok ? null : (is_string($data['error'] ?? null) ? $data['error'] : 'http_' . $response['status']),
        ];
    }

    /** What the token endpoint answered, as stored: the expiry a minute early. */
    function polar_tokens_from(array $data): array
    {
        $seconds = (int) ($data['expires_in'] ?? 0);

        return [
            'access_token'     => (string) $data['access_token'],
            'refresh_token'    => is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? $data['refresh_token'] : null,
            'token_expires_at' => $seconds > 0 ? date('Y-m-d H:i:s', time() + max(60, $seconds - 60)) : null,
            'scopes'           => is_string($data['scope'] ?? null) ? mb_substr(trim($data['scope']), 0, 1000) : null,
        ];
    }

    /* =========================================================== connecting */

    function polar_b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * What a web state is bound to: a random value kept in this browser's
     * session — not the session id, which a sign-in kept alive may renew
     * between leaving for Polar and coming back. Another browser, or this
     * one signed out in between, does not have it.
     */
    function polar_session_hash(bool $create = false): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        if (!is_string($_SESSION['polar_binding'] ?? null)) {
            if (!$create) {
                return null;
            }
            $_SESSION['polar_binding'] = polar_b64url(random_bytes(32));
        }

        return hash('sha256', 'ownify-polar|' . $_SESSION['polar_binding']);
    }

    /**
     * Starts connecting, for the signed-in user. `client` is where it was
     * asked: 'web' (this browser session finishes it) or 'app' (the phone's
     * browser, which has no session: the callback asks to confirm).
     *
     * @return array{ok: bool, url: ?string, error: ?string, status: int}
     */
    function polar_begin(int $userId, string $client): array
    {
        if (($problem = integration_blocked_reason('polar')) !== null) {
            return ['ok' => false, 'url' => null, 'error' => $problem, 'status' => 503];
        }

        if (!in_array($client, ['web', 'app'], true)) {
            return ['ok' => false, 'url' => null, 'error' => 'Onbekende herkomst.', 'status' => 400];
        }

        /* Old states go; a person starting over and over is slowed down. */
        db_run('DELETE FROM integration_oauth_states WHERE expires_at < NOW() - INTERVAL 1 DAY');

        $recent = (int) db_value(
            "SELECT COUNT(*) FROM integration_oauth_states
              WHERE user_id = ? AND provider = 'polar' AND created_at > NOW() - INTERVAL 10 MINUTE",
            [$userId]
        );

        if ($recent >= 10) {
            return ['ok' => false, 'url' => null, 'error' => 'Je hebt het koppelen net een paar keer gestart. Probeer het over tien minuten opnieuw.', 'status' => 429];
        }

        $sessionHash = null;

        if ($client === 'web') {
            $sessionHash = polar_session_hash(true);

            if ($sessionHash === null) {
                return ['ok' => false, 'url' => null, 'error' => 'Je bent niet ingelogd.', 'status' => 401];
            }
        }

        $state  = polar_b64url(random_bytes(32));
        $config = polar_config();

        db_run(
            'INSERT INTO integration_oauth_states (user_id, provider, state_hash, client, session_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL 10 MINUTE)',
            [$userId, 'polar', hash('sha256', $state), $client, $sessionHash]
        );

        $query = [
            'response_type' => 'code',
            'client_id'     => $config['client_id'],
            'redirect_uri'  => $config['redirect_uri'],
            'scope'         => implode(' ', $config['scopes']),
            'state'         => $state,
        ];

        return [
            'ok'     => true,
            'url'    => $config['authorize_url'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'error'  => null,
            'status' => 200,
        ];
    }

    /** The state row for a state from a request, or null. Never trusted for more than its hash. */
    function polar_state_row(string $state): ?array
    {
        if ($state === '' || strlen($state) > 128 || !polar_stored()) {
            return null;
        }

        return db_one(
            "SELECT * FROM integration_oauth_states WHERE state_hash = ? AND provider = 'polar'",
            [hash('sha256', $state)]
        );
    }

    /** Claims a state once: true for exactly one caller. */
    function polar_claim_state(int $id): bool
    {
        $statement = db_run(
            'UPDATE integration_oauth_states SET used_at = NOW(), pending_code = NULL
              WHERE id = ? AND used_at IS NULL AND expires_at > NOW()',
            [$id]
        );

        return $statement !== null && $statement->rowCount() === 1;
    }

    /**
     * Polar has sent the browser back. Everything about whose connection this
     * is comes from the state row — never from the query.
     *
     * @return array{outcome: string, message: string, client: ?string, confirm?: array}
     *   outcome: connected | confirm | cancelled | error
     */
    function polar_callback(array $query, ?int $sessionUserId): array
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $row   = polar_state_row($state);

        if ($row === null || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()) {
            return ['outcome' => 'error', 'client' => $row['client'] ?? null,
                    'message' => 'Deze koppelpoging is verlopen of al gebruikt. Start het koppelen opnieuw vanuit Ownify.'];
        }

        $client = (string) $row['client'];
        $userId = (int) $row['user_id'];

        /* Refused or broken off at Polar: the state is used up either way. */
        if (isset($query['error'])) {
            polar_claim_state((int) $row['id']);
            $cancelled = $query['error'] === 'access_denied';

            return [
                'outcome' => $cancelled ? 'cancelled' : 'error',
                'client'  => $client,
                'message' => $cancelled
                    ? 'Je hebt Polar geen toegang gegeven. Er is niets gekoppeld.'
                    : 'Polar kon de koppeling niet starten. Probeer het later opnieuw.',
            ];
        }

        $code = is_string($query['code'] ?? null) ? trim($query['code']) : '';

        if ($code === '' || strlen($code) > 512) {
            polar_claim_state((int) $row['id']);
            return ['outcome' => 'error', 'client' => $client, 'message' => 'Polar stuurde geen geldige code terug. Start het koppelen opnieuw.'];
        }

        /* The web: only the browser session that started it, signed in as
           the same person, finishes it. */
        if ($client === 'web') {
            if ($row['session_hash'] === null || !hash_equals((string) $row['session_hash'], (string) polar_session_hash())
                || $sessionUserId !== $userId) {
                polar_claim_state((int) $row['id']);
                return ['outcome' => 'error', 'client' => $client,
                        'message' => 'Deze koppeling is in een andere browser of door een ander account gestart. Start het koppelen opnieuw vanuit Instellingen.'];
            }

            if (!polar_claim_state((int) $row['id'])) {
                return ['outcome' => 'error', 'client' => $client, 'message' => 'Deze koppelpoging is al gebruikt.'];
            }

            return polar_finish($userId, $code, $client);
        }

        /* The app's browser. Already signed in here as the same person:
           nothing to confirm. */
        if ($sessionUserId === $userId) {
            if (!polar_claim_state((int) $row['id'])) {
                return ['outcome' => 'error', 'client' => $client, 'message' => 'Deze koppelpoging is al gebruikt.'];
            }

            return polar_finish($userId, $code, $client);
        }

        /* Otherwise the person confirms which Ownify account Polar goes to,
           on a page with a one-time token no other site can read. The code
           waits, sealed, beside the state — once. */
        if (!crypto_available()) {
            polar_claim_state((int) $row['id']);
            return ['outcome' => 'error', 'client' => $client, 'message' => 'De server kan tokens niet veilig opslaan.'];
        }

        $confirm   = polar_b64url(random_bytes(32));
        $statement = db_run(
            'UPDATE integration_oauth_states SET pending_code = ?, confirm_hash = ?
              WHERE id = ? AND used_at IS NULL AND pending_code IS NULL AND confirm_hash IS NULL AND expires_at > NOW()',
            [crypto_seal($code), hash('sha256', $confirm), (int) $row['id']]
        );

        if ($statement === null || $statement->rowCount() !== 1) {
            return ['outcome' => 'error', 'client' => $client, 'message' => 'Deze koppelpoging is al gebruikt.'];
        }

        $username = (string) db_value('SELECT username FROM users WHERE id = ?', [$userId]);

        /* Signed in to Ownify in this browser as somebody else: the page says
           so plainly — somebody sent this link who should not have. */
        $other = $sessionUserId !== null
            ? (string) db_value('SELECT username FROM users WHERE id = ?', [$sessionUserId])
            : null;

        return [
            'outcome' => 'confirm',
            'client'  => $client,
            'message' => '',
            'confirm' => ['token' => $confirm, 'username' => $username, 'signed_in_as' => $other ?: null],
        ];
    }

    /**
     * The confirmation page answered: 'confirm' connects Polar to the account
     * the page named, 'cancel' throws the code away.
     *
     * @return array{outcome: string, message: string, client: ?string}
     */
    function polar_confirm(string $token, string $action): array
    {
        $row = ($token !== '' && strlen($token) <= 128 && polar_stored())
            ? db_one("SELECT * FROM integration_oauth_states WHERE confirm_hash = ? AND provider = 'polar'", [hash('sha256', $token)])
            : null;

        if ($row === null || $row['pending_code'] === null) {
            return ['outcome' => 'error', 'client' => 'app', 'message' => 'Deze koppelpoging is verlopen of al gebruikt. Start het koppelen opnieuw vanuit de app.'];
        }

        $code = crypto_open($row['pending_code']);

        if (!polar_claim_state((int) $row['id']) || !is_string($code) || $code === '') {
            return ['outcome' => 'error', 'client' => 'app', 'message' => 'Deze koppelpoging is verlopen of al gebruikt. Start het koppelen opnieuw vanuit de app.'];
        }

        if ($action !== 'confirm') {
            return ['outcome' => 'cancelled', 'client' => 'app', 'message' => 'Er is niets gekoppeld.'];
        }

        return polar_finish((int) $row['user_id'], $code, 'app');
    }

    /** The code, exchanged; the tokens stored for `$userId`. */
    function polar_finish(int $userId, string $code, string $client): array
    {
        $answer = polar_token_request([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => polar_config()['redirect_uri'],
        ]);

        if (!$answer['ok']) {
            return ['outcome' => 'error', 'client' => $client,
                    'message' => $answer['status'] === 0
                        ? 'Polar is nu niet bereikbaar. Probeer het later opnieuw.'
                        : 'Polar heeft de koppeling geweigerd. Start het koppelen opnieuw.'];
        }

        $tokens = polar_tokens_from($answer['data']);
        $stored = integration_connect($userId, 'polar', $tokens + ['external_account_label' => null]);

        if (!$stored['ok']) {
            return ['outcome' => 'error', 'client' => $client, 'message' => (string) $stored['error']];
        }

        /* A connection made again starts with a fresh history window. */
        db_run("UPDATE user_integrations SET last_sync_status = 'never', last_sync_at = NULL, last_error = NULL
                 WHERE user_id = ? AND provider = 'polar'", [$userId]);

        return ['outcome' => 'connected', 'client' => $client, 'user_id' => $userId,
                'message' => 'Polar is gekoppeld. Je gegevens worden nu opgehaald.'];
    }

    /* =============================================================== tokens */

    /**
     * A working access token for the user, refreshed when it has (nearly)
     * run out — or why there is none.
     *
     * @return array{token: ?string, problem: ?string}  problem: revoked | disconnected | unreachable
     */
    function polar_access_token(int $userId, bool $forceRefresh = false): array
    {
        $row = integration_get($userId, 'polar');

        if ($row === null || !in_array($row['status'], ['connected', 'error'], true)) {
            return ['token' => null, 'problem' => 'disconnected'];
        }

        $tokens = integration_tokens($userId, 'polar');

        if ($tokens === null || ($tokens['access_token'] === null && $tokens['refresh_token'] === null)) {
            return ['token' => null, 'problem' => 'disconnected'];
        }

        if (!$forceRefresh && $tokens['access_token'] !== null && !$tokens['expired']) {
            return ['token' => $tokens['access_token'], 'problem' => null];
        }

        if ($tokens['refresh_token'] === null) {
            integration_mark_revoked($userId, 'polar', 'Polar-toegang is verlopen. Koppel Polar opnieuw.');
            return ['token' => null, 'problem' => 'revoked'];
        }

        $answer = polar_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);

        if (!$answer['ok']) {
            /* No answer is not a refusal: keep everything, try again later. */
            if ($answer['status'] === 0 || $answer['status'] >= 500 || $answer['status'] === 429) {
                return ['token' => null, 'problem' => 'unreachable'];
            }

            integration_mark_revoked($userId, 'polar', 'Polar heeft de toegang ingetrokken. Koppel Polar opnieuw.');
            return ['token' => null, 'problem' => 'revoked'];
        }

        $fresh = polar_tokens_from($answer['data']);
        integration_store_tokens($userId, 'polar', $fresh['access_token'], $fresh['refresh_token'], $fresh['token_expires_at']);

        return ['token' => $fresh['access_token'], 'problem' => null];
    }

    /* ================================================================== API */

    /**
     * One GET on the data API, for the user in `$ctx`. Refreshes the token
     * once on a 401, retries once on a server error, stops the whole sync on
     * a 429 or a refused refresh.
     *
     * @param array $ctx {user_id, token, requests, limited, revoked, budget}
     * @return array{status: string, data: array}
     *   status: ok | empty | forbidden | limited | revoked | error
     */
    function polar_get(array &$ctx, string $path, array $query = [], array $features = []): array
    {
        if ($ctx['limited'] || $ctx['revoked']) {
            return ['status' => $ctx['revoked'] ? 'revoked' : 'limited', 'data' => []];
        }

        if ($ctx['requests'] >= $ctx['budget']) {
            $ctx['limited'] = true;
            return ['status' => 'limited', 'data' => []];
        }

        $url = rtrim(polar_config()['api_base'], '/') . $path;
        $qs  = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        foreach ($features as $feature) {
            $qs .= ($qs === '' ? '' : '&') . 'features=' . rawurlencode($feature);
        }

        $url .= $qs === '' ? '' : '?' . $qs;
        $retried   = false;
        $refreshed = false;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($ctx['token'] === null) {
                $access = polar_access_token($ctx['user_id']);
                if ($access['token'] === null) {
                    $ctx['revoked'] = $access['problem'] !== 'unreachable';
                    return ['status' => $ctx['revoked'] ? 'revoked' : 'error', 'data' => []];
                }
                $ctx['token'] = $access['token'];
            }

            $ctx['requests']++;
            $response = polar_http('GET', $url, ['Authorization: Bearer ' . $ctx['token'], 'Accept: application/json']);

            if ($response === null || $response['status'] >= 500) {
                if (!$retried) {
                    $retried = true;
                    usleep(700000);
                    continue;
                }
                return ['status' => 'error', 'data' => []];
            }

            switch (true) {
                case $response['status'] === 200:
                    $data = json_decode($response['body'], true);
                    return is_array($data) ? ['status' => 'ok', 'data' => $data] : ['status' => 'error', 'data' => []];

                case $response['status'] === 204 || $response['status'] === 404:
                    return ['status' => 'empty', 'data' => []];

                case $response['status'] === 401:
                    if (!$refreshed) {
                        $refreshed = true;
                        $access = polar_access_token($ctx['user_id'], true);
                        if ($access['token'] === null) {
                            $ctx['revoked'] = $access['problem'] !== 'unreachable';
                            return ['status' => $ctx['revoked'] ? 'revoked' : 'error', 'data' => []];
                        }
                        $ctx['token'] = $access['token'];
                        continue 2;
                    }
                    integration_mark_revoked($ctx['user_id'], 'polar', 'Polar heeft de toegang ingetrokken. Koppel Polar opnieuw.');
                    $ctx['revoked'] = true;
                    return ['status' => 'revoked', 'data' => []];

                case $response['status'] === 403:
                    return ['status' => 'forbidden', 'data' => []];

                case $response['status'] === 429:
                    $ctx['limited'] = true;
                    return ['status' => 'limited', 'data' => []];

                default:
                    error_log('[polar] GET ' . $path . ' answered HTTP ' . $response['status']);
                    return ['status' => 'error', 'data' => []];
            }
        }

        return ['status' => 'error', 'data' => []];
    }

    /* ============================================================== mapping */

    /**
     * A Polar local time as ISO 8601 with its offset, so the importer keeps
     * the clock it was recorded on. Polar sends trainings' times as local
     * times without an offset, plus the offset in minutes beside them.
     */
    function polar_local_time(?string $value, ?int $offsetMinutes): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $zone  = $offsetMinutes === null ? null
            : new DateTimeZone(sprintf('%s%02d:%02d', $offsetMinutes < 0 ? '-' : '+', intdiv(abs($offsetMinutes), 60), abs($offsetMinutes) % 60));

        try {
            $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);
            $time    = $hasZone || $zone === null ? new DateTimeImmutable($value) : new DateTimeImmutable($value, $zone);

            if ($hasZone && $zone !== null) {
                $time = $time->setTimezone($zone);
            }

            return $time->format(DATE_ATOM);
        } catch (Exception $e) {
            return null;
        }
    }

    /** "28800s" or "3.5s" — Polar's durations — in seconds. */
    function polar_seconds(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)s?$/', trim($value), $m)) {
            return (float) $m[1];
        }

        return null;
    }

    function polar_number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    function polar_origin(?string $device): string
    {
        $device = $device === null ? '' : trim($device);

        return 'polar' . ($device === '' ? '' : ':' . mb_substr($device, 0, 150));
    }

    /** The days from `$from` up to and including `$to`, as Y-m-d. */
    function polar_days(string $from, string $to): array
    {
        $days = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    function polar_next_day(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    /** sports/list as id => name ("RUNNING", "ROAD_BIKING"). */
    function polar_sports(array &$ctx): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $answer = polar_get($ctx, '/sports/list');
        $sports = [];

        foreach ((array) ($answer['data']['sports'] ?? []) as $sport) {
            $id = $sport['id']['id'] ?? null;
            if ($id !== null && is_string($sport['name'] ?? null) && $sport['name'] !== '') {
                $sports[(string) $id] = $sport['name'];
            }
        }

        if ($answer['status'] === 'ok') {
            $cache = $sports;
        }

        return $sports;
    }

    /**
     * One training session as an Ownify workout. What Polar does not send
     * stays empty.
     */
    function polar_map_training(array $s, array $sports): ?array
    {
        $id     = is_string($s['identifier']['id'] ?? null) ? $s['identifier']['id'] : null;
        $offset = isset($s['timezoneOffsetMinutes']) && is_numeric($s['timezoneOffsetMinutes']) ? (int) $s['timezoneOffsetMinutes'] : null;
        $start  = polar_local_time($s['startTime'] ?? null, $offset);

        if ($id === null || $start === null) {
            return null;
        }

        $sportId = $s['sport']['id'] ?? ($s['exercises'][0]['sport']['id'] ?? null);
        $name    = $sportId !== null ? ($sports[(string) $sportId] ?? null) : null;
        $name  ??= is_string($s['name'] ?? null) && trim($s['name']) !== '' ? trim($s['name']) : 'other';

        $ascent = null;
        foreach ((array) ($s['exercises'] ?? []) as $exercise) {
            if (is_numeric($exercise['ascentMeters'] ?? null)) {
                $ascent = ($ascent ?? 0) + (float) $exercise['ascentMeters'];
            }
        }

        $millis = polar_number($s['durationMillis'] ?? null);

        return [
            'type'             => 'workout',
            'external_id'      => 'polar:training:' . $id,
            'started_at'       => $start,
            'ended_at'         => polar_local_time($s['stopTime'] ?? null, $offset),
            'duration_seconds' => $millis === null ? null : (int) round($millis / 1000),
            'activity_type'    => mb_strtolower(mb_substr($name, 0, 40)),
            'distance_m'       => polar_number($s['distanceMeters'] ?? null),
            'total_kcal'       => polar_number($s['calories'] ?? null),
            'avg_hr'           => polar_number($s['hrAvg'] ?? null),
            'max_hr'           => polar_number($s['hrMax'] ?? null),
            'elevation_gain_m' => $ascent,
        ];
    }

    /**
     * A training's heart-rate samples (features=samples), minute by minute,
     * on the training's own clock.
     */
    function polar_training_heart(array $s): ?array
    {
        $offset = isset($s['timezoneOffsetMinutes']) && is_numeric($s['timezoneOffsetMinutes']) ? (int) $s['timezoneOffsetMinutes'] : null;
        $id     = is_string($s['identifier']['id'] ?? null) ? $s['identifier']['id'] : null;
        $points = [];

        foreach ((array) ($s['exercises'] ?? []) as $exercise) {
            $start = polar_local_time($exercise['startTime'] ?? ($s['startTime'] ?? null), $offset);

            if ($start === null) {
                continue;
            }

            $from = new DateTimeImmutable($start);

            foreach ((array) ($exercise['samples']['samples'] ?? []) as $series) {
                if (($series['type'] ?? null) !== 'HEART_RATE' || !is_numeric($series['intervalMillis'] ?? null)) {
                    continue;
                }

                $step = max(1, (int) $series['intervalMillis']);

                foreach (array_values((array) ($series['values'] ?? [])) as $i => $value) {
                    if (!is_numeric($value) || (float) $value <= 0) {
                        continue;                               // no reading at that moment
                    }
                    $points[] = [
                        'time'           => $from->modify('+' . intdiv($i * $step, 1000) . ' seconds')->format(DATE_ATOM),
                        'beatsPerMinute' => (float) $value,
                    ];
                }
            }
        }

        if ($id === null || $points === []) {
            return null;
        }

        return [
            'type'        => 'heart_rate',
            'external_id' => 'polar:training-hr:' . $id,
            'data_origin' => polar_origin(is_string($s['deviceId'] ?? null) ? $s['deviceId'] : null),
            'minutes'     => health_connect_heart_minutes($points),
        ];
    }

    /** Polar's sleep states as Health Connect's stages, which Ownify keeps. */
    function polar_sleep_stage(string $state): int
    {
        return match ($state) {
            'SLEEP_STATE_WAKE'                            => HC_STAGE_AWAKE,
            'SLEEP_STATE_REM'                             => HC_STAGE_REM,
            'SLEEP_STATE_NON_REM1', 'SLEEP_STATE_NON_REM2' => HC_STAGE_LIGHT,
            'SLEEP_STATE_NON_REM3'                        => HC_STAGE_DEEP,
            default                                       => 0,     // unknown (e.g. poor skin contact): said nothing
        };
    }

    /**
     * One night (features=sleep-result,sleep-evaluation) as an Ownify sleep,
     * worked out by the same rules as a Health Connect night
     * (health_connect_sleep()): asleep, in bed, awake and the stages from the
     * hypnogram, so the two sources cannot disagree on what a night is.
     */
    function polar_map_sleep(array $night): ?array
    {
        $date = is_string($night['sleepDate'] ?? null) ? $night['sleepDate'] : null;
        $hyp  = $night['sleepResult']['hypnogram'] ?? null;

        if ($date === null || !is_array($hyp) || !is_string($hyp['sleepStart'] ?? null) || !is_string($hyp['sleepEnd'] ?? null)) {
            return null;
        }

        try {
            $start = new DateTimeImmutable($hyp['sleepStart']);
            $end   = new DateTimeImmutable($hyp['sleepEnd']);
        } catch (Exception $e) {
            return null;
        }

        if ($end <= $start) {
            return null;
        }

        $changes = [];
        foreach ((array) ($hyp['sleepStateChanges'] ?? []) as $change) {
            $at = polar_seconds($change['offsetFromStart'] ?? null);
            if ($at !== null && is_string($change['newState'] ?? null)) {
                $changes[] = [$at, polar_sleep_stage($change['newState'])];
            }
        }
        usort($changes, static fn ($a, $b) => $a[0] <=> $b[0]);

        $stages = [];
        $length = $end->getTimestamp() - $start->getTimestamp();

        foreach ($changes as $i => [$at, $stage]) {
            $until = $changes[$i + 1][0] ?? $length;
            if ($stage === 0 || $until <= $at || $at >= $length) {
                continue;
            }
            $stages[] = [
                'stage'     => $stage,
                'startTime' => $start->modify('+' . (int) round($at) . ' seconds')->format(DATE_ATOM),
                'endTime'   => $start->modify('+' . (int) round(min($until, $length)) . ' seconds')->format(DATE_ATOM),
            ];
        }

        $records = health_connect_sleep('polar:sleep:' . $date, [
            'startTime' => $start->format(DATE_ATOM),
            'endTime'   => $end->format(DATE_ATOM),
            'stages'    => $stages,
        ]);

        if ($records === []) {
            return null;
        }

        $record             = $records[0];
        $record['night_of'] = $date;

        /* A night with no hypnogram (sleep time only): Polar's own measured
           sleep, rather than a night of unknown length. */
        if (!isset($record['duration_minutes'])) {
            $asleep = polar_seconds($night['sleepEvaluation']['asleepDuration'] ?? null);
            if ($asleep !== null && $asleep > 0) {
                $record['duration_minutes'] = (int) round($asleep / 60);
            }
        }

        return $record;
    }

    /**
     * One day of activity (features=samples): the steps per hour, per device,
     * each hour with its span so a day's total counts every moment once
     * against any other source (health_metric_totals()).
     */
    function polar_map_steps(array $day): array
    {
        $date = is_string($day['date'] ?? null) ? $day['date'] : null;
        $out  = [];

        if ($date === null) {
            return [];
        }

        foreach ((array) ($day['activitiesPerDevice'] ?? []) as $perDevice) {
            $device = is_string($perDevice['deviceReference']['deviceId'] ?? null) ? $perDevice['deviceReference']['deviceId'] : null;
            $hours  = [];
            $now    = new DateTimeImmutable();

            foreach ((array) ($perDevice['activitySamples'] ?? []) as $samples) {
                $steps = $samples['stepSamples'] ?? null;
                if (!is_array($steps) || !is_string($steps['startTime'] ?? null) || !is_numeric($steps['interval'] ?? null)) {
                    continue;
                }

                try {
                    $from = new DateTimeImmutable($date . 'T' . substr($steps['startTime'], 0, 8));
                } catch (Exception $e) {
                    continue;
                }

                $interval = max(1000, (int) $steps['interval']);

                foreach (array_values((array) ($steps['steps'] ?? [])) as $i => $count) {
                    if (!is_numeric($count) || (int) $count <= 0) {
                        continue;
                    }
                    $at = $from->modify('+' . intdiv($i * $interval, 1000) . ' seconds');
                    if ($at->format('Y-m-d') !== $date || $at > $now) {
                        continue;                               // the day's own buckets, none still to come
                    }
                    $hour = (int) $at->format('G');
                    $hours[$hour] = ($hours[$hour] ?? 0) + (int) $count;
                }
            }

            foreach ($hours as $hour => $count) {
                $start = sprintf('%sT%02d:00:00', $date, $hour);
                $end   = (new DateTimeImmutable($start))->modify('+1 hour');
                $now   = new DateTimeImmutable();
                $out[] = [
                    'type'        => 'metric',
                    'metric_code' => 'steps',
                    'external_id' => sprintf('polar:steps:%s:%s:%02d', $device ?? '-', $date, $hour),
                    'value'       => $count,
                    'started_at'  => $start,
                    'recorded_at' => ($end > $now ? $now : $end)->format('Y-m-d\TH:i:s'),     // the hour so far
                    'data_origin' => polar_origin($device),
                ];
            }
        }

        return $out;
    }

    /**
     * The 24/7 heart rate: one record per day and device, minute by minute,
     * on the day's own clock. Also returned as raw samples, for a night's
     * heart rate.
     *
     * @return array{records: array, samples: list<array{0: int, 1: float}>}  samples: [unix time, bpm]
     */
    function polar_map_continuous(array $data): array
    {
        $records = [];
        $samples = [];

        foreach ((array) ($data['continuousSamples']['heartRateSamplesPerDay'] ?? []) as $day) {
            $date   = is_string($day['date'] ?? null) ? $day['date'] : null;
            $device = is_string($day['deviceRef']['deviceId'] ?? null) ? $day['deviceRef']['deviceId'] : null;

            if ($date === null) {
                continue;
            }

            $midnight = new DateTimeImmutable($date . 'T00:00:00');
            $points   = [];

            foreach ((array) ($day['samples'] ?? []) as $sample) {
                if (!is_numeric($sample['heartRate'] ?? null) || !is_numeric($sample['offsetMillis'] ?? null) || (float) $sample['heartRate'] <= 0) {
                    continue;
                }
                $at       = $midnight->modify('+' . intdiv((int) $sample['offsetMillis'], 1000) . ' seconds');
                $points[] = ['time' => $at->format('Y-m-d\TH:i:s'), 'beatsPerMinute' => (float) $sample['heartRate']];
                $samples[] = [$at->getTimestamp(), (float) $sample['heartRate']];
            }

            if ($points === []) {
                continue;
            }

            $records[] = [
                'type'        => 'heart_rate',
                'external_id' => 'polar:hr:' . ($device ?? '-') . ':' . $date,
                'data_origin' => polar_origin($device),
                'minutes'     => health_connect_heart_minutes($points),
            ];
        }

        return ['records' => $records, 'samples' => $samples];
    }

    /**
     * Nightly Recharge: the night's HRV (RMSSD, ms) and breathing rate
     * (60 000 / the mean respiration interval in ms), on the morning the
     * night ended. Polar's recovery status itself is not kept: it is a class
     * of 1 to 6 relative to the person's own baseline, not a score Ownify has
     * a place for (docs/POLAR.md).
     */
    function polar_map_recharge(array $data, array $nightEnds): array
    {
        $out = [];

        foreach ((array) ($data['nightlyRechargeResults']['nightlyRechargeResults'] ?? []) as $result) {
            $date = is_string($result['sleepResultDate'] ?? null) ? $result['sleepResultDate'] : null;
            if ($date === null) {
                continue;
            }

            /* When the night ended, if Polar sent the night; otherwise the
               value belongs to the date and nothing more precise is said. */
            $at = $nightEnds[$date] ?? $date . 'T00:00:00';

            $rmssd = polar_number($result['meanNightlyRecoveryRmssd'] ?? null);
            if ($rmssd !== null && $rmssd > 0) {
                $out[] = ['type' => 'metric', 'metric_code' => 'hrv', 'external_id' => 'polar:recharge:' . $date . ':hrv',
                          'value' => $rmssd, 'recorded_at' => $at, 'data_origin' => 'polar'];
            }

            $interval = polar_number($result['meanNightlyRecoveryRespirationInterval'] ?? null);
            if ($interval !== null && $interval > 0) {
                $out[] = ['type' => 'metric', 'metric_code' => 'respiratory_rate', 'external_id' => 'polar:recharge:' . $date . ':breathing',
                          'value' => round(60000 / $interval, 1), 'recorded_at' => $at, 'data_origin' => 'polar'];
            }
        }

        return $out;
    }

    /* ================================================================= sync */

    /** A sync running now (started under 15 minutes ago). */
    function polar_syncing(?array $row): bool
    {
        return $row !== null && !empty($row['sync_started_at'])
            && strtotime((string) $row['sync_started_at']) > time() - 900;
    }

    /**
     * Fetches and imports. `$trigger`: connect | manual | schedule.
     *
     * @return array{ok: bool, status: string, message: string, written: int, requests: int}
     *   status: ok | partial | failed | busy | revoked | disconnected
     */
    function polar_sync(int $userId, string $trigger = 'manual'): array
    {
        require_once __DIR__ . '/health-connect-map.php';
        require_once __DIR__ . '/health-import.php';

        $row = integration_get($userId, 'polar');

        if ($row === null || !in_array($row['status'], ['connected', 'error'], true)) {
            return ['ok' => false, 'status' => 'disconnected', 'message' => 'Polar is niet gekoppeld.', 'written' => 0, 'requests' => 0];
        }

        /* One sync per person at a time: a second one returns at once. */
        $lock = 'ownify_polar_' . $userId;
        if ((int) db_value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return ['ok' => false, 'status' => 'busy', 'message' => 'Polar wordt al gesynchroniseerd.', 'written' => 0, 'requests' => 0];
        }

        db_run("UPDATE user_integrations SET sync_started_at = NOW() WHERE user_id = ? AND provider = 'polar'", [$userId]);

        try {
            $result = polar_sync_run($userId, $row, $trigger);
        } finally {
            db_run("UPDATE user_integrations SET sync_started_at = NULL WHERE user_id = ? AND provider = 'polar'", [$userId]);
            db_value('SELECT RELEASE_LOCK(?)', [$lock]);
        }

        return $result;
    }

    function polar_sync_run(int $userId, array $row, string $trigger): array
    {
        $config = polar_config();
        $today  = date('Y-m-d');
        $first  = $row['last_sync_at'] === null;

        /* The window: 28 days the first time; afterwards back to the last
           sync with a few days' overlap — never further than 28 days, the
           most Polar answers for Nightly Recharge in one request. */
        $back = $first
            ? $config['initial_days'] - 1
            : max($config['overlap_days'], (int) floor((time() - strtotime((string) $row['last_sync_at'])) / 86400) + $config['overlap_days']);
        $back = min(27, $back);
        $from = (new DateTimeImmutable($today))->modify('-' . $back . ' days')->format('Y-m-d');
        $to   = polar_next_day($today);                                     // exclusive

        $ctx = ['user_id' => $userId, 'token' => null, 'requests' => 0, 'limited' => false, 'revoked' => false,
                'budget' => $first ? 260 : 120];

        $records   = [];
        $failed    = [];          // categories that did not come through
        $forbidden = [];          // categories Polar refused (a scope or a consent)
        $note = static function (string $category, string $status) use (&$failed, &$forbidden): void {
            if ($status === 'forbidden') {
                $forbidden[$category] = true;
            } elseif (!in_array($status, ['ok', 'empty'], true)) {
                $failed[$category] = true;
            }
        };

        /* ---- devices: what the screen calls the connection */
        $devices = polar_get($ctx, '/user-devices');
        $note('apparaten', $devices['status']);
        if ($devices['status'] === 'ok') {
            $names  = [];
            $active = [];
            foreach ((array) ($devices['data']['userDevicesData']['activeDevices'] ?? []) as $device) {
                if (is_string($device['deviceReference']['uuid'] ?? null)) {
                    $active[$device['deviceReference']['uuid']] = true;
                }
            }
            foreach ((array) ($devices['data']['devicesData'] ?? []) as $device) {
                $uuid = $device['deviceReference']['uuid'] ?? null;
                $name = $device['productVariant']['productDescription'] ?? null;
                if (is_string($name) && trim($name) !== '' && ($active === [] || isset($active[$uuid]))) {
                    $names[trim($name)] = true;
                }
            }
            $label = $names === [] ? null : mb_substr(implode(', ', array_keys($names)), 0, 191);
            db_run("UPDATE user_integrations SET external_account_label = ? WHERE user_id = ? AND provider = 'polar'", [$label, $userId]);
        }

        /* ---- trainings: the list over the window, then each training day's
                heart rate (features: one day per request) */
        $sports    = polar_sports($ctx);
        $trainings = polar_get($ctx, '/training-sessions/list', ['from' => $from, 'to' => $to]);
        $note('trainingen', $trainings['status']);
        $trainingDays = [];
        foreach ((array) ($trainings['data']['trainingSessions'] ?? []) as $session) {
            $mapped = polar_map_training((array) $session, $sports);
            if ($mapped !== null) {
                $records[] = $mapped;
                $trainingDays[substr($mapped['started_at'], 0, 10)] = true;
            }
        }
        /* Kept apart and imported last: a training's minutes (a reading a
           second) then win over the 24/7 reading every few minutes from the
           same watch, which would otherwise overwrite them. */
        $trainingHeart = [];
        foreach (array_keys($trainingDays) as $day) {
            $detail = polar_get($ctx, '/training-sessions/list', ['from' => $day, 'to' => polar_next_day($day)], ['samples']);
            $note('hartslag tijdens trainingen', $detail['status']);
            foreach ((array) ($detail['data']['trainingSessions'] ?? []) as $session) {
                if (($heart = polar_training_heart((array) $session)) !== null) {
                    $trainingHeart[] = $heart;
                }
            }
        }

        /* ---- sleep: which nights exist, then each night in full */
        $nightEnds = [];
        $nights    = polar_get($ctx, '/sleeps', ['from' => $from, 'to' => $to]);
        $note('slaap', $nights['status']);
        $nightRecords = [];
        foreach ((array) ($nights['data']['nightSleeps'] ?? []) as $night) {
            $date = is_string($night['sleepDate'] ?? null) ? $night['sleepDate'] : null;
            if ($date === null || $date < $from || $date >= $to) {
                continue;
            }
            $full = polar_get($ctx, '/sleeps', ['from' => $date, 'to' => polar_next_day($date)], ['sleep-result', 'sleep-evaluation']);
            $note('slaap', $full['status']);
            foreach ((array) ($full['data']['nightSleeps'] ?? []) as $one) {
                if (($mapped = polar_map_sleep((array) $one)) !== null) {
                    $nightRecords[] = $mapped;
                    $nightEnds[$mapped['night_of']] = $mapped['ended_at'];
                }
            }
        }
        array_push($records, ...$nightRecords);

        /* ---- 24/7 heart rate, the whole window at once (30 days at most),
                and each night's heart rate from it */
        $continuous = polar_get($ctx, '/continuous-samples', ['from' => $from, 'to' => $to], ['heart-rate-samples']);
        $note('hartslag', $continuous['status']);
        $heart = polar_map_continuous($continuous['data']);
        array_push($records, ...$heart['records']);

        foreach ($nightRecords as $night) {
            $a = strtotime($night['started_at']);
            $b = strtotime($night['ended_at']);
            $sum = 0.0;
            $n   = 0;
            foreach ($heart['samples'] as [$at, $bpm]) {
                if ($at >= $a && $at < $b) {
                    $sum += $bpm;
                    $n++;
                }
            }
            if ($n > 0) {
                $records[] = ['type' => 'metric', 'metric_code' => 'sleeping_hr', 'external_id' => 'polar:sleep-hr:' . $night['night_of'],
                              'value' => round($sum / $n, 1), 'recorded_at' => $night['ended_at'], 'data_origin' => 'polar'];
            }
        }

        /* ---- Nightly Recharge */
        $recharge = polar_get($ctx, '/nightly-recharge-results', ['from' => $from, 'to' => $to]);
        $note('nightly recharge', $recharge['status']);
        array_push($records, ...polar_map_recharge($recharge['data'], $nightEnds));

        /* ---- steps: which days exist, then each day's samples */
        $days = polar_get($ctx, '/activity/list', ['from' => $from, 'to' => $to]);
        $note('activiteit', $days['status']);
        foreach ((array) ($days['data']['activities']['activityDays'] ?? []) as $day) {
            $date = is_string($day['date'] ?? null) ? $day['date'] : null;
            if ($date === null || $date < $from || $date >= $to) {
                continue;
            }
            $full = polar_get($ctx, '/activity/list', ['from' => $date, 'to' => polar_next_day($date)], ['samples']);
            $note('activiteit', $full['status']);
            foreach ((array) ($full['data']['activities']['activityDays'] ?? []) as $one) {
                array_push($records, ...polar_map_steps((array) $one));
            }
        }

        array_push($records, ...$trainingHeart);

        /* ---- import: one door in, as every source */
        $written = 0;
        $skipped = 0;
        if ($records !== [] && !$ctx['revoked']) {
            $imported = health_import_records($userId, 'polar', $records);
            $written  = (int) ($imported['written'] ?? 0);
            $skipped  = (int) ($imported['skipped'] ?? 0);
            if (!$imported['ok']) {
                $failed['opslaan'] = true;
            }
        }

        /* ---- how it went, in one sentence for the owner */
        if ($ctx['revoked']) {
            return ['ok' => false, 'status' => 'revoked', 'message' => 'Polar heeft de toegang ingetrokken. Koppel Polar opnieuw.', 'written' => $written, 'requests' => $ctx['requests']];
        }

        $message = null;
        if ($ctx['limited']) {
            $message = 'Polar beperkt het aantal verzoeken even. De rest wordt bij de volgende synchronisatie opgehaald.';
        } elseif ($forbidden !== []) {
            $message = 'Polar gaf geen toegang tot ' . implode(', ', array_keys($forbidden))
                . '. Accepteer de toestemmingen in je Polar-account (account.polar.com) en koppel Polar opnieuw.';
        } elseif ($failed !== []) {
            $message = 'Niet alles kon worden opgehaald (' . implode(', ', array_keys($failed)) . '). Het wordt later opnieuw geprobeerd.';
        }

        $everything = count($failed) + count($forbidden) >= 6 && $records === [];
        $status     = $message === null ? 'ok' : ($everything ? 'failed' : 'partial');

        integration_note_sync($userId, 'polar', $status, $message);

        if ($skipped > 0) {
            error_log(sprintf('[polar] user %d: %d record(s) skipped by the importer', $userId, $skipped));
        }

        return [
            'ok'       => $status !== 'failed',
            'status'   => $status,
            'message'  => $message ?? ($written > 0 ? 'Polar is gesynchroniseerd.' : 'Polar is gesynchroniseerd. Er was niets nieuws.'),
            'written'  => $written,
            'requests' => $ctx['requests'],
        ];
    }

    /**
     * Disconnects Polar for the user: the tokens are destroyed here. Polar's
     * v4 API offers no way for a service to withdraw its own access, so the
     * person is told where to remove Ownify at Polar as well.
     */
    function polar_disconnect(int $userId): bool
    {
        if (polar_stored()) {
            db_run("DELETE FROM integration_oauth_states WHERE user_id = ? AND provider = 'polar'", [$userId]);
        }

        return integration_disconnect($userId, 'polar');
    }
}
