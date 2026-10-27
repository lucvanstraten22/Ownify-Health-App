<?php
/**
 * Changes the password of the signed-in account — the website (session +
 * CSRF) and the Ownify app (account token) alike (api_require_account_user()).
 *
 *   POST  current, password, password_confirm
 *
 *   200   { ok }                 changed; every OTHER browser, stay-signed-in
 *                                cookie and app sign-in is signed out, the one
 *                                that asked stays. Health-sync tokens stay.
 *   403   the current password is wrong (counted like a wrong sign-in)
 *   409   the account has no password (it signs in with Google)
 *   422   the new password breaks a rule: 8–200 characters, not the
 *         username, not one of the account's addresses, typed twice the same
 *   429   too many wrong current passwords; Retry-After says how long
 *   503   migration 027 is not there yet — without it other browsers could
 *         not be signed out, so nothing is changed
 *
 * docs/ACCOUNT-SECURITY.md §9 says exactly what ends.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();
$input  = api_json_body();

$text = static fn (string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';

$result = account_change_password(
    $userId,
    $text('current'),
    $text('password'),
    $text('password_confirm'),
    api_current_device()
);

if (!$result['ok']) {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
    }

    api_fail((string) $result['error'], (int) ($result['status'] ?? 400));
}

/* The website reloads Settings and says so there. */
if (api_current_device() === null) {
    auth_flash(t('Je wachtwoord is gewijzigd. Je bent op je andere apparaten uitgelogd.'), 'ok', 'settings-account');
}

api_ok(['message' => 'Je wachtwoord is gewijzigd. Je bent op je andere apparaten uitgelogd.']);
