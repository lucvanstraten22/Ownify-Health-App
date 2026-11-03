<?php
/**
 * Wachtwoord vergeten on the website (Release B, docs/ACCOUNT-SECURITY.md §7B).
 *
 *   POST csrf, email
 *   200  { ok, message } — the same answer, always: whether or not the
 *        address has an account, a password, a confirmed address or a limit
 *        reached. Exactly one outbox row either way: the reset link (e-mail 4,
 *        1 hour) or a decoy.
 *   422  not an address
 *   429  this network address, or this address from it, asked too often
 *        (3 per quarter hour per address, 10 per hour) — Retry-After
 *   403  not available (Release B is off)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/account-flows.php';

api_require_post();
api_require_csrf();
api_require_database();

if (!account_mail_open()) {
    api_fail('Dit kan even niet. Probeer het later opnieuw.', 403);
}

$result = account_forgot_password((string) ($_POST['email'] ?? ''));

if (!$result['ok']) {
    if (isset($result['retry_after'])) {
        header('Retry-After: ' . $result['retry_after']);
    }

    api_fail((string) $result['error'], (int) ($result['status'] ?? 400));
}

api_ok(['message' => account_forgot_answer()]);
