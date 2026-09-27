<?php
/**
 * The JoLu app creates an account: e-mail address, username, password.
 *
 *   POST  { "email": "…", "username": "…", "password": "…",
 *           "label": "Pixel 10", "platform": "android", "app_version": "1.0" }
 *
 *   200   { ok, token, scope: "account", provider: "password", account: {…} }
 *   422   the website's own validation message: an invalid or taken address,
 *         an invalid or taken username, a password that is too short or long
 *   429   too many tries with an address or username that was taken, for
 *         this address from here; the Retry-After header says how long
 *   503   not available on this server yet (migration 013)
 *
 * The account is made by auth_register_email() — the function the website's
 * registration uses — so the rules, the password hash and the messages are
 * the same ones. Nobody is signed in in a browser: the app gets an account
 * token, exactly as after app-login.php.
 *
 * "Taken" answers are counted per e-mail address and network address, under
 * their own name, with the sign-in limit (includes/auth-throttle.php): trying
 * to learn which addresses or usernames have an account is slowed down the
 * way password guessing is. A field that is simply invalid is not counted.
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

$input    = api_json_body();
$email    = is_string($input['email'] ?? null) ? $input['email'] : '';
$username = is_string($input['username'] ?? null) ? trim($input['username']) : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';

$wait = auth_throttle_wait('register', $email);

if ($wait !== null) {
    header('Retry-After: ' . $wait);
    api_fail(auth_throttle_message($wait), 429);
}

$result = auth_register_email($email, $password, $username);

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
