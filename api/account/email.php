<?php
/**
 * Settings › Inloggen › E-mailadres (Release B, docs/ACCOUNT-SECURITY.md §6):
 * change the sign-in address — or, for an account that signs in with Google
 * only, add one. The website and the app alike.
 *
 *   POST email, and the proof: password (an account with one), or — without
 *        a password — Google: the website confirms with Google first
 *        (api/auth/reauth.php, purpose add_email / change_email), the app
 *        sends its id_token (nonce from api/auth/reauth.php)
 *   200  { ok, message } — a link to the new address (e-mail 3, or 2 when
 *        adding); nothing changes until it is used
 *   403  the proof is missing or wrong — `reauth` says which (password | google)
 *   409  another Ownify account has that address
 *   422  not an address, or the address it already has
 *   429  a limit — Retry-After
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/account-flows.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!account_mail_open()) {
    api_fail('Dit kan even niet. Probeer het later opnieuw.', 403);
}

$input  = api_json_body();
$result = account_email_change_request(
    $userId,
    is_string($input['email'] ?? null) ? $input['email'] : '',
    $input,
    api_current_device()
);

if (!$result['ok']) {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
    }

    api_json(['ok' => false, 'error' => (string) $result['error']]
        + (isset($result['reauth']) ? ['reauth' => (string) $result['reauth']] : []), (int) ($result['status'] ?? 400));
}

$message = tf('Verstuurd naar %s. Open de link in die e-mail; tot dan blijft alles zoals het was.', (string) $result['sent_to']);

/* The website reloads Settings and says so there. */
if (api_current_device() === null) {
    auth_flash($message, 'ok', 'settings-account');
}

api_ok(['message' => $message]);
