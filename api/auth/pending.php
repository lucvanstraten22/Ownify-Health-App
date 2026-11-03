<?php
/**
 * The verification screen on the website (Release B, docs/ACCOUNT-SECURITY.md
 * §3): the registration this browser holds (account_pending_hold()), after
 * registering or signing in to one that still waits for its link.
 *
 *   POST csrf, action=status   "Ik heb bevestigd" — { ok, state: pending | gone }
 *                              or, once the link was used, signed in here:
 *                              { ok, state: active, account }
 *   POST csrf, action=resend   "Stuur opnieuw" — { ok, message, resend_after }
 *                              or 429 with the wait (this registration's own)
 *                              or the neutral "Dit kan even niet…" at the
 *                              per-recipient limit
 *   POST csrf, action=cancel   "Ander adres gebruiken" (D2) — the waiting
 *                              account is deleted; { ok }
 *
 * A placeholder (a taken address, §4) answers exactly like a real one; it is
 * simply never confirmed.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/account-flows.php';

api_require_post();
api_require_csrf();
api_require_database();

$action = (string) ($_POST['action'] ?? '');
$hold   = account_pending_held();

if ($hold === null) {
    api_json(['ok' => false, 'state' => 'gone', 'error' => 'Deze registratie bestaat niet meer. Begin opnieuw.'], 410);
}

if ($action === 'cancel') {
    if (account_pending_state($hold) === 'pending') {
        account_pending_cancel($hold['user_id']);
    }

    account_pending_release();
    api_ok(['state' => 'gone']);
}

$state = account_pending_state($hold);

if ($state === 'gone') {
    account_pending_release();
    api_json(['ok' => false, 'state' => 'gone', 'error' => 'Deze registratie bestaat niet meer. Begin opnieuw.'], 410);
}

if ($state === 'active') {
    /* Its link was used — elsewhere, since here it would have signed in
       already: this browser started it, so it is signed in now (§3). */
    account_pending_release();
    session_login($hold['user_id']);
    auth_touch_last_seen($hold['user_id']);

    api_ok(['state' => 'active'] + api_account_payload($hold['user_id']));
}

if ($action === 'status') {
    api_ok(['state' => 'pending', 'message' => 'Nog niet bevestigd. Open de link in de e-mail die we je stuurden.']);
}

if ($action === 'resend') {
    $result = account_pending_resend($hold['user_id']);

    if (!$result['ok']) {
        if (isset($result['retry_after'])) {
            header('Retry-After: ' . $result['retry_after']);
        }

        api_json(['ok' => false, 'state' => 'pending', 'error' => (string) $result['error']]
            + (isset($result['retry_after']) ? ['resend_after' => (int) $result['retry_after']] : []), (int) ($result['status'] ?? 400));
    }

    api_ok(['state' => 'pending', 'message' => 'Verstuurd. Kijk ook even in je spam.', 'resend_after' => (int) $result['resend_after']]);
}

api_fail('Onbekende actie.', 400);
