<?php
/**
 * The website makes an account with an e-mail address and a password.
 *
 * Closed in Release A (28.0): a new e-mail registration has to wait on a
 * verification screen until its link is used, and that needs mail, which
 * comes with Release B (docs/ACCOUNT-SECURITY.md). Until then the answer is
 * "Registreren met e-mail is tijdelijk niet beschikbaar." and the panel
 * offers Google instead. Signing in is not affected.
 *
 * When it is open (only a local test server, account_email_registration_open()):
 * the new password rules with "Herhaal wachtwoord" (password_confirm), and
 * limits on attempts — 5 per quarter hour per address from one network
 * address, 20 per hour from one network address.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/auth-throttle.php';

api_require_post();
api_require_csrf();
api_require_database();

if (!account_email_registration_open()) {
    api_fail('Registreren met e-mail is tijdelijk niet beschikbaar.', 403);
}

$email = (string) ($_POST['email'] ?? '');

$wait = auth_throttle_attempt('register_ip', '', 20, 3600)
    ?? auth_throttle_attempt('register_try', $email, 5, 900);

if ($wait !== null) {
    header('Retry-After: ' . $wait);
    api_fail(auth_throttle_message($wait), 429);
}

$result = auth_register_email(
    $email,
    (string) ($_POST['password'] ?? ''),
    (string) ($_POST['username'] ?? ''),
    (string) ($_POST['password_confirm'] ?? '')
);

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

session_login((int) $result['user_id']);

api_ok(api_account_payload((int) $result['user_id']));
