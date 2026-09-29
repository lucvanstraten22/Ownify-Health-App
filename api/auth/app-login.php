<?php
/**
 * The Ownify app signs in with a username or e-mail address and the password.
 *
 *   POST  { "identifier": "sanne" | "sanne@example.nl", "password": "…",
 *           "label": "Pixel 10", "platform": "android", "app_version": "1.0" }
 *
 *   200   { ok, token, scope: "account", provider: "password", account: {…} }
 *   401   wrong name or password — one message for both, whether or not the
 *         name has an account
 *   422   a field left empty
 *   429   too many wrong passwords for this name from this address; the
 *         Retry-After header says how many seconds to wait
 *   503   not available on this server yet (migration 013)
 *
 * No session, no CSRF token: there is no browser and no cookie to protect,
 * and nobody is signed in yet — the password is the proof. The password is
 * checked by auth_login_password(), the same function the website's login
 * uses, and counted by the same limit (includes/auth-throttle.php).
 *
 * What comes back is an ACCOUNT token (includes/devices.php): the app keeps
 * it and sends it as `Authorization: Bearer`. A token the app already holds
 * for this account can be sent the same way; that phone's row then gets the
 * new token instead of a second row appearing in Settings.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth-throttle.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_https();
api_require_database();

if (!devices_scoped()) {
    api_fail('Inloggen in de app is op deze server nog niet beschikbaar.', 503);
}

$input      = api_json_body();
$identifier = is_string($input['identifier'] ?? null) ? trim($input['identifier']) : '';
$password   = is_string($input['password'] ?? null) ? $input['password'] : '';

if ($identifier === '' || $password === '') {
    api_fail('Vul je gebruikersnaam of e-mailadres en je wachtwoord in.', 422);
}

$result = auth_login_attempt($identifier, $password);

if (!$result['ok']) {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
        api_fail($result['error'], 429);
    }

    api_fail($result['error'], 401);
}

api_app_signed_in((int) $result['user_id'], 'password', $input);
