<?php
/**
 * Settings › Inloggen › Wachtwoord › Instellen (Release B,
 * docs/ACCOUNT-SECURITY.md §7): the first password of an account that signs
 * in with Google — only with a confirmed address to sign in with, and after
 * Google (the website: api/auth/reauth.php purpose set_password first; the
 * app: its id_token). Nobody is signed out; e-mail 5 (the "set" variant).
 *
 *   POST password, password_confirm [, id_token]
 *   200  { ok, message }
 *   403  Google first — `reauth: google`
 *   409  no confirmed address yet, or a password already
 *   422  the password breaks a rule
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

$input = api_json_body();
$text  = static fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';

$result = account_set_password($userId, $text('password'), $text('password_confirm'), $input, api_current_device());

if (!$result['ok']) {
    api_json(['ok' => false, 'error' => (string) $result['error']]
        + (isset($result['reauth']) ? ['reauth' => (string) $result['reauth']] : []), (int) ($result['status'] ?? 400));
}

if (api_current_device() === null) {
    auth_flash(t('Je wachtwoord is ingesteld. Je kunt nu ook met je e-mailadres en wachtwoord inloggen.'), 'ok', 'settings-account');
}

api_ok(['message' => 'Je wachtwoord is ingesteld. Je kunt nu ook met je e-mailadres en wachtwoord inloggen.']);
