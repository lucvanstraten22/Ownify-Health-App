<?php
/**
 * Google Health — the Google Health API (v4), read server to server. PRIVATE.
 *
 * docs/GOOGLE-HEALTH.md has the whole story; this is the code. It is the
 * cloud way to Fitbit and Pixel Watch data — through the person's Google
 * account, no phone app needed. Health Connect (the phone) is a different
 * source, read by the Ownify Android app.
 *
 * ---------------------------------------------------------------------------
 * CONNECTING (OAuth 2.0, authorization code — as Polar, includes/polar.php)
 * ---------------------------------------------------------------------------
 *   1. google_health_begin()    a signed-in person asks to connect: a one-time
 *                               state (only its hash stored), bound to this
 *                               browser session (web) or confirmed on a page
 *                               (app); the browser goes to Google's consent
 *                               screen with access_type=offline, so a refresh
 *                               token comes back.
 *   2. google_health_callback() / _confirm()   the state is checked exactly as
 *                               Polar's; only then is the code exchanged and
 *                               the tokens stored, sealed (includes/crypto.php).
 *
 * An access token lasts an hour; google_health_access_token() refreshes it.
 * A refresh Google refuses (invalid_grant) means access is gone — withdrawn
 * by the person, or ended by Google: a Google Cloud project still in
 * "Testing" ends its refresh tokens after 7 days. The connection is then
 * marked revoked and the screen asks to connect again.
 *
 * ---------------------------------------------------------------------------
 * SYNCING
 * ---------------------------------------------------------------------------
 * google_health_sync() lists each data type over a window — 30 days the
 * first time, then from the last sync with three days' overlap — and turns
 * the data points into Ownify records, mostly through the Health Connect
 * mapping (includes/health-connect-map.php), so a night, a step or a weight
 * means the same whichever way it arrived. Every record carries Google's own
 * id: fetching a day again rewrites it. Nothing is estimated.
 *
 * Data Google got from Health Connect is left out for a person who also
 * syncs Health Connect with the Ownify app: it arrives that way already.
 *
 * Nothing here ever reaches a browser or a log: no token, no code, no secret,
 * no response body. The owner sees one sentence (user_integrations.last_error).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/integrations.php';

if (!function_exists('google_health_config')) {

    /* ================================================================ setup */

    /** The configuration, environment first (config/integrations.php). */
    function google_health_config(): array
    {
        $config = integration_config('google_health');

        foreach (['client_id', 'client_secret', 'redirect_uri', 'authorize_url', 'token_url', 'revoke_url', 'api_base'] as $key) {
            $config[$key] = trim((string) ($config[$key] ?? ''));
        }

        $config['authorize_url'] = $config['authorize_url'] ?: 'https://accounts.google.com/o/oauth2/v2/auth';
        $config['token_url']     = $config['token_url'] ?: 'https://oauth2.googleapis.com/token';
        $config['revoke_url']    = $config['revoke_url'] ?: 'https://oauth2.googleapis.com/revoke';
        $config['api_base']      = $config['api_base'] ?: 'https://health.googleapis.com/v4';
        $config['scopes']        = array_values(array_filter((array) ($config['scopes'] ?? []), 'is_string'));
        $config['initial_days']  = max(1, min(90, (int) ($config['initial_days'] ?? 30)));
        $config['overlap_days']  = max(1, min(7, (int) ($config['overlap_days'] ?? 3)));

        return $config;
    }

    /** An id and a secret that are real — not empty, not the example's words. */
    function google_health_credentials_present(): bool
    {
        $config = google_health_config();

        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (integration_placeholder($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /** Migration 020 (the state table and the sync marker, shared with Polar) and the source row. */
    function google_health_stored(): bool
    {
        static $stored = null;

        if ($stored === null) {
            $stored = db_available()
                && db_value("SELECT COUNT(*) FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'integration_oauth_states'") > 0
                && db_value("SELECT COUNT(*) FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_integrations'
                                AND COLUMN_NAME = 'sync_started_at'") > 0
                && db_value("SELECT COUNT(*) FROM data_sources WHERE code = 'google_health'") > 0;
        }

        return $stored;
    }

    /** Why Google Health cannot be connected right now, beyond what every cloud source needs — or null. */
    function google_health_problem(): ?string
    {
        if (!google_health_credentials_present()) {
            return 'Deze koppeling is op de server nog niet ingesteld.';
        }

        if (!google_health_stored()) {
            return 'De database is nog niet bijgewerkt voor deze koppeling (migratie 020).';
        }

        return null;
    }

    /* ================================================================= HTTP */

    /**
     * One request to Google. Never logs a body, a token or a query string.
     * Replaceable for the tests (tools/google-health-test.php).
     *
     * @return array{status: int, body: string}|null  null: no answer at all
     */
    function google_health_http(string $method, string $url, array $headers, ?string $body = null, int $timeout = 25): ?array
    {
        if (isset($GLOBALS['google_health_http_stub']) && is_callable($GLOBALS['google_health_http_stub'])) {
            return ($GLOBALS['google_health_http_stub'])($method, $url, $headers, $body);
        }

        if (!function_exists('curl_init')) {
            error_log('[google-health] the curl extension is missing');
            return null;
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status   = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_error($handle);
        unset($handle);

        if (!is_string($response)) {
            error_log('[google-health] ' . $method . ' ' . (string) parse_url($url, PHP_URL_HOST)
                . (string) parse_url($url, PHP_URL_PATH) . ' failed: ' . $error);
            return null;
        }

        return ['status' => $status, 'body' => $response];
    }

    /**
     * Google's token endpoint, with the client's id and secret in the form.
     *
     * @return array{ok: bool, status: int, data: array, error: ?string}
     */
    function google_health_token_request(array $form): array
    {
        $config = google_health_config();
        $form  += ['client_id' => $config['client_id'], 'client_secret' => $config['client_secret']];

        $response = google_health_http('POST', $config['token_url'], [
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
            error_log(sprintf('[google-health] token request (%s) refused: HTTP %d %s',
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

    /** The token answer as stored: the expiry a minute early. */
    function google_health_tokens_from(array $data): array
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

    /** As polar_session_hash(), with its own key: the browser that started it. */
    function google_health_session_hash(bool $create = false): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        if (!is_string($_SESSION['google_health_binding'] ?? null)) {
            if (!$create) {
                return null;
            }
            $_SESSION['google_health_binding'] = polar_b64url(random_bytes(32));
        }

        return hash('sha256', 'ownify-google-health|' . $_SESSION['google_health_binding']);
    }

    /**
     * Starts connecting, for the signed-in user. `client`: 'web' or 'app'.
     *
     * @return array{ok: bool, url: ?string, error: ?string, status: int}
     */
    function google_health_begin(int $userId, string $client): array
    {
        if (($problem = integration_blocked_reason('google_health')) !== null) {
            return ['ok' => false, 'url' => null, 'error' => $problem, 'status' => 503];
        }

        if (!in_array($client, ['web', 'app'], true)) {
            return ['ok' => false, 'url' => null, 'error' => 'Onbekende herkomst.', 'status' => 400];
        }

        db_run('DELETE FROM integration_oauth_states WHERE expires_at < NOW() - INTERVAL 1 DAY');

        $recent = (int) db_value(
            "SELECT COUNT(*) FROM integration_oauth_states
              WHERE user_id = ? AND provider = 'google_health' AND created_at > NOW() - INTERVAL 10 MINUTE",
            [$userId]
        );

        if ($recent >= 10) {
            return ['ok' => false, 'url' => null, 'error' => 'Je hebt het koppelen net een paar keer gestart. Probeer het over tien minuten opnieuw.', 'status' => 429];
        }

        $sessionHash = null;

        if ($client === 'web') {
            $sessionHash = google_health_session_hash(true);

            if ($sessionHash === null) {
                return ['ok' => false, 'url' => null, 'error' => 'Je bent niet ingelogd.', 'status' => 401];
            }
        }

        $state  = polar_b64url(random_bytes(32));
        $config = google_health_config();

        db_run(
            'INSERT INTO integration_oauth_states (user_id, provider, state_hash, client, session_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL 10 MINUTE)',
            [$userId, 'google_health', hash('sha256', $state), $client, $sessionHash]
        );

        $query = [
            'response_type'          => 'code',
            'client_id'              => $config['client_id'],
            'redirect_uri'           => $config['redirect_uri'],
            'scope'                  => implode(' ', $config['scopes']),
            'state'                  => $state,
            /* A refresh token, so Ownify can sync while nobody is looking —
               and asked for again on every connect, or Google sends none
               the second time. */
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
        ];

        return [
            'ok'     => true,
            'url'    => $config['authorize_url'] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'error'  => null,
            'status' => 200,
        ];
    }

    function google_health_state_row(string $state): ?array
    {
        if ($state === '' || strlen($state) > 128 || !google_health_stored()) {
            return null;
        }

        return db_one(
            "SELECT * FROM integration_oauth_states WHERE state_hash = ? AND provider = 'google_health'",
            [hash('sha256', $state)]
        );
    }

    /**
     * Google has sent the browser back. Whose connection this is comes from
     * the state row — never from the query.
     *
     * @return array{outcome: string, message: string, client: ?string, confirm?: array}
     */
    function google_health_callback(array $query, ?int $sessionUserId): array
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $row   = google_health_state_row($state);

        if ($row === null || $row['used_at'] !== null || strtotime((string) $row['expires_at']) <= time()) {
            return ['outcome' => 'error', 'client' => $row['client'] ?? null,
                    'message' => 'Deze koppelpoging is verlopen of al gebruikt. Start het koppelen opnieuw vanuit Ownify.'];
        }

        $client = (string) $row['client'];
        $userId = (int) $row['user_id'];

        if (isset($query['error'])) {
            polar_claim_state((int) $row['id']);
            $cancelled = $query['error'] === 'access_denied';

            return [
                'outcome' => $cancelled ? 'cancelled' : 'error',
                'client'  => $client,
                'message' => $cancelled
                    ? 'Je hebt Google geen toegang gegeven. Er is niets gekoppeld.'
                    : 'Google kon de koppeling niet starten. Probeer het later opnieuw.',
            ];
        }

        $code = is_string($query['code'] ?? null) ? trim($query['code']) : '';

        if ($code === '' || strlen($code) > 1024) {
            polar_claim_state((int) $row['id']);
            return ['outcome' => 'error', 'client' => $client, 'message' => 'Google stuurde geen geldige code terug. Start het koppelen opnieuw.'];
        }

        if ($client === 'web') {
            if ($row['session_hash'] === null || !hash_equals((string) $row['session_hash'], (string) google_health_session_hash())
                || $sessionUserId !== $userId) {
                polar_claim_state((int) $row['id']);
                return ['outcome' => 'error', 'client' => $client,
                        'message' => 'Deze koppeling is in een andere browser of door een ander account gestart. Start het koppelen opnieuw vanuit Instellingen.'];
            }

            if (!polar_claim_state((int) $row['id'])) {
                return ['outcome' => 'error', 'client' => $client, 'message' => 'Deze koppelpoging is al gebruikt.'];
            }

            return google_health_finish($userId, $code, $client);
        }

        if ($sessionUserId === $userId) {
            if (!polar_claim_state((int) $row['id'])) {
                return ['outcome' => 'error', 'client' => $client, 'message' => 'Deze koppelpoging is al gebruikt.'];
            }

            return google_health_finish($userId, $code, $client);
        }

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
        $other    = $sessionUserId !== null ? (string) db_value('SELECT username FROM users WHERE id = ?', [$sessionUserId]) : null;

        return [
            'outcome' => 'confirm',
            'client'  => $client,
            'message' => '',
            'confirm' => ['token' => $confirm, 'username' => $username, 'signed_in_as' => $other ?: null],
        ];
    }

    /** The confirmation page answered (the app's browser). */
    function google_health_confirm(string $token, string $action): array
    {
        $row = ($token !== '' && strlen($token) <= 128 && google_health_stored())
            ? db_one("SELECT * FROM integration_oauth_states WHERE confirm_hash = ? AND provider = 'google_health'", [hash('sha256', $token)])
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

        return google_health_finish((int) $row['user_id'], $code, 'app');
    }

    /** The code, exchanged; the tokens stored for `$userId`. */
    function google_health_finish(int $userId, string $code, string $client): array
    {
        $answer = google_health_token_request([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => google_health_config()['redirect_uri'],
        ]);

        if (!$answer['ok']) {
            return ['outcome' => 'error', 'client' => $client,
                    'message' => $answer['status'] === 0
                        ? 'Google is nu niet bereikbaar. Probeer het later opnieuw.'
                        : 'Google heeft de koppeling geweigerd. Start het koppelen opnieuw.'];
        }

        $tokens = google_health_tokens_from($answer['data']);
        $stored = integration_connect($userId, 'google_health', $tokens + ['external_account_label' => null]);

        if (!$stored['ok']) {
            return ['outcome' => 'error', 'client' => $client, 'message' => (string) $stored['error']];
        }

        db_run("UPDATE user_integrations SET last_sync_status = 'never', last_sync_at = NULL, last_error = NULL
                 WHERE user_id = ? AND provider = 'google_health'", [$userId]);

        return ['outcome' => 'connected', 'client' => $client, 'user_id' => $userId,
                'message' => 'Google Health is gekoppeld. Je gegevens worden nu opgehaald.'];
    }

    /* =============================================================== tokens */

    /**
     * A working access token, refreshed when it has (nearly) run out — or why
     * there is none.
     *
     * @return array{token: ?string, problem: ?string}  problem: revoked | disconnected | unreachable
     */
    function google_health_access_token(int $userId, bool $forceRefresh = false): array
    {
        $row = integration_get($userId, 'google_health');

        if ($row === null || !in_array($row['status'], ['connected', 'error'], true)) {
            return ['token' => null, 'problem' => 'disconnected'];
        }

        $tokens = integration_tokens($userId, 'google_health');

        if ($tokens === null || ($tokens['access_token'] === null && $tokens['refresh_token'] === null)) {
            return ['token' => null, 'problem' => 'disconnected'];
        }

        if (!$forceRefresh && $tokens['access_token'] !== null && !$tokens['expired']) {
            return ['token' => $tokens['access_token'], 'problem' => null];
        }

        if ($tokens['refresh_token'] === null) {
            integration_mark_revoked($userId, 'google_health', 'De toegang tot Google Health is verlopen. Koppel Google Health opnieuw.');
            return ['token' => null, 'problem' => 'revoked'];
        }

        $answer = google_health_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);

        if (!$answer['ok']) {
            if ($answer['status'] === 0 || $answer['status'] >= 500 || $answer['status'] === 429) {
                return ['token' => null, 'problem' => 'unreachable'];
            }

            integration_mark_revoked($userId, 'google_health', 'De toegang tot Google Health is verlopen of ingetrokken. Koppel Google Health opnieuw.');
            return ['token' => null, 'problem' => 'revoked'];
        }

        $fresh = google_health_tokens_from($answer['data']);
        integration_store_tokens($userId, 'google_health', $fresh['access_token'], $fresh['refresh_token'], $fresh['token_expires_at']);

        return ['token' => $fresh['access_token'], 'problem' => null];
    }

    /* ================================================================== API */

    /**
     * Every data point of `$type` matching `$filter`, page by page. Refreshes
     * the token once on a 401, retries once on a server error, stops the
     * whole sync on a 429 or a refused refresh.
     *
     * With `$each`, every page is handed to it as it arrives and nothing is
     * kept (44.2): a month of a watch's heart rate is hundreds of thousands
     * of points, which held at once ran PHP out of memory.
     *
     * @param array $ctx {user_id, token, requests, limited, revoked, budget, deadline}
     * @param ?callable(list<array>): void $each
     * @return array{status: string, points: list<array>}  status: ok | forbidden | limited | revoked | error
     */
    function google_health_list(array &$ctx, string $type, string $filter, int $pageSize = 1000, ?callable $each = null): array
    {
        $points = [];
        $token  = null;
        $base   = rtrim(google_health_config()['api_base'], '/') . '/users/me/dataTypes/' . rawurlencode($type) . '/dataPoints';

        do {
            if ($ctx['limited'] || $ctx['revoked']) {
                return ['status' => $ctx['revoked'] ? 'revoked' : 'limited', 'points' => $points];
            }
            if ($ctx['requests'] >= $ctx['budget'] || microtime(true) > ($ctx['deadline'] ?? INF)) {
                $ctx['limited'] = true;
                return ['status' => 'limited', 'points' => $points];
            }

            $query = ['filter' => $filter, 'pageSize' => $pageSize];
            if ($token !== null) {
                $query['pageToken'] = $token;
            }
            $page = google_health_get($ctx, $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            if ($page['status'] !== 'ok') {
                return ['status' => $page['status'], 'points' => $points];
            }

            $list = array_values(array_filter((array) ($page['data']['dataPoints'] ?? []), 'is_array'));
            $token = is_string($page['data']['nextPageToken'] ?? null) && $page['data']['nextPageToken'] !== '' ? $page['data']['nextPageToken'] : null;
            unset($page);

            if ($each !== null) {
                $each($list);
            } else {
                array_push($points, ...$list);
            }
            unset($list);
        } while ($token !== null);

        return ['status' => 'ok', 'points' => $points];
    }

    /** One GET. @return array{status: string, data: array} */
    function google_health_get(array &$ctx, string $url): array
    {
        $retried   = false;
        $refreshed = false;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($ctx['token'] === null) {
                $access = google_health_access_token($ctx['user_id']);
                if ($access['token'] === null) {
                    $ctx['revoked'] = $access['problem'] !== 'unreachable';
                    return ['status' => $ctx['revoked'] ? 'revoked' : 'error', 'data' => []];
                }
                $ctx['token'] = $access['token'];
            }

            $ctx['requests']++;
            $response = google_health_http('GET', $url, ['Authorization: Bearer ' . $ctx['token'], 'Accept: application/json']);

            if ($response === null || $response['status'] >= 500) {
                if (!$retried) {
                    $retried = true;
                    usleep(500000);
                    continue;
                }
                return ['status' => 'error', 'data' => []];
            }

            switch (true) {
                case $response['status'] === 200:
                    $data = json_decode($response['body'], true);
                    return is_array($data) ? ['status' => 'ok', 'data' => $data] : ['status' => 'error', 'data' => []];

                case $response['status'] === 404:
                    return ['status' => 'ok', 'data' => []];

                case $response['status'] === 401:
                    if (!$refreshed) {
                        $refreshed = true;
                        $access = google_health_access_token($ctx['user_id'], true);
                        if ($access['token'] === null) {
                            $ctx['revoked'] = $access['problem'] !== 'unreachable';
                            return ['status' => $ctx['revoked'] ? 'revoked' : 'error', 'data' => []];
                        }
                        $ctx['token'] = $access['token'];
                        continue 2;
                    }
                    integration_mark_revoked($ctx['user_id'], 'google_health', 'De toegang tot Google Health is verlopen of ingetrokken. Koppel Google Health opnieuw.');
                    $ctx['revoked'] = true;
                    return ['status' => 'revoked', 'data' => []];

                case $response['status'] === 403:
                    return ['status' => 'forbidden', 'data' => []];

                case $response['status'] === 429:
                    $ctx['limited'] = true;
                    return ['status' => 'limited', 'data' => []];

                default:
                    error_log('[google-health] GET ' . (string) parse_url($url, PHP_URL_PATH) . ' answered HTTP ' . $response['status']);
                    return ['status' => 'error', 'data' => []];
            }
        }

        return ['status' => 'error', 'data' => []];
    }

    /* ============================================================== mapping */

    /** "3600s" / "-18000s" — Google's durations — in seconds, or null. */
    function google_health_seconds(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round((float) $value);
        }

        return is_string($value) && preg_match('/^(-?\d+(?:\.\d+)?)s?$/', trim($value), $m) ? (int) round((float) $m[1]) : null;
    }

    /**
     * A moment as ISO 8601 on the clock it was recorded on: Google sends
     * UTC plus the offset beside it; the importer keeps the local time it is
     * given, so a night lands on the morning the person lived.
     */
    function google_health_time(mixed $utc, mixed $offset): ?string
    {
        if (!is_string($utc) || trim($utc) === '') {
            return null;
        }

        try {
            $time    = new DateTimeImmutable($utc);
            $seconds = google_health_seconds($offset);
            if ($seconds !== null) {
                $zone = sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
                $time = $time->setTimezone(new DateTimeZone($zone));
            }
            return $time->format(DATE_ATOM);
        } catch (Exception $e) {
            return null;
        }
    }

    /** A google.type.Date ({year, month, day}) as Y-m-d, or null. */
    function google_health_date(mixed $date): ?string
    {
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        if (!is_array($date) || !isset($date['year'], $date['month'], $date['day'])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $date['year'], (int) $date['month'], (int) $date['day']);
    }

    /** A number Google sends as a number or as an int64 string. */
    function google_health_number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** The point's own id (the last part of its name), or one made of its type and time. */
    function google_health_id(string $type, array $point, string $when): string
    {
        $name = is_string($point['name'] ?? null) ? $point['name'] : '';
        $id   = $name !== '' ? substr($name, (int) strrpos($name, '/') + 1) : '';

        return 'gh:' . $type . ':' . ($id !== '' ? mb_substr($id, 0, 120) : substr(hash('sha256', $when . json_encode($point)), 0, 24));
    }

    /** Which device or platform recorded it, as an app name for counting overlaps once. */
    function google_health_origin(array $point): string
    {
        $source = (array) ($point['dataSource'] ?? []);
        $device = trim((string) ($source['device']['displayName'] ?? ''));
        $platform = trim((string) ($source['platform'] ?? ''));

        return mb_substr('google_health' . ($platform !== '' ? ':' . $platform : '') . ($device !== '' ? ':' . $device : ''), 0, 191);
    }

    /** The interval of an interval or session point: [start, end] on their own clocks. */
    function google_health_interval(array $payload): array
    {
        $i = (array) ($payload['interval'] ?? []);

        return [google_health_time($i['startTime'] ?? null, $i['startUtcOffset'] ?? null),
                google_health_time($i['endTime'] ?? null, $i['endUtcOffset'] ?? null)];
    }

    /** The moment of a sample point, on its own clock. */
    function google_health_sample_time(array $payload): ?string
    {
        $s = (array) ($payload['sampleTime'] ?? []);

        return google_health_time($s['physicalTime'] ?? null, $s['utcOffset'] ?? null);
    }

    /** Google's sleep stage as Health Connect's (which Ownify keeps); RESTLESS is "awake in bed", as Rusteloosheid. */
    function google_health_sleep_stage(string $type): int
    {
        return match ($type) {
            'AWAKE'    => 1,
            'ASLEEP'   => 2,
            'LIGHT'    => 4,
            'DEEP'     => 5,
            'REM'      => 6,
            'RESTLESS' => 7,
            default    => 0,
        };
    }

    /** Google's meal type as Health Connect's number. */
    function google_health_meal_type(mixed $type): int
    {
        $type = strtoupper((string) $type);

        return match (true) {
            str_contains($type, 'BREAKFAST') => 1,
            str_contains($type, 'LUNCH')     => 2,
            str_contains($type, 'DINNER')    => 3,
            str_contains($type, 'SNACK')     => 4,
            default                          => 0,
        };
    }

    /**
     * One data point as a Health Connect-shaped record (for
     * health_connect_map()), or an Ownify record ready for the importer
     * (workouts, carrying what Google measured), or null.
     *
     * @return array{hc?: array, own?: array}|null
     */
    function google_health_map_point(string $type, array $point): ?array
    {
        $origin = google_health_origin($point);

        switch ($type) {
            case 'steps':
            case 'distance':
            case 'active-energy-burned': {
                $key     = ['steps' => 'steps', 'distance' => 'distance', 'active-energy-burned' => 'activeEnergyBurned'][$type];
                $payload = (array) ($point[$key] ?? []);
                [$start, $end] = google_health_interval($payload);
                if ($start === null || $end === null) {
                    return null;
                }
                $meta = ['id' => google_health_id($type, $point, $start), 'dataOrigin' => $origin];
                return ['hc' => match ($type) {
                    'steps'    => ['recordType' => 'Steps', 'metadata' => $meta, 'startTime' => $start, 'endTime' => $end,
                                   'count' => google_health_number($payload['count'] ?? null)],
                    'distance' => ['recordType' => 'Distance', 'metadata' => $meta, 'startTime' => $start, 'endTime' => $end,
                                   'distance' => ['meters' => (google_health_number($payload['millimeters'] ?? null) ?? 0) / 1000]],
                    default    => ['recordType' => 'ActiveCaloriesBurned', 'metadata' => $meta, 'startTime' => $start, 'endTime' => $end,
                                   'energy' => ['kilocalories' => google_health_number($payload['kcal'] ?? null)]],
                }];
            }

            case 'weight':
            case 'body-fat':
            case 'height': {
                $key     = ['weight' => 'weight', 'body-fat' => 'bodyFat', 'height' => 'height'][$type];
                $payload = (array) ($point[$key] ?? []);
                $at      = google_health_sample_time($payload);
                if ($at === null) {
                    return null;
                }
                $meta = ['id' => google_health_id($type, $point, $at), 'dataOrigin' => $origin];
                return ['hc' => match ($type) {
                    'weight'   => ['recordType' => 'Weight', 'metadata' => $meta, 'time' => $at,
                                   'weight' => ['kilograms' => (google_health_number($payload['weightGrams'] ?? null) ?? 0) / 1000]],
                    'body-fat' => ['recordType' => 'BodyFat', 'metadata' => $meta, 'time' => $at,
                                   'percentage' => google_health_number($payload['percentage'] ?? null)],
                    default    => ['recordType' => 'Height', 'metadata' => $meta, 'time' => $at,
                                   'height' => ['meters' => (google_health_number($payload['heightMillimeters'] ?? null) ?? 0) / 1000]],
                }];
            }

            case 'daily-resting-heart-rate':
            case 'daily-heart-rate-variability':
            case 'daily-oxygen-saturation':
            case 'daily-respiratory-rate': {
                $key = [
                    'daily-resting-heart-rate'     => 'dailyRestingHeartRate',
                    'daily-heart-rate-variability' => 'dailyHeartRateVariability',
                    'daily-oxygen-saturation'      => 'dailyOxygenSaturation',
                    'daily-respiratory-rate'       => 'dailyRespiratoryRate',
                ][$type];
                $payload = (array) ($point[$key] ?? []);
                $date    = google_health_date($payload['date'] ?? null);
                if ($date === null) {
                    return null;
                }
                /* A day's figure, filed in the morning: what the night gave. */
                $at   = $date . 'T07:00:00';
                $meta = ['id' => 'gh:' . $type . ':' . $date, 'dataOrigin' => $origin];
                return ['hc' => match ($type) {
                    'daily-resting-heart-rate'     => ['recordType' => 'RestingHeartRate', 'metadata' => $meta, 'time' => $at,
                                                       'beatsPerMinute' => google_health_number($payload['beatsPerMinute'] ?? null)],
                    'daily-heart-rate-variability' => ['recordType' => 'HeartRateVariabilityRmssd', 'metadata' => $meta, 'time' => $at,
                                                       'heartRateVariabilityMillis' => google_health_number($payload['averageHeartRateVariabilityMilliseconds'] ?? null)],
                    'daily-oxygen-saturation'      => ['recordType' => 'OxygenSaturation', 'metadata' => $meta, 'time' => $at,
                                                       'percentage' => google_health_number($payload['averagePercentage'] ?? null)],
                    default                        => ['recordType' => 'RespiratoryRate', 'metadata' => $meta, 'time' => $at,
                                                       'rate' => google_health_number($payload['breathsPerMinute'] ?? null)],
                }];
            }

            case 'hydration-log': {
                $payload = (array) ($point['hydrationLog'] ?? []);
                [$start, $end] = google_health_interval($payload);
                $ml = google_health_number($payload['amountConsumed']['milliliters'] ?? null);
                if ($start === null || $ml === null) {
                    return null;
                }
                return ['hc' => ['recordType' => 'Hydration', 'metadata' => ['id' => google_health_id($type, $point, $start), 'dataOrigin' => $origin],
                                 'startTime' => $start, 'endTime' => $end ?? $start, 'volume' => ['liters' => $ml / 1000]]];
            }

            case 'nutrition-log': {
                $payload = (array) ($point['nutritionLog'] ?? []);
                [$start, $end] = google_health_interval($payload);
                if ($start === null) {
                    return null;
                }
                $hc = ['recordType' => 'Nutrition', 'metadata' => ['id' => google_health_id($type, $point, $start), 'dataOrigin' => $origin],
                       'startTime' => $start, 'endTime' => $end ?? $start,
                       'name' => is_string($payload['foodDisplayName'] ?? null) ? $payload['foodDisplayName'] : null,
                       'mealType' => google_health_meal_type($payload['mealType'] ?? null)];
                if (($kcal = google_health_number($payload['energy']['kcal'] ?? null)) !== null) {
                    $hc['energy'] = ['kilocalories' => $kcal];
                }
                foreach (['totalCarbohydrate' => 'totalCarbohydrate', 'totalFat' => 'totalFat'] as $from => $to) {
                    if (($g = google_health_number($payload[$from]['grams'] ?? null)) !== null) {
                        $hc[$to] = ['grams' => $g];
                    }
                }
                foreach ((array) ($payload['nutrients'] ?? []) as $nutrient) {
                    $name = strtoupper((string) ($nutrient['nutrient'] ?? ''));
                    $g    = google_health_number($nutrient['quantity']['grams'] ?? null);
                    $to   = match (true) {
                        $name === 'PROTEIN'                     => 'protein',
                        str_contains($name, 'SATURATED')        => 'saturatedFat',
                        str_contains($name, 'FIBER') || str_contains($name, 'FIBRE') => 'dietaryFiber',
                        str_starts_with($name, 'SUGAR')         => 'sugar',
                        $name === 'SODIUM'                      => 'sodium',
                        $name === 'CAFFEINE'                    => 'caffeine',
                        default                                 => null,
                    };
                    if ($to !== null && $g !== null) {
                        $hc[$to] = ['grams' => $g];
                    }
                }
                return ['hc' => $hc];
            }

            case 'sleep': {
                $payload = (array) ($point['sleep'] ?? []);
                [$start, $end] = google_health_interval($payload);
                if ($start === null || $end === null) {
                    return null;
                }
                $stages = [];
                foreach ((array) ($payload['stages'] ?? []) as $stage) {
                    $kind = google_health_sleep_stage((string) ($stage['type'] ?? ''));
                    $from = google_health_time($stage['startTime'] ?? null, $stage['startUtcOffset'] ?? null);
                    $to   = google_health_time($stage['endTime'] ?? null, $stage['endUtcOffset'] ?? null);
                    if ($kind !== 0 && $from !== null && $to !== null) {
                        $stages[] = ['startTime' => $from, 'endTime' => $to, 'stage' => $kind];
                    }
                }
                foreach ((array) ($payload['outOfBedSegments'] ?? []) as $out) {
                    $from = google_health_time($out['startTime'] ?? null, $out['startUtcOffset'] ?? null);
                    $to   = google_health_time($out['endTime'] ?? null, $out['endUtcOffset'] ?? null);
                    if ($from !== null && $to !== null) {
                        $stages[] = ['startTime' => $from, 'endTime' => $to, 'stage' => 3];
                    }
                }
                return ['hc' => ['recordType' => 'SleepSession', 'metadata' => ['id' => google_health_id($type, $point, $start), 'dataOrigin' => $origin],
                                 'startTime' => $start, 'endTime' => $end, 'stages' => $stages]];
            }

            case 'exercise': {
                $payload = (array) ($point['exercise'] ?? []);
                [$start, $end] = google_health_interval($payload);
                if ($start === null) {
                    return null;
                }
                $m       = (array) ($payload['metricsSummary'] ?? []);
                $active  = google_health_seconds($payload['activeDuration'] ?? null);
                $seconds = $active !== null && $active > 0 ? $active
                    : ($end !== null ? strtotime($end) - strtotime($start) : null);
                $type    = strtolower(trim((string) ($payload['exerciseType'] ?? '')));
                $mm      = google_health_number($m['distanceMillimeters'] ?? null);
                $speed   = google_health_number($m['averageSpeedMillimetersPerSecond'] ?? null);
                $rise    = google_health_number($m['elevationGainMillimeters'] ?? null);
                return ['own' => [
                    'type'             => 'workout',
                    'external_id'      => google_health_id('exercise', $point, $start),
                    'started_at'       => $start,
                    'ended_at'         => $end,
                    'duration_seconds' => $seconds !== null && $seconds > 0 ? $seconds : null,
                    'activity_type'    => mb_substr($type !== '' && $type !== 'exercise_type_unspecified' ? $type
                                            : (trim((string) ($payload['displayName'] ?? '')) ?: 'other'), 0, 40),
                    'distance_m'       => $mm === null || $mm <= 0 ? null : round($mm / 1000, 1),
                    'total_kcal'       => google_health_number($m['caloriesKcal'] ?? null),
                    'avg_hr'           => google_health_number($m['averageHeartRateBeatsPerMinute'] ?? null),
                    'avg_speed_kmh'    => $speed === null || $speed <= 0 ? null : round($speed * 0.0036, 2),
                    'elevation_gain_m' => $rise === null ? null : (int) round($rise / 1000),
                    'notes'            => is_string($payload['notes'] ?? null) && trim($payload['notes']) !== '' ? $payload['notes'] : null,
                ]];
            }
        }

        return null;
    }

    /* ================================================================= sync */

    /**
     * What is read, and how its filter names the time. The categories are
     * what the owner reads when one does not come through.
     */
    function google_health_types(): array
    {
        return [
            'exercise'                     => ['session_civil', 'trainingen'],
            'sleep'                        => ['sleep_end', 'slaap'],
            'steps'                        => ['interval', 'stappen'],
            'distance'                     => ['interval', 'afstand'],
            'active-energy-burned'         => ['interval', 'calorieën'],
            'heart-rate'                   => ['sample', 'hartslag'],
            'daily-resting-heart-rate'     => ['daily', 'rusthartslag'],
            'daily-heart-rate-variability' => ['daily', 'hrv'],
            'daily-oxygen-saturation'      => ['daily', 'zuurstof'],
            'daily-respiratory-rate'       => ['daily', 'ademhaling'],
            'weight'                       => ['sample', 'lichaamsmaten'],
            'body-fat'                     => ['sample', 'lichaamsmaten'],
            'height'                       => ['sample', 'lichaamsmaten'],
            'hydration-log'                => ['session_civil', 'water'],
            'nutrition-log'                => ['session_civil', 'voeding'],
        ];
    }

    /** The AIP-160 filter for `$type` from `$from` (Y-m-d, the person's day) on. */
    function google_health_filter(string $type, string $kind, string $from): string
    {
        $field = str_replace('-', '_', $type);
        $utc   = $from . 'T00:00:00Z';

        return match ($kind) {
            'interval'      => sprintf('%s.interval.start_time >= "%s"', $field, $utc),
            'sample'        => sprintf('%s.sample_time.physical_time >= "%s"', $field, $utc),
            'daily'         => sprintf('%s.date >= "%s"', $field, $from),
            'sleep_end'     => sprintf('sleep.interval.end_time >= "%s"', $utc),
            default         => sprintf('%s.interval.civil_start_time >= "%s"', $field, $from),
        };
    }

    /** "128M" / "1G" / "512K" / "-1" (PHP's shorthand) in bytes. */
    function google_health_bytes(string $value): int
    {
        $value = trim($value);
        $n     = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G'     => $n * 1024 * 1024 * 1024,
            'M'     => $n * 1024 * 1024,
            'K'     => $n * 1024,
            default => $n,
        };
    }

    function google_health_syncing(?array $row): bool
    {
        return $row !== null && !empty($row['sync_started_at'])
            && strtotime((string) $row['sync_started_at']) > time() - 900;
    }

    /**
     * Fetches and imports. `$trigger`: connect | manual | schedule.
     *
     * `$early` (44.2): called once, with no arguments, when the sync is still
     * running `$earlyAfter` seconds in — sync.php answers the app then and
     * lets the rest run on the server, so a pull never waits on Google past
     * the app's own patience.
     *
     * A run that dies (out of memory, out of time) no longer leaves
     * "Bezig met synchroniseren" behind for a quarter of an hour: a shutdown
     * guard clears it and says the sync failed.
     *
     * @return array{ok: bool, status: string, message: string, written: int, requests: int}
     */
    function google_health_sync(int $userId, string $trigger = 'manual', ?callable $early = null, float $earlyAfter = 8.0): array
    {
        require_once __DIR__ . '/health-connect-map.php';
        require_once __DIR__ . '/health-import.php';

        $row = integration_get($userId, 'google_health');

        if ($row === null || !in_array($row['status'], ['connected', 'error'], true)) {
            return ['ok' => false, 'status' => 'disconnected', 'message' => 'Google Health is niet gekoppeld.', 'written' => 0, 'requests' => 0];
        }

        /* One page from Google is up to 5,000 points, some 5 MB of JSON —
           decoded, a good part of PHP's usual 128 MB. More room where the
           host allows it; the sync never holds more than a page and a chunk. */
        $limit = (string) ini_get('memory_limit');
        if (function_exists('ini_set') && $limit !== '-1' && google_health_bytes($limit) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        $lock = 'ownify_google_health_' . $userId;
        if ((int) db_value('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
            return ['ok' => false, 'status' => 'busy', 'message' => 'Google Health wordt al gesynchroniseerd.', 'written' => 0, 'requests' => 0];
        }

        db_run("UPDATE user_integrations SET sync_started_at = NOW() WHERE user_id = ? AND provider = 'google_health'", [$userId]);

        $finished = false;
        register_shutdown_function(static function () use (&$finished, $userId): void {
            if ($finished) {
                return;
            }
            $error = error_get_last();
            error_log('[google-health] sync for user ' . $userId . ' stopped before it finished'
                . (is_array($error) ? ': ' . mb_substr((string) $error['message'], 0, 200) : ''));
            try {
                db_run("UPDATE user_integrations SET sync_started_at = NULL WHERE user_id = ? AND provider = 'google_health'", [$userId]);
                integration_note_sync($userId, 'google_health', 'failed',
                    'De synchronisatie met Google Health werd afgebroken. Het wordt later opnieuw geprobeerd.');
            } catch (Throwable $e) {
                /* nothing more to do on the way out */
            }
        });

        try {
            return google_health_sync_run($userId, $row, $trigger, $early, $earlyAfter);
        } finally {
            $finished = true;
            db_run("UPDATE user_integrations SET sync_started_at = NULL WHERE user_id = ? AND provider = 'google_health'", [$userId]);
            db_value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    function google_health_sync_run(int $userId, array $row, string $trigger, ?callable $early = null, float $earlyAfter = 8.0): array
    {
        $config = google_health_config();
        $first  = $row['last_sync_at'] === null;
        $back   = $first
            ? $config['initial_days']
            : min(90, max($config['overlap_days'], (int) floor((time() - strtotime((string) $row['last_sync_at'])) / 86400) + $config['overlap_days']));
        $from   = (new DateTimeImmutable('today'))->modify('-' . $back . ' days')->format('Y-m-d');
        $began  = microtime(true);

        /* At most two and a half minutes with Google per run: what is left
           comes the next time (a scheduled run, a pull) rather than the
           server ending the run halfway. */
        $ctx = ['user_id' => $userId, 'token' => null, 'requests' => 0, 'limited' => false, 'revoked' => false,
                'budget' => $first ? 400 : 160, 'deadline' => $began + 150];

        /* The four that come minute by minute — a watch's heart rate alone
           is several hundred thousand points a month — start a week back
           the first time; everything else a month. */
        $heavy     = ['steps', 'distance', 'active-energy-burned', 'heart-rate'];
        $heavyFrom = $first
            ? (new DateTimeImmutable('today'))->modify('-' . min($back, 7) . ' days')->format('Y-m-d')
            : $from;

        $answered = false;
        $answerEarly = static function () use (&$answered, $early, $earlyAfter, $began): void {
            if ($early !== null && !$answered && microtime(true) - $began >= $earlyAfter) {
                $answered = true;
                $early();
            }
        };
        /* Still alive: Instellingen keeps saying "Bezig met synchroniseren". */
        $tick = static function () use ($answerEarly, $userId): void {
            db_run("UPDATE user_integrations SET sync_started_at = NOW() WHERE user_id = ? AND provider = 'google_health'", [$userId]);
            $answerEarly();
        };

        /* What Google got from Health Connect arrives through the Ownify
           app already, for a person who syncs Health Connect too. */
        $viaPhone  = integration_get($userId, 'google_health_connect');
        $skipPhone = $viaPhone !== null && in_array($viaPhone['status'], ['connected', 'error'], true);

        $failed    = [];
        $forbidden = [];
        $devices   = [];
        $nights    = [];
        $heart     = [];          // origin => minute (Y-m-d H:i:00) => [sum, n]
        $written   = 0;
        $any       = false;

        $keep = static function (array $point) use ($skipPhone, &$devices): bool {
            if ($skipPhone && ($point['dataSource']['platform'] ?? null) === 'HEALTH_CONNECT') {
                return false;
            }
            /* The connection is named after the watches and trackers, not a
               phone that wrote to Health Connect. */
            $name = trim((string) ($point['dataSource']['device']['displayName'] ?? ''));
            if ($name !== '' && ($point['dataSource']['platform'] ?? null) !== 'HEALTH_CONNECT') {
                $devices[$name] = true;
            }
            return true;
        };

        $import = static function (array $records) use ($userId, &$written, &$failed, &$any): void {
            if ($records === []) {
                return;
            }
            $any = true;
            $imported = health_import_records($userId, 'google_health', $records);
            $written += (int) ($imported['written'] ?? 0);
            if (empty($imported['ok'])) {
                $failed['opslaan'] = true;
            }
        };

        /* A type's records, saved: Health Connect's shape through its own
           mapping; the nights kept for the heart rate during them. */
        $flush = static function (array &$hc, array &$own) use ($import, &$nights, &$ctx): void {
            $records = $own;
            if ($hc !== []) {
                foreach (health_connect_map($hc)['records'] as $record) {
                    if (($record['type'] ?? null) === 'sleep' && isset($record['started_at'], $record['ended_at'])) {
                        /* A night is filed under the morning it ended, on its own clock. */
                        $nights[substr((string) $record['ended_at'], 0, 10)] = [(string) $record['started_at'], (string) $record['ended_at']];
                    }
                    $records[] = $record;
                }
            }
            $hc  = [];
            $own = [];
            if (!$ctx['revoked']) {
                $import($records);
            }
        };

        foreach (google_health_types() as $type => [$kind, $category]) {
            $hc  = [];
            $own = [];

            $each = static function (array $points) use ($type, $keep, $flush, $answerEarly, &$hc, &$own, &$heart): void {
                $answerEarly();
                foreach ($points as $point) {
                    if (!$keep($point)) {
                        continue;
                    }
                    if ($type === 'heart-rate') {
                        /* Straight into minutes: never the samples themselves. */
                        $payload = (array) ($point['heartRate'] ?? []);
                        $at      = google_health_sample_time($payload);
                        $bpm     = google_health_number($payload['beatsPerMinute'] ?? null);
                        if ($at === null || $bpm === null || $bpm <= 0 || $bpm > 260) {
                            continue;
                        }
                        $minute = substr($at, 0, 10) . ' ' . substr($at, 11, 5) . ':00';
                        $origin = google_health_origin($point);
                        $heart[$origin][$minute][0] = ($heart[$origin][$minute][0] ?? 0) + $bpm;
                        $heart[$origin][$minute][1] = ($heart[$origin][$minute][1] ?? 0) + 1;
                        continue;
                    }
                    $mapped = google_health_map_point($type, $point);
                    if (isset($mapped['hc'])) {
                        $hc[] = $mapped['hc'];
                    } elseif (isset($mapped['own'])) {
                        $own[] = $mapped['own'];
                    }
                }
                /* A long day of minutes is saved as it comes, not held. */
                if (count($hc) + count($own) >= 5000) {
                    $flush($hc, $own);
                }
            };

            $answer = google_health_list($ctx, $type, google_health_filter($type, $kind, in_array($type, $heavy, true) ? $heavyFrom : $from),
                in_array($type, ['exercise', 'sleep'], true) ? 25 : 10000, $each);

            if ($answer['status'] === 'forbidden') {
                $forbidden[$category] = true;
            } elseif ($answer['status'] !== 'ok' && $answer['status'] !== 'limited') {
                $failed[$category] = true;
            }

            $flush($hc, $own);

            if ($type === 'heart-rate' && $heart !== [] && !$ctx['revoked']) {
                $import(google_health_heart_records($heart, $nights));
                $heart = [];
            }

            $tick();

            if ($ctx['revoked'] || $ctx['limited']) {
                break;
            }
        }

        if ($devices !== []) {
            db_run("UPDATE user_integrations SET external_account_label = ? WHERE user_id = ? AND provider = 'google_health'",
                [mb_substr(implode(', ', array_keys($devices)), 0, 191), $userId]);
        }

        if ($ctx['revoked']) {
            return ['ok' => false, 'status' => 'revoked', 'message' => 'De toegang tot Google Health is verlopen of ingetrokken. Koppel Google Health opnieuw.',
                    'written' => $written, 'requests' => $ctx['requests']];
        }

        $message = null;
        if ($ctx['limited']) {
            $message = 'Nog niet alles is opgehaald. De rest volgt bij de volgende synchronisatie.';
        } elseif ($forbidden !== []) {
            $message = 'Google gaf geen toegang tot ' . implode(', ', array_keys($forbidden))
                . '. Koppel Google Health opnieuw en sta alle gegevens toe.';
        } elseif ($failed !== []) {
            $message = 'Niet alles kon worden opgehaald (' . implode(', ', array_keys($failed)) . '). Het wordt later opnieuw geprobeerd.';
        }

        $status = $message === null ? 'ok' : ((!$any && count($failed) + count($forbidden) >= 10) ? 'failed' : 'partial');

        /* A first sync cut short starts over from its first day next time,
           rather than from now: what was not fetched yet is not skipped. */
        if ($first && $ctx['limited']) {
            integration_note_sync($userId, 'google_health', $status, $message);
            db_run("UPDATE user_integrations SET last_sync_at = NULL WHERE user_id = ? AND provider = 'google_health'", [$userId]);
        } else {
            integration_note_sync($userId, 'google_health', $status, $message);
        }

        return [
            'ok'       => $status !== 'failed',
            'status'   => $status,
            'message'  => integration_message_shown($message ?? ($written > 0 ? 'Google Health is gesynchroniseerd.' : 'Google Health is gesynchroniseerd. Er was niets nieuws.')),
            'written'  => $written,
            'requests' => $ctx['requests'],
        ];
    }

    /**
     * The heart rate, gathered into minutes per device as it arrived
     * (google_health_sync_run), as Ownify's records: one per day and device —
     * and each night's mean, the sleep card's "Hartslag in slaap".
     *
     * @param array<string, array<string, array{0: float, 1: int}>> $heart origin => minute => [sum, n]
     * @param array<string, array{0: string, 1: string}> $nights night => [start, end] (ISO 8601)
     * @return list<array> Ownify records
     */
    function google_health_heart_records(array $heart, array $nights): array
    {
        $out    = [];
        $ranges = [];
        foreach ($nights as $night => [$start, $end]) {
            $ranges[$night] = [substr($start, 0, 10) . ' ' . substr($start, 11, 5) . ':00', substr($end, 0, 10) . ' ' . substr($end, 11, 5) . ':00', 0.0, 0];
        }

        foreach ($heart as $origin => $minutes) {
            ksort($minutes);
            $byDay = [];
            foreach ($minutes as $minute => [$sum, $n]) {
                $byDay[substr($minute, 0, 10)][] = ['at' => $minute, 'bpm' => round($sum / $n, 1), 'n' => $n];
                foreach ($ranges as $night => $range) {
                    if ($minute >= $range[0] && $minute < $range[1]) {
                        $ranges[$night][2] += $sum;
                        $ranges[$night][3] += $n;
                    }
                }
            }
            foreach ($byDay as $day => $list) {
                $out[] = ['type' => 'heart_rate', 'external_id' => 'gh:heart-rate:' . $day . ':' . substr(hash('sha256', (string) $origin), 0, 8),
                          'data_origin' => (string) $origin, 'minutes' => $list];
            }
        }

        foreach ($ranges as $night => [, $end, $sum, $n]) {
            if ($n > 0) {
                $out[] = ['type' => 'metric', 'metric_code' => 'sleeping_hr', 'external_id' => 'gh:sleep-hr:' . $night,
                          'value' => round($sum / $n, 1), 'recorded_at' => $nights[$night][1], 'data_origin' => 'google_health'];
            }
        }

        return $out;
    }

    /**
     * Disconnects: Google is asked to withdraw Ownify's access (it can,
     * unlike Polar), and the tokens are destroyed here either way.
     */
    function google_health_disconnect(int $userId): bool
    {
        $tokens = integration_get($userId, 'google_health') !== null ? integration_tokens($userId, 'google_health') : null;
        $token  = $tokens['refresh_token'] ?? $tokens['access_token'] ?? null;

        if (is_string($token) && $token !== '') {
            google_health_http('POST', google_health_config()['revoke_url'],
                ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $token]), 8);
        }

        if (google_health_stored()) {
            db_run("DELETE FROM integration_oauth_states WHERE user_id = ? AND provider = 'google_health'", [$userId]);
        }

        return integration_disconnect($userId, 'google_health');
    }
}
