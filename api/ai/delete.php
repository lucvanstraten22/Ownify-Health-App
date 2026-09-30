<?php
/**
 * Ownify AI: wiping conversations — one, or all of this account's.
 *
 *   POST conversation_id=…   one conversation, with its messages
 *   POST all=1               every conversation of this account (Instellingen
 *                            → Privacy → "AI-gesprekken wissen")
 *   → 200 { ok, deleted, message }
 *   → 404 a conversation that is not this account's
 *
 * Only ever the signed-in account's own rows: every statement carries its id.
 * No consent needed: anybody may always delete what is theirs.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ai/assistant.php';

api_require_post();
api_require_database();

$userId = api_require_account_user();

if (!ai_installed()) {
    api_json(['ok' => false, 'code' => 'unavailable', 'error' => ai_error_text('unavailable')], 503);
}

if (($_POST['all'] ?? '') === '1') {
    $deleted = ai_conversations_delete_all($userId);

    api_ok([
        'deleted' => $deleted,
        'message' => $deleted === 0 ? 'Er waren geen gesprekken om te wissen.' : 'Je AI-gesprekken zijn gewist.',
    ]);
}

$conversationId = is_scalar($_POST['conversation_id'] ?? null) ? (int) $_POST['conversation_id'] : 0;

if (!ai_conversation_delete($userId, $conversationId)) {
    api_json(['ok' => false, 'code' => 'not_found', 'error' => ai_error_text('not_found')], 404);
}

api_ok(['deleted' => 1, 'message' => 'Gesprek verwijderd.']);
