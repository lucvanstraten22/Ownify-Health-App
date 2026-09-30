<?php
/**
 * Ownify AI: yes or no to a change the assistant prepared — a goal to add,
 * a goal to pause.
 *
 *   POST message_id=… decision=confirm|decline
 *   → 200 { ok, messages: [the proposal, now answered; Ownify's note] }
 *   → 404 not this account's message; 409 answered already
 *
 * The change is carried out here, checked again with the rules as they are
 * now (includes/ai/tools.php, ai_action_execute), and only for the account
 * the message belongs to — the id from the request finds nothing else.
 * No consent needed to say no; saying yes needs it, as asking did.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ai/assistant.php';

api_require_post();
api_require_database();

$userId    = api_require_account_user();
$messageId = is_scalar($_POST['message_id'] ?? null) ? (int) $_POST['message_id'] : 0;
$decision  = (string) ($_POST['decision'] ?? '');

if ($decision !== 'confirm' && $decision !== 'decline') {
    api_fail('Kies bevestigen of niet.', 422);
}

if (!ai_installed()) {
    api_json(['ok' => false, 'code' => 'unavailable', 'error' => ai_error_text('unavailable')], 503);
}

if ($decision === 'confirm' && !ai_consented($userId)) {
    api_json(['ok' => false, 'code' => 'consent', 'error' => ai_error_text('consent')], 403);
}

$result = ai_action_resolve($userId, $messageId, $decision === 'confirm');

if (!$result['ok']) {
    api_json(['ok' => false, 'code' => $result['code'], 'error' => $result['error'], 'messages' => $result['messages']], $result['status']);
}

api_ok(['messages' => $result['messages']]);
