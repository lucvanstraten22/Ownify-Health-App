<?php
/**
 * The JoLu app signs in with Google — Android Credential Manager, "Sign in
 * with Google" — and gets an account token.
 *
 * ---------------------------------------------------------------------------
 * THE CONVERSATION, IN UP TO THREE REQUESTS
 * ---------------------------------------------------------------------------
 *   0. { "action": "status" }
 *        -> { ok, available }
 *      Whether this server offers Google sign-in to the app at all, so the
 *      app can show its Google button as the website shows its own: working,
 *      or disabled with "Google is nog niet gekoppeld." Asked before
 *      anything else and answered even when it is not set up.
 *
 *   1. { "action": "nonce" }
 *        -> { ok, nonce, expires_in, client_id }
 *      A fresh random nonce, kept in this server's session (the jolu_session
 *      cookie; the app keeps cookies for the length of this conversation).
 *      The app hands it to Credential Manager (GetSignInWithGoogleOption or
 *      GetGoogleIdOption, setNonce), with client_id — the Web client's id,
 *      which is public: it is in every Google sign-in address the website
 *      sends a browser to — as the server client id. So the app carries no
 *      copy of it that could drift from the audience checked here.
 *
 *   2. { "action": "verify", "id_token": "…", label, platform, app_version }
 *      The ID token Google gave the app, checked here by
 *      google_signin_verify() — the website's own check: RS256 signature
 *      against Google's published keys, issuer, audience (the Web client),
 *      authorised party (one of the app's Android clients), expiry, issue
 *      time, and the nonce from step 1, which is then used up.
 *        -> { ok, status: "signed_in", token, scope, provider: "google", account }
 *           a JoLu account already has this Google account
 *        -> { ok, status: "choose_username", email, expires_in }
 *           nobody has it yet; the verified identity waits in the session
 *           for ten minutes, as it does on the website
 *
 *   3. { "action": "username", "username": "…", label, platform, app_version }
 *        -> { ok, status: "signed_in", token, … }   the account is made,
 *           already linked to Google, by google_signin_create_account()
 *      { "action": "cancel" } forgets the waiting identity instead.
 *
 * Nothing the app says about the person is believed: not a Google id, not an
 * e-mail address, not a name. Only what comes out of an ID token verified
 * here. The rules are the website's (google_signin_match()): an e-mail
 * address that a password account already uses is refused, and linking
 * Google to such an account is done in Settings on the website.
 *
 * The session is only the few minutes of this conversation. What the app
 * keeps is the account token, and the session is thrown away once it is
 * issued. A step that does not belong to a conversation this endpoint
 * started — a browser's own Google sign-in waiting for its username, for one
 * — is refused.
 *
 * Until config/auth.php names the app's Android clients, this answers 501.
 * See docs/APP-AUTH.md for the Google Cloud setup.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_https();

$input  = api_json_body();
$action = is_string($input['action'] ?? null) ? $input['action'] : '';

/* 0. Whether to offer it at all: yes only when every step below can work. */
if ($action === 'status') {
    api_ok(['available' => db_available() && google_signin_native_configured() && devices_scoped()]);
}

api_require_database();

if (!google_signin_native_configured()) {
    api_fail('Inloggen met Google is in de app nog niet beschikbaar.', 501);
}

if (!devices_scoped()) {
    api_fail('Inloggen in de app is op deze server nog niet beschikbaar.', 503);
}

/** Ends this conversation's session: the app has its token, or has given up. */
$finish = static function (): void {
    unset($_SESSION['google_native']);
    google_signin_cancel();
    session_regenerate_id(true);
};

switch ($action) {

    /* ------------------------------------------------------------ 1. nonce */
    case 'nonce':
        /* A fresh session id for a fresh conversation, and one conversation
           at a time: asking again replaces the last nonce. */
        session_regenerate_id(true);
        google_signin_cancel();

        $nonce = google_signin_b64url_encode(random_bytes(32));

        $_SESSION['google_native'] = ['nonce' => $nonce, 'started' => time()];

        api_ok([
            'nonce'      => $nonce,
            'expires_in' => GOOGLE_SIGNIN_FLOW_TTL,
            'client_id'  => google_signin_config()['client_id'],
        ]);

    /* ----------------------------------------------------------- 2. verify */
    case 'verify':
        $flow = $_SESSION['google_native'] ?? null;
        unset($_SESSION['google_native']);                  // the nonce works once, whatever happens next

        if (!is_array($flow) || !is_string($flow['nonce'] ?? null) || !is_int($flow['started'] ?? null)
            || time() - $flow['started'] > GOOGLE_SIGNIN_FLOW_TTL) {
            api_fail('Deze aanmelding is verlopen of al gebruikt. Begin opnieuw.', 410);
        }

        $idToken = $input['id_token'] ?? null;

        if (!is_string($idToken) || $idToken === '' || strlen($idToken) > 8192) {
            api_fail('Inloggen met Google is niet gelukt. Probeer het opnieuw.', 422);
        }

        $keys = google_signin_keys();

        if ($keys === null) {
            api_fail('Google kon je aanmelding niet bevestigen. Probeer het opnieuw.', 502);
        }

        $config   = google_signin_config();
        $verified = google_signin_verify($idToken, $keys, $config['client_id'], $flow['nonce'], null, $config['android_client_ids']);

        if (!$verified['ok']) {
            /* Why goes to the log — never the token — and the app gets one
               sentence for every kind of refusal. */
            error_log('[google-signin] app ID token refused: ' . $verified['error']);
            api_fail('Inloggen met Google is niet gelukt. Probeer het opnieuw.', 401);
        }

        $sub   = (string) $verified['claims']['sub'];
        $email = google_signin_verified_email($verified['claims']);
        $match = google_signin_match($sub, $email);

        if ($match['outcome'] === 'error') {
            api_fail((string) $match['error'], !empty($match['conflict']) ? 409 : 403);
        }

        if ($match['outcome'] === 'existing') {
            $finish();
            api_app_signed_in($match['user_id'], 'google', $input, ['status' => 'signed_in']);
        }

        google_signin_hold($sub, (string) $email);
        $_SESSION['google_native'] = ['pending' => true];

        api_ok([
            'status'     => 'choose_username',
            'email'      => $email,
            'expires_in' => GOOGLE_SIGNIN_PENDING_TTL,
        ]);

    /* --------------------------------------------------------- 3. username */
    case 'username':
        if (($_SESSION['google_native']['pending'] ?? false) !== true) {
            api_json([
                'ok'      => false,
                'error'   => 'Je Google-aanmelding is verlopen. Begin opnieuw met Google.',
                'expired' => true,
            ], 410);
        }

        $result = google_signin_create_account(is_string($input['username'] ?? null) ? $input['username'] : '');

        if (!$result['ok']) {
            if (!empty($result['expired'])) {
                unset($_SESSION['google_native']);
            }

            api_json([
                'ok'      => false,
                'error'   => $result['error'],
                'expired' => !empty($result['expired']),
            ], !empty($result['expired']) ? 410 : 422);
        }

        $finish();
        api_app_signed_in((int) $result['user_id'], 'google', $input, ['status' => 'signed_in']);

    case 'cancel':
        $finish();
        api_ok();

    default:
        api_fail('Onbekende actie.', 400);
}
