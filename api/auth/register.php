<?php
/**
 * The website makes an account with an e-mail address and a password.
 *
 * Release B (35.0, docs/ACCOUNT-SECURITY.md §3–4), when config/mail.php's
 * account_mail and email_registration are on: the account waits — status
 * pending — until the link in e-mail 1 is used. The answer is
 * { ok, pending: { email_masked, username, resend_after } } for a free
 * address and a taken one alike (a taken one gets a placeholder and e-mail 7
 * goes to its owner); the account panel shows the verification screen, and
 * this browser holds the registration (account_pending_hold()). At a limit:
 * "Dit kan even niet. Probeer het later opnieuw." (429), the same for both.
 *
 * Off (Release A): "Registreren met e-mail is tijdelijk niet beschikbaar."
 * and the panel offers Google instead. Signing in is not affected.
 *
 * A local test server can open today's immediate registration instead
 * (OWNIFY_TEST_EMAIL_REGISTRATION=1, account_email_registration_open()).
 *
 * Limits on attempts either way — 5 per quarter hour per address from one
 * network address, 20 per hour from one network address — and Release B's
 * per-recipient limit inside the flow (§12).
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

/* 45.0: registering has no repeat field any more (the eye in the field
   shows what was typed); a client that still sends one is held to it. */
$repeat = is_string($_POST['password_confirm'] ?? null) && $_POST['password_confirm'] !== ''
    ? $_POST['password_confirm']
    : (string) ($_POST['password'] ?? '');

if (account_email_registration_pending()) {
    require_once dirname(__DIR__, 2) . '/includes/account-flows.php';

    $result = account_pending_register(
        $email,
        (string) ($_POST['password'] ?? ''),
        (string) ($_POST['username'] ?? ''),
        $repeat
    );

    if (!$result['ok']) {
        api_fail((string) $result['error'], (int) ($result['status'] ?? 422));
    }

    account_pending_hold((int) $result['user_id']);

    api_ok(['pending' => (account_pending_view((int) $result['user_id']) ?? []) + ['resend_after' => (int) $result['resend_after']]]);
}

$result = auth_register_email(
    $email,
    (string) ($_POST['password'] ?? ''),
    (string) ($_POST['username'] ?? ''),
    $repeat
);

if (!$result['ok']) {
    api_fail($result['error'], 422);
}

session_login((int) $result['user_id']);

api_ok(api_account_payload((int) $result['user_id']));
