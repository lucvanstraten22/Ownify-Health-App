<?php
/**
 * Sign in with Google — OpenID Connect, authorization code flow with PKCE.
 *
 * ---------------------------------------------------------------------------
 * THE FLOW
 * ---------------------------------------------------------------------------
 *   1. google_signin_begin()   A random state, nonce and PKCE verifier are kept
 *                              in the server session, and the browser is sent
 *                              to Google with the state, the nonce and the
 *                              verifier's hash.
 *   2. Google                  The person chooses an account and agrees.
 *   3. google_signin_finish()  Google sends the browser back with a code. The
 *                              state must match the one in this session, the
 *                              code is exchanged server to server (with the
 *                              client secret and the PKCE verifier), and the
 *                              ID token that comes back is verified here:
 *                              RS256 signature against Google's published
 *                              keys, issuer, audience, authorised party,
 *                              expiry, issue time and nonce. Only then is its
 *                              `sub` — Google's permanent id for the account —
 *                              believed.
 *
 * What happens next depends on why the flow was started:
 *
 *   login   `sub` already linked      -> signed in to that account
 *           the verified e-mail belongs to an e-mail/password account
 *                                     -> refused. Nobody is signed in, nothing
 *                                        is created; they sign in with their
 *                                        password and link Google in Settings.
 *           otherwise                 -> the verified identity is held in the
 *                                        session for ten minutes while they
 *                                        choose a username. Nothing is saved
 *                                        until they do.
 *   link    signed in, from Settings  -> the verified `sub` is attached to the
 *                                        signed-in account.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE BROWSER CAN AND CANNOT DO
 * ---------------------------------------------------------------------------
 * The browser carries a code and a state, nothing more. It never supplies a
 * Google id, an e-mail address or a token that is believed: the identity comes
 * only out of an ID token this server fetched from Google itself and verified.
 * The state stops a code minted for somebody else's session being replayed
 * into this one, the nonce stops a token minted for another sign-in being
 * reused, and PKCE stops a stolen code being redeemed anywhere else.
 *
 * Tokens and codes are never logged and never stored. Only `sub` and a
 * Google-verified e-mail address reach the database.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/user.php';

/*
 * Google's endpoints and issuers.
 *
 * Constants, not configuration: nothing a request or a config file can reach
 * changes where a token is fetched from or whose signature is trusted. They
 * are defined only if not defined already, so a development router that runs
 * before this file can point them at a local stand-in to test the whole flow
 * without Google — which takes running code on the server, and anybody who
 * can do that does not need a way around sign-in.
 */
if (!defined('GOOGLE_SIGNIN_AUTHORIZE_URL')) {
    define('GOOGLE_SIGNIN_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
}
if (!defined('GOOGLE_SIGNIN_TOKEN_URL')) {
    define('GOOGLE_SIGNIN_TOKEN_URL', 'https://oauth2.googleapis.com/token');
}
if (!defined('GOOGLE_SIGNIN_JWKS_URL')) {
    define('GOOGLE_SIGNIN_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs');
}
if (!defined('GOOGLE_SIGNIN_ISSUERS')) {
    define('GOOGLE_SIGNIN_ISSUERS', ['https://accounts.google.com', 'accounts.google.com']);
}

/** How long a started sign-in may take, and how long a chosen-but-unnamed identity waits. */
if (!defined('GOOGLE_SIGNIN_FLOW_TTL')) {
    define('GOOGLE_SIGNIN_FLOW_TTL', 600);
    define('GOOGLE_SIGNIN_PENDING_TTL', 600);
    define('GOOGLE_SIGNIN_LEEWAY', 60);     // seconds of clock difference forgiven on exp/iat
}

if (!function_exists('google_signin_config')) {

    /* ==================================================================
       CONFIGURATION
       ================================================================== */

    /** @return array{client_id: string, client_secret: string, redirect_uri: string} */
    function google_signin_config(): array
    {
        static $config = null;

        if ($config !== null) {
            return $config;
        }

        $all    = (array) require dirname(__DIR__) . '/config/auth.php';
        $google = (array) ($all['google'] ?? []);

        return $config = [
            'client_id'     => trim((string) ($google['client_id'] ?? '')),
            'client_secret' => trim((string) ($google['client_secret'] ?? '')),
            'redirect_uri'  => trim((string) ($google['redirect_uri'] ?? '')),
        ];
    }

    /**
     * Whether Google sign-in can work on this server at all.
     *
     * All three values, and the means to fetch and verify: openssl for the
     * signature, and curl or URL streams for the two calls to Google.
     */
    function google_signin_configured(): bool
    {
        $config = google_signin_config();

        return $config['client_id'] !== ''
            && $config['client_secret'] !== ''
            && $config['redirect_uri'] !== ''
            && function_exists('openssl_verify')
            && (function_exists('curl_init') || (bool) ini_get('allow_url_fopen'));
    }

    /* ==================================================================
       SMALL PIECES
       ================================================================== */

    function google_signin_b64url_encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Strict: anything outside the base64url alphabet is not a token part. */
    function google_signin_b64url_decode(string $text): ?string
    {
        if ($text === '' || preg_match('/[^A-Za-z0-9_-]/', $text)) {
            return null;
        }

        $padded  = strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * One request to Google. Returns the status and body, or null when the
     * request did not complete at all. Certificates are always verified.
     *
     * @param array<string, string>|null $form  a POST body, or null for GET
     * @return array{status: int, body: string}|null
     */
    function google_signin_http(string $url, ?array $form = null): ?array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (function_exists('curl_init')) {
            $handle = curl_init($url);

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);

            if ($form !== null) {
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form, '', '&'));
            }

            $body   = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error  = curl_error($handle);
            unset($handle);

            if (!is_string($body)) {
                error_log('[google-signin] request to ' . $host . ' failed: ' . $error);
                return null;
            }

            return ['status' => $status, 'body' => $body];
        }

        $context = stream_context_create([
            'http' => [
                'method'          => $form === null ? 'GET' : 'POST',
                'header'          => "Accept: application/json\r\n"
                    . ($form === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
                'content'         => $form === null ? '' : http_build_query($form, '', '&'),
                'timeout'         => 10,
                'ignore_errors'   => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            error_log('[google-signin] request to ' . $host . ' failed');
            return null;
        }

        $headers = function_exists('http_get_last_response_headers')
            ? (array) http_get_last_response_headers()
            : ($http_response_header ?? []);

        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $match)) {
                $status = (int) $match[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    /* ==================================================================
       VERIFYING AN ID TOKEN
       ================================================================== */

    function google_signin_der(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 0x80) {
            return chr($tag) . chr($length) . $content;
        }

        $bytes = ltrim(pack('N', $length), "\0");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }

    function google_signin_der_integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");

        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\0" . $bytes;     // a positive INTEGER must not start with a set high bit
        }

        return google_signin_der(0x02, $bytes);
    }

    /**
     * An RSA public key from a JWK's modulus and exponent, as PEM.
     *
     * SubjectPublicKeyInfo { AlgorithmIdentifier rsaEncryption, BIT STRING
     * RSAPublicKey { n, e } } — written out by hand because PHP has no call
     * that takes n and e directly on every version this runs on.
     */
    function google_signin_rsa_pem(string $modulus, string $exponent): string
    {
        $rsa       = google_signin_der(0x30, google_signin_der_integer($modulus) . google_signin_der_integer($exponent));
        $algorithm = google_signin_der(0x30, google_signin_der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
        $info      = google_signin_der(0x30, $algorithm . google_signin_der(0x03, "\0" . $rsa));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($info), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Verifies a Google ID token. Pure: everything it trusts is an argument,
     * so it can be tested without Google.
     *
     *   signature  RS256 only, with the key whose kid the header names, out of
     *              the key set fetched from Google. 'none', HS256 — a public
     *              key used as an HMAC secret — and every other algorithm are
     *              refused before any key is looked at.
     *   iss        Google
     *   aud        this app's client id (and azp too, when present)
     *   exp, iat   not expired, not issued in the future (a minute's leeway)
     *   nonce      the one this sign-in was started with
     *   sub        present and plausible
     *
     * @param list<array<string, mixed>> $keys
     * @return array{ok: bool, error: ?string, claims: ?array<string, mixed>}
     */
    function google_signin_verify(string $jwt, array $keys, string $clientId, string $nonce, ?int $now = null): array
    {
        $now  = $now ?? time();
        $fail = static fn (string $why): array => ['ok' => false, 'error' => $why, 'claims' => null];

        if ($clientId === '' || $nonce === '') {
            return $fail('nothing to check against');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return $fail('not a JWT');
        }

        [$head64, $body64, $sig64] = $parts;

        $header    = json_decode((string) google_signin_b64url_decode($head64), true);
        $claims    = json_decode((string) google_signin_b64url_decode($body64), true);
        $signature = google_signin_b64url_decode($sig64);

        if (!is_array($header) || !is_array($claims) || $signature === null) {
            return $fail('malformed');
        }

        if (($header['alg'] ?? null) !== 'RS256') {
            return $fail('algorithm ' . (is_string($header['alg'] ?? null) ? $header['alg'] : '?') . ' refused');
        }

        $kid = $header['kid'] ?? null;
        if (!is_string($kid) || $kid === '') {
            return $fail('no key id');
        }

        $key = null;
        foreach ($keys as $candidate) {
            if (is_array($candidate)
                && ($candidate['kid'] ?? null) === $kid
                && ($candidate['kty'] ?? null) === 'RSA'
                && (!isset($candidate['use']) || $candidate['use'] === 'sig')
                && (!isset($candidate['alg']) || $candidate['alg'] === 'RS256')) {
                $key = $candidate;
                break;
            }
        }

        if ($key === null) {
            return $fail('unknown key');
        }

        $modulus  = google_signin_b64url_decode((string) ($key['n'] ?? ''));
        $exponent = google_signin_b64url_decode((string) ($key['e'] ?? ''));

        if ($modulus === null || $exponent === null || strlen(ltrim($modulus, "\0")) < 256) {
            return $fail('unusable key');
        }

        $public = openssl_pkey_get_public(google_signin_rsa_pem($modulus, $exponent));
        if ($public === false) {
            return $fail('unusable key');
        }

        if (openssl_verify($head64 . '.' . $body64, $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
            return $fail('bad signature');
        }

        /* The signature is Google's. Now whether it is about this sign-in. */
        if (!in_array($claims['iss'] ?? null, GOOGLE_SIGNIN_ISSUERS, true)) {
            return $fail('wrong issuer');
        }

        $audience  = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];

        if (!in_array($clientId, $audiences, true)) {
            return $fail('wrong audience');
        }

        if ((is_array($audience) || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $clientId) {
            return $fail('wrong authorised party');
        }

        if (!is_int($claims['exp'] ?? null) || $claims['exp'] < $now - GOOGLE_SIGNIN_LEEWAY) {
            return $fail('expired');
        }

        if (!is_int($claims['iat'] ?? null) || $claims['iat'] > $now + GOOGLE_SIGNIN_LEEWAY) {
            return $fail('issued in the future');
        }

        if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])) {
            return $fail('wrong nonce');
        }

        $sub = $claims['sub'] ?? null;
        if (!is_string($sub) || $sub === '' || strlen($sub) > 191) {
            return $fail('no subject');
        }

        return ['ok' => true, 'error' => null, 'claims' => $claims];
    }

    /** The e-mail address, only if Google says it has verified it. */
    function google_signin_verified_email(array $claims): ?string
    {
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? null) === 'true';
        $email    = $claims['email'] ?? null;

        if (!$verified || !is_string($email) || auth_validate_email($email) !== null) {
            return null;
        }

        return auth_normalise_email($email);
    }

    /* ==================================================================
       THE FLOW
       ================================================================== */

    /**
     * Starts a sign-in (mode 'login') or a link from Settings (mode 'link',
     * for the signed-in user). Returns the URL to send the browser to, or null
     * when Google sign-in is not configured.
     */
    function google_signin_begin(string $mode, ?int $userId = null): ?string
    {
        if (!google_signin_configured() || !in_array($mode, ['login', 'link'], true)) {
            return null;
        }

        if ($mode === 'link' && $userId === null) {
            return null;
        }

        session_boot();

        $config   = google_signin_config();
        $state    = google_signin_b64url_encode(random_bytes(32));
        $nonce    = google_signin_b64url_encode(random_bytes(32));
        $verifier = google_signin_b64url_encode(random_bytes(48));

        /* One flow at a time: starting again replaces an unfinished one. */
        $_SESSION['google_signin_flow'] = [
            'state'        => $state,
            'nonce'        => $nonce,
            'verifier'     => $verifier,
            'mode'         => $mode,
            'user_id'      => $mode === 'link' ? $userId : null,
            'redirect_uri' => $config['redirect_uri'],
            'started'      => time(),
        ];

        return GOOGLE_SIGNIN_AUTHORIZE_URL . '?' . http_build_query([
            'client_id'             => $config['client_id'],
            'redirect_uri'          => $config['redirect_uri'],
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => google_signin_b64url_encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** The code, exchanged for an ID token. Null when Google refused. */
    function google_signin_exchange(string $code, array $flow): ?string
    {
        $config = google_signin_config();

        $response = google_signin_http(GOOGLE_SIGNIN_TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri'  => (string) $flow['redirect_uri'],
            'code_verifier' => (string) $flow['verifier'],
        ]);

        if ($response === null) {
            return null;
        }

        $data = json_decode($response['body'], true);

        if ($response['status'] !== 200 || !is_array($data) || !is_string($data['id_token'] ?? null)) {
            /* Google's error code says what went wrong (redirect_uri_mismatch,
               invalid_client, invalid_grant) and is safe to log; the body
               around it is not logged. */
            error_log(sprintf(
                '[google-signin] token exchange refused: HTTP %d %s',
                $response['status'],
                is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : ''
            ));
            return null;
        }

        return $data['id_token'];
    }

    /** Google's current signing keys. */
    function google_signin_keys(): ?array
    {
        $response = google_signin_http(GOOGLE_SIGNIN_JWKS_URL);

        if ($response === null || $response['status'] !== 200) {
            error_log('[google-signin] could not fetch the signing keys');
            return null;
        }

        $data = json_decode($response['body'], true);

        return is_array($data) && is_array($data['keys'] ?? null) ? $data['keys'] : null;
    }

    /**
     * Handles Google's redirect back. Always consumes the flow it belongs to.
     *
     * @param array<string, mixed> $query  the callback's query string
     * @return array{outcome: string, mode: string, message: ?string}
     *         outcome: signed_in | choose_username | linked | cancelled | error
     */
    function google_signin_finish(array $query): array
    {
        session_boot();

        $flow = $_SESSION['google_signin_flow'] ?? null;
        unset($_SESSION['google_signin_flow']);        // single use, whatever happens next

        $mode = is_array($flow) && ($flow['mode'] ?? null) === 'link' ? 'link' : 'login';
        $out  = static fn (string $outcome, ?string $message = null): array
            => ['outcome' => $outcome, 'mode' => $mode, 'message' => $message];

        $failed = $mode === 'link'
            ? 'Google koppelen is niet gelukt. Probeer het opnieuw.'
            : 'Inloggen met Google is niet gelukt. Probeer het opnieuw.';

        if (!is_array($flow) || !is_string($flow['state'] ?? null) || !is_int($flow['started'] ?? null)) {
            return $out('error', 'Deze aanmelding is verlopen of al gebruikt. Begin opnieuw.');
        }

        if (time() - $flow['started'] > GOOGLE_SIGNIN_FLOW_TTL) {
            return $out('error', 'Deze aanmelding duurde te lang en is verlopen. Begin opnieuw.');
        }

        $state = $query['state'] ?? null;
        if (!is_string($state) || !hash_equals($flow['state'], $state)) {
            error_log('[google-signin] state did not match this session');
            return $out('error', $failed);
        }

        /* The person pressed Cancel at Google, or Google refused. */
        if (isset($query['error'])) {
            return $out('cancelled', $mode === 'link'
                ? 'Google koppelen is geannuleerd.'
                : 'Inloggen met Google is geannuleerd.');
        }

        $code = $query['code'] ?? null;
        if (!is_string($code) || $code === '' || strlen($code) > 4096) {
            return $out('error', $failed);
        }

        $token = google_signin_exchange($code, $flow);
        $keys  = $token === null ? null : google_signin_keys();

        if ($token === null || $keys === null) {
            return $out('error', 'Google kon je aanmelding niet bevestigen. Probeer het opnieuw.');
        }

        $verified = google_signin_verify($token, $keys, google_signin_config()['client_id'], (string) $flow['nonce']);

        if (!$verified['ok']) {
            error_log('[google-signin] ID token refused: ' . $verified['error']);
            return $out('error', $failed);
        }

        $sub   = (string) $verified['claims']['sub'];
        $email = google_signin_verified_email($verified['claims']);

        return $mode === 'link'
            ? google_signin_link($sub, $email, $flow, $out)
            : google_signin_login($sub, $email, $out);
    }

    /** @param callable(string, ?string): array $out */
    function google_signin_link(string $sub, ?string $email, array $flow, callable $out): array
    {
        $userId = current_user_id();

        /* The account that asked must be the account that is still signed in. */
        if ($userId === null || $userId !== (int) ($flow['user_id'] ?? 0)) {
            return $out('error', 'Log eerst in om Google aan je account te koppelen.');
        }

        $owner = db_value(
            'SELECT user_id FROM user_auth_identities WHERE provider = ? AND provider_subject = ?',
            ['google', $sub]
        );

        if ($owner !== null) {
            return (int) $owner === $userId
                ? $out('linked', 'Dit Google-account was al aan je account gekoppeld.')
                : $out('error', 'Dit Google-account hoort al bij een ander JoLu-account.');
        }

        $existing = db_value(
            'SELECT provider_subject FROM user_auth_identities WHERE user_id = ? AND provider = ?',
            [$userId, 'google']
        );

        if ($existing !== null) {
            return $out('error', 'Je account is al aan een ander Google-account gekoppeld.');
        }

        try {
            db_run(
                'INSERT INTO user_auth_identities (user_id, provider, provider_subject, email, email_verified_at)
                      VALUES (?, ?, ?, ?, ?)',
                [$userId, 'google', $sub, $email, $email === null ? null : date('Y-m-d H:i:s')]
            );
        } catch (PDOException $e) {
            /* Lost a race on the unique key to somebody linking the same account. */
            return $out('error', 'Dit Google-account hoort al bij een ander JoLu-account.');
        }

        return $out('linked', 'Google is gekoppeld. Je kunt voortaan ook met Google inloggen.');
    }

    /** @param callable(string, ?string): array $out */
    function google_signin_login(string $sub, ?string $email, callable $out): array
    {
        $identity = db_one(
            'SELECT i.id, i.user_id, u.status
               FROM user_auth_identities i
               JOIN users u ON u.id = i.user_id
              WHERE i.provider = ? AND i.provider_subject = ?',
            ['google', $sub]
        );

        if ($identity !== null) {
            if ($identity['status'] !== 'active') {
                return $out('error', 'Dit account is niet actief.');
            }

            db_run('UPDATE user_auth_identities SET last_login_at = NOW() WHERE id = ?', [(int) $identity['id']]);

            session_login((int) $identity['user_id']);
            auth_touch_last_seen((int) $identity['user_id']);

            return $out('signed_in');
        }

        /* A new account needs an address Google vouches for: it is what the
           check below compares, and an unverified one proves nothing. */
        if ($email === null) {
            return $out('error', 'Google gaf geen bevestigd e-mailadres door. Gebruik een Google-account met een bevestigd adres, of maak een account aan met e-mail en wachtwoord.');
        }

        if (google_signin_email_taken($email)) {
            return $out('error', 'Er bestaat al een account met dit e-mailadres. Log in met je wachtwoord en koppel Google via Instellingen.');
        }

        /* Held here, and only here, until a username is chosen. A new session
           id first, so an identity waiting to become an account can never sit
           in a session id somebody else could have planted. */
        session_regenerate_id(true);

        $_SESSION['google_signin_pending'] = [
            'sub'     => $sub,
            'email'   => $email,
            'expires' => time() + GOOGLE_SIGNIN_PENDING_TTL,
        ];

        return $out('choose_username');
    }

    /** Whether an e-mail/password account already uses this address. */
    function google_signin_email_taken(string $email): bool
    {
        return db_value(
            'SELECT id FROM user_auth_identities WHERE provider = ? AND provider_subject = ?',
            ['email', auth_normalise_email($email)]
        ) !== null;
    }

    /* ==================================================================
       THE USERNAME STEP
       ================================================================== */

    /**
     * The verified identity waiting for a username, or null — also when it
     * has expired, in which case it is dropped.
     *
     * @return array{sub: string, email: string, expires: int}|null
     */
    function google_signin_pending(): ?array
    {
        session_boot();

        $pending = $_SESSION['google_signin_pending'] ?? null;

        if (!is_array($pending) || !is_string($pending['sub'] ?? null)
            || !is_string($pending['email'] ?? null) || !is_int($pending['expires'] ?? null)) {
            unset($_SESSION['google_signin_pending']);
            return null;
        }

        if ($pending['expires'] < time()) {
            unset($_SESSION['google_signin_pending']);
            return null;
        }

        return $pending;
    }

    function google_signin_cancel(): void
    {
        session_boot();
        unset($_SESSION['google_signin_pending']);
    }

    /**
     * Creates the account for the waiting identity, with the chosen username,
     * and links it to Google — in one transaction, so there is never an
     * account without its Google identity or the other way round.
     *
     * @return array{ok: bool, error: ?string, user_id?: int, expired?: bool}
     */
    function google_signin_create_account(string $username): array
    {
        $pending = google_signin_pending();

        if ($pending === null) {
            return ['ok' => false, 'error' => 'Je Google-aanmelding is verlopen. Begin opnieuw met Google.', 'expired' => true];
        }

        $pdo = db();
        if ($pdo === null) {
            return ['ok' => false, 'error' => 'Geen databaseverbinding.'];
        }

        $username = trim($username);

        $error = user_validate_username($username);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        if (user_username_taken($username)) {
            return ['ok' => false, 'error' => 'Deze gebruikersnaam is al bezet.'];
        }

        /* Ten minutes is long enough for either of these to have changed. */
        $linked = db_value(
            'SELECT user_id FROM user_auth_identities WHERE provider = ? AND provider_subject = ?',
            ['google', $pending['sub']]
        );

        if ($linked !== null) {
            google_signin_cancel();
            return ['ok' => false, 'error' => 'Dit Google-account hoort inmiddels bij een account. Log opnieuw in met Google.', 'expired' => true];
        }

        if (google_signin_email_taken($pending['email'])) {
            google_signin_cancel();
            return ['ok' => false, 'error' => 'Er bestaat al een account met dit e-mailadres. Log in met je wachtwoord en koppel Google via Instellingen.', 'expired' => true];
        }

        try {
            $pdo->beginTransaction();

            db_run('INSERT INTO users (username) VALUES (?)', [$username]);
            $userId = (int) $pdo->lastInsertId();

            db_run('INSERT INTO user_profiles (user_id) VALUES (?)', [$userId]);

            db_run(
                'INSERT INTO user_auth_identities
                    (user_id, provider, provider_subject, email, email_verified_at, last_login_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())',
                [$userId, 'google', $pending['sub'], $pending['email']]
            );

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            /* A race on the username or on the Google id lands here. */
            return ['ok' => false, 'error' => 'Dit account kon niet worden aangemaakt. Kies een andere gebruikersnaam of probeer het opnieuw.'];
        }

        google_signin_cancel();

        return ['ok' => true, 'error' => null, 'user_id' => $userId];
    }
}
