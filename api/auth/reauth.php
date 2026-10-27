<?php
/**
 * Confirming the signed-in account with Google before an action that cannot
 * be undone — deleting the account — for an account without a password.
 * (An account with a password sends it with the action itself.)
 *
 *   The website   POST action=google, csrf, purpose=delete
 *                 -> { ok, redirect }   to Google, which asks for the password
 *                 again (prompt=login, max_age=0). Back in
 *                 api/auth/google-callback.php the account's own Google id and
 *                 a fresh auth_time are required; then one action of that
 *                 purpose may follow within 5 minutes.
 *
 *   The app       POST { "action": "nonce" } with its account token
 *                 -> { ok, nonce, client_id, expires_in }
 *                 for Credential Manager. The ID token Google gives back is
 *                 sent WITH the action (api/profile/delete.php, id_token), in
 *                 the same conversation (the cookie this answer sets), and
 *                 checked there (account_reauth_google_token()).
 *
 * At most 20 per quarter of an hour from one network address, the Google limit.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';
require_once dirname(__DIR__, 2) . '/includes/auth-throttle.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

$input  = api_json_body();
$action = is_string($input['action'] ?? null) ? $input['action'] : '';
$userId = api_require_account_user();

if (account_google_sub($userId) === null) {
    api_fail('Je account is niet aan Google gekoppeld.', 409);
}

$wait = auth_throttle_attempt('google', '', ACCOUNT_GOOGLE_LIMIT, 900);

if ($wait !== null) {
    header('Retry-After: ' . $wait);
    api_fail(auth_throttle_message($wait), 429);
}

$deviceId = api_current_device();

if ($action === 'nonce') {
    if ($deviceId === null) {
        api_fail('Onbekende actie.', 400);
    }

    if (!google_signin_native_configured()) {
        api_fail('Bevestigen met Google is in de app nog niet beschikbaar.', 501);
    }

    api_ok([
        'nonce'      => account_reauth_nonce($userId, $deviceId),
        'client_id'  => google_signin_config()['client_id'],
        'expires_in' => ACCOUNT_REAUTH_SECONDS,
    ]);
}

if ($action === 'google') {
    if ($deviceId !== null) {
        api_fail('Onbekende actie.', 400);
    }

    if (!google_signin_configured()) {
        api_fail('Inloggen met Google is nog niet gekoppeld.', 501);
    }

    $url = google_signin_begin('reauth', $userId, null, 'delete');

    if ($url === null) {
        api_fail('Bevestigen met Google kon niet worden gestart.', 500);
    }

    api_ok(['redirect' => $url]);
}

api_fail('Onbekende actie.', 400);
