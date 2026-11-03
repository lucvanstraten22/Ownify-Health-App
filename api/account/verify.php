<?php
/**
 * Settings › Inloggen › "Stuur bevestiging" (Release B, docs/ACCOUNT-SECURITY.md
 * §7B): e-mail 1 to the account's own unconfirmed sign-in address — the
 * website (session + CSRF) and the app (account token) alike.
 *
 *   POST
 *   200  { ok, message, resend_after }
 *   409  already confirmed; no address; or another account has it confirmed
 *        (then only Wijzigen helps)
 *   429  one a minute, 3 an hour, 10 a day for the account — Retry-After
 *   403  not available (Release B is off)
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

$result = account_verify_request($userId);

if (!$result['ok']) {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
    }

    api_json(['ok' => false, 'error' => (string) $result['error']]
        + (isset($result['retry_after']) ? ['resend_after' => (int) $result['retry_after']] : []), (int) ($result['status'] ?? 400));
}

api_ok([
    'message'      => tf('Verstuurd naar %s. Open de link in die e-mail om je adres te bevestigen.', (string) $result['sent_to']),
    'resend_after' => (int) $result['resend_after'],
]);
