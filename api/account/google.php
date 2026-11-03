<?php
/**
 * Settings › Inloggen › Google (Release B, docs/ACCOUNT-SECURITY.md §7).
 *
 *   action=remove, password        the website and the app: Google is
 *                                  removed (only with a password and a
 *                                  confirmed address), every other session
 *                                  ends, e-mail 6b. The website then goes past
 *                                  Google once so it can forget Ownify too,
 *                                  as after deleting an account: { ok, redirect }.
 *   action=nonce, password         the app's link, step 1: the current
 *                                  password, then a nonce for Credential
 *                                  Manager — { ok, nonce, client_id, expires_in }
 *                                  (the conversation's cookie keeps it)
 *   action=link, id_token          the app's link, step 2: { ok, message }
 *
 * The website links through api/auth/oauth.php (mode link, with the password).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/account-flows.php';
require_once dirname(__DIR__, 2) . '/includes/google-signin.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!account_mail_open()) {
    api_fail('Dit kan even niet. Probeer het later opnieuw.', 403);
}

$input    = api_json_body();
$action   = is_string($input['action'] ?? null) ? $input['action'] : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
$deviceId = api_current_device();

$fail = static function (array $result): never {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
    }

    api_json(['ok' => false, 'error' => (string) ($result['error'] ?? $result['message'] ?? '')]
        + (isset($result['reauth']) ? ['reauth' => (string) $result['reauth']] : []), (int) ($result['status'] ?? 400));
};

if ($action === 'remove') {
    $sub    = account_google_sub($userId);
    $result = account_google_remove($userId, $password, $deviceId);

    if (!$result['ok']) {
        $fail($result);
    }

    if ($deviceId !== null) {
        api_ok([
            'message' => 'Google is losgekoppeld. Je bent op je andere apparaten uitgelogd.',
            'link'    => ['href' => GOOGLE_ACCOUNT_CONNECTIONS_URL, 'label' => t('Apps met toegang tot je Google-account')],
        ]);
    }

    /* The website: one silent trip past Google so it forgets Ownify too
       (as deleting does); without Google sign-in configured, just say so. */
    $redirect = $sub === null ? null : google_signin_begin('revoke', null, $sub, 'unlink');

    if ($redirect === null) {
        auth_flash(t('Google is losgekoppeld. Je bent op je andere apparaten uitgelogd.'), 'ok', 'settings-account');
    }

    api_ok(['redirect' => $redirect]);
}

if ($action === 'nonce' || $action === 'link') {
    if ($deviceId === null) {
        api_fail('Onbekende actie.', 400);
    }

    $wait = auth_throttle_attempt('google', '', ACCOUNT_GOOGLE_LIMIT, 900);

    if ($wait !== null) {
        header('Retry-After: ' . $wait);
        api_fail(auth_throttle_message($wait), 429);
    }

    if ($action === 'nonce') {
        $result = account_google_link_start_native($userId, $deviceId, $password);

        if (!$result['ok']) {
            $fail($result);
        }

        api_ok([
            'nonce'      => $result['nonce'],
            'client_id'  => google_signin_config()['client_id'],
            'expires_in' => ACCOUNT_REAUTH_SECONDS,
        ]);
    }

    $result = account_google_link_native($userId, $deviceId, is_string($input['id_token'] ?? null) ? $input['id_token'] : '');

    if (!$result['ok']) {
        $fail($result);
    }

    api_ok(['message' => (string) $result['message']]);
}

api_fail('Onbekende actie.', 400);
