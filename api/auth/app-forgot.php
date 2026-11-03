<?php
/**
 * Wachtwoord vergeten in the app (Release B, docs/ACCOUNT-SECURITY.md §7B):
 * the website's own flow (api/auth/forgot.php) — the same answer always, one
 * outbox row either way. The link in the e-mail opens the website.
 *
 *   POST { "email": "…" }
 *   200  { ok, message }   422 not an address   429 a limit (Retry-After)
 *   403  not available (Release B is off)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/account-flows.php';

api_require_post();
api_require_https();
api_require_database();

if (!account_mail_open()) {
    api_fail('Dit kan even niet. Probeer het later opnieuw.', 403);
}

$input  = api_json_body();
$result = account_forgot_password(is_string($input['email'] ?? null) ? $input['email'] : '');

if (!$result['ok']) {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
    }

    api_fail((string) $result['error'], (int) ($result['status'] ?? 400));
}

api_ok(['message' => account_forgot_answer()]);
