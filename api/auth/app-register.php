<?php
/**
 * The Ownify app creates an account: e-mail address, username, password.
 *
 * Release B (35.0), as on the website (api/auth/register.php): the account
 * waits for the link in e-mail 1. The answer is then
 *
 *   200   { ok, pending: { email_masked, username, resend_after } }
 *
 * for a free address and a taken one alike, and this conversation (the
 * app's cookie jar) holds the registration: api/auth/app-pending.php asks
 * whether the link was used, sends the mail again, or cancels — and gives the
 * app its token once the account is real. At a limit 429 with "Dit kan even
 * niet. Probeer het later opnieuw.", the same for both.
 *
 * Off (Release A): 403 "Registreren met e-mail is tijdelijk niet beschikbaar."
 * The app offers Google instead.
 *
 *   POST  { "email": "…", "username": "…", "password": "…", "password_confirm": "…",
 *           "label": "Pixel 10", "platform": "android", "app_version": "1.0" }
 *
 *   200   { ok, token, scope: "account", provider: "password", account: {…} }
 *         — only on a local test server with the immediate registration
 *   422   the website's own validation message
 *   429   a limit; Retry-After says how long where it may be said
 *   503   not available on this server yet (migration 013)
 *
 * The account is made by the function the website's registration uses, so
 * the rules, the password hash and the messages are the same ones.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth-throttle.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_https();
api_require_database();

if (!devices_scoped()) {
    api_fail('Registreren in de app is op deze server nog niet beschikbaar.', 503);
}

if (!account_email_registration_open()) {
    api_fail('Registreren met e-mail is tijdelijk niet beschikbaar.', 403);
}

$input    = api_json_body();
$email    = is_string($input['email'] ?? null) ? $input['email'] : '';
$username = is_string($input['username'] ?? null) ? trim($input['username']) : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';

if (account_email_registration_pending()) {
    require_once dirname(__DIR__, 2) . '/includes/account-flows.php';

    /* The website's limits (§12): per network address, and per address from it. */
    $wait = auth_throttle_attempt('register_ip', '', 20, 3600)
        ?? auth_throttle_attempt('register_try', $email, 5, 900);

    if ($wait !== null) {
        header('Retry-After: ' . $wait);
        api_fail(auth_throttle_message($wait), 429);
    }

    $result = account_pending_register(
        $email,
        $password,
        $username,
        is_string($input['password_confirm'] ?? null) && $input['password_confirm'] !== '' ? $input['password_confirm'] : $password
    );

    if (!$result['ok']) {
        api_fail((string) $result['error'], (int) ($result['status'] ?? 422));
    }

    account_pending_hold((int) $result['user_id']);

    api_ok(['pending' => (account_pending_view((int) $result['user_id']) ?? []) + ['resend_after' => (int) $result['resend_after']]]);
}

$wait = auth_throttle_wait('register', $email);

if ($wait !== null) {
    header('Retry-After: ' . $wait);
    api_fail(auth_throttle_message($wait), 429);
}

$repeat = is_string($input['password_confirm'] ?? null) ? $input['password_confirm'] : null;

$result = auth_register_email($email, $password, $username, $repeat);

if (!$result['ok']) {
    /* Only an answer about somebody else's account is worth limiting: a
       password that is too short tells nobody anything. */
    if (!empty($result['taken'])) {
        auth_throttle_fail('register', $email);
    }

    api_fail((string) $result['error'], 422);
}

auth_throttle_clear('register', $email);

api_app_signed_in((int) $result['user_id'], 'password', $input);
