<?php
/**
 * The verification screen in the app (Release B, docs/ACCOUNT-SECURITY.md
 * §3): the registration this conversation holds — the cookie jar the app
 * registered or signed in with (api/auth/app-register.php, app-login.php).
 *
 *   POST { "action": "status" }  "Ik heb bevestigd", and on returning to the
 *                                foreground: { ok, state: pending | gone },
 *                                or once the link was used the app's token:
 *                                { ok, state: active, token, scope, provider, account }
 *   POST { "action": "resend" }  "Stuur opnieuw": { ok, message, resend_after },
 *                                or 429 with the wait (this registration's
 *                                own) or the neutral "Dit kan even niet…"
 *   POST { "action": "cancel" }  "Ander adres gebruiken" (D2): { ok, state: gone }
 *
 * "label", "platform" and "app_version" go with status, for the token.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/account-flows.php';
require_once dirname(__DIR__, 2) . '/includes/devices.php';

api_require_post();
api_require_https();
api_require_database();

$input  = api_json_body();
$action = is_string($input['action'] ?? null) ? $input['action'] : '';
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
    /* The link was used (in a browser): this conversation started the
       registration, so the app is signed in now (§3). */
    account_pending_release();
    api_app_signed_in($hold['user_id'], 'password', $input, ['state' => 'active']);
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
