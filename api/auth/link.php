<?php
/**
 * The links in the account e-mails (Release B, docs/ACCOUNT-SECURITY.md §7):
 * https://ownify.acits.nl/?account-link#<token>. The token travels in the
 * fragment, so it is in no server log and no Referer; the page reads it and
 * asks here.
 *
 *   POST csrf, action=info, token
 *        { ok, link: { purpose, needs: confirm | password, email, … } }
 *        Only reads: opening a link (or a mail scanner opening it) uses
 *        nothing up.
 *   POST csrf, action=use, token [, password, password_confirm]
 *        The tap — or, for a reset or a revert, the new password, checked
 *        before anything is used so a typo can be corrected. One transaction;
 *        { ok, message, signed_in?, signed_out? }.
 *
 * 410 "Deze link is verlopen of al gebruikt." for an expired, used, voided or
 * unknown link — one message for all four. 30 per quarter hour from one
 * network address (§12).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/account-flows.php';

api_require_post();
api_require_csrf();
api_require_database();

header('Referrer-Policy: no-referrer');

if (!account_mail_open()) {
    api_fail('Deze link is verlopen of al gebruikt.', 410);
}

$wait = auth_throttle_attempt('link_ip', '', 30, 900);

if ($wait !== null) {
    header('Retry-After: ' . $wait);
    api_fail(auth_throttle_message($wait), 429);
}

$action = (string) ($_POST['action'] ?? '');
$token  = (string) ($_POST['token'] ?? '');

if ($action === 'info') {
    $view = account_link_view($token);

    if ($view === null) {
        api_fail('Deze link is verlopen of al gebruikt.', 410);
    }

    api_ok(['link' => $view]);
}

if ($action === 'use') {
    $result = account_link_use($token, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirm'] ?? ''));

    if (!$result['ok']) {
        api_json(['ok' => false, 'error' => (string) $result['message'], 'gone' => (int) ($result['status'] ?? 400) === 410],
            (int) ($result['status'] ?? 400));
    }

    $answer = ['message' => (string) $result['message']];

    /* A reset or a revert ends every session, this browser's too (§6, §7B). */
    if (!empty($result['signed_out']) && current_user_id() !== null) {
        session_logout();
        $answer['signed_out'] = true;
    }

    /* A registration confirmed in the browser that started it (§3). */
    if (isset($result['signed_in_user'])) {
        session_login((int) $result['signed_in_user']);
        auth_touch_last_seen((int) $result['signed_in_user']);
        $answer['signed_in'] = true;
    }

    api_ok($answer);
}

api_fail('Onbekende actie.', 400);
